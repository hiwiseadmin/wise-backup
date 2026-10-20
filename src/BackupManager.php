<?php

namespace wise\backup;

use wise\support\context\ContextualLogger;
use think\facade\Event;
use wise\backup\driver\MysqldumpDriver;
use wise\backup\driver\PdoDriver;
use wise\backup\event\AfterBackup;
use wise\backup\event\AfterRestore;
use wise\backup\event\AfterTableBackup;
use wise\backup\event\BeforeBackup;
use wise\backup\event\BeforeRestore;
use wise\backup\exception\BackupException;
use wise\backup\exception\RestoreException;
use wise\backup\exception\RemoteTransferException;
use wise\backup\helper\Compressor;
use wise\backup\storage\StorageManager;

/**
 * 备份管理器 — 核心编排器
 *
 * 协调驱动、存储、压缩等组件完成备份/还原/清理/迁移操作
 */
class BackupManager
{
    /** @var array 完整配置 */
    private array $config;

    /** @var BackupDriverInterface 数据库驱动 */
    private BackupDriverInterface $driver;

    /** @var StorageManager 存储管理器 */
    private StorageManager $storage;

    /** @var bool 驱动是否已初始化 */
    private bool $driverInitialized = false;

    /**
     * 构造函数
     *
     * @param array $config 完整备份配置
     */
    public function __construct(array $config)
    {
        $this->config = $config;

        // 初始化 StorageManager
        $disksConfig = $config['disks'] ?? [];
        $this->storage = new StorageManager($disksConfig);
    }

    /**
     * 解析并初始化数据库驱动
     *
     * 优先尝试 mysqldump，不可用时降级为 PDO
     *
     * @return BackupDriverInterface
     */
    public function resolveDriver(): BackupDriverInterface
    {
        if ($this->driverInitialized && isset($this->driver)) {
            return $this->driver;
        }

        // 获取数据库连接配置
        $connection = $this->config['connection'] ?? 'mysql';
        $dbConfig = $this->getDatabaseConfig($connection);

        // 尝试实例化 MysqldumpDriver
        $mysqldumpDriver = new MysqldumpDriver($dbConfig);
        $binaryPath = $mysqldumpDriver->detectMysqldump();

        if (!empty($binaryPath)) {
            try {
                $mysqldumpDriver->connect($dbConfig);
                $this->driver = $mysqldumpDriver;
                ContextualLogger::channel('backup')->info("Using mysqldump driver (binary: {$binaryPath})");
            } catch (\Throwable $e) {
                ContextualLogger::channel('backup')->warning("MysqldumpDriver connect failed: " . $e->getMessage() . ", falling back to PdoDriver");
                $this->driver = $this->createPdoDriver($dbConfig);
            }
        } else {
            ContextualLogger::channel('backup')->warning('mysqldump binary not found, falling back to PdoDriver');
            $this->driver = $this->createPdoDriver($dbConfig);
        }

        $this->driverInitialized = true;
        return $this->driver;
    }

    /**
     * 创建 PDO 驱动
     *
     * @param array $dbConfig
     * @return PdoDriver
     */
    private function createPdoDriver(array $dbConfig): PdoDriver
    {
        $driver = new PdoDriver($dbConfig);
        $driver->connect($dbConfig);
        ContextualLogger::channel('backup')->info("Using PdoDriver");
        return $driver;
    }

    /**
     * 设置驱动
     *
     * @param BackupDriverInterface $driver
     */
    public function setDriver(BackupDriverInterface $driver): void
    {
        $this->driver = $driver;
        $this->driverInitialized = true;
    }

    /**
     * 获取当前驱动
     *
     * @return BackupDriverInterface
     */
    public function getDriver(): BackupDriverInterface
    {
        if (!$this->driverInitialized) {
            $this->resolveDriver();
        }
        return $this->driver;
    }

