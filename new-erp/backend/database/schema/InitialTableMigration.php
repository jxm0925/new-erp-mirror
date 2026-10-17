<?php

namespace Database\Schema;

use Illuminate\Database\Connection;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * 每表的完整初始定义共用此入口。旧库只读接纳，新库仅建缺表；
 * 不修复漂移，不回填业务数据，不代替 Laravel 写迁移记录。
 */
abstract class InitialTableMigration extends Migration
{
    public const TABLE = '';
    public const CREATE_SQL = '';
    public const DECLARED_CHECKS = [];
    public const LEGACY_UNIQUE_INDEX = [];
    public const ESTABLISHED_EXTENSION = false;

    public $withinTransaction = false;

    private static ?WeakMap $plans = null;
    private static ?WeakMap $unsafePdos = null;

    public static function baseline(): array
    {
        return json_decode(file_get_contents(__DIR__.'/baseline.json'), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string, self> */
    public static function definitions(): array
    {
        $definitions = [];
        foreach (static::baseline()['tables'] as $table => $name) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/D', $table) || !preg_match('/^[a-zA-Z0-9_]+$/D', $name)) {
                throw new RuntimeException('Invalid initial migration manifest entry.');
            }
            $definition = require __DIR__.'/../migrations/'.$name.'.php';
            if (!$definition instanceof self || $definition::TABLE !== $table) {
                throw new RuntimeException('Initial migration definition mismatch: '.$table);
            }
            [$header] = static::parts($definition::CREATE_SQL);
            if ($header !== ['create', 'table', static::quoteIdentifier($table)]) {
                throw new RuntimeException('CREATE TABLE target differs from manifest: '.$table);
            }
            $definitions[$table] = $definition;
        }

        return $definitions;
    }

    protected function databaseConnection(): Connection
    {
        return DB::connection($this->getConnection());
    }

    /**
     * 全库只读预检。可供维护工具调用；禁止在这里执行 SET、DDL 或写 ledger。
     * 使用写连接读取元数据，避免副本延迟把已有表误认为缺表。
     */
    public static function preflight(Connection $connection): array
    {
        return static::inspectBaseline($connection);
    }

    /**
     * 唯一索引兼容只由声明它的 owner 调用；普通 preflight 永远不忽略缺失约束。
     * 全表、完整 legacy ledger、owner 未登记、精确单索引缺口及零重复必须同时满足。
     */
    final public function preflightLegacyUniqueIndex(Connection $connection): array
    {
        $this->legacyUniqueIndexClause();

        return static::inspectBaseline($connection, $this);
    }

    /** 新业务表仅由声明它的完整 owner 扩展已接纳基线，普通预检仍拒绝缺表。 */
    final public function preflightEstablishedExtension(Connection $connection): array
    {
        if (!static::ESTABLISHED_EXTENSION) {
            throw new RuntimeException('This owner does not declare an established-baseline extension.');
        }
        return static::inspectBaseline($connection, null, $this);
    }

