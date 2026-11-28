<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\SalesCustomer;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

final class CrmProgressApplicationService
{
    public static function rules(): array
    {
        return [
            'version' => 'nullable|integer|min:1', 'business_version' => 'nullable|integer|min:1',
            'crm_version' => 'nullable|integer|min:1', 'customer_pool' => 'nullable|string|max:80',
            'nine_step_stage' => 'nullable|string|max:80', 'stage' => 'nullable|string|max:80',
            'lifecycle_stage' => 'nullable|string|max:80', 'win_probability' => 'nullable|string|max:80',
            'current_blocker' => 'nullable|string|max:160', 'customer_promise' => 'nullable|string|max:5000',
            'expected_close_date' => 'nullable|date_format:Y-m-d', 'expected_pay_date' => 'nullable|date_format:Y-m-d',
            'reason' => 'nullable|string|max:500',
        ];
    }

    public function update(string $type, int $id, array $data, object $actor): Model
    {
        return DB::transaction(function () use ($type, $id, $data, $actor) {
            if ($type === 'lead') {
                $application = app(CrmLeadApplicationService::class);
                $target = $application->visible($id, $actor, true);
                $abilities = $application->abilities($target, $actor, true);
                abort_unless($abilities['pool'] || $abilities['edit'] || $abilities['follow'], 403, '无权调整该线索进度。');
            } elseif ($type === 'opportunity') {
                $application = app(CrmOpportunityApplicationService::class);
                $target = $application->visible($id, $actor, true);
                abort_unless($application->abilities($target, $actor, true)['stage'], 403, '无权调整该商机进度。');
            } else {
                abort_unless($type === 'customer', 422, '无效的跟进对象。');
                $target = SalesCustomer::query()->lockForUpdate()->findOrFail($id);
                app(CrmCustomerAccessService::class)->assert($target, $actor, 'edit', '无权调整该客户进度。');
            }
            $field = $type === 'customer' ? 'crm_version' : 'version';
            abort_unless((int) ($data[$field] ?? $data['business_version'] ?? 0) === (int) $target->$field,
                409, '进度已被其他人修改，请刷新后重试。');
            $this->applyLocked($type, $target, $data, $actor);

            return $target->fresh();
        }, 3);
    }

    /** 调用方在同一事务锁定目标；跟进与日报仍按实际变更字段复核权限，不能借跟进权限调整商机阶段。 */
    public function applyLocked(string $type, Model $target, array $data, object $actor): array
    {
        abort_unless(DB::transactionLevel() > 0, 500, '进度更新必须在业务事务内执行。');
        $before = $target->attributesToArray();
        if ($type === 'lead') {
            $onlyPool = ! array_diff(array_keys($data), ['customer_pool', 'version', 'business_version', 'reason']);
            abort_unless($target->status === 'active' || ($target->status === 'converted' && $onlyPool),
                422, '当前线索状态不能调整跟进进度。');
        }
        if ($type === 'opportunity') {
            abort_unless(in_array($target->status, ['open', 'won'], true), 422, '当前商机状态不能调整进度。');
        }
        if ($type === 'customer') {
            abort_if(in_array($target->status, ['disabled', 'blacklisted'], true), 422, '客户已停用或列入黑名单。');
        }
        if (array_key_exists('customer_pool', $data)) {
            $target->customer_pool = app(CrmCustomerPoolService::class)->assertSelectable($data['customer_pool'], $type, $target->customer_pool);
        }
        $field = match ($type) {
            'lead' => 'nine_step_stage', 'opportunity' => 'stage', default => 'lifecycle_stage'
        };
        $stage = $data[$field] ?? $data['nine_step_stage'] ?? $data['stage'] ?? null;
        if ($stage !== null && $stage !== '') {
            $stage = CrmNineStepCatalog::normalizeStage($type, $stage);
            abort_unless(in_array($stage, array_column(CrmNineStepCatalog::stages($type), 'value'), true), 422, '九步阶段不适用于当前对象。');
            if ($type === 'opportunity' && $target->status === 'won') {
                abort_unless($stage === 'step_8_closing', 422, '已成交商机须保留确认成交阶段。');
            }
            if ($stage !== CrmNineStepCatalog::normalizeStage($type, $target->$field)) {
                $target->$field = $stage;
                if ($type === 'opportunity') {
                    $target->stage_entered_at = now();
                }
                if (! array_key_exists('win_probability', $data)) {
                    $target->win_probability = CrmNineStepCatalog::probability($stage);
                }
            }
        }
        foreach (['win_probability', 'current_blocker', 'customer_promise', 'latest_followup_summary'] as $field) {
            if (array_key_exists($field, $data)) {
                $target->$field = $data[$field];
            }
        }
        if ($target->win_probability !== null) {
            abort_unless(preg_match('/^(100|[1-9]?[0-9])%([｜|].*)?$/u', $target->win_probability), 422, '成交概率须为0至100%的概率。');
        }
        if ($type === 'opportunity' && $target->status === 'won') {
            $target->win_probability = '100%｜已成交';
        }
        if ($type === 'opportunity' && array_key_exists('expected_close_date', $data)) {
            $target->expected_close_date = $data['expected_close_date'];
        }
        $this->authorizeChanges($type, $target, $actor);
        if (! $target->isDirty()) {
            return ['before' => $before, 'after' => $before];
        }
        $version = $type === 'customer' ? 'crm_version' : 'version';
        $target->$version++;
        $target->save();
        $after = $target->attributesToArray();
        $reason = trim((string) ($data['reason'] ?? '')) ?: '更新客户池与九步进度';
        match ($type) {
            'lead' => app(CrmLeadApplicationService::class)->log($target, 'progress', '更新线索跟进进度', $before, $after, $reason, $actor),
            'opportunity' => app(CrmOpportunityApplicationService::class)->log($target, 'progress', '更新商机跟进进度', $before, $after, $reason, $actor),
            default => app(CrmCustomerLogService::class)->write($target->id, 'progress', '更新客户维系进度', $before, $after, $reason, $actor),
        };

        return ['before' => $before, 'after' => $after];
    }

