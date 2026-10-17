<?php

namespace MikeFrancis\LaravelUnleash\Tests;

use MikeFrancis\LaravelUnleash\FeatureFile;
use MikeFrancis\LaravelUnleash\Tests\Stubs\FullDiskStream;
use PHPUnit\Framework\TestCase;

class FeatureFileTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        parent::setUp();

        stream_wrapper_register('fulldisk', FullDiskStream::class);
        $this->path = tempnam(sys_get_temp_dir(), 'unleash-test-');
        unlink($this->path);
    }

    protected function tearDown(): void
    {
        stream_wrapper_unregister('fulldisk');
        foreach (glob($this->path . '*') ?: [] as $file) {
            unlink($file);
        }

        parent::tearDown();
    }

    public function testAFailedWriteLeavesNoTemporaryFileBehind()
    {
        $file = new FeatureFile('fulldisk://' . $this->path);

        $this->assertFalse($file->write([['name' => 'someFeature', 'enabled' => true]], time() + 15));
        $this->assertSame([], glob($this->path . '*'), 'each failed write would leave a file on the full disk');
    }
}
