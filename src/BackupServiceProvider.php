<?php

namespace wise\backup;

use wise\backup\command\Run;
use wise\backup\command\Restore;
use wise\backup\command\Clean;
use wise\backup\command\Migrate;
use wise\support\traits\ServiceTrait;

use wise\support\traits\RegistersMigrations;

/**
 * ThinkPHP 服务提供者
 *
 * 使用 DeferredService：register() 仅绑定闭包工厂，
 * 实际初始化（config merge / BackupManager 绑定）在首次 make() 时触发。
 *
 * boot() 不受影响，命令注册在每个请求的 boot 阶段正常执行。
 */
class BackupServiceProvider extends \think\Service
{
    use ServiceTrait;

    use \wise\support\traits\RegistersMigrations;

    /**
     * 提供的服务映射
     */
    /**
     * 实际注册逻辑（首次 make 时执行）
     */
    public function register(): void
    {
        $this->mergeConfig('wise-backup');

        $this->app->bind(BackupManager::class, function () {
            $config = $this->app->config->get('wise-backup', []);
            return new BackupManager($config);
        });

        // 注册 CLI 命令（仅在真正执行 php think ... 时触发）
        $this->commands([
            Run::class,
            Restore::class,
            Clean::class,
            Migrate::class,
        ]);
    }

    /**
     * 启动服务（每个请求都执行，适合命令注册）
     */
    public function boot(): void
    {
        $this->registerMigrations();
    }
}