    private static function inspectBaseline(Connection $connection, ?self $owner = null, ?self $newOwner = null): array
    {
        if ($connection->pretending()) {
            throw new RuntimeException('Read-only preflight requires actual metadata, not pretend results.');
        }
        static::assertSafeSession($connection->getPdo());
        if ($connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== '') {
            throw new RuntimeException('Initial migrations require MySQL and an empty table prefix.');
        }
        $identity = (array) $connection->selectOne('SELECT DATABASE() AS database_name, VERSION() AS server_version', [], false);
        if (empty($identity['database_name'])) {
            throw new RuntimeException('No database is selected for initial migration preflight.');
        }
        $version = (string) ($identity['server_version'] ?? '');
        static::checkVersion($version);
        $manifest = static::baseline();
        $definitions = static::definitions();
        if (array_keys($definitions) !== array_keys($manifest['tables'])) {
            throw new RuntimeException('Initial migration manifest and definitions disagree.');
        }
        $tables = static::tableNames($connection);
        $unknown = array_diff(array_keys($tables), array_merge(array_keys($definitions), ['migrations']));
        if ($unknown) {
            throw new RuntimeException('Unknown database tables: '.implode(', ', $unknown));
        }
        foreach ($tables as $table => $type) {
            if ($type !== 'BASE TABLE') {
                throw new RuntimeException('Views are not accepted by the initial baseline: '.$table);
            }
        }

        $records = [];
        if (isset($tables['migrations'])) {
            foreach ($connection->select('SELECT migration FROM '.static::quoteIdentifier('migrations'), [], false) as $row) {
                $name = (string) ((array) $row)['migration'];
                if (isset($records[$name])) {
                    throw new RuntimeException('Duplicate migration ledger entry: '.$name);
                }
                $records[$name] = true;
            }
        }
        $canonical = array_flip($manifest['tables']);
        $legacy = $manifest['legacy_migrations'];
        $unknownRecords = array_diff(array_keys($records), array_merge(array_keys($canonical), array_keys($legacy)));
        if ($unknownRecords) {
            throw new RuntimeException('Unknown migration ledger entries: '.implode(', ', $unknownRecords));
        }
        $legacyRecords = array_intersect_key($records, $legacy);
        if ($legacyRecords && count($legacyRecords) !== count($legacy)) {
            throw new RuntimeException('Incomplete legacy migration ledger; historical data migrations must not be skipped.');
        }
        foreach (array_intersect_key($records, $canonical) as $name => $_) {
            if (!isset($tables[$canonical[$name]])) {
                throw new RuntimeException('Recorded initial migration has no table: '.$canonical[$name]);
            }
        }

        $present = array_intersect_key($tables, $definitions);
        $missing = array_diff_key($definitions, $present);
        $establishedExtension = false;
        if ($newOwner) {
            $canonicalOwner = $definitions[$newOwner::TABLE] ?? null;
            if (!$canonicalOwner || !$canonicalOwner::ESTABLISHED_EXTENSION
                || static::normalizeSql($canonicalOwner::CREATE_SQL) !== static::normalizeSql($newOwner::CREATE_SQL)
                || $canonicalOwner::DECLARED_CHECKS !== $newOwner::DECLARED_CHECKS) {
                throw new RuntimeException('New-table owner differs from the canonical manifest.');
            }
            $otherDefinitions = array_diff_key($definitions, [$newOwner::TABLE => true]);
            // 必须已登记其余全部初始定义，且只允许本 owner 尚未登记、尚未建表。
            // 既有表丢失、其他新表缺失或旧基线尚未接纳均不能走这个入口。
            $otherNames = array_map(fn ($table) => $manifest['tables'][$table], array_keys($otherDefinitions));
            $establishedExtension = $otherDefinitions
                && !isset($records[$manifest['tables'][$newOwner::TABLE]])
                && array_diff($otherNames, array_keys($records)) === []
                && array_diff(array_keys($missing), [$newOwner::TABLE]) === [];
        }
        if ($missing && $legacyRecords && !$establishedExtension) {
            throw new RuntimeException('Partial legacy database: missing '.implode(', ', array_keys($missing)));
        }
        $pendingLegacyIndex = false;
        if ($owner && (!isset($definitions[$owner::TABLE])
            || $definitions[$owner::TABLE]::LEGACY_UNIQUE_INDEX !== $owner::LEGACY_UNIQUE_INDEX
            || static::normalizeSql($definitions[$owner::TABLE]::CREATE_SQL) !== static::normalizeSql($owner::CREATE_SQL))) {
            throw new RuntimeException('Legacy compatibility owner differs from the canonical manifest.');
        }
        // 必须先核对所有表再允许首张表建表，不能一边修复一边发现后面的漂移。
        foreach ($present as $table => $_) {
            $actual = static::showCreate($connection, $table);
            if ($owner && $table === $owner::TABLE && $owner->schemaDifferences($actual, $version)) {
                $owner->assertOnlyLegacyUniqueIndexMissing($actual, $version);
                if ($missing || !$legacyRecords || count($legacyRecords) !== count($legacy)) {
                    throw new RuntimeException('Legacy index compatibility requires the complete legacy database and ledger.');
                }
                if (isset($records[$manifest['tables'][$table]])) {
                    throw new RuntimeException('Recorded initial migration lost its unique index; refusing compatibility repair.');
                }
                $pendingLegacyIndex = true;
            } else {
                $definitions[$table]->assertEquivalent($actual, $version);
            }
        }
        if ($pendingLegacyIndex) {
            $owner->assertNoLegacyIndexDuplicates($connection);
        }

        $allCanonicalRecorded = count(array_intersect_key($records, $canonical)) === count($canonical);
        $mode = $establishedExtension ? 'extension' : (!$missing && ($legacyRecords || $allCanonicalRecorded) ? 'existing' : 'fresh');
        if ($mode === 'fresh') {
            static::assertEmpty($connection, array_keys($present));
        }
        $warnings = [];
        if (!static::supportsChecks($version)) {
            foreach ($definitions as $table => $definition) {
                if ($definition::DECLARED_CHECKS) {
                    $warnings[] = $table.': MySQL '.$version.' does not enforce CHECK constraints; declared checks remain documented, not enforced.';
                }
            }
        }

        return [
            'mode' => $mode,
            'pending_legacy_unique_index' => $pendingLegacyIndex,
            'database' => (string) ($identity['database_name'] ?? ''),
            'version' => $version,
            'tables' => array_keys($present),
            'missing' => array_keys($missing),
            'recorded' => array_keys($records),
            'warnings' => $warnings,
        ];
    }

