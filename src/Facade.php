<?php

namespace wise\backup;

use think\Facade as BaseFacade;

/**
 * BackupManager 门面
 *
 * @method static string backup(array $options = [])
 * @method static bool   restore(string $file, array $options = [])
 * @method static int    clean()
 * @method static string migrate(string $source, array $options = [])
 * @method static void   setDriver(BackupDriverInterface $driver)
 * @method static BackupDriverInterface getDriver()
 *
 * @see \wise\backup\BackupManager
 */
class Facade extends BaseFacade
{
    /**
     * 获取门面对应的实际类名
     */
    protected static function getFacadeClass(): string
    {
        return BackupManager::class;
    }
}
