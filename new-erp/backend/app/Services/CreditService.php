<?php

namespace App\Services;

use App\Models\CreditRecord;
use App\Models\CreditSetting;
use App\Models\CreditTitle;
use App\Models\UserCredit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreditService
{
    public function award(User $user, string $ruleKey, array $options = []): ?CreditRecord
    {
        $setting = CreditSetting::where('rule_key', $ruleKey)->first();
        if (!$setting || !$setting->enabled || $setting->credit_mode === 'none') {
            return null;
        }

        $credit = $this->resolveCredit($setting, $options['credit_type'] ?? null, $options['credit'] ?? null);
        if ($credit <= 0) {
            return null;
        }

        $today = now()->toDateString();
        $already = (float) CreditRecord::where('user_id', $user->id)
            ->where('rule_key', $setting->rule_key)
            ->where('occurred_on', $today)
            ->sum('credit');

        $dailyLimit = (int) $setting->daily_limit;
        if ($dailyLimit > 0) {
            $remain = max(0, $dailyLimit - $already);
            if ($remain <= 0) {
                return null;
            }
            $credit = min($credit, $remain);
        }

        return DB::transaction(function () use ($user, $setting, $credit, $options, $today) {
            $record = CreditRecord::create([
                'user_id' => $user->id,
                'credit_setting_id' => $setting->id,
                'rule_key' => $setting->rule_key,
                'behavior_name' => $setting->behavior_name,
                'credit' => $credit,
                'source_type' => $options['source_type'] ?? null,
                'source_id' => isset($options['source_id']) ? (string) $options['source_id'] : null,
                'remark' => $options['remark'] ?? null,
                'occurred_on' => $today,
            ]);

            $balance = UserCredit::firstOrCreate(
                ['user_id' => $user->id],
                ['total_credit' => 0]
            );
            $balance->increment('total_credit', $credit);
            $balance->refresh();
            $this->refreshUserTitle($balance);

            return $record;
        });
    }

    public function refreshUserTitle(UserCredit $balance): void
    {
        $title = CreditTitle::where('min_credit', '<=', (float) $balance->total_credit)
            ->orderByDesc('min_credit')
            ->orderByDesc('id')
            ->first();

        $balance->credit_title_id = $title?->id;
        $balance->save();
    }

    public function refreshAllUserTitles(): void
    {
        UserCredit::query()->chunkById(100, function ($balances) {
            foreach ($balances as $balance) {
                $this->refreshUserTitle($balance);
            }
        });
    }

    private function resolveCredit(CreditSetting $setting, ?string $creditType, $overrideCredit): float
    {
        if ($overrideCredit !== null) {
            return max(0, (float) $overrideCredit);
        }

        if ($setting->credit_mode === 'required_optional') {
            return (float) ($creditType === 'required' ? $setting->required_credit : $setting->elective_credit);
        }

        return (float) $setting->credit_value;
    }
}