    public function up(): void
    {
        $connection = $this->databaseConnection();
        // 离线编译绝不能解析 PDO，也不能把模拟 SELECT 的空结果当成真实空库。
        if ($connection->pretending()) {
            $version = (string) ($connection->getConfig('version') ?: static::baseline()['source_server_version']);
            $connection->statement($this->createSql($version));

            return;
        }

        $pdo = $connection->getPdo();
        static::assertSafeSession($pdo);
        self::$plans ??= new WeakMap;
        $manifestKey = hash('sha256', json_encode(static::baseline(), JSON_THROW_ON_ERROR));
        $cached = self::$plans[$connection] ?? null;
        if (!$cached || $cached['pdo'] !== $pdo || $cached['manifest'] !== $manifestKey) {
            $plan = static::preflight($connection);
            static::assertSameSession($connection, $pdo);
            self::$plans[$connection] = ['pdo' => $pdo, 'manifest' => $manifestKey, 'plan' => $plan];
            $this->reportWarnings($plan['warnings']);
        }
        $plan = self::$plans[$connection]['plan'];
        $identity = (array) $connection->selectOne('SELECT DATABASE() AS database_name', [], false);
        if (($identity['database_name'] ?? null) !== $plan['database']) {
            throw new RuntimeException('Database changed after initial migration preflight.');
        }
        $tables = static::tableNames($connection);
        if (isset($tables[static::TABLE])) {
            $this->assertEquivalent(static::showCreate($connection, static::TABLE), $plan['version']);
            static::assertSameSession($connection, $pdo);

            return;
        }
        if ($plan['mode'] !== 'fresh' || !in_array(static::TABLE, $plan['missing'], true)) {
            throw new RuntimeException('An existing baseline table disappeared; refusing to create '.$this::TABLE);
        }
        $allowed = array_keys(static::baseline()['tables']);
        if (array_diff(array_keys($tables), array_merge($allowed, ['migrations']))) {
            throw new RuntimeException('Database tables changed after initial migration preflight.');
        }
        static::assertEmpty($connection, array_values(array_intersect(array_keys($tables), $allowed)));
        static::assertSameSession($connection, $pdo);
        $setting = (array) $connection->selectOne('SELECT @@SESSION.FOREIGN_KEY_CHECKS AS foreign_key_checks', [], false);
        if (!isset($setting['foreign_key_checks']) || !in_array((string) $setting['foreign_key_checks'], ['0', '1'], true)) {
            throw new RuntimeException('Cannot determine the original FOREIGN_KEY_CHECKS session setting.');
        }
        $original = (int) $setting['foreign_key_checks'];
        static::assertSameSession($connection, $pdo);
        // PDO 固定会话且不使用 Laravel 自动重连重试：断线后绝不能在另一会话建表/恢复开关。
        // 外键循环只在已经确认空库的分支放行；恢复检查不会追溯扫描，所以不能用于业务库。
        try {
            static::execute($pdo, 'SET SESSION FOREIGN_KEY_CHECKS = 0');
            static::execute($pdo, $this->createSql($plan['version']));
        } finally {
            try {
                static::execute($pdo, 'SET SESSION FOREIGN_KEY_CHECKS = '.$original);
            } catch (Throwable $failure) {
                static::discardSession($connection, $pdo);
                throw new RuntimeException('Failed to restore FOREIGN_KEY_CHECKS; unsafe connection disconnected and cached plan discarded.', 0, $failure);
            }
        }
        $this->assertEquivalent(static::showCreate($connection, static::TABLE), $plan['version']);
        static::assertSameSession($connection, $pdo);
        $cached = self::$plans[$connection];
        $cached['plan']['missing'] = array_values(array_diff($cached['plan']['missing'], [static::TABLE]));
        $cached['plan']['tables'][] = static::TABLE;
        self::$plans[$connection] = $cached;
    }

