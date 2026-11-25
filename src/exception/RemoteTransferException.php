<?php
declare(strict_types=1);

namespace wise\backup\exception;

/**
 * 远程传输异常
 *
 * 在远程存储传输（SFTP/S3/FTP）过程中出现错误时抛出。
 *
 * 错误码：ErrorCode::REMOTE_TRANSFER_ERROR (140200)
 * HTTP：502
 */
class RemoteTransferException extends BackupException
{
    /**
     * @param string          $message  异常消息
     * @param int             $code     异常代码（传 0 时回退到 ErrorCode::REMOTE_TRANSFER_ERROR）
     * @param ?\Throwable     $previous 前一个异常
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null)
    {
        $code = $code ?: ErrorCode::REMOTE_TRANSFER_ERROR->value;
        parent::__construct($message, $code, $previous, 502);
    }
}
