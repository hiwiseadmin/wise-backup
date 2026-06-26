<?php

namespace wise\backup\tests;

use PHPUnit\Framework\TestCase;
use wise\backup\CleanupEngine;
use wise\backup\storage\StorageManager;

class CleanupEngineTest extends TestCase
{
    public function testClassifyByDate(): void
    {
        $storage = $this->createMock(StorageManager::class);
        $retention = ['daily' => 7, 'weekly' => 4, 'monthly' => 6];

        $engine = new CleanupEngine($storage, $retention);

        $now = time();

        // 1 天前的文件 → daily
        $files = [
            ['path' => 'backup_20250101_120000.zip', 'timestamp' => $now - 86400],
            ['path' => 'backup_20250102_120000.zip', 'timestamp' => $now - 14 * 86400], // 14天 → weekly
            ['path' => 'backup_20250103_120000.zip', 'timestamp' => $now - 60 * 86400], // 60天 → monthly
        ];

        $classified = $engine->classifyByDate($files);

        $this->assertCount(1, $classified['daily']);
        $this->assertCount(1, $classified['weekly']);
        $this->assertCount(1, $classified['monthly']);
    }

    public function testGetRetentionCutoffs(): void
    {
        $storage = $this->createMock(StorageManager::class);
        $retention = ['daily' => 7, 'weekly' => 4, 'monthly' => 6];

        $engine = new CleanupEngine($storage, $retention);

        $cutoffs = $engine->getRetentionCutoffs();

        $this->assertArrayHasKey('daily', $cutoffs);
        $this->assertArrayHasKey('weekly', $cutoffs);
        $this->assertArrayHasKey('monthly', $cutoffs);

        // 验证 daily 截止点在 7 天前
        $expectedDaily = strtotime('-7 days');
        $this->assertEqualsWithDelta($expectedDaily, $cutoffs['daily'], 10);

        // 验证 weekly 截止点在 4 周前
        $expectedWeekly = strtotime('-4 weeks');
        $this->assertEqualsWithDelta($expectedWeekly, $cutoffs['weekly'], 10);

        // 验证 monthly 截止点在 6 月前
        $expectedMonthly = strtotime('-6 months');
        $this->assertEqualsWithDelta($expectedMonthly, $cutoffs['monthly'], 10);
    }

    public function testMarkExpiredDaily(): void
    {
        $storage = $this->createMock(StorageManager::class);
        $retention = ['daily' => 7, 'weekly' => 4, 'monthly' => 6];

        $engine = new CleanupEngine($storage, $retention);

        $now = time();
        $cutoffs = $engine->getRetentionCutoffs();

        // 一个 10 天前的 daily 文件 → 应标记为删除
        $classified = [
            'daily' => [
                ['path' => 'old.zip', 'timestamp' => $now - 10 * 86400],
            ],
            'weekly' => [],
            'monthly' => [],
        ];

        $toDelete = $engine->markExpired($classified, $cutoffs);

        $this->assertCount(1, $toDelete);
        $this->assertEquals('old.zip', $toDelete[0]['path']);
    }

    public function testMarkExpiredWeeklyKeepsOne(): void
    {
        $storage = $this->createMock(StorageManager::class);
        $retention = ['daily' => 7, 'weekly' => 4, 'monthly' => 6];

        $engine = new CleanupEngine($storage, $retention);

        $now = time();
        $cutoffs = $engine->getRetentionCutoffs();

        // 同一周的两个文件（都在 cutoff 内）→ 应保留第一个，删除第二个
        $weekAgo = $now - 10 * 86400; // 10 天前（在 4 周 cutoff 内）
        $sameWeek = $weekAgo - 3600; // 同一周但更早

        $classified = [
            'daily' => [],
            'weekly' => [
                ['path' => 'keep.zip', 'timestamp' => $weekAgo],
                ['path' => 'delete.zip', 'timestamp' => $sameWeek],
            ],
            'monthly' => [],
        ];

        $toDelete = $engine->markExpired($classified, $cutoffs);

        // 第二个（更早的）应被删除
        $paths = array_column($toDelete, 'path');
        $this->assertContains('delete.zip', $paths);
        $this->assertNotContains('keep.zip', $paths);
    }

    public function testEmptyFiles(): void
    {
        $storage = $this->createMock(StorageManager::class);
        $retention = ['daily' => 7, 'weekly' => 4, 'monthly' => 6];

        $engine = new CleanupEngine($storage, $retention);
        $cutoffs = $engine->getRetentionCutoffs();

        $classified = ['daily' => [], 'weekly' => [], 'monthly' => []];

        $toDelete = $engine->markExpired($classified, $cutoffs);
        $this->assertEmpty($toDelete);
    }
}