    /**
     * 只 CREATE 当前新表，不 ALTER 原表、不回填、不写迁移记录、不关闭外键检查。
     * Laravel 在本方法成功后正常登记 owner；DDL 成功但登记中断时可精确重试。
     */
    final protected function createEstablishedExtension(): void
    {
        $connection = $this->databaseConnection();
        if ($connection->pretending()) {
            $this->upAsInitialTable();
            return;
        }
        $pdo = $connection->getPdo();
        static::assertSafeSession($pdo);
        $plan = $this->preflightEstablishedExtension($connection);
        static::assertSameSession($connection, $pdo);
        if ($plan['mode'] !== 'extension') {
            $this->upAsInitialTable();
            return;
        }
        if (!in_array(static::TABLE, $plan['missing'], true)) {
            // 上次已建成但尚未登记：只核对，不重复建表，也不要求旧业务表为空。
            return;
        }
        $setting = (array) $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS lock_wait_timeout', [], false);
        $original = $setting['lock_wait_timeout'] ?? null;
        if (!ctype_digit((string) $original) || (int) $original < 1 || (int) $original > 31536000) {
            throw new RuntimeException('Cannot determine the original lock_wait_timeout session setting.');
        }
        static::assertSameSession($connection, $pdo);
        $identity = (array) $connection->selectOne('SELECT DATABASE() AS database_name', [], false);
        if (($identity['database_name'] ?? null) !== $plan['database']) {
            throw new RuntimeException('Database changed after extension preflight.');
        }
        static::assertSameSession($connection, $pdo);
        try {
            static::execute($pdo, 'SET SESSION lock_wait_timeout = '.min(5, (int) $original));
            static::assertSameSession($connection, $pdo);
            static::execute($pdo, $this->createSql($plan['version']));
        } finally {
            try {
                static::execute($pdo, 'SET SESSION lock_wait_timeout = '.(int) $original);
            } catch (Throwable $failure) {
                static::discardSession($connection, $pdo);
                throw new RuntimeException('Failed to restore lock_wait_timeout; unsafe connection disconnected.', 0, $failure);
            }
        }
        static::assertSameSession($connection, $pdo);
        if (self::$plans !== null) unset(self::$plans[$connection]);
        // 新表落地后再核对整库；不能以成功执行 CREATE 冒充完整接纳。
        $after = $this->preflightEstablishedExtension($connection);
        if ($after['database'] !== $plan['database']) {
            throw new RuntimeException('Database changed after extension CREATE.');
        }
        static::assertSameSession($connection, $pdo);
    }

    private function upAsInitialTable(): void
    {
        // 调用本类初始建表实现，避免 owner 的 up() 再次进入扩展方法。
        self::up();
    }

