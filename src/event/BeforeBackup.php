<?php

namespace wise\backup\event;

/**
 * 备份开始前事件
 */
class BeforeBackup
{
    /**
     * @param array  $tables      要备份的表列表
     * @param string $destination 目标 storage disk 名称
     */
    public function __construct(
        public readonly array $tables,
        public readonly string $destination
    ) {}
}
