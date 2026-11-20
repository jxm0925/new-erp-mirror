<?php

namespace Database\Seeders;

use App\Models\Erp\{Item, ItemCategory, Location, Unit, Warehouse};
use App\Services\Erp\{ItemCategoryApplicationService, MasterDataApplicationService, MaterialPolicyApplicationService, ThirdPartyServiceConfigService};
use Database\Seeders\Support\SeedSafety;
use Illuminate\Database\Seeder;
use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\{DB, Storage, Validator};
use League\Flysystem\UnableToReadFile;
use OSS\Core\OssException;
use RuntimeException;
use ZipArchive;

/** 最终确认的物料、分类和摆放清单；不删除旧档案、不重编已有档案、不写库存数量或成本。 */
class ErpWarehouseWorkbookMaterialSeeder extends Seeder
{
    private array $manifest;
    private array $rows;
    private array $categoryRows;
    private array $imageUrls = [];

    /** 只准备正式仓库、库位和所选原图，不创建物料。 */
    public function prepareAssets(): array
    {
        $summary = $this->preflight(true);
        DB::transaction(function (): void {
            $this->reserveCounters(['warehouse', 'location']);
            $warehouse = $this->warehouse(true);
            foreach ($this->manifest['locations'] as $row) $this->location($row, $warehouse, true);
        });
        $this->uploadPhotos();
        return $summary;
    }

    public function run(): void
    {
        $summary = $this->preflight();
        $this->uploadPhotos();
        // 图片按内容寻址；数据库失败时保留已核验原图，重跑不会重复上传或删除共享对象。
        DB::transaction(function (): void {
            $this->reserveCounters(['warehouse', 'location', 'item_category', 'item']);
            $warehouse = $this->warehouse(true);
            $unit = $this->unit(true);
            $categories = $this->categories(true);
            $locations = [];
            foreach ($this->manifest['locations'] as $row) $locations[$row['location_code']] = $this->location($row, $warehouse, true);
            foreach ($this->rows as $row) {
                $item = $this->item($row, $warehouse, $categories[$row['category_code']], $unit, true);
                foreach ($row['location_codes'] as $code) {
                    SeedSafety::insertMissing('erp_item_storage_locations', ['item_id' => $item->id, 'location_id' => $locations[$code]->id], []);
                }
            }
        });
        $this->command?->info('已补齐 '.$summary['materials'].' 种原材料、'.$summary['placements'].' 项摆放关联；未写库存数量和成本。');
    }