    /**
     * 受限旧库补缺：固定 PDO、短 metadata-lock 等待、只 ADD owner 声明的唯一索引。
     * 并发插入重复仍由 MySQL 的原子 ADD UNIQUE 拒绝；绝不删数据或写 migration ledger。
     */
    final protected function completeLegacyUniqueIndex(): void
    {
        $connection = $this->databaseConnection();
        if ($connection->pretending()) return;
        $pdo = $connection->getPdo();
        static::assertSafeSession($pdo);
        $plan = $this->preflightLegacyUniqueIndex($connection);
        static::assertSameSession($connection, $pdo);
        if (!$plan['pending_legacy_unique_index']) return;

        $setting = (array) $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS lock_wait_timeout', [], false);
        $original = $setting['lock_wait_timeout'] ?? null;
        if (!ctype_digit((string) $original) || (int) $original < 1 || (int) $original > 31536000) {
            throw new RuntimeException('Cannot determine the original lock_wait_timeout session setting.');
        }
        static::assertSameSession($connection, $pdo);
        if (self::$plans !== null) unset(self::$plans[$connection]);
        try {
            static::execute($pdo, 'SET SESSION lock_wait_timeout = '.min(5, (int) $original));
            // 当前表在真正 DDL 前再次逐项核对；任何新差异都不能被这项授权覆盖。
            $this->assertOnlyLegacyUniqueIndexMissing(static::showCreate($connection, static::TABLE), $plan['version']);
            $this->assertNoLegacyIndexDuplicates($connection);
            static::assertSameSession($connection, $pdo);
            static::execute($pdo, 'ALTER TABLE '.static::quoteIdentifier(static::TABLE).' ADD '.$this->legacyUniqueIndexClause());
        } finally {
            try {
                static::execute($pdo, 'SET SESSION lock_wait_timeout = '.(int) $original);
            } catch (Throwable $failure) {
                static::discardSession($connection, $pdo);
                throw new RuntimeException('Failed to restore lock_wait_timeout; unsafe connection disconnected and cached plan discarded.', 0, $failure);
            }
        }
        // 成功返回前重新执行普通严格全库预检；不得把“ALTER 成功”当成全库已接纳。
        static::preflight($connection);
        static::assertSameSession($connection, $pdo);
    }

    private function legacyUniqueIndexClause(): string
    {
        $index = static::LEGACY_UNIQUE_INDEX;
        if (!isset($index['name'], $index['columns']) || !is_array($index['columns']) || !$index['columns']
            || count($index['columns']) !== count(array_unique($index['columns']))) {
            throw new RuntimeException('This initial migration has no authorized legacy unique index.');
        }
        foreach (array_merge([$index['name']], $index['columns']) as $identifier) {
            if (!is_string($identifier) || !preg_match('/^[a-zA-Z0-9_]+$/D', $identifier)) {
                throw new RuntimeException('Invalid legacy unique index identifier.');
            }
        }

        return 'UNIQUE KEY '.static::quoteIdentifier($index['name']).' ('
            .implode(',', array_map(static fn ($column) => static::quoteIdentifier($column), $index['columns'])).')';
    }

    private function assertOnlyLegacyUniqueIndexMissing(string $actual, string $version): void
    {
        $expected = static::fingerprint($this->createSql($version));
        $clause = implode(' ', static::tokens($this->legacyUniqueIndexClause()));
        $matches = array_keys($expected['constraints'], $clause, true);
        if (count($matches) !== 1) {
            throw new RuntimeException('Authorized unique index must occur exactly once in the canonical CREATE definition.');
        }
        unset($expected['constraints'][$matches[0]]);
        $expected['constraints'] = array_values($expected['constraints']);
        if ($expected !== static::fingerprint($actual)) {
            throw new RuntimeException('Schema drift exceeds the authorized missing unique index in '.static::TABLE);
        }
    }

    private function assertNoLegacyIndexDuplicates(Connection $connection): void
    {
        $columns = array_map(static fn ($column) => static::quoteIdentifier($column), static::LEGACY_UNIQUE_INDEX['columns']);
        $query = 'SELECT 1 FROM '.static::quoteIdentifier(static::TABLE).' WHERE '
            .implode(' AND ', array_map(static fn ($column) => $column.' IS NOT NULL', $columns))
            .' GROUP BY '.implode(',', $columns).' HAVING COUNT(*) > 1 LIMIT 1';
        if ($connection->select($query, [], false)) {
            throw new RuntimeException('Duplicate non-null combinations prevent the authorized unique index in '.static::TABLE);
        }
    }

    private static function assertSafeSession(PDO $pdo): void
    {
        if (self::$unsafePdos !== null && isset(self::$unsafePdos[$pdo])) {
            throw new RuntimeException('An unsafe database session must not be reused after restoration failure.');
        }
    }

