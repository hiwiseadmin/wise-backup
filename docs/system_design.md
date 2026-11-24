# wise-backup 代码审计报告

> 审审计人：Bob（Architect）｜ 审审计日期：2025-07-07  
> 项目：wiseadmin/wise-backup (ThinkPHP 8 数据库备份与恢复扩展包)  
> PHP: ^8.1 | 依赖: topthink/framework ^8.0, league/flysystem ^3.0

---

## 问题汇总

| # | 严重级别 | 类别 | 文件 | 行号 | 问题描述 |
|---|---------|------|------|------|---------|
| 1 | **P0** | Bug | Service.php | L6-10 | 引用不存在的命令类名（`BackupRun`→实际类名`Run`），包无法启动 |
| 2 | **P0** | Bug | MysqldumpDriver.php | L281-283 | offset=0 时导出全表数据，与 BackupManager 分块循环冲突导致**重复数据** |
| 3 | **P0** | Bug | Compressor.php | L33 | `setCompressionIndex(0,...)` 只压缩 index=0，多文件备份时其余文件不压缩 |
| 4 | **P1** | Security | MysqldumpDriver.php | L190, L343 | MySQL 密码通过 `-p'password'` 命令行传递，`ps aux` 暴露明文密码 |
| 5 | **P1** | Bug | SqlSplitter.php | L30 | `substr($sql, $i)` 在循环内每次截取 O(n) 子串，大文件 O(n²) 内存崩溃 |
| 6 | **P1** | Bug | MysqldumpDriver.php | L390 | `backupTable()` 用 `>` 重定向 stdout 到文件，错误信息丢失 |
| 7 | **P1** | Bug | BackupManager.php | L387-421 | `restore()` 逐表恢复，中间失败不回滚已恢复成功的表，数据不一致 |
| 8 | **P1** | Bug | BackupManager.php | L429 | AfterRestore 事件传入的 `$localFile` 可能在 finally 中被删除（远程下载场景） |
| 9 | **P1** | Bug | Compressor.php | L46-50 | `compress()` 打包后自动删除源文件，语义不明确且与 BackupManager finally 重复清理 |
| 10 | **P2** | Performance | PdoDriver.php | L159 | `exportData()` 每次调用都执行 `SHOW COLUMNS FROM`，同表多次分块重复查询 |
| 11 | **P2** | Performance | StorageManager.php | L168 | `copyToAll()` 用 `file_get_contents` 读整个 ZIP 到内存，大文件 OOM |
| 12 | **P2** | Performance | PdoDriver.php | L233 | `importFile()` 用 `file_get_contents` 读整个 SQL 到内存 |
| 13 | **P2** | Bug | BackupManager.php | L474 | `clean()` 无法感知 CleanupEngine 内部异常（被内部 catch 吞掉） |
| 14 | **P2** | Bug | CleanupEngine.php | L131 | `classifyByDate()` 硬编码 7/35 天分界，与 `retention.daily` 配置不联动 |
| 15 | **P2** | Bug | BackupManager.php | L521 | `migrate()` 忽略 `restore()` 返回值，部分失败也视为成功 |
| 16 | **P3** | Type | 多个文件 | 多处 | 缺少显式返回类型声明（PHP 8.1+ 应补全） |
| 17 | **P3** | Code | Facade.php | L19 | 类名 `Facade` 与 ThinkPHP 内置 `think\Facade` 易混淆 |
| 18 | **P3** | Code | BackupManager.php | L294+L304 | catch 与 finally 重复调用 `cleanupTempDir()`（`is_dir` 守卫无害但冗余） |

---

## 详细分析

### 问题 #1: Service.php 引用不存在的命令类名 — 包完全不可用

- **严重级别**: P0 (Blocker)
- **类别**: Bug
- **文件+行号**: `src/Service.php` L6-10
- **问题描述**:

  `Service::register()` 中使用 `BackupRun::class`, `BackupRestore::class`, `BackupClean::class`, `BackupMigrate::class`, `BackupInit::class`，但实际类名是 `Run`, `Restore`, `Clean`, `Migrate`, `Init`（无 `Backup` 前缀）。PHP 自动加载将失败，抛出 `Error`。

  ```php
  // Service.php (当前 — 错误)
  use wise\backup\command\BackupRun;         // ❌ 类不存在
  use wise\backup\command\BackupRestore;     // ❌ 类不存在
  
  // 实际类名
  // src/command/Run.php    → class Run extends Command     ✅
  // src/command/Restore.php → class Restore extends Command ✅
  ```

