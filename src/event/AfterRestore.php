<?php

namespace wise\backup\event;

/**
 * 还原完成后事件
 */
class AfterRestore
{
    /**
     * @param string $backupFile     备份文件路径
     * @param array  $tablesRestored 已还原的表列表
     * @param float  $duration       耗时（秒）
     */
    public function __construct(
        public readonly string $backupFile,
        public readonly array $tablesRestored,
        public readonly float $duration
    ) {}
}
