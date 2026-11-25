<?php

namespace wise\backup\storage;

use League\Flysystem\Filesystem;
use League\Flysystem\UnableToReadFile;
use League\Flysystem\UnableToWriteFile;
use League\Flysystem\UnableToDeleteFile;
use wise\backup\exception\RemoteTransferException;
use wise\support\context\ContextualLogger;

/**
 * 存储管理器
 *
 * 封装多个 Flysystem disk，提供统一的多地存储操作接口
 */
class StorageManager
{
    /** @var array<string, array> disk 配置（懒加载，避免构造时就去连 S3/SFTP/FTP） */
    private array $disksConfig = [];

    /** @var array<string, Filesystem> 已初始化的文件系统实例 */
    private array $filesystems = [];

    /**
     * 构造函数
     *
     * @param array $disksConfig disk 配置数组 ['disk_name' => ['driver' => '...', ...], ...]
     */
    public function __construct(array $disksConfig)
    {
        $this->disksConfig = $disksConfig;
    }

    /**
     * 获取指定 disk 的 Flysystem 实例（首次访问时初始化）
     *
     * @param string $name disk 名称
     * @return Filesystem
     * @throws RemoteTransferException
     */
    public function disk(string $name): Filesystem
    {
        if (isset($this->filesystems[$name])) {
            return $this->filesystems[$name];
        }

        if (!isset($this->disksConfig[$name])) {
            throw new RemoteTransferException("Storage disk '{$name}' not found");
        }

        try {
            $filesystem = AdapterFactory::create($this->disksConfig[$name]);
        } catch (\Throwable $e) {
            throw new RemoteTransferException(
                "Storage disk '{$name}' failed to initialize: " . $e->getMessage(),
                0,
                $e
            );
        }

        ContextualLogger::channel('backup')->info("Storage disk '{$name}' initialized successfully");

        return $this->filesystems[$name] = $filesystem;
    }

    /**
     * 获取所有 disk 名称
     *
     * @return array
     */
    public function allDisks(): array
    {
        return array_keys($this->disksConfig);
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
     * 把远端文件流式写到本地（替代 read() + file_put_contents）
     *
     * read() 会把整份备份读进内存：3GB 的包需要 3GB+ 内存，
     * FPM 下直接 OOM，Swoole 下 OOM 会杀掉整个 worker。
     *
     * @param string $disk      disk 名称
     * @param string $path      远端路径
     * @param string $localPath 本地目标路径
     */
    public function copyToLocal(string $disk, string $path, string $localPath): void
    {
        $filesystem = $this->disk($disk);

        try {
            $stream = $filesystem->readStream($path);
        } catch (UnableToReadFile $e) {
            throw new RemoteTransferException("Failed to read from disk '{$disk}' path '{$path}': " . $e->getMessage(), 0, $e);
        }

        if (!is_resource($stream)) {
            throw new RemoteTransferException("Failed to open remote stream from disk '{$disk}' path '{$path}'");
        }

        $handle = fopen($localPath, 'wb');
        if ($handle === false) {
            fclose($stream);
            throw new RemoteTransferException("Failed to open local file for writing: {$localPath}");
        }

        try {
            if (stream_copy_to_stream($stream, $handle) === false) {
                throw new RemoteTransferException("Failed to copy remote file '{$path}' to '{$localPath}'");
            }
        } finally {
            fclose($handle);
            fclose($stream);
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
            ContextualLogger::channel('backup')->warning("Failed to list contents of disk '{$disk}' directory '{$directory}': " . $e->getMessage());
            return [];
        }
    }

    /**
     * 将本地文件复制到目标 disk
     *
     * @param string $localPath   本地文件路径
     * @param string $remotePath  远程路径
     * @param array  $destinations 目标 disk 列表（为空时复制到所有非 local disk，保持旧行为）
     * @return array ['disk_name' => true/false, ...]
     * @throws RemoteTransferException
     */
    public function copyToAll(string $localPath, string $remotePath, array $destinations = []): array
    {
        $results = [];

        if (!file_exists($localPath)) {
            throw new RemoteTransferException("Local file not found: {$localPath}");
        }

        $targets = empty($destinations)
            ? array_values(array_filter($this->allDisks(), static fn (string $name): bool => $name !== 'local'))
            : array_values(array_diff($destinations, ['local']));

        if (empty($targets)) {
            return $results;
        }

        foreach ($targets as $name) {
            $stream = fopen($localPath, 'r');
            if ($stream === false) {
                throw new RemoteTransferException("Failed to open local file: {$localPath}");
            }

            try {
                $this->disk($name)->writeStream($remotePath, $stream);
                $results[$name] = true;
                ContextualLogger::channel('backup')->info("File copied to disk '{$name}': {$remotePath}");
            } catch (\Throwable $e) {
                $results[$name] = false;
                ContextualLogger::channel('backup')->error("Failed to copy file to disk '{$name}': " . $e->getMessage());
            } finally {
                fclose($stream);
            }
        }

        return $results;
    }
}