- **风险影响**: 扩展包注册时立即崩溃，所有 CLI 命令不可用，整个包完全无法工作。

- **修复建议**:

  ```php
  // Service.php (修复后)
  use wise\backup\command\Run;
  use wise\backup\command\Restore;
  use wise\backup\command\Clean;
  use wise\backup\command\Migrate;
  use wise\backup\command\Init;
  
  // register() 中:
  $this->commands([
      Run::class,
      Restore::class,
      Clean::class,
      Migrate::class,
      Init::class,
  ]);
  ```

- **是否确认修复**: **Y — 必须立即修复**

---

### 问题 #2: MysqldumpDriver offset=0 全量导出导致重复数据

- **严重级别**: P0 (Critical — 数据损坏)
- **类别**: Bug
- **文件+行号**: `src/driver/MysqldumpDriver.php` L281-283
- **问题描述**:

  `exportData()` 在 offset=0 时不加 LIMIT，mysqldump 导出**全表所有行**。BackupManager 的分块循环以为只导出了一个 chunk，继续以 `offset+=chunkSize` 调用，导致后续 chunk 与首次全量数据**重复**。

  假设表有 15000 行，chunkSize=10000：
  - 第 1 次调用 offset=0：mysqldump 不加 `--where` → **导出 15000 行全部数据**
  - 第 2 次调用 offset=10000：`--where="1=1 LIMIT 10000,10000"` → 导出 5000 行（行 10000-14999）
  - **结果：备份文件中 5000 行重复！还原后数据库有 20000 行。**

- **风险影响**: 备份文件包含重复数据。还原后表中存在重复行。数据完整性受损。

- **修复建议**:

  ```php
  // 方案1：始终添加 LIMIT（推荐）
  public function exportData(string $table, int $offset, int $chunkSize): string
  {
      $command = $this->buildCommand('exportData') . ' --no-create-info --skip-triggers --skip-add-drop-table';
      // 始终添加 LIMIT
      $command .= sprintf(' --where="1=1 LIMIT %d,%d"', $offset, $chunkSize);
      $command .= ' ' . escapeshellarg($table);
      // ...
  }
  
  // 方案2：BackupManager 侧检测（防御性）
  // 在 BackupManager::backup() 的 while 循环中:
  if ($driver->getDriverName() === 'mysqldump' && $offset === 0) {
      // mysqldump 首次已导出全部，跳出循环
      $content .= $data;
      break;
  }
  ```

- **是否确认修复**: **Y — 必须立即修复。推荐方案1：始终加 LIMIT。**

---

### 问题 #3: Compressor 只对 index 0 设置压缩

- **严重级别**: P0 (Critical)
- **类别**: Bug
- **文件+行号**: `src/helper/Compressor.php` L33
- **问题描述**:

  `$zip->setCompressionIndex(0, \ZipArchive::CM_DEFLATE, $level)` 只对索引 0 的条目设置 DEFLATE 压缩。后续 `addFile()` 添加的文件（index 1, 2, ...）不继承此设置，将**以存储模式（不压缩）写入 ZIP**。备份文件远大于预期。

- **风险影响**: 多表备份的 ZIP 文件绝大多数内容未压缩，占用大量磁盘空间和传输带宽。

- **修复建议**:

  ```php
  // 方案1：对每个文件分别设置压缩
  $index = 0;
  foreach ($files as $internalPath => $diskPath) {
      if (!file_exists($diskPath)) {
          $zip->close();
          throw new BackupException("File not found for compression: {$diskPath}");
      }
      $zip->addFile($diskPath, $internalPath);
      $zip->setCompressionIndex($index, \ZipArchive::CM_DEFLATE, $level);
      $index++;
  }
  
  // 方案2：使用 setCompressionName（更可读）
  foreach ($files as $internalPath => $diskPath) {
      $zip->addFile($diskPath, $internalPath);
      $zip->setCompressionName($internalPath, \ZipArchive::CM_DEFLATE, $level);
  }
  ```

- **是否确认修复**: **Y — 必须立即修复。推荐方案2。**

---

### 问题 #4: MySQL 密码通过命令行明文暴露

