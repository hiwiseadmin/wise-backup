<?php

namespace wise\backup\event;

/**
 * 单张表备份完成后事件
 */
class AfterTableBackup
{
    /**
     * @param string $table       表名
     * @param string $tempFile    临时 SQL 文件路径
     * @param int    $rowsExported 导出行数
     */
    public function __construct(
        public readonly string $table,
        public readonly string $tempFile,
        public readonly int $rowsExported
    ) {}
}
