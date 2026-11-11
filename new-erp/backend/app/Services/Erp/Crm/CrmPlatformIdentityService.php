<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\CrmCustomerIdentity;
use App\Models\Erp\SalesCustomer;
use App\Services\Erp\TradePlatformApplicationService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 平台身份：按成交平台档案（主平台＝一家店铺）上的买家ID识别方式计算唯一键。
 * - platform：同一电商平台各店买家ID相同，键＝所属电商平台＋买家ID（京东三家店的同一买家只算一个身份）；
 * - shop：各店不同，键＝本店平台编号＋买家ID；
 * - 未设置：不生成正式身份，订单照常保存，CRM 线上线索只能存草稿。
 * 跨电商平台（天猫咨询、京东下单）永远是两个身份，只能经人工确认后归到同一客户，不靠键自动合并。
 */
final class CrmPlatformIdentityService
{
    public function __construct(private readonly CrmCustomerLogService $logs) {}

    /** @return array{scope:string,key:?string,platform_name:?string,platform_group:?string} */
    public function resolve(?int $platformLegacyId, ?string $buyerId, bool $lock = false): array
    {
        // 与平台设置共用事务锁，防止检查旧规则后按已失效的键绑定身份。
        if ($lock) app(TradePlatformApplicationService::class)->lockIdentityCounter();
        $buyerId = trim((string) $buyerId);
        $row = $platformLegacyId
            ? DB::table('erp_sales_order_trade_platforms')->where('legacy_id', $platformLegacyId)->where('parent_legacy_id', 0)->when($lock, fn ($q) => $q->lockForUpdate())->first()
            : null;
        $setting = TradePlatformApplicationService::buyerIdentity($row?->legacy_payload);
        $result = ['scope' => 'unset', 'key' => null, 'platform_name' => $row?->name, 'platform_group' => $setting['buyer_platform_group']];
        if (!$row || $buyerId === '') return $result;
        if ($setting['buyer_identity_scope'] === 'platform' && $setting['buyer_platform_group']) {
            return ['scope' => 'platform', 'key' => hash('sha256', 'group|'.mb_strtolower($setting['buyer_platform_group'])."\0".$buyerId)] + $result;
        }
        if ($setting['buyer_identity_scope'] === 'shop') {
            return ['scope' => 'shop', 'key' => hash('sha256', 'shop|'.$row->legacy_id."\0".$buyerId)] + $result;
        }
        return $result;
    }

    public function owner(?string $key, bool $lock = false): ?CrmCustomerIdentity
    {
        return $key ? CrmCustomerIdentity::query()->where('identity_key', $key)->when($lock, fn ($q) => $q->lockForUpdate())->first() : null;
    }

    public function assertCustomer(int $customerId, ?int $platformLegacyId, ?string $buyerId, bool $lock = false): array
    {
        $resolved = $this->resolve($platformLegacyId, $buyerId, $lock);
        $owner = $this->owner($resolved['key'], $lock);
        if ($owner && (int) $owner->customer_id !== $customerId) $this->conflict($owner);
        return $resolved;
    }

