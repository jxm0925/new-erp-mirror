<?php

namespace App\Models\Erp;

/** 提成模板适用范围：default=默认模板；category:{id}=商品分类（erp_item_categories）。 */
class ProductionPerformanceBinding extends MasterModel
{
    public const DEFAULT_KEY = 'default';

    protected $table = 'erp_production_performance_bindings';
    protected $casts = ['category_id' => 'integer', 'template_id' => 'integer'];

    public static function categoryKey(int $categoryId): string { return 'category:'.$categoryId; }

    public function template() { return $this->belongsTo(ProductionPerformanceTemplate::class, 'template_id'); }
    public function category() { return $this->belongsTo(ItemCategory::class, 'category_id'); }
}
