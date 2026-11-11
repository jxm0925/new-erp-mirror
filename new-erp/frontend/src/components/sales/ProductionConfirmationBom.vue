<template>
  <section class="batch-bom">
    <div class="bom-head">
      <h3 class="bom-title">{{ historical ? '已确认生产BOM' : '本单生产BOM' }}</h3>
      <span v-if="bom" class="bom-version">标准BOM V{{ bom.version }}<small v-if="bom.bom_no"> · {{ bom.bom_no }}</small></span>
      <span v-if="batch" class="bom-batch">批次 {{ batch.fulfillment_id }} · {{ batch.confirmed_at || '—' }}</span>
      <span v-if="batch && batch.demand_status === 'superseded'" class="bom-batch">已被后续安排替代</span>
      <strong>{{ historical ? '已确认生产' : '本次生产' }} {{ number(quantity) }} {{ unit }}</strong>
      <el-button
        v-if="bom"
        class="bom-toggle"
        type="text"
        size="mini"
        :aria-expanded="expanded"
        @click="expanded = !expanded"
      >
        {{ expanded ? '收起BOM' : '展开BOM' }} <i :class="expanded ? 'el-icon-arrow-up' : 'el-icon-arrow-down'" />
      </el-button>
    </div>
    <div v-if="historical && !bom" class="bom-note">未记录本批BOM</div>
    <div v-if="conflict" class="bom-conflict" role="status">
      <span>{{ conflict }}</span>
      <el-button v-if="editable" type="text" size="mini" @click="$emit('reset')">清除原替换并重新核对</el-button>
    </div>
    <template v-if="expanded && bom">
      <div v-if="bom.block_reason" class="bom-note">{{ bom.block_reason }}</div>
      <div v-if="items.length" class="bom-table-wrap">
        <table class="bom-table">
          <thead>
            <tr>
              <th scope="col">序号</th>
              <th scope="col">标准物料 / 规格</th>
              <th scope="col" class="material-qty">{{ baseUnit ? `每${baseUnit}用量` : '单位用量' }}</th>
              <th scope="col" class="material-qty">本次用量</th>
              <th scope="col">用料工序</th>
              <th scope="col">本单替换</th>
            </tr>
          </thead>
          <tbody>
            <tr v-for="(item, index) in items" :key="item.position_key">
              <td>{{ String(index + 1).padStart(2, '0') }}</td>
              <td>
                <b>{{ item.standard.item_name }}</b><span v-if="item.standard.spec"> / {{ item.standard.spec }}</span>
                <small v-if="item.standard.item_code" class="bom-code">{{ item.standard.item_code }}</small>
              </td>
              <td class="material-qty">{{ number(item.qty) }} {{ item.standard.unit_name }}</td>
              <td class="material-qty" :title="quantityTitle(item)">{{ number(item.total) }} {{ item.standard.unit_name }}</td>
              <td>{{ item.operation_name || '—' }}</td>
              <td>
                <div class="bom-choice">
                  <template v-if="item.replacement">
                    <span class="original-chip">原：{{ materialLabel(item.standard) }}</span>
                    <button
                      v-if="canChoose(item)"
                      type="button"
                      class="replacement-chip"
                      :aria-label="`重新选择替换物料，当前为${materialLabel(item.replacement)}`"
                      @click="$emit('choose', item.position_key)"
                    >本单：{{ materialLabel(item.replacement) }} <i class="el-icon-arrow-down" /></button>
                    <span v-else class="replacement-chip">本单：{{ materialLabel(item.replacement) }}</span>
                    <el-button v-if="canChoose(item)" type="success" plain size="mini" @click="$emit('choose', item.position_key)">重新选择</el-button>
                    <el-button v-if="editable && !conflict" class="undo-replacement" type="text" size="mini" @click="$emit('undo', item.position_key)">撤销替换</el-button>
                    <span class="replacement-chip">{{ pending ? '待核实' : '已替换' }}</span>
                  </template>
                  <template v-else>
                    <span>沿用标准（{{ materialLabel(item.standard) }}）</span>
                    <el-button v-if="canChoose(item)" type="success" plain size="mini" @click="$emit('choose', item.position_key)">选择替换</el-button>
                    <small v-else-if="editable && item.replacement_block_reason" class="bom-note">{{ item.replacement_block_reason }}</small>
                  </template>
                </div>
              </td>
            </tr>
          </tbody>
        </table>
      </div>
      <div v-else-if="!bom.block_reason" class="bom-note">暂无BOM物料</div>
      <div v-if="items.length" class="bom-footer">
        <strong v-if="quantity > 0">已替换 {{ replaced }} 项 · {{ historical ? '该批' : '本次' }} {{ number(quantity) }} {{ unit }}按同一份BOM生产</strong>
        <strong v-else>已替换 {{ replaced }} 项 · 本次生产量为 0，替换不生效</strong>
        <template v-if="replaced">
          <el-input
            v-if="editable"
            size="mini"
            aria-label="BOM调整原因"
            placeholder="填写本单BOM调整原因"
            :value="reason"
            maxlength="500"
            @input="$emit('update-reason', $event)"
          >
            <template slot="prepend">调整原因</template>
          </el-input>
          <span v-else-if="reason" class="bom-reason">调整原因：{{ reason }}</span>
        </template>
      </div>
    </template>
  </section>