    private function authorizeChanges(string $type, Model $target, object $actor): void
    {
        $changed = array_keys($target->getDirty());
        if (! $changed) {
            return;
        }
        if ($type === 'lead') {
            $abilities = app(CrmLeadApplicationService::class)->abilities($target, $actor, true);
            if (in_array('customer_pool', $changed, true)) {
                abort_unless($abilities['pool'], 403, '无权调整该线索客户池。');
            }
            if (array_intersect($changed, ['nine_step_stage', 'win_probability', 'current_blocker', 'customer_promise'])) {
                abort_unless($abilities['edit'] || $abilities['follow'], 403, '无权调整该线索跟进进度。');
            }
        } elseif ($type === 'opportunity') {
            $abilities = app(CrmOpportunityApplicationService::class)->abilities($target, $actor, true);
            // 成交概率由正式生效事实固定，普通跟进兼容历史空值不能被视作人工调阶段。
            $stageFields = $target->status === 'won' ? ['stage', 'customer_pool', 'expected_close_date'] : ['stage', 'win_probability', 'customer_pool', 'expected_close_date'];
            if (array_intersect($changed, $stageFields)) {
                abort_unless($abilities['stage'], 403, '无权调整该商机客户池或九步阶段。');
            }
            if (array_intersect($changed, ['current_blocker', 'customer_promise'])) {
                abort_unless($abilities['follow'] || $abilities['edit'], 403, '无权调整该商机跟进进度。');
            }
        } else {
            $abilities = app(CrmCustomerAccessService::class)->abilities($target, $actor);
            if (array_intersect($changed, ['customer_pool', 'lifecycle_stage', 'win_probability', 'current_blocker', 'customer_promise'])) {
                abort_unless($abilities['edit'], 403, '无权调整该客户维系进度。');
            }
        }
    }

    public function customerWon(int $customerId, ?object $actor): void
    {
        $customer = SalesCustomer::query()->lockForUpdate()->findOrFail($customerId);
        if ($customer->lifecycle_stage === CrmNineStepCatalog::CUSTOMER && $customer->customer_pool) {
            return;
        }
        $before = $customer->attributesToArray();
        $customer->lifecycle_stage = CrmNineStepCatalog::CUSTOMER;
        $customer->customer_pool = $customer->customer_pool ?: '交付跟踪/发货池';
        $customer->crm_version++;
        $customer->save();
        app(CrmCustomerLogService::class)->write($customer->id, 'first_order_effective', '正式订单确认，进入第9步｜赢销企业',
            $before, $customer->attributesToArray(), null, $actor);
    }
}