    private static function discardSession(Connection $connection, PDO $pdo): void
    {
        if (self::$plans !== null) unset(self::$plans[$connection]);
        self::$unsafePdos ??= new WeakMap;
        self::$unsafePdos[$pdo] = true;
        // disconnect 同时释放读/写 PDO。保留隔离标记，防错误 reconnect closure 再返回原会话。
        $connection->disconnect();
    }

    public function down(): void
    {
        throw new RuntimeException('Automatic rollback of the initial baseline is forbidden; preserve tables, business data and legacy migration history.');
    }

    protected function reportWarnings(array $warnings): void
    {
        foreach ($warnings as $warning) {
            fwrite(STDERR, 'WARNING: '.$warning.PHP_EOL);
        }
    }

    private static function execute(PDO $pdo, string $sql): void
    {
        if ($pdo->exec($sql) === false) {
            throw new RuntimeException('Initial schema statement failed; migration must not be recorded.');
        }
    }

    private static function assertSameSession(Connection $connection, PDO $pdo): void
    {
        static::assertSafeSession($pdo);
        if ($connection->getPdo() !== $pdo) {
            throw new RuntimeException('Database session changed during initial migration preflight.');
        }
    }

    private static function tableNames(Connection $connection): array
    {
        $tables = [];
        foreach ($connection->select('SELECT TABLE_NAME, TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()', [], false) as $row) {
            $row = (array) $row;
            $tables[$row['TABLE_NAME']] = $row['TABLE_TYPE'];
        }

        return $tables;
    }

    private static function showCreate(Connection $connection, string $table): string
    {
        $row = (array) $connection->selectOne('SHOW CREATE TABLE '.static::quoteIdentifier($table), [], false);
        if (!isset($row['Create Table'])) {
            throw new RuntimeException('Cannot read table definition: '.$table);
        }

        return $row['Create Table'];
    }

    private static function assertEmpty(Connection $connection, array $tables): void
    {
        foreach ($tables as $table) {
            if ($connection->select('SELECT 1 FROM '.static::quoteIdentifier($table).' LIMIT 1', [], false)) {
                throw new RuntimeException('Fresh/recovery baseline requires empty tables; data found in '.$table);
            }
        }
    }

    private static function quoteIdentifier(string $name): string
    {
        return chr(96).str_replace(chr(96), chr(96).chr(96), $name).chr(96);
    }

    private static function checkVersion(string $version): void
    {
        if (!preg_match('/^(\d+\.\d+\.\d+)/', $version, $match)
            || str_contains(strtolower($version), 'mariadb')
            || version_compare($match[1], '8.0.12', '<')) {
            throw new RuntimeException('Unsupported MySQL server version: '.$version);
        }
    }

    private static function supportsChecks(string $version): bool
    {
        static::checkVersion($version);
        preg_match('/^(\d+\.\d+\.\d+)/', $version, $match);

        return version_compare($match[1], '8.0.16', '>=');
    }

    /** 完整 CREATE SQL；高版本只在新库建表时合入同文件中声明的 CHECK。 */
    public function createSql(string $version): string
    {
        static::checkVersion($version);
        $sql = static::CREATE_SQL;
        if (!static::supportsChecks($version) || !static::DECLARED_CHECKS) {
            return $sql;
        }
        [, $clauses, $tail, $closingOffset] = static::parts($sql);
        $checks = static::checks($clauses);
        $charset = static::tableCharset($tail);
        $additions = [];
        foreach (static::DECLARED_CHECKS as $name => $expression) {
            if (isset($checks[$name])) {
                if (static::expression($checks[$name], $charset) !== static::expression(static::tokens($expression), $charset)) {
                    throw new RuntimeException('Conflicting inline and declared CHECK: '.$name);
                }
                continue;
            }
            $additions[] = 'CONSTRAINT '.static::quoteIdentifier($name).' CHECK ('.$expression.')';
        }

        // 保留原 SQL 字面量及字符集前缀的紧邻关系，只在表体末尾插入约束。
        return $additions ? substr($sql, 0, $closingOffset).', '.implode(', ', $additions).substr($sql, $closingOffset) : $sql;
    }

