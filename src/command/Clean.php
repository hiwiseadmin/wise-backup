<?php

namespace wise\backup\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use wise\backup\BackupManager;
use wise\support\context\ContextualLogger;

/**
 * 备份清理命令
 *
 * 用法：php think wise:backup:clean
 */
class Clean extends Command
{
    /**
     * 配置命令
     */
    protected function configure(): void
    {
        $this->setName('wise:backup:clean')
            ->setDescription('根据保留策略清理过期备份文件')
            ->setHelp(<<<'HELP'
<info>php think wise:backup:clean</info>

从所有配置的存储磁盘中清理过期备份文件，
保留策略定义在 config/wise-backup.php 中。
HELP
            );
    }

    /**
     * 执行命令
     *
     * @param Input  $input
     * @param Output $output
     * @return int
     */
    protected function execute(Input $input, Output $output): int
    {
        try {
            $config = $this->app->config->get('wise-backup', []);
            $manager = new BackupManager($config);

            $retention = $config['retention'] ?? ['daily' => 7, 'weekly' => 4, 'monthly' => 6];

            $output->writeln('<info>正在清理过期备份...</info>');
            $output->writeln('');
            $output->writeln('<comment>保留策略：</comment>');
            $output->writeln('  每日：' . ($retention['daily'] ?? 7) . ' 天');
            $output->writeln('  每周：' . ($retention['weekly'] ?? 4) . ' 周');
            $output->writeln('  每月：' . ($retention['monthly'] ?? 6) . ' 月');

            $deletedCount = $manager->clean();

            $output->writeln('');
            $output->writeln("<info>清理完成！已删除 {$deletedCount} 个过期备份文件。</info>");

            ContextualLogger::channel('backup')->info("CLI cleanup completed: {$deletedCount} files deleted");

            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>清理失败：' . $e->getMessage() . '</error>');
            ContextualLogger::channel('backup')->error("CLI cleanup failed: " . $e->getMessage());
            return 1;
        }
    }
}