- **严重级别**: P1 (Security)
- **类别**: Security
- **文件+行号**: `src/driver/MysqldumpDriver.php` L190, L343
- **问题描述**:

  `buildCommand()` 和 `importFile()` 中，密码通过 `-p'password'` 拼接在命令行中。Linux 上任何用户执行 `ps aux` 都能看到完整命令行和密码。

  ```php
  // L190 (buildCommand)
  if (!empty($password)) {
      $command .= ' -p' . escapeshellarg($password);  // ps aux 可见!
  }
  ```

- **风险影响**: 服务器上任何能执行 `ps` 的用户可获取数据库密码。多租户环境下风险极高。

- **修复建议**:

  ```php
  // 推荐方案：使用 MYSQL_PWD 环境变量（MySQL 5.6+）
  // 修改 buildCommand()：命令行中不再包含 -p 参数
  // 在调用 exec() 前设置环境变量：
  
  // buildCommand() 中移除密码拼接:
  // $command .= ' -p' . escapeshellarg($password);  // 删除此行
  
  // 在 exportCreateTable/exportData/importFile 调用 exec 前:
  $env = $_ENV;
  if (!empty($this->dbConfig['password'])) {
      $env['MYSQL_PWD'] = $this->dbConfig['password'];
  }
  // 使用 proc_open 或 Symfony Process 传递环境变量
  // 或者使用 putenv('MYSQL_PWD=' . $password) 然后 exec()
  ```

- **是否确认修复**: **Y — 推荐使用 MYSQL_PWD 环境变量方案。**

---

### 问题 #5: SqlSplitter 循环内 O(n²) 子串创建

- **严重级别**: P1 (Bug — 大文件内存崩溃)
- **类别**: Bug
- **文件+行号**: `src/helper/SqlSplitter.php` L30
- **问题描述**:

  ```php
  for ($i = 0; $i < $length; $i++) {
      // ...
      if (stripos(substr($sql, $i), 'DELIMITER ') === 0) {  // L30
  ```

  每次循环迭代执行 `substr($sql, $i)` — 创建一个从位置 `$i` 到末尾的新字符串。对 10MB SQL 文件（~10M 字符），总计创建约 **50TB** 的临时字符串内存分配。在 `PdoDriver::importFile()` 中会触发（它用 `file_get_contents` 读整个文件后调用 `SqlSplitter::split()`）。

- **风险影响**: 导入稍大的 SQL 文件时 PHP 内存耗尽崩溃，备份恢复失败。

- **修复建议**:

  ```php
  // 修改 L30：只取需要的长度
  if (stripos(substr($sql, $i, 10), 'DELIMITER ') === 0) {
  // 或者更高效：直接比较
  if (strtoupper(substr($sql, $i, 10)) === 'DELIMITER ') {
  ```

- **是否确认修复**: **Y**

---

### 问题 #6: MysqldumpDriver::backupTable() 错误信息丢失

- **严重级别**: P1 (Bug)
- **类别**: Bug
- **文件+行号**: `src/driver/MysqldumpDriver.php` L390
- **问题描述**:

  ```php
  exec($command . ' > ' . escapeshellarg($tempFile) . ' 2>&1', $output, $returnVar);
  ```

  `>` 将 stdout 重定向到文件，`2>&1` 将 stderr 合并到 stdout（也进入文件）。`$output` 始终为空数组。如果 mysqldump 失败（$returnVar != 0），L393 的 `$errorMsg = implode("\n", $output)` 是空字符串，异常消息无意义。

- **风险影响**: 备份恢复失败时无法诊断原因，错误信息被写入数据文件而非捕获。

- **修复建议**:

  ```php
  // 分开捕获 stdout 和 stderr
  $command = $this->buildCommand('backupTable') . ' ' . escapeshellarg($table);
  $descriptorspec = [
      0 => ['pipe', 'r'],
      1 => ['file', $tempFile, 'w'],
      2 => ['pipe', 'w'],
  ];
  $process = proc_open($command, $descriptorspec, $pipes);
  $stderr = stream_get_contents($pipes[2]);
  fclose($pipes[2]);
  $returnVar = proc_close($process);
  
  if ($returnVar !== 0) {
      throw new BackupException("Failed to backup table {$table}: {$stderr}");
  }
  ```

- **是否确认修复**: **Y**

---

### 问题 #7: restore() 逐表恢复无全局回滚

