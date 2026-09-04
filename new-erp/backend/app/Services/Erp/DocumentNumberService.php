<?php

namespace App\Services\Erp;

use App\Models\Erp\DocumentNumber;
use App\Models\Erp\DocumentNumberReservation;
use App\Models\Erp\DocumentNumberRule;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class DocumentNumberService
{
    /**
     * Reserve a visible number for a create page.
     * A creation session always receives the same number after refresh.
     */
    public function reserve(
        string $documentType,
        string $creationSessionId,
        ?int $reservedByLegacyId,
        ?string $reservedPage = null
    ): DocumentNumberReservation {
        return DB::transaction(function () use ($documentType, $creationSessionId, $reservedByLegacyId, $reservedPage) {
            $existing = DocumentNumberReservation::query()
                ->where('document_type', $documentType)
                ->where('creation_session_id', $creationSessionId)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ($existing->status === 'expired') {
                    throw ValidationException::withMessages([
                        'creation_session_id' => '该新建会话的预占编号已过期，请重新打开新建页面。',
                    ]);
                }

                return $existing;
            }

            $number = $this->allocate($documentType);

            return DocumentNumberReservation::create([
                'document_type' => $documentType,
                'creation_session_id' => $creationSessionId,
                'document_no' => $number,
                'reservation_token' => (string) Str::uuid(),
                'status' => 'reserved',
                'reserved_by_legacy_id' => $reservedByLegacyId,
                'reserved_page' => $reservedPage,
                'expires_at' => now()->addDay(),
            ]);
        }, 5);
    }

    public function getBySession(string $documentType, string $creationSessionId): ?DocumentNumberReservation
    {
        return DocumentNumberReservation::query()
            ->where('document_type', $documentType)
            ->where('creation_session_id', $creationSessionId)
            ->first();
    }

    /**
     * Resolve the visible number held by an active create-page reservation.
     * Business services use this before inserting the record so a client cannot
     * replace the displayed number with a manually supplied value.
     */
    public function reservedNumber(
        string $reservationToken,
        string $documentType,
        ?int $operatorLegacyId,
        ?string $creationSessionId = null
    ): string {
        $reservation = $this->lockReservation($reservationToken, $documentType, $operatorLegacyId);

        if ($creationSessionId && $reservation->creation_session_id !== $creationSessionId) {
            throw ValidationException::withMessages([
                'creation_session_id' => '新建会话与预占编号不匹配，请重新打开新建页面。',
            ]);
        }

        if ($reservation->status !== 'reserved'
            || ($reservation->expires_at && $reservation->expires_at->isPast())) {
            throw ValidationException::withMessages([
                'reservation_token' => '该预占编号已失效，请重新打开新建页面。',
            ]);
        }

        return (string) $reservation->document_no;
    }

    /**
     * Consume a reserved number in the same transaction as the business write.
     */
    public function consume(
        string $reservationToken,
        string $documentType,
        string $documentNo,
        ?int $operatorLegacyId,
        string $businessType,
        int $businessId
    ): DocumentNumberReservation {
        $reservation = $this->lockReservation($reservationToken, $documentType, $operatorLegacyId);

        if ($reservation->document_no !== $documentNo) {
            throw ValidationException::withMessages([
                'reservation_token' => '预占编号与当前单据不匹配，请重新打开新建页面。',
            ]);
        }

        if ($reservation->status === 'used') {
            if ($reservation->business_type === $businessType
                && (int) $reservation->business_id === $businessId) {
                return $reservation;
            }
            throw ValidationException::withMessages([
                'reservation_token' => '该预占编号已被其他业务单据使用。',
            ]);
        }

        if ($reservation->status !== 'reserved'
            || ($reservation->expires_at && $reservation->expires_at->isPast())) {
            throw ValidationException::withMessages([
                'reservation_token' => '该预占编号已失效，请重新打开新建页面。',
            ]);
        }

        $reservation->update([
            'status' => 'used',
            'consumed_at' => now(),
            'business_type' => $businessType,
            'business_id' => $businessId,
        ]);

        return $reservation->fresh();
    }

    private function lockReservation(
        string $reservationToken,
        string $documentType,
        ?int $operatorLegacyId
    ): DocumentNumberReservation {
        $reservation = DocumentNumberReservation::query()
            ->where('reservation_token', $reservationToken)
            ->lockForUpdate()
            ->first();

        if (!$reservation || $reservation->document_type !== $documentType) {
            throw ValidationException::withMessages([
                'reservation_token' => '预占编号与当前业务类型不匹配，请重新打开新建页面。',
            ]);
        }

        if ($reservation->reserved_by_legacy_id
            && $operatorLegacyId
            && (int) $reservation->reserved_by_legacy_id !== $operatorLegacyId) {
            throw ValidationException::withMessages([
                'reservation_token' => '该预占编号不属于当前登录用户。',
            ]);
        }

        return $reservation;
    }

    public function expire(): int
    {
        return DocumentNumberReservation::query()
            ->where('status', 'reserved')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'void_reason' => '预占超时自动作废',
                'updated_at' => now(),
            ]);
    }

    /**
     * Compatibility entry for server-created downstream documents.
     * New create pages must call reserve() instead.
     */
    public function next(string $documentType, ?string $fallbackPrefix = null): string
    {
        return DB::transaction(fn () => $this->allocate($documentType, $fallbackPrefix), 5);
    }

    private function allocate(string $documentType, ?string $fallbackPrefix = null): string
    {
        $rule = DocumentNumberRule::query()
            ->where('document_type', $documentType)
            ->where('enabled', true)
            ->lockForUpdate()
            ->first();

        if (!$rule) {
            if (!$fallbackPrefix) {
                throw ValidationException::withMessages([
                    'document_type' => '未配置或未启用该业务的编号规则。',
                ]);
            }
            $rule = new DocumentNumberRule([
                'document_type' => $documentType,
                'prefix' => $fallbackPrefix,
                'date_format' => 'Ymd',
                'sequence_length' => 5,
                'reset_cycle' => 'daily',
                'enabled' => true,
            ]);
        }

        $numberDate = match ($rule->reset_cycle) {
            'none' => '1970-01-01',
            'yearly' => now()->startOfYear()->toDateString(),
            'monthly' => now()->startOfMonth()->toDateString(),
            default => now()->toDateString(),
        };

        $counter = DocumentNumber::query()
            ->where('document_type', $documentType)
            ->whereDate('number_date', $numberDate)
            ->lockForUpdate()
            ->first();

        if (!$counter) {
            $counter = DocumentNumber::create([
                'document_type' => $documentType,
                'number_date' => $numberDate,
                'current_sequence' => 0,
            ]);
        }

        $counter->increment('current_sequence');
        $sequence = str_pad(
            (string) $counter->fresh()->current_sequence,
            (int) $rule->sequence_length,
            '0',
            STR_PAD_LEFT
        );
        $datePart = $rule->date_format ? now()->format($rule->date_format) : '';

        return (string) $rule->prefix.$datePart.$sequence;
    }
}
