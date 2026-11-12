<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\TechnicalDrawingApplicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class TechnicalDrawingController extends Controller
{
    public function __construct(private readonly TechnicalDrawingApplicationService $drawings) {}

    public function metadata(Request $request)
    {
        $actor = $this->actor($request);
        $options = fn ($values) => collect($values)->map(fn ($label, $value) => compact('value', 'label'))->values();
        return response()->json(['data' => ['source_types' => $options(TechnicalDrawingApplicationService::SOURCES),
            'drawing_types' => $options(TechnicalDrawingApplicationService::TYPES), 'abilities' => ['statistics' => $this->drawings->canStatistics($actor)],
            'today' => now()->toDateString()]]);
    }

    public function employees(Request $request)
    {
        return response()->json($this->drawings->employees($this->selectorFilters($request), $this->actor($request)));
    }

    public function candidates(Request $request)
    {
        return response()->json($this->drawings->candidates($this->selectorFilters($request), $this->actor($request)));
    }

    public function revisions(Request $request, int $id)
    {
        return response()->json($this->drawings->revisions($id, $this->selectorFilters($request), $this->actor($request)));
    }

    public function sources(Request $request)
    {
        $data = $request->validate($this->sourceRules(true) + $this->pageRules());
        return response()->json($this->drawings->sourceUsages($data, $this->actor($request)));
    }

    public function people(Request $request)
    {
        return response()->json($this->drawings->people($this->statisticsFilters($request), $this->actor($request)));
    }

    public function index(Request $request)
    {
        return response()->json($this->drawings->paginate($this->statisticsFilters($request), $this->actor($request)));
    }

    public function show(Request $request, int $id)
    {
        $data = $request->validate(['revisions_page' => 'nullable|integer|min:1', 'usages_page' => 'nullable|integer|min:1'] + $this->pageRules());
        return response()->json(['data' => $this->drawings->detail($id, $this->actor($request), $data)]);
    }

    public function store(Request $request)
    {
        $data = $request->except('file');
        // multipart的来源作为JSON对象传递；业务规则仍在共享登记服务完整校验。
        if (isset($data['source']) && is_string($data['source'])) {
            $source = json_decode($data['source'], true);
            abort_unless(is_array($source), 422, '来源业务格式无效。');
            $data['source'] = $source;
        }
        $result = $this->drawings->register($data, $this->actor($request), $request->file('file'));
        return response()->json(['message' => '图纸登记已保存', 'data' => $result], 201);
    }

    public function confirm(Request $request, int $revisionId)
    {
        $data = $request->validate($this->sourceRules(true) + ['remark' => 'nullable|string|max:1000']);
        return response()->json(['message' => '图纸已确认', 'data' => $this->drawings->confirm($revisionId, $data['source'], $this->actor($request), $data['remark'] ?? null)]);
    }

    public function download(Request $request, int $revisionId)
    {
        $data = $request->validate(['usage_id' => 'nullable|integer|min:1']);
        return $this->drawings->download($revisionId, $this->actor($request), isset($data['usage_id']) ? (int) $data['usage_id'] : null);
    }

    private function statisticsFilters(Request $request): array
    {
        return $request->validate(['date_from' => 'nullable|date_format:Y-m-d',
            'date_to' => ['nullable', 'date_format:Y-m-d', Rule::when($request->filled('date_from'), 'after_or_equal:date_from')],
            'designer_legacy_id' => 'nullable|integer|min:1', 'source_type' => ['nullable', Rule::in(array_keys(TechnicalDrawingApplicationService::SOURCES))],
            'keyword' => 'nullable|string|max:200'] + $this->pageRules());
    }

    private function selectorFilters(Request $request): array
    {
        return $request->validate(['keyword' => 'nullable|string|max:200', 'ids' => 'nullable|array|max:100', 'ids.*' => 'required|integer|min:1|distinct']
            + $this->sourceRules() + $this->pageRules());
    }

    private function sourceRules(bool $required = false): array
    {
        return ['source' => ($required ? 'required' : 'nullable').'|array', 'source.type' => 'required_with:source|in:opportunity,bom,work_order',
            'source.id' => 'required_with:source|integer|min:1', 'source.file_id' => 'nullable|integer|min:1', 'source.position_uid' => 'nullable|string|max:80',
            'source.version' => ['nullable', function ($attribute, $value, $fail) { if (!is_scalar($value) || mb_strlen((string) $value) > 40) $fail('来源版本无效。'); }]];
    }

    private function pageRules(): array { return ['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']; }

    private function actor(Request $request): object
    {
        $actor = app(AuthContextService::class)->currentUser($request);
        abort_unless($actor, 401, '请先登录ERP。');
        return $actor;
    }
}
