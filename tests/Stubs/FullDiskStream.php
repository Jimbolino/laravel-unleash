<?php

namespace MikeFrancis\LaravelUnleash\Tests\Stubs;

// phpcs:disable PSR1.Methods.CamelCapsMethodName -- PHP names the stream wrapper methods

/**
 * The local file system on a full disk: files can be created, but only their first bytes fit.
 * Registered as a stream wrapper, it serves the local path that follows its protocol.
 */
class FullDiskStream
{
    private const ROOM = 8;

    /** @var resource|null set by PHP */
    public $context;

    /** @var resource */
    private $handle;

    private int $room = self::ROOM;

    public function stream_open(string $path, string $mode): bool
    {
        $handle = fopen($this->localPath($path), $mode);
        if ($handle === false) {
            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function stream_write(string $data): int
    {
        $written = (int) fwrite($this->handle, substr($data, 0, $this->room));
        $this->room -= $written;

        return $written;
    }

    public function stream_lock(int $operation): bool
    {
        return flock($this->handle, $operation);
    }

    public function stream_close(): void
    {
        fclose($this->handle);
    }

    public function rename(string $from, string $to): bool
    {
        return rename($this->localPath($from), $this->localPath($to));
    }

    public function unlink(string $path): bool
    {
        return unlink($this->localPath($path));
    }

    private function localPath(string $path): string
    {
        return substr($path, strpos($path, '://') + 3);
    }
}
