<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{DocumentNumberReservation, FinanceAccount, FinanceCashDocument, FinanceInvoice, Item, PaymentMethod, PurchaseLog, PurchasePlan, PurchasePlanItem, PurchasePlanSupplierSplit, PurchaseRequest, PurchaseRequestItem, SalesCustomer, Supplier, Unit};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\InputStream;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/** Committed, uniquely named fixtures are necessary for two independent MySQL connections. */
class DeletionConcurrencyTest extends TestCase
{
    public static function financeRaces(): array
    {
        $cases = [];
        foreach (['supplier', 'customer'] as $party) foreach (['cash', 'invoice'] as $document) foreach ([true, false] as $createFirst) {
            $cases[$party.'-'.$document.'-'.($createFirst ? 'create-first' : 'delete-first')] = [$party, $document, $createFirst];
        }
        return $cases;
    }

    #[DataProvider('financeRaces')]
    public function test_finance_creation_and_party_deletion_cannot_both_commit(string $partyType, string $documentType, bool $createFirst): void
    {
        $tag = 'RACE-'.Str::ulid();
        $party = $partyType === 'supplier'
            ? Supplier::create(['supplier_code' => $tag, 'supplier_name' => '并发测试供应商', 'status' => 'disabled'])
            : SalesCustomer::create(['customer_name' => $tag, 'customer_type' => 'company', 'status' => 'potential']);
        $type = $documentType === 'cash' ? ($partyType === 'supplier' ? 'finance_payment' : 'finance_receipt') : 'finance_invoice';
        $reservation = DocumentNumberReservation::create(['document_type' => $type, 'document_no' => $tag, 'creation_session_id' => (string) Str::uuid(), 'reservation_token' => (string) Str::uuid(), 'status' => 'reserved', 'expires_at' => now()->addDay()]);
        $account = null; $method = null;
        try {
            $payload = ['party_type' => $partyType, 'party_id' => $party->id, 'reservation_token' => $reservation->reservation_token];
            if ($documentType === 'cash') {
                $account = FinanceAccount::create(['account_no' => $tag, 'account_name' => '并发测试账户', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
                $method = PaymentMethod::create(['method_code' => $tag, 'method_name' => '并发测试方式', 'available_for_receipt' => true, 'available_for_payment' => true, 'status' => 'enabled']);
                $payload += ['direction' => $partyType === 'supplier' ? 'payment' : 'receipt', 'finance_account_id' => $account->id, 'payment_method_id' => $method->id, 'amount' => '100.0000', 'currency' => 'CNY', 'business_date' => now()->toDateString()];
            } else {
                $payload += ['invoice_direction' => $partyType === 'supplier' ? 'purchase' : 'sales', 'tax_detail' => [['tax_rate' => 0, 'amount_excl_tax' => '100.0000', 'tax_amount' => '0.0000']]];
            }
            $common = ['id' => $party->id, 'lock_table' => $party->getTable()];
            $create = $common + ['action' => $documentType.'_create', 'payload' => $payload];
            $delete = $common + ['action' => $partyType.'_delete'];
            [$first, $second] = $this->race($createFirst ? $create : $delete, $createFirst ? $delete : $create);
            $this->assertSame(200, $first['status']);
            $this->assertSame(422, $second['status']);
            $table = $documentType === 'cash' ? 'erp_finance_cash_documents' : 'erp_finance_invoices';
            $count = DB::table($table)->where('party_type', $partyType)->where('party_id', $party->id)->count();
            $this->assertSame($createFirst ? 1 : 0, $count);
            $this->assertSame($createFirst, $party->newQuery()->whereKey($party->id)->exists());
            if ($createFirst) $this->assertDatabaseHas($table, ['party_type' => $partyType, 'party_id' => $party->id, 'status' => 'draft']);
        } finally {
            $cashIds = FinanceCashDocument::where('document_no', $tag)->pluck('id');
            $invoiceIds = FinanceInvoice::where('document_no', $tag)->pluck('id');
            DB::table('erp_finance_operation_logs')->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('document_type', 'cash_document')->whereIn('document_id', $cashIds))
                ->orWhere(fn ($q) => $q->where('document_type', 'finance_invoice')->whereIn('document_id', $invoiceIds)))->delete();
            FinanceCashDocument::where('document_no', $tag)->delete();
            FinanceInvoice::where('document_no', $tag)->delete();
            $reservation->delete();
            $account?->delete(); $method?->delete();
            $party->newQuery()->whereKey($party->id)->delete();
        }
    }

    public static function planRaces(): array
    {
        return ['submit-first' => ['submit', true], 'delete-before-submit' => ['submit', false], 'update-first' => ['update', true], 'delete-before-update' => ['update', false]];
    }

