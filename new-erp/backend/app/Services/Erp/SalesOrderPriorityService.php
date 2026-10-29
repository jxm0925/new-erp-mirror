<?php

namespace App\Services\Erp;

use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderLog;
use App\Models\Erp\SalesOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 订单优先级：销售填的“加急 / 延期”自动对应优先级，销售负责人可以单独调整。
 * 数字越小越先做；工单跟着订单走，同一优先级先建的工单先做。
 */
final class SalesOrderPriorityService
{
    public const TOP = 1;
    public const URGENT = 2;
    public const NORMAL = 3;
    public const LATER = 4;
    public const LABELS = [self::TOP => '特急', self::URGENT => '加急', self::NORMAL => '普通', self::LATER => '可延后'];
    private const OPEN_WO = ['planned', 'released', 'in_progress', 'paused'];

    /** 销售填的加急 / 延期对应的优先级：加急 → 加急；延期 → 可延后；都没勾 → 普通。 */
    public static function fromFlags(bool $urgent, bool $delay): int
    {
        return $urgent ? self::URGENT : ($delay ? self::LATER : self::NORMAL);
    }

    public static function label(?int $priority): string
    {
        return self::LABELS[(int) $priority] ?? self::LABELS[self::NORMAL];
    }

    public static function normalize(mixed $priority): int
    {
        $value = (int) $priority;
        return isset(self::LABELS[$value]) ? $value : self::NORMAL;
    }

    /** 订单保存时：销售负责人没单独调过的，按加急 / 延期重算。 */
    public static function applyFlags(SalesOrder $order): void
    {
        if ($order->priority_adjusted_at) return;
        $order->priority = self::fromFlags((bool) $order->is_urgent, (bool) ($order->is_delay || $order->delay));
    }

    /** 销售负责人调整优先级：订单和没做完的工单（含补做、半成品工单）一起改。 */
    public function adjust(int $orderId, int $priority, ?string $reason, object $actor): array
    {
        $priority = self::normalize($priority);
        return DB::transaction(function () use ($orderId, $priority, $reason, $actor) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($orderId);
            if (in_array($order->order_status, ['cancelled', 'closed'], true)) throw ValidationException::withMessages(['priority' => '订单已取消或已完结，不用再调优先级。']);
            $before = (int) $order->priority;
            $name = (string) (($actor->nickname ?? null) ?: ($actor->username ?? null) ?: '系统');
            $order->forceFill(['priority' => $priority, 'priority_adjusted_by_legacy_id' => (int) ($actor->legacy_id ?? $actor->id ?? 0) ?: null,
                'priority_adjusted_by_name' => $name, 'priority_adjusted_at' => now(), 'priority_reason' => $reason ? mb_substr($reason, 0, 500) : null])->saveQuietly();
            $workOrders = $this->syncWorkOrders($order, $actor);
            return ['before' => $before, 'after' => $priority, 'work_orders' => $workOrders];
        }, 5);
    }

    /** 把订单优先级同步到它没做完的工单和这些工单下的半成品工单；返回改了的工单号。调用方须在事务中。 */
    public function syncWorkOrders(SalesOrder $order, ?object $actor = null): array
    {
        $ids = MfgWorkOrder::query()->where('sales_order_id', $order->id)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $pending = $ids;
        while ($pending) {
            $children = MfgWorkOrderLink::query()->whereIn('parent_work_order_id', $pending)->pluck('child_work_order_id')->map(fn ($id) => (int) $id)->all();
            $pending = array_values(array_diff($children, $ids));
            $ids = array_values(array_unique(array_merge($ids, $pending)));
        }
        if (! $ids) return [];
        $priority = (int) $order->priority;
        $changed = [];
        foreach (MfgWorkOrder::query()->whereIn('id', $ids)->whereIn('status', self::OPEN_WO)->where('priority', '!=', $priority)->orderBy('id')->lockForUpdate()->get() as $wo) {
            $before = (int) $wo->priority;
            $wo->update(['priority' => $priority, 'is_urgent' => $priority <= self::URGENT, 'business_version' => (int) $wo->business_version + 1]);
            MfgWorkOrderLog::create(['work_order_id' => $wo->id, 'action' => 'priority_changed', 'before_status' => $wo->status, 'after_status' => $wo->status,
                'content' => '订单 '.$order->sales_order_no.' 优先级由“'.self::label($before).'”改为“'.self::label($priority).'”，工单跟着调整',
                'payload' => ['before' => $before, 'after' => $priority],
                'operator_legacy_id' => $actor ? ((int) ($actor->legacy_id ?? $actor->id ?? 0) ?: null) : null,
                'operator_name' => $actor ? (string) (($actor->nickname ?? null) ?: ($actor->username ?? null) ?: '系统') : '系统']);
            $changed[] = $wo->work_order_no;
        }
        return $changed;
    }
}
