<?php

namespace MikeFrancis\LaravelUnleash;

/**
 * The flags of the last fetch, kept in a local file that every process on the machine reads.
 * Reading a flag then costs a small file read instead of a round trip to a cache server.
 */
class FeatureFile
{
    public function __construct(private string $path)
    {
    }

    /**
     * @return array{features: array, expires: int}|null null when there is no (valid) file yet
     */
    public function read(): ?array
    {
        $json = @file_get_contents($this->path);
        if ($json === false) {
            return null;
        }

        $data = json_decode($json, true);
        if (!is_array($data) || !is_array($data['features'] ?? null) || !is_int($data['expires'] ?? null)) {
            return null;
        }

        return ['features' => $data['features'], 'expires' => $data['expires']];
    }

    /**
     * Write to a temporary file and rename it over the old one, so a reader never sees half a file.
     */
    public function write(array $features, int $expires): bool
    {
        $json = json_encode(['features' => $features, 'expires' => $expires]);
        $tmp = $this->path . '.' . uniqid('', true) . '.tmp';
        if ($json === false || @file_put_contents($tmp, $json) === false) {
            return false;
        }
        if (!@rename($tmp, $this->path)) {
            @unlink($tmp);

            return false;
        }

        return true;
    }

    /**
     * Run $task while holding the lock on the file. Without $wait, a task that finds the lock taken is
     * skipped: another process is already doing the same work. When no lock file can be opened at all,
     * the task runs unlocked rather than not at all.
     */
    public function withLock(callable $task, bool $wait): void
    {
        $handle = @fopen($this->path . '.lock', 'c');
        if ($handle === false) {
            $task();

            return;
        }

        try {
            if (!flock($handle, $wait ? LOCK_EX : LOCK_EX | LOCK_NB)) {
                return;
            }
            try {
                $task();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }
}
