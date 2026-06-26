<?php

namespace wise\backup\exception;

/**
 * 清理异常
 *
 * 在备份文件清理过程中出现错误时抛出
 */
class CleanupException extends BackupException
{
    /**
     * @param string          $message  异常消息
     * @param int             $code     异常代码
     * @param ?\Throwable     $previous 前一个异常
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}
