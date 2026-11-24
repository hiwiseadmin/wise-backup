<?php

namespace wise\backup;

use wise\support\context\ContextualLogger;
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

        Event::trigger(new BeforeClean($diskNames, $this->retention));

        $allDeletedFiles = [];
        $totalDeleted = 0;
        $errors = [];

        foreach ($diskNames as $diskName) {
            try {
                $contents = $this->storage->listContents($diskName, '', false);
                $backupFiles = [];

                foreach ($contents as $item) {
                    $path = $item['path'] ?? '';
                    if (preg_match('/^backup_(\d{8})_(\d{6})(?:_\d+)?\.zip$/', basename($path), $matches)) {
                        $backupFiles[] = [
                            'path'      => $path,
                            'timestamp' => $this->parseTimestamp($matches[1], $matches[2]),
                        ];
                    }
                }

                if (empty($backupFiles)) {
                    continue;
                }

                usort($backupFiles, function ($a, $b) {
                    return $b['timestamp'] <=> $a['timestamp'];
                });

                $classified = $this->classifyByDate($backupFiles);

                $cutoffs = $this->getRetentionCutoffs();

                $toDelete = $this->markExpired($classified, $cutoffs);

                $diskDeleted = 0;
                foreach ($toDelete as $file) {
                    try {
                        $this->storage->delete($diskName, $file['path']);
                        $allDeletedFiles[] = $diskName . ':' . $file['path'];
                        $totalDeleted++;
                        $diskDeleted++;
                    } catch (\Throwable $e) {
                        ContextualLogger::channel('backup')->warning("Failed to delete {$file['path']} from disk {$diskName}: " . $e->getMessage());
                    }
                }

                ContextualLogger::channel('backup')->info("Cleanup on disk '{$diskName}': {$diskDeleted} files deleted");
            } catch (\Throwable $e) {
                $errors[$diskName] = $e->getMessage();
                ContextualLogger::channel('backup')->error("Cleanup failed on disk '{$diskName}': " . $e->getMessage());
            }
        }

        Event::trigger(new AfterClean($totalDeleted, $allDeletedFiles));

        ContextualLogger::channel('backup')->info("Cleanup total: {$totalDeleted} files deleted across " . count($diskNames) . " disks");

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

        if (!empty($classified['daily'])) {
            foreach ($classified['daily'] as $file) {
                if ($file['timestamp'] < $cutoffs['daily']) {
                    $toDelete[] = $file;
                }
            }
        }

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
                usort($weekFiles, function ($a, $b) {
                    return $b['timestamp'] <=> $a['timestamp'];
                });

                $weekRetention = $this->retention['weekly'] ?? 4;
                $weekCutoff = $cutoffs['weekly'];

                for ($i = 0; $i < count($weekFiles); $i++) {
                    if ($i === 0 && $weekFiles[$i]['timestamp'] >= $weekCutoff) {
                        continue;
                    }
                    $toDelete[] = $weekFiles[$i];
                }
            }
        }

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
                usort($monthFiles, function ($a, $b) {
                    return $b['timestamp'] <=> $a['timestamp'];
                });

                $monthCutoff = $cutoffs['monthly'];

                for ($i = 0; $i < count($monthFiles); $i++) {
                    if ($i === 0 && $monthFiles[$i]['timestamp'] >= $monthCutoff) {
                        continue;
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
