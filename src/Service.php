<?php

namespace wise\backup;

use wise\backup\command\Run;
use wise\backup\command\Restore;
use wise\backup\command\Clean;
use wise\backup\command\Migrate;
use wise\backup\command\Publish;
use wise\backup\trait\ServiceTrait;

/**
 * ThinkPHP 8 服务提供者
 * 注册扩展包配置、命令和核心服务
 */
class Service extends \think\Service
{
    use ServiceTrait;

    /**
     * 注册服务
     */
    public function register(): void
    {
        // 合并配置文件
        $this->mergeConfig('wise-backup');

        // 仅在控制台环境下注册命令
        if ($this->app->runningInConsole()) {
            $this->commands([
                Run::class,
                Restore::class,
                Clean::class,
                Migrate::class,
                Publish::class,
            ]);
        }

        // 单例绑定 BackupManager
        $this->app->bind(BackupManager::class, function () {
            $config = $this->app->config->get('wise-backup', []);
            return new BackupManager($config);
        });
    }

    /**
     * 启动服务
     */
    public function boot(): void
    {
        // 注册事件监听（从配置读取）
        // 预留：若配置中有 listeners 项，可在此处动态注册
    }
}
