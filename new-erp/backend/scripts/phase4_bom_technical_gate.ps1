$ErrorActionPreference = 'Stop'

$base = 'http://127.0.0.1:8011/api/v1/erp'
$out = [ordered]@{}

function ApiGet($path) {
    Invoke-RestMethod -Method Get -Uri "$base$path"
}

function ApiPost($path, $body = $null) {
    $json = if ($null -eq $body) { '{}' } else { $body | ConvertTo-Json -Depth 20 }
    try {
        $res = Invoke-WebRequest -Method Post -Uri "$base$path" -Body $json -ContentType 'application/json; charset=utf-8' -Headers @{ Accept = 'application/json' }
        return [ordered]@{
            ok = $true
            status = [int]$res.StatusCode
            body = ($res.Content | ConvertFrom-Json)
        }
    } catch {
        $resp = $_.Exception.Response
        $status = if ($resp) { [int]$resp.StatusCode } else { 0 }
        $content = ''
        if ($resp) {
            $reader = [System.IO.StreamReader]::new($resp.GetResponseStream())
            $content = $reader.ReadToEnd()
        } else {
            $content = $_.Exception.Message
        }
        $parsed = $null
        try { $parsed = $content | ConvertFrom-Json } catch { $parsed = $content }
        return [ordered]@{
            ok = $false
            status = $status
            body = $parsed
        }
    }
}

$boms = (ApiGet '/bom/boms?per_page=100').data
$skuaV1 = $boms | Where-Object bom_no -eq 'BOM-TG-SKUA-V1' | Select-Object -First 1
$skuaV2 = $boms | Where-Object bom_no -eq 'BOM-TG-SKUA-V2' | Select-Object -First 1
$skubV1 = $boms | Where-Object bom_no -eq 'BOM-TG-SKUB-V1' | Select-Object -First 1
$bombV1 = $boms | Where-Object bom_no -eq 'BOM-TG-B-V1' | Select-Object -First 1
$expired = $boms | Where-Object bom_no -eq 'BOM-TG-SKUA-EXPIRED' | Select-Object -First 1

$out.initial_defaults = @($skuaV1, $skuaV2, $skubV1, $bombV1) |
    Select-Object bom_no, is_default, status, audit_status, product_id, sku_id, output_item_id, effective_date, expire_date

$out.set_default_skua_v2 = ApiPost "/bom/boms/$($skuaV2.id)/set-default"

$bomsAfterDefault = (ApiGet '/bom/boms?per_page=100').data
$out.defaults_after_set = @(
    $bomsAfterDefault |
        Where-Object { $_.bom_no -in @('BOM-TG-SKUA-V1', 'BOM-TG-SKUA-V2', 'BOM-TG-SKUB-V1', 'BOM-TG-B-V1') } |
        Select-Object bom_no, is_default, product_id, sku_id, output_item_id, status, audit_status
)
$out.default_groups = @(
    $bomsAfterDefault |
        Where-Object is_default |
        Group-Object product_id, sku_id, output_item_id |
        ForEach-Object {
            [ordered]@{
                scope = $_.Name
                count = $_.Count
                boms = @($_.Group | ForEach-Object bom_no)
            }
        }
)

$skubAfter = $bomsAfterDefault | Where-Object bom_no -eq 'BOM-TG-SKUB-V1' | Select-Object -First 1
$skuaV1After = $bomsAfterDefault | Where-Object bom_no -eq 'BOM-TG-SKUA-V1' | Select-Object -First 1
$out.deactivate_default_skub = ApiPost "/bom/boms/$($skubAfter.id)/deactivate"
$out.deactivate_non_default_skua_v1 = ApiPost "/bom/boms/$($skuaV1After.id)/deactivate"

$expandBody = [ordered]@{
    product_id = $skuaV2.product_id
    sku_id = $skuaV2.sku_id
    output_item_id = $skuaV2.output_item_id
    planned_qty = 10
    business_date = '2026-07-14'
}
$out.expand_default_recursive = ApiPost '/bom/expand' $expandBody

$expiredBody = [ordered]@{
    bom_id = $expired.id
    planned_qty = 10
    business_date = '2026-07-14'
}
$out.expand_expired_explicit = ApiPost '/bom/expand' $expiredBody

$missingBody = [ordered]@{
    product_id = $skuaV2.product_id
    sku_id = $skuaV2.sku_id
    output_item_id = $expired.output_item_id
    planned_qty = 1
    business_date = '2025-07-14'
}
$out.expand_no_effective_default_on_business_date = ApiPost '/bom/expand' $missingBody

$itemsResp = ApiGet '/master/items?per_page=100'
$itemList = if ($itemsResp.data) { $itemsResp.data } else { $itemsResp }
$cycleA = $itemList | Where-Object item_code -eq 'ITEM-TG-CYCLE-A' | Select-Object -First 1
$cycleB = $itemList | Where-Object item_code -eq 'ITEM-TG-CYCLE-B' | Select-Object -First 1
$cycleC = $itemList | Where-Object item_code -eq 'ITEM-TG-CYCLE-C' | Select-Object -First 1
$unitId = $cycleA.unit_id

function CycleBom($no, $outItem, $compItem) {
    [ordered]@{
        bom_no = $no
        bom_name = $no
        bom_type = 'standard'
        version = 'TG'
        output_item_id = $outItem.id
        effective_date = '2026-01-01'
        items = @(
            @{
                component_item_id = $compItem.id
                qty = 1
                unit_id = $unitId
                loss_rate = 0
                fixed_qty = 0
                replaceable = $false
            }
        )
    }
}

$out.cycle_create_a_to_b = ApiPost '/bom/boms' (CycleBom 'BOM-TG-CYCLE-AB-A' $cycleA $cycleB)
$out.cycle_create_b_to_a_should_422 = ApiPost '/bom/boms' (CycleBom 'BOM-TG-CYCLE-AB-B' $cycleB $cycleA)
$out.cycle_chain_a_to_b = ApiPost '/bom/boms' (CycleBom 'BOM-TG-CYCLE-ABC-A' $cycleA $cycleB)
$out.cycle_chain_b_to_c = ApiPost '/bom/boms' (CycleBom 'BOM-TG-CYCLE-ABC-B' $cycleB $cycleC)
$out.cycle_chain_c_to_a_should_422 = ApiPost '/bom/boms' (CycleBom 'BOM-TG-CYCLE-ABC-C' $cycleC $cycleA)

$txBefore = ApiGet '/inventory/transactions?per_page=1'
$null = ApiPost '/bom/expand' $expandBody
$txAfter = ApiGet '/inventory/transactions?per_page=1'
$out.inventory_transactions_before_after_expand = [ordered]@{
    before_total = $txBefore.total
    after_total = $txAfter.total
    note = 'BOM expand reads inventory balances only and does not create inventory transactions.'
}

$out | ConvertTo-Json -Depth 30 | Set-Content -Encoding UTF8 'D:\codex-introduce\new_erp\docs\phase4_bom_technical_gate_api.json'
$out | ConvertTo-Json -Depth 8
