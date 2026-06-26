<?php

namespace wise\backup\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\input\Option;
use think\console\Output;
use wise\backup\BackupManager;
use think\facade\Log;

/**
 * 备份还原命令
 *
 * 用法：php think wise:backup:restore <backup_file> [--drop-tables] [--force]
 */
class Restore extends Command
{
    /**
     * 配置命令
     */
    protected function configure(): void
    {
        $this->setName('wise:backup:restore')
            ->setDescription('从 ZIP 备份文件还原数据库')
            ->addArgument('backup_file', Argument::REQUIRED, '备份 ZIP 文件路径（本地或 sftp:// s3:// ftp://）')
            ->addOption('drop-tables', null, Option::VALUE_NONE, '还原前删除现有表')
            ->addOption('force', null, Option::VALUE_NONE, '强制还原（覆盖前备份现有表）')
            ->setHelp(<<<'HELP'
<info>php think wise:backup:restore <备份文件> [--drop-tables] [--force]</info>

示例：
  <comment>php think wise:backup:restore /path/to/backup_20250101_120000.zip</comment>
  <comment>php think wise:backup:restore sftp://host/backups/backup_20250101_120000.zip --drop-tables</comment>
  <comment>php think wise:backup:restore /path/to/backup.zip --force</comment>
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
            // 获取 BackupManager 实例
            $config = $this->app->config->get('wise-backup', []);
            $manager = new BackupManager($config);

            // 解析参数
            $backupFile = $input->getArgument('backup_file');
            $options = [
                'drop_tables' => $input->getOption('drop-tables') ? true : false,
                'force'       => $input->getOption('force') ? true : false,
            ];

            $output->writeln('<info>正在开始数据库还原...</info>');
            $output->writeln("<comment>备份文件：{$backupFile}</comment>");
            $output->writeln('');

            // 执行还原
            $result = $manager->restore($backupFile, $options);

            if ($result) {
                $output->writeln('<info>数据库还原完成！</info>');
                Log::channel('backup')->info("CLI restore completed: {$backupFile}");
                return 0;
            }

            $output->writeln('<error>还原失败</error>');
            return 1;
        } catch (\Throwable $e) {
            $output->writeln('<error>还原失败：' . $e->getMessage() . '</error>');
            Log::channel('backup')->error("CLI restore failed: " . $e->getMessage());
            return 1;
        }
    }
}
