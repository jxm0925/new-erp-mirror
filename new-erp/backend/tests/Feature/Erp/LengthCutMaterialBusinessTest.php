<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\DocumentNumberReservation;
use App\Models\Erp\Item;
use App\Models\Erp\Unit;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class LengthCutMaterialBusinessTest extends TestCase
{
    use DatabaseTransactions;

    protected function setUp(): void
    {
        parent::setUp();
        $user = (object) ['legacy_id' => 910014, 'username' => 'length_cut_test', 'nickname' => '下料测试员', 'status' => 'normal'];
        $this->mock(\App\Services\Erp\AuthContextService::class, function (MockInterface $mock) use ($user): void {
            $mock->shouldReceive('currentUser')->andReturn($user);
            $mock->shouldReceive('currentLegacyId')->andReturn($user->legacy_id);
        });
    }

    public function test_304_and_201_are_independent_real_items_with_6000mm_stock_length(): void
    {
        $unit = $this->unit('根');
        $a304 = $this->item($unit, '304 方管', '304', true);
        $a201 = $this->item($unit, '201 方管', '201', true);

        $this->assertNotSame($a304->id, $a201->id);
        $this->assertSame('304', $a304->material_grade);
        $this->assertSame('201', $a201->material_grade);
        $this->assertSame(6000.0, (float) $a304->standard_stock_length_mm);
        $this->assertSame(6000.0, (float) $a201->standard_stock_length_mm);
        $this->assertSame(2, Item::query()->whereIn('id', [$a304->id, $a201->id])->count());
    }

    public function test_one_304_item_accepts_three_cut_lengths_in_item_only_bom(): void
    {
        $unit = $this->unit('根');
        $tube = $this->item($unit, '304 方管', '304', true);
        $semiFinished = $this->item($this->unit('件'), '机架立柱', null, false, true);
        $payload = $this->bomPayload($semiFinished, [
            $this->line($tube, 350, 2),
            $this->line($tube, 680, 4),
            $this->line($tube, 1250, 1),
        ]);

        $response = $this->postJson('/api/v1/erp/bom/boms', $payload);

        $response->assertCreated()
            ->assertJsonPath('data.product_id', null)
            ->assertJsonPath('data.sku_id', null)
            ->assertJsonCount(3, 'data.items')
            ->assertJsonPath('data.items.1.cut_length_mm', '680.00')
            ->assertJsonPath('data.items.1.piece_qty', 4);
        $this->assertSame(1, Item::query()->whereKey($tube->id)->count());
        $this->assertSame(0, Item::query()->where('item_name', 'like', '304 方管-%')->count());
    }

    public function test_ordinary_controller_needs_no_cut_length_and_rejects_one_if_supplied(): void
    {
        $unit = $this->unit('件');
        $controller = $this->item($unit, '控制器', null, false);
        $output = $this->item($unit, '普通装配半成品', null, false, true);

        $this->postJson('/api/v1/erp/bom/boms', $this->bomPayload($output, [[
            'component_item_id' => $controller->id,
            'qty' => 2,
            'loss_rate' => 0,
            'fixed_qty' => 0,
            'replaceable' => false,
        ]]))->assertCreated()->assertJsonPath('data.items.0.cut_length_mm', null);

        $this->postJson('/api/v1/erp/bom/boms', $this->bomPayload($output, [[
            'component_item_id' => $controller->id,
            'cut_length_mm' => 680,
            'piece_qty' => 4,
            'qty' => 2,
            'loss_rate' => 0,
            'fixed_qty' => 0,
            'replaceable' => false,
        ]]))->assertStatus(422)
            ->assertJsonPath('message', '普通物料 控制器 不能填写下料长度或段数。');
    }

    private function line(Item $item, int $length, int $pieces): array
    {
        return [
            'component_item_id' => $item->id,
            'cut_length_mm' => $length,
            'piece_qty' => $pieces,
            'qty' => 1,
            'loss_rate' => 0,
            'fixed_qty' => 0,
            'replaceable' => false,
        ];
    }

    private function bomPayload(Item $output, array $lines): array
    {
        $sessionId = (string) Str::uuid();
        $token = (string) Str::uuid();
        DocumentNumberReservation::create([
            'document_type' => 'bom',
            'creation_session_id' => $sessionId,
            'document_no' => 'BOM-CUT-'.strtoupper(Str::random(12)),
            'reservation_token' => $token,
            'status' => 'reserved',
            'expires_at' => now()->addHour(),
        ]);
        return [
            'reservation_token' => $token,
            'creation_session_id' => $sessionId,
            'bom_name' => '真实方管下料 BOM',
            'product_id' => null,
            'sku_id' => null,
            'output_item_id' => $output->id,
            'bom_type' => 'standard',
            'version' => 'V1.0',
            'effective_date' => now()->toDateString(),
            'items' => $lines,
        ];
    }

    private function unit(string $name): Unit
    {
        $suffix = strtoupper(Str::random(8));
        return Unit::create([
            'unit_code' => 'CUT-U-'.$suffix,
            'unit_name' => $name,
            'symbol' => $name,
            'unit_type' => 'quantity',
            'allow_decimal' => false,
            'decimal_places' => 0,
            'is_base' => true,
            'status' => 'enabled',
        ]);
    }

    private function item(Unit $unit, string $name, ?string $grade, bool $lengthCut, bool $production = false): Item
    {
        return Item::create([
            'item_code' => 'CUT-I-'.strtoupper(Str::random(10)),
            'item_name' => $name,
            'item_type' => $production ? 'semi_finished' : 'raw_material',
            'spec' => $grade ? "{$grade} 方管" : null,
            'material_grade' => $grade,
            'standard_stock_length_mm' => $lengthCut ? 6000 : null,
            'is_length_cut_material' => $lengthCut,
            'unit_id' => $unit->id,
            'is_purchase_item' => ! $production,
            'is_stock_item' => true,
            'is_production_item' => $production,
            'cost_method' => 'weighted_average',
            'status' => 'enabled',
        ]);
    }
}