    /** 只读预检；不会占号、上传图片、修改分类或写入业务记录。 */
    public function preflight(bool $assetsOnly = false): array
    {
        SeedSafety::assertSchema();
        $contents = file_get_contents(__DIR__.'/data/warehouse_raw_materials.json');
        if ($contents === false) throw new RuntimeException('无法读取最终仓库物料清单。');
        $this->manifest = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        Validator::make($this->manifest, [
            'schema_version' => 'required|integer|in:2',
            'source.file_name' => 'required|string', 'source.sha256' => 'required|regex:/^[a-f0-9]{64}$/D',
            'warehouse.code' => 'required|regex:/^WH\d{5}$/D', 'warehouse.name' => 'required|string|max:120',
            'warehouse.warehouse_type' => 'required|in:general,raw_material', 'warehouse.management_scope' => 'required|in:factory', 'warehouse.status' => 'required|in:enabled',
            'locations' => 'required|array|size:23',
            'locations.*.location_code' => 'required|distinct|regex:/^LOC\d{6}$/D',
            'locations.*.location_name' => 'required|string|max:120|distinct',
            'locations.*.warehouse_code' => 'required|string', 'locations.*.location_purpose' => 'required|in:general',
            'locations.*.status' => 'required|in:enabled', 'locations.*.allow_mixed' => 'required|boolean',
            'categories' => 'required|array|min:1',
            'categories.*.category_code' => 'required|distinct|regex:/^IC\d{6}$/D',
            'categories.*.category_name' => 'required|string|max:120', 'categories.*.parent_code' => 'nullable|string',
            'categories.*.category_type' => 'required|in:item', 'categories.*.management_scope' => 'required|in:factory',
            'categories.*.status' => 'required|in:enabled,disabled', 'categories.*.sort_order' => 'required|integer|min:0',
            'materials' => 'required|array|size:241',
            'materials.*.item_code' => 'required|distinct|regex:/^ITEM\d{8}$/D',
            'materials.*.item_name' => 'required|string|max:160', 'materials.*.spec' => 'nullable|string|max:255',
            'materials.*.brand' => 'nullable|string|max:100', 'materials.*.unit_code' => 'required|in:EA',
            'materials.*.category_code' => 'required|string',
            'materials.*.location_codes' => 'required|array|min:1', 'materials.*.location_codes.*' => 'required|string',
            'materials.*.photos' => 'required|array|size:1',
            'materials.*.photos.*.archive_path' => ['required', 'regex:~^xl/media/[^/\\\\]+\.(?:jpe?g|png|gif|webp)$~iD'],
            'materials.*.photos.*.sha256' => 'required|regex:/^[a-f0-9]{64}$/D',
            'materials.*.photos.*.size_bytes' => 'required|integer|min:1|max:5242880',
        ])->validate();
        $this->rows = $this->manifest['materials'];
        usort($this->rows, fn ($left, $right) => strcmp($left['item_code'], $right['item_code']));
        $definitions = array_column($this->manifest['categories'], null, 'category_code');
        $this->categoryRows = [];
        while ($definitions) {
            $before = count($definitions);
            foreach ($definitions as $code => $row) {
                $parent = $row['parent_code'] ?? null;
                if ($parent !== null && !isset($this->categoryRows[$parent])) continue;
                if ($parent !== null && $row['status'] === 'enabled' && $this->categoryRows[$parent]['status'] !== 'enabled') throw new RuntimeException('启用类目的父级未启用：'.$code);
                $this->categoryRows[$code] = $row;
                unset($definitions[$code]);
            }
            if ($before === count($definitions)) throw new RuntimeException('分类清单缺少父级或存在循环。');
        }
        $locationCodes = array_column($this->manifest['locations'], 'location_code');
        foreach ($this->manifest['locations'] as $row) {
            if ($row['warehouse_code'] !== $this->manifest['warehouse']['code']) throw new RuntimeException('库位的正式仓库编号不符：'.$row['location_code']);
        }
        $placements = 0;
        $photos = [];
        $names = [];
        foreach ($this->rows as $index => $row) {
            if ($row['item_code'] !== 'ITEM'.str_pad((string) ($index + 1), 8, '0', STR_PAD_LEFT)) throw new RuntimeException('最终物料编码必须从 ITEM00000001 连续排列。');
            $category = $this->categoryRows[$row['category_code']] ?? null;
            if (!$category || $category['status'] !== 'enabled' || in_array($row['category_code'], array_column($this->categoryRows, 'parent_code'), true)) throw new RuntimeException('物料未指向启用的末级分类：'.$row['item_code']);
            if (count($row['location_codes']) !== count(array_unique($row['location_codes'])) || array_diff($row['location_codes'], $locationCodes)) throw new RuntimeException('物料库位编号重复或未列入正式清单：'.$row['item_code']);
            $identity = json_encode([$row['item_name'], $row['spec'] ?? ''], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            if (isset($names[$identity])) throw new RuntimeException('最终清单仍有同名同规格物料：'.$row['item_name']);
            $names[$identity] = true;
            $placements += count($row['location_codes']);
            $photo = $row['photos'][0];
            if (isset($photos[$photo['sha256']]) && $photos[$photo['sha256']] !== $photo['size_bytes']) throw new RuntimeException('相同图片哈希的文件大小不一致。');
            $photos[$photo['sha256']] = $photo['size_bytes'];
        }
        if ($placements !== 242) throw new RuntimeException('最终清单必须保留 242 项摆放关联。');
        $warehouse = $this->warehouse(false);
        foreach ($this->manifest['locations'] as $row) $this->location($row, $warehouse, false);
        $this->reserveCounters($assetsOnly ? ['warehouse', 'location'] : ['warehouse', 'location', 'item_category', 'item'], false);
        if (!$assetsOnly) {
            $unit = $this->unit(false);
            $categories = $this->categories(false);
            foreach ($this->rows as $row) {
                $category = $categories[$row['category_code']];
                if ($category && $category->children()->where('category_type', 'item')->exists()) throw new RuntimeException('物料分类存在子类目，不能直接挂物料：'.$row['category_code']);
                $this->item($row, $warehouse, $category, $unit, false);
            }
        }
        $config = config('filesystems.disks.oss', []);
        if (($config['driver'] ?? null) !== 'oss') throw new RuntimeException('本批图片必须使用真实 OSS 存储。');
        foreach (['access_key_id', 'access_key_secret', 'bucket', 'endpoint'] as $field) if (blank($config[$field] ?? null)) throw new RuntimeException('OSS 配置不完整：'.$field);
        $this->transferDisk();
        return ['materials' => count($this->rows), 'placements' => $placements, 'locations' => count($locationCodes), 'categories' => count($this->categoryRows), 'unique_photos' => count($photos)];
    }

    private function warehouse(bool $write): ?Warehouse
    {
        $row = $this->manifest['warehouse'];
        $query = Warehouse::query()->where('warehouse_code', $row['code'])->orWhere('warehouse_name', $row['name']);
        $matches = $write ? $query->lockForUpdate()->get() : $query->get();
        if ($matches->count() > 1) throw new RuntimeException('原材料仓名称或编码存在歧义。');
        $expected = ['warehouse_code' => $row['code'], 'warehouse_name' => $row['name'], 'warehouse_type' => $row['warehouse_type'], 'management_scope' => 'factory', 'status' => 'enabled'];
        if ($record = $matches->first()) {
            $this->assertFields($record, $expected, '仓库 '.$row['code']);
            return $record;
        }
        return $write ? app(MasterDataApplicationService::class)->create('warehouses', Warehouse::class, $expected, null) : null;
    }

    private function location(array $row, ?Warehouse $warehouse, bool $write): ?Location
    {
        $query = Location::query()->where('location_code', $row['location_code']);
        if ($warehouse) $query->orWhere(fn ($q) => $q->where('warehouse_id', $warehouse->id)->where('location_name', $row['location_name']));
        $matches = $write ? $query->lockForUpdate()->get() : $query->get();
        if ($matches->count() > 1) throw new RuntimeException('库位名称或编码存在歧义：'.$row['location_code']);
        $expected = ['location_code' => $row['location_code'], 'location_name' => $row['location_name'], 'warehouse_id' => $warehouse?->id,
            'location_purpose' => 'general', 'status' => 'enabled', 'allow_mixed' => (int) $row['allow_mixed']];
        if ($record = $matches->first()) {
            $this->assertFields($record, $expected, '库位 '.$row['location_code']);
            return $record;
        }
        return $write ? app(MasterDataApplicationService::class)->create('locations', Location::class, $expected, null) : null;
    }

    private function unit(bool $write): ?Unit
    {
        $query = Unit::query()->where('unit_code', 'EA');
        $record = $write ? $query->lockForUpdate()->first() : $query->first();
        if ($record) {
            $this->assertFields($record, ['unit_name' => '个', 'unit_type' => 'quantity', 'status' => 'enabled', 'is_legacy' => 0, 'allow_decimal' => 0, 'decimal_places' => 0], '单位 EA');
            return $record;
        }
        return $write ? app(MasterDataApplicationService::class)->create('units', Unit::class, [
            'unit_code' => 'EA', 'unit_name' => '个', 'symbol' => '个', 'unit_type' => 'quantity', 'allow_decimal' => false,
            'decimal_places' => 0, 'is_base' => false, 'is_legacy' => false, 'sort_order' => 70, 'status' => 'enabled',
        ], null) : null;
    }

    private function categories(bool $write): array
    {
        $records = [];
        foreach ($this->categoryRows as $code => $row) {
            $query = ItemCategory::query()->where('category_code', $code);
            $record = $write ? $query->lockForUpdate()->first() : $query->first();
            $parent = $row['parent_code'] ?? null;
            $expected = ['category_code' => $code, 'category_name' => $row['category_name'], 'category_type' => 'item',
                'management_scope' => 'factory', 'parent_id' => $parent === null ? null : $records[$parent]?->id, 'sort_order' => $row['sort_order']];
            if ($record) {
                $this->assertFields($record, $expected, '分类 '.$code);
                if (!in_array($record->status, ['enabled', 'disabled'], true)) throw new RuntimeException('分类状态异常：'.$code);
            } else {
                if (ItemCategory::query()->where('category_name', $row['category_name'])->where('category_type', 'item')->where('management_scope', 'factory')->where('parent_id', $expected['parent_id'])->exists()) throw new RuntimeException('同名分类使用其他编号：'.$row['category_name']);
                if ($write) $record = app(ItemCategoryApplicationService::class)->create($expected + ['status' => $row['status']]);
            }
            if ($write && $row['status'] === 'enabled' && $record->status !== 'enabled') $record = app(ItemCategoryApplicationService::class)->enable($record);
            $records[$code] = $record;
        }
        // 先停用末级，再停用父级，沿用分类服务对子类目的保护。
        foreach (array_reverse($this->categoryRows, true) as $code => $row) {
            $record = $records[$code];
            if ($row['status'] !== 'disabled' || !$record || $record->status !== 'enabled') continue;
            $disabledCodes = array_keys(array_filter($this->categoryRows, fn ($definition) => $definition['status'] === 'disabled'));
            if ($record->children()->where('category_type', 'item')->where('status', 'enabled')->whereNotIn('category_code', $disabledCodes)->exists()) {
                throw new RuntimeException('待停用分类仍有清单之外的启用子类目：'.$code);
            }
            // 旧树只在未被实际业务使用时停用；工艺适用表没有分类外键，也必须显式核对。
            foreach (['erp_items' => 'category_id', 'erp_products' => 'category_id',
                'erp_supplier_category_capabilities' => 'item_category_id', 'erp_production_routing_applicabilities' => 'item_category_id',
                'erp_production_performance_bindings' => 'category_id'] as $table => $field) {
                if (DB::table($table)->where($field, $record->id)->exists()) throw new RuntimeException('待停用分类仍有业务引用，保留启用状态：'.$code.' '.$table);
            }
            if ($write) $records[$code] = app(ItemCategoryApplicationService::class)->disable($record);
        }
        return $records;
    }

    private function item(array $row, ?Warehouse $warehouse, ?ItemCategory $category, ?Unit $unit, bool $write): ?Item
    {
        $query = Item::query()->where('item_code', $row['item_code'])->orWhere(function ($q) use ($row): void {
            $q->where('item_name', $row['item_name']);
            if (($row['spec'] ?? '') === '') $q->where(fn ($spec) => $spec->whereNull('spec')->orWhere('spec', ''));
            else $q->where('spec', $row['spec']);
        });
        $matches = $write ? $query->lockForUpdate()->get() : $query->get();
        if ($matches->count() > 1) throw new RuntimeException('物料编码和名称指向不同档案，请先完成旧档案整理：'.$row['item_code']);
        $url = $this->imageUrls[$row['photos'][0]['sha256']] ?? Storage::disk('oss')->url($this->photoPath($row['photos'][0]));
        $expected = ['item_code' => $row['item_code'], 'item_name' => $row['item_name'], 'spec' => $row['spec'] ?? null,
            'item_type' => 'raw_material', 'management_scope' => 'factory', 'status' => 'enabled', 'unit_id' => $unit?->id,
            'category_id' => $category?->id, 'brand' => $row['brand'] ?? null, 'default_warehouse_id' => $warehouse?->id,
            'image_url' => $url, 'is_stock_item' => 1];
        if ($record = $matches->first()) {
            $this->assertFields($record, $expected, '物料 '.$row['item_code']);
            $extra = DB::table('erp_item_storage_locations as placement')->join('erp_locations as location', 'location.id', '=', 'placement.location_id')
                ->where('placement.item_id', $record->id)->whereNotIn('location.location_code', $row['location_codes'])->exists();
            if ($extra) throw new RuntimeException('物料另有清单之外的存放位置，保留原关联并停止：'.$row['item_code']);
        } elseif ($write) {
            $record = app(MasterDataApplicationService::class)->create('items', Item::class, $expected + [
                'is_purchase_item' => true, 'is_production_item' => false, 'manufacturing_strategy' => 'purchase',
                'material_management_mode' => 'quantity', 'cutting_mode' => 'none',
            ], null);
        }
        if ($record) {
            $policy = $record->materialPolicies()->where('status', 'active')->get();
            if ($policy->count() > 1) throw new RuntimeException('物料存在多个生效策略：'.$row['item_code']);
            if ($policy->isNotEmpty()) $this->assertFields($policy->first(), $this->policyParameters(), '物料策略 '.$row['item_code']);
            elseif ($record->materialPolicies()->exists()) throw new RuntimeException('物料没有可用的生效策略：'.$row['item_code']);
            elseif ($write) app(MaterialPolicyApplicationService::class)->activate($record, $this->policyParameters(), null);
        }
        return $record;
    }

    private function policyParameters(): array
    {
        return ['template_code' => 'inventory_material', 'is_stock_managed' => true, 'inventory_management_mode' => 'standard',
            'requires_custodian' => false, 'is_returnable' => false, 'requires_capitalization' => false,
            'serial_tracking_mode' => 'none', 'production_execution_mode' => 'quantity',
            'serial_generation_stage' => 'before_finished_goods_posting', 'serial_generation_routing_operation_id' => null,
            'post_purchase_action' => 'inventory_receipt', 'consumption_confirmation_mode' => 'none',
            'future_route' => 'inventory', 'future_bearer_type' => 'company'];
    }

    private function assertFields(object $record, array $expected, string $label): void
    {
        foreach ($expected as $field => $value) {
            $actual = $record->{$field};
            if (is_bool($actual)) $actual = (int) $actual;
            if (is_bool($value)) $value = (int) $value;
            if ((string) $actual !== (string) $value) throw new RuntimeException($label.' 的 '.$field.' 与最终清单不符；保留现有记录并停止。');
        }
    }

    private function reserveCounters(array $types, bool $write = true): void
    {
        $definitions = ['warehouse' => ['erp_warehouses', 'warehouse_code', 'WH', 5, [$this->manifest['warehouse']['code']]],
            'location' => ['erp_locations', 'location_code', 'LOC', 6, array_column($this->manifest['locations'], 'location_code')],
            'item_category' => ['erp_item_categories', 'category_code', 'IC', 6, array_column($this->manifest['categories'], 'category_code')],
            'item' => ['erp_items', 'item_code', 'ITEM', 8, array_column($this->rows, 'item_code')]];
        foreach ($types as $type) {
            [$table, $field, $prefix, $digits, $codes] = $definitions[$type];
            $query = DB::table('erp_document_number_rules')->where('document_type', $type);
            $rule = $write ? $query->lockForUpdate()->first() : $query->first();
            if (!$rule || !$rule->enabled || $rule->prefix !== $prefix || $rule->date_format !== '' || $rule->reset_cycle !== 'none' || (int) $rule->sequence_length !== $digits) throw new RuntimeException('正式编号规则与固定清单不兼容：'.$type);
            if (!$write) continue;
            $minimum = 0;
            foreach (array_merge($codes, DB::table($table)->pluck($field)->all()) as $code) {
                if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/D', $code, $match)) $minimum = max($minimum, (int) $match[1]);
            }
            SeedSafety::restoreCounterMinimum(['document_type' => $type, 'prefix' => $prefix, 'date_format' => '', 'reset_cycle' => 'none', 'number_date' => '1970-01-01', 'minimum_sequence' => $minimum]);
        }
    }

    private function photoPath(array $photo): string
    {
        return 'erp/master/item-images/warehouse-workbook/'.$photo['sha256'].'.'.strtolower(pathinfo($photo['archive_path'], PATHINFO_EXTENSION));
    }

    private function transferDisk(): FilesystemAdapter
    {
        $runtime = config('filesystems.disks.oss', []);
        if (empty($runtime['is_cname'])) return Storage::disk('oss');
        $origin = trim((string) (app(ThirdPartyServiceConfigService::class)->getAliyunOssConfig()['config']['endpoint'] ?? ''));
        $originHost = parse_url(str_contains($origin, '://') ? $origin : 'https://'.$origin, PHP_URL_HOST);
        $runtimeEndpoint = (string) ($runtime['endpoint'] ?? '');
        $runtimeHost = parse_url(str_contains($runtimeEndpoint, '://') ? $runtimeEndpoint : 'https://'.$runtimeEndpoint, PHP_URL_HOST);
        if (!$originHost || !preg_match('/^oss-[a-z0-9-]+\.aliyuncs\.com$/D', $originHost) || $originHost === $runtimeHost) {
            throw new RuntimeException('OSS 自定义域名模式缺少可确认的官方源站 Endpoint，停止图片传输。');
        }
        // CDN 可能缓存缺失响应；传输及哈希核对直连源站，展示链接仍沿用正式 OSS 磁盘。
        return Storage::build(array_replace($runtime, ['endpoint' => $originHost, 'domain' => null, 'is_cname' => false, 'throw' => true]));
    }

    private function uploadPhotos(): void
    {
        $publicDisk = Storage::disk('oss');
        $disk = $this->transferDisk();
        $zip = null;
        $this->imageUrls = [];
        try {
            foreach ($this->rows as $row) foreach ($row['photos'] as $photo) {
                if (isset($this->imageUrls[$photo['sha256']])) continue;
                $path = $this->photoPath($photo);
                try {
                    $remoteBytes = $disk->getDriver()->read($path);
                } catch (UnableToReadFile $exception) {
                    $cause = $exception->getPrevious();
                    while ($cause && !($cause instanceof OssException)) $cause = $cause->getPrevious();
                    // 使用普通 GET 保留 OSS 错误响应体；流式 GET 会丢失 NoSuchKey，HEAD 又可能返回 403。
                    if (!($cause instanceof OssException) || (int) $cause->getHTTPStatus() !== 404 || $cause->getErrorCode() !== 'NoSuchKey') throw $exception;
                    $remoteBytes = null;
                }
                if ($remoteBytes === null) {
                    if ($zip === null) {
                        $source = getenv('ERP_WAREHOUSE_WORKBOOK') ?: ($this->manifest['source']['path'] ?? 'D:/desktop/'.$this->manifest['source']['file_name']);
                        if (!is_file($source) || hash_file('sha256', $source) !== $this->manifest['source']['sha256']) {
                            throw new RuntimeException('OSS 缺少原图，需要与清单 SHA256 一致的原始工作簿：'.$source);
                        }
                        $zip = new ZipArchive();
                        if ($zip->open($source) !== true) throw new RuntimeException('无法读取原始工作簿压缩包。');
                    }
                    $bytes = $zip->getFromName($photo['archive_path']);
                    $mime = $bytes === false ? null : ((getimagesizefromstring($bytes) ?: [])['mime'] ?? null);
                    if ($bytes === false || strlen($bytes) !== $photo['size_bytes'] || hash('sha256', $bytes) !== $photo['sha256']
                        || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
                        throw new RuntimeException('工作簿原图的大小、哈希或格式不符：'.$photo['archive_path']);
                    }
                    if (!$disk->put($path, $bytes, ['visibility' => 'public', 'headers' => ['Content-Type' => $mime, 'x-oss-forbid-overwrite' => 'true']])) {
                        throw new RuntimeException('OSS 原图上传失败：'.$path);
                    }
                    $remoteBytes = $disk->getDriver()->read($path);
                }
                if (strlen($remoteBytes) !== $photo['size_bytes'] || hash('sha256', $remoteBytes) !== $photo['sha256']) {
                    throw new RuntimeException('OSS 原图哈希不符；保留既有对象并停止：'.$path);
                }
                $url = $publicDisk->url($path);
                if (!filter_var($url, FILTER_VALIDATE_URL) || !in_array(parse_url($url, PHP_URL_SCHEME), ['http', 'https'], true)
                    || mb_strlen($url) > 1024) throw new RuntimeException('OSS 原图访问地址无效。');
                $this->imageUrls[$photo['sha256']] = $url;
            }
        } finally {
            $zip?->close();
        }
    }
}
