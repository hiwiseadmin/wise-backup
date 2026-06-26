<?php

namespace wise\backup;

use think\facade\Log;
use think\facade\Event;
use wise\backup\event\BeforeClean;
use wise\backup\event\AfterClean;
use wise\backup\storage\StorageManager;

/**
 * 清理引擎
 *
 * 负责按保留策略清理过期的备份文件
 */
class CleanupEngine
{
    /** @var StorageManager 存储管理器 */
    private StorageManager $storage;

    /** @var array 保留策略 ['daily' => N, 'weekly' => N, 'monthly' => N] */
    private array $retention;

    /**
     * 构造函数
     *
     * @param StorageManager $storage   存储管理器
     * @param array          $retention 保留策略
     */
    public function __construct(StorageManager $storage, array $retention)
    {
        $this->storage = $storage;
        $this->retention = $retention;
    }

    /**
     * 执行清理
     *
     * @param array $diskNames 要清理的 disk 名称列表
     * @return array ['deleted' => int, 'errors' => ['diskName' => 'error message', ...]]
     */
    public function execute(array $diskNames = []): array
    {
        if (empty($diskNames)) {
            $diskNames = $this->storage->allDisks();
        }

        // 触发 BeforeClean 事件
        Event::trigger(new BeforeClean($diskNames, $this->retention));

        $allDeletedFiles = [];
        $totalDeleted = 0;
        $errors = [];

        foreach ($diskNames as $diskName) {
            try {
                // 列出所有备份文件
                $contents = $this->storage->listContents($diskName, '', false);
                $backupFiles = [];

                foreach ($contents as $item) {
                    $path = $item['path'] ?? '';
                    if (preg_match('/^backup_(\d{8})_(\d{6})\.zip$/', basename($path), $matches)) {
                        $backupFiles[] = [
                            'path'      => $path,
                            'timestamp' => $this->parseTimestamp($matches[1], $matches[2]),
                        ];
                    }
                }

                if (empty($backupFiles)) {
                    continue;
                }

                // 按时间戳降序排序（最新的在前）
                usort($backupFiles, function ($a, $b) {
                    return $b['timestamp'] <=> $a['timestamp'];
                });

                // 分类
                $classified = $this->classifyByDate($backupFiles);

                // 计算保留截止点
                $cutoffs = $this->getRetentionCutoffs();

                // 标记要删除的文件
                $toDelete = $this->markExpired($classified, $cutoffs);

                // 执行删除
                $diskDeleted = 0;
                foreach ($toDelete as $file) {
                    try {
                        $this->storage->delete($diskName, $file['path']);
                        $allDeletedFiles[] = $diskName . ':' . $file['path'];
                        $totalDeleted++;
                        $diskDeleted++;
                    } catch (\Throwable $e) {
                        Log::channel('backup')->warning("Failed to delete {$file['path']} from disk {$diskName}: " . $e->getMessage());
                    }
                }

                Log::channel('backup')->info("Cleanup on disk '{$diskName}': {$diskDeleted} files deleted");
            } catch (\Throwable $e) {
                $errors[$diskName] = $e->getMessage();
                Log::channel('backup')->error("Cleanup failed on disk '{$diskName}': " . $e->getMessage());
            }
        }

        // 触发 AfterClean 事件
        Event::trigger(new AfterClean($totalDeleted, $allDeletedFiles));

        Log::channel('backup')->info("Cleanup total: {$totalDeleted} files deleted across " . count($diskNames) . " disks");

        return [
            'deleted' => $totalDeleted,
            'errors'  => $errors,
        ];
    }

    /**
     * 按日期分类备份文件
     *
     * @param array $files 备份文件列表
     * @return array ['daily' => [...], 'weekly' => [...], 'monthly' => [...]]
     */
    public function classifyByDate(array $files): array
    {
        $now = time();
        $classified = [
            'daily'   => [],
            'weekly'  => [],
            'monthly' => [],
        ];

        // 从配置读取分界值（与 getRetentionCutoffs 联动）
        $dailyDays = (int) ($this->retention['daily'] ?? 7);
        $weeklyDays = $dailyDays + (int) ($this->retention['weekly'] ?? 4) * 7;

        foreach ($files as $file) {
            $ageInDays = ($now - $file['timestamp']) / 86400;

            if ($ageInDays <= $dailyDays) {
                $classified['daily'][] = $file;
            } elseif ($ageInDays <= $weeklyDays) {
                $classified['weekly'][] = $file;
            } else {
                $classified['monthly'][] = $file;
            }
        }

        return $classified;
    }

