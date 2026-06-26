# wiseadmin/wise-backup

> ThinkPHP 8 数据库备份与恢复扩展包 — 一键备份、多地容灾、自动清理

[![PHP Version](https://img.shields.io/badge/PHP-%3E%3D8.1-blue)](https://www.php.net)
[![ThinkPHP](https://img.shields.io/badge/ThinkPHP-%5E6.0%20%7C%7C%20%5E8.0-green)](https://www.thinkphp.cn) 
[![License](https://img.shields.io/badge/license-MIT-brightgreen)](LICENSE)
[![Packagist](https://img.shields.io/badge/packagist-wiseadmin%2Fwise--backup-orange)](https://packagist.org/packages/wiseadmin/wise-backup)

---

## ✨ 特性

- 🔧 **双驱动支持**：优先使用 `mysqldump` 二进制（性能更优），不可用时自动降级为纯 PHP PDO 驱动
- 📦 **智能压缩**：备份数据打包为 ZIP，支持自定义压缩级别
- 🌍 **多地容灾**：集成 Flysystem，支持本地 / SFTP / S3 / FTP / OSS / COS 远程存储
- 🧹 **自动清理**：按日/周/月保留策略自动清理过期备份文件
- 🔌 **事件驱动**：丰富的生命周期事件（BeforeBackup、AfterTableBackup、AfterBackup 等），方便扩展
- 🖥️ **CLI 命令**：5 个 artisan 风格命令，快速上手
- 🎭 **门面模式**：支持 `\wise\backup\Facade` 静态调用

---

## 📦 安装

```bash
composer require wiseadmin/wise-backup
```

### 可选存储驱动

```bash
# SFTP 远程备份
composer require league/flysystem-sftp-v3

# S3 / 阿里云OSS / 腾讯云COS 远程备份
composer require league/flysystem-aws-s3-v3

# FTP 远程备份
composer require league/flysystem-ftp
```

---

## 🚀 快速开始

### 初始化配置

```bash
php think wise:backup:publish
```

执行后会在项目 `config/wise-backup.php` 生成配置文件。

### CLI 命令

#### wise:backup:run — 执行备份

```bash
# 备份所有表
php think wise:backup:run

# 备份指定表
php think wise:backup:run --tables=users,orders,products

# 排除指定表
php think wise:backup:run --exclude=cache_*,logs_*,sessions*

# 备份到远程 S3
php think wise:backup:run --destination=s3
```

#### wise:backup:restore — 还原备份

```bash
# 从本地文件还原
php think wise:backup:restore /path/to/backup_20250101_120000.zip

# 从远程 SFTP 还原（先删除现有表）
php think wise:backup:restore sftp://host/backups/backup_20250101_120000.zip --drop-tables

# 强制还原（还原前先备份现有表）
php think wise:backup:restore /path/to/backup.zip --force
```

#### wise:backup:clean — 清理过期备份

```bash
php think wise:backup:clean
```

#### wise:backup:migrate — 从远程源迁移

```bash
# 从 SFTP 迁移
php think wise:backup:migrate --remote=sftp://server/backups/latest.zip

# 从 S3 迁移并指定表
php think wise:backup:migrate --remote=s3://bucket/backups/backup.zip --tables=users,orders
```

#### wise:backup:publish — 发布备份配置文件

```bash
php think wise:backup:publish
```

### 编程接口

#### 门面方式

```php
use wise\backup\Facade as Backup;

// 备份
$zipFile = Backup::backup(['tables' => ['users', 'orders']]);

// 还原
Backup::restore('/path/to/backup.zip', ['drop_tables' => true]);

// 清理
$deleted = Backup::clean();

// 迁移
$localFile = Backup::migrate('sftp://host/backups/latest.zip');
```

#### 依赖注入方式

```php
use wise\backup\BackupManager;

class MyController
{
    public function backup(BackupManager $manager)
    {
        $zipFile = $manager->backup();
        return 'Backup created: ' . $zipFile;
    }
}
```

### 事件监听

扩展包在关键节点触发事件，可在项目 `app/event.php` 中注册监听：

```php
<?php
// app/event.php

return [
    \wise\backup\event\BeforeBackup::class => [
        \app\listener\BeforeBackupListener::class,
    ],
    \wise\backup\event\AfterBackup::class => [
        \app\listener\AfterBackupListener::class,
    ],
    \wise\backup\event\AfterRestore::class => [
        \app\listener\AfterRestoreListener::class,
    ],
];
```

可用事件：

| 事件类 | 触发时机 | 属性 |
|--------|---------|------|
| `BeforeBackup` | 备份开始前 | `$tables`, `$destination` |
| `AfterTableBackup` | 每张表备份完成 | `$table`, `$tempFile`, `$rowsExported` |
| `AfterBackup` | 全部备份完成 | `$zipFile`, `$tables`, `$duration` |
| `BeforeRestore` | 还原开始前 | `$backupFile`, `$dropTables` |
| `AfterRestore` | 还原完成后 | `$backupFile`, `$tablesRestored`, `$duration` |
| `BeforeClean` | 清理开始前 | `$disks`, `$retention` |
| `AfterClean` | 清理完成后 | `$deletedCount`, `$deletedFiles` |

---

## ⚙️ 配置

发布配置文件：

```bash
php think wise:backup:publish
```

配置文件 `config/wise-backup.php`:

```php
return [
    // 数据库连接名称（对应 config/database.php 中的 connections）
    'connection'     => 'mysql',

    // 需要备份的表，'*' 表示所有表
    'tables'         => '*',

    // 忽略的表（支持 glob 模式）
    'ignore_tables'  => ['cache_*', 'migrations'],

    // 是否压缩为 ZIP
    'compress'       => true,

    // 压缩级别（1-9，越大压缩率越高但越慢）
    'compress_level' => 6,

    // 默认目标 disk
    'destination'    => 'local',

    // 备份目标 disk 列表（支持同时备份到多个位置）
    'destinations'   => ['local'],

    // 存储 disk 配置
    'disks'          => [
        'local' => [
            'driver' => 'local',
            'root'   => '',  // 留空默认用 runtime_path() . 'backup'
        ],
        // SFTP 远程存储示例
        'sftp' => [
            'driver'     => 'sftp',
            'host'       => '192.168.1.100',
            'port'       => 22,
            'username'   => 'backup',
            'password'   => 'your_password',   // 或使用 privateKey
            // 'privateKey' => '/path/to/id_rsa',
            // 'passphrase' => '',
            'root'       => '/home/backup/',
        ],
        // S3 兼容存储示例
        's3' => [
            'driver'    => 's3',
            'key'       => 'your-access-key',
            'secret'    => 'your-secret-key',
            'region'    => 'us-east-1',
            'bucket'    => 'my-backups',
            'endpoint'  => 'https://s3.amazonaws.com',  // OSS/COS 自定义 endpoint
        ],
        // FTP 存储示例
        'ftp' => [
            'driver'   => 'ftp',
            'host'     => 'ftp.example.com',
            'port'     => 21,
            'username' => 'backup',
            'password' => 'your_password',
            'root'     => '/backups/',
            'ssl'      => false,
            'passive'  => true,
        ],
    ],

    // 保留策略
    'retention'      => [
        'daily'   => 7,   // 日备份保留天数
        'weekly'  => 4,   // 周备份保留周数（每周保留最新一份）
        'monthly' => 6,   // 月备份保留月数（每月保留最新一份）
    ],

    // 超时时间（秒）
    'timeout'        => 3600,

    // 分块大小（每次导出行数）
    'chunk_size'     => 10000,
];
```

### 扩展存储驱动

如需支持其他存储驱动（阿里云 OSS、腾讯云 COS 等），可通过 S3 兼容模式配置：

```php
// Aliyun OSS
's3' => [
    'driver'      => 's3',
    'key'         => env('OSS_ACCESS_KEY'),
    'secret'      => env('OSS_SECRET_KEY'),
    'region'      => 'oss-cn-hangzhou',
    'bucket'      => 'my-backups',
    'endpoint'    => 'https://oss-cn-hangzhou.aliyuncs.com',
],

// Tencent COS
's3' => [
    'driver'      => 's3',
    'key'         => env('COS_SECRET_ID'),
    'secret'      => env('COS_SECRET_KEY'),
    'region'      => 'ap-guangzhou',
    'bucket'      => 'my-backups-1234567890',
    'endpoint'    => 'https://cos.ap-guangzhou.myqcloud.com',
],
```

---

## 📂 目录结构

```
src/
├── BackuapDriverInterface.php    # 备份驱动接口
├── BackupManager.php             # 核心备份管理器
├── CleanupEngine.php             # 清理引擎
├── command/
│   ├── Clean.php                 # wise:backup:clean
│   ├── Migrate.php               # wise:backup:migrate
│   ├── Publish.php               # wise:backup:publish
│   ├── Restore.php               # wise:backup:restore
│   └── Run.php                   # wise:backup:run
├── driver/
│   ├── MysqldumpDriver.php       # mysqldump 二进制驱动
│   └── PdoDriver.php             # PHP PDO 驱动
├── event/
│   ├── AfterBackup.php
│   ├── AfterClean.php
│   ├── AfterRestore.php
│   ├── AfterTableBackup.php
│   ├── BeforeBackup.php
│   ├── BeforeClean.php
│   └── BeforeRestore.php
├── exception/
│   ├── BackupException.php
│   ├── CleanupException.php
│   ├── RemoteTransferException.php
│   └── RestoreException.php
├── Facade.php                    # 门面类
├── helper/
│   ├── Compressor.php            # ZIP 压缩工具
│   └── SqlSplitter.php           # SQL 分割工具
├── Service.php                   # 服务提供者
├── storage/
│   ├── AdapterFactory.php        # 存储适配器工厂
│   └── StorageManager.php        # 存储管理器
└── trait/
    ├── CommandTrait.php
    └── ServiceTrait.php
```

---

## 📄 License

MIT
