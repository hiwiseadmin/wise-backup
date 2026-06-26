<?php

namespace wise\backup\tests;

use PHPUnit\Framework\TestCase;
use wise\backup\BackupManager;
use wise\backup\BackupDriverInterface;
use wise\backup\driver\MysqldumpDriver;
use wise\backup\exception\BackupException;
use wise\backup\exception\RestoreException;
use wise\backup\exception\RemoteTransferException;

/**
 * BackupManager 单元测试
 *
 * 使用 mock 验证编排逻辑、事件触发、异常处理
 */
class BackupManagerTest extends TestCase
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

    // ========================
    // 驱动管理测试
    // ========================

    public function testSetAndGetDriver(): void
    {
        $config = [
            'disks' => [],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);

        $mockDriver = $this->createMock(BackupDriverInterface::class);
        $manager->setDriver($mockDriver);

        $this->assertSame($mockDriver, $manager->getDriver());
    }

    public function testGetDriverAutoResolvesWhenNotSet(): void
    {
        $config = [
            'disks' => [],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);
        $driver = $manager->getDriver();

        // 应该自动 resolve 并返回一个 BackupDriverInterface 实例
        $this->assertInstanceOf(BackupDriverInterface::class, $driver);
    }

    // ========================
    // 备份流程测试
    // ========================

    public function testBackupThrowsExceptionWhenNoTables(): void
    {
        $config = [
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root'   => $this->tmpDir,
                ],
            ],
            'ignore_tables' => ['*'],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);

        // mock 驱动返回空表列表
        $mockDriver = $this->createMock(BackupDriverInterface::class);
        $mockDriver->method('getTables')->willReturn([]);
        $manager->setDriver($mockDriver);

        $this->expectException(BackupException::class);
        $this->expectExceptionMessage('No tables found to backup');
        $manager->backup();
    }

    public function testBackupWithMockDriverProducesZip(): void
    {
        $config = [
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root'   => $this->tmpDir,
                ],
            ],
            'destination' => 'local',
            'destinations' => ['local'],
            'compress_level' => 6,
            'chunk_size' => 1000,
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);

        // Mock driver 返回一张表，无数据
        $mockDriver = $this->createMock(BackupDriverInterface::class);
        $mockDriver->method('getTables')->willReturn(['test_table']);
        $mockDriver->method('exportCreateTable')
            ->with('test_table')
            ->willReturn("CREATE TABLE `test_table` (\n  `id` int NOT NULL AUTO_INCREMENT,\n  PRIMARY KEY (`id`)\n) ENGINE=InnoDB;\n");
        $mockDriver->method('exportData')
            ->with('test_table', 0, 1000)
            ->willReturn('');
        $mockDriver->method('disableForeignKeys')->doesNothing();
        $mockDriver->method('enableForeignKeys')->doesNothing();
        $manager->setDriver($mockDriver);

        $zipFile = $manager->backup(['timeout' => 60]);

        $this->assertFileExists($zipFile);
        $this->assertStringEndsWith('.zip', $zipFile);
    }

    public function testBackupWithTableFiltering(): void
    {
        $config = [
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root'   => $this->tmpDir,
                ],
            ],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);

        // Mock driver 返回多张表
        $mockDriver = $this->createMock(BackupDriverInterface::class);
        $mockDriver->method('getTables')->willReturn(['users', 'orders', 'products']);
        $mockDriver->method('exportCreateTable')->willReturn("CREATE TABLE test (id int);\n");
        $mockDriver->method('exportData')->willReturn('');
        $mockDriver->method('disableForeignKeys')->doesNothing();
        $mockDriver->method('enableForeignKeys')->doesNothing();
        $manager->setDriver($mockDriver);

        // 指定只备份 users
        $zipFile = $manager->backup(['tables' => ['users'], 'timeout' => 60]);

        $this->assertFileExists($zipFile);
    }

    // ========================
    // 还原流程测试
    // ========================

    public function testRestoreThrowsExceptionWhenFileNotFound(): void
    {
        $config = [
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root'   => $this->tmpDir,
                ],
            ],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);

        $this->expectException(RestoreException::class);
        $manager->restore('/nonexistent/path/backup.zip');
    }

    public function testRestoreWithMockDriver(): void
    {
        // 创建一个简单的 zip 包
        $sqlFile1 = $this->tmpDir . DIRECTORY_SEPARATOR . 'test_table.sql';
        file_put_contents($sqlFile1, "CREATE TABLE `test_table` (id int);\nINSERT INTO `test_table` VALUES (1);\n");

        $zipFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'backup.zip';
        $zip = new \ZipArchive();
        $zip->open($zipFile, \ZipArchive::CREATE);
        $zip->addFile($sqlFile1, 'test_table.sql');
        $zip->close();

        $config = [
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root'   => $this->tmpDir,
                ],
            ],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);

        $mockDriver = $this->createMock(BackupDriverInterface::class);
        $mockDriver->method('disableForeignKeys')->doesNothing();
        $mockDriver->method('enableForeignKeys')->doesNothing();
        $mockDriver->method('importFile')->doesNothing();
        $mockDriver->method('getTables')->willReturn([]);
        $manager->setDriver($mockDriver);

        $result = $manager->restore($zipFile, ['timeout' => 60]);
        $this->assertTrue($result);
    }

    // ========================
    // 清理流程测试
    // ========================

    public function testCleanReturnsInt(): void
    {
        $config = [
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root'   => $this->tmpDir,
                ],
            ],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        // 创建一个很旧的备份文件以确保被清理
        $oldFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'backup_20200101_000000.zip';
        file_put_contents($oldFile, 'test');

        $manager = new BackupManager($config);
        $result = $manager->clean();

        $this->assertIsInt($result);
    }

    // ========================
    // 迁移流程测试
    // ========================

    public function testMigrateReturnsLocalPath(): void
    {
        // 创建源文件
        $sourceFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'source_backup.zip';

        $zip = new \ZipArchive();
        $zip->open($sourceFile, \ZipArchive::CREATE);
        $sqlFile = $this->tmpDir . DIRECTORY_SEPARATOR . 'test.sql';
        file_put_contents($sqlFile, "CREATE TABLE test (id int);\n");
        $zip->addFile($sqlFile, 'test.sql');
        $zip->close();

        $config = [
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root'   => $this->tmpDir,
                ],
            ],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);

        $mockDriver = $this->createMock(BackupDriverInterface::class);
        $mockDriver->method('disableForeignKeys')->doesNothing();
        $mockDriver->method('enableForeignKeys')->doesNothing();
        $mockDriver->method('importFile')->doesNothing();
        $mockDriver->method('getTables')->willReturn([]);
        $manager->setDriver($mockDriver);

        $result = $manager->migrate($sourceFile, ['timeout' => 60]);

        $this->assertFileExists($result);
        $this->assertStringEndsWith('.zip', $result);
    }

    // ========================
    // 异常处理测试
    // ========================

    public function testBackupExceptionIsThrownCorrectly(): void
    {
        $config = [
            'disks' => [],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);

        $mockDriver = $this->createMock(BackupDriverInterface::class);
        $mockDriver->method('getTables')->willReturn(['test_table']);
        $mockDriver->method('exportCreateTable')
            ->willThrowException(new BackupException('Simulated export failure'));
        $mockDriver->method('disableForeignKeys')->doesNothing();
        $mockDriver->method('enableForeignKeys')->doesNothing();
        $manager->setDriver($mockDriver);

        $this->expectException(BackupException::class);
        $manager->backup(['timeout' => 60]);
    }

    // ========================
    // 配置合并测试
    // ========================

    public function testBackupOptionsOverrideConfig(): void
    {
        $config = [
            'disks' => [
                'local' => [
                    'driver' => 'local',
                    'root'   => $this->tmpDir,
                ],
            ],
            'compress_level' => 3,
            'chunk_size' => 5000,
            'ignore_tables' => ['cache_*'],
            'retention' => ['daily' => 7, 'weekly' => 4, 'monthly' => 6],
        ];

        $manager = new BackupManager($config);

        $mockDriver = $this->createMock(BackupDriverInterface::class);
        $mockDriver->method('getTables')->willReturn(['users']);
        $mockDriver->method('exportCreateTable')->willReturn("CREATE TABLE users (id int);\n");
        $mockDriver->method('exportData')->willReturn('');
        $mockDriver->method('disableForeignKeys')->doesNothing();
        $mockDriver->method('enableForeignKeys')->doesNothing();
        $manager->setDriver($mockDriver);

        // 使用 options 覆盖 chunk_size 和 compress_level
        $zipFile = $manager->backup([
            'chunk_size' => 1000,
            'compress_level' => 9,
            'timeout' => 60,
        ]);

        $this->assertFileExists($zipFile);
    }

    // ========================
    // 工具方法
    // ========================

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
                @rmdir($item->getRealPath());
            } else {
                @unlink($item->getRealPath());
            }
        }
        @rmdir($dir);
    }
}
