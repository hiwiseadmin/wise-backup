<?php
declare(strict_types=1);
namespace wise\backup\command;
use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;
use wise\backup\trait\CommandTrait;

class Publish extends Command
{
    use CommandTrait;

    protected function configure(): void
    {
        $this->setName('wise:backup:publish')
            ->setDescription('发布数据库备份配置文件及迁移文件')
            ->addOption('force', 'f', Option::VALUE_NONE, '强制覆盖已存在的配置文件');
    }

    protected function getPackageConfigName(): string
    {
        return 'wise-backup';
    }

    protected function execute(Input $input, Output $output): int
    {
        $force = $input->getOption('force');
        $this->printBanner($output, 'wise-backup');

        // 发布配置和迁移文件
        $this->publishConfig($output, $force);
        $migrationsPublished = $this->publishMigrations($output);

        $this->printSuccessFooter($output, '数据库备份', $migrationsPublished);
        return 0;
    }
}
