<?php

namespace MikeFrancis\LaravelUnleash;

use Closure;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\TransferException;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use JsonException;
use MikeFrancis\LaravelUnleash\Strategies\Contracts\DynamicStrategy;
use MikeFrancis\LaravelUnleash\Strategies\Contracts\Strategy;

class Unleash
{
    public const DEFAULT_CACHE_TTL = 15;

    protected $client;
    protected $cache;
    protected $config;
    protected $request;
    protected $features;
    protected $expires;
    protected ?FeatureFile $file;
    protected ?Closure $defer;
    private bool $refreshQueued = false;

    /**
     * The client and the cache may be closures that build them: reading flags from the feature file
     * needs neither, only fetching does.
     *
     * @param ClientInterface|Closure(): ClientInterface $client
     * @param Cache|Closure(): Cache $cache holds the failover copy, shared by every machine
     * @param ?FeatureFile $file required for caching (unleash.cache.isEnabled)
     * @param ?Closure(Closure): void $defer runs the refresh of a stale feature file; when it is null,
     *                                       the refresh runs right away
     */
    public function __construct(
        ClientInterface|Closure $client,
        Cache|Closure $cache,
        Config $config,
        Request $request,
        ?FeatureFile $file = null,
        ?Closure $defer = null
    ) {
        $this->client = $client;
        $this->cache = $cache;
        $this->config = $config;
        $this->request = $request;
        $this->file = $file;
        $this->defer = $defer;
    }

    public function getFeatures(): array
    {
        if ($this->isFresh()) {
            return $this->features;
        }

        if (!$this->config->get('unleash.isEnabled')) {
            return [];
        }

        if ($this->config->get('unleash.cache.isEnabled') && $this->file !== null) {
            $data = $this->getCachedFeatures($this->file);
        } else {
            $data = $this->fetchFeaturesWithFailover();
        }

        $this->features = $data['features'];
        $this->expires = $data['expires'];

        return $this->features;
    }

    public function getFeature(string $name): array
    {
        $features = $this->getFeatures();

        return (array) Arr::first(
            $features,
            function (array $unleashFeature) use ($name) {
                return $name === $unleashFeature['name'];
            }
        );
    }

    public function isFeatureEnabled(string $name, ...$args): bool
    {
        $feature = $this->getFeature($name);
        $isEnabled = Arr::get($feature, 'enabled', false);

        if (!$isEnabled) {
            return false;
        }

        $strategies = Arr::get($feature, 'strategies', []);
        $allStrategies = $this->config->get('unleash.strategies', []);

        if (count($strategies) === 0) {
            return $isEnabled;
        }

        foreach ($strategies as $strategyData) {
            $className = $strategyData['name'];

            if (!array_key_exists($className, $allStrategies)) {
                continue;
            }

            if (is_callable($allStrategies[$className])) {
                $strategy = $allStrategies[$className]();
            } else {
                $strategy = new $allStrategies[$className]();
            }

            if (!$strategy instanceof Strategy && !$strategy instanceof DynamicStrategy) {
                throw new \Exception($className . ' does not implement base Strategy/DynamicStrategy.');
            }

            $params = Arr::get($strategyData, 'parameters', []);

            if ($strategy->isEnabled($params, $this->request, ...$args)) { // @phan-suppress-current-line PhanParamTooManyUnpack
                return true;
            }
        }

        return false;
    }

    public function isFeatureDisabled(string $name, ...$args): bool
    {
        return !$this->isFeatureEnabled($name, ...$args);
    }

    /**
     * Fetch the flags into the feature file now, unless another process holds the lock or has
     * refreshed the file since this process read it.
     */
    public function refreshCache()
    {
        $file = $this->file;
        if (!$this->config->get('unleash.isEnabled') || !$this->config->get('unleash.cache.isEnabled') || !$file) {
            return;
        }

        $file->withLock(function () use ($file): void {
            $current = $file->read();
            if ($current === null || $current['expires'] <= $this->getExpires()) {
                $this->refreshFeatureFile($file, $current);
            }
        }, false);
    }

    protected function isFresh(): bool
    {
        return is_array($this->features) && $this->expires > time();
    }

