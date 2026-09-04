<template>
  <section class="bom-expand-page">
    <div class="expand-head">
      <div>
        <h1>BOM 展开</h1>
        <p>BOM 展开只做计算，不扣库存，不生成采购需求、采购计划或工单。</p>
      </div>
      <el-button size="small" @click="$router.push('/bom/boms')">返回BOM管理</el-button>
    </div>

    <section class="expand-card">
      <div class="mode-row">
        <el-radio-group v-model="mode" size="small">
          <el-radio-button label="bom">按 BOM</el-radio-button>
          <el-radio-button label="sku">按 SKU</el-radio-button>
          <el-radio-button label="item">按产出 Item</el-radio-button>
        </el-radio-group>
      </div>
      <div class="expand-form">
        <label>
          BOM版本
          <el-select v-model="form.bom_id" filterable clearable size="small" :disabled="mode!=='bom'">
            <el-option v-for="b in boms" :key="b.id" :label="`${b.bom_no} / ${b.bom_name} / ${b.version}`" :value="b.id" />
          </el-select>
        </label>
        <label>
          SKU
          <el-select v-model="form.sku_id" filterable clearable size="small" :disabled="mode!=='sku'">
            <el-option v-for="s in skus" :key="s.id" :label="`${s.sku_code} / ${s.sku_name}`" :value="s.id" />
          </el-select>
        </label>
        <label>
          产出Item
          <div class="picked" :class="{disabled:mode!=='item'}">
            <span>{{ outputItemLabel }}</span>
            <el-button :disabled="mode!=='item'" size="mini" type="success" plain @click="openPicker">选择</el-button>
          </div>
        </label>
        <label>
          计划生产数量
          <el-input-number v-model="form.planned_qty" size="small" :min="0.0001" :precision="4" controls-position="right" />
        </label>
        <el-button size="small" type="success" @click="expand">计算展开</el-button>
      </div>
    </section>

    <section v-if="result" class="expand-result">
      <div class="result-summary">
        <div><span>汇总行数</span><b>{{ result.summary.line_count }}</b></div>
        <div><span>路径行数</span><b>{{ result.summary.tree_line_count || 0 }}</b></div>
        <div><span>总需求数量</span><b>{{ result.summary.total_demand_qty }}</b></div>
        <div><span>总可用数量</span><b>{{ result.summary.total_available_qty }}</b></div>
        <div><span>总缺料数量</span><b class="danger">{{ result.summary.total_shortage_qty }}</b></div>
      </div>

      <el-tabs v-model="activeTab" type="card" class="expand-tabs">
        <el-tab-pane label="汇总结果" name="summary">
          <el-table :data="result.lines" border size="small">
            <el-table-column prop="component_item_code" label="物料Item" width="135" />
            <el-table-column prop="component_item_name" label="物料名称" min-width="160" show-overflow-tooltip />
            <el-table-column prop="unit_name" label="单位" width="70" />
            <el-table-column prop="planned_qty" label="计划数量" width="90" />
            <el-table-column prop="demand_qty" label="汇总需求" width="100" />
            <el-table-column prop="quantity_on_hand" label="账面库存" width="95" />
            <el-table-column prop="quantity_available" label="可用库存" width="95" />
            <el-table-column prop="quantity_locked" label="锁定库存" width="95" />
            <el-table-column label="缺口数量" width="95">
              <template slot-scope="{row}">
                <span :class="{danger:row.shortage_qty>0}">{{ row.shortage_qty }}</span>
              </template>
            </el-table-column>
            <el-table-column label="来源路径" min-width="260" show-overflow-tooltip>
              <template slot-scope="{row}">{{ (row.paths || []).join('；') || '-' }}</template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="路径明细" name="paths">
          <el-table :data="result.tree_lines || []" border size="small">
            <el-table-column prop="level" label="层级" width="70" />
            <el-table-column prop="path" label="来源路径" min-width="260" show-overflow-tooltip />
            <el-table-column prop="parent_bom_no" label="父级BOM" width="145" show-overflow-tooltip />
            <el-table-column prop="child_bom_no" label="子级BOM" width="145" show-overflow-tooltip>
              <template slot-scope="{row}">{{ row.child_bom_no || '-' }}</template>
            </el-table-column>
            <el-table-column prop="component_item_code" label="物料Item" width="135" />
            <el-table-column prop="component_item_name" label="物料名称" min-width="140" show-overflow-tooltip />
            <el-table-column prop="unit_qty" label="单位用量" width="90" />
            <el-table-column prop="loss_rate" label="损耗率(%)" width="95" />
            <el-table-column prop="fixed_qty" label="固定用量" width="90" />
            <el-table-column prop="demand_qty" label="本路径需求" width="105" />
            <el-table-column label="叶子" width="70">
              <template slot-scope="{row}">{{ row.is_leaf ? '是' : '否' }}</template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>

      <div class="suggestion">
        <h3>建议操作（仅提示，不生成单据）</h3>
        <p v-if="!result.suggestions || !result.suggestions.length">当前无缺料建议。</p>
        <p v-for="(s,i) in result.suggestions" :key="i">{{ s.message }}</p>
      </div>
    </section>

    <el-dialog :visible.sync="picker.visible" title="选择产出 Item" width="860px">
      <div class="picker-filter">
        <el-input v-model="picker.keyword" size="small" clearable placeholder="Item编码/名称" @keyup.enter.native="searchItems" />
        <el-select v-model="picker.item_type" size="small" clearable placeholder="类型">
          <el-option label="成品" value="finished_product" />
          <el-option label="半成品" value="semi_finished" />
        </el-select>
        <el-button size="small" type="success" @click="searchItems">查询</el-button>
      </div>
      <el-table :data="picker.rows" border size="mini" height="340">
        <el-table-column prop="item_code" label="编码" width="140" />
        <el-table-column prop="item_name" label="名称" min-width="180" />
        <el-table-column label="类型" width="110">
          <template slot-scope="{row}">{{ itemTypeText(row.item_type) }}</template>
        </el-table-column>
        <el-table-column label="选择" width="90">
          <template slot-scope="{row}">
            <el-button size="mini" plain type="success" @click="selectItem(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-pagination background small layout="total, sizes, prev, pager, next" :current-page.sync="picker.page" :page-size.sync="picker.perPage" :total="picker.total" @current-change="loadItems" @size-change="loadItems" />
    </el-dialog>
  </section>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { listBoms, expandBom } from '@/api/erp/bom'

