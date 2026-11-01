<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final class AssemblyDisplayChanged implements ShouldBroadcast
{
    public string $connection = 'database';
    public string $queue = 'assembly-display';

    public function __construct(
        private readonly string $eventId,
        private readonly string $type,
        private readonly array $workOrderIds,
        private readonly ?int $salesOrderId = null,
        private readonly ?int $changeId = null,
    ) {}

    public function broadcastOn(): array { return [new Channel('assembly-display')]; }
    public function broadcastAs(): string { return 'assembly-display.changed'; }
    public function broadcastWith(): array
    {
        return array_filter(['event_id' => $this->eventId, 'type' => $this->type,
            'work_order_ids' => $this->workOrderIds, 'sales_order_id' => $this->salesOrderId,
            'change_id' => $this->changeId], fn ($value) => $value !== null);
    }

    public static function afterCommit(string $eventId, string $type, array $workOrderIds, ?int $salesOrderId = null, ?int $changeId = null): void
    {
        if (! $workOrderIds) return;
        $event = new self($eventId, $type, array_values(array_unique(array_map('intval', $workOrderIds))), $salesOrderId, $changeId);
        // 提交后只进专用队列，推送超时不阻塞订单；入队失败也不能让已成功的订单被前端当成失败重试。
        DB::afterCommit(function () use ($event): void {
            try {
                event($event);
            } catch (\Throwable $error) {
                Log::warning('总装通知屏推送失败', ['event_id' => $event->eventId, 'type' => $event->type, 'exception' => $error::class]);
            }
        });
    }
}