    #[DataProvider('planRaces')]
    public function test_plan_submit_update_and_delete_use_the_same_row_lock(string $action, bool $writeFirst): void
    {
        $tag = 'RACE-'.Str::ulid();
        $unit = Unit::create(['unit_code' => $tag, 'unit_name' => '件', 'unit_type' => 'quantity', 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $tag, 'item_name' => '并发采购物料', 'unit_id' => $unit->id, 'item_type' => 'raw_material', 'is_purchase_item' => true, 'status' => 'enabled']);
        $supplier = Supplier::create(['supplier_code' => $tag, 'supplier_name' => '并发采购供应商', 'status' => 'enabled', 'approval_status' => 'approved']);
        $request = PurchaseRequest::create(['request_no' => $tag, 'item_id' => $item->id, 'request_status' => 'partially_planned', 'status' => 'partially_planned', 'request_qty' => 10, 'planned_qty' => 6]);
        $requestItem = PurchaseRequestItem::create(['request_id' => $request->id, 'item_id' => $item->id, 'request_qty' => 10, 'converted_qty' => 6, 'remaining_qty' => 4]);
        $plan = PurchasePlan::create(['plan_no' => $tag, 'plan_status' => 'draft', 'audit_status' => 'pending']);
        $planItem = PurchasePlanItem::create(['plan_id' => $plan->id, 'request_id' => $request->id, 'request_item_id' => $requestItem->id, 'item_id' => $item->id, 'required_qty' => 6, 'plan_qty' => 6, 'allocated_qty' => 6, 'remaining_qty' => 0]);
        $split = PurchasePlanSupplierSplit::create(['plan_id' => $plan->id, 'plan_item_id' => $planItem->id, 'item_id' => $item->id, 'supplier_id' => $supplier->id, 'purchase_qty' => 6, 'unit_price' => 20, 'ordered_qty' => 0, 'split_status' => 'not_ordered']);
        try {
            $common = ['id' => $plan->id, 'lock_table' => 'erp_purchase_plans'];
            $write = $common + ['action' => 'plan_'.$action, 'payload' => ['remark' => '并发编辑已提交', 'items' => [[
                'item_id' => $item->id, 'request_id' => $request->id, 'request_item_id' => $requestItem->id, 'required_qty' => 6,
                'splits' => [['supplier_id' => $supplier->id, 'purchase_qty' => 6, 'unit_price' => 20]],
            ]]]];
            $delete = $common + ['action' => 'plan_delete'];
            [$first, $second] = $this->race($writeFirst ? $write : $delete, $writeFirst ? $delete : $write);
            $this->assertSame(200, $first['status']);
            $this->assertSame($writeFirst ? 422 : 404, $second['status']);
            $this->assertSame($writeFirst, PurchasePlan::whereKey($plan->id)->exists());
            $this->assertSame($writeFirst ? 6.0 : 0.0, (float) $requestItem->fresh()->converted_qty);
            $this->assertSame($writeFirst ? 4.0 : 10.0, (float) $requestItem->fresh()->remaining_qty);
            $this->assertSame($writeFirst ? 1 : 0, PurchaseLog::where('target_type', 'purchase_plan')->where('target_id', $plan->id)->where('action', $action)->count());
            $this->assertSame($writeFirst ? 0 : 1, PurchaseLog::where('target_type', 'purchase_plan')->where('target_id', $plan->id)->where('action', 'delete_draft')->count());
            if ($writeFirst) $this->assertSame($action === 'submit' ? 'submitted' : 'draft', $plan->fresh()->plan_status);
        } finally {
            PurchasePlanSupplierSplit::where('plan_id', $plan->id)->delete();
            PurchasePlanItem::where('plan_id', $plan->id)->delete();
            PurchasePlan::whereKey($plan->id)->delete();
            PurchaseLog::where('target_type', 'purchase_plan')->where('target_id', $plan->id)->delete();
            $requestItem->delete(); $request->delete(); $supplier->delete(); $item->delete(); $unit->delete();
        }
    }

    private function race(array $firstArgs, array $secondArgs): array
    {
        $database = (string) config('database.connections.mysql.database');
        $this->assertStringEndsWith('_test', $database);
        $environment = array_merge($_ENV, ['ERP_DELETION_RACE_DATABASE' => $database, 'APP_ENV' => 'testing', 'DB_DATABASE' => $database]);
        $input = new InputStream();
        $first = new Process([PHP_BINARY, base_path('tests/Support/deletion_race_worker.php'), base64_encode(json_encode($firstArgs + ['hold' => true], JSON_THROW_ON_ERROR))], base_path(), $environment, $input, 20);
        $second = new Process([PHP_BINARY, base_path('tests/Support/deletion_race_worker.php'), base64_encode(json_encode($secondArgs + ['hold' => false], JSON_THROW_ON_ERROR))], base_path(), $environment, null, 20);
        try {
            $first->start();
            $locked = $this->waitEvent($first, 'locked');
            $this->assertGreaterThan(0, $locked['transaction_level']);
            $second->start();
            $attempt = $this->waitEvent($second, 'lock_attempt');
            $this->assertNotSame($locked['connection_id'], $attempt['connection_id']);
            // 两个独立进程真实重叠；先行事务持锁时后行事务不得返回成功。
            usleep(200000);
            $this->assertTrue($second->isRunning(), $second->getOutput().$second->getErrorOutput());
            $this->assertNull($this->event($second, 'result'));
            $input->write("continue\n"); $input->close();
            $first->wait(); $second->wait();
            $this->assertTrue($first->isSuccessful(), $first->getErrorOutput().$first->getOutput());
            $this->assertTrue($second->isSuccessful(), $second->getErrorOutput().$second->getOutput());
            $this->assertNotNull($this->event($second, 'locked'), '后行事务必须等待后以locking read重新检查。');
            return [$this->event($first, 'result'), $this->event($second, 'result')];
        } finally {
            $input->close();
            if ($first->isRunning()) $first->stop();
            if ($second->isRunning()) $second->stop();
        }
    }

    private function waitEvent(Process $process, string $name): array
    {
        $deadline = microtime(true) + 8;
        do {
            if ($event = $this->event($process, $name)) return $event;
            if (!$process->isRunning()) break;
            usleep(20000);
        } while (microtime(true) < $deadline);
        $this->fail('未取得进程事件 '.$name.'：'.$process->getOutput().$process->getErrorOutput());
    }

    private function event(Process $process, string $name): ?array
    {
        foreach (explode("\n", trim($process->getOutput())) as $line) {
            $event = json_decode($line, true);
            if (is_array($event) && ($event['event'] ?? null) === $name) return $event;
        }
        return null;
    }
}
