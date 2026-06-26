<?php

namespace wise\backup\exception;

/**
 * 备份异常基类
 *
 * 所有备份相关异常均继承此类
 */
class BackupException extends \RuntimeException
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