    /**
     * 执行数据库备份
     *
     * @param array $options 备份选项（覆盖配置）
     * @return string ZIP 文件完整路径
     * @throws BackupException
     */
    public function backup(array $options = []): string
    {
        $startTime = microtime(true);

        // 合并选项与配置
        $destination = $options['destination'] ?? $this->config['destination'] ?? 'local';
        $destinations = $options['destinations'] ?? $this->config['destinations'] ?? ['local'];
        $compressLevel = $options['compress_level'] ?? $this->config['compress_level'] ?? 6;
        $chunkSize = $options['chunk_size'] ?? $this->config['chunk_size'] ?? 10000;
        $ignoreTables = $options['ignore_tables'] ?? $this->config['ignore_tables'] ?? [];
        $timeout = $options['timeout'] ?? $this->config['timeout'] ?? 3600;

        // 设置超时
        set_time_limit((int) $timeout);

        // 获取驱动
        $driver = $this->resolveDriver();

        // 获取表列表
        $allTables = $driver->getTables($ignoreTables);

        // 如果指定了 --tables，取交集
        if (!empty($options['tables'])) {
            $requestedTables = is_array($options['tables'])
                ? $options['tables']
                : explode(',', $options['tables']);
            $allTables = array_intersect($allTables, $requestedTables);
        }

        // 如果指定了 --exclude，减去
        if (!empty($options['exclude'])) {
            $excludePatterns = is_array($options['exclude'])
                ? $options['exclude']
                : explode(',', $options['exclude']);
            $allTables = array_filter($allTables, function ($table) use ($excludePatterns) {
                foreach ($excludePatterns as $pattern) {
                    if (fnmatch(trim($pattern), $table)) {
                        return false;
                    }
                }
                return true;
            });
        }

        // 重新索引
        $allTables = array_values($allTables);

        if (empty($allTables)) {
            throw new BackupException("No tables found to backup");
        }

        // 触发 BeforeBackup 事件
        Event::trigger(new BeforeBackup($allTables, $destination));

        ContextualLogger::channel('backup')->info("Starting backup of " . count($allTables) . " tables");

        // 禁用外键检查
        $driver->disableForeignKeys();

        // 创建临时目录
        $tmpDir = runtime_path() . 'backup' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . uniqid('backup_', true);
        if (!is_dir($tmpDir)) {
            if (!mkdir($tmpDir, 0755, true)) {
                $driver->enableForeignKeys();
                throw new BackupException("Failed to create temporary directory: {$tmpDir}");
            }
        }

        $tempFiles = [];

        try {
            // 遍历每张表
            foreach ($allTables as $table) {
                ContextualLogger::channel('backup')->info("Backing up table: {$table}");

                $tableFile = $tmpDir . DIRECTORY_SEPARATOR . $table . '.sql';
                $content = '';

                // 导出建表语句
                $createSql = $driver->exportCreateTable($table);
                $content .= $createSql;

                // 分块导出数据
                $offset = 0;
                $totalRows = 0;
                while (true) {
                    $data = $driver->exportData($table, $offset, $chunkSize);
                    if (empty(trim($data))) {
                        break;
                    }
                    $content .= $data;
                    // 统计实际数据行数：兼容 extended-insert 和 PdoDriver 换行格式
                    // 跳过无INSERT语句的内容块（如只有空白字符）
                    if (stripos($data, 'INSERT') !== false) {
                        $batchRows = preg_match_all('/\)\s*,\s*\(/m', $data) + 1;
                        $totalRows += $batchRows;
                    }
                    $offset += $chunkSize;
                }

                // 写入临时文件
                if (file_put_contents($tableFile, $content) === false) {
                    throw new BackupException("Failed to write temporary file for table {$table}");
                }

                $tempFiles[$table . '.sql'] = $tableFile;

                // 触发 AfterTableBackup 事件
                Event::trigger(new AfterTableBackup($table, $tableFile, $totalRows));

                ContextualLogger::channel('backup')->info("Table {$table} backed up: {$totalRows} rows");
            }

            // 启用外键检查
            $driver->enableForeignKeys();

            // 生成 ZIP 文件名
            $zipFileName = 'backup_' . date('Ymd_His') . '.zip';
            $localRoot = $this->config['disks']['local']['root'] ?? '';
            if (empty($localRoot)) {
                $localRoot = runtime_path() . 'backup';
            }
            if (!is_dir($localRoot)) {
                @mkdir($localRoot, 0755, true);
            }
            $zipFilePath = $localRoot . DIRECTORY_SEPARATOR . $zipFileName;

            // 压缩为 ZIP
            Compressor::compress($tempFiles, $zipFilePath, (int) $compressLevel, true);

            ContextualLogger::channel('backup')->info("ZIP file created: {$zipFilePath}");

            // 复制到远程 disk
            if (!empty($destinations)) {
                $remoteResults = $this->storage->copyToAll($zipFilePath, $zipFileName);
                foreach ($remoteResults as $diskName => $success) {
                    if ($success) {
                        ContextualLogger::channel('backup')->info("Backup copied to remote disk '{$diskName}'");
                    } else {
                        ContextualLogger::channel('backup')->warning("Failed to copy backup to remote disk '{$diskName}'");
                    }
                }
            }

            // 触发 AfterBackup 事件
            $duration = round(microtime(true) - $startTime, 2);
            Event::trigger(new AfterBackup($zipFilePath, $allTables, $duration));

            ContextualLogger::channel('backup')->info("Backup completed in {$duration}s");

            return $zipFilePath;
        } catch (\Throwable $e) {
            // 启用外键检查
            try {
                $driver->enableForeignKeys();
            } catch (\Throwable $fkException) {
                // 忽略
            }

            ContextualLogger::channel('backup')->error("Backup failed: " . $e->getMessage());

            if ($e instanceof BackupException) {
                throw $e;
            }
            throw new BackupException("Backup failed: " . $e->getMessage(), 0, $e);
        } finally {
            // 清理临时目录
            $this->cleanupTempDir($tmpDir);
        }
    }

