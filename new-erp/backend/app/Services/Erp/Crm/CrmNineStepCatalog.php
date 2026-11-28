<?php

namespace App\Services\Erp\Crm;

use Illuminate\Support\Facades\DB;

final class CrmNineStepCatalog
{
    public const LEAD = ['第1步｜营销自己', '第2步｜发现需求', '第3步｜确认需求'];

    public const OPPORTUNITY = ['step_4_solution' => '第4步｜产品方案', 'step_5_value' => '第5步｜营销产品', 'step_6_consensus' => '第6步｜达成共识', 'step_7_commitment' => '第7步｜要求承诺', 'step_8_closing' => '第8步｜确认成交'];

    public const CUSTOMER = '第9步｜赢销企业';

    public const LEGACY = ['requirement' => 'step_4_solution', 'solution' => 'step_5_value', 'product' => 'step_6_consensus', 'price' => 'step_7_commitment', 'contract' => 'step_8_closing'];

    public const PROBABILITIES = ['10%｜刚建立联系', '30%｜需求初步明确', '50%｜方案匹配中', '70%｜主要异议已识别', '80%｜明确意向', '85%｜已要求承诺', '95%｜待付款/签约', '100%｜已成交'];

    public static function normalizeStage(string $type, ?string $stage): string
    {
        $stage = trim((string) $stage);
        if ($type === 'lead') {
            $map = ['step_1' => self::LEAD[0], 'step_2' => self::LEAD[1], 'step_3' => self::LEAD[2], '0' => self::LEAD[0], '1' => self::LEAD[2]];

            return $map[$stage] ?? $stage;
        }
        if ($type === 'opportunity') {
            $numeric = ['2' => 'step_4_solution', '3' => 'step_5_value', '4' => 'step_6_consensus', '5' => 'step_7_commitment', '6' => 'step_8_closing'];

            return self::LEGACY[$stage] ?? $numeric[$stage] ?? array_search($stage, self::OPPORTUNITY, true) ?: $stage;
        }

        return $stage === 'step_9_advocacy' ? self::CUSTOMER : $stage;
    }

    public static function probability(string $stage): string
    {
        $stage = self::LEGACY[$stage] ?? $stage;

        return match ($stage) {
            self::LEAD[0] => self::PROBABILITIES[0], self::LEAD[1] => self::PROBABILITIES[1],
            self::LEAD[2], 'step_4_solution' => self::PROBABILITIES[2], 'step_5_value' => self::PROBABILITIES[3],
            'step_6_consensus' => self::PROBABILITIES[4], 'step_7_commitment' => self::PROBABILITIES[5],
            'step_8_closing' => self::PROBABILITIES[6], self::CUSTOMER => self::PROBABILITIES[7], default => self::PROBABILITIES[0],
        };
    }

    public static function stages(string $type): array
    {
        $map = match ($type) {
            'lead' => array_combine(self::LEAD, self::LEAD), 'opportunity' => self::OPPORTUNITY, 'customer' => [self::CUSTOMER => self::CUSTOMER], default => []
        };
        $rows = [];
        foreach ($map as $value => $label) {
            $rows[] = compact('value', 'label') + ['probability' => self::probability($value)];
        }

        return $rows;
    }

    public static function actions(): array
    {
        return ['微信', '电话', '视频沟通', '上门/到店', '报价', '出方案', '发案例/证明', '样品/试用', '邀约参观', '售后回访', '内部协同', '其他'];
    }

    public static function blockers(): array
    {
        return ['无明显卡点', '客户未回复', '需求不清', '关键人未接触', '预算/价格', '产品/方案匹配', '安装/技术条件', '交期/库存', '竞品对比', '不愿交定金', '其他'];
    }

    public static function metadata(string $scope): array
    {
        $pools = app(CrmCustomerPoolService::class)->options($scope === 'all' ? null : $scope);

        return ['pools' => array_column($pools, 'pool_name'), 'customer_pools' => $pools, 'nine_step_stages' => self::stages($scope),
            'stage_catalog' => ['lead' => self::stages('lead'), 'opportunity' => self::stages('opportunity'), 'customer' => self::stages('customer')],
            'all_customer_pools' => app(CrmCustomerPoolService::class)->options(),
            'pool_catalog' => ['lead' => app(CrmCustomerPoolService::class)->options('lead'), 'opportunity' => app(CrmCustomerPoolService::class)->options('opportunity'), 'customer' => app(CrmCustomerPoolService::class)->options('customer')],
            'followup_actions' => self::actions(), 'blockers' => self::blockers(), 'probabilities' => self::PROBABILITIES];
    }

    public static function tradeType(?int $department): string
    {
        $names = $visited = [];
        // 班组通常不重复写贸易部门名，沿真实父级识别；组织数据意外成环时仍须终止查询。
        while ($department && !isset($visited[$department])) {
            $visited[$department] = true;
            $row = DB::table('erp_departments')->where('legacy_id', $department)->first(['name', 'parent_legacy_id']);
            if (!$row) break;
            $names[] = $row->name;
            $department = (int) $row->parent_legacy_id;
        }
        $path = str_replace('Ｂ', 'B', implode('/', $names));

        return str_contains($path, '外贸') ? '外贸' : (preg_match('/B\s*端|内贸业务/u', $path) ? '内贸业务' : '内贸销售');
    }
}
