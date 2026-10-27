<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ErpApprovedSalesReferenceSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/erp_approved_sales_references.json'), true, 512, JSON_THROW_ON_ERROR);
        $sources = $data['sources'];
        $platforms = $this->rows($sources['jt_base_trade_platform']);
        $deliveries = $this->rows($sources['jt_base_delivery']);
        $payments = $this->rows($sources['jt_base_pay_type']);
        foreach ($platforms as $row) {
            if ($row['pid'] !== 0 && (!isset($platforms[$row['pid']]) || $platforms[$row['pid']]['pid'] !== 0)) {
                throw new RuntimeException('成交平台上级缺失或层级无效，导入已取消。');
            }
            if ($row['first_pay'] !== null && !isset($payments[$row['first_pay']])) {
                throw new RuntimeException('成交平台默认支付方式缺失，导入已取消。');
            }
        }

        $this->replaceUnusedDefaultPaymentMethods();
        foreach ([
            ['jt_base_trade_platform', 'erp_sales_order_trade_platforms', $platforms],
            ['jt_base_delivery', 'erp_sales_order_deliveries', $deliveries],
            ['jt_base_pay_type', 'erp_sales_order_pay_types', $payments],
        ] as [$source, $table, $rows]) {
            foreach ($rows as $row) {
                $this->insertReference($source, $table, $row, $sources[$source]['sha256']);
            }
        }
        foreach ($payments as $row) {
            $code = 'JT_PAY_TYPE_'.$row['id'];
            foreach (DB::table('erp_payment_methods')->where('id', $row['id'])->orWhere('method_code', $code)->lockForUpdate()->get() as $existing) {
                if ((int) $existing->id !== $row['id'] || $existing->method_code !== $code) {
                    throw new RuntimeException('付款方式ID/编码冲突：'.$row['id'].' / '.$code);
                }
            }
            SeedSafety::insertMissing('erp_payment_methods', ['id' => $row['id']], [
                'method_code' => $code, 'method_name' => $row['name'],
                'available_for_sales' => true, 'available_for_receipt' => true, 'available_for_payment' => true,
                'status' => 'enabled', 'sort' => $row['sort'],
            ]);
        }
    }

    private function rows(array $source): array
    {
        if (count($source['rows']) !== $source['source_row_count'] - count($source['excluded_legacy_ids'])) {
            throw new RuntimeException('指定销售基础数据数量不完整，导入已取消。');
        }
        $rows = [];
        foreach ($source['rows'] as $row) {
            if (!is_int($row['id']) || $row['id'] <= 0 || isset($rows[$row['id']])
                || !is_string($row['name']) || trim($row['name']) === ''
                || !in_array($row['trade'], ['domestic', 'foreign'], true)
                || !is_int($row['sort']) || $row['sort'] < 0 || $row['switch'] !== 1 || $row['deletetime'] !== null) {
                throw new RuntimeException('指定销售基础数据无效，导入已取消。');
            }
            $rows[$row['id']] = $row;
        }
        return $rows;
    }

    private function insertReference(string $source, string $table, array $row, string $sha256): void
    {
        $matches = DB::table($table)->where('id', $row['id'])->orWhere('legacy_id', $row['id'])->lockForUpdate()->get();
        foreach ($matches as $existing) {
            $payload = json_decode($existing->legacy_payload ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            // 来源身份固定，页面维护后的名称、状态、排序和时间一律保留。
            if ((int) $existing->id !== $row['id'] || (int) $existing->legacy_id !== $row['id']
                || ($payload['source_table'] ?? null) !== $source || ($payload['id'] ?? null) !== $row['id']
                || ($payload['source_sha256'] ?? null) !== $sha256) {
                throw new RuntimeException($table.' 原始ID或来源身份冲突：'.$row['id']);
            }
        }
        if ($matches->isNotEmpty()) return;

        $values = [
            'legacy_id' => $row['id'], 'name' => $row['name'], 'trade_type' => $row['trade'],
            'sort' => $row['sort'], 'enabled' => true,
            'legacy_payload' => json_encode($row + ['source_table' => $source, 'source_sha256' => $sha256], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        ];
        if ($source === 'jt_base_trade_platform') {
            $values += ['parent_legacy_id' => $row['pid'], 'short_name' => $row['shortname']];
        } elseif ($source === 'jt_base_delivery') {
            $values['code'] = $row['code'];
        }
        SeedSafety::insertMissing($table, ['id' => $row['id']], $values);
    }

    private function replaceUnusedDefaultPaymentMethods(): void
    {
        $rows = DB::table('erp_payment_methods')->orderBy('id')->lockForUpdate()->get();
        $defaults = [
            [1, 'BANK_TRANSFER', '银行转账', 10], [2, 'CASH', '现金', 20],
            [3, 'ALIPAY', '支付宝', 30], [4, 'WECHAT_PAY', '微信支付', 40],
            [5, 'PLATFORM', '平台收付', 50], [6, 'OTHER', '其他', 90],
        ];
        $defaultCodes = array_column($defaults, 1, 0);
        if (!$rows->contains(fn ($row) => (int) $row->id <= 4 && ($defaultCodes[(int) $row->id] ?? null) === $row->method_code)) return;

        // 用户指定原ID替代系统默认项；仅转换完整、未维护且无业务引用的旧默认集。
        if ($rows->count() !== count($defaults)) throw new RuntimeException('付款方式不是原始完整默认集，不能自动替换。');
        foreach ($defaults as $index => [$id, $code, $name, $sort]) {
            $row = $rows[$index];
            if ((int) $row->id !== $id || $row->method_code !== $code || $row->method_name !== $name
                || (int) $row->available_for_sales !== 1 || (int) $row->available_for_receipt !== 1 || (int) $row->available_for_payment !== 1
                || $row->status !== 'enabled' || (int) $row->sort !== $sort || $row->remark !== null
                || $row->created_by !== null || $row->updated_by !== null || (int) $row->business_version !== 1
                || $row->created_at === null || $row->created_at !== $row->updated_at) {
                throw new RuntimeException('系统默认付款方式已被维护，不能自动替换：'.$id);
            }
        }
        foreach (['erp_sales_orders', 'erp_finance_cash_documents'] as $table) {
            // 同时保护旧文本编码和JSON快照，不能仅依赖可空外键判断未被使用。
            if (DB::table($table)->lockForUpdate()->exists()) throw new RuntimeException('已有销售或收付款单据，不能自动替换默认付款方式。');
        }
        DB::table('erp_payment_methods')->whereIn('id', array_column($defaults, 0))->delete();
    }
}
