<?php

namespace MikeFrancis\LaravelUnleash\Tests;

use Closure;
use Exception;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use MikeFrancis\LaravelUnleash\FeatureFile;
use MikeFrancis\LaravelUnleash\Tests\Stubs\ImplementedStrategy;
use MikeFrancis\LaravelUnleash\Tests\Stubs\ImplementedStrategyThatIsDisabled;
use MikeFrancis\LaravelUnleash\Tests\Stubs\NonImplementedStrategy;
use MikeFrancis\LaravelUnleash\Unleash;
use PHPUnit\Framework\TestCase;

class UnleashTest extends TestCase
{
    private const FEATURE = 'someFeature';

    private MockHandler $mockHandler;
    private array $cacheStore = [];
    private string $path;
    /** @var Closure[] */
    private array $deferred = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockHandler = new MockHandler();
        $this->path = tempnam(sys_get_temp_dir(), 'unleash-test-');
        unlink($this->path);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->path . '*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function testIsFeatureEnabled()
    {
        $this->respondWith([['name' => self::FEATURE, 'enabled' => true]]);

        $this->assertTrue($this->unleash(['cache' => ['isEnabled' => false]])->isFeatureEnabled(self::FEATURE));
    }

    public function testIsFeatureDisabled()
    {
        $this->respondWith([['name' => self::FEATURE, 'enabled' => false]]);

        $this->assertTrue($this->unleash(['cache' => ['isEnabled' => false]])->isFeatureDisabled(self::FEATURE));
    }

    public function testIsFeatureEnabledWithValidStrategy()
    {
        $this->respondWith([
            ['name' => self::FEATURE, 'enabled' => true, 'strategies' => [['name' => 'testStrategy']]],
        ]);

        $this->assertTrue($this->unleash(['cache' => ['isEnabled' => false]])->isFeatureEnabled(self::FEATURE));
    }

    public function testIsFeatureEnabledWithMultipleStrategies()
    {
        $this->respondWith([
            [
                'name' => self::FEATURE,
                'enabled' => true,
                'strategies' => [['name' => 'testStrategyThatIsDisabled'], ['name' => 'testStrategy']],
            ],
        ]);

        $this->assertTrue($this->unleash(['cache' => ['isEnabled' => false]])->isFeatureEnabled(self::FEATURE));
    }

    public function testIsFeatureDisabledWithInvalidStrategy()
    {
        $this->respondWith([
            ['name' => self::FEATURE, 'enabled' => true, 'strategies' => [['name' => 'invalidStrategy']]],
        ]);

        $this->assertTrue($this->unleash(['cache' => ['isEnabled' => false]])->isFeatureDisabled(self::FEATURE));
    }

    public function testIsFeatureDisabledWithStrategyThatDoesNotImplementBaseStrategy()
    {
        $this->respondWith([
            ['name' => self::FEATURE, 'enabled' => true, 'strategies' => [['name' => 'nonImplementedStrategy']]],
        ]);

        $this->expectException(Exception::class);

        $this->unleash(['cache' => ['isEnabled' => false]])->isFeatureDisabled(self::FEATURE);
    }

    public function testFeatureDetectionCanBeDisabled()
    {
        $unleash = $this->unleash(['isEnabled' => false]);

        $this->assertSame([], $unleash->getFeatures());
        $this->assertFileDoesNotExist($this->path);
    }

    public function testErrorsFromUnleashWithoutFailoverCopyDisableFeatures()
    {
        $this->mockHandler->append(new Response(500));

        $this->assertTrue($this->unleash(['cache' => ['isEnabled' => false]])->isFeatureDisabled(self::FEATURE));
    }

    public function testErrorsFromUnleashFallBackOnTheFailoverCopy()
    {
        $this->cacheStore['unleash.failover'] = ['features' => [['name' => self::FEATURE, 'enabled' => true]]];
        $this->mockHandler->append(new Response(500));

        $this->assertTrue($this->unleash(['cache' => ['isEnabled' => false]])->isFeatureEnabled(self::FEATURE));
    }

