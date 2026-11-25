<?php

namespace wise\backup\storage;

use League\Flysystem\Filesystem;
use League\Flysystem\Local\LocalFilesystemAdapter;
use wise\backup\exception\RemoteTransferException;

/**
 * 存储适配器工厂
 *
 * 根据配置创建对应的 Flysystem 文件系统实例
 */
class AdapterFactory
{
    /**
     * 创建 Flysystem 文件系统实例
     *
     * @param array $config disk 配置
     * @return Filesystem
     * @throws RemoteTransferException
     */
    public static function create(array $config): Filesystem
    {
        $driver = $config['driver'] ?? 'local';

        switch ($driver) {
            case 'local':
                return self::createLocalAdapter($config);

            case 'sftp':
                return self::createSftpAdapter($config);

            case 's3':
                return self::createS3Adapter($config);

            case 'ftp':
                return self::createFtpAdapter($config);

            default:
                throw new RemoteTransferException("Unsupported storage driver: {$driver}");
        }
    }

    /**
     * 创建本地文件系统适配器
     *
     * @param array $config
     * @return Filesystem
     */
    private static function createLocalAdapter(array $config): Filesystem
    {
        $root = $config['root'] ?? '';
        if (empty($root)) {
            $root = runtime_path() . 'backup';
        }

        if (!is_dir($root)) {
            @mkdir($root, 0755, true);
        }

        $adapter = new LocalFilesystemAdapter($root);
        return new Filesystem($adapter);
    }

    /**
     * 创建 SFTP 适配器
     *
     * @param array $config
     * @return Filesystem
     * @throws RemoteTransferException
     */
    private static function createSftpAdapter(array $config): Filesystem
    {
        if (!class_exists(\League\Flysystem\PhpseclibV3\SftpAdapter::class)) {
            throw new RemoteTransferException(
                "SFTP 驱动需要安装 league/flysystem-sftp-v3，请执行: composer require league/flysystem-sftp-v3"
            );
        }

        $host = $config['host'] ?? '';
        $port = $config['port'] ?? 22;
        $username = $config['username'] ?? '';
        $root = $config['root'] ?? '/';

        if (empty($host) || empty($username)) {
            throw new RemoteTransferException("SFTP configuration requires host and username");
        }

        $connectionProvider = new \League\Flysystem\PhpseclibV3\SftpConnectionProvider(
            $host,
            $username,
            $config['password'] ?? null,
            $config['privateKey'] ?? null,
            $config['passphrase'] ?? null,
            (int) $port,
            false,
            30
        );

        $adapter = new \League\Flysystem\PhpseclibV3\SftpAdapter($connectionProvider, $root);
        return new Filesystem($adapter);
    }

    /**
     * 创建 S3 兼容适配器（AWS S3 / Aliyun OSS / Tencent COS）
     *
     * @param array $config
     * @return Filesystem
     * @throws RemoteTransferException
     */
    private static function createS3Adapter(array $config): Filesystem
    {
        if (!class_exists(\League\Flysystem\AwsS3V3\AwsS3V3Adapter::class)
            || !class_exists(\Aws\S3\S3Client::class)
        ) {
            throw new RemoteTransferException(
                "S3 驱动需要安装 league/flysystem-aws-s3-v3，请执行: composer require league/flysystem-aws-s3-v3"
            );
        }

        $key = $config['key'] ?? $config['access_key'] ?? '';
        $secret = $config['secret'] ?? $config['secret_key'] ?? '';
        $region = $config['region'] ?? 'us-east-1';
        $bucket = $config['bucket'] ?? '';
        $endpoint = $config['endpoint'] ?? null;

        if (empty($key) || empty($secret) || empty($bucket)) {
            throw new RemoteTransferException("S3 configuration requires key, secret, and bucket");
        }

        $s3Config = [
            'version'     => 'latest',
            'region'      => $region,
            'credentials' => [
                'key'    => $key,
                'secret' => $secret,
            ],
        ];

        if (!empty($endpoint)) {
            $s3Config['endpoint'] = $endpoint;
            $s3Config['use_path_style_endpoint'] = $config['use_path_style'] ?? true;
        }

        $client = new \Aws\S3\S3Client($s3Config);
        $adapter = new \League\Flysystem\AwsS3V3\AwsS3V3Adapter($client, $bucket);
        return new Filesystem($adapter);
    }

    /**
     * 创建 FTP 适配器
     *
     * @param array $config
     * @return Filesystem
     * @throws RemoteTransferException
     */
    private static function createFtpAdapter(array $config): Filesystem
    {
        if (!class_exists(\League\Flysystem\Ftp\FtpAdapter::class)) {
            throw new RemoteTransferException(
                "FTP 驱动需要安装 league/flysystem-ftp，请执行: composer require league/flysystem-ftp"
            );
        }

        $host = $config['host'] ?? '';
        $username = $config['username'] ?? '';
        $password = $config['password'] ?? '';
        $port = $config['port'] ?? 21;
        $root = $config['root'] ?? '/';
        $ssl = $config['ssl'] ?? false;
        $passive = $config['passive'] ?? true;

        if (empty($host) || empty($username)) {
            throw new RemoteTransferException("FTP configuration requires host and username");
        }

        $options = \League\Flysystem\Ftp\FtpConnectionOptions::fromArray([
            'host'     => $host,
            'username' => $username,
            'password' => $password,
            'port'     => (int) $port,
            'root'     => $root,
            'ssl'      => (bool) $ssl,
            'passive'  => (bool) $passive,
        ]);

        $adapter = new \League\Flysystem\Ftp\FtpAdapter($options);
        return new Filesystem($adapter);
    }
}
