<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{ImportBatch, ImportRow};
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Imports\MasterImportPipeline;
use App\Services\Erp\ItemManagementScopeService;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ImportController extends Controller
{
    public function template(Request $request, MasterImportPipeline $pipeline): StreamedResponse
    {
        $this->authorizeAnyPermission($request, ['master.import.upload', 'master.import']);
        $data = $request->validate(['import_type' => 'required|in:'.implode(',', MasterImportPipeline::types()),
            'management_scope' => 'nullable|in:factory,office']);
        $book = $pipeline->template($data['import_type'], $data['management_scope'] ?? null);
        return response()->streamDownload(function () use ($book): void {
            try {
                (new Xlsx($book))->save('php://output');
            } finally {
                $book->disconnectWorksheets();
            }
        }, $data['import_type'].'-标准导入模板.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function upload(Request $request)
    {
        $this->authorizePermission($request, 'master.import.upload');
        $file = $request->file('file');
        // A real UTF-8 CSV can be detected as text/plain; its original extension must still be csv.
        $mimes = $file instanceof UploadedFile && strtolower($file->getClientOriginalExtension()) === 'csv' ? 'csv,txt' : 'xlsx,xls';
        $data = $request->validate(['file' => 'required|file|extensions:xlsx,xls,csv|mimes:'.$mimes.'|max:10240',
            'import_type' => 'required|in:'.implode(',', MasterImportPipeline::types())]);
        $queryScope = app(ItemManagementScopeService::class)->requestScope($request);
        $body = Validator::make($request->post(), ['management_scope' => 'sometimes|required|in:factory,office'])->validate();
        $bodyScope = $body['management_scope'] ?? null;
        if ($bodyScope !== null && $queryScope !== null && $bodyScope !== $queryScope) {
            throw ValidationException::withMessages(['management_scope' => '上传表单与请求参数的管理范围不一致。']);
        }
        $scope = $bodyScope ?? $queryScope;
        abort_if($scope !== null && !MasterImportPipeline::supportsScope($data['import_type']), 422, '管理范围只适用于物料或仓库导入批次。');
        $path = $request->file('file')->store('erp-imports');
        $batch = ImportBatch::create([
            'batch_no' => 'IMP-'.now()->format('YmdHis').'-'.Str::upper(Str::random(4)),
            'import_type' => $data['import_type'], 'file_name' => $request->file('file')->getClientOriginalName(),
            'stored_path' => $path, 'status' => 'uploaded', 'management_scope' => $scope,
        ]);
        return response()->json(['message' => '上传成功', 'data' => $batch], 201);
    }

    public function preview(Request $request, int $id, MasterImportPipeline $pipeline)
    {
        $this->authorizePermission($request, 'master.import.upload');
        $batch = ImportBatch::findOrFail($id);
        $this->assertBatchContext($request, $batch);
        $batch = $pipeline->preview($batch, app(AuthContextService::class)->currentUser($request));
        return response()->json(['message' => '预检完成', 'data' => $batch->fresh(), 'rows' => $batch->rows()->orderBy('row_no')->orderBy('id')->paginate(10)]);
    }

    public function rows(Request $request, int $id)
    {
        $this->authorizePermission($request, 'master.import.upload');
        $this->assertBatchContext($request, ImportBatch::findOrFail($id));
        $query = ImportRow::where('batch_id', $id);
        if ($request->filled('status')) $query->where('validation_status', $request->status);
        return response()->json($query->orderBy('row_no')->orderBy('id')->paginate(min(200, max(10, $request->integer('per_page', 10)))));
    }

    public function confirm(Request $request, int $id, MasterImportPipeline $pipeline)
    {
        $this->authorizePermission($request, 'master.import.execute');
        $batch = ImportBatch::findOrFail($id);
        $this->assertBatchContext($request, $batch);
        $result = $pipeline->confirm($batch, app(AuthContextService::class)->currentUser($request));
        return response()->json(['message' => "已导入 {$result['imported_rows']} 条正确数据", 'data' => $result['batch']]);
    }

    public function exportErrors(Request $request, int $id): StreamedResponse
    {
        $this->authorizePermission($request, 'master.import.upload');
        $batch = ImportBatch::findOrFail($id);
        $this->assertBatchContext($request, $batch);
        $inventoryAmounts = app(\App\Services\Erp\InventoryAmountVisibilityService::class);
        $showCosts = $inventoryAmounts->canView($request);
        return response()->streamDownload(function () use ($batch, $inventoryAmounts, $showCosts) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF");
            fputcsv($out, ['行号', '字段', '错误类型', '错误原因', '原始值', '建议处理方式']);
            ImportRow::where('batch_id', $batch->id)->where('validation_status', 'error')->orderBy('row_no')
                ->each(function ($row) use ($out, $inventoryAmounts, $showCosts) {
                    if (! $showCosts && $inventoryAmounts->isCostField((string) $row->error_field)) return;
                    $raw = $showCosts ? $row->raw_data : $inventoryAmounts->redact($row->raw_data);
                    fputcsv($out, [$row->row_no, $row->error_field, $row->error_type, $row->error_reason, json_encode($raw, JSON_UNESCAPED_UNICODE), $row->suggestion]);
                });
            fclose($out);
        }, $batch->batch_no.'-errors.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function destroy(Request $request, int $id)
    {
        $this->authorizePermission($request, 'master.import.delete');
        $path = DB::transaction(function () use ($request, $id): string {
            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($id);
            $this->assertBatchContext($request, $batch);
            abort_unless(in_array($batch->status, ['uploaded', 'previewed'], true) && $batch->confirmed_at === null, 422, '已确认导入的批次不能删除；其导入结果属于正式主数据。');
            $path = (string) $batch->stored_path;
            $batch->delete();
            return $path;
        }, 5);

        if ($path !== '') {
            DB::afterCommit(function () use ($path): void {
                try {
                    Storage::delete($path);
                } catch (\Throwable $error) {
                    report($error);
                }
            });
        }
        return response()->json(['message' => '未确认的导入批次及预检明细已删除。']);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $this->authorizeAnyPermission($request, [$permission]);
    }

    private function authorizeAnyPermission(Request $request, array $permissions): void
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || array_intersect($permissions, $auth->permissionCodes($user)),
            403,
            '无导入操作权限。'
        );
    }

    private function assertBatchContext(Request $request, ImportBatch $batch): void
    {
        if ($batch->import_type === 'Legacy Customers') {
            $this->authorizePermission($request, 'sales.customer');
            abort_unless($request->isMethodSafe(), 422, '客户迁移批次及合并映射只供追溯，不能通过普通导入入口重新处理。');
        }
        if ($batch->import_type === 'Legacy Users') {
            $this->authorizePermission($request, 'system.admin.view');
            abort_unless($request->isMethodSafe(), 422, '员工迁移批次只供追溯，不能通过普通导入入口重新处理。');
        }
        // 旧ERP完整快照保留旧成本字段，普通导入权限不能读取这类未脱敏追溯。
        if (str_starts_with($batch->import_type, 'Legacy Master: ') || $batch->import_type === 'Legacy Orders') {
            $this->authorizePermission($request, 'inventory.amount.view');
            abort_unless($request->isMethodSafe(), 422, '历史迁移批次只供追溯，不能通过普通导入入口重新处理。');
        }
        $scope = app(ItemManagementScopeService::class)->requestScope($request);
        if ($scope !== null && (!MasterImportPipeline::supportsScope($batch->import_type) || $batch->management_scope !== $scope)) {
            throw ValidationException::withMessages(['management_scope' => '该导入批次不属于当前管理范围，请从原上传入口打开。']);
        }
    }
}
