<?php

namespace wise\backup\event;

/**
 * 清理完成后事件
 */
class AfterClean
{
    /**
     * @param int    $deletedCount 删除的文件数量
     * @param array  $deletedFiles 被删除的文件列表
     */
    public function __construct(
        public readonly int $deletedCount,
        public readonly array $deletedFiles
    ) {}
}
