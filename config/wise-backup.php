<?php

return [
    // 数据库连接名称
    'connection'     => 'mysql',

    // 忽略的表（支持 glob 模式，如 'cache_*'）
    'ignore_tables'  => ['cache_*', 'migrations'],

    // 压缩级别（1-9）
    'compress_level' => 6,

    // 默认目标 disk
    'destination'    => 'local',

    // 备份目标 disk 列表
    'destinations'   => ['local'],

    // 存储 disk 配置
    'disks'          => [
        'local' => [
            'driver' => 'local',
            'root'   => '',
        ],
    ],

    // 保留策略
    'retention'      => [
        'daily'   => 7,
        'weekly'  => 4,
        'monthly' => 6,
    ],

    // 超时时间（秒）
    'timeout'        => 3600,

    // 分块大小（每次导出行数）
    'chunk_size'     => 10000,
];