- **严重级别**: P1 (Bug)
- **类别**: Bug — 数据一致性
- **文件+行号**: `src/BackupManager.php` L387-421
- **问题描述**:

  `restore()` 在 `for` 循环中逐表 `importFile()`。如果恢复表 #3 时失败：
  - 表 #1 和 #2 已成功恢复（如果 `dropTables=true`，原表已先 DROP）
  - 仅表 #3 尝试从 `.bak` 回滚
  - 表 #1、#2 保持"新数据"状态，无法回滚到原始状态
  - **数据库处于不一致状态：部分是旧备份数据，部分是原有数据**

- **风险影响**: 恢复中断后数据库状态不可预测。用户可能不知道哪些表已恢复、哪些未恢复。

- **修复建议**:

  ```php
  // 短期方案：在文档中明确说明恢复不保证原子性
  // 长期方案：先全部导入到临时表，全部成功后再 RENAME 替换
  // 或使用事务（仅 InnoDB 表支持 DDL 事务）
  ```

- **是否确认修复**: **Y — 短期标注文档警告，长期考虑事务性恢复。**

---

### 问题 #8: AfterRestore 事件传入已删除文件路径

- **严重级别**: P1 (Bug)
- **类别**: Bug
- **文件+行号**: `src/BackupManager.php` L429 → L451-455
- **问题描述**:

  当从远程下载时，`$localFile` 指向 `$tmpZipDir` 中的临时文件。AfterRestore 事件在 try 块内触发（L429），但 finally 块（L451-455）删除 `$tmpZipDir`。
  **事件的监听器收到一个即将被删除的文件路径**，任何试图读取该文件的操作都会失败。

- **风险影响**: 事件监听器无法在 AfterRestore 中访问备份文件内容（如做校验、复制到其他位置）。

- **修复建议**:

  ```php
  // 方案1：在 finally 之前先完成所有事件处理
  // 方案2：AfterRestore 中附带文件内容而非路径，如果文件即将被删
  // 方案3：将临时文件移到持久位置再触发事件
  ```

- **是否确认修复**: **Y — 建议在 finally 之前确保事件处理完成，或事件传文件内容。**

---

### 问题 #9: Compressor::compress() 自动删除源文件

- **严重级别**: P1 (Bug)
- **类别**: Bug — 副作用不明确
- **文件+行号**: `src/helper/Compressor.php` L46-50
- **问题描述**:

  `compress()` 方法名暗示只做压缩，但实际上在 L46-50 自动删除所有源文件。这是**隐藏的副作用**。如果 Compressor 在其他上下文中被调用（它是静态公共方法），调用方的文件会被意外删除。

  BackupManager 的 `finally` 块也调用 `cleanupTempDir()` 清理同一批文件——形成双重清理（虽然有 `is_dir` 守卫）。

- **风险影响**: Compressor 被复用时源文件被意外删除。开发者容易踩坑。

- **修复建议**:

  ```php
  // 方案1：移除 compress() 中的 unlink 逻辑，由调用方负责清理
  // 方案2：添加可选参数 $deleteSource = false
  
  public static function compress(array $files, string $output, int $level = 6, bool $deleteSource = false): string
  {
      // ... 压缩逻辑 ...
      
      if ($deleteSource) {
          foreach ($files as $diskPath) {
              @unlink($diskPath);
          }
      }
      return $output;
  }
  ```

- **是否确认修复**: **Y — 推荐方案2：添加可选参数控制。**

---

### 问题 #10: PdoDriver::exportData() 每次重复查询列信息