    /**
     * 还原数据库备份
     *
     * @param string $file    备份 ZIP 文件路径（支持 sftp:// 或 s3:// 协议前缀）
     * @param array  $options 还原选项
     * @return bool
     * @throws RestoreException|BackupException
     * @warning 恢复操作不保证原子性。如果中途失败，已恢复的表保持新状态，未恢复的表保持原状态。
     *          建议在生产环境恢复前先手动备份当前数据库。
     */
    public function restore(string $file, array $options = []): bool
    {
        $startTime = microtime(true);
        $dropTables = $options['drop_tables'] ?? false;
        $force = $options['force'] ?? false;
        $timeout = $options['timeout'] ?? $this->config['timeout'] ?? 3600;

        set_time_limit((int) $timeout);

        $localFile = $file;

        // 如果以协议前缀开头，从远程下载
        if (preg_match('#^(sftp|s3|ftp)://(.+)#', $file, $matches)) {
            $protocol = $matches[1];
            $remotePath = $matches[2];

            $tmpZipDir = runtime_path() . 'backup' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . uniqid('restore_', true);
            if (!is_dir($tmpZipDir)) {
                mkdir($tmpZipDir, 0755, true);
            }

            $localFile = $tmpZipDir . DIRECTORY_SEPARATOR . basename($remotePath);

            try {
                $content = $this->storage->read($protocol, $remotePath);
                if (file_put_contents($localFile, $content) === false) {
                    throw new RemoteTransferException("Failed to write downloaded backup to local file: {$localFile}");
                }
            } catch (\Throwable $e) {
                throw new RemoteTransferException("Failed to download backup from remote: " . $e->getMessage(), 0, $e);
            }
        }

        // 验证文件存在
        if (!file_exists($localFile)) {
            throw new RestoreException("Backup file not found: {$localFile}");
        }

        // 触发 BeforeRestore 事件
        Event::trigger(new BeforeRestore($localFile, $dropTables));

        // 创建解压临时目录
        $extractDir = runtime_path() . 'backup' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . uniqid('extract_', true);
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        $tablesRestored = [];
        $duration = 0;

        try {
            // 解压 ZIP
            $extractedFiles = Compressor::extract($localFile, $extractDir);

            // 获取驱动并连接
            $driver = $this->resolveDriver();

            // 禁用外键检查
            $driver->disableForeignKeys();

            // 如果需要先删除表
            if ($dropTables) {
                foreach ($extractedFiles as $sqlFile) {
                    $tableName = basename($sqlFile, '.sql');
                    try {
                        $driver->importSql("DROP TABLE IF EXISTS `{$tableName}`");
                        ContextualLogger::channel('backup')->info("Dropped table: {$tableName}");
                    } catch (\Throwable $e) {
                        ContextualLogger::channel('backup')->warning("Failed to drop table {$tableName}: " . $e->getMessage());
                    }
                }
            }

            // 遍历每个 SQL 文件还原
            // NOTE: 恢复操作不保证原子性。如果中途失败，已恢复的表保持新状态，未恢复的表保持原状态。
            // 建议在生产环境恢复前先手动备份当前数据库。
            foreach ($extractedFiles as $sqlFile) {
                $tableName = basename($sqlFile, '.sql');

                // 如果 --force 且表存在，先备份现表
                if ($force) {
                    try {
                        $tables = $driver->getTables();
                        if (in_array($tableName, $tables)) {
                            $bakFile = $extractDir . DIRECTORY_SEPARATOR . $tableName . '.bak.sql';
                            $driver->backupTable($tableName, $bakFile);
                            ContextualLogger::channel('backup')->info("Existing table {$tableName} backed up before restore");
                        }
                    } catch (\Throwable $e) {
                        ContextualLogger::channel('backup')->warning("Failed to backup existing table {$tableName}: " . $e->getMessage());
                    }
                }

                // 导入 SQL 文件
                try {
                    $driver->importFile($sqlFile);
                    $tablesRestored[] = $tableName;
                    ContextualLogger::channel('backup')->info("Table restored: {$tableName}");
                } catch (\Throwable $e) {
                    // 导入失败 → 尝试恢复 .bak
                    $bakFile = $extractDir . DIRECTORY_SEPARATOR . $tableName . '.bak.sql';
                    if (file_exists($bakFile)) {
                        try {
                            $driver->importFile($bakFile);
                            ContextualLogger::channel('backup')->info("Table {$tableName} rolled back from .bak");
                        } catch (\Throwable $rollbackException) {
                            ContextualLogger::channel('backup')->error("Failed to rollback table {$tableName}: " . $rollbackException->getMessage());
                        }
                    }
                    throw new RestoreException("Failed to restore table {$tableName}: " . $e->getMessage(), 0, $e);
                }
            }

            // 启用外键检查
            $driver->enableForeignKeys();

            ContextualLogger::channel('backup')->info("Restore completed, tables: " . implode(', ', $tablesRestored));

            return true;
        } catch (\Throwable $e) {
            // 尝试启用外键检查
            try {
                if (isset($driver)) {
                    $driver->enableForeignKeys();
                }
            } catch (\Throwable $fkException) {
                // 忽略
            }

            ContextualLogger::channel('backup')->error("Restore failed: " . $e->getMessage());

            if ($e instanceof RestoreException || $e instanceof BackupException) {
                throw $e;
            }
            throw new RestoreException("Restore failed: " . $e->getMessage(), 0, $e);
        } finally {
            // 在清理前触发 AfterRestore 事件，确保监听器能访问文件
            if (!empty($tablesRestored)) {
                $duration = round(microtime(true) - $startTime, 2);
                Event::trigger(new AfterRestore($localFile, $tablesRestored, $duration));
            }

            // 清理临时目录
            $this->cleanupTempDir($extractDir);
            if (isset($tmpZipDir)) {
                $this->cleanupTempDir($tmpZipDir);
            }
        }
    }

