<?php

namespace wise\backup\event;

/**
 * 清理开始前事件
 */
class BeforeClean
{
    /**
     * @param array $disks     需要清理的 disk 列表
     * @param array $retention 保留策略
     */
    public function __construct(
        public readonly array $disks,
        public readonly array $retention
    ) {}
}