export default {
  data: () => ({
    mode: 'bom',
    form: { bom_id: null, sku_id: null, output_item_id: null, planned_qty: 1 },
    outputItem: null,
    boms: [],
    skus: [],
    result: null,
    activeTab: 'summary',
    picker: { visible: false, keyword: '', item_type: '', rows: [], page: 1, perPage: 10, total: 0 }
  }),
  computed: {
    outputItemLabel() {
      return this.outputItem ? `${this.outputItem.item_code} / ${this.outputItem.item_name}` : '按产出 Item 展开时选择'
    }
  },
  async created() {
    this.form.bom_id = this.$route.query.bom_id ? Number(this.$route.query.bom_id) : null
    this.form.planned_qty = this.$route.query.planned_qty ? Number(this.$route.query.planned_qty) : this.form.planned_qty
    if (this.form.bom_id) this.mode = 'bom'
    await this.loadRefs()
    if (this.form.bom_id) this.expand()
  },
  methods: {
    async loadRefs() {
      const [b, s] = await Promise.all([listBoms({ per_page: 100 }), listEntity('skus', { per_page: 100 })])
      this.boms = b.data.data || []
      this.skus = s.data.data || []
    },
    requestPayload() {
      return {
        bom_id: this.mode === 'bom' ? this.form.bom_id : null,
        sku_id: this.mode === 'sku' ? this.form.sku_id : null,
        output_item_id: this.mode === 'item' ? this.form.output_item_id : null,
        planned_qty: this.form.planned_qty
      }
    },
    async expand() {
      const payload = this.requestPayload()
      if (!payload.bom_id && !payload.sku_id && !payload.output_item_id) return this.$message.error('请选择展开对象')
      const { data } = await expandBom(payload)
      this.result = data
      this.activeTab = 'summary'
      this.$message.success('BOM 展开计算完成，库存未被修改')
    },
    openPicker() {
      this.picker.visible = true
      this.picker.page = 1
      this.loadItems()
    },
    searchItems() {
      this.picker.page = 1
      this.loadItems()
    },
    async loadItems() {
      const { data } = await listEntity('items', { keyword: this.picker.keyword, item_type: this.picker.item_type, page: this.picker.page, per_page: this.picker.perPage })
      this.picker.rows = data.data || []
      this.picker.total = data.total || 0
    },
    selectItem(row) {
      this.outputItem = row
      this.form.output_item_id = row.id
      this.picker.visible = false
    },
    itemTypeText(v) {
      return ({ finished_product: '成品', semi_finished: '半成品', raw_material: '原材料', packaging: '包材', service: '服务' })[v] || v
    }
  }
}
</script>

<style scoped>
.bom-expand-page{padding:16px 18px 28px;background:#f6f8fa;min-height:calc(100vh - 52px);font-size:13px}
.expand-head{height:50px;display:flex;justify-content:space-between;align-items:flex-start}
.expand-head h1{margin:0;font-size:18px}.expand-head p{margin:4px 0 0;color:#d97706}
.expand-card,.expand-result{background:#fff;border:1px solid #e5e7eb;border-radius:4px;padding:14px;margin-bottom:14px}
.mode-row{margin-bottom:12px}
.expand-form{display:grid;grid-template-columns:1.2fr 1.1fr 1.2fr 150px 86px;gap:10px;align-items:end}
.expand-form label{display:grid;gap:5px;color:#5b6470}
.picked{height:32px;display:flex;justify-content:space-between;align-items:center;border:1px solid #dcdfe6;border-radius:4px;padding:0 8px;background:#fbfcfd}
.picked.disabled{background:#f5f7fa;color:#a8abb2}
.result-summary{display:grid;grid-template-columns:repeat(5,1fr);gap:10px;margin-bottom:12px}
.result-summary div{border:1px solid #e5e7eb;background:#f8faf9;padding:10px;display:grid;gap:4px}
.result-summary span{color:#6b7280}.result-summary b{font-size:16px}
.expand-tabs{margin-top:8px}.danger{color:#d93026;font-weight:700}
.suggestion{margin-top:12px;padding:12px;background:#fff8ed;border:1px solid #fde2bd}
.suggestion h3{margin:0 0 8px;font-size:13px}.suggestion p{margin:4px 0}
.picker-filter{display:grid;grid-template-columns:1fr 140px 70px;gap:8px;margin-bottom:10px}
.el-pagination{margin-top:10px;text-align:right}
</style>
