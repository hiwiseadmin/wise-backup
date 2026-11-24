<?php
declare(strict_types=1);

namespace wise\backup\exception;

use wise\support\exception\WiseException;

/**
 * 备份异常基类
 *
 * 所有备份相关异常均继承此类。
 *
 * 错误码：ErrorCode::BACKUP_ERROR (140000)
 * HTTP：500
 */
class BackupException extends WiseException
{
    /**
     * @param string          $message  异常消息
     * @param int             $code     异常代码（传 0 时回退到 ErrorCode::BACKUP_ERROR）
     * @param ?\Throwable     $previous 前一个异常
     * @param int             $httpCode HTTP 状态码
     */
    public function __construct(string $message = '', int $code = 0, ?\Throwable $previous = null, int $httpCode = 500)
    {
        $code = $code ?: ErrorCode::BACKUP_ERROR->value;
        parent::__construct($code, $message, $httpCode, $previous);
    }
}
