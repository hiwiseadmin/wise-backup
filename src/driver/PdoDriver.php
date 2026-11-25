<?php

namespace wise\backup\driver;

use wise\backup\BackupDriverInterface;
use wise\backup\exception\BackupException;
use wise\backup\helper\SqlSplitter;
use wise\support\context\ContextualLogger;

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

    /** @var array<string, array> 主键列缓存（空数组表示该表没有主键） */
    private array $primaryKeyCache = [];

    /** @var array 每个表上一块的最后主键值（keyset 分页用） */
    private array $lastKeyCache = [];

    /** @var array 已告警过的无主键表（避免每块都刷日志） */
    private array $noPrimaryKeyWarned = [];

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

        $columns = $this->columnsOf($table);

        if (empty($columns)) {
            return '';
        }

        $colList = '`' . implode('`, `', $columns) . '`';

        $primaryKeyColumns = $this->primaryKeyColumns($table);
        $cursorColumn = count($primaryKeyColumns) === 1 ? $primaryKeyColumns[0] : null;
        $orderByColumns = $primaryKeyColumns !== [] ? $primaryKeyColumns : [$columns[0]];

        if ($primaryKeyColumns === [] && $offset === 0 && !isset($this->noPrimaryKeyWarned[$table])) {
            $this->noPrimaryKeyWarned[$table] = true;
            ContextualLogger::channel('backup')->warning(
                "Table '{$table}' has no primary key, chunked export may be inconsistent; consider adding one"
            );
        }

        $stmt = $this->pdo->query($this->buildSelectSql($table, $orderByColumns, $offset, $chunkSize, $cursorColumn));

        $result = '';
        $buffer = [];
        $lastKey = null;

        while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
            $values = [];
            foreach ($row as $value) {
                if ($value === null) {
                    $values[] = 'NULL';
                } else {
                    $values[] = $this->pdo->quote((string) $value);
                }
            }

            $buffer[] = '(' . implode(', ', $values) . ')';
            if ($cursorColumn !== null) {
                $lastKey = $row[$cursorColumn] ?? $lastKey;
            }

            if (count($buffer) >= 500) {
                $result .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $buffer) . ";\n";
                $buffer = [];
            }
        }

        if (!empty($buffer)) {
            $result .= "INSERT INTO `{$table}` ({$colList}) VALUES\n" . implode(",\n", $buffer) . ";\n";
        }

        if ($cursorColumn !== null && $lastKey !== null) {
            $this->lastKeyCache[$table] = $lastKey;
        }

        return $result;
    }

    /**
     * 构造分块查询 SQL
     *
     * @param string      $table          表名
     * @param array       $orderByColumns 排序列表（主键列，或无主键时的首列）
     * @param int         $offset         偏移量（仅首块与无游标时使用）
     * @param int         $chunkSize      分块大小
     * @param string|null $cursorColumn   keyset 游标列（仅单列主键时非 null）
     * @return string
     */
    private function buildSelectSql(string $table, array $orderByColumns, int $offset, int $chunkSize, ?string $cursorColumn): string
    {
        $chunkSize = max(1, (int) $chunkSize);
        $offset = max(0, (int) $offset);

        $orderBy = implode(', ', array_map(static fn (string $column): string => "`{$column}`", $orderByColumns));

        $lastKey = $cursorColumn !== null && $offset > 0
            ? ($this->lastKeyCache[$table] ?? null)
            : null;

        if ($lastKey !== null) {
            return sprintf(
                "SELECT * FROM `%s` WHERE `%s` > %s ORDER BY %s LIMIT %d",
                $table,
                $cursorColumn,
                $this->pdo->quote((string) $lastKey),
                $orderBy,
                $chunkSize
            );
        }

        return $offset > 0
            ? sprintf("SELECT * FROM `%s` ORDER BY %s LIMIT %d, %d", $table, $orderBy, $offset, $chunkSize)
            : sprintf("SELECT * FROM `%s` ORDER BY %s LIMIT %d", $table, $orderBy, $chunkSize);
    }

    /**
     * 取表名对应的列列表（带缓存）
     *
     * @param string $table 表名
     * @return array
     */
    private function columnsOf(string $table): array
    {
        if (!isset($this->columnsCache[$table])) {
            $columnsStmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}`");
            $this->columnsCache[$table] = $columnsStmt !== false
                ? $columnsStmt->fetchAll(\PDO::FETCH_COLUMN, 0)
                : [];
        }

        return $this->columnsCache[$table];
    }

    /**
     * 取表的主键列（按主键内顺序；无主键返回空数组），带缓存
     *
     * @param string $table 表名
     * @return array
     */
    private function primaryKeyColumns(string $table): array
    {
        if (array_key_exists($table, $this->primaryKeyCache)) {
            return $this->primaryKeyCache[$table];
        }

        $stmt = $this->pdo->query("SHOW KEYS FROM `{$table}` WHERE Key_name = 'PRIMARY'");
        $rows = $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];

        $columns = [];
        foreach ($rows as $row) {
            if (!empty($row['Column_name'])) {
                $columns[] = (string) $row['Column_name'];
            }
        }

        return $this->primaryKeyCache[$table] = $columns;
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

        if ($fileSize >= 5 * 1024 * 1024) {
            $this->importFileStream($filePath);
            return;
        }

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
                if ($buffer === '' && ($trimmed === '' || strpos($trimmed, '--') === 0)) {
                    continue;
                }
                $buffer .= $line;

                if (substr(rtrim($line), -1) === ';' && $this->quotesBalanced($buffer)) {
                    $this->importSql(trim($buffer));
                    $buffer = '';
                }
            }
            if (trim($buffer) !== '') {
                $this->importSql(trim($buffer));
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * 判断 SQL 片段中的引号是否已全部闭合
     *
     * 粗略但足够用于 INSERT 切分：只关心单/双引号状态，反斜杠转义的字符跳过。
     *
     * @param string $sql SQL 片段
     * @return bool
     */
    private function quotesBalanced(string $sql): bool
    {
        $inSingle = false;
        $inDouble = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];

            if ($char === '\\') {
                $i++;
                continue;
            }

            if ($char === "'" && !$inDouble) {
                $inSingle = !$inSingle;
            } elseif ($char === '"' && !$inSingle) {
                $inDouble = !$inDouble;
            }
        }

        return !$inSingle && !$inDouble;
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
        $handle = fopen($tempFile, 'wb');
        if ($handle === false) {
            throw new BackupException("Failed to open backup file: {$tempFile}");
        }

        $offset = 0;
        $chunkSize = 10000;

        try {
            if (fwrite($handle, $this->exportCreateTable($table)) === false) {
                throw new BackupException("Failed to write backup file: {$tempFile}");
            }

            while (true) {
                $data = $this->exportData($table, $offset, $chunkSize);

                if (stripos($data, 'INSERT INTO') === false) {
                    break;
                }

                if (fwrite($handle, $data) === false) {
                    throw new BackupException("Failed to write backup file: {$tempFile}");
                }

                $offset += $chunkSize;
            }
        } finally {
            fclose($handle);
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
        if ($this->pdo !== null) {
            try {
                $this->pdo->exec("DROP TABLE IF EXISTS `{$table}`");
            } catch (\PDOException $e) {
                throw new BackupException("Failed to drop table {$table}: " . $e->getMessage(), 0, $e);
            }
        }

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
