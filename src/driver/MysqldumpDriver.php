<?php

namespace wise\backup\driver;

use wise\backup\BackupDriverInterface;
use wise\backup\exception\BackupException;
use wise\support\context\ContextualLogger;

/**
 * mysqldump 二进制驱动
 *
 * 通过调用系统的 mysqldump 命令执行备份/还原操作
 */
class MysqldumpDriver implements BackupDriverInterface
{
    /** @var string mysqldump 二进制文件路径 */
    private string $binaryPath = 'mysqldump';

    /** @var array 数据库连接配置 */
    private array $dbConfig = [];

    /** @var string MySQL 服务端版本号 */
    private string $mysqlVersion = '';

    /** @var string mysqldump 客户端版本号 */
    private string $clientVersion = '';

    /** @var ?\PDO PDO 连接（用于执行非导出 SQL） */
    private ?\PDO $pdo = null;

    /**
     * 构造函数
     *
     * @param array $dbConfig 数据库配置 [host, port, database, username, password, charset]
     */
    public function __construct(array $dbConfig = [])
    {
        $this->dbConfig = $dbConfig;
    }

    /**
     * 检测 mysqldump 二进制文件路径
     *
     * @return string 找到的路径或空字符串
     */
    public function detectMysqldump(): string
    {
        $isWindows = strtoupper(substr(PHP_OS, 0, 3)) === 'WIN';

        if ($isWindows) {
            $output = [];
            $returnVar = 0;
            exec('where mysqldump 2>NUL', $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                $this->binaryPath = trim($output[0]);
                return $this->binaryPath;
            }
        } else {
            $output = [];
            $returnVar = 0;
            exec('which mysqldump 2>/dev/null', $output, $returnVar);
            if ($returnVar === 0 && !empty($output)) {
                $this->binaryPath = trim($output[0]);
                return $this->binaryPath;
            }
        }

        $commonPaths = [
            '/usr/bin/mysqldump',
            '/usr/local/bin/mysqldump',
            '/usr/local/mysql/bin/mysqldump',
            '/opt/homebrew/bin/mysqldump',
            'C:\mysql\bin\mysqldump.exe',
            'C:\Program Files\MySQL\MySQL Server 8.0\bin\mysqldump.exe',
        ];

        foreach ($commonPaths as $path) {
            if (file_exists($path) && is_executable($path)) {
                $this->binaryPath = $path;
                return $this->binaryPath;
            }
        }

        return '';
    }

