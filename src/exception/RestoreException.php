<?php
declare(strict_types=1);

namespace wise\backup\exception;

/**
 * 还原异常
 *
 * 在备份还原过程中出现错误时抛出。
 *
 * 错误码：ErrorCode::RESTORE_ERROR (140100)
 * HTTP：500
 */
class RestoreException extends BackupException
{
    /**
     * @param string          $message  异常消息
     * @param int             $code     异常代码（传 0 时回退到 ErrorCode::RESTORE_ERROR）
     * @param ?\Throwable     $previous 前一个异常
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $code = $code ?: ErrorCode::RESTORE_ERROR->value;
        parent::__construct($message, $code, $previous);
    }
}
