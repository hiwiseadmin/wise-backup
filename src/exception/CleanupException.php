<?php
declare(strict_types=1);

namespace wise\backup\exception;

/**
 * 清理异常
 *
 * 在备份文件清理过程中出现错误时抛出。
 *
 * 错误码：ErrorCode::CLEANUP_ERROR (140300)
 * HTTP：500
 */
class CleanupException extends BackupException
{
    /**
     * @param string          $message  异常消息
     * @param int             $code     异常代码（传 0 时回退到 ErrorCode::CLEANUP_ERROR）
     * @param ?\Throwable     $previous 前一个异常
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $code = $code ?: ErrorCode::CLEANUP_ERROR->value;
        parent::__construct($message, $code, $previous);
    }
}
