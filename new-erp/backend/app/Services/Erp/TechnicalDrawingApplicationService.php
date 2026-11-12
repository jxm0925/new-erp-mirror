<?php

namespace App\Services\Erp;

use App\Models\Erp\TechnicalDrawing;
use App\Models\Erp\TechnicalDrawingRevision;
use App\Models\Erp\TechnicalDrawingUsage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Throwable;

/** 图纸完成事实只追加；来源单据保留自己的附件、状态与可见范围。 */
final class TechnicalDrawingApplicationService
{
    public const SOURCES = ['opportunity' => '商机', 'bom' => 'BOM', 'work_order' => '工单'];
    public const TYPES = ['structure' => '结构图', 'part' => '零件图', 'assembly' => '装配图', 'other' => '其他技术图'];
    private const EXTENSIONS = ['pdf', 'png', 'jpg', 'jpeg', 'webp', 'gif', 'dwg', 'dxf', 'step', 'stp', 'zip'];

    public function __construct(private readonly AuthContextService $auth, private readonly TechnicalDrawingSourceService $sources) {}

    public function canStatistics(object $actor): bool
    {
        return $this->auth->isSuperAdmin($actor) || in_array('production.drawing_stats', $this->auth->permissionCodes($actor), true);
    }

    public function employees(array $filters, object $actor): array
    {
        $this->queryAccess($filters, $actor);
        if (empty($filters['source']) && $this->canStatistics($actor)) {
            $query = DB::table('erp_legacy_admin_users')->where(function ($visible) {
                $visible->where(fn ($active) => $active->whereNull('deleted_at')->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active']))
                    ->orWhereExists(fn ($facts) => $facts->selectRaw('1')->from('erp_technical_drawing_revisions')
                        ->whereColumn('designer_legacy_id', 'erp_legacy_admin_users.legacy_id'));
            });
        } else $query = $this->activeEmployees();
        if (!empty($filters['ids'])) $query->whereIn('legacy_id', $filters['ids']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('nickname', 'like', '%'.$keyword.'%')->orWhere('username', 'like', '%'.$keyword.'%'));
        }
        return $query->orderBy('legacy_id')->paginate($filters['per_page'] ?? 10, ['legacy_id', 'nickname', 'username'])
            ->through(fn ($user) => $this->employeeRow($user))->toArray();
    }

