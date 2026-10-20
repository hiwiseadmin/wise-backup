<?php

namespace wise\backup\event;

/**
 * 全部备份完成后事件
 */
class AfterBackup
{
    /**
     * @param string $zipFile  生成的 ZIP 文件路径
     * @param array  $tables   已备份的表列表
     * @param float  $duration 耗时（秒）
     */
    public function __construct(
        public readonly string $zipFile,
        public readonly array $tables,
        public readonly float $duration
    ) {}
}
