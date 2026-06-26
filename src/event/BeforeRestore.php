<?php

namespace wise\backup\event;

/**
 * 还原开始前事件
 */
class BeforeRestore
{
    /**
     * @param string $backupFile 备份文件路径
     * @param bool   $dropTables 是否先删除现有表
     */
    public function __construct(
        public readonly string $backupFile,
        public readonly bool $dropTables
    ) {}
}
