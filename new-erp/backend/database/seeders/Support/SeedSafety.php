<?php

namespace Database\Seeders\Support;

use Database\Schema\InitialTableMigration;
use Illuminate\Support\Facades\DB;
use RuntimeException;

require_once __DIR__.'/../../schema/InitialTableMigration.php';

/** 安装补缺与配置升级分开：结构先严格核对，任何非重复错误必须向上传播。 */
final class SeedSafety
{
    public static function assertSchema(): void
    {
        self::assertPlan(InitialTableMigration::preflight(DB::connection()));
    }

    public static function assertPlan(array $plan): void
    {
        if (($plan['mode'] ?? null) !== 'existing' || ($plan['missing'] ?? null) !== []) {
            throw new RuntimeException('基础数据补缺要求已完整迁移且通过严格结构预检；请先完成正式迁移。');
        }
    }

    public static function insertMissing(string $table, array $key, array $values): bool
    {
        if (DB::table($table)->where($key)->lockForUpdate()->exists()) {
            return false;
        }
        // 不使用 INSERT IGNORE：外键、非空、长度及并发唯一冲突都应报错并回滚，不能伪装成功。
        DB::table($table)->insert($key + $values + ['created_at' => now(), 'updated_at' => now()]);
        return true;
    }

    /** 快照沿用原主键以保留跨表引用。主键或业务身份已被其他记录占用时，由外层事务整体回滚。 */
    public static function restoreRows(string $table, array $rows, array $identity = ['id'], ?string $primary = 'id'): void
    {
        if (!$identity) throw new RuntimeException($table.' 缺少基础资料身份字段。');
        $identityFields = array_flip($identity);
        $columns = array_values(array_unique($primary === null ? $identity : array_merge([$primary], $identity)));
        foreach ($rows as $row) {
            $key = array_intersect_key($row, $identityFields);
            if (count($key) !== count($identity) || ($primary !== null && !isset($row[$primary]))) {
                throw new RuntimeException($table.' 基础资料快照缺少身份字段。');
            }
            $query = DB::table($table)->where($key);
            if ($primary !== null && $identity !== [$primary]) $query->orWhere($primary, $row[$primary]);
            $existing = $query->lockForUpdate()->get($columns);
            if ($existing->isEmpty()) {
                DB::table($table)->insert($row);
                continue;
            }
            foreach ($existing as $record) {
                foreach ($columns as $column) {
                    if (($record->$column === null) !== ($row[$column] === null) || (string) $record->$column !== (string) $row[$column]) {
                        throw new RuntimeException($table.' 基础资料身份冲突：'.$column.'。');
                    }
                }
            }
        }
    }

    /** 只补足已保留实体使用的编号下限；后续人工调整规则或更高计数都保持。 */
    public static function restoreCounterMinimum(array $baseline): void
    {
        $type = $baseline['document_type'];
        $minimum = (int) $baseline['minimum_sequence'];
        if ($minimum < 0) throw new RuntimeException('基础资料编号下限无效：'.$type);
        $rule = DB::table('erp_document_number_rules')->where('document_type', $type)->lockForUpdate()->first();
        if (!$rule) throw new RuntimeException('基础资料缺少编号规则：'.$type.'，请先补齐编号规则。');
        if ($rule->prefix !== $baseline['prefix'] || $rule->date_format !== $baseline['date_format'] || $rule->reset_cycle !== $baseline['reset_cycle']) return;

        $key = ['document_type' => $type, 'number_date' => $baseline['number_date']];
        $counter = DB::table('erp_document_numbers')->where($key)->lockForUpdate()->first();
        if (!$counter) {
            DB::table('erp_document_numbers')->insert($key + ['current_sequence' => $minimum, 'created_at' => now(), 'updated_at' => now()]);
        } elseif ((int) $counter->current_sequence < $minimum) {
            DB::table('erp_document_numbers')->where('id', $counter->id)->update(['current_sequence' => $minimum, 'updated_at' => now()]);
        }
    }
}
