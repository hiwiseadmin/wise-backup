<?php

namespace wise\backup\driver;

use wise\backup\BackupDriverInterface;
use wise\backup\exception\BackupException;
use wise\backup\helper\SqlSplitter;

/**
 * PDO 纯 PHP 驱动
 *
 * 不依赖 mysqldump 二进制文件，纯 PHP 实现备份/还原操作
 */
class PdoDriver implements BackupDriverInterface
{
    /** @var ?\PDO PDO 连接实例 */
    private ?\PDO $pdo = null;

    /** @var string MySQL 版本号 */
    private string $mysqlVersion = '';

    /** @var array 数据库连接配置 */
    private array $dbConfig = [];

    /** @var array 列信息缓存，避免同表多次分块重复执行 SHOW COLUMNS FROM */
    private array $columnsCache = [];

    /**
     * 构造函数
     *
     * @param array $dbConfig 数据库配置
     */
    public function __construct(array $dbConfig = [])
    {
        $this->dbConfig = $dbConfig;
    }

    /**
     * 连接数据库
     *
     * @param array $config 数据库连接配置
     */
    public function connect(array $config): void
    {
        $this->dbConfig = array_merge($this->dbConfig, $config);

        $host = $this->dbConfig['host'] ?? '127.0.0.1';
        $port = $this->dbConfig['port'] ?? 3306;
        $database = $this->dbConfig['database'] ?? '';
        $charset = $this->dbConfig['charset'] ?? 'utf8mb4';
        $username = $this->dbConfig['username'] ?? 'root';
        $password = $this->dbConfig['password'] ?? '';

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $host,
                $port,
                $database,
                $charset
            );