- **严重级别**: P2 (Performance)
- **类别**: Performance
- **文件+行号**: `src/driver/PdoDriver.php` L159
- **问题描述**:

  ```php
  $columnsStmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}`");
  ```

  每次 `exportData()` 调用都执行一次 `SHOW COLUMNS FROM`。对百万行表（chunkSize=10000），同一查询执行 100 次。

- **风险影响**: 对数据库增加不必要负载，延长备份时间。

- **修复建议**:

  ```php
  // 添加类属性缓存
  private array $columnsCache = [];
  
  public function exportData(string $table, int $offset, int $chunkSize): string
  {
      // ...
      if (!isset($this->columnsCache[$table])) {
          $columnsStmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}`");
          $this->columnsCache[$table] = $columnsStmt->fetchAll(\PDO::FETCH_COLUMN, 0);
      }
      $columns = $this->columnsCache[$table];
      // ...
  }
  ```

- **是否确认修复**: **Y**

---

### 问题 #11: StorageManager::copyToAll() 全文读入内存

- **严重级别**: P2 (Performance)
- **类别**: Performance
- **文件+行号**: `src/storage/StorageManager.php` L168
- **问题描述**:

  ```php
  $content = file_get_contents($localPath);
  ```

  将整个备份 ZIP 文件读入 PHP 内存。对于大型数据库（数GB），这将导致 OOM。

- **风险影响**: 大数据库备份传输失败，PHP 进程崩溃。

- **修复建议**:

  ```php
  // 使用 Flysystem 的 writeStream 或流式读取
  $stream = fopen($localPath, 'r');
  if ($stream === false) {
      throw new RemoteTransferException("Failed to open local file: {$localPath}");
  }
  
  foreach ($this->filesystems as $name => $filesystem) {
      if (strpos($name, 'local') === 0) continue;
      try {
          $filesystem->writeStream($remotePath, $stream);
          rewind($stream);  // 为下一个 disk 重置流位置
          $results[$name] = true;
      } catch (\Throwable $e) {
          $results[$name] = false;
      }
  }
  fclose($stream);
  ```

- **是否确认修复**: **Y**

---

### 问题 #12: PdoDriver::importFile() 全文读入内存

- **严重级别**: P2 (Performance)
- **类别**: Performance
- **文件+行号**: `src/driver/PdoDriver.php` L233
- **问题描述**:

  ```php
  $content = file_get_contents($filePath);
  $statements = SqlSplitter::split($content);
  ```

  与问题 #5、#11 叠加，大 SQL 文件消耗极大量内存。

- **风险影响**: 大备份文件恢复失败。

- **修复建议**: 使用逐行/流式读取 + 分号分割，避免一次性读入整个文件。或考虑按表文件分别导入（当前 SQL 文件已按表拆分）。

- **是否确认修复**: **Y — 但当前架构已按表拆分 SQL 文件，单表 SQL 通常不会过大。优先级可降低。**

---

### 问题 #13: BackupManager::clean() 吞掉错误

- **严重级别**: P2 (Bug)
- **类别**: Bug
- **文件+行号**: `src/BackupManager.php` L474 + `src/CleanupEngine.php` L100-102
- **问题描述**:

  CleanupEngine::execute() 在 foreach 循环中 catch 所有异常（L100-102），仅写日志，不收集错误信息。BackupManager::clean() 返回 `$deletedCount`，调用方无法知道哪些 disk 清理失败了。

- **风险影响**: 清理失败时调用方无感知，过期备份持续堆积。

- **修复建议**:

  ```php
  // CleanupEngine::execute() 返回更详细的结果:
  public function execute(array $diskNames = []): array
  {
      $results = ['deleted' => 0, 'errors' => []];
      // ...
      foreach ($diskNames as $diskName) {
          try {
              // ...
          } catch (\Throwable $e) {
              $results['errors'][$diskName] = $e->getMessage();
          }
      }
      return $results;
  }
  ```

- **是否确认修复**: **Y**

---

### 问题 #14: CleanupEngine 分类边界硬编码

- **严重级别**: P2 (Bug)
- **类别**: Bug
- **文件+行号**: `src/CleanupEngine.php` L131
- **问题描述**:

  `classifyByDate()` 硬编码 ≤7天→daily, 8-35天→weekly, >35天→monthly。但 `getRetentionCutoffs()` 使用 `retention.daily` 配置值（如 30 天）。

  如果 `retention.daily = 30`：
  - 7-30 天前的文件被归入 weekly 组
  - weekly 组只保留每周 1 份（其余删除）
  - 用户期望保留 30 天 daily 备份，实际只保留 7 天

- **风险影响**: 保留策略配置与实现不一致，用户可能丢失期望保留的备份。

- **修复建议**:

  ```php
  public function classifyByDate(array $files): array
  {
      $now = time();
      $dailyDays = $this->retention['daily'] ?? 7;
      $weeklyDays = $dailyDays + ($this->retention['weekly'] ?? 4) * 7;
      // ...
      foreach ($files as $file) {
          $ageInDays = ($now - $file['timestamp']) / 86400;
          if ($ageInDays <= $dailyDays) {
              $classified['daily'][] = $file;
          } elseif ($ageInDays <= $weeklyDays) {
              $classified['weekly'][] = $file;
          } else {
              $classified['monthly'][] = $file;
          }
      }
      return $classified;
  }
  ```

- **是否确认修复**: **Y**

---

### 问题 #15: migrate() 忽略 restore() 返回值

- **严重级别**: P2 (Bug)
- **类别**: Bug
- **文件+行号**: `src/BackupManager.php` L521
- **问题描述**:

  ```php
  $this->restore($localZipPath, $options);  // 返回值 ignored!
  ```
  
  `restore()` 返回 `bool`，但 `migrate()` 不检查。如果 restore 返回 false（或内部抛出异常被包装），migrate 仍认为成功。

- **风险影响**: 迁移失败时 CLI 报告成功，用户误以为数据已迁移。

- **修复建议**:

  ```php
  $result = $this->restore($localZipPath, $options);
  if (!$result) {
      throw new BackupException("Migration restore failed for: {$source}");
  }
  ```

- **是否确认修复**: **Y**

---

### 问题 #16: 缺少显式返回类型声明

- **严重级别**: P3 (Type Safety)
- **类别**: Type
- **文件+行号**: 多处
- **问题描述**:

  PHP 8.1 完全支持返回类型，以下方法缺少声明：
  - `BackupManager::getDatabaseConfig()` → `: array`
  - `MysqldumpDriver::buildCommand()` → `: string`
  - `PdoDriver::exportCreateTable()` → `: string`
  - `PdoDriver::exportData()` → `: string`
  - `CleanupEngine::parseTimestamp()` → `: int`
  - `AdapterFactory::create()` 和所有私有方法 → `: Filesystem`
  - `SqlSplitter::split()` → `: array`
  - `SqlSplitter::splitByTable()` → `: array`
  - `Compressor::compress()` → `: string`
  - `Compressor::extract()` → `: array`
  - `Compressor::verify()` → `: bool`
  - `StorageManager::allDisks()` → `: array`
  - `StorageManager::listContents()` → `: array`
  - `StorageManager::copyToAll()` → `: array`
  - 所有 Command 类的 `execute()` → `: int`（已部分有）

- **风险影响**: IDE 静态分析能力降低，类型相关的隐蔽 bug。

- **修复建议**: 逐方法补全 `: array`, `: string`, `: int`, `: bool`, `: void`, `: Filesystem` 等。

- **是否确认修复**: **Y — 可在常规迭代中逐步补全。**

---

### 问题 #17: Facade 类名与 ThinkPHP 内置冲突

- **严重级别**: P3 (Code Quality)
- **类别**: Code
- **文件+行号**: `src/Facade.php` L19
- **问题描述**:

  `wise\backup\Facade extends think\Facade`。虽不同命名空间，但当文件中同时 `use wise\backup\Facade` 和 `use think\Facade` 时，必须用别名区分。

- **风险影响**: 使用者混淆。建议重命名为 `BackupFacade`。

- **修复建议**: 重命名类为 `BackupFacade`。

- **是否确认修复**: **Y**

---

### 问题 #18: catch 与 finally 重复清理

- **严重级别**: P3 (Code Quality)
- **类别**: Code
- **文件+行号**: `src/BackupManager.php` L294 + L304
- **问题描述**:

  ```php
  } catch (\Throwable $e) {
      // ...
      $this->cleanupTempDir($tmpDir);  // L294
      // ...
  } finally {
      $this->cleanupTempDir($tmpDir);  // L304 — 重复调用
  }
  ```

  `cleanupTempDir()` 有 `is_dir()` 守卫（L565），第二次调用是空操作。但逻辑冗余，降低可读性。

- **修复建议**:

  ```php
  } catch (\Throwable $e) {
      try { $driver->enableForeignKeys(); } catch (\Throwable $e) {}
      throw new BackupException(...);
  } finally {
      $this->cleanupTempDir($tmpDir);  // 仅 finally 中清理，移除 catch 中的调用
  }
  ```

- **是否确认修复**: **Y — 移除 catch 中的 L294，仅保留 finally 中的 L304。**

---

## 新增发现的问题（不在主理人清单中的）

| # | 严重级别 | 类别 | 文件 | 行号 | 问题描述 |
|---|---------|------|------|------|---------|
| N1 | **P0** | Bug | Service.php | L6-10 | 命令类名引用错误（见问题#1） |
| N2 | **P0** | Bug | MysqldumpDriver.php | L281-283 | offset=0 全量导出+分块循环=重复数据（见问题#2） |
| N3 | **P1** | Bug | SqlSplitter.php | L30 | O(n²) 子串创建（见问题#5） |
| N4 | **P1** | Bug | MysqldumpDriver.php | L390 | backupTable 错误输出丢失（见问题#6） |
| N5 | **P1** | Bug | BackupManager.php | L387-421 | restore 无全局回滚（见问题#7） |
| N6 | **P2** | Bug | BackupManager.php | L521 | migrate 忽略 restore 返回值（见问题#15） |
| N7 | **P2** | Bug | BackupManager.php | L474 | clean 错误被吞（见问题#13） |
| N8 | **P2** | Bug | CleanupEngine.php | L131 | 分类边界硬编码（见问题#14） |

---

## 对主理人 17 个问题的逐一确认

| # | 主理人描述 | 确认 | 修正 |
|---|----------|------|------|
| 1 | MysqldumpDriver 密码暴露 | ✅ **确认 P1** | 同问题#4。importFile() 中也存在（L343） |
| 2 | exportCreateTable 命令注入 | ⚠️ **部分确认** | `escapeshellarg` 保护充分；风险主要在 L190 的密码暴露。WHERE 子句中 `%d` 强制整数 |
| 3 | Compressor 只压缩 index=0 | ✅ **确认 P0** | 同问题#3 |
| 4 | Compressor 自动删除源文件 | ✅ **确认 P1** | 同问题#9。降级为 P1（非数据损坏） |
| 5 | exportData 重复 SHOW COLUMNS | ✅ **确认 P2** | 同问题#10 |
| 6 | copyToAll 内存问题 | ✅ **确认 P2** | 同问题#11 |
| 7 | backup catch+finally 重复清理 | ✅ **确认 P3** | 同问题#18。降级为 P3（有 is_dir 守卫无害） |
| 8 | BeforeRestore 路径不一致 | ✅ **确认 P1** | 同问题#8。核心是 AfterRestore 中文件已被删 |
| 9 | cleanupTempDir 效率低 | ❌ **不确认** | `RecursiveIteratorIterator` 是 PHP 标准实践。`rm -rf` 不可移植且有安全风险 |
| 10 | restore force 模式无验证 | ✅ **确认 P2** | 这是功能缺失而非 Bug。已记录 |
| 11 | SqlSplitter 内存/DELIMITER | ✅ **确认 P1** | 同问题#5。补充了 O(n²) 子串的具体分析 |
| 12 | 缺少返回类型声明 | ✅ **确认 P3** | 同问题#16 |
| 13 | /86400 计算天数 | ⚠️ **部分确认 P3** | 影响微乎其微，不构成实际 Bug |
| 14 | markExpired daily 误删 weekly | ❌ **不确认** | `classifyByDate()` 将文件**互斥**分类（≤7天=daily, 8-35天=weekly, >35天=monthly），不存在重叠。但分类边界硬编码是另一个问题（见问题#14） |
| 15 | StorageManager::read() 无验证 | ⚠️ **部分确认 P3** | Flysystem 内部有路径保护，实际风险低 |
| 16 | offset=0 不加 LIMIT | ✅ **升级为 P0** | 这不仅是性能问题——与分块循环冲突导致**数据重复**（见问题#2） |
| 17 | Facade 命名冲突 | ✅ **确认 P3** | 同问题#17 |

---

## 修复优先级建议

```
P0 (立即修复 — 阻断性):
  ├─ #1  Service.php 类名引用错误（扩展包完全不可用）
  ├─ #2  MysqldumpDriver offset=0 重复数据 bug（备份数据损坏）
  └─ #3  Compressor 只压缩 index=0（备份文件未压缩）

P1 (紧急修复):
  ├─ #4  密码命令行暴露
  ├─ #5  SqlSplitter O(n²) 内存问题
  ├─ #6  backupTable 错误信息丢失
  ├─ #7  restore 无全局回滚
  ├─ #8  AfterRestore 路径已删除
  └─ #9  Compressor 自动删除源文件

P2 (计划修复):
  ├─ #10 PdoDriver 重复 SHOW COLUMNS
  ├─ #11 copyToAll 内存问题
  ├─ #12 importFile 内存问题
  ├─ #13 clean 错误被吞
  ├─ #14 分类边界硬编码
  └─ #15 migrate 忽略返回值

P3 (改进项):
  ├─ #16 返回类型补全
  ├─ #17 Facade 类名
  └─ #18 冗余清理调用
```
