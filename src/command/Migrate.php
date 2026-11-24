<?php

namespace wise\backup\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\backup\BackupManager;
use wise\support\context\ContextualLogger;

/**
 * 备份迁移命令
 *
 * 用法：php think wise:backup:migrate --remote=sftp://host/path/file.zip [--tables=...]
 */
class Migrate extends Command
{
    /**
     * 配置命令
     */
    protected function configure(): void
    {
        $this->setName('wise:backup:migrate')
            ->setDescription('从远程备份源迁移数据库')
            ->addOption('remote', null, Option::VALUE_REQUIRED, '远程源路径（例如：sftp://host/path/file.zip）')
            ->addOption('tables', null, Option::VALUE_OPTIONAL, '要还原的表名，逗号分隔')
            ->setHelp(<<<'HELP'
<info>php think wise:backup:migrate --remote=<来源></info>

从远程源下载备份并还原到当前数据库。

示例：
  <comment>php think wise:backup:migrate --remote=sftp://server/backups/backup_latest.zip</comment>
  <comment>php think wise:backup:migrate --remote=s3://bucket/backups/backup.zip --tables=users,orders</comment>
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
            $remote = $input->getOption('remote');

            if (empty($remote)) {
                $output->writeln('<error>必须指定 --remote 参数</error>');
                $output->writeln('<comment>用法：php think wise:backup:migrate --remote=sftp://host/path/file.zip</comment>');
                return 1;
            }

            $config = $this->app->config->get('wise-backup', []);
            $manager = new BackupManager($config);

            $options = [];
            $tables = $input->getOption('tables');
            if (!empty($tables)) {
                $options['tables'] = explode(',', $tables);
            }

            $output->writeln('<info>正在从远程源迁移...</info>');
            $output->writeln("<comment>远程源：{$remote}</comment>");
            $output->writeln('');

            $result = $manager->migrate($remote, $options);

            $output->writeln("<info>迁移完成！</info>");
            $output->writeln("<comment>本地备份已保存到：{$result}</comment>");

            ContextualLogger::channel('backup')->info("CLI migration completed: source={$remote}, local={$result}");

            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>迁移失败：' . $e->getMessage() . '</error>');
            ContextualLogger::channel('backup')->error("CLI migration failed: " . $e->getMessage());
            return 1;
        }
    }
}
