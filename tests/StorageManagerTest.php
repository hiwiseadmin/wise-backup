<?php

namespace wise\backup\tests;

use PHPUnit\Framework\TestCase;
use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;

class StorageManagerTest extends TestCase
{
    private string $tmpRoot;

    protected function setUp(): void
    {
        $this->tmpRoot = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_backup_storage_' . uniqid();
        mkdir($this->tmpRoot, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpRoot);
    }

    public function testLocalDiskReadWrite(): void
    {
        $config = [
            'local' => [
                'driver' => 'local',
                'root'   => $this->tmpRoot,
            ],
        ];

        $storage = new \wise\backup\storage\StorageManager($config);

        $storage->put('local', 'test.txt', 'Hello Storage');
        $this->assertTrue($storage->exists('local', 'test.txt'));

        $content = $storage->read('local', 'test.txt');
        $this->assertEquals('Hello Storage', $content);

        $storage->delete('local', 'test.txt');
        $this->assertFalse($storage->exists('local', 'test.txt'));
    }

    public function testListContents(): void
    {
        $config = [
            'local' => [
                'driver' => 'local',
                'root'   => $this->tmpRoot,
            ],
        ];

        $storage = new \wise\backup\storage\StorageManager($config);

        $storage->put('local', 'backup_20250101_120000.zip', 'content1');
        $storage->put('local', 'backup_20250102_120000.zip', 'content2');
        $storage->put('local', 'other.txt', 'other');

        $contents = $storage->listContents('local', '', false);
        $this->assertGreaterThanOrEqual(3, count($contents));
    }

    public function testAllDisks(): void
    {
        $config = [
            'local1' => ['driver' => 'local', 'root' => $this->tmpRoot . '/d1'],
            'local2' => ['driver' => 'local', 'root' => $this->tmpRoot . '/d2'],
        ];

        $storage = new \wise\backup\storage\StorageManager($config);
        $disks = $storage->allDisks();

        $this->assertContains('local1', $disks);
        $this->assertContains('local2', $disks);
    }

    public function testUnknownDiskThrowsException(): void
    {
        $config = [
            'local' => ['driver' => 'local', 'root' => $this->tmpRoot],
        ];

        $storage = new \wise\backup\storage\StorageManager($config);

        $this->expectException(\wise\backup\exception\RemoteTransferException::class);
        $storage->disk('nonexistent');
    }

    private function removeDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($items as $item) {
            if ($item->isDir()) {
                rmdir($item->getRealPath());
            } else {
                unlink($item->getRealPath());
            }
        }
        rmdir($dir);
    }
}
