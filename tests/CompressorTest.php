<?php

namespace wise\backup\tests;

use PHPUnit\Framework\TestCase;

class CompressorTest extends TestCase
{
    private string $tmpDir;

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'wise_backup_test_' . uniqid();
        mkdir($this->tmpDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $this->removeDir($this->tmpDir);
    }

    public function testCompressAndExtract(): void
    {
        $file1 = $this->tmpDir . DIRECTORY_SEPARATOR . 'test1.txt';
        $file2 = $this->tmpDir . DIRECTORY_SEPARATOR . 'test2.txt';
        file_put_contents($file1, 'Hello World 1');
        file_put_contents($file2, 'Hello World 2');

        $zipFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'test.zip';
        $extractDir = $this->tmpDir . DIRECTORY_SEPARATOR . 'extracted';

        $files = [
            'test1.txt' => $file1,
            'test2.txt' => $file2,
        ];

        $result = \wise\backup\helper\Compressor::compress($files, $zipFile, 6, true);
        $this->assertEquals($zipFile, $result);
        $this->assertFileExists($zipFile);

        $this->assertFileDoesNotExist($file1);
        $this->assertFileDoesNotExist($file2);

        $extracted = \wise\backup\helper\Compressor::extract($zipFile, $extractDir);
        $this->assertCount(2, $extracted);
        $this->assertDirectoryExists($extractDir);

        $extracted1 = $extractDir . DIRECTORY_SEPARATOR . 'test1.txt';
        $extracted2 = $extractDir . DIRECTORY_SEPARATOR . 'test2.txt';
        $this->assertFileExists($extracted1);
        $this->assertFileExists($extracted2);
        $this->assertEquals('Hello World 1', file_get_contents($extracted1));
        $this->assertEquals('Hello World 2', file_get_contents($extracted2));
    }

    public function testVerify(): void
    {
        $filePath = $this->tmpDir . DIRECTORY_SEPARATOR . 'data.txt';
        file_put_contents($filePath, 'Test data for verification');

        $zipFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'verified.zip';
        \wise\backup\helper\Compressor::compress(['data.txt' => $filePath], $zipFile);

        $this->assertTrue(\wise\backup\helper\Compressor::verify($zipFile));
    }

    public function testVerifyWithInvalidFile(): void
    {
        $this->assertFalse(\wise\backup\helper\Compressor::verify('/nonexistent/file.zip'));

        $nonZipFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'not-a-zip.txt';
        file_put_contents($nonZipFile, 'This is not a ZIP file');
        $this->assertFalse(\wise\backup\helper\Compressor::verify($nonZipFile));
    }

    public function testCompressWithNonexistentFile(): void
    {
        $zipFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'should-fail.zip';

        $this->expectException(\wise\backup\exception\BackupException::class);
        \wise\backup\helper\Compressor::compress(
            ['missing.txt' => '/nonexistent/file.txt'],
            $zipFile
        );
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
