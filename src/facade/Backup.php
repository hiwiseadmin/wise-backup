<?php

namespace wise\backup\facade;

use think\Facade;

/**
 * BackupManager 门面
 *
 * @method static string backup(array $options = [])
 * @method static bool   restore(string $file, array $options = [])
 * @method static int    clean()
 * @method static string migrate(string $source, array $options = [])
 * @method static void   setDriver(\wise\backup\BackupDriverInterface $driver)
 * @method static \wise\backup\BackupDriverInterface getDriver()
 *
 * @see \wise\backup\BackupManager
 */
class Backup extends Facade
{
    /**
     * 获取门面对应的实际类名
     */
    protected static function getFacadeClass(): string
    {
        return \wise\backup\BackupManager::class;
    }
}
