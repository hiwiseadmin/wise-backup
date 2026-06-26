<?php

namespace wise\backup\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\backup\BackupManager;
use think\facade\Log;

/**
 * 数据库备份命令
 *
 * 用法：php think wise:backup:run [--tables=...] [--exclude=...] [--compress=6] [--destination=local]
 */
class Run extends Command
{
    /**
     * 配置命令
     */
    protected function configure(): void
    {
        $this->setName('wise:backup:run')
            ->setDescription('执行数据库备份')
            ->addOption('tables', null, Option::VALUE_OPTIONAL, '要备份的表名，逗号分隔（默认：全部）')
            ->addOption('exclude', null, Option::VALUE_OPTIONAL, '要排除的表匹配模式，逗号分隔')
            ->addOption('compress', null, Option::VALUE_OPTIONAL, '压缩级别（1-9，默认：6）', '6')
            ->addOption('destination', null, Option::VALUE_OPTIONAL, '目标存储磁盘（默认：local）', 'local')
            ->setHelp(<<<'HELP'
<info>php think wise:backup:run</info>

示例：
  <comment>php think wise:backup:run</comment>                        备份全部表
  <comment>php think wise:backup:run --tables=users,orders</comment>   备份指定表
  <comment>php think wise:backup:run --exclude=cache_*,logs_*</comment> 按模式排除表
  <comment>php think wise:backup:run --destination=s3</comment>        备份到 S3 远程磁盘
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
            $options = [];

            $tables = $input->getOption('tables');
            if (!empty($tables)) {
                $options['tables'] = explode(',', $tables);
            }

            $exclude = $input->getOption('exclude');
            if (!empty($exclude)) {
                $options['exclude'] = explode(',', $exclude);
            }

            $compress = $input->getOption('compress');
            if ($compress !== null) {
                $options['compress_level'] = (int) $compress;
            }

            $destination = $input->getOption('destination');
            if (!empty($destination)) {
                $options['destination'] = $destination;
            }

            // 输出开始信息
            $output->writeln('<info>正在开始数据库备份...</info>');
            $output->writeln('');

            // 执行备份
            $zipFile = $manager->backup($options);

            // 输出结果表格
            $output->writeln('<info>数据库备份完成！</info>');
            $output->writeln('');

            $fileSize = filesize($zipFile);
            $output->table(
                ['项目', '值'],
                [
                    ['备份文件', $zipFile],
                    ['文件大小', $fileSize !== false ? $this->formatBytes($fileSize) : '未知'],
                    ['时间戳', date('Y-m-d H:i:s')],
                ]
            );

            Log::channel('backup')->info("CLI backup completed: {$zipFile}");

            return 0;
        } catch (\Throwable $e) {
            $output->writeln('<error>备份失败：' . $e->getMessage() . '</error>');
            Log::channel('backup')->error("CLI backup failed: " . $e->getMessage());
            return 1;
        }
    }

    /**
     * 格式化字节大小
     *
     * @param int $bytes
     * @return string
     */
    private function formatBytes(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $i = intval(floor(log($bytes, 1024)));
        return round($bytes / pow(1024, $i), 2) . ' ' . $units[$i];
    }
}
