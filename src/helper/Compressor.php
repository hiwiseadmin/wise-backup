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
            if (!file_exists($diskPath)) {
                $zip->close();
                throw new BackupException("File not found for compression: {$diskPath}");
            }
            $zip->addFile($diskPath, $internalPath);
            $zip->setCompressionName($internalPath, \ZipArchive::CM_DEFLATE, $level);
        }

        $zip->close();

        // 打包完成后按需清理源临时文件
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
            if (!$zip->extractTo($targetDir)) {
                throw new BackupException("Failed to extract ZIP file: {$zipFile} to {$targetDir}");
            }

            $realTargetDir = realpath($targetDir) ?: $targetDir;
            $extractedFiles = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $fullPath = $targetDir . DIRECTORY_SEPARATOR . $filename;
                $realPath = realpath($fullPath) ?: $fullPath;
                // 防止 Zip Slip：确保解压后的文件仍在目标目录内
                if (strpos($realPath, $realTargetDir) !== 0) {
                    continue;
                }
                $extractedFiles[] = $fullPath;
            }

            return $extractedFiles;
        } finally {
            $zip->close();
        }
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

        // 遍历所有条目，用 statName 校验
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