</template>

<script>
export default {
  name: 'ProductionConfirmationBom',
  props: {
    bom: { type: Object, default: null },
    batch: { type: Object, default: null },
    draft: { type: Object, default: null },
    quantity: { type: Number, required: true },
    productionBaseQty: { type: Number, required: true },
    unit: { type: String, default: '' },
    baseUnit: { type: String, default: '' },
    editable: Boolean,
    historical: Boolean,
    pending: Boolean,
    conflict: { type: String, default: '' }
  },
  data () {
    return { expanded: !!this.draft || this.pending }
  },
  computed: {
    items () {
      return (this.bom?.items || []).map(item => {
        const effective = { id: item.component_item_id, item_name: item.component_item_name, item_code: item.component_item_code, spec: item.spec, unit_name: item.unit_name }
        return {
          ...item,
          standard: item.original_component || effective,
          replacement: item.original_component ? effective : this.draft?.replacements[item.position_key],
          total: this.totalQuantity(item)
        }
      })
    },
    replaced () {
      return this.items.filter(item => item.replacement).length
    },
    reason () {
      return this.historical ? this.bom?.adjustment_reason : this.draft?.reason
    }
  },
  methods: {
    materialLabel (item) {
      return [item.item_name, item.spec].filter(Boolean).join(' / ')
    },
    canChoose (item) {
      return this.editable && !this.conflict && this.quantity > 0 && item.can_replace
    },
    number (value) {
      return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 })
    },
    quantityTitle (item) {
      if (!Number(item.loss_rate) && !Number(item.fixed_qty)) return ''
      return `按生产数量和单位用量计算，含损耗 ${this.number(item.loss_rate)}% 和本批固定用量 ${this.number(item.fixed_qty)} ${item.standard.unit_name || ''}`
    },
    totalQuantity (item) {
      if (this.historical || this.productionBaseQty === Number(this.bom.production_base_qty)) return item.total_required_qty
      if (this.productionBaseQty <= 0) return 0
      return (this.productionBaseQty * Number(item.qty) * (1 + Number(item.loss_rate || 0) / 100) + Number(item.fixed_qty || 0)).toFixed(4)
    }
  }
}
</script>

<style scoped>
.batch-bom {
  grid-column: 1 / -1;
  box-sizing: border-box;
  min-width: 0;
  padding: 8px;
  border: 1px solid #b9ddcb;
  border-radius: 6px;
  background: linear-gradient(180deg, #f4fbf7 0%, #fbfdfc 58%);
  font-size: 12px;
}
.bom-head {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px 14px;
  padding: 3px 5px 7px;
}
.bom-title {
  margin: 0;
  padding-left: 8px;
  border-left: 3px solid #008b4b;
  font-size: 14px;
}
.bom-version,
.replacement-chip {
  padding: 3px 7px;
  border-radius: 4px;
  background: #e8f8ee;
  color: #006334;
  font-weight: 600;
}
button.replacement-chip {
  border: 0;
  cursor: pointer;
  font: inherit;
  font-weight: 600;
}
button.replacement-chip:hover {
  background: #d3f1de;
}
.bom-toggle {
  margin-left: auto;
}
.bom-table-wrap {
  overflow-x: auto;
}
.bom-table {
  width: 100%;
  min-width: 550px;
  border-collapse: separate;
  border-spacing: 0;
  text-align: left;
}
.bom-table th,
.bom-table td {
  padding: 7px 8px;
  border-bottom: 1px solid #e2e8f0;
}
.bom-table th {
  background: #eaf0f1;
  white-space: nowrap;
}
.bom-table th:first-child {
  border-radius: 5px 0 0 5px;
}
.bom-table th:last-child {
  border-radius: 0 5px 5px 0;
}
.bom-table td:first-child,
.material-qty {
  white-space: nowrap;
  font-variant-numeric: tabular-nums;
}
.material-qty {
  text-align: right;
}
.bom-choice,
.bom-footer {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px 8px;
}
.bom-choice .el-button + .el-button {
  margin-left: 0;
}
.bom-choice .el-button--mini {
  padding: 5px 8px;
}
.original-chip {
  padding: 3px 7px;
  border-radius: 4px;
  background: #f1f5f9;
  color: #334155;
}
.bom-choice .undo-replacement {
  color: #334155;
}
.bom-code {
  display: block;
  color: #64748b;
  overflow-wrap: anywhere;
}
.bom-footer {
  margin-top: 8px;
  padding: 7px 10px;
  border-radius: 5px;
  background: #edfaf2;
  color: #006334;
}
.bom-footer .el-input {
  flex: 1 1 230px;
  width: auto;
  min-width: 0;
}
.bom-batch,
.bom-note {
  color: #64748b;
}
div.bom-note,
.bom-conflict {
  padding: 6px 10px;
}
.bom-conflict {
  background: #fffbeb;
  color: #92400e;
}
.bom-conflict .el-button {
  margin-left: 8px;
}
.bom-reason {
  overflow-wrap: anywhere;
}
</style>
