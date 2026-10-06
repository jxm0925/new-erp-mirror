<template>
  <div v-if="snapshot" :class="['conversion-facts-root', { 'is-compact': compact }]">
    <!-- 1:1 基准单位 -->
    <template v-if="isBaseUnit">
      <div v-if="compact" class="base-unit-tag">
        <i class="el-icon-check" />
        <span>基本库存单位</span>
      </div>
      <div v-else class="base-unit-card">
        <i class="el-icon-circle-check" />
        <span>基本库存单位（与库存主单位 1:1 一致，无需换算）</span>
      </div>
    </template>

    <!-- 具有换算关系的采购单位 -->
    <template v-else>
      <div v-if="compact" class="conversion-pill-box">
        <div class="conversion-rate-line">
          <span class="rate-badge">换算</span>
          <span class="rate-formula">
            1 {{ snapshot.purchase_unit_name_snapshot }} = <strong>{{ number(snapshot.conversion_factor_snapshot) }}</strong> {{ snapshot.base_unit_name_snapshot }}
          </span>
        </div>
        <div class="conversion-stock-line">
          <span class="stock-label">折合库存：</span>
          <strong class="stock-qty highlight-green">{{ number(snapshot.planned_base_qty) }}</strong>
          <span class="stock-unit">{{ snapshot.base_unit_name_snapshot }}</span>
        </div>
      </div>

      <div v-else class="conversion-card-full">
        <div class="card-rate-header">
          <span class="rate-badge-pill"><i class="el-icon-sort" /> 换算比率</span>
          <span class="rate-formula-bold">
            1 {{ snapshot.purchase_unit_name_snapshot }} = <strong>{{ number(snapshot.conversion_factor_snapshot) }}</strong> {{ snapshot.base_unit_name_snapshot }}
          </span>
        </div>
        <div class="card-flows">
          <div class="flow-step">
            <span class="step-label">采购包装</span>
            <span class="step-val"><strong>{{ number(snapshot.purchase_qty) }}</strong> {{ snapshot.purchase_unit_name_snapshot }}</span>
          </div>
          <div class="flow-arrow"><i class="el-icon-right" /></div>
          <div class="flow-step highlight-step">
            <span class="step-label">折合入库</span>
            <span class="step-val highlight-green"><strong>{{ number(snapshot.planned_base_qty) }}</strong> {{ snapshot.base_unit_name_snapshot }}</span>
          </div>
        </div>
      </div>
    </template>
  </div>
  <span v-else class="purchase-conversion-empty">
    <i class="el-icon-info" /> 尚未确认采购换算
  </span>
</template>

<script>
export default {
  name: 'PurchaseConversionFacts',
  props: {
    snapshot: { type: Object, default: null },
    compact: { type: Boolean, default: false }
  },
  computed: {
    isBaseUnit() {
      if (!this.snapshot) return false
      const factor = Number(this.snapshot.conversion_factor_snapshot ?? this.snapshot.conversion_factor ?? 1)
      const purchaseId = Number(this.snapshot.purchase_unit_id ?? 0)
      const baseId = Number(this.snapshot.base_unit_id ?? 0)
      const purchaseName = this.snapshot.purchase_unit_name_snapshot || this.snapshot.purchase_unit_name || ''
      const baseName = this.snapshot.base_unit_name_snapshot || this.snapshot.base_unit_name || ''
      return factor === 1 && ((purchaseId && baseId && purchaseId === baseId) || (purchaseName && baseName && purchaseName === baseName))
    }
  },
  methods: {
    number(value) {
      if (value == null || value === '') return '0'
      return Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 8 })
    }
  }
}
</script>

<style scoped>
.conversion-facts-root {
  box-sizing: border-box;
  min-width: 0;
  max-width: 100%;
}

/* 1:1 基准单位小徽标 */
.base-unit-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-top: 4px;
  padding: 2px 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  color: #15803d;
  font-size: 11px;
  font-weight: 500;
  line-height: 1.4;
}

.base-unit-card {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  color: #475569;
  font-size: 12px;
}

.base-unit-card i {
  color: #008b4b;
}

/* 表格紧凑换算卡片 */
.conversion-pill-box {
  margin-top: 5px;
  padding: 5px 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-left: 3px solid #008b4b;
  border-radius: 4px;
  display: flex;
  flex-direction: column;
  gap: 3px;
  font-size: 12px;
}

.conversion-rate-line {
  display: flex;
  align-items: center;
  gap: 6px;
  color: #475569;
  line-height: 1.3;
}

.rate-badge {
  font-size: 10px;
  padding: 0 4px;
  background: #e2e8f0;
  color: #334155;
  border-radius: 2px;
  font-weight: 600;
  flex-shrink: 0;
}

.rate-formula {
  font-size: 11px;
  color: #334155;
}

.rate-formula strong {
  color: #0f172a;
}

.conversion-stock-line {
  display: flex;
  align-items: baseline;
  gap: 3px;
  line-height: 1.3;
}

.stock-label {
  font-size: 11px;
  color: #64748b;
}

.stock-qty {
  font-size: 13px;
  font-weight: 700;
  color: #008b4b;
}

.stock-unit {
  font-size: 11px;
  color: #475569;
}

/* 完整展开模式卡片 */
.conversion-card-full {
  margin: 6px 0;
  padding: 8px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-left: 3px solid #008b4b;
  border-radius: 6px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.card-rate-header {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 12px;
}

.rate-badge-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  padding: 2px 6px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #15803d;
  border-radius: 4px;
  font-weight: 500;
}

.rate-formula-bold {
  color: #1e293b;
  font-weight: 500;
}

.rate-formula-bold strong {
  color: #008b4b;
}

.card-flows {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.flow-step {
  display: flex;
  flex-direction: column;
  gap: 2px;
  padding: 4px 10px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  min-width: 90px;
}

.highlight-step {
  border-color: #bbf7d0;
  background: #f0fdf4;
}

.step-label {
  font-size: 11px;
  color: #64748b;
}

.step-val {
  font-size: 13px;
  color: #1e293b;
}

.highlight-green {
  color: #008b4b !important;
}

.flow-arrow {
  color: #94a3b8;
  font-size: 14px;
}

.purchase-conversion-empty {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #94a3b8;
  font-size: 12px;
  margin-top: 4px;
}
</style>