    /**
     * 绑定到指定客户；同一键已属其他客户时拒绝并只说明“属于其他客户”，不改任何一方资料。
     * 唯一索引兜底并发：两人同时绑定同一买家ID，只有一条成功，另一方得到同样的冲突提示。
     */
    public function bind(SalesCustomer $customer, int $platformLegacyId, string $buyerId, string $source, ?object $actor, ?string $note = null): CrmCustomerIdentity
    {
        $resolved = $this->resolve($platformLegacyId, $buyerId, true);
        if (!$resolved['key']) {
            throw ValidationException::withMessages(['platform_legacy_id' => $resolved['platform_name']
                ? "“{$resolved['platform_name']}”尚未设置买家ID识别方式，不能生成正式平台身份。请在成交平台档案中设置后再绑定。"
                : '请选择有效的成交平台（主平台）。']);
        }
        $existing = $this->owner($resolved['key'], true);
        if ($existing) {
            if ((int) $existing->customer_id === (int) $customer->id) return $existing;
            $this->conflict($existing);
        }
        try {
            $identity = CrmCustomerIdentity::create([
                'customer_id' => $customer->id, 'platform_legacy_id' => $platformLegacyId,
                'platform_name' => $resolved['platform_name'], 'platform_group' => $resolved['platform_group'],
                'scope' => $resolved['scope'], 'buyer_id' => trim($buyerId), 'identity_key' => $resolved['key'],
                'status' => 'confirmed', 'source' => $source, 'note' => $note,
                'bound_by_legacy_id' => $actor?->legacy_id, 'bound_by_name' => $this->actorName($actor), 'bound_at' => now(),
            ]);
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) !== 1062) throw $error;
            $this->conflict($this->owner($resolved['key']));
        }
        $this->logs->write($customer->id, 'identity_bind', "绑定平台身份：{$resolved['platform_name']} {$identity->buyer_id}", null, $this->present($identity), $note, $actor);
        return $identity;
    }

    /** 旧来源待核实身份确认为正式身份：补平台、按当前识别方式生成唯一键。 */
    public function verify(CrmCustomerIdentity $identity, int $platformLegacyId, string $buyerId, ?object $actor, string $note): CrmCustomerIdentity
    {
        abort_unless($identity->status === 'pending', 422, '只有待核实的身份需要核实。');
        $resolved = $this->resolve($platformLegacyId, $buyerId, true);
        if (!$resolved['key']) {
            throw ValidationException::withMessages(['platform_legacy_id' => '该成交平台尚未设置买家ID识别方式，不能确认为正式身份。']);
        }
        $existing = $this->owner($resolved['key'], true);
        if ($existing && (int) $existing->customer_id !== (int) $identity->customer_id) $this->conflict($existing);
        $before = $this->present($identity);
        try {
            $identity->update([
                'platform_legacy_id' => $platformLegacyId, 'platform_name' => $resolved['platform_name'], 'platform_group' => $resolved['platform_group'],
                'scope' => $resolved['scope'], 'buyer_id' => trim($buyerId), 'identity_key' => $existing ? null : $resolved['key'],
                'status' => $existing ? 'disabled' : 'confirmed', 'note' => $note, 'version' => $identity->version + 1,
            ]);
        } catch (QueryException $error) {
            if (($error->errorInfo[1] ?? null) !== 1062) throw $error;
            $this->conflict($this->owner($resolved['key']));
        }
        $this->logs->write($identity->customer_id, 'identity_verify', "核实平台身份：{$resolved['platform_name']} {$identity->buyer_id}", $before, $this->present($identity), $note, $actor);
        return $identity;
    }

    public function disable(CrmCustomerIdentity $identity, ?object $actor, string $reason): void
    {
        $before = $this->present($identity);
        $identity->update(['status' => 'disabled', 'identity_key' => null, 'note' => $reason, 'version' => $identity->version + 1]);
        $this->logs->write($identity->customer_id, 'identity_disable', "停用平台身份：{$identity->platform_name} {$identity->buyer_id}", $before, $this->present($identity), $reason, $actor);
    }

    public function present(CrmCustomerIdentity $identity): array
    {
        return $identity->only(['id', 'customer_id', 'platform_legacy_id', 'platform_name', 'platform_group', 'scope', 'buyer_id', 'status', 'source', 'raw_value', 'note', 'bound_by_name', 'bound_at', 'version']);
    }

    /** 冲突提示不带对方客户名称：对方可能是无权查看的其他部门客户。 */
    public function conflict(?CrmCustomerIdentity $existing): never
    {
        $message = '该平台买家ID已属于另一位客户，不能重复绑定。请提交核实，或更换客户/买家ID。';
        throw new HttpResponseException(response()->json([
            'message' => $message, 'errors' => ['platform_buyer_id' => [$message]], 'crm_identity_conflict' => true,
        ], 422));
    }

    private function actorName(?object $actor): ?string
    {
        return $actor ? (($actor->nickname ?? null) ?: ($actor->username ?? null)) : null;
    }
}