    public function assertEquivalent(string $actualSql, string $version): void
    {
        $differences = $this->schemaDifferences($actualSql, $version);
        if ($differences) {
            throw new RuntimeException('Schema drift in '.static::TABLE.': '.implode('; ', $differences));
        }
    }

    /** 只读纯比较；既有库缺高版本 CHECK 也必须拒绝，不偷偷 ALTER。 */
    public function schemaDifferences(string $actualSql, string $version): array
    {
        $expected = static::fingerprint($this->createSql($version));
        $actual = static::fingerprint($actualSql);
        $differences = [];
        foreach (['header', 'columns', 'constraints', 'options', 'checks'] as $part) {
            if ($expected[$part] !== $actual[$part]) {
                $differences[] = $part.' differ';
            }
        }

        return $differences;
    }

    /**
     * 只忽略表计数器、非 ZEROFILL 整数显示宽度和词间空白。
     * 字符串/注释/标识符逐字保留，不能用全局正则误改默认值或 COMMENT。
     */
    public static function normalizeSql(string $sql): string
    {
        [$head, $clauses, $tail] = static::parts($sql);
        foreach ($clauses as &$clause) {
            if (str_starts_with($clause[0] ?? '', chr(96))
                && in_array($clause[1] ?? '', ['tinyint', 'smallint', 'mediumint', 'int', 'integer', 'bigint'], true)
                && ($clause[2] ?? '') === '(' && ctype_digit($clause[3] ?? '')
                && ($clause[4] ?? '') === ')' && !in_array('zerofill', $clause, true)) {
                array_splice($clause, 2, 3);
            }
        }
        unset($clause);
        for ($i = 0; $i < count($tail); $i++) {
            if ($tail[$i] === 'auto_increment' && ($tail[$i + 1] ?? '') === '=' && ctype_digit($tail[$i + 2] ?? '')) {
                array_splice($tail, $i, 3);
                $i--;
            }
        }

        return implode(' ', $head).' ( '.implode(' , ', array_map(static fn ($clause) => implode(' ', $clause), $clauses)).' ) '.implode(' ', $tail);
    }

    private static function fingerprint(string $sql): array
    {
        [$head, $clauses, $tail] = static::parts(static::normalizeSql($sql));
        $result = ['header' => $head, 'columns' => [], 'constraints' => [], 'options' => $tail, 'checks' => []];
        $checks = static::checks($clauses);
        foreach ($checks as $name => $expression) {
            $result['checks'][$name] = static::expression($expression, static::tableCharset($tail));
        }
        ksort($result['checks']);
        foreach ($clauses as $clause) {
            if (str_starts_with($clause[0], chr(96))) {
                $result['columns'][] = $clause;
            } elseif (!(($clause[0] ?? '') === 'constraint' && ($clause[2] ?? '') === 'check')) {
                $result['constraints'][] = implode(' ', $clause);
            }
        }
        sort($result['constraints']);

        return $result;
    }

    /** @return array<string, array> */
    private static function checks(array $clauses): array
    {
        $checks = [];
        foreach ($clauses as $clause) {
            if (($clause[0] ?? '') === 'constraint' && ($clause[2] ?? '') === 'check') {
                $name = trim($clause[1], chr(96));
                if (isset($checks[$name])) {
                    throw new RuntimeException('Duplicate CHECK name: '.$name);
                }
                // NOT ENFORCED remains part of the expression and therefore cannot pass equivalence.
                $checks[$name] = array_slice($clause, 3);
            }
        }

        return $checks;
    }

    private static function parts(string $sql): array
    {
        $positions = [];
        $tokens = static::tokens($sql, $positions);
        $open = array_search('(', $tokens, true);
        if ($open === false || array_slice($tokens, 0, 2) !== ['create', 'table']) {
            throw new RuntimeException('Expected a complete CREATE TABLE definition.');
        }
        $clauses = [];
        $clause = [];
        $depth = 1;
        for ($i = $open + 1; $i < count($tokens); $i++) {
            $token = $tokens[$i];
            if ($token === '(') $depth++;
            if ($token === ')') $depth--;
            if ($depth === 0) {
                $clauses[] = $clause;
                $tail = array_slice($tokens, $i + 1);
                if (end($tail) === ';') array_pop($tail);

                return [array_slice($tokens, 0, $open), $clauses, $tail, $positions[$i]];
            }
            if ($token === ',' && $depth === 1) {
                $clauses[] = $clause;
                $clause = [];
            } else {
                $clause[] = $token;
            }
        }

        throw new RuntimeException('Unbalanced CREATE TABLE definition.');
    }

