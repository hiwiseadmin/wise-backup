<?php

namespace wise\backup\tests;

use PHPUnit\Framework\TestCase;
use wise\backup\BackupDriverInterface;
use wise\backup\driver\MysqldumpDriver;
use wise\backup\driver\PdoDriver;

/**
 * 驱动接口测试
 *
 * 验证接口签名一致性及两个驱动类的完整实现
 */
class DriverTest extends TestCase
{
    /**
     * 接口应定义的 12 个方法名
     */
    private const REQUIRED_METHODS = [
        'connect',
        'getTables',
        'exportCreateTable',
        'exportData',
        'importSql',
        'importFile',
        'disableForeignKeys',
        'enableForeignKeys',
        'backupTable',
        'restoreTable',
        'getDriverName',
        'getMysqlVersion',
    ];

    // ========================
    // 接口定义测试
    // ========================

    public function testInterfaceMethodsExist(): void
    {
        $reflection = new \ReflectionClass(BackupDriverInterface::class);
        $methods = $reflection->getMethods();

        $methodNames = array_map(fn ($m) => $m->getName(), $methods);

        foreach (self::REQUIRED_METHODS as $method) {
            $this->assertContains($method, $methodNames, "Interface should have method: {$method}");
        }
    }

    public function testDriverNameIsString(): void
    {
        $reflection = new \ReflectionClass(BackupDriverInterface::class);
        $method = $reflection->getMethod('getDriverName');
        $returnType = $method->getReturnType();

        $this->assertNotNull($returnType);
        $this->assertEquals('string', $returnType->getName());
    }

    public function testGetTablesSignature(): void
    {
        $reflection = new \ReflectionClass(BackupDriverInterface::class);
        $method = $reflection->getMethod('getTables');

        $params = $method->getParameters();
        $this->assertCount(1, $params);

        // 第一个参数应有默认值 []
        $param = $params[0];
        $this->assertEquals('ignoreTables', $param->getName());
        $this->assertTrue($param->isDefaultValueAvailable());
        $this->assertEquals([], $param->getDefaultValue());
    }

    // ========================
    // MysqldumpDriver 实现验证
    // ========================

    public function testMysqldumpDriverImplementsInterface(): void
    {
        $reflection = new \ReflectionClass(MysqldumpDriver::class);

        $this->assertTrue(
            $reflection->implementsInterface(BackupDriverInterface::class),
            'MysqldumpDriver must implement BackupDriverInterface'
        );
    }

    public function testMysqldumpDriverHasAllMethods(): void
    {
        $reflection = new \ReflectionClass(MysqldumpDriver::class);
        $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        foreach (self::REQUIRED_METHODS as $method) {
            $this->assertContains(
                $method,
                $methods,
                "MysqldumpDriver must implement method: {$method}"
            );
        }
    }

    public function testMysqldumpDriverGetDriverName(): void
    {
        $driver = new MysqldumpDriver([]);
        $this->assertEquals('mysqldump', $driver->getDriverName());
    }

    public function testMysqldumpDriverGetMysqlVersionDefault(): void
    {
        // 未连接时返回空字符串
        $driver = new MysqldumpDriver([]);
        $this->assertIsString($driver->getMysqlVersion());
    }

    // ========================
    // PdoDriver 实现验证
    // ========================

    public function testPdoDriverImplementsInterface(): void
    {
        $reflection = new \ReflectionClass(PdoDriver::class);

        $this->assertTrue(
            $reflection->implementsInterface(BackupDriverInterface::class),
            'PdoDriver must implement BackupDriverInterface'
        );
    }

    public function testPdoDriverHasAllMethods(): void
    {
        $reflection = new \ReflectionClass(PdoDriver::class);
        $methods = array_map(fn ($m) => $m->getName(), $reflection->getMethods());

        foreach (self::REQUIRED_METHODS as $method) {
            $this->assertContains(
                $method,
                $methods,
                "PdoDriver must implement method: {$method}"
            );
        }
    }

    public function testPdoDriverGetDriverName(): void
    {
        $driver = new PdoDriver([]);
        $this->assertEquals('pdo', $driver->getDriverName());
    }

    public function testPdoDriverGetMysqlVersionDefault(): void
    {
        // 未连接时返回空字符串
        $driver = new PdoDriver([]);
        $this->assertIsString($driver->getMysqlVersion());
    }

    // ========================
    // 方法签名一致性检查
    // ========================

    /**
     * @dataProvider methodSignatureProvider
     */
    public function testMethodSignaturesMatchInterface(string $methodName): void
    {
        $ifaceRef = new \ReflectionMethod(BackupDriverInterface::class, $methodName);
        $mysqldumpRef = new \ReflectionMethod(MysqldumpDriver::class, $methodName);
        $pdoRef = new \ReflectionMethod(PdoDriver::class, $methodName);

        // 参数数量一致
        $this->assertCount(
            $ifaceRef->getNumberOfParameters(),
            $mysqldumpRef->getParameters(),
            "MysqldumpDriver::{$methodName} parameter count mismatch"
        );
        $this->assertCount(
            $ifaceRef->getNumberOfParameters(),
            $pdoRef->getParameters(),
            "PdoDriver::{$methodName} parameter count mismatch"
        );

        // 返回类型一致
        $ifaceReturn = $ifaceRef->getReturnType();
        if ($ifaceReturn !== null) {
            $this->assertNotNull($mysqldumpRef->getReturnType(), "MysqldumpDriver::{$methodName} missing return type");
            $this->assertNotNull($pdoRef->getReturnType(), "PdoDriver::{$methodName} missing return type");
            $this->assertEquals(
                (string) $ifaceReturn,
                (string) $mysqldumpRef->getReturnType(),
                "MysqldumpDriver::{$methodName} return type mismatch"
            );
            $this->assertEquals(
                (string) $ifaceReturn,
                (string) $pdoRef->getReturnType(),
                "PdoDriver::{$methodName} return type mismatch"
            );
        }
    }

    public static function methodSignatureProvider(): array
    {
        return array_map(fn ($m) => [$m], self::REQUIRED_METHODS);
    }
}
