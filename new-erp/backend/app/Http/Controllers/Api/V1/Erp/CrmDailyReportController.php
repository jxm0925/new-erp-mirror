<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\{CrmCustomerAccessService, CrmDailyFacts, CrmDailyReportService, CrmSalesStaffService};
use App\Services\Erp\CrmAnalysisProvider;
use Illuminate\Http\Request;

final class CrmDailyReportController extends Controller
{
    public function __construct(
        private readonly CrmDailyReportService $reports,
        private readonly CrmDailyFacts $facts,
        private readonly AuthContextService $auth,
        private readonly CrmCustomerAccessService $access,
        private readonly CrmAnalysisProvider $provider,
    ) {}

    private function actor(Request $r, bool $sales = false): object
    {
        $user = $this->auth->currentUser($r);
        abort_unless($user, 401, '请先登录。');
        abort_unless($this->access->can($user, 'crm.lead.view'), 403, '需要线索查看权限。');
        if ($sales) abort_unless($this->seller($user), 403, '需要有效销售身份和跟进权限。');
        return $user;
    }

    private function seller(object $actor): bool
    {
        return $this->auth->isSuperAdmin($actor) || (app(CrmSalesStaffService::class)->isActiveSales($actor->legacy_id)
            && $this->access->can($actor, 'crm.lead.follow'));
    }

    public function access(Request $r)
    {
        $actor = $this->actor($r);
        return [
            'seller' => $this->seller($actor),
            'departments' => $this->facts->departments($actor),
            'sales_staff' => $this->facts->salesStaffForActor($actor),
            'ai_providers' => $this->facts->aiProviders($this->provider),
            'today' => today()->toDateString(),
        ];
    }

    private function day(Request $r): string
    {
        return $r->validate(['date' => 'required|date_format:Y-m-d|before_or_equal:today'])['date'];
    }

    public function sales(Request $r)
    {
        $actor = $this->actor($r, false);
        $params = $r->validate([
            'sales_id' => 'nullable|integer|min:1',
            'project_keys' => 'sometimes|array|max:100',
            'project_keys.*' => ['string', 'distinct', 'regex:/^(lead|opportunity):[1-9][0-9]*$/'],
        ]);
        $salesId = $params['sales_id'] ?? null;
        $salesId = $salesId !== null ? (int) $salesId : null;
        if ($salesId === null || $salesId === (int) $actor->legacy_id) {
            abort_unless($this->seller($actor), 403, '需要有效销售身份和跟进权限。');
        }
        return $this->reports->sales($actor, $this->day($r), $salesId, $params['project_keys'] ?? []);
    }

    public function saveSales(Request $r)
    {
        return $this->reports->saveSales($this->actor($r, true), $this->day($r), $r->all());
    }

    public function saveSupervisorNote(Request $r)
    {
        $actor = $this->actor($r, false);
        $payload = $r->validate([
            'date' => 'required|date_format:Y-m-d|before_or_equal:today',
            'sales_id' => 'required|integer|min:1',
            'final' => 'nullable|string|max:2000',
        ]);
        return $this->reports->saveSupervisorNote($actor, $payload['date'], (int) $payload['sales_id'], (string) ($payload['final'] ?? ''));
    }

    public function analyzeSales(Request $r)
    {
        $ai = $this->aiSelection($r);
        return $this->reports->analyzeSales($this->actor($r, true), $this->day($r), $this->version($r), $ai['service_code'], $ai['model']);
    }

    public function team(Request $r, int $dept)
    {
        return $this->reports->team($this->actor($r), $this->day($r), $dept);
    }

    public function saveTeam(Request $r, int $dept)
    {
        return $this->reports->saveTeam($this->actor($r), $this->day($r), $dept, $r->all());
    }

    public function analyzeTeam(Request $r, int $dept)
    {
        $ai = $this->aiSelection($r);
        return $this->reports->analyzeTeam($this->actor($r), $this->day($r), $dept, $this->version($r), $ai['service_code'], $ai['model']);
    }

    public function candidates(Request $r)
    {
        $actor = $this->actor($r, true);
        $filters = $r->validate(['keyword' => 'nullable|string|max:150', 'page' => 'nullable|integer|min:1']);
        return $this->facts->candidates($actor, $this->day($r), trim($filters['keyword'] ?? ''), (int) ($filters['page'] ?? 1));
    }

    public function coordinate(Request $r, int $dept, int $id)
    {
        $this->reports->coordinate($this->actor($r), $dept, $id, $r->all());
        return ['message' => '协调事项已更新'];
    }

    public function resubmit(Request $r, int $id)
    {
        $actor = $this->actor($r, true);
        $this->reports->resubmit($actor, $id, $r->validate(['note' => 'required|string|max:500', 'version' => 'required|integer|min:1']));
        return ['message' => '已重新提交协调事项'];
    }

    public function startCoordination(Request $r, int $dept)
    {
        $this->reports->startCoordination($this->actor($r), $this->day($r), $dept, $r->validate([
            'owner' => 'required|integer|min:1',
            'key' => ['required', 'regex:/^(lead|opportunity):[1-9][0-9]*$/'],
            'note' => 'required|string|max:500',
        ]));
        return ['message' => '已发起协调'];
    }

    private function version(Request $r): int
    {
        return (int) $r->validate(['version' => 'required|integer|min:0'])['version'];
    }

    private function aiSelection(Request $r): array
    {
        $ai = $r->validate([
            'service_code' => ['nullable', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/'],
            'model' => 'nullable|string|max:120',
        ]);
        return [
            'service_code' => isset($ai['service_code']) && $ai['service_code'] !== '' ? $ai['service_code'] : null,
            'model' => isset($ai['model']) && trim((string) $ai['model']) !== '' ? trim((string) $ai['model']) : null,
        ];
    }
}