    public function candidates(array $filters, object $actor): array
    {
        $this->queryAccess($filters, $actor);
        $query = TechnicalDrawing::query();
        $this->visibleDrawings($query, $actor);
        if (!empty($filters['ids'])) $query->whereIn('id', $filters['ids']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('drawing_no', 'like', '%'.$keyword.'%')->orWhere('drawing_name', 'like', '%'.$keyword.'%'));
        }
        return $query->withMax('revisions', 'version_no')->orderByDesc('id')->paginate($filters['per_page'] ?? 10)
            ->through(fn ($drawing) => ['id' => $drawing->id, 'drawing_no' => $drawing->drawing_no, 'drawing_name' => $drawing->drawing_name,
                'drawing_type' => $drawing->drawing_type, 'drawing_type_label' => self::TYPES[$drawing->drawing_type] ?? $drawing->drawing_type,
                'current_version' => (int) $drawing->revisions_max_version_no, 'current_revision_id' => $drawing->current_revision_id])->toArray();
    }

    public function revisions(int $drawingId, array $filters, object $actor): array
    {
        $this->queryAccess($filters, $actor);
        $drawing = $this->visibleDrawing($drawingId, $actor);
        $query = $drawing->revisions()->with('drawing');
        if (!$this->canStatistics($actor)) $query->whereHas('usages', fn ($usage) => $this->sources->applyVisible($usage, $actor));
        if (!empty($filters['ids'])) $query->whereIn('id', $filters['ids']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where(function ($matching) use ($keyword) {
            $matching->where('designer_name', 'like', '%'.$keyword.'%')->orWhere('original_name', 'like', '%'.$keyword.'%');
            if (preg_match('/^V?(\d+)$/i', $keyword, $version)) $matching->orWhere('version_no', (int) $version[1]);
        });
        return $query->orderByDesc('version_no')->paginate($filters['per_page'] ?? 10)
            ->through(fn ($revision) => $this->revisionRow($revision, $actor))->toArray();
    }

    public function people(array $filters, object $actor): array
    {
        $this->statisticsAccess($actor);
        $events = $this->events($filters);
        $query = DB::query()->fromSub($events, 'events')->selectRaw('designer_legacy_id, MAX(designer_name) AS designer_name, MAX(department_name) AS department_name, SUM(new_count) AS new_count, SUM(modify_count) AS modify_count, SUM(confirmed_count) AS confirmed_count')
            ->groupBy('designer_legacy_id');
        $page = $query->orderBy('designer_legacy_id')->paginate($filters['per_page'] ?? 10);
        $page->through(fn ($row) => ['designer_legacy_id' => (int) $row->designer_legacy_id, 'designer_name' => $row->designer_name,
            'department_name' => $row->department_name, 'new_count' => (int) $row->new_count, 'modify_count' => (int) $row->modify_count,
            'confirmed_count' => (int) $row->confirmed_count]);
        return $page->toArray() + ['summary' => $this->summary($filters)];
    }

    public function paginate(array $filters, object $actor): array
    {
        $this->statisticsAccess($actor);
        $query = TechnicalDrawingRevision::query()->with('drawing');
        $this->revisionFilters($query, $filters);
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) $query->where(function ($q) use ($filters) {
            $q->where(fn ($completed) => $this->completedPeriod($completed, $filters))
                ->orWhereHas('drawing', function ($drawing) use ($filters) {
                    $drawing->whereColumn('first_confirmed_revision_id', 'erp_technical_drawing_revisions.id');
                    $this->confirmationPeriod($drawing, $filters);
                });
        });
        $page = $query->orderByDesc('completed_date')->orderByDesc('id')->paginate($filters['per_page'] ?? 10)
            ->through(function ($revision) use ($actor, $filters) {
                $completed = $this->dateInPeriod($revision->completed_date->toDateString(), $filters);
                $first = (int) $revision->drawing->first_confirmed_revision_id === (int) $revision->id;
                return $this->revisionRow($revision, $actor) + ['counted_new' => $completed && $revision->action === 'new',
                    'counted_modify' => $completed && $revision->action === 'modify',
                    'counted_confirmed' => $first && $this->dateInPeriod($revision->drawing->first_confirmed_at?->toDateString(), $filters)];
            });
        return $page->toArray() + ['summary' => $this->summary($filters)];
    }

    public function sourceUsages(array $filters, object $actor): array
    {
        $source = $this->sources->resolve($filters['source'], $actor);
        $query = TechnicalDrawingUsage::query()->with('revision.drawing')->where('source_type', $source['type'])->where('source_id', $source['id'])->where('active', true);
        if (!empty($source['file_id'])) $query->where('source_file_id', $source['file_id']);
        if (!empty($source['position_uid'])) $query->where('position_uid', $source['position_uid']);
        return $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10)->through(fn ($usage) => $this->revisionRow($usage->revision, $actor)
            + ['usage_id' => $usage->id, 'source_file_id' => $usage->source_file_id, 'position_uid' => $usage->position_uid])->toArray();
    }

    public function detail(int $id, object $actor, array $filters = []): array
    {
        $drawing = $this->visibleDrawing($id, $actor);
        $revisionQuery = $drawing->revisions()->with('drawing')->orderByDesc('version_no');
        $usageQuery = $drawing->usages()->with('revision')->orderByDesc('id');
        if (!$this->canStatistics($actor)) {
            $revisionQuery->whereHas('usages', fn ($usage) => $this->sources->applyVisible($usage, $actor));
            $this->sources->applyVisible($usageQuery, $actor);
        }
        $revisions = $revisionQuery->paginate($filters['per_page'] ?? 10, ['*'], 'revisions_page', $filters['revisions_page'] ?? 1);
        $usages = $usageQuery->paginate($filters['per_page'] ?? 10, ['*'], 'usages_page', $filters['usages_page'] ?? 1);
        $current = $drawing->revisions()->find($drawing->current_revision_id);
        return ['id' => $drawing->id, 'drawing_no' => $drawing->drawing_no, 'drawing_name' => $drawing->drawing_name,
            'drawing_type' => $drawing->drawing_type, 'drawing_type_label' => self::TYPES[$drawing->drawing_type] ?? $drawing->drawing_type,
            'current_version' => $current?->version_no, 'status_label' => $current?->confirmed_at ? '已确认' : '待确认',
            'first_confirmed_at' => $drawing->first_confirmed_at?->toDateTimeString(),
            'totals' => ['new_count' => $drawing->revisions()->where('action', 'new')->count(), 'modify_count' => $drawing->revisions()->where('action', 'modify')->count(),
                'confirmed_count' => $drawing->first_confirmed_at ? 1 : 0],
            'revisions' => $revisions->getCollection()->map(fn ($revision) => $this->revisionRow($revision, $actor))->values()->all(),
            'revisions_pagination' => $this->paginationRow($revisions), 'usages_pagination' => $this->paginationRow($usages),
            'usages' => $usages->getCollection()->map(fn ($usage) => ['id' => $usage->id, 'source_type' => $usage->source_type, 'source_label' => self::SOURCES[$usage->source_type],
                    'source_id' => $usage->source_id, 'source_no' => $usage->source_no, 'source_name' => $usage->source_name,
                    'source_file_id' => $usage->source_file_id, 'position_uid' => $usage->position_uid, 'revision_id' => $usage->revision_id,
                    'version_no' => $usage->revision?->version_no, 'active' => $usage->active, 'can_view' => $this->sources->canRead($usage, $actor)])->values()->all()];
    }

    public function register(array $data, object $actor, ?UploadedFile $upload = null): array
    {
        $data = $this->registrationData($data, $upload);
        $uploadHash = $upload ? hash_file('sha256', $upload->getRealPath()) : null;
        $hash = hash('sha256', json_encode([$data, $uploadHash], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($existing = TechnicalDrawingUsage::query()->where('request_key', $data['request_key'])->first()) return $this->replay($existing, $hash, $actor);
        $source = $this->sources->resolve($data['source'], $actor, true);
        $storedPath = null;
        $storedInRevision = false;
        try {
            $file = $source['file'] ?? null;
            if ($upload) {
                abort_unless($source['type'] === 'bom' && !$file && empty($source['file_id']), 422, '只有BOM图纸可以直接上传，其他来源请使用已上传的技术附件。');
                $storedPath = Storage::disk('local')->putFileAs('technical-drawings/'.now()->format('Ym'), $upload, Str::uuid().'.'.strtolower($upload->getClientOriginalExtension()));
                abort_unless($storedPath, 500, '图纸文件保存失败。');
                $file = ['storage_disk' => 'local', 'storage_path' => $storedPath, 'original_name' => mb_substr(basename($upload->getClientOriginalName()), 0, 255),
                    'mime_type' => $upload->getMimeType(), 'file_size' => (int) $upload->getSize(), 'file_hash' => $uploadHash];
            }
            if ($data['action'] !== 'reuse') $file = $this->verifiedFile($file);
            $result = DB::transaction(function () use ($data, $hash, $actor, $file, &$storedInRevision) {
                $storedInRevision = false;
                // 锁来源再锁图纸；与来源确认/复制钩子保持顺序，重放不重复落文件或版本。
                $source = $this->sources->resolve($data['source'], $actor, true, true);
                if ($existing = TechnicalDrawingUsage::query()->where('request_key', $data['request_key'])->lockForUpdate()->first()) return $this->replay($existing, $hash, $actor);
                // 内容身份跨图号唯一；重复上传不能通过另填图号增加工作量，也不透露原图纸归属。
                if ($data['action'] !== 'reuse') abort_if(TechnicalDrawingRevision::query()->where('file_hash', $file['file_hash'])->exists(), 422, '该文件内容已经登记，请核对并沿用已有版本。');
                if ($data['action'] === 'new') {
                    abort_if(TechnicalDrawing::query()->where('drawing_no', $data['drawing_no'])->exists(), 422, '图纸编号已经存在，请选择修改或沿用已有图纸。');
                    $drawing = TechnicalDrawing::create(['drawing_no' => $data['drawing_no'], 'drawing_name' => $data['drawing_name'], 'drawing_type' => $data['drawing_type'],
                        'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->actorName($actor)]);
                    $previous = null;
                } else {
                    $drawingId = $data['action'] === 'reuse'
                        ? TechnicalDrawingRevision::query()->findOrFail($data['revision_id'])->drawing_id : $data['drawing_id'];
                    $drawing = TechnicalDrawing::query()->lockForUpdate()->findOrFail($drawingId);
                    $previous = $drawing->current_revision_id ? TechnicalDrawingRevision::query()->findOrFail($drawing->current_revision_id) : null;
                    $this->visibleDrawing((int) $drawing->id, $actor);
                }
                if ($data['action'] === 'reuse') {
                    $revision = $drawing->revisions()->findOrFail($data['revision_id']);
                    abort_unless($this->readableUsage($revision, $actor), 403, '没有权限沿用这个图纸版本。');
                } else {
                    if ($previous) {
                        abort_unless($this->readableUsage($previous, $actor), 403, '没有权限读取图纸当前版本，不能登记修改。');
                        abort_unless((int) $previous->id === (int) $data['expected_revision_id'], 409, '图纸已产生新版本，请刷新后重新选择。');
                        abort_if($data['completed_date'] < $previous->completed_date->toDateString(), 422, '新版本完成日期不能早于上一版本。');
                    }
                    $employee = $this->activeEmployees()->where('legacy_id', $data['designer_legacy_id'])->lockForUpdate()->first(['legacy_id', 'nickname', 'username']);
                    abort_unless($employee, 422, '绘制人必须选择有效的员工账号。');
                    $person = $this->employeeRow($employee);
                    abort_unless(trim((string) $person['name']) !== '', 422, '员工账号缺少姓名，请先维护账号资料。');
                    $revision = TechnicalDrawingRevision::create($file + ['drawing_id' => $drawing->id, 'version_no' => $previous ? $previous->version_no + 1 : 1,
                        'previous_revision_id' => $previous?->id, 'action' => $data['action'], 'designer_legacy_id' => $person['id'], 'designer_name' => $person['name'],
                        'department_legacy_id' => $person['department_legacy_id'], 'department_name' => $person['department_name'], 'completed_date' => $data['completed_date'],
                        'source_type' => $source['type'], 'source_id' => $source['id'], 'source_no' => $source['no'], 'source_name' => mb_substr($source['name'], 0, 200),
                        'remark' => $data['remark'] ?? null, 'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->actorName($actor)]);
                    $drawing->update(['current_revision_id' => $revision->id]);
                    $storedInRevision = true;
                }
                abort_if(TechnicalDrawingUsage::query()->where('source_type', $source['type'])->where('source_id', $source['id'])
                    ->where('position_uid', $source['position_uid'] ?? '')->where('revision_id', $revision->id)->exists(), 422, '该位置已经使用此图纸版本，无需重复登记。');
                $usage = TechnicalDrawingUsage::create(['drawing_id' => $drawing->id, 'revision_id' => $revision->id, 'source_type' => $source['type'], 'source_id' => $source['id'],
                    'source_file_id' => $source['file_id'] ?: 0, 'position_uid' => $source['position_uid'] ?? '', 'source_no' => $source['no'], 'source_name' => mb_substr($source['name'], 0, 200),
                    'request_key' => $data['request_key'], 'request_hash' => $hash, 'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->actorName($actor)]);
                $this->sources->bind($usage, $revision, $source, $actor);
                $usage->refresh();
                if ($usage->source_file_id) $usage->update(['source_file_key' => $usage->source_type.':'.$usage->source_id.':'.$usage->source_file_id]);
                return $this->registrationResult($usage, $revision);
            }, 3);
            if ($storedPath && !$storedInRevision) Storage::disk('local')->delete($storedPath);
            return $result;
        } catch (QueryException $error) {
            if ($storedPath) Storage::disk('local')->delete($storedPath);
            if (($error->errorInfo[1] ?? null) !== 1062) throw $error;
            if ($existing = TechnicalDrawingUsage::query()->where('request_key', $data['request_key'])->first()) return $this->replay($existing, $hash, $actor);
            abort(422, '图纸编号、文件内容或来源附件已经登记，请刷新核对；重复内容请沿用已有版本。');
        } catch (Throwable $error) {
            if ($storedPath) Storage::disk('local')->delete($storedPath);
            throw $error;
        }
    }

    public function confirm(int $revisionId, array $source, object $actor, ?string $remark = null): array
    {
        return DB::transaction(function () use ($revisionId, $source, $actor, $remark) {
            $resolved = $this->sources->resolve($source, $actor, true, true);
            $usage = TechnicalDrawingUsage::query()->where('revision_id', $revisionId)->where('source_type', $resolved['type'])->where('source_id', $resolved['id'])->where('active', true)
                ->when(!empty($resolved['file_id']), fn ($q) => $q->where('source_file_id', $resolved['file_id']))
                ->where('position_uid', $resolved['position_uid'] ?? '')->firstOrFail();
            $this->markConfirmed($revisionId, $actor, $remark);
            return $this->registrationResult($usage, $usage->revision()->firstOrFail());
        }, 3);
    }

    /** 仅由已经完成来源鉴权并持有来源锁的正式确认用例调用。 */
    public function confirmLinked(string $type, int $sourceId, int $sourceFileId, object $actor, ?string $remark = null): void
    {
        abort_unless(DB::transactionLevel() > 0, 500, '图纸确认必须在来源业务事务中执行。');
        $usages = TechnicalDrawingUsage::query()->where('source_type', $type)->where('source_id', $sourceId)->where('source_file_id', $sourceFileId)->where('active', true)->orderBy('drawing_id')->get();
        foreach ($usages as $usage) $this->markConfirmed((int) $usage->revision_id, $actor, $remark);
    }

    public function download(int $revisionId, object $actor, ?int $usageId = null)
    {
        $revision = TechnicalDrawingRevision::query()->findOrFail($revisionId);
        abort_unless($this->readableUsage($revision, $actor, $usageId), 403, '无权下载该图纸文件。');
        $disk = Storage::disk($revision->storage_disk);
        abort_unless($disk->exists($revision->storage_path), 404, '图纸文件不存在。');
        return $disk->download($revision->storage_path, $revision->original_name, ['Content-Type' => $revision->mime_type ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff']);
    }

    private function markConfirmed(int $revisionId, object $actor, ?string $remark): void
    {
        $drawingId = TechnicalDrawingRevision::query()->findOrFail($revisionId)->drawing_id;
        $drawing = TechnicalDrawing::query()->lockForUpdate()->findOrFail($drawingId);
        $revision = $drawing->revisions()->lockForUpdate()->findOrFail($revisionId);
        $at = now();
        if (!$revision->confirmed_at) $revision->update(['confirmed_at' => $at, 'confirmed_by_legacy_id' => $actor->legacy_id,
            'confirmed_by_name' => $this->actorName($actor), 'confirmation_remark' => $remark === null ? null : mb_substr($remark, 0, 1000)]);
        // 首次确认按图号只记一次，归首次被确认版本作者；后续版本确认不改变这个归属。
        if (!$drawing->first_confirmed_at) $drawing->update(['first_confirmed_revision_id' => $revision->id, 'first_confirmed_at' => $at,
            'first_confirmed_by_legacy_id' => $actor->legacy_id, 'first_confirmed_by_name' => $this->actorName($actor)]);
    }

    private function registrationData(array $data, ?UploadedFile $upload): array
    {
        $data = Validator::make($data, ['request_key' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/', 'action' => 'required|in:new,modify,reuse',
            'source' => 'required|array', 'source.type' => 'required|in:opportunity,bom,work_order', 'source.id' => 'required|integer|min:1',
            'source.file_id' => 'nullable|integer|min:1', 'source.position_uid' => 'nullable|string|max:80', 'source.version' => 'nullable',
            'drawing_no' => 'required_if:action,new|nullable|string|max:80', 'drawing_name' => 'required_if:action,new|nullable|string|max:200',
            'drawing_type' => 'required_if:action,new|nullable|in:structure,part,assembly,other', 'drawing_id' => 'required_if:action,modify|nullable|integer|min:1',
            'revision_id' => 'required_if:action,reuse|nullable|integer|min:1', 'expected_revision_id' => 'required_if:action,modify|nullable|integer|min:1',
            'designer_legacy_id' => 'required_unless:action,reuse|nullable|integer|min:1', 'completed_date' => 'required_unless:action,reuse|nullable|date_format:Y-m-d|before_or_equal:today',
            'remark' => 'required_if:action,modify|nullable|string|max:1000'])->validate();
        if (isset($data['source']['version'])) abort_unless(is_scalar($data['source']['version']) && mb_strlen((string) $data['source']['version']) <= 40, 422, '来源版本无效。');
        foreach (['drawing_no', 'drawing_name', 'remark'] as $field) if (isset($data[$field])) $data[$field] = trim($data[$field]);
        if ($data['action'] === 'new') abort_unless($data['drawing_no'] !== '' && $data['drawing_name'] !== '', 422, '请填写图纸编号和名称。');
        if ($data['action'] === 'modify') abort_unless(trim($data['remark'] ?? '') !== '', 422, '请说明图纸实际修改的内容。');
        if ($data['action'] === 'reuse') abort_if($upload || !empty($data['source']['file_id']) || !empty($data['designer_legacy_id']) || !empty($data['completed_date']), 422, '沿用已有版本只选择版本和使用位置，不重复登记文件、绘制人或完成日期。');
        if ($upload) {
            Validator::make(['file' => $upload], ['file' => 'required|file|max:51200'])->validate();
            abort_unless(in_array(strtolower($upload->getClientOriginalExtension()), self::EXTENSIONS, true), 422, '图纸只支持PDF、图片、DWG/DXF、STEP或ZIP。');
        }
        // 只保留定义过的来源字段，避免请求中多余键影响幂等指纹。
        $data['source'] = array_intersect_key($data['source'], array_flip(['type', 'id', 'file_id', 'position_uid', 'version']));
        foreach (['drawing_id', 'revision_id', 'expected_revision_id', 'designer_legacy_id'] as $field) if (isset($data[$field])) $data[$field] = (int) $data[$field];
        foreach (['id', 'file_id'] as $field) if (isset($data['source'][$field])) $data['source'][$field] = (int) $data['source'][$field];
        if (isset($data['source']['version'])) $data['source']['version'] = (string) $data['source']['version'];
        ksort($data['source']);
        ksort($data);
        return $data;
    }

    private function verifiedFile(?array $file): array
    {
        abort_unless($file && !empty($file['storage_disk']) && !empty($file['storage_path']), 422, '请先上传要登记的技术图纸。');
        abort_unless(in_array(strtolower(pathinfo($file['original_name'] ?? '', PATHINFO_EXTENSION)), self::EXTENSIONS, true), 422, '该附件格式不能登记为技术图纸，请使用PDF、图片或CAD图纸文件。');
        $disk = Storage::disk($file['storage_disk']);
        abort_unless($disk->exists($file['storage_path']), 422, '来源图纸文件不存在。');
        if (empty($file['file_hash'])) {
            $stream = $disk->readStream($file['storage_path']);
            abort_unless(is_resource($stream), 500, '图纸文件读取失败。');
            try { $context = hash_init('sha256'); hash_update_stream($context, $stream); $file['file_hash'] = hash_final($context); }
            finally { fclose($stream); }
        }
        abort_unless(preg_match('/^[a-f0-9]{64}$/i', $file['file_hash']), 422, '图纸文件校验值无效。');
        $file['file_hash'] = strtolower($file['file_hash']);
        return array_intersect_key($file, array_flip(['storage_disk', 'storage_path', 'original_name', 'mime_type', 'file_size', 'file_hash']));
    }

    private function replay(TechnicalDrawingUsage $usage, string $hash, object $actor): array
    {
        abort_unless((int) $usage->created_by_legacy_id === (int) $actor->legacy_id && hash_equals($usage->request_hash, $hash), 422, '提交标识已经用于其他登记，请重新打开登记窗口。');
        abort_unless($this->sources->canRead($usage, $actor), 403, '无权读取该登记结果。');
        return $this->registrationResult($usage, $usage->revision()->firstOrFail());
    }

    private function registrationResult(TechnicalDrawingUsage $usage, TechnicalDrawingRevision $revision): array
    {
        return ['drawing_id' => (int) $revision->drawing_id, 'revision_id' => (int) $revision->id, 'usage_id' => (int) $usage->id, 'version_no' => $revision->version_no];
    }

    private function visibleDrawings(Builder $query, object $actor): void
    {
        if (!$this->canStatistics($actor)) $query->whereHas('usages', fn ($usage) => $this->sources->applyVisible($usage, $actor));
    }

    private function visibleDrawing(int $id, object $actor): TechnicalDrawing
    {
        $query = TechnicalDrawing::query();
        $this->visibleDrawings($query, $actor);
        return $query->findOrFail($id);
    }

    private function readableUsage(TechnicalDrawingRevision $revision, object $actor, ?int $usageId = null): ?TechnicalDrawingUsage
    {
        $query = $revision->usages()->when($usageId, fn ($q) => $q->whereKey($usageId));
        $this->sources->applyVisible($query, $actor);
        foreach ($query->orderByDesc('active')->get() as $usage) {
            if ($this->sources->canRead($usage, $actor)) return $usage;
        }
        return null;
    }

    private function revisionRow(TechnicalDrawingRevision $revision, object $actor): array
    {
        $drawing = $revision->drawing;
        $usage = $this->readableUsage($revision, $actor);
        $confirmSource = null;
        foreach ($revision->usages()->where('active', true)->get() as $candidate) {
            if (!$this->sources->canRead($candidate, $actor)) continue;
            try {
                $source = ['type' => $candidate->source_type, 'id' => $candidate->source_id, 'file_id' => $candidate->source_file_id ?: null, 'position_uid' => $candidate->position_uid];
                $resolved = $this->sources->resolve($source, $actor);
                $source['version'] = $resolved['version'] ?? null;
                $this->sources->resolve($source, $actor, true);
                $confirmSource = $source;
                break;
            } catch (\Symfony\Component\HttpKernel\Exception\HttpExceptionInterface|\Illuminate\Database\Eloquent\ModelNotFoundException|\Illuminate\Validation\ValidationException $error) {
                continue;
            }
        }
        return ['id' => $revision->id, 'drawing_id' => $revision->drawing_id, 'drawing_no' => $drawing->drawing_no, 'drawing_name' => $drawing->drawing_name,
            'drawing_type' => $drawing->drawing_type, 'drawing_type_label' => self::TYPES[$drawing->drawing_type] ?? $drawing->drawing_type,
            'version_no' => $revision->version_no, 'action' => $revision->action, 'action_label' => $revision->action === 'new' ? '新画' : '修改',
            'designer_legacy_id' => $revision->designer_legacy_id, 'designer_name' => $revision->designer_name, 'department_name' => $revision->department_name,
            'completed_date' => $revision->completed_date->toDateString(), 'source_type' => $revision->source_type, 'source_label' => self::SOURCES[$revision->source_type],
            'source_id' => $revision->source_id, 'source_no' => $revision->source_no, 'source_name' => $revision->source_name, 'original_name' => $revision->original_name,
            'mime_type' => $revision->mime_type, 'file_size' => $revision->file_size,
            'remark' => $revision->remark, 'confirmed_at' => $revision->confirmed_at?->toDateTimeString(), 'confirmed_by_name' => $revision->confirmed_by_name,
            'first_confirmation' => (int) $drawing->first_confirmed_revision_id === (int) $revision->id,
            'status_label' => (int) $drawing->current_revision_id !== (int) $revision->id ? '历史版' : ($revision->confirmed_at ? '已确认' : '待确认'),
            'can_download' => (bool) $usage, 'download_url' => $usage ? '/api/v1/erp/production/technical-drawings/revisions/'.$revision->id.'/download?usage_id='.$usage->id : null,
            'can_confirm' => !$revision->confirmed_at && $confirmSource !== null, 'confirm_source' => $confirmSource];
    }

    private function activeEmployees()
    {
        return DB::table('erp_legacy_admin_users')->whereNull('deleted_at')->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active']);
    }

    private function employeeRow(object $user): array
    {
        $department = DB::table('erp_department_users as du')->join('erp_departments as d', 'd.legacy_id', '=', 'du.department_legacy_id')
            ->where('du.user_legacy_id', $user->legacy_id)->orderBy('du.id')->first(['d.legacy_id', 'd.name']);
        return ['id' => (int) $user->legacy_id, 'name' => $user->nickname ?: $user->username,
            'department_legacy_id' => $department ? (int) $department->legacy_id : null, 'department_name' => $department?->name];
    }

    private function queryAccess(array $filters, object $actor): void
    {
        if ($this->canStatistics($actor)) return;
        abort_unless(!empty($filters['source']), 403, '请从有权限的业务资料打开图纸登记。');
        $this->sources->resolve($filters['source'], $actor, true);
    }

    private function statisticsAccess(object $actor): void
    {
        abort_unless($this->canStatistics($actor), 403, '没有查看技术绘图统计的权限。');
    }

    private function revisionFilters($query, array $filters): void
    {
        if (!empty($filters['designer_legacy_id'])) $query->where('designer_legacy_id', $filters['designer_legacy_id']);
        if (!empty($filters['source_type'])) $query->where('source_type', $filters['source_type']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->whereExists(fn ($drawing) => $drawing->selectRaw('1')->from('erp_technical_drawings as keyword_drawing')
            ->whereColumn('keyword_drawing.id', 'erp_technical_drawing_revisions.drawing_id')
            ->where(fn ($q) => $q->where('keyword_drawing.drawing_no', 'like', '%'.$keyword.'%')->orWhere('keyword_drawing.drawing_name', 'like', '%'.$keyword.'%')));
    }

    private function completedPeriod($query, array $filters): void
    {
        if (!empty($filters['date_from'])) $query->where('completed_date', '>=', $filters['date_from']);
        if (!empty($filters['date_to'])) $query->where('completed_date', '<=', $filters['date_to']);
    }

    private function confirmationPeriod($query, array $filters, string $column = 'first_confirmed_at'): void
    {
        $query->whereNotNull($column);
        if (!empty($filters['date_from'])) $query->where($column, '>=', $filters['date_from'].' 00:00:00');
        if (!empty($filters['date_to'])) $query->where($column, '<', \Carbon\CarbonImmutable::parse($filters['date_to'], config('app.timezone'))->addDay()->toDateString().' 00:00:00');
    }

    private function events(array $filters)
    {
        $completed = DB::table('erp_technical_drawing_revisions')->selectRaw("designer_legacy_id, designer_name, department_name, IF(action = 'new', 1, 0) AS new_count, IF(action = 'modify', 1, 0) AS modify_count, 0 AS confirmed_count");
        $this->revisionFilters($completed, $filters);
        $this->completedPeriod($completed, $filters);
        $confirmed = DB::table('erp_technical_drawing_revisions')->join('erp_technical_drawings as drawing', 'drawing.first_confirmed_revision_id', '=', 'erp_technical_drawing_revisions.id')
            ->selectRaw('designer_legacy_id, designer_name, department_name, 0 AS new_count, 0 AS modify_count, 1 AS confirmed_count');
        $this->revisionFilters($confirmed, $filters);
        $this->confirmationPeriod($confirmed, $filters, 'drawing.first_confirmed_at');
        return $completed->unionAll($confirmed);
    }

    private function summary(array $filters): array
    {
        $row = DB::query()->fromSub($this->events($filters), 'events')->selectRaw('COALESCE(SUM(new_count), 0) AS new_count, COALESCE(SUM(modify_count), 0) AS modify_count, COALESCE(SUM(confirmed_count), 0) AS confirmed_count, COUNT(DISTINCT designer_legacy_id) AS people_count')->first();
        return ['new_count' => (int) $row->new_count, 'modify_count' => (int) $row->modify_count, 'confirmed_count' => (int) $row->confirmed_count, 'people_count' => (int) $row->people_count];
    }

    private function dateInPeriod(?string $date, array $filters): bool
    {
        return $date !== null && (empty($filters['date_from']) || $date >= $filters['date_from']) && (empty($filters['date_to']) || $date <= $filters['date_to']);
    }

    private function actorName(object $actor): string { return (string) (($actor->nickname ?? null) ?: ($actor->username ?? '')); }

    private function paginationRow($page): array
    {
        return ['current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()];
    }
}