    private static function tokens(string $sql, ?array &$positions = null): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            if (ctype_space($sql[$i])) { $i++; continue; }
            $start = $i;
            $positions[] = $start;
            $char = $sql[$i];
            if ($char === "'" || $char === '"' || $char === chr(96)) {
                $i++;
                $closed = false;
                while ($i < $length) {
                    if ($sql[$i] === '\\' && $char !== chr(96)) { $i += 2; continue; }
                    if ($sql[$i] === $char) {
                        if (($sql[$i + 1] ?? '') === $char) { $i += 2; continue; }
                        $i++;
                        $closed = true;
                        break;
                    }
                    $i++;
                }
                if (!$closed) throw new RuntimeException('Unterminated SQL quoted token.');
                $tokens[] = substr($sql, $start, $i - $start);
            } elseif (preg_match('/\G(?:[a-zA-Z_][a-zA-Z_0-9$]*|[0-9]+(?:\.[0-9]+)?|<=>|>=|<=|<>|!=|&&|\|\|)/A', $sql, $match, 0, $i)) {
                $tokens[] = strtolower($match[0]);
                $i += strlen($match[0]);
            } else {
                $tokens[] = $char;
                $i++;
            }
        }

        return $tokens;
    }

    /**
     * MySQL SHOW CREATE 为布尔操作数添加括号/反引号。按运算优先级比较，
     * 只处理声明所用的 AND/OR/比较/IN；未知形式保守保留原 token。
     */
    private static function tableCharset(array $tail): ?string
    {
        foreach ($tail as $i => $token) {
            if ($token === 'charset' && ($tail[$i + 1] ?? '') === '=') {
                return $tail[$i + 2] ?? null;
            }
        }

        return null;
    }

    private static function expression(array $tokens, ?string $charset = null): array
    {
        // SHOW CREATE 在 CHECK 字符串前补表默认字符集前缀；只接受与该表完全相同的前缀。
        if ($charset !== null) {
            foreach ($tokens as $i => $token) {
                if ($token === '_'.$charset && str_starts_with($tokens[$i + 1] ?? '', "'")) {
                    unset($tokens[$i]);
                }
            }
            $tokens = array_values($tokens);
        }
        while (($tokens[0] ?? '') === '(' && end($tokens) === ')') {
            $depth = 0;
            foreach ($tokens as $i => $token) {
                if ($token === '(') $depth++;
                if ($token === ')') $depth--;
                if ($depth === 0 && $i !== count($tokens) - 1) break;
            }
            if ($i !== count($tokens) - 1) break;
            $tokens = array_slice($tokens, 1, -1);
        }
        foreach ([['or'], ['and'], ['is', 'in', '=', '<>', '!=', '<', '>', '<=', '>=']] as $operators) {
            $depth = 0;
            foreach ($tokens as $i => $token) {
                if ($token === '(') $depth++;
                if ($token === ')') $depth--;
                if ($depth === 0 && in_array($token, $operators, true)) {
                    return [$token, static::expression(array_slice($tokens, 0, $i), $charset), static::expression(array_slice($tokens, $i + 1), $charset)];
                }
            }
        }

        return array_map(static function ($token) {
            // 区分标识符和 NULL 等关键字；不能把名为 `null` 的列当成 NULL 常量。
            if (str_starts_with($token, chr(96))) {
                return ['identifier', strtolower(str_replace(chr(96).chr(96), chr(96), substr($token, 1, -1)))];
            }
            if (preg_match('/^[a-z_][a-z_0-9$]*$/iD', $token)
                && !in_array($token, ['null', 'not', 'true', 'false', 'is', 'in', 'and', 'or'], true)) {
                return ['identifier', $token];
            }

            return $token;
        }, $tokens);
    }
}