    public function testFailoverCanBeDisabled()
    {
        $this->cacheStore['unleash.failover'] = ['features' => [['name' => self::FEATURE, 'enabled' => true]]];
        $this->mockHandler->append(new Response(500));

        $unleash = $this->unleash(['cache' => ['isEnabled' => false, 'failover' => false]]);

        $this->assertTrue($unleash->isFeatureDisabled(self::FEATURE));
    }

    public function testFetchStoresTheFailoverCopy()
    {
        $this->respondWith([['name' => self::FEATURE, 'enabled' => true]]);

        $this->unleash()->getFeatures();

        $this->assertSame(self::FEATURE, $this->cacheStore['unleash.failover']['features'][0]['name']);
    }

    public function testMissingFeatureFileIsFilledOnceAndThenRead()
    {
        $this->respondWith([['name' => self::FEATURE, 'enabled' => true]]);

        $this->assertTrue($this->unleash()->isFeatureEnabled(self::FEATURE));
        $this->assertSame(0, $this->mockHandler->count(), 'the first process fetches');

        // a second process (another request) reads the file; an extra request would make the
        // mock handler throw
        $this->assertTrue($this->unleash()->isFeatureEnabled(self::FEATURE));
        $this->assertSame([], $this->deferred, 'a fresh file needs no refresh');
    }

    public function testFreshFeatureFileNeedsNeitherClientNorCache()
    {
        (new FeatureFile($this->path))->write([['name' => self::FEATURE, 'enabled' => true]], time() + 15);

        $fail = function () {
            $this->fail('reading a fresh feature file must not build the client or the cache');
        };
        $request = $this->createMock(Request::class);
        $unleash = new Unleash($fail, $fail, $this->config(), $request, new FeatureFile($this->path), $this->defer());

        $this->assertTrue($unleash->isFeatureEnabled(self::FEATURE));
    }

    public function testStaleFeatureFileIsServedAndRefreshedAfterwards()
    {
        (new FeatureFile($this->path))->write([['name' => self::FEATURE, 'enabled' => true]], time() - 1);
        $this->respondWith([['name' => self::FEATURE, 'enabled' => false]]);

        $unleash = $this->unleash();
        $this->assertTrue($unleash->isFeatureEnabled(self::FEATURE), 'the stale flags are served');
        $this->assertTrue($unleash->isFeatureEnabled(self::FEATURE));
        $this->assertCount(1, $this->deferred, 'one refresh is queued per process');
        $this->assertSame(1, $this->mockHandler->count(), 'nothing is fetched before the response');

        $this->runDeferred();

        $this->assertSame(0, $this->mockHandler->count());
        $this->assertTrue($this->unleash()->isFeatureDisabled(self::FEATURE), 'the next process sees the new flags');
    }

    public function testFailedRefreshKeepsTheFlagsUntilTheNextTtl()
    {
        (new FeatureFile($this->path))->write([['name' => self::FEATURE, 'enabled' => true]], time() - 1);
        $this->mockHandler->append(new Response(500));

        $this->unleash()->getFeatures();
        $this->runDeferred();

        $data = (new FeatureFile($this->path))->read();
        $this->assertTrue($data['features'][0]['enabled']);
        $this->assertGreaterThan(time(), $data['expires'], 'the next attempt waits a ttl');
        $this->assertTrue($this->unleash()->isFeatureEnabled(self::FEATURE));
        $this->assertSame([], $this->deferred, 'no retry on the next request');
    }

    public function testMissingFeatureFileWithUnleashDownUsesTheFailoverCopy()
    {
        $this->cacheStore['unleash.failover'] = ['features' => [['name' => self::FEATURE, 'enabled' => true]]];
        $this->mockHandler->append(new Response(500));

        $this->assertTrue($this->unleash()->isFeatureEnabled(self::FEATURE));
        $this->assertTrue((new FeatureFile($this->path))->read()['features'][0]['enabled']);
    }

    public function testRefreshIsSkippedWhileAnotherProcessHoldsTheLock()
    {
        (new FeatureFile($this->path))->write([['name' => self::FEATURE, 'enabled' => true]], time() - 1);
        $this->respondWith([['name' => self::FEATURE, 'enabled' => false]]);
        $lock = fopen($this->path . '.lock', 'c');
        flock($lock, LOCK_EX);

        $this->unleash()->getFeatures();
        $this->runDeferred();

        flock($lock, LOCK_UN);
        fclose($lock);
        $this->assertSame(1, $this->mockHandler->count(), 'no request was made');
        $this->assertLessThanOrEqual(time(), (new FeatureFile($this->path))->read()['expires']);
    }