    /**
     * 计算保留截止点
     *
     * @return array ['daily' => timestamp, 'weekly' => timestamp, 'monthly' => timestamp]
     */
    public function getRetentionCutoffs(): array
    {
        $dailyRetention = $this->retention['daily'] ?? 7;
        $weeklyRetention = $this->retention['weekly'] ?? 4;
        $monthlyRetention = $this->retention['monthly'] ?? 6;

        $now = time();

        return [
            'daily'   => strtotime("-{$dailyRetention} days", $now),
            'weekly'  => strtotime("-{$weeklyRetention} weeks", $now),
            'monthly' => strtotime("-{$monthlyRetention} months", $now),
        ];
    }

    /**
     * 标记过期文件
     *
     * @param array $classified 分类后的文件
     * @param array $cutoffs    保留截止点
     * @return array 需要删除的文件列表
     */
    public function markExpired(array $classified, array $cutoffs): array
    {
        $toDelete = [];

        // Daily: 保留最近 N 天（每天所有文件都保留），删除超过 N 天的
        if (!empty($classified['daily'])) {
            foreach ($classified['daily'] as $file) {
                if ($file['timestamp'] < $cutoffs['daily']) {
                    $toDelete[] = $file;
                }
            }
        }

        // Weekly: 保留最近 N 周（每周只保留第一份即最新的）
        if (!empty($classified['weekly'])) {
            $weeklyGroups = [];
            foreach ($classified['weekly'] as $file) {
                $weekKey = date('o-W', $file['timestamp']);
                if (!isset($weeklyGroups[$weekKey])) {
                    $weeklyGroups[$weekKey] = [];
                }
                $weeklyGroups[$weekKey][] = $file;
            }

            foreach ($weeklyGroups as $weekKey => $weekFiles) {
                // 按时间戳降序（最新的在前）
                usort($weekFiles, function ($a, $b) {
                    return $b['timestamp'] <=> $a['timestamp'];
                });

                // 保留第一份（最新的），其余删除
                $weekRetention = $this->retention['weekly'] ?? 4;
                $weekCutoff = $cutoffs['weekly'];

                for ($i = 0; $i < count($weekFiles); $i++) {
                    if ($i === 0 && $weekFiles[$i]['timestamp'] >= $weekCutoff) {
                        continue; // 保留
                    }
                    $toDelete[] = $weekFiles[$i];
                }
            }
        }

        // Monthly: 保留最近 N 月（每月只保留第一份即最新的）
        if (!empty($classified['monthly'])) {
            $monthlyGroups = [];
            foreach ($classified['monthly'] as $file) {
                $monthKey = date('Y-m', $file['timestamp']);
                if (!isset($monthlyGroups[$monthKey])) {
                    $monthlyGroups[$monthKey] = [];
                }
                $monthlyGroups[$monthKey][] = $file;
            }

            foreach ($monthlyGroups as $monthKey => $monthFiles) {
                // 按时间戳降序
                usort($monthFiles, function ($a, $b) {
                    return $b['timestamp'] <=> $a['timestamp'];
                });

                $monthCutoff = $cutoffs['monthly'];

                for ($i = 0; $i < count($monthFiles); $i++) {
                    if ($i === 0 && $monthFiles[$i]['timestamp'] >= $monthCutoff) {
                        continue; // 保留
                    }
                    $toDelete[] = $monthFiles[$i];
                }
            }
        }

        return $toDelete;
    }

    /**
     * 解析备份文件名中的时间戳
     *
     * @param string $datePart YYYYMMDD
     * @param string $timePart HHMMSS
     * @return int Unix 时间戳
     */
    private function parseTimestamp(string $datePart, string $timePart): int
    {
        $year = (int) substr($datePart, 0, 4);
        $month = (int) substr($datePart, 4, 2);
        $day = (int) substr($datePart, 6, 2);
        $hour = (int) substr($timePart, 0, 2);
        $minute = (int) substr($timePart, 2, 2);
        $second = (int) substr($timePart, 4, 2);

        $timestamp = mktime($hour, $minute, $second, $month, $day, $year);
        return $timestamp !== false ? $timestamp : 0;
    }
}
