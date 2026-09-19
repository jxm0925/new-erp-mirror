<?php
$path = dirname(__DIR__) . '/app/Services/Erp/CuttingRecordService.php';
$text = file_get_contents($path);
if ($text === false) { fwrite(STDERR, "missing service\n"); exit(1); }

if (strpos($text, 'function publishStockOrder') !== false) {
    echo "already patched\n";
    exit(0);
}

$needle = "\$c = \$this->commands; \$c->permission(\$permissions, 'production.cutting.plan');\n        if (! is_array(\$p['plans'] ?? null)) \$c->fail('source_missing', '请选择正式来源计划。');";
$repl = "\$c = \$this->commands; \$c->permission(\$permissions, 'production.cutting.plan');\n        \$purpose = strtoupper((string) (\$p['purpose'] ?? 'FORMAL'));\n        if (! in_array(\$purpose, ['FORMAL', 'STOCK'], true)) \$c->fail('purpose_invalid', '下料目的只能是正式需求或备货。');\n        if (\$purpose === 'STOCK') {\n            return \$this->publishStockOrder(\$p, \$user, \$permissions, \$super);\n        }\n        if (! is_array(\$p['plans'] ?? null)) \$c->fail('source_missing', '请选择正式来源计划，或改为备货下料。');";

if (strpos($text, $needle) === false) { fwrite(STDERR, "publish needle missing\n"); exit(1); }
$text = str_replace($needle, $repl, $text);

$needle2 = "'status' => 'PUBLISHED', 'business_version' => 1, 'responsible_user_legacy_id' => \$c->actor(\$user),";
$repl2 = "'status' => 'PUBLISHED', 'purpose' => 'FORMAL', 'business_version' => 1, 'responsible_user_legacy_id' => \$c->actor(\$user),";
if (strpos($text, $needle2) === false) { fwrite(STDERR, "purpose insert needle missing\n"); exit(1); }
$text = str_replace($needle2, $repl2, $text);

$needle3 = "if (! \$allowed) \$c->fail('output_not_allowed', '该Item、配置或阶段不属于本下料任务允许的正式产出集合。');\n            if (! \$this->materials->plans(\$batch->cutting_order_id)->where('r.component_item_id',\$batch->input_item_id)\n                ->where('p.output_item_id',\$allowed->item_id)->where('p.configuration_id',\$allowed->configuration_id)->where('p.stage_id',\$allowed->stage_id)->exists())\n                \$c->fail('output_input_mismatch','当前实际投入原料不属于这条产出的正式需求及冻结工序。');\n            \$item = Item::find(\$allowed->item_id); \$plan = DB::table('erp_cutting_plan_allocations')->where('id', \$allowed->plan_id)->first();\n            \$this->configuration(\$allowed->configuration_id, \$item, \$plan->work_order_id);";

$repl3 = "if (! \$allowed) \$c->fail('output_not_allowed', '该Item、配置或阶段不属于本下料任务允许的产出集合。');\n            \$orderPurpose = (string) (DB::table('erp_cutting_orders')->where('id', \$batch->cutting_order_id)->value('purpose') ?: 'FORMAL');\n            if (\$orderPurpose !== 'STOCK') {\n                if (! \$this->materials->plans(\$batch->cutting_order_id)->where('r.component_item_id',\$batch->input_item_id)\n                    ->where('p.output_item_id',\$allowed->item_id)->where('p.configuration_id',\$allowed->configuration_id)->where('p.stage_id',\$allowed->stage_id)->exists())\n                    \$c->fail('output_input_mismatch','当前实际投入原料不属于这条产出的正式需求及冻结工序。');\n                \$item = Item::find(\$allowed->item_id); \$plan = DB::table('erp_cutting_plan_allocations')->where('id', \$allowed->plan_id)->first();\n                if (! \$plan) \$c->fail('plan_missing', '正式产出缺少来源计划。');\n                \$this->configuration(\$allowed->configuration_id, \$item, \$plan->work_order_id);\n            } else {\n                \$item = Item::find(\$allowed->item_id);\n                if (! \$item || \$item->status !== 'enabled') \$c->fail('output_item_invalid', '备货产出物料未启用。');\n            }";

if (strpos($text, $needle3) === false) { fwrite(STDERR, "resultData needle missing\n"); exit(1); }
$text = str_replace($needle3, $repl3, $text);

$methods = <<<'PHP'

    private function publishStockOrder(array $p, object $user, array $permissions, bool $super = false): array
    {
        $c = $this->commands;
        return $c->run('publish_cutting_order', 0, $p, $user, function () use ($c, $p, $user): array {
            if (($p['expected_version'] ?? null) !== 0) $c->fail('version_required', '新建下料单版本必须为0。');
            $outputs = $p['stock_outputs'] ?? $p['allowed_item_ids'] ?? [];
            if (! is_array($outputs) || ! array_is_list($outputs) || count($outputs) < 1 || count($outputs) > 100) {
                $c->fail('stock_outputs_missing', '备货下料请至少选择1个产出物料。');
            }
            $id = DB::table('erp_cutting_orders')->insertGetId([
                'cutting_order_no' => $this->numbers->next('cutting_order', 'CUT'),
                'status' => 'PUBLISHED',
                'purpose' => 'STOCK',
                'business_version' => 1,
                'responsible_user_legacy_id' => $c->actor($user),
                'created_by_legacy_id' => $c->actor($user),
                'published_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $taskId = DB::table('erp_cutting_tasks')->insertGetId([
                'cutting_order_id' => $id,
                'task_no' => $this->numbers->next('cutting_task', 'CT'),
                'status' => 'WAIT_CLAIM',
                'business_version' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $allowedIds = [];
            foreach ($outputs as $row) {
                $itemId = is_array($row) ? (int) ($row['item_id'] ?? $row['id'] ?? 0) : (int) $row;
                $configId = is_array($row) && isset($row['configuration_id']) ? (int) $row['configuration_id'] : null;
                $item = Item::find($itemId);
                if (! $item || $item->status !== 'enabled') $c->fail('output_item_invalid', '备货产出物料未启用。');
                $allowedIds[] = DB::table('erp_cutting_allowed_outputs')->insertGetId([
                    'cutting_order_id' => $id,
                    'plan_id' => null,
                    'item_id' => $itemId,
                    'configuration_id' => $configId,
                    'stage_id' => null,
                    'quality_mode' => 'none',
                    'output_mode' => 'stockable',
                    'work_mode' => 'manual',
                    'rule_snapshot' => json_encode([
                        'purpose' => 'STOCK',
                        'item_code' => $item->item_code,
                        'item_name' => $item->item_name,
                    ], JSON_THROW_ON_ERROR),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
            $response = [
                'cutting_order_id' => $id,
                'cutting_task_id' => $taskId,
                'purpose' => 'STOCK',
                'allowed_output_ids' => $allowedIds,
                'business_version' => 1,
            ];
            $c->event('order', $id, 'publish_stock', $user, null, $response);
            return $response;
        });
    }

PHP;

$pos = strrpos($text, "\n}");
if ($pos === false) { fwrite(STDERR, "class end missing\n"); exit(1); }
$text = substr($text, 0, $pos) . $methods . substr($text, $pos);

file_put_contents($path, $text);
echo "patched ok bytes=" . strlen($text) . PHP_EOL;
