<?php

namespace App\Models\Erp;

use Illuminate\Support\Facades\DB;

/** 共用工艺路线的适用范围（物料或物料类目）。 */
class ProductionRoutingApplicability extends MasterModel
{
    protected $table = 'erp_production_routing_applicabilities';

    public function routing() { return $this->belongsTo(ProductionRouting::class, 'routing_id'); }
    public function item() { return $this->belongsTo(Item::class, 'item_id'); }
    public function category() { return $this->belongsTo(ItemCategory::class, 'item_category_id'); }

    /** 物料所在类目及其上级类目，由近到远。 */
    public static function categoryChain(int $itemId): array
    {
        $chain = [];
        $id = (int) DB::table('erp_items')->where('id', $itemId)->value('category_id');
        while ($id > 0 && ! in_array($id, $chain, true) && count($chain) < 20) {
            $chain[] = $id;
            $id = (int) DB::table('erp_item_categories')->where('id', $id)->value('parent_id');
        }
        return $chain;
    }
}
