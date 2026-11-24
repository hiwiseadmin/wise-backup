<?php

namespace wise\backup;

/**
 * 备份驱动接口
 *
 * 所有数据库驱动（mysqldump、PDO 等）必须实现此接口
 */
interface BackupDriverInterface
{
    /**
     * 连接数据库
     *
     * @param array $config 数据库连接配置
     */
    public function connect(array $config): void;

    /**
     * 获取表列表
     *
     * @param array $ignoreTables 忽略的表（glob 模式）
     * @return array 表名数组
     */
    public function getTables(array $ignoreTables = []): array;

    /**
     * 导出建表语句
     *
     * @param string $table 表名
     * @return string CREATE TABLE 语句
     */
    public function exportCreateTable(string $table): string;

    /**
     * 分块导出数据
     *
     * @param string $table     表名
     * @param int    $offset    偏移量
     * @param int    $chunkSize 分块大小
     * @return string INSERT 语句
     */
    public function exportData(string $table, int $offset, int $chunkSize): string;

    /**
     * 执行 SQL 语句
     *
     * @param string $sql SQL 语句
     */
    public function importSql(string $sql): void;

    /**
     * 导入 SQL 文件
     *
     * @param string $filePath SQL 文件路径
     */
    public function importFile(string $filePath): void;

    /**
     * 禁用外键检查
     */
    public function disableForeignKeys(): void;

    /**
     * 启用外键检查
     */
    public function enableForeignKeys(): void;

    /**
     * 备份单张表到临时文件
     *
     * @param string $table    表名
     * @param string $tempFile 临时文件路径
     */
    public function backupTable(string $table, string $tempFile): void;

    /**
     * 从备份文件还原单张表
     *
     * @param string $table      表名
     * @param string $backupFile 备份文件路径
     */
    public function restoreTable(string $table, string $backupFile): void;

    /**
     * 获取驱动名称
     *
     * @return string
     */
    public function getDriverName(): string;

    /**
     * 获取 MySQL 版本号
     *
     * @return string
     */
    public function getMysqlVersion(): string;
}
