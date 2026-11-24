<?php
declare(strict_types=1);

/**
 * PHPUnit bootstrap for wise-backup package tests
 *
 * 包既可独立安装（自带 vendor/），也可在 monorepo 中直接跑测试（vendor 在 src/ 下）。
 * 因此按候选路径依次探测，而不是硬编码 `vendor/autoload.php`——后者在仓库内运行时
 * 会报「Cannot open bootstrap script .../wise-backup/vendor/autoload.php」。
 */

$autoloadCandidates = [
    dirname(__DIR__) . '/vendor/autoload.php',
    dirname(__DIR__, 4) . '/vendor/autoload.php',
    dirname(__DIR__, 5) . '/vendor/autoload.php',
];

$autoloadLoaded = false;
foreach ($autoloadCandidates as $autoloadPath) {
    if (is_file($autoloadPath)) {
        require_once $autoloadPath;
        $autoloadLoaded = true;
        break;
    }
}

if (!$autoloadLoaded) {
    throw new \RuntimeException(
        '找不到 composer autoload.php，请先在项目根执行 composer install。候选路径：'
        . implode(', ', $autoloadCandidates)
    );
}

$appRoot = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR . 'app';
spl_autoload_register(static function (string $class) use ($appRoot): void {
    if (str_starts_with($class, 'app\\')) {
        $file = $appRoot . DIRECTORY_SEPARATOR
            . str_replace('\\', DIRECTORY_SEPARATOR, substr($class, 4)) . '.php';
        if (is_file($file)) {
            require_once $file;
        }
    }
});

$projectRoot = dirname(__DIR__, 4) . DIRECTORY_SEPARATOR;

if (!function_exists('root_path')) {
    function root_path(string $path = ''): string
    {
        return $GLOBALS['wise_backup_test_root'] . ltrim($path, '/\\');
    }
}
if (!function_exists('runtime_path')) {
    function runtime_path(string $path = ''): string
    {
        return $GLOBALS['wise_backup_test_root'] . 'runtime' . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
if (!function_exists('app_path')) {
    function app_path(string $path = ''): string
    {
        return $GLOBALS['wise_backup_test_root'] . 'app' . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
if (!function_exists('public_path')) {
    function public_path(string $path = ''): string
    {
        return $GLOBALS['wise_backup_test_root'] . 'public' . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}
if (!function_exists('config_path')) {
    function config_path(string $path = ''): string
    {
        return $GLOBALS['wise_backup_test_root'] . 'config' . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
    }
}

$GLOBALS['wise_backup_test_root'] = $projectRoot;

if (!function_exists('config')) {
    function config($name = null, $default = null)
    {
        try {
            $config = \think\Container::getInstance()->config;

            return $name === null ? $config->get() : $config->get($name, $default);
        } catch (\Throwable) {
            return $default;
        }
    }
}

if (!class_exists(WiseBackupTestNullLogger::class)) {
    class WiseBackupTestNullLogger
    {
        public function channel(string $name): self
        {
            return $this;
        }

        public function __call($method, $args)
        {
            return null;
        }

        public static function __callStatic($method, $args)
        {
            return null;
        }
    }
}

$testApp  = new \think\App();
$nullLog  = new WiseBackupTestNullLogger();
$testApp->instance('log', $nullLog);
$testApp->instance(\think\Log::class, $nullLog);
