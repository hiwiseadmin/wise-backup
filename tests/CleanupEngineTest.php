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

        $files = [
            ['path' => 'backup_20250101_120000.zip', 'timestamp' => $now - 86400],
            ['path' => 'backup_20250102_120000.zip', 'timestamp' => $now - 14 * 86400],
            ['path' => 'backup_20250103_120000.zip', 'timestamp' => $now - 60 * 86400],
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

        $expectedDaily = strtotime('-7 days');
        $this->assertEqualsWithDelta($expectedDaily, $cutoffs['daily'], 10);

        $expectedWeekly = strtotime('-4 weeks');
        $this->assertEqualsWithDelta($expectedWeekly, $cutoffs['weekly'], 10);

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

        $weekAgo = $now - 10 * 86400;
        $sameWeek = $weekAgo - 3600;

        $classified = [
            'daily' => [],
            'weekly' => [
                ['path' => 'keep.zip', 'timestamp' => $weekAgo],
                ['path' => 'delete.zip', 'timestamp' => $sameWeek],
            ],
            'monthly' => [],
        ];

        $toDelete = $engine->markExpired($classified, $cutoffs);

        $paths = array_column($toDelete, 'path');
        $this->assertContains('delete.zip', $paths);
        $this->assertNotContains('keep.zip', $paths);
    }

    public function testExecuteMatchesSameSecondSequenceNames(): void
    {
        $storage = $this->createMock(StorageManager::class);
        $storage->method('allDisks')->willReturn(['local']);
        $storage->method('listContents')->willReturn([
            ['path' => 'backup_20200101_120000.zip'],
            ['path' => 'backup_20200101_120000_1.zip'],
            ['path' => 'other.zip'],
        ]);

        $deleted = [];
        $storage->method('delete')->willReturnCallback(function (string $disk, string $path) use (&$deleted): void {
            $deleted[] = $path;
        });

        $engine = new CleanupEngine($storage, ['daily' => 7, 'weekly' => 4, 'monthly' => 6]);
        $result = $engine->execute();

        $this->assertContains('backup_20200101_120000.zip', $deleted);
        $this->assertContains('backup_20200101_120000_1.zip', $deleted);
        $this->assertNotContains('other.zip', $deleted);
        $this->assertSame(2, $result['deleted']);
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
