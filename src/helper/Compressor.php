<?php

namespace wise\backup\helper;

use wise\backup\exception\BackupException;
use ZipArchive;

/**
 * ZIP 压缩/解压工具类
 */
class Compressor
{
    /**
     * 将多个文件打包为 ZIP
     *
     * @param array  $files       文件映射 ['内部路径' => '磁盘路径', ...]
     * @param string $output      ZIP 输出路径
     * @param int    $level       压缩级别（1-9），默认 6
     * @param bool   $deleteSource 是否在压缩后删除源文件，默认 false
     * @return string ZIP 文件完整路径
     * @throws BackupException
     */
    public static function compress(array $files, string $output, int $level = 6, bool $deleteSource = false): string
    {
        $zip = new \ZipArchive();
        $flags = \ZipArchive::CREATE | \ZipArchive::OVERWRITE;

        $result = $zip->open($output, $flags);
        if ($result !== true) {
            throw new BackupException("Failed to create ZIP file: {$output} (error code: {$result})");
        }

        foreach ($files as $internalPath => $diskPath) {
            if (!is_readable($diskPath)) {
                $zip->close();
                throw new BackupException("File not readable for compression: {$diskPath}");
            }

            if (!$zip->addFile($diskPath, $internalPath)) {
                $zip->close();
                throw new BackupException("Failed to add file to ZIP: {$diskPath}");
            }

            if (!$zip->setCompressionName($internalPath, \ZipArchive::CM_DEFLATE, $level)) {
                $zip->close();
                throw new BackupException("Failed to set compression for {$internalPath}");
            }
        }

        if (!$zip->close()) {
            throw new BackupException("Failed to finalize ZIP file: {$output}");
        }

        if ($deleteSource) {
            foreach ($files as $internalPath => $diskPath) {
                if (file_exists($diskPath)) {
                    @unlink($diskPath);
                }
            }
        }

        return $output;
    }

    /**
     * 解压 ZIP 文件到目标目录
     *
     * @param string $zipFile   ZIP 文件路径
     * @param string $targetDir 目标目录
     * @return array 解压出的文件路径数组
     * @throws BackupException
     */
    public static function extract(string $zipFile, string $targetDir): array
    {
        if (!file_exists($zipFile)) {
            throw new BackupException("ZIP file not found: {$zipFile}");
        }

        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                throw new BackupException("Failed to create target directory: {$targetDir}");
            }
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipFile);
        if ($result !== true) {
            throw new BackupException("Failed to open ZIP file: {$zipFile} (error code: {$result})");
        }

        try {
            $safeEntries = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $entry = (string) $zip->getNameIndex($i);

                if ($entry === '' || !self::isSafeRootEntry($entry)) {
                    continue;
                }

                $safeEntries[] = $entry;
            }

            if (empty($safeEntries)) {
                throw new BackupException("No valid file entry found in ZIP file: {$zipFile}");
            }

            if ($zip->extractTo($targetDir, $safeEntries) !== true) {
                throw new BackupException("Failed to extract ZIP file: {$zipFile} to {$targetDir}");
            }

            $targetPrefix = rtrim(realpath($targetDir) ?: $targetDir, '\\/') . DIRECTORY_SEPARATOR;
            $extractedFiles = [];

            foreach ($safeEntries as $entry) {
                $realPath = realpath($targetDir . DIRECTORY_SEPARATOR . $entry);
                if ($realPath === false || strpos($realPath, $targetPrefix) !== 0) {
                    continue;
                }
                $extractedFiles[] = $realPath;
            }

            return $extractedFiles;
        } finally {
            $zip->close();
        }
    }

    /**
     * 判断 ZIP 条目是否是「根目录下的普通文件」
     *
     * 备份包由本包生成（每张表一个 table.sql），因此：
     * - 含路径分隔符的条目（a/b.sql、../evil.sql）一律拒绝 → 防 Zip Slip；
     * - 以分隔符结尾的目录条目一律拒绝 → 否则调用方会把 dir/ 当成表名去导入。
     *
     * @param string $entry ZIP 条目名
     * @return bool
     */
    private static function isSafeRootEntry(string $entry): bool
    {
        if (str_ends_with($entry, '/') || str_ends_with($entry, '\\')) {
            return false;
        }

        return strpbrk($entry, "/\\") === false && $entry !== '.' && $entry !== '..';
    }

    /**
     * 校验 ZIP 文件完整性
     *
     * @param string $zipFile ZIP 文件路径
     * @return bool
     */
    public static function verify(string $zipFile): bool
    {
        if (!file_exists($zipFile)) {
            return false;
        }

        $zip = new \ZipArchive();
        $result = $zip->open($zipFile);
        if ($result !== true) {
            return false;
        }

        for ($i = 0; $i < $zip->numFiles; $i++) {
            $filename = $zip->getNameIndex($i);
            $stat = $zip->statName($filename);
            if ($stat === false) {
                $zip->close();
                return false;
            }
        }

        $zip->close();
        return true;
    }
}
