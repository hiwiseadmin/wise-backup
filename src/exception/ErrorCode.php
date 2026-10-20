<?php
declare(strict_types=1);

namespace wise\backup\exception;

use wise\support\exception\ErrorCodeInterface;

/**
 * wise-backup 业务错误码（号段 140000~149999）
 *
 *  140000~140099  备份创建/执行
 *  140100~140199  还原
 *  140200~140299  远程传输（SFTP/S3/FTP）
 *  140300~140399  清理
 *
 * @package wise\backup\exception
 */
enum ErrorCode: int implements ErrorCodeInterface
{
    /** 备份失败 */
    case BACKUP_ERROR       = 140000;

    /** 还原失败 */
    case RESTORE_ERROR      = 140100;

    /** 远程传输失败 */
    case REMOTE_TRANSFER_ERROR = 140200;

    /** 清理失败 */
    case CLEANUP_ERROR      = 140300;

    public function defaultMessage(): string
    {
        return match ($this) {
            self::BACKUP_ERROR         => '备份失败',
            self::RESTORE_ERROR        => '还原失败',
            self::REMOTE_TRANSFER_ERROR => '远程传输失败',
            self::CLEANUP_ERROR        => '清理失败',
        };
    }
}