    /**
     * 清理过期备份文件
     *
     * @return int 删除的文件数量
     */
    public function clean(): int
    {
        $retention = $this->config['retention'] ?? [
            'daily'   => 7,
            'weekly'  => 4,
            'monthly' => 6,
        ];

        $disks = $this->storage->allDisks();

        $cleanupEngine = new CleanupEngine($this->storage, $retention);
        $result = $cleanupEngine->execute($disks);

        $deletedCount = $result['deleted'] ?? 0;
        $errors = $result['errors'] ?? [];

        if (!empty($errors)) {
            foreach ($errors as $disk => $msg) {
                ContextualLogger::channel('backup')->error("Cleanup failed on disk '{$disk}': {$msg}");
            }
        }

        ContextualLogger::channel('backup')->info("Cleanup completed: {$deletedCount} files deleted, " . count($errors) . " errors");

        return $deletedCount;
    }

    /**
     * 从远程源迁移备份
     *
     * @param string $source  远程源路径
     * @param array  $options 选项
     * @return string 本地 ZIP 路径
     */
    public function migrate(string $source, array $options = []): string
    {
        ContextualLogger::channel('backup')->info("Starting migration from source: {$source}");

        // 从远程下载备份到本地
        $tmpDir = runtime_path() . 'backup' . DIRECTORY_SEPARATOR . 'tmp' . DIRECTORY_SEPARATOR . uniqid('migrate_', true);
        if (!is_dir($tmpDir)) {
            mkdir($tmpDir, 0755, true);
        }

        $zipFileName = basename($source);
        if (empty($zipFileName)) {
            $zipFileName = 'migrated_' . date('Ymd_His') . '.zip';
        }

        $localZipPath = $tmpDir . DIRECTORY_SEPARATOR . $zipFileName;

        try {
            // 解析远程源
            if (preg_match('#^(sftp|s3|ftp)://(.+)#', $source, $matches)) {
                $protocol = $matches[1];
                $remotePath = $matches[2];
                $content = $this->storage->read($protocol, $remotePath);
                file_put_contents($localZipPath, $content);
            } else {
                // 假设是本地文件
                if (!copy($source, $localZipPath)) {
                    throw new RemoteTransferException("Failed to copy file from {$source} to {$localZipPath}");
                }
            }

            // 执行还原
            $result = $this->restore($localZipPath, $options);
            if (!$result) {
                throw new BackupException("Migration restore failed for: {$source}");
            }

            ContextualLogger::channel('backup')->info("Migration completed: {$localZipPath}");

            return $localZipPath;
        } catch (\Throwable $e) {
            ContextualLogger::channel('backup')->error("Migration failed: " . $e->getMessage());
            throw new BackupException("Migration failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取数据库连接配置
     *
     * @param string $connection 连接名称
     * @return array
     */
    private function getDatabaseConfig(string $connection): array
    {
        // 从 ThinkPHP 配置中读取数据库连接信息
        $dbConfig = config('database.connections.' . $connection, []);

        if (empty($dbConfig)) {
            // 回退到默认配置
            $dbConfig = config('database.connections.mysql', []);
        }

        return [
            'host'     => $dbConfig['hostname'] ?? $dbConfig['host'] ?? '127.0.0.1',
            'port'     => $dbConfig['hostport'] ?? $dbConfig['port'] ?? 3306,
            'database' => $dbConfig['database'] ?? '',
            'username' => $dbConfig['username'] ?? 'root',
            'password' => $dbConfig['password'] ?? '',
            'charset'  => $dbConfig['charset'] ?? 'utf8mb4',
        ];
    }

    /**
     * 清理临时目录
     *
     * @param string $dir 目录路径
     */
    private function cleanupTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($files as $file) {
            if ($file->isDir()) {
                @rmdir($file->getRealPath());
            } else {
                @unlink($file->getRealPath());
            }
        }

        @rmdir($dir);
    }
}
