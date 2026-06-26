<?php

namespace wise\backup\storage;

use League\Flysystem\Filesystem;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToDeleteFile;
use wise\backup\exception\RemoteTransferException;
use think\facade\Log;

/**
 * 存储管理器
 *
 * 封装多个 Flysystem disk，提供统一的多地存储操作接口
 */
class StorageManager
{
    /** @var array<string, Filesystem> 文件系统实例 */
    private array $filesystems = [];

    /**
     * 构造函数
     *
     * @param array $disksConfig disk 配置数组 ['disk_name' => ['driver' => '...', ...], ...]
     */
    public function __construct(array $disksConfig)
    {
        foreach ($disksConfig as $name => $config) {
            try {
                $this->filesystems[$name] = AdapterFactory::create($config);
                Log::channel('backup')->info("Storage disk '{$name}' initialized successfully");
            } catch (\Throwable $e) {
                Log::channel('backup')->error("Failed to initialize storage disk '{$name}': " . $e->getMessage());
            }
        }
    }

    /**
     * 获取指定 disk 的 Flysystem 实例
     *
     * @param string $name disk 名称
     * @return Filesystem
     * @throws RemoteTransferException
     */
    public function disk(string $name): Filesystem
    {
        if (!isset($this->filesystems[$name])) {
            throw new RemoteTransferException("Storage disk '{$name}' not found or failed to initialize");
        }

        return $this->filesystems[$name];
    }

    /**
     * 获取所有 disk 名称
     *
     * @return array
     */
    public function allDisks(): array
    {
        return array_keys($this->filesystems);
    }

    /**
     * 上传文件到指定 disk
     *
     * @param string $disk    disk 名称
     * @param string $path    远程路径
     * @param string $content 文件内容
     */
    public function put(string $disk, string $path, string $content): void
    {
        $filesystem = $this->disk($disk);
        try {
            $filesystem->write($path, $content);
        } catch (UnableToWriteFile $e) {
            throw new RemoteTransferException("Failed to write to disk '{$disk}' path '{$path}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 从指定 disk 读取文件
     *
     * @param string $disk disk 名称
     * @param string $path 远程路径
     * @return string
     */
    public function read(string $disk, string $path): string
    {
        $filesystem = $this->disk($disk);
        try {
            return $filesystem->read($path);
        } catch (UnableToReadFile $e) {
            throw new RemoteTransferException("Failed to read from disk '{$disk}' path '{$path}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 从指定 disk 删除文件
     *
     * @param string $disk disk 名称
     * @param string $path 远程路径
     */
    public function delete(string $disk, string $path): void
    {
        $filesystem = $this->disk($disk);
        try {
            $filesystem->delete($path);
        } catch (UnableToDeleteFile $e) {
            throw new RemoteTransferException("Failed to delete from disk '{$disk}' path '{$path}': " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * 检查文件是否存在
     *
     * @param string $disk disk 名称
     * @param string $path 远程路径
     * @return bool
     */
    public function exists(string $disk, string $path): bool
    {
        $filesystem = $this->disk($disk);
        return $filesystem->fileExists($path);
    }

    /**
     * 列出目录内容
     *
     * @param string $directory 目录路径
     * @param string $disk      disk 名称
     * @param bool   $recursive 是否递归
     * @return array
     */
    public function listContents(string $disk, string $directory, bool $recursive = false): array
    {
        $filesystem = $this->disk($disk);

        try {
            $listing = $filesystem->listContents($directory, $recursive);
            $contents = [];
            foreach ($listing as $item) {
                $contents[] = $item;
            }
            return $contents;
        } catch (\Throwable $e) {
            Log::channel('backup')->warning("Failed to list contents of disk '{$disk}' directory '{$directory}': " . $e->getMessage());
            return [];
        }
    }

    /**
     * 将本地文件复制到所有非 local disk
     *
     * @param string $localPath  本地文件路径
     * @param string $remotePath 远程路径
     * @return array ['disk_name' => true/false, ...]
     * @throws RemoteTransferException
     */
    public function copyToAll(string $localPath, string $remotePath): array
    {
        $results = [];

        if (!file_exists($localPath)) {
            throw new RemoteTransferException("Local file not found: {$localPath}");
        }

        $stream = fopen($localPath, 'r');
        if ($stream === false) {
            throw new RemoteTransferException("Failed to open local file: {$localPath}");
        }

        try {
            foreach ($this->filesystems as $name => $filesystem) {
                // 跳过 local disk
                if ($name === 'local') {
                    continue;
                }

                try {
                    $filesystem->writeStream($remotePath, $stream);
                    rewind($stream);  // 为下一个 disk 重置流位置
                    $results[$name] = true;
                    Log::channel('backup')->info("File copied to disk '{$name}': {$remotePath}");
                } catch (\Throwable $e) {
                    $results[$name] = false;
                    Log::channel('backup')->error("Failed to copy file to disk '{$name}': " . $e->getMessage());
                }
            }
        } finally {
            fclose($stream);
        }

        return $results;
    }
}
