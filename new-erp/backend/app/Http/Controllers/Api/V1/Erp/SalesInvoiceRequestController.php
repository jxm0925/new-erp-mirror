<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\FinanceAttachment;
use App\Models\Erp\SalesInvoiceRequest;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\SalesInvoiceRequestService;
use App\Services\Erp\SalesOrderVisibilityService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** 销售开票申请：销售在订单里申请，财务在“开票申请”里开票登记或驳回。 */
class SalesInvoiceRequestController extends Controller
{
    public function __construct(private readonly SalesInvoiceRequestService $service) {}

    public function orderIndex(Request $request, int $id)
    {
        $user = $this->authorizeAll($request, ['sales_order.view', 'sales_order.amount.view']);
        $data = $this->service->forOrder($this->visibleOrder($user, $id));
        $data['can_create'] = $this->has($user, 'sales_order.invoice_request');
        return response()->json(['data' => $data]);
    }

    public function store(Request $request, int $id)
    {
        $user = $this->authorizeAll($request, ['sales_order.view', 'sales_order.invoice_request']);
        $order = $this->visibleOrder($user, $id);
        $data = $request->validate([
            'invoice_type' => ['required', Rule::in(SalesInvoiceRequestService::INVOICE_TYPES)],
            'invoice_title' => 'required|string|max:200',
            'invoice_tax_no' => 'nullable|string|max:60',
            'invoice_address_phone' => 'nullable|string|max:300',
            'invoice_bank_account' => 'nullable|string|max:300',
            'invoice_email' => 'nullable|email|max:160',
            'amount' => ['required', 'regex:/^\d{1,14}(\.\d{1,2})?$/'],
            'tax_rate' => 'nullable|numeric|min:0|max:100',
            'remark' => 'nullable|string|max:500',
            'save_to_customer' => 'boolean',
        ], [], ['invoice_title' => '发票抬头', 'invoice_tax_no' => '纳税人识别号', 'invoice_email' => '收票邮箱', 'amount' => '开票金额', 'tax_rate' => '税率']);
        $row = $this->service->create($order, $data, $user);
        return response()->json(['message' => '开票申请已提交，等待财务开票', 'data' => $this->service->payload($row)], 201);
    }

    public function withdraw(Request $request, int $id)
    {
        $user = $this->currentUser($request);
        $canHandle = $this->has($user, 'finance.invoice_request.handle');
        abort_unless($canHandle || $this->has($user, 'sales_order.invoice_request'), 403, '无开票申请权限');
        $row = $this->service->withdraw($id, $user, $canHandle);
        return response()->json(['message' => '开票申请已撤回', 'data' => $this->service->payload($row)]);
    }

    public function attachment(Request $request, int $id, int $attachmentId)
    {
        $user = $this->currentUser($request);
        $row = SalesInvoiceRequest::query()->findOrFail($id);
        if (!$this->has($user, 'finance.invoice_request.view')) {
            abort_unless($this->has($user, 'sales_order.view') && $this->has($user, 'sales_order.amount.view'), 403, '无权查看发票文件');
            $this->visibleOrder($user, (int) $row->sales_order_id);
        }
        $file = FinanceAttachment::query()->where('document_type', 'finance_invoice')->where('document_id', $row->finance_invoice_id)
            ->where('status', 'active')->findOrFail($attachmentId);
        $inline = str_starts_with((string) $file->mime_type, 'image/') || $file->mime_type === 'application/pdf';
        return Storage::disk($file->storage_disk)->response($file->storage_path, $file->original_name, [
            'Content-Type' => $file->mime_type ?: 'application/octet-stream',
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename="'.rawurlencode($file->original_name).'"',
        ]);
    }

    public function index(Request $request)
    {
        $this->authorizeAll($request, ['finance.invoice_request.view']);
        $filters = $request->validate([
            'status' => 'nullable|in:pending,issued,rejected,withdrawn', 'keyword' => 'nullable|string|max:160',
            'date_start' => 'nullable|date', 'date_end' => 'nullable|date',
        ]);
        return response()->json($this->service->paginate($filters, min(100, max(5, (int) $request->input('per_page', 20)))));
    }

    public function issue(Request $request, int $id)
    {
        $user = $this->authorizeAll($request, ['finance.invoice_request.handle']);
        $data = $request->validate([
            'invoice_no' => 'required|string|max:120', 'invoice_code' => 'nullable|string|max:80',
            'invoice_date' => 'required|date', 'tax_rate' => 'required|numeric|min:0|max:100',
            'remark' => 'nullable|string|max:500', 'file' => 'nullable|file|max:10240',
        ], [], ['invoice_no' => '发票号码', 'invoice_date' => '开票日期', 'tax_rate' => '税率', 'file' => '发票文件']);
        $row = $this->service->issue($id, $data, $request->file('file'), $user);
        $message = $row->getAttribute('upload_failed') ? '已登记开票，但发票文件上传失败，请在列表里补传' : '已登记开票，销项发票已生成';
        return response()->json(['message' => $message, 'data' => $this->service->payload($row)]);
    }

    public function uploadFile(Request $request, int $id)
    {
        $user = $this->authorizeAll($request, ['finance.invoice_request.handle']);
        $data = $request->validate(['file' => 'required|file|max:10240'], [], ['file' => '发票文件']);
        $row = $this->service->uploadFile($id, $data['file'], $user);
        return response()->json(['message' => '发票文件已上传', 'data' => $this->service->payload($row)]);
    }

    public function reject(Request $request, int $id)
    {
        $user = $this->authorizeAll($request, ['finance.invoice_request.handle']);
        $data = $request->validate(['reason' => 'required|string|max:500'], [], ['reason' => '驳回原因']);
        $row = $this->service->reject($id, $data['reason'], $user);
        return response()->json(['message' => '开票申请已驳回', 'data' => $this->service->payload($row)]);
    }

    private function visibleOrder(object $user, int $id): SalesOrder
    {
        $query = SalesOrder::query();
        app(SalesOrderVisibilityService::class)->apply($query, $user);
        return $query->findOrFail($id);
    }

    private function currentUser(Request $request): object
    {
        $user = app(AuthContextService::class)->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        return $user;
    }

    private function has(object $user, string $permission): bool
    {
        $auth = app(AuthContextService::class);
        return $auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true);
    }

    private function authorizeAll(Request $request, array $permissions): object
    {
        $user = $this->currentUser($request);
        foreach ($permissions as $permission) abort_unless($this->has($user, $permission), 403, '无开票申请相关权限');
        return $user;
    }
}