    public function testWithoutDeferTheRefreshHappensRightAway()
    {
        (new FeatureFile($this->path))->write([['name' => self::FEATURE, 'enabled' => true]], time() - 1);
        $this->respondWith([['name' => self::FEATURE, 'enabled' => false]]);

        $request = $this->createMock(Request::class);
        $file = new FeatureFile($this->path);
        $unleash = new Unleash($this->client(), $this->cache(), $this->config(), $request, $file);

        // a long-running process sees the new flags at once
        $this->assertTrue($unleash->isFeatureDisabled(self::FEATURE));
    }

    public function testRefreshCacheSkipsAFileAnotherProcessRefreshed()
    {
        (new FeatureFile($this->path))->write([['name' => self::FEATURE, 'enabled' => true]], time() + 10);
        $this->respondWith([['name' => self::FEATURE, 'enabled' => false]]);
        $unleash = $this->unleash();
        $unleash->getFeatures();

        (new FeatureFile($this->path))->write([['name' => self::FEATURE, 'enabled' => true]], time() + 15);
        $unleash->refreshCache();

        $this->assertSame(1, $this->mockHandler->count(), 'no request was made');
    }

    public function testRefreshCacheFetches()
    {
        (new FeatureFile($this->path))->write([['name' => self::FEATURE, 'enabled' => true]], time() + 10);
        $this->respondWith([['name' => self::FEATURE, 'enabled' => false]]);
        $unleash = $this->unleash();
        $unleash->getFeatures();

        $unleash->refreshCache();

        $this->assertSame(0, $this->mockHandler->count());
        $this->assertFalse((new FeatureFile($this->path))->read()['features'][0]['enabled']);
    }

    public function testAskingForTheExpiryDoesNotSkipLoadingTheFlags()
    {
        (new FeatureFile($this->path))->write([['name' => self::FEATURE, 'enabled' => true]], time() + 15);
        $unleash = $this->unleash();

        $unleash->getExpires(); // the RefreshFeatures middleware does this on the shared instance

        $this->assertTrue($unleash->isFeatureEnabled(self::FEATURE));
    }

    private function respondWith(array $features): void
    {
        $this->mockHandler->append(new Response(200, [], json_encode(['version' => 1, 'features' => $features])));
    }

    private function unleash(array $config = []): Unleash
    {
        return new Unleash(
            fn () => $this->client(),
            fn () => $this->cache(),
            $this->config($config),
            $this->createMock(Request::class),
            new FeatureFile($this->path),
            $this->defer()
        );
    }

    private function defer(): Closure
    {
        return function (Closure $refresh) {
            $this->deferred[] = $refresh;
        };
    }

    private function runDeferred(): void
    {
        $deferred = $this->deferred;
        $this->deferred = [];
        foreach ($deferred as $refresh) {
            $refresh();
        }
    }

    private function client(): Client
    {
        return new Client(['handler' => $this->mockHandler]);
    }

    private function cache(): Cache
    {
        $cache = $this->createMock(Cache::class);
        $cache->method('get')->willReturnCallback(fn ($key, $default = null) => $this->cacheStore[$key] ?? $default);
        $cache->method('forever')->willReturnCallback(function ($key, $value) {
            $this->cacheStore[$key] = $value;

            return true;
        });

        return $cache;
    }

    private function config(array $overrides = []): Config
    {
        $values = array_replace_recursive(
            [
                'isEnabled' => true,
                'featuresEndpoint' => '/api/client/features',
                'cache' => ['isEnabled' => true, 'ttl' => 15, 'failover' => true],
                'strategies' => [
                    'testStrategy' => ImplementedStrategy::class,
                    'testStrategyThatIsDisabled' => ImplementedStrategyThatIsDisabled::class,
                    'nonImplementedStrategy' => NonImplementedStrategy::class,
                ],
            ],
            $overrides
        );

        $config = $this->createMock(Config::class);
        $config->method('get')->willReturnCallback(
            fn ($key, $default = null) => Arr::get(['unleash' => $values], $key, $default)
        );

        return $config;
    }
}