    /**
     * Flags come from the feature file. A stale file is still served while it gets refreshed (after
     * the response, see $defer), so a request never waits for the Unleash server. Only a missing file
     * is filled before answering, by the first process to get the lock; the others wait and read it.
     *
     * @return array{features: array, expires: int}
     */
    protected function getCachedFeatures(FeatureFile $file): array
    {
        $data = $file->read();

        if ($data === null) {
            $file->withLock(function () use ($file, &$data): void {
                $data = $file->read() ?? $this->refreshFeatureFile($file, null);
            }, true);

            return $data ?? $this->refreshFeatureFile($file, null);
        }

        if ($data['expires'] <= time()) {
            $this->queueRefresh($file);
            // a refresh that ran right away (no $defer) is visible at once
            $data = $file->read() ?? $data;
            if ($data['expires'] <= time()) {
                $data['expires'] = time() + 1; // the refresh is queued or running elsewhere: look again soon
            }
        }

        return $data;
    }

    public function getCacheTTL(): int
    {
        return $this->config->get('unleash.cache.ttl', self::DEFAULT_CACHE_TTL);
    }

    protected function setExpires(): int
    {
        return $this->expires = $this->getCacheTTL() + time();
    }

    public function getExpires(): int
    {
        return $this->expires ?? $this->getCacheTTL() + time();
    }

    protected function fetchFeatures(): array
    {
        $response = $this->client()->request('GET', $this->config->get('unleash.featuresEndpoint'));

        $data = (array) json_decode((string)$response->getBody(), true, 512, JSON_BIGINT_AS_STRING + JSON_THROW_ON_ERROR);

        $data['expires'] = $this->setExpires();

        if ($this->config->get('unleash.cache.failover') === true) {
            $this->cache()->forever('unleash.failover', $data);
        }

        $this->features = Arr::get($data, 'features', []);

        return $data;
    }

    private function queueRefresh(FeatureFile $file): void
    {
        if ($this->refreshQueued) {
            return;
        }
        $this->refreshQueued = true;

        $refresh = function () use ($file): void {
            $this->refreshQueued = false;
            $file->withLock(function () use ($file): void {
                $current = $file->read();
                if ($current === null || $current['expires'] <= time()) {
                    $this->refreshFeatureFile($file, $current);
                }
            }, false);
        };

        if ($this->defer !== null) {
            ($this->defer)($refresh);
        } else {
            $refresh();
        }
    }

    /**
     * Fetch the flags into the feature file for the next ttl. When the Unleash server cannot be
     * reached, keep the flags we had (or the failover copy) and only try again after that ttl, instead
     * of on every request.
     *
     * @param ?array{features: array, expires: int} $current
     * @return array{features: array, expires: int}
     */
    private function refreshFeatureFile(FeatureFile $file, ?array $current): array
    {
        try {
            $features = Arr::get($this->fetchFeatures(), 'features', []);
        } catch (TransferException | JsonException) {
            $features = [];
            if ($this->config->get('unleash.cache.failover') === true) {
                $features = $current['features'] ?? $this->getFailoverFeatures();
            }
        }

        $expires = time() + $this->getCacheTTL();
        $file->write($features, $expires);

        return ['features' => $features, 'expires' => $expires];
    }

    /**
     * Without caching, every fetch goes to the Unleash server, falling back to the failover copy.
     *
     * @return array{features: array, expires: int}
     */
    private function fetchFeaturesWithFailover(): array
    {
        try {
            $features = Arr::get($this->fetchFeatures(), 'features', []);
        } catch (TransferException | JsonException) {
            $features = $this->config->get('unleash.cache.failover') === true ? $this->getFailoverFeatures() : [];
        }

        return ['features' => $features, 'expires' => $this->getExpires()];
    }

    private function getFailoverFeatures(): array
    {
        return Arr::get($this->cache()->get('unleash.failover', []), 'features', []);
    }

    private function client(): ClientInterface
    {
        $client = $this->client;
        if ($client instanceof Closure) {
            $client = $client();
            $this->client = $client;
        }
        assert($client instanceof ClientInterface);

        return $client;
    }

    private function cache(): Cache
    {
        $cache = $this->cache;
        if ($cache instanceof Closure) {
            $cache = $cache();
            $this->cache = $cache;
        }
        assert($cache instanceof Cache);

        return $cache;
    }
}