            $this->pdo = new \PDO($dsn, $username, $password, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
            ]);

            // 获取 MySQL 版本
            $version = $this->pdo->query('SELECT VERSION()')->fetchColumn();
            $this->mysqlVersion = (string) $version;
        } catch (\PDOException $e) {
            throw new BackupException("PDO connection failed: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 获取 MySQL 版本号
     *
     * @return string
     */
    public function getMysqlVersion(): string
    {
        return $this->mysqlVersion;
    }

    /**
     * 获取表列表
     *
     * @param array $ignoreTables 忽略的表（glob 模式）
     * @return array
     */
    public function getTables(array $ignoreTables = []): array
    {
        if ($this->pdo === null) {
            throw new BackupException("Not connected to database");
        }

        $stmt = $this->pdo->query('SHOW TABLES');
        $tables = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($ignoreTables)) {
            return $tables;
        }

        $filtered = [];
        foreach ($tables as $table) {
            $ignored = false;
            foreach ($ignoreTables as $pattern) {
                if (fnmatch($pattern, $table)) {
                    $ignored = true;
                    break;
                }
            }
            if (!$ignored) {
                $filtered[] = $table;
            }
        }

        return $filtered;
    }

    /**
     * 导出建表语句
     *
     * @param string $table 表名
     * @return string
     */
    public function exportCreateTable(string $table): string
    {
        if ($this->pdo === null) {
            throw new BackupException("Not connected to database");
        }

        $stmt = $this->pdo->query("SHOW CREATE TABLE `{$table}`");
        $row = $stmt->fetch(\PDO::FETCH_NUM);

        if ($row === false || !isset($row[1])) {
            throw new BackupException("Failed to get CREATE TABLE for {$table}");
        }

        return $row[1] . ";\n";
    }

    /**
     * 分块导出数据
     *
     * 使用 PDO 游标逐行读取，批量构造 INSERT 语句以控制内存使用
     *
     * @param string $table     表名
     * @param int    $offset    偏移量
     * @param int    $chunkSize 分块大小
     * @return string
     */
    public function exportData(string $table, int $offset, int $chunkSize): string
    {
        if ($this->pdo === null) {
            throw new BackupException("Not connected to database");
        }

        // 获取列信息（缓存避免重复查询）
        if (!isset($this->columnsCache[$table])) {
            $columnsStmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}`");
            $this->columnsCache[$table] = $columnsStmt->fetchAll(\PDO::FETCH_COLUMN, 0);
        }
        $columns = $this->columnsCache[$table];

        if (empty($columns)) {
            return '';
        }

        $colList = '`' . implode('`, `', $columns) . '`';

        // 分页查询（强制整型避免 SQL 注入）
        $sql = sprintf("SELECT * FROM `%s` LIMIT %d, %d", $table, (int) $offset, (int) $chunkSize);
        $stmt = $this->pdo->query($sql);

        $result = '';
        $buffer = [];
        $rowCount = 0;

        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $this->pdo->quote($value);
                }
            }

            $buffer[] = '(' . implode(', ', $values) . ')';
            $rowCount++;

            // 缓冲区满 500 行或循环结束 → 输出 INSERT 语句
            if (count($buffer) >= 500) {
                $result .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $buffer) . ";\n";
                $buffer = [];
            }
        }

        // 输出剩余缓冲
        if (!empty($buffer)) {
            $result .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $buffer) . ";\n";
        }

        return $result;
    }

    /**
     * 执行 SQL 语句
     *
     * @param string $sql SQL 语句
     */
    public function importSql(string $sql): void
    {
        if ($this->pdo === null) {
            throw new BackupException("Not connected to database");
        }

        try {
            $this->pdo->exec($sql);
        } catch (\PDOException $e) {
            throw new BackupException("Failed to execute SQL: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 导入 SQL 文件
     *
     * 小文件（<5MB）使用全文读取 + SqlSplitter 分割
     * 大文件使用流式逐行读取 → 降低内存占用
     *
     * @param string $filePath SQL 文件路径
     */
    public function importFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new BackupException("SQL file not found: {$filePath}");
        }

        $fileSize = filesize($filePath);
        if ($fileSize === false) {
            throw new BackupException("Failed to get file size: {$filePath}");
        }

        // 大文件（≥5MB）使用流式逐行读取
        if ($fileSize >= 5 * 1024 * 1024) {
            $this->importFileStream($filePath);
            return;
        }

        // 小文件：使用 SqlSplitter 一次处理
        $content = file_get_contents($filePath);
        if ($content === false) {
            throw new BackupException("Failed to read SQL file: {$filePath}");
        }

        $statements = SqlSplitter::split($content);
        foreach ($statements as $sql) {
            $sql = trim($sql);
            if ($sql === '') {
                continue;
            }
            $this->importSql($sql);
        }
    }

    /**
     * 流式导入大 SQL 文件
     *
     * 逐行读取 SQL 文件，按分号分割语句并逐条执行，
     * 避免将整个文件读入内存
     *
     * @param string $filePath SQL 文件路径
     */
    private function importFileStream(string $filePath): void
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            throw new BackupException("Failed to open SQL file: {$filePath}");
        }

        try {
            $buffer = '';
            while (($line = fgets($handle)) !== false) {
                $trimmed = trim($line);
                // 跳过空行和纯注释行
                if ($trimmed === '' || strpos($trimmed, '--') === 0) {
                    continue;
                }
                $buffer .= $line;
                // 检测语句结束（分号结尾）
                if (substr(trim($buffer), -1) === ';') {
                    $this->importSql(trim($buffer));
                    $buffer = '';
                }
            }
            // 处理剩余缓冲
            if (trim($buffer) !== '') {
                $this->importSql(trim($buffer));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 禁用外键检查
     */
    public function disableForeignKeys(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 0;');
        }
    }

    /**
     * 启用外键检查
     */
    public function enableForeignKeys(): void
    {
        if ($this->pdo !== null) {
            $this->pdo->exec('SET FOREIGN_KEY_CHECKS = 1;');
        }
    }

    /**
     * 备份单张表到临时文件
     *
     * @param string $table    表名
     * @param string $tempFile 临时文件路径
     */
    public function backupTable(string $table, string $tempFile): void
    {
        $content = '';

        // 导出建表语句
        $createSql = $this->exportCreateTable($table);
        $content .= $createSql;

        // 导出全部数据（分块循环）
        $offset = 0;
        $chunkSize = 10000;
        while (true) {
            $data = $this->exportData($table, $offset, $chunkSize);
            if (empty(trim($data))) {
                break;
            }
            $content .= $data;
            $offset += $chunkSize;
        }

        // 写入文件
        if (file_put_contents($tempFile, $content) === false) {
            throw new BackupException("Failed to write backup file: {$tempFile}");
        }
    }

    /**
     * 从备份文件还原单张表
     *
     * @param string $table      表名
     * @param string $backupFile 备份文件路径
     */
    public function restoreTable(string $table, string $backupFile): void
    {
        // 先删除表
        if ($this->pdo !== null) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            } catch (\PDOException $e) {
                throw new BackupException("Failed to drop table {$table}: " . $e->getMessage(), 0, $e);
            }
        }

        // 导入备份文件
        $this->importFile($backupFile);
    }

    /**
     * 获取驱动名称
     *
     * @return string
     */
    public function getDriverName(): string
    {
        return 'pdo';
    }
}
