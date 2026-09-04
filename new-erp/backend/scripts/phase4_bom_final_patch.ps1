$ErrorActionPreference = 'Stop'

$base = 'http://127.0.0.1:8011/api/v1/erp'
$headers = @{ Accept = 'application/json' }
$out = [ordered]@{}

function ApiGet($path) {
    Invoke-RestMethod -Method Get -Uri "$base$path" -Headers $headers
}

function ApiPost($path, $body = $null) {
    $json = if ($null -eq $body) { '{}' } else { $body | ConvertTo-Json -Depth 20 }
    try {
        $res = Invoke-WebRequest -Method Post -Uri "$base$path" -Body $json -ContentType 'application/json; charset=utf-8' -Headers $headers
        return [ordered]@{ ok = $true; status = [int]$res.StatusCode; body = ($res.Content | ConvertFrom-Json) }
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
        try { $parsed = $content | ConvertFrom-Json } catch { $parsed = $content }
        return [ordered]@{ ok = $false; status = $status; body = $parsed }
    }
}

$boms = (ApiGet '/bom/boms?per_page=100').data
$valid1 = $boms | Where-Object bom_no -eq 'BOM-FP-VALID-1' | Select-Object -First 1
$valid2 = $boms | Where-Object bom_no -eq 'BOM-FP-VALID-2' | Select-Object -First 1
$expired = $boms | Where-Object bom_no -eq 'BOM-FP-EXPIRED' | Select-Object -First 1
$future = $boms | Where-Object bom_no -eq 'BOM-FP-FUTURE' | Select-Object -First 1
$lossA = $boms | Where-Object bom_no -eq 'BOM-FP-LOSS-A' | Select-Object -First 1
$half1 = $boms | Where-Object bom_no -eq 'BOM-FP-HALF-1' | Select-Object -First 1
$half2 = $boms | Where-Object bom_no -eq 'BOM-FP-HALF-2' | Select-Object -First 1

$out.set_default_expired = ApiPost "/bom/boms/$($expired.id)/set-default"
$out.set_default_future = ApiPost "/bom/boms/$($future.id)/set-default"
$out.original_valid_defaults_after_failed_requests = @(
    (ApiGet '/bom/boms?per_page=100').data |
        Where-Object { $_.bom_no -in @('BOM-FP-VALID-1', 'BOM-FP-VALID-2', 'BOM-FP-EXPIRED', 'BOM-FP-FUTURE') } |
        Select-Object bom_no, is_default, status, audit_status, effective_date, expire_date
)

$lossExpand = ApiPost '/bom/expand' ([ordered]@{
    bom_id = $lossA.id
    planned_qty = 10
    business_date = '2026-07-14'
})
$out.loss_expand = $lossExpand

$jobs = @()
for ($i = 1; $i -le 20; $i++) {
    $targetId = if ($i % 2 -eq 0) { $valid2.id } else { $valid1.id }
    $jobs += Start-Job -ScriptBlock {
        param($baseUrl, $bomId)
        try {
            $res = Invoke-WebRequest -Method Post -Uri "$baseUrl/bom/boms/$bomId/set-default" -Body '{}' -ContentType 'application/json; charset=utf-8' -Headers @{ Accept = 'application/json' }
            [ordered]@{ ok = $true; status = [int]$res.StatusCode; bom_id = $bomId; body = ($res.Content | ConvertFrom-Json).message }
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
            $msg = $content
            try { $msg = ($content | ConvertFrom-Json).message } catch {}
            [ordered]@{ ok = $false; status = $status; bom_id = $bomId; body = $msg }
        }
    } -ArgumentList $base, $targetId
}

$out.concurrent_set_default_results = @($jobs | Wait-Job | Receive-Job)
$jobs | Remove-Job

$afterConcurrency = (ApiGet '/bom/boms?per_page=100').data
$scopeDefaults = @(
    $afterConcurrency |
        Where-Object { $_.product_id -eq $valid1.product_id -and $_.sku_id -eq $valid1.sku_id -and $_.output_item_id -eq $valid1.output_item_id -and $_.is_default } |
        Select-Object bom_no, id, is_default, product_id, sku_id, output_item_id
)
$out.concurrent_final_scope_defaults = $scopeDefaults
$out.concurrent_final_default_count = $scopeDefaults.Count
$out.other_default_scopes_after_concurrency = @(
    $afterConcurrency |
        Where-Object { $_.is_default -and $_.output_item_id -ne $valid1.output_item_id } |
        Select-Object bom_no, product_id, sku_id, output_item_id, is_default
)

$out.half_set_default_h2 = ApiPost "/bom/boms/$($half2.id)/set-default"
$afterHalf = (ApiGet '/bom/boms?per_page=100').data
$halfDefaults = @(
    $afterHalf |
        Where-Object { $null -eq $_.product_id -and $null -eq $_.sku_id -and $_.output_item_id -eq $half1.output_item_id -and $_.is_default } |
        Select-Object bom_no, id, is_default, product_id, sku_id, output_item_id
)
$out.half_null_scope_defaults = $halfDefaults
$out.half_null_scope_default_count = $halfDefaults.Count

$txBefore = ApiGet '/inventory/transactions?per_page=1'
$null = ApiPost '/bom/expand' ([ordered]@{ bom_id = $lossA.id; planned_qty = 10; business_date = '2026-07-14' })
$txAfter = ApiGet '/inventory/transactions?per_page=1'
$out.inventory_transactions_before_after_expand = [ordered]@{
    before_total = $txBefore.total
    after_total = $txAfter.total
}

$out | ConvertTo-Json -Depth 30 | Set-Content -Encoding UTF8 'D:\codex-introduce\new_erp\docs\phase4_bom_final_patch_api.json'

$summary = [ordered]@{
    expired_set_default_status = $out.set_default_expired.status
    expired_set_default_message = $out.set_default_expired.body.message
    future_set_default_status = $out.set_default_future.status
    future_set_default_message = $out.set_default_future.body.message
    defaults_after_failed_requests = $out.original_valid_defaults_after_failed_requests
    loss_expand_status = $out.loss_expand.status
    loss_aggregate_lines = $out.loss_expand.body.lines | Select-Object component_item_code, component_item_name, demand_qty, paths
    loss_tree_lines = $out.loss_expand.body.tree_lines | Select-Object level, path, unit_qty, loss_rate, fixed_qty, demand_qty, is_leaf
    concurrent_request_count = $out.concurrent_set_default_results.Count
    concurrent_success_count = @($out.concurrent_set_default_results | Where-Object ok).Count
    concurrent_422_count = @($out.concurrent_set_default_results | Where-Object { $_.status -eq 422 }).Count
    concurrent_final_default_count = $out.concurrent_final_default_count
    concurrent_final_scope_defaults = $out.concurrent_final_scope_defaults
    half_null_scope_default_count = $out.half_null_scope_default_count
    half_null_scope_defaults = $out.half_null_scope_defaults
    inventory_transactions = $out.inventory_transactions_before_after_expand
}
$summary | ConvertTo-Json -Depth 20 | Set-Content -Encoding UTF8 'D:\codex-introduce\new_erp\docs\phase4_bom_final_patch_summary.json'
$summary | ConvertTo-Json -Depth 12
