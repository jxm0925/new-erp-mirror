<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Models\Erp\FinanceAccountTransfer;
use App\Models\Erp\FinanceAttachment;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceInvoice;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Models\Erp\FinanceOperationLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

class FinanceDraftDeletionApplicationService
{
    public function __construct(
        private readonly PurchaseSettlementSourceApplicationService $purchaseSettlementSources,
    ) {}

    public function deleteTransfer(int $id, string $reason, object $operator): void
    {
        $files = DB::transaction(function () use ($id, $reason, $operator): array {
            $transfer = FinanceAccountTransfer::query()->lockForUpdate()->findOrFail($id);
            $this->assertReason($reason);
            if ($transfer->status !== FinanceConstants::STATUS_DRAFT || $transfer->confirmed_at !== null) {
                throw ValidationException::withMessages(['status' => '只有未确认、未入账的资金转账/换汇草稿可以删除。']);
            }
            if (DB::table('erp_finance_account_movements')->where('source_type', 'account_transfer')->where('source_id', $id)->exists()
                || DB::table('erp_finance_platform_fees')->where('transfer_id', $id)->exists()) {
                throw ValidationException::withMessages(['transfer' => '该转账已产生账户流水或手续费事实，不能删除。']);
            }
            $files = $this->attachmentFiles('account_transfer', $id);
            $this->deleteAttachments('account_transfer', $id);
            $this->auditDelete('account_transfer', $id, $transfer->transfer_no, $reason, $operator);
            $transfer->delete();
            return $files;
        }, 5);
        $this->deleteFiles($files);
    }

    public function deleteCashDocument(int $id, string $reason, object $operator): void
    {
        $files = DB::transaction(function () use ($id, $reason, $operator): array {
            $document = FinanceCashDocument::query()->lockForUpdate()->findOrFail($id);
            $this->assertReason($reason);
            if ($document->status !== FinanceConstants::STATUS_DRAFT || $document->confirmed_at !== null) {
                throw ValidationException::withMessages(['status' => '只有未确认、未入账的收付款草稿可以删除。']);
            }
            if (DB::table('erp_finance_allocations')->where('cash_document_id', $id)->exists()
                || DB::table('erp_finance_platform_fees')->where('cash_document_id', $id)->exists()
                || DB::table('erp_finance_account_movements')->where('source_type', 'cash_document')->where('source_id', $id)->exists()
                || DB::table('erp_finance_cash_documents')->where('reversal_of_id', $id)->exists()) {
                throw ValidationException::withMessages(['document' => '该资金单已产生核销、手续费、账户流水或冲销引用，不能删除。']);
            }
            $files = $this->attachmentFiles('cash_document', $id);
            $this->deleteAttachments('cash_document', $id);
            $this->auditDelete('cash_document', $id, $document->document_no, $reason, $operator);
            $document->delete();
            return $files;
        }, 5);
        $this->deleteFiles($files);
    }

    public function deleteInvoice(int $id, string $reason, object $operator): void
    {
        $files = DB::transaction(function () use ($id, $reason, $operator): array {
            $invoice = FinanceInvoice::query()->lockForUpdate()->findOrFail($id);
            $this->assertReason($reason);
            if ($invoice->status !== FinanceConstants::STATUS_DRAFT || $invoice->confirmed_at !== null) {
                throw ValidationException::withMessages(['status' => '只有未确认、尚未形成税务事实的发票草稿可以删除。']);
            }
            if (FinanceInvoice::query()->where('red_invoice_of_id', $invoice->id)->exists()) {
                throw ValidationException::withMessages(['invoice' => '该发票已被红字发票引用，不能删除。']);
            }

            $sourceIds = FinanceInvoiceAllocation::query()
                ->where('invoice_id', $invoice->id)
                ->where('source_business_type', FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE)
                ->pluck('source_document_id')->map(fn ($value) => (int) $value)->unique()->values();
            // 反向分摊先删除，避免 self-FK restrict；随后清除该草稿的全部匹配占用。
            FinanceInvoiceAllocation::query()->where('invoice_id', $invoice->id)
                ->whereNotNull('reversal_of_id')->delete();
            FinanceInvoiceAllocation::query()->where('invoice_id', $invoice->id)->delete();

            $files = $this->attachmentFiles('finance_invoice', $id);
            $this->deleteAttachments('finance_invoice', $id);
            $this->auditDelete('finance_invoice', $id, $invoice->document_no, $reason, $operator);
            $invoice->delete();
            foreach ($sourceIds as $sourceId) {
                $this->purchaseSettlementSources->refresh($sourceId, $operator->legacy_id ?? null);
            }
            return $files;
        }, 5);
        $this->deleteFiles($files);
    }

    private function attachmentFiles(string $documentType, int $documentId): array
    {
        return FinanceAttachment::query()
            ->where('document_type', $documentType)->where('document_id', $documentId)
            ->get(['storage_disk', 'storage_path'])
            ->map(fn (FinanceAttachment $attachment): array => [
                'disk' => (string) $attachment->storage_disk,
                'path' => (string) $attachment->storage_path,
            ])->all();
    }

    private function deleteAttachments(string $documentType, int $documentId): void
    {
        FinanceAttachment::query()
            ->where('document_type', $documentType)->where('document_id', $documentId)->delete();
    }

    private function auditDelete(string $documentType, int $documentId, string $documentNo, string $reason, object $operator): void
    {
        FinanceOperationLog::create([
            'document_type' => $documentType,
            'document_id' => $documentId,
            'action' => 'delete_draft',
            'from_status' => FinanceConstants::STATUS_DRAFT,
            'to_status' => 'deleted',
            'fact_snapshot' => ['document_no' => $documentNo],
            'operator_id' => $operator->legacy_id ?? null,
            'operator_name' => $operator->nickname ?? $operator->username ?? '系统',
            'content' => trim($reason),
        ]);
    }

    private function assertReason(string $reason): void
    {
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => '删除财务草稿必须填写原因。']);
        }
    }

    private function deleteFiles(array $files): void
    {
        // 调用方可能还有外层事务；只有最外层提交后才可清理文件，避免回滚后
        // 附件记录恢复、实际文件却已消失。清理失败不诱导重试已提交的业务删除。
        DB::afterCommit(function () use ($files): void {
            foreach ($files as $file) {
                if ($file['path'] === '') continue;
                try {
                    Storage::disk($file['disk'] ?: config('filesystems.default'))->delete($file['path']);
                } catch (\Throwable $exception) {
                    Log::warning('删除财务草稿后清理附件文件失败。', ['disk' => $file['disk'], 'path' => $file['path'], 'error' => $exception->getMessage()]);
                }
            }
        });
    }
}