    /**
     * 连接数据库
     *
     * @param array $config 数据库连接配置
     */
    public function connect(array $config): void
    {
        $this->dbConfig = array_merge($this->dbConfig, $config);

        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=%s',
                $this->dbConfig['host'] ?? '127.0.0.1',
                $this->dbConfig['port'] ?? 3306,
                $this->dbConfig['database'] ?? '',
                $this->dbConfig['charset'] ?? 'utf8mb4'
            );
            $this->pdo = new \PDO(
                $dsn,
                $this->dbConfig['username'] ?? 'root',
                $this->dbConfig['password'] ?? '',
                [
                    \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                    \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                ]
            );
        } catch (\PDOException $e) {
            throw new BackupException("PDO connection failed: " . $e->getMessage(), 0, $e);
        }

        $found = $this->detectMysqldump();
        if (empty($found)) {
            ContextualLogger::channel('backup')->warning('mysqldump binary not found, will fallback to PdoDriver');
        } else {
            ContextualLogger::channel('backup')->info("mysqldump binary found: {$found}");
        }

        $this->getMysqlVersion();
    }

    /**
     * 获取 MySQL 版本号
     *
     * @return string
     */
    public function getMysqlVersion(): string
    {
        if (!empty($this->mysqlVersion)) {
            return $this->mysqlVersion;
        }

        if ($this->pdo !== null) {
            try {
                $version = $this->pdo->query('SELECT VERSION()')->fetchColumn();
                $this->mysqlVersion = (string) $version;
                return $this->mysqlVersion;
            } catch (\PDOException $e) {
            }
        }

        if (!empty($this->binaryPath)) {
            $command = sprintf('%s --version 2>&1', escapeshellarg($this->binaryPath));
            $output = shell_exec($command);
            if (is_string($output) && preg_match('/(\d+\.\d+\.\d+)/', $output, $matches)) {
                $this->mysqlVersion = $matches[1];
                return $this->mysqlVersion;
            }
        }

        $this->mysqlVersion = '0.0.0';
        return $this->mysqlVersion;
    }

    /**
     * 获取 mysqldump 客户端版本号
     *
     * 注意与服务端版本分开：`--column-statistics`（8.0+）、`--no-tablespaces`（5.7.31+/8.0.21+）
     * 都是**客户端**选项。按服务端版本拼接会在「5.7 客户端 + 8.0 服务端」的组合上报
     * unknown option，备份直接失败。
     *
     * @return string
     */
    public function getClientVersion(): string
    {
        if ($this->clientVersion !== '') {
            return $this->clientVersion;
        }

        if (!empty($this->binaryPath)) {
            $command = sprintf('%s --version 2>&1', escapeshellarg($this->binaryPath));
            $output = shell_exec($command);
            if (is_string($output) && preg_match('/(\d+\.\d+\.\d+)/', $output, $matches)) {
                return $this->clientVersion = $matches[1];
            }
        }

        return $this->clientVersion = '0.0.0';
    }

    /**
     * 将密码写入临时 MySQL 配置文件，通过 --defaults-extra-file 传递
     *
     * 比 MYSQL_PWD 环境变量更安全：密码仅存在于临时文件，不会泄露到
     * /proc/PID/environ 或 PHP getenv() 中。使用后立即删除临时文件。
     *
     * @return string|null 临时配置文件路径，失败（如无密码）返回 null
     */
    private function writeTempDefaultsFile(): ?string
    {
        $password = $this->dbConfig['password'] ?? '';
        if ($password === '') {
            return null;
        }

        $tmpFile = tempnam(sys_get_temp_dir(), 'wise_backup_mysql_');
        if ($tmpFile === false) {
            return null;
        }

        $escapedPassword = str_replace(['\\', "\n", "\r"], ['\\\\', '\\n', '\\r'], $password);
        $content = "[client]\npassword=" . $escapedPassword . "\n";
        if (file_put_contents($tmpFile, $content, LOCK_EX) === false) {
            @unlink($tmpFile);
            return null;
        }

        @chmod($tmpFile, 0600);

        return $tmpFile;
    }

    /**
     * 执行 mysqldump/mysql 命令，安全传递密码
     *
     * 只用 `--defaults-extra-file`（0600 临时文件）：不用 MYSQL_PWD/putenv，
     * 因为 putenv() 是进程级全局状态 —— Swoole/常驻 worker 里协程并发时，
     * 另一个协程拉起的子进程会继承这里的密码，可能连到错误的库。
     *
     * @param string $command 要执行的命令
     * @return array{0: array, 1: int} [output, returnVar]
     * @throws BackupException 无法创建凭据文件时直接失败，绝不降级到明文/环境变量
     */
    private function execWithPassword(string $command): array
    {
        $tmpFile = $this->writeTempDefaultsFile();

        if ($tmpFile === null && ($this->dbConfig['password'] ?? '') !== '') {
            throw new BackupException('无法创建 MySQL 临时凭据文件，已中止以避免密码泄露');
        }

        $fullCommand = $tmpFile === null ? $command : $this->injectDefaultsFile($command, $tmpFile);

        $output = [];
        $returnVar = 0;

        try {
            exec($fullCommand . ' 2>&1', $output, $returnVar);
        } finally {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
        }

        return [$output, $returnVar];
    }

    /**
     * 把 --defaults-extra-file 插到命令的可执行文件之后
     *
     * @param string $command 原命令（形如 "<binary>" --opt ...）
     * @param string $tmpFile 临时凭据文件
     * @return string
     */
    private function injectDefaultsFile(string $command, string $tmpFile): string
    {
        $option = ' --defaults-extra-file=' . escapeshellarg($tmpFile);

        $injected = preg_replace_callback(
            '/^("[^"]*"|\S+)/',
            static fn (array $matches): string => $matches[1] . $option,
            $command,
            1
        );

        return is_string($injected) && $injected !== '' ? $injected : $command . $option;
    }

    /**
     * 构建 mysqldump 命令行（不含密码，密码通过环境变量或临时文件传递）
     *
     * @param string $action 操作类型
     * @param array  $params 额外参数
     * @return string
     */
    public function buildCommand(string $action, array $params = []): string
    {
        $host = $this->dbConfig['host'] ?? '127.0.0.1';
        $port = $this->dbConfig['port'] ?? 3306;
        $username = $this->dbConfig['username'] ?? 'root';
        $database = $this->dbConfig['database'] ?? '';
        $charset = $this->dbConfig['charset'] ?? 'utf8mb4';

        $command = sprintf(
            '"%s" --host=%s --port=%d -u%s',
            escapeshellarg($this->binaryPath),
            escapeshellarg($host),
            $port,
            escapeshellarg($username)
        );


        $clientVersion = $this->getClientVersion();
        if (version_compare($clientVersion, '8.0.0', '>=')) {
            $command .= ' --column-statistics=0';
        }
        if (version_compare($clientVersion, '5.7.31', '>=')) {
            $command .= ' --no-tablespaces';
        }

        $command .= ' --default-character-set=' . escapeshellarg($charset);

        if (!empty($database)) {
            $command .= ' ' . escapeshellarg($database);
        }

        return $command;
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
        $command = $this->buildCommand('exportCreateTable') . ' --no-data --skip-triggers --skip-add-drop-table ' . escapeshellarg($table);

        [$output, $returnVar] = $this->execWithPassword($command);

        if ($returnVar !== 0) {
            $errorMsg = implode("\n", $output);
            throw new BackupException("mysqldump failed to export CREATE TABLE for {$table}: {$errorMsg}");
        }

        return implode("\n", $output);
    }

    /**
     * 分块导出数据
     *
     * @param string $table     表名
     * @param int    $offset    偏移量
     * @param int    $chunkSize 分块大小
     * @return string
     */
    public function exportData(string $table, int $offset, int $chunkSize): string
    {
        $command = $this->buildCommand('exportData') . ' --no-create-info --skip-triggers --skip-add-drop-table';
        $command .= ' --order-by-primary';
        $command .= ' --skip-add-locks --skip-comments';
        $command .= sprintf(' --where="1=1 LIMIT %d,%d"', max(0, (int) $offset), max(1, (int) $chunkSize));
        $command .= ' ' . escapeshellarg($table);

        [$output, $returnVar] = $this->execWithPassword($command);

        if ($returnVar !== 0) {
            $errorMsg = implode("\n", $output);
            throw new BackupException("mysqldump failed to export data for {$table}: {$errorMsg}");
        }

        return implode("\n", $output);
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
     * @param string $filePath SQL 文件路径
     */
    public function importFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            throw new BackupException("SQL file not found: {$filePath}");
        }

        $host = $this->dbConfig['host'] ?? '127.0.0.1';
        $port = $this->dbConfig['port'] ?? 3306;
        $username = $this->dbConfig['username'] ?? 'root';
        $database = $this->dbConfig['database'] ?? '';

        $command = sprintf(
            '"mysql" --host=%s --port=%d -u%s',
            escapeshellarg($host),
            $port,
            escapeshellarg($username)
        );

        $command .= ' ' . escapeshellarg($database) . ' < ' . escapeshellarg($filePath);

        [$output, $returnVar] = $this->execWithPassword($command);

        if ($returnVar !== 0) {
            $errorMsg = implode("\n", $output);
            throw new BackupException("Failed to import SQL file {$filePath}: {$errorMsg}");
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
     * 备份单张表到临时文件（使用 proc_open 分离 stdout/stderr）
     *
     * @param string $table    表名
     * @param string $tempFile 临时文件路径
     */
    public function backupTable(string $table, string $tempFile): void
    {
        $command = $this->buildCommand('backupTable') . ' ' . escapeshellarg($table);

        $tmpFile = $this->writeTempDefaultsFile();
        if ($tmpFile === null && ($this->dbConfig['password'] ?? '') !== '') {
            throw new BackupException('无法创建 MySQL 临时凭据文件，已中止以避免密码泄露');
        }

        if ($tmpFile !== null) {
            $command = $this->injectDefaultsFile($command, $tmpFile);
        }

        try {
            $descriptorspec = [
                0 => ['pipe', 'r'],
                1 => ['file', $tempFile, 'w'],
                2 => ['pipe', 'w'],
            ];
            $process = proc_open($command, $descriptorspec, $pipes);

            if (!is_resource($process)) {
                throw new BackupException("Failed to start mysqldump process for table {$table}");
            }

            fclose($pipes[0]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[2]);
            $returnVar = proc_close($process);
        } finally {
            if ($tmpFile !== null) {
                @unlink($tmpFile);
            }
        }

        if ($returnVar !== 0) {
            throw new BackupException("Failed to backup table {$table}: " . trim($stderr));
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
        return 'mysqldump';
    }
}
