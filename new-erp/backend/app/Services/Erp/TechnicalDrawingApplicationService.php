<?php

namespace App\Services\Erp;

use App\Models\Erp\TechnicalDrawing;
use App\Models\Erp\TechnicalDrawingRevision;
use App\Models\Erp\TechnicalDrawingUsage;
use App\Models\Erp\MfgWorkOrderTech;
use App\Models\Erp\MfgWorkOrder;
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
    public const SOURCES = ['opportunity' => '商机', 'drawing_request' => '绘图需求', 'bom' => 'BOM', 'work_order' => '工单'];
    public const TYPES = ['structure' => '结构图', 'part' => '零件图', 'assembly' => '装配图', 'other' => '其他技术图'];
    public const FILE_TYPES = ['technical' => '技术图', 'architecture' => '架构图', 'welding' => '焊接图', 'cutting' => '下料图'];
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
        $page = $query->orderByDesc('version_no')->paginate($filters['per_page'] ?? 10);
        $contexts = $this->sources->contexts($page->getCollection(), $actor);
        return $page->through(fn ($revision) => $this->revisionRow($revision, $actor, $contexts))->toArray();
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
        if (!empty($filters['drawing_id'])) $query->where('drawing_id', $filters['drawing_id']);
        if (!empty($filters['date_from']) || !empty($filters['date_to'])) $query->where(function ($q) use ($filters) {
            $q->where(fn ($completed) => $this->completedPeriod($completed, $filters))
                ->orWhereHas('drawing', function ($drawing) use ($filters) {
                    $drawing->whereColumn('first_confirmed_revision_id', 'erp_technical_drawing_revisions.id');
                    $this->confirmationPeriod($drawing, $filters);
                });
        });
        if (($filters['group_by'] ?? null) === 'drawing_no') {
            // 图号唯一；先按筛选内的版本聚合分页，避免展开时重复或漏掉同图号版本。
            $page = $query->select('drawing_id')->selectRaw('COUNT(*) AS revision_count, MAX(completed_date) AS latest_completed_date, MAX(id) AS latest_revision_id')
                ->groupBy('drawing_id')->orderByDesc('latest_completed_date')->orderByDesc('latest_revision_id')->paginate($filters['per_page'] ?? 10)
                ->through(fn ($row) => ['drawing_id' => (int) $row->drawing_id, 'drawing_no' => $row->drawing->drawing_no,
                    'drawing_name' => $row->drawing->drawing_name, 'revision_count' => (int) $row->revision_count]);
            return $page->toArray() + ['summary' => $this->summary($filters)];
        }
        if (!empty($filters['drawing_id'])) $query->orderByDesc('version_no');
        else $query->orderByDesc('completed_date')->orderByDesc('id');
        $page = $query->paginate($filters['per_page'] ?? 10);
        $contexts = $this->sources->contexts($page->getCollection(), $actor);
        $page->through(function ($revision) use ($actor, $filters, $contexts) {
            $completed = $this->dateInPeriod($revision->completed_date->toDateString(), $filters);
            $first = (int) $revision->drawing->first_confirmed_revision_id === (int) $revision->id;
            return $this->revisionRow($revision, $actor, $contexts) + ['counted_new' => $completed && $revision->action === 'new',
                'counted_modify' => $completed && $revision->action === 'modify',
                'counted_confirmed' => $first && $this->dateInPeriod($revision->drawing->first_confirmed_at?->toDateString(), $filters)];
        });
        return $page->toArray() + (empty($filters['drawing_id']) ? ['summary' => $this->summary($filters)] : []);
    }

    public function sourceUsages(array $filters, object $actor): array
    {
        $source = $this->sources->resolve($filters['source'], $actor);
        if ($source['type'] === 'work_order' && empty($source['file_id']) && empty($source['position_uid'])) {
            $wo = MfgWorkOrder::query()->findOrFail($source['id']);
            $confirmed = $this->sources->confirmedVersionsForWorkOrder($wo);
            if ($confirmed) {
                $usages = fn ($query) => $query->where('source_type', 'work_order')->where('source_id', $wo->id)->where('active', true);
                $page = TechnicalDrawingRevision::query()->with(['drawing', 'usages' => $usages])
                    ->where(fn ($query) => $query->whereIn('id', array_keys($confirmed))->orWhereHas('usages', $usages))
                    ->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
                $contexts = $this->sources->contexts($page->getCollection(), $actor);
                return $page->through(function ($revision) use ($wo, $confirmed, $actor, $contexts) {
                    if (isset($confirmed[$revision->id])) return $this->sources->confirmedWorkOrderRow($wo, $revision, $confirmed[$revision->id]);
                    $usage = $revision->usages->first();
                    return $this->revisionRow($revision, $actor, $contexts)
                        + ['usage_id' => $usage->id, 'source_file_id' => $usage->source_file_id, 'position_uid' => $usage->position_uid];
                })->toArray();
            }
        }
        $query = TechnicalDrawingUsage::query()->with('revision.drawing')->where('source_type', $source['type'])->where('source_id', $source['id'])->where('active', true);
        if (!empty($source['file_id'])) $query->where('source_file_id', $source['file_id']);
        if (!empty($source['position_uid'])) $query->where('position_uid', $source['position_uid']);
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
        $contexts = $this->sources->contexts($page->getCollection()->pluck('revision'), $actor);
        return $page->through(fn ($usage) => $this->revisionRow($usage->revision, $actor, $contexts)
            + ['usage_id' => $usage->id, 'source_file_id' => $usage->source_file_id, 'position_uid' => $usage->position_uid])->toArray();
    }

    public function detail(int $id, object $actor, array $filters = []): array
    {
        $drawing = $this->visibleDrawing($id, $actor);
        $revisionQuery = $drawing->revisions()->with('drawing')->orderByDesc('version_no');
        $usageQuery = $drawing->usages()->with('revision')->orderByDesc('id');
        if (!$this->canStatistics($actor)) {
            $revisionQuery->whereHas('usages', fn ($usage) => $this->sources->applyVisible($usage, $actor));
            $this->sources->applyVisible($usageQuery->getQuery(), $actor);
        }
        $revisions = $revisionQuery->paginate($filters['per_page'] ?? 10, ['*'], 'revisions_page', $filters['revisions_page'] ?? 1);
        $usages = $usageQuery->paginate($filters['per_page'] ?? 10, ['*'], 'usages_page', $filters['usages_page'] ?? 1);
        $contexts = $this->sources->contexts($revisions->getCollection()->concat($usages->getCollection()), $actor);
        $current = $drawing->revisions()->find($drawing->current_revision_id);
        $reviews = app(\App\Services\Erp\Crm\CrmDrawingRequestService::class)->versionReviews($revisions->getCollection()->concat($current ? [$current] : []), $actor);
        $currentReview = $current?->source_type === 'drawing_request' ? ($reviews[$current->source_id.':'.$current->id] ?? []) : [];
        return ['id' => $drawing->id, 'drawing_no' => $drawing->drawing_no, 'drawing_name' => $drawing->drawing_name,
            'drawing_type' => $drawing->drawing_type, 'drawing_type_label' => self::TYPES[$drawing->drawing_type] ?? $drawing->drawing_type,
            'current_version' => $current?->version_no, 'status_label' => $currentReview['review_status_label'] ?? ($current?->confirmed_at ? '已确认' : '待确认'),
            'first_confirmed_at' => $drawing->first_confirmed_at?->toDateTimeString(),
            'totals' => ['new_count' => $drawing->revisions()->where('action', 'new')->count(), 'modify_count' => $drawing->revisions()->where('action', 'modify')->count(),
                'confirmed_count' => $drawing->first_confirmed_at ? 1 : 0],
            'revisions' => $revisions->getCollection()->map(fn ($revision) => $this->revisionRow($revision, $actor, $contexts, $reviews))->values()->all(),
            'revisions_pagination' => $this->paginationRow($revisions), 'usages_pagination' => $this->paginationRow($usages),
            'usages' => $usages->getCollection()->map(fn ($usage) => ['id' => $usage->id, 'source_type' => $usage->source_type, 'source_label' => self::SOURCES[$usage->source_type],
                    'source_id' => $usage->source_id, 'source_no' => $usage->source_no, 'source_name' => $usage->source_name,
                    'source_context' => array_replace($contexts[$usage->source_type.':'.$usage->source_id] ?? [], ['source_no' => $usage->source_no, 'source_name' => $usage->source_name]),
                    'source_file_id' => $usage->source_file_id, 'position_uid' => $usage->position_uid, 'revision_id' => $usage->revision_id,
                    'version_no' => $usage->revision?->version_no, 'active' => $usage->active, 'can_view' => $this->sources->canRead($usage, $actor)])->values()->all()];
    }

    public function register(array $data, object $actor, UploadedFile|array|null $upload = null): array
    {
        $uploads = $upload instanceof UploadedFile ? [$upload] : ($upload ?? []);
        $data = $this->registrationData($data, $uploads);
        if (($data['source']['type'] ?? '') === 'drawing_request' && $data['action'] !== 'reuse') {
            abort_unless((int) $data['designer_legacy_id'] === (int) $actor->legacy_id, 403, '绘制人只能是当前交图的技术人员本人。');
        }
        $uploadHashes = array_map(fn (UploadedFile $file) => hash_file('sha256', $file->getRealPath()), $uploads);
        // 单文件沿用旧指纹；新套图的顺序和类型均属于本次不可变提交。
        $uploadHash = count($uploadHashes) === 1 ? $uploadHashes[0] : ($uploadHashes ?: null);
        $hash = hash('sha256', json_encode([$data, $uploadHash], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        if ($existing = TechnicalDrawingUsage::query()->where('request_key', $data['request_key'])->first()) return $this->replay($existing, $hash, $actor, $data, $uploadHash);
        $source = $this->sources->resolve($data['source'], $actor, true);
        $storedPaths = [];
        $storedInRevision = false;
        try {
            $uploadedFiles = [];
            if ($uploads) abort_unless(in_array($source['type'], ['bom', 'drawing_request', 'work_order'], true), 422, '请从BOM、绘图需求或工单上传技术图纸。');
            foreach ($uploads as $index => $file) {
                $storedPath = Storage::disk('local')->putFileAs('technical-drawings/'.now()->format('Ym'), $file, Str::uuid().'.'.strtolower($file->getClientOriginalExtension()));
                abort_unless($storedPath, 500, '图纸文件保存失败。');
                $storedPaths[] = $storedPath;
                $uploadedFiles[] = $this->verifiedFile(['storage_disk' => 'local', 'storage_path' => $storedPath, 'original_name' => mb_substr(basename($file->getClientOriginalName()), 0, 255),
                    'mime_type' => $file->getMimeType(), 'file_size' => (int) $file->getSize(), 'file_hash' => $uploadHashes[$index]]);
            }
            $result = DB::transaction(function () use ($data, $hash, $actor, $uploadedFiles, $uploadHash, $uploads, &$storedInRevision) {
                $storedInRevision = false;
                // 锁来源再锁图纸；与来源确认/复制钩子保持顺序，重放不重复落文件或版本。
                $source = $this->sources->resolve($data['source'], $actor, true, true);
                if ($existing = TechnicalDrawingUsage::query()->where('request_key', $data['request_key'])->lockForUpdate()->first()) return $this->replay($existing, $hash, $actor, $data, $uploadHash);
                $tech = null;
                if ($data['action'] !== 'reuse' && empty($source['file_id']) && $source['type'] === 'work_order') {
                    $tech = MfgWorkOrderTech::query()->where('work_order_id', $source['id'])->lockForUpdate()->first();
                    abort_unless($tech, 422, '这张工单不是定制工单，不能直接上传图纸。');
                }
                if ($data['action'] === 'new') {
                    // 旧手填图号可能占用流水；在同一事务中跳过，失败回滚不会卡在同一个历史号码。
                    do {
                        $drawingNo = app(DocumentNumberService::class)->next('technical_drawing', 'TZ');
                    } while (TechnicalDrawing::query()->where('drawing_no', $drawingNo)->lockForUpdate()->first(['id']));
                    $drawing = TechnicalDrawing::create(['drawing_no' => $drawingNo, 'drawing_name' => $data['drawing_name'], 'drawing_type' => $data['drawing_type'] ?? 'other',
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
                    abort_if($data['action'] === 'modify' && !$previous, 409, '图纸当前版本不存在，请刷新后重新选择。');
                    if ($previous) {
                        abort_unless($this->readableUsage($previous, $actor), 403, '没有权限读取图纸当前版本，不能登记修改。');
                        abort_unless((int) $previous->id === (int) $data['expected_revision_id'], 409, '图纸已产生新版本，请刷新后重新选择。');
                        abort_if($data['completed_date'] < $previous->completed_date->toDateString(), 422, '新版本完成日期不能早于上一版本。');
                    }
                    if (isset($data['revision_files'])) {
                        $files = $this->modifiedFiles($previous, $data['revision_files'], $uploadedFiles, $source);
                    } else {
                        $files = empty($source['file']) ? [] : [$this->verifiedFile($source['file'])];
                        $files = array_merge($files, $uploadedFiles);
                        foreach ($files as $index => &$file) $file['file_type'] = $data['file_types'][$index] ?? 'technical';
                        unset($file);
                    }
                    abort_unless($files, 422, '请先上传要登记的技术图纸。');
                    $contentHashes = array_column($files, 'file_hash');
                    abort_unless(count($contentHashes) === count(array_unique($contentHashes)), 422, '同一套图纸不能重复添加相同的文件内容。');
                    sort($contentHashes);
                    // 内容去重不含顺序和类型，重排或只改分类不能重复计算修改工作量。
                    $contentHash = count($contentHashes) === 1 ? $contentHashes[0] : hash('sha256', implode('|', $contentHashes));
                    if ($previous) abort_if(hash_equals($previous->file_hash, $contentHash), 422, '文件内容没有实际变化，无需登记修改；请替换、新增或移除具体文件。');
                    // 内容身份跨图号唯一，重复内容沿用原版，也不透露原图纸归属。
                    abort_if(TechnicalDrawingRevision::query()->where('file_hash', $contentHash)->exists(), 422, '这套文件内容已经登记，请核对并沿用已有版本。');
                    $employee = $this->activeEmployees()->where('legacy_id', $data['designer_legacy_id'])->lockForUpdate()->first(['legacy_id', 'nickname', 'username']);
                    abort_unless($employee, 422, '绘制人必须选择有效的员工账号。');
                    $person = $this->employeeRow($employee);
                    abort_unless(trim((string) $person['name']) !== '', 422, '员工账号缺少姓名，请先维护账号资料。');
                    $primary = $files[0];
                    unset($primary['file_type']);
                    $primary['file_hash'] = $contentHash;
                    $revision = TechnicalDrawingRevision::create($primary + ['drawing_id' => $drawing->id, 'version_no' => $previous ? $previous->version_no + 1 : 1,
                        'previous_revision_id' => $previous?->id, 'action' => $data['action'], 'designer_legacy_id' => $person['id'], 'designer_name' => $person['name'],
                        'department_legacy_id' => $person['department_legacy_id'], 'department_name' => $person['department_name'], 'completed_date' => $data['completed_date'],
                        'source_type' => $source['type'], 'source_id' => $source['id'], 'source_no' => $source['no'], 'source_name' => mb_substr($source['name'], 0, 200),
                        'remark' => $data['remark'] ?? null, 'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->actorName($actor)]);
                    foreach ($files as $index => $file) DB::table('erp_technical_drawing_revision_files')->insert($file + [
                        'revision_id' => $revision->id, 'sort' => $index + 1, 'created_at' => now(), 'updated_at' => now(),
                    ]);
                    $drawing->update(['current_revision_id' => $revision->id]);
                    $storedInRevision = true;
                }
                abort_if(TechnicalDrawingUsage::query()->where('source_type', $source['type'])->where('source_id', $source['id'])
                    ->where('position_uid', $source['position_uid'] ?? '')->where('revision_id', $revision->id)->exists(), 422, '该位置已经使用此图纸版本，无需重复登记。');
                $usage = TechnicalDrawingUsage::create(['drawing_id' => $drawing->id, 'revision_id' => $revision->id, 'source_type' => $source['type'], 'source_id' => $source['id'],
                    'source_file_id' => $source['file_id'] ?: 0, 'position_uid' => $source['position_uid'] ?? '', 'source_no' => $source['no'], 'source_name' => mb_substr($source['name'], 0, 200),
                    'request_key' => $data['request_key'], 'request_hash' => $hash, 'created_by_legacy_id' => $actor->legacy_id, 'created_by_name' => $this->actorName($actor)]);
                $this->sources->bind($usage, $revision, $source, $actor, $data);
                if ($tech && !$tech->tech_owner_legacy_id) $tech->update(['tech_owner_legacy_id' => $actor->legacy_id, 'tech_owner_name' => $this->actorName($actor)]);
                $usage->refresh();
                if ($usage->source_file_id) $usage->update(['source_file_key' => $usage->source_type.':'.$usage->source_id.':'.$usage->source_file_id]);
                return $this->registrationResult($usage, $revision);
            }, 3);
            if ($storedPaths && !$storedInRevision) Storage::disk('local')->delete($storedPaths);
            return $result;
        } catch (QueryException $error) {
            if ($storedPaths) Storage::disk('local')->delete($storedPaths);
            if (($error->errorInfo[1] ?? null) !== 1062) throw $error;
            if ($existing = TechnicalDrawingUsage::query()->where('request_key', $data['request_key'])->first()) return $this->replay($existing, $hash, $actor, $data, $uploadHash);
            abort(422, '图纸编号、文件内容或来源附件已经登记，请刷新核对；重复内容请沿用已有版本。');
        } catch (Throwable $error) {
            if ($storedPaths) Storage::disk('local')->delete($storedPaths);
            throw $error;
        }
    }

    public function confirm(int $revisionId, array $source, object $actor, ?string $remark = null): array
    {
        abort_if(($source['type'] ?? '') === 'drawing_request', 422, '请在绘图需求中登记客户确认的具体版本。');
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
    public function confirmLinked(string $type, int $sourceId, int $sourceFileId, object $actor, ?string $remark = null, ?int $revisionId = null): void
    {
        abort_unless(DB::transactionLevel() > 0, 500, '图纸确认必须在来源业务事务中执行。');
        $usages = TechnicalDrawingUsage::query()->where('source_type', $type)->where('source_id', $sourceId)->where('source_file_id', $sourceFileId)->where('active', true)
            ->when($revisionId, fn ($query) => $query->where('revision_id', $revisionId))->orderBy('drawing_id')->get();
        foreach ($usages as $usage) $this->markConfirmed((int) $usage->revision_id, $actor, $remark);
    }

    /** 仅供已完成来源或订单权限校验的用例读取，私有存储字段不得直接输出到接口。 */
    public function revisionFiles(TechnicalDrawingRevision $revision): array
    {
        $files = DB::table('erp_technical_drawing_revision_files')->where('revision_id', $revision->id)->orderBy('sort')->orderBy('id')->get()
            ->map(fn ($file) => (array) $file)->all();
        if (!$files) {
            $file = ['id' => 0, 'revision_id' => (int) $revision->id, 'sort' => 1, 'file_type' => 'technical'];
            foreach (['storage_disk', 'storage_path', 'original_name', 'mime_type', 'file_size', 'file_hash'] as $field) $file[$field] = $revision->{$field};
            $files = [$file];
        }
        return $files;
    }

    public function revisionFile(TechnicalDrawingRevision $revision, ?int $fileId = null): array
    {
        $files = $this->revisionFiles($revision);
        if ($fileId === null || $fileId === 0) return $files[0];
        foreach ($files as $file) if ((int) $file['id'] === $fileId) return $file;
        abort(404, '该文件不属于此图纸版本。');
    }

    public function presentFiles(TechnicalDrawingRevision $revision, ?int $usageId = null): array
    {
        return array_map(function ($file) use ($revision, $usageId) {
            $base = '/api/v1/erp/production/technical-drawings/revisions/'.$revision->id;
            $query = '?usage_id='.$usageId.'&file_id='.$file['id'];
            return ['id' => (int) $file['id'], 'revision_id' => (int) $revision->id, 'sort' => (int) $file['sort'], 'file_type' => $file['file_type'],
                'file_type_label' => self::FILE_TYPES[$file['file_type']] ?? $file['file_type'], 'original_name' => $file['original_name'],
                'mime_type' => $file['mime_type'], 'file_size' => (int) $file['file_size'], 'can_download' => (bool) $usageId,
                'download_url' => $usageId ? $base.'/download'.$query : null,
                'preview_url' => $usageId && in_array($file['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) ? $base.'/preview'.$query : null];
        }, $this->revisionFiles($revision));
    }

    public function download(int $revisionId, object $actor, ?int $usageId = null, ?int $fileId = null, bool $preview = false, ?int $workOrderId = null)
    {
        $revision = TechnicalDrawingRevision::query()->findOrFail($revisionId);
        if ($workOrderId) {
            $this->sources->resolve(['type' => 'work_order', 'id' => $workOrderId], $actor);
            $versions = $this->sources->confirmedVersionsForWorkOrder(MfgWorkOrder::query()->findOrFail($workOrderId));
            abort_unless(isset($versions[$revisionId]), 404, '该工单未沿用此客户确认版本。');
        } else abort_unless($this->readableUsage($revision, $actor, $usageId), 403, '无权下载该图纸文件。');
        $file = $this->revisionFile($revision, $fileId);
        if ($preview) abort_unless(in_array($file['mime_type'], ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'image/gif'], true), 422, '此图纸格式请下载后查看。');
        $disk = Storage::disk($file['storage_disk']);
        abort_unless($disk->exists($file['storage_path']), 404, '图纸文件不存在。');
        return $disk->response($file['storage_path'], $file['original_name'], ['Content-Type' => $file['mime_type'] ?: 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'], $preview ? 'inline' : 'attachment');
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

    private function registrationData(array $data, array $uploads): array
    {
        if (isset($data['revision_files']) && is_string($data['revision_files'])) {
            $data['revision_files'] = json_decode($data['revision_files'], true);
            abort_unless(is_array($data['revision_files']), 422, '修改图纸文件清单格式无效。');
        }
        $data = Validator::make($data, ['request_key' => 'required|string|max:64|regex:/^[A-Za-z0-9_-]+$/', 'action' => 'required|in:new,modify,reuse',
            'source' => 'required|array', 'source.type' => 'required|in:opportunity,bom,work_order,drawing_request', 'source.id' => 'required|integer|min:1',
            'source.file_id' => 'nullable|integer|min:1', 'source.position_uid' => 'nullable|string|max:80', 'source.version' => 'nullable',
            'drawing_name' => 'required_if:action,new|nullable|string|max:200',
            'drawing_type' => 'nullable|in:structure,part,assembly,other', 'drawing_id' => 'required_if:action,modify|nullable|integer|min:1',
            'revision_id' => 'required_if:action,reuse|nullable|integer|min:1', 'expected_revision_id' => 'required_if:action,modify|nullable|integer|min:1',
            'return_request_revision_id' => 'nullable|integer|min:1',
            'designer_legacy_id' => 'required_unless:action,reuse|nullable|integer|min:1', 'completed_date' => 'required_unless:action,reuse|nullable|date_format:Y-m-d|before_or_equal:today',
            'remark' => 'required_if:action,modify|nullable|string|max:1000', 'file_types' => 'sometimes|array|min:1',
            'file_types.*' => 'required|in:technical,architecture,welding,cutting',
            'revision_files' => 'sometimes|required|array|min:1', 'revision_files.*' => 'required|array:file_id,upload_index,source_file_id,file_type',
            'revision_files.*.file_id' => 'sometimes|required|integer|min:0|distinct',
            'revision_files.*.upload_index' => 'sometimes|required|integer|min:0|distinct',
            'revision_files.*.source_file_id' => 'sometimes|required|integer|min:1|distinct',
            'revision_files.*.file_type' => 'required|in:technical,architecture,welding,cutting'])->validate();
        if (isset($data['source']['version'])) abort_unless(is_scalar($data['source']['version']) && mb_strlen((string) $data['source']['version']) <= 40, 422, '来源版本无效。');
        foreach (['drawing_name', 'remark'] as $field) if (isset($data[$field])) $data[$field] = trim($data[$field]);
        if ($data['action'] === 'new') abort_unless($data['drawing_name'] !== '', 422, '请填写图纸名称。');
        if ($data['action'] === 'modify') abort_unless(trim($data['remark'] ?? '') !== '', 422, '请说明图纸实际修改的内容。');
        if (!empty($data['return_request_revision_id'])) abort_unless($data['action'] === 'modify' && $data['source']['type'] === 'drawing_request', 422, '修改意见只能在原绘图需求中按原图号交付新版。');
        if ($data['action'] === 'reuse') abort_if($uploads || !empty($data['file_types']) || !empty($data['source']['file_id']) || !empty($data['designer_legacy_id']) || !empty($data['completed_date']), 422, '沿用已有版本只选择版本和使用位置，不重复登记文件、绘制人或完成日期。');
        Validator::make(['files' => $uploads], ['files' => 'array', 'files.*' => 'required|file|max:51200'])->validate();
        abort_unless(array_is_list($uploads), 422, '图纸文件顺序无效。');
        foreach ($uploads as $file) abort_unless(in_array(strtolower($file->getClientOriginalExtension()), self::EXTENSIONS, true), 422, '图纸只支持PDF、图片、DWG/DXF、STEP或ZIP。');
        if (isset($data['revision_files'])) {
            abort_unless($data['action'] === 'modify' && !isset($data['file_types']) && array_is_list($data['revision_files']), 422, '修改文件清单只用于按上一版逐张修改，不能与整套上传分类混用。');
            $usedUploads = [];
            $usedSource = false;
            foreach ($data['revision_files'] as $index => &$entry) {
                $hasFile = array_key_exists('file_id', $entry);
                $hasUpload = array_key_exists('upload_index', $entry);
                $hasSource = array_key_exists('source_file_id', $entry);
                abort_unless(($hasFile || $hasUpload || $hasSource) && !($hasSource && ($hasFile || $hasUpload)), 422, '请逐份选择沿用旧图、替换上传或新增文件。');
                foreach (['file_id', 'upload_index', 'source_file_id'] as $field) if (isset($entry[$field])) $entry[$field] = (int) $entry[$field];
                if ($hasUpload) {
                    abort_unless(array_key_exists($entry['upload_index'], $uploads), 422, '修改文件与上传文件不对应，请重新选择。');
                    $usedUploads[] = $entry['upload_index'];
                }
                if ($hasSource) {
                    abort_unless($entry['source_file_id'] === (int) ($data['source']['file_id'] ?? 0), 422, '来源图纸附件不属于当前选择的业务文件。');
                    abort_unless($index === 0, 422, '来源图纸附件必须保留在整套文件首位。');
                    $usedSource = true;
                }
                ksort($entry);
            }
            unset($entry);
            sort($usedUploads);
            abort_unless($usedUploads === array_keys($uploads), 422, '存在未对应到图纸清单的上传文件，请重新选择。');
            abort_unless($usedSource === !empty($data['source']['file_id']), 422, '来源附件与修改文件清单不一致，请重新选择。');
        } else {
            $fileCount = count($uploads) + (empty($data['source']['file_id']) ? 0 : 1);
            if (isset($data['file_types'])) abort_unless(array_is_list($data['file_types']) && count($data['file_types']) === $fileCount, 422, '请为每份图纸选择对应的文件类型。');
            else abort_if($fileCount > 1, 422, '请为每份图纸选择对应的文件类型。');
        }
        // 只保留定义过的来源字段，避免请求中多余键影响幂等指纹。
        $data['source'] = array_intersect_key($data['source'], array_flip(['type', 'id', 'file_id', 'position_uid', 'version']));
        foreach (['drawing_id', 'revision_id', 'expected_revision_id', 'return_request_revision_id', 'designer_legacy_id'] as $field) if (isset($data[$field])) $data[$field] = (int) $data[$field];
        foreach (['id', 'file_id'] as $field) if (isset($data['source'][$field])) $data['source'][$field] = (int) $data['source'][$field];
        if (isset($data['source']['version'])) $data['source']['version'] = (string) $data['source']['version'];
        ksort($data['source']);
        ksort($data);
        return $data;
    }

    private function modifiedFiles(TechnicalDrawingRevision $previous, array $entries, array $uploads, array $source): array
    {
        // 只从已锁定的基版按文件 ID 取引用，类别或同名文件不能代替文件身份。
        $previousFiles = array_column($this->revisionFiles($previous), null, 'id');
        $files = [];
        foreach ($entries as $entry) {
            if (array_key_exists('file_id', $entry)) abort_unless(isset($previousFiles[$entry['file_id']]), 422, '要修改的文件不属于上一版本，请刷新后重新选择。');
            if (array_key_exists('upload_index', $entry)) $file = $uploads[$entry['upload_index']];
            elseif (array_key_exists('source_file_id', $entry)) $file = $this->verifiedFile($source['file'] ?? null);
            else $file = $this->verifiedFile($previousFiles[$entry['file_id']]);
            $files[] = $file + ['file_type' => $entry['file_type']];
        }
        return $files;
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

    private function replay(TechnicalDrawingUsage $usage, string $hash, object $actor, array $data, array|string|null $uploadHash): array
    {
        $matches = hash_equals($usage->request_hash, $hash);
        if (!$matches && $data['action'] === 'new') {
            // 旧登记指纹包含手填图号；仅用已落库图号重建，客户端不能借重试改写历史。
            $data['drawing_no'] = $usage->drawing()->value('drawing_no');
            ksort($data);
            $legacyHash = hash('sha256', json_encode([$data, $uploadHash], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $matches = hash_equals($usage->request_hash, $legacyHash);
        }
        abort_unless((int) $usage->created_by_legacy_id === (int) $actor->legacy_id && $matches, 422, '提交标识已经用于其他登记，请重新打开登记窗口。');
        abort_unless($this->sources->canRead($usage, $actor), 403, '无权读取该登记结果。');
        return $this->registrationResult($usage, $usage->revision()->firstOrFail());
    }

    private function registrationResult(TechnicalDrawingUsage $usage, TechnicalDrawingRevision $revision): array
    {
        $files = $this->presentFiles($revision, (int) $usage->id);
        return ['drawing_id' => (int) $revision->drawing_id, 'drawing_no' => $revision->drawing->drawing_no,
            'revision_id' => (int) $revision->id, 'usage_id' => (int) $usage->id, 'version_no' => $revision->version_no,
            'files' => $files, 'file_count' => count($files)];
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
        $this->sources->applyVisible($query->getQuery(), $actor);
        foreach ($query->orderByDesc('active')->get() as $usage) {
            if ($this->sources->canRead($usage, $actor)) return $usage;
        }
        return null;
    }

    private function revisionRow(TechnicalDrawingRevision $revision, object $actor, array $contexts, array $reviews = []): array
    {
        $drawing = $revision->drawing;
        $usage = $this->readableUsage($revision, $actor);
        $files = $this->presentFiles($revision, $usage ? (int) $usage->id : null);
        $confirmSource = null;
        foreach ($revision->usages()->where('active', true)->get() as $candidate) {
            if ($candidate->source_type === 'drawing_request') continue;
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
        $row = ['id' => $revision->id, 'drawing_id' => $revision->drawing_id, 'drawing_no' => $drawing->drawing_no, 'drawing_name' => $drawing->drawing_name,
            'drawing_type' => $drawing->drawing_type, 'drawing_type_label' => self::TYPES[$drawing->drawing_type] ?? $drawing->drawing_type,
            'version_no' => $revision->version_no, 'action' => $revision->action, 'action_label' => $revision->action === 'new' ? '新画' : '修改',
            'designer_legacy_id' => $revision->designer_legacy_id, 'designer_name' => $revision->designer_name, 'department_name' => $revision->department_name,
            'completed_date' => $revision->completed_date->toDateString(), 'source_type' => $revision->source_type, 'source_label' => self::SOURCES[$revision->source_type],
            'source_id' => $revision->source_id, 'source_no' => $revision->source_no, 'source_name' => $revision->source_name, 'original_name' => $revision->original_name,
            'source_context' => array_replace($contexts[$revision->source_type.':'.$revision->source_id] ?? [], ['source_no' => $revision->source_no, 'source_name' => $revision->source_name]),
            'mime_type' => $revision->mime_type, 'file_size' => $revision->file_size, 'files' => $files, 'file_count' => count($files),
            'remark' => $revision->remark, 'confirmed_at' => $revision->confirmed_at?->toDateTimeString(), 'confirmed_by_name' => $revision->confirmed_by_name,
            'first_confirmation' => (int) $drawing->first_confirmed_revision_id === (int) $revision->id,
            'status_label' => (int) $drawing->current_revision_id !== (int) $revision->id ? '历史版' : ($revision->confirmed_at ? '已确认' : '待确认'),
            'can_download' => (bool) $usage, 'download_url' => $usage ? '/api/v1/erp/production/technical-drawings/revisions/'.$revision->id.'/download?usage_id='.$usage->id : null,
            'preview_url' => $files[0]['preview_url'],
            'can_confirm' => !$revision->confirmed_at && $confirmSource !== null, 'confirm_source' => $confirmSource];
        return array_replace($row, $revision->source_type === 'drawing_request' ? ($reviews[$revision->source_id.':'.$revision->id] ?? []) : []);
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
