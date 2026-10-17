<template>
  <div class="bom-expand-page">
    <!-- 页面全局头部卡片：白底圆角独立卡片 (100% 对齐主数据中心 / SkuList.vue 风格) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-cpu" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">BOM 展开与用料算量</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">
              虚拟仿真试算
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" class="btn-default" @click="$router.push('/bom/boms')">
          返回 BOM 管理
        </el-button>
        <el-button size="small" type="success" icon="el-icon-cpu" class="btn-theme-create" @click="expand">
          执行用料算量
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面业务提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>BOM 展开仅执行多级用料需求与库存可用性仿真推算，不扣减实际账面库存，不生成采购需求或生产工单。</span>
    </div>

    <!-- 算量参数配置卡片 -->
    <section class="table-container-card config-card">
      <div class="config-header">
        <div class="section-title">
          <h3><i class="el-icon-setting" /> 算量对象与数量配置</h3>
        </div>
        <div class="mode-select-wrap">
          <el-radio-group v-model="mode" size="small" class="mode-radio-group">
            <el-radio-button label="bom">按 BOM 方案</el-radio-button>
            <el-radio-button label="sku">按 SKU 规格</el-radio-button>
            <el-radio-button label="item">按产出物料</el-radio-button>
          </el-radio-group>
        </div>
      </div>

      <div class="expand-form-grid">
        <div class="form-field-group">
          <label class="field-label">BOM 方案版本</label>
          <el-select
            v-model="form.bom_id"
            filterable
            clearable
            size="small"
            :disabled="mode !== 'bom'"
            placeholder="请选择 BOM 方案版本"
            class="full-width"
          >
            <el-option
              v-for="b in boms"
              :key="b.id"
              :label="`${b.bom_no} / ${b.bom_name} / ${b.version}`"
              :value="b.id"
            />
          </el-select>
        </div>

        <div class="form-field-group">
          <label class="field-label">目标 SKU</label>
          <el-select
            v-model="form.sku_id"
            filterable
            clearable
            size="small"
            :disabled="mode !== 'sku'"
            placeholder="请选择关联 SKU"
            class="full-width"
          >
            <el-option
              v-for="s in skus"
              :key="s.id"
              :label="`${s.sku_code} / ${s.sku_name}`"
              :value="s.id"
            />
          </el-select>
        </div>

        <div class="form-field-group">
          <label class="field-label">目标产出物料</label>
          <div class="picked-item-box" :class="{ disabled: mode !== 'item' }">
            <span class="picked-name" :title="outputItemLabel">{{ outputItemLabel }}</span>
            <el-button
              :disabled="mode !== 'item'"
              size="mini"
              type="success"
              plain
              class="btn-pick"
              @click="openPicker"
            >
              选择物料
            </el-button>
          </div>
        </div>

        <div class="form-field-group">
          <label class="field-label">计划生产数量</label>
          <el-input-number
            v-model="form.planned_qty"
            size="small"
            :min="0.0001"
            :precision="4"
            controls-position="right"
            class="full-width"
          />
        </div>

        <div class="form-action-group">
          <el-button size="small" type="success" icon="el-icon-cpu" class="btn-theme-submit" @click="expand">
            计算展开
          </el-button>
        </div>
      </div>
    </section>

    <!-- 算量计算结果区域 -->
    <section v-if="result" class="table-container-card result-container-card">
      <div class="section-title">
        <h3><i class="el-icon-data-analysis" /> 用料需求与库存对比推算结果</h3>
      </div>

      <!-- 5 维概览指标卡片 -->
      <section class="metric-overview-grid metric-five-cols">
        <div class="metric-card metric-lines">
          <div class="metric-icon-box"><i class="el-icon-document" /></div>
          <div class="metric-info">
            <span class="metric-label">汇总物料行数</span>
            <strong class="metric-val code-mono">{{ result.summary.line_count }}</strong>
          </div>
        </div>

        <div class="metric-card metric-tree">
          <div class="metric-icon-box"><i class="el-icon-share" /></div>
          <div class="metric-info">
            <span class="metric-label">多级路径行数</span>
            <strong class="metric-val code-mono">{{ result.summary.tree_line_count || 0 }}</strong>
          </div>
        </div>

        <div class="metric-card metric-demand">
          <div class="metric-icon-box"><i class="el-icon-box" /></div>
          <div class="metric-info">
            <span class="metric-label">总用料需求数量</span>
            <strong class="metric-val code-mono">{{ result.summary.total_demand_qty }}</strong>
          </div>
        </div>

        <div class="metric-card metric-avail">
          <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
          <div class="metric-info">
            <span class="metric-label">总可用库存数量</span>
            <strong class="metric-val code-mono">{{ result.summary.total_available_qty }}</strong>
          </div>
        </div>

        <div class="metric-card metric-shortage" :class="{ has_shortage: result.summary.total_shortage_qty > 0 }">
          <div class="metric-icon-box"><i class="el-icon-warning-outline" /></div>
          <div class="metric-info">
            <span class="metric-label">总缺料缺口数量</span>
            <strong class="metric-val code-mono">{{ result.summary.total_shortage_qty }}</strong>
          </div>
        </div>
      </section>

      <!-- 结果明细标签页 -->
      <el-tabs v-model="activeTab" type="card" class="expand-tabs">
        <el-tab-pane label="物料汇总清单" name="summary">
          <el-table :data="result.lines" border size="small" class="result-table erp-page-table">
            <el-table-column prop="component_item_code" label="物料编码" min-width="140">
              <template slot-scope="{ row }">
                <span class="item-code-chip">{{ row.component_item_code }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="component_item_name" label="物料名称" min-width="160" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <strong>{{ row.component_item_name }}</strong>
              </template>
            </el-table-column>
            <el-table-column label="下料要求" min-width="160">
              <template slot-scope="{ row }">{{ cutRequirement(row, true) }}</template>
            </el-table-column>
            <el-table-column prop="unit_name" label="单位" width="75" align="center" />
            <el-table-column prop="planned_qty" label="计划数量" width="95" align="right" class-name="code-mono" />
            <el-table-column prop="demand_qty" label="汇总需求" width="105" align="right">
              <template slot-scope="{ row }">
                <span class="code-mono fw-bold">{{ row.demand_qty }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="quantity_on_hand" label="账面库存" width="95" align="right" class-name="code-mono text-muted" />
            <el-table-column prop="quantity_available" label="可用库存" width="95" align="right">
              <template slot-scope="{ row }">
                <span class="code-mono text-success">{{ row.quantity_available }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="quantity_locked" label="锁定库存" width="95" align="right" class-name="code-mono text-muted" />
            <el-table-column label="缺口数量" width="105" align="right">
              <template slot-scope="{ row }">
                <span v-if="row.shortage_qty > 0" class="shortage-badge">
                  -{{ row.shortage_qty }}
                </span>
                <span v-else class="status-pill enabled">足额</span>
              </template>
            </el-table-column>
            <el-table-column label="来源路径" min-width="260" show-overflow-tooltip>
              <template slot-scope="{ row }">{{ (row.paths || []).join('；') || '-' }}</template>
            </el-table-column>
          </el-table>
        </el-tab-pane>

        <el-tab-pane label="多级展开路径明细" name="paths">
          <el-table :data="result.tree_lines || []" border size="small" class="result-table erp-page-table">
            <el-table-column prop="level" label="层级" width="70" align="center">
              <template slot-scope="{ row }">
                <span class="level-tag">L{{ row.level }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="path" label="来源路径" min-width="240" show-overflow-tooltip />
            <el-table-column prop="parent_bom_no" label="父级BOM" width="145" show-overflow-tooltip />
            <el-table-column prop="child_bom_no" label="子级BOM" width="145" show-overflow-tooltip>
              <template slot-scope="{ row }">{{ row.child_bom_no || '-' }}</template>
            </el-table-column>
            <el-table-column prop="component_item_code" label="物料编码" width="140">
              <template slot-scope="{ row }">
                <span class="item-code-chip">{{ row.component_item_code }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="component_item_name" label="物料名称" min-width="150" show-overflow-tooltip />
            <el-table-column label="下料要求" min-width="160">
              <template slot-scope="{ row }">{{ cutRequirement(row, false) }}</template>
            </el-table-column>
            <el-table-column prop="unit_qty" label="单位用量" width="90" align="right" class-name="code-mono" />
            <el-table-column prop="loss_rate" label="损耗率(%)" width="95" align="right" class-name="code-mono" />
            <el-table-column prop="fixed_qty" label="固定用量" width="90" align="right" class-name="code-mono" />
            <el-table-column prop="demand_qty" label="本路径需求" width="110" align="right">
              <template slot-scope="{ row }">
                <strong class="code-mono text-success">{{ row.demand_qty }}</strong>
              </template>
            </el-table-column>
            <el-table-column label="叶子节点" width="85" align="center">
              <template slot-scope="{ row }">
                <span class="status-pill" :class="row.is_leaf ? 'enabled' : 'disabled'">
                  {{ row.is_leaf ? '是' : '否' }}
                </span>
              </template>
            </el-table-column>
          </el-table>
        </el-tab-pane>
      </el-tabs>

      <!-- 智能缺料建议与操作提示卡片 -->
      <div class="suggestion-card">
        <div class="suggestion-header">
          <i class="el-icon-magic-stick" />
          <h4>算量分析与备料建议（仅供参考，不自动生成业务单据）</h4>
        </div>
        <div v-if="!result.suggestions || !result.suggestions.length" class="empty-suggestion">
          <i class="el-icon-circle-check text-success" /> 当前无物料缺口，现有可用库存完全满足计划生产用量要求。
        </div>
        <div v-else class="suggestion-list">
          <div v-for="(s, i) in result.suggestions" :key="i" class="suggestion-item">
            <i class="el-icon-warning text-warning" />
            <span>{{ s.message }}</span>
          </div>
        </div>
      </div>
    </section>

    <!-- 选择产出物料居中弹窗 (严格遵循 2026-09-24 居中遮罩规范) -->
    <el-dialog
      :visible.sync="picker.visible"
      title="选择产出物料"
      width="860px"
      top="8vh"
      custom-class="erp-themed-dialog"
      append-to-body
      :close-on-click-modal="false"
    >
      <div class="dialog-header-banner">
        <div class="dialog-banner-icon"><i class="el-icon-goods" /></div>
        <div class="dialog-banner-text">
          <span class="dialog-banner-title">选择目标产出物料</span>
          <span class="dialog-banner-desc">系统将自动检索该物料生效的默认 BOM 方案执行展开试算。</span>
        </div>
      </div>

      <div class="picker-filter-toolbar">
        <el-input
          v-model="picker.keyword"
          size="small"
          clearable
          prefix-icon="el-icon-search"
          placeholder="搜索物料编码、名称..."
          class="picker-input"
          @keyup.enter.native="searchItems"
        />
        <el-select v-model="picker.item_type" size="small" clearable placeholder="物料类型" class="picker-select" @change="searchItems">
          <el-option label="全部类型" value="" />
          <el-option label="成品" value="finished_product" />
          <el-option label="半成品" value="semi_finished" />
        </el-select>
        <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-submit" @click="searchItems">查询</el-button>
      </div>

      <el-table :data="picker.rows" border size="small" height="340" class="erp-page-table">
        <el-table-column prop="item_code" label="物料编码" width="150">
          <template slot-scope="{ row }">
            <span class="item-code-chip">{{ row.item_code }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="item_name" label="物料名称" min-width="180" show-overflow-tooltip />
        <el-table-column label="物料类型" width="120" align="center">
          <template slot-scope="{ row }">
            <span class="status-pill disabled">{{ itemTypeText(row.item_type) }}</span>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="90" align="center">
          <template slot-scope="{ row }">
            <el-button size="mini" type="success" plain @click="selectItem(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="pager-row">
        <span class="pager-total">共 {{ picker.total }} 条可选物料</span>
        <el-pagination
          background
          layout="prev, pager, next"
          :current-page.sync="picker.page"
          :page-size.sync="picker.perPage"
          :total="picker.total"
          @current-change="loadItems"
        />
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { listBoms, expandBom } from '@/api/erp/bom'
import { cuttingQuantityBasis, totalLengthMetres } from '@/utils/cutting-quantity.mjs'

export default {
  name: 'BomExpand',
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
      return this.outputItem ? `${this.outputItem.item_code} / ${this.outputItem.item_name}` : '点击选择目标产出物料...'
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
    cutRequirement(row, aggregate) {
      if (cuttingQuantityBasis(row) === 'total_length') {
        const length = totalLengthMetres(aggregate ? row.required_total_length_mm : (row.total_length_mm ?? row.cutting_quantity_snapshot?.total_length_mm))
        return length ? `${aggregate ? '所需总用量' : '每份总用量'} ${length} m` : '总用量未提供'
      }
      if (row.cut_length_mm === null || row.cut_length_mm === undefined) return '无需下料'
      const pieces = aggregate ? row.required_piece_qty : row.piece_qty
      const dimensions = [row.cut_length_mm, row.cut_width_mm, row.cut_thickness_mm]
        .filter(value => value != null)
        .map(value => Number(value).toLocaleString('zh-CN'))
        .join(' × ')
      return `${dimensions} mm × ${Number(pieces).toLocaleString('zh-CN')}${row.cut_width_mm != null ? '件' : '段'}`
    },
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
      if (!payload.bom_id && !payload.sku_id && !payload.output_item_id) {
        return this.$message.error('请选择需要展开算量的 BOM 方案、SKU 或产出物料')
      }
      try {
        const { data } = await expandBom(payload)
        this.result = data
        this.activeTab = 'summary'
        this.$message.success('BOM 展开计算完成，库存未被修改')
      } catch (error) {
        this.$message.error(error.userMessage || 'BOM 展开算量失败')
      }
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
      try {
        const { data } = await listEntity('items', {
          management_scope: 'factory',
          keyword: this.picker.keyword,
          item_type: this.picker.item_type,
          page: this.picker.page,
          per_page: this.picker.perPage
        })
        this.picker.rows = data.data || []
        this.picker.total = data.total || 0
      } catch (error) {
        this.$message.error('加载产出物料失败')
      }
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
.bom-expand-page {
  padding: 16px 20px 24px;
  background: #f8fafc;
  min-height: calc(100vh - 54px);
  box-sizing: border-box;
}

/* 顶部标头卡片 (100% 对齐主数据中心 / SkuList.vue 风格) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #ffffff;
  padding: 16px 20px;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  margin-bottom: 12px;
}
.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}
.head-icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
  color: #008b4b;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.head-title-wrap {
  display: flex;
  flex-direction: column;
}
.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.2;
}
.head-tag {
  font-size: 12px;
  border-radius: 4px;
  height: 24px;
  line-height: 22px;
  padding: 0 8px;
}
.total-tag {
  background: #f0fdf4 !important;
  border-color: #bbf7d0 !important;
  color: #008b4b !important;
  font-weight: 600;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.btn-default {
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #374151 !important;
}
.btn-default:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
  background: #f0fdf4 !important;
}
.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 全局统一提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 13px;
  margin-bottom: 14px;
}
.erp-page-tip i {
  font-size: 15px;
  flex-shrink: 0;
}

/* 白底卡片容器 */
.table-container-card {
  background: #ffffff;
  border-radius: 8px;
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  border: 1px solid #e2e8f0;
  margin-bottom: 14px;
}
.config-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  border-bottom: 1px solid #f1f5f9;
  padding-bottom: 14px;
  margin-bottom: 16px;
  flex-wrap: wrap;
  gap: 12px;
}
.section-title h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}
.section-title h3 i {
  color: #008b4b;
}

.mode-radio-group ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background-color: #008b4b;
  border-color: #008b4b;
  box-shadow: -1px 0 0 0 #008b4b;
}

/* 参数表单栅格 */
.expand-form-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr) auto;
  gap: 14px;
  align-items: end;
}
.form-field-group {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.field-label {
  font-size: 13px;
  font-weight: 500;
  color: #475569;
}
.full-width {
  width: 100%;
}
.picked-item-box {
  height: 32px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  padding: 0 8px;
  background: #ffffff;
  box-sizing: border-box;
}
.picked-item-box.disabled {
  background: #f8fafc;
  border-color: #e2e8f0;
}
.picked-name {
  font-size: 13px;
  color: #1e293b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.picked-item-box.disabled .picked-name {
  color: #94a3b8;
}
.btn-pick {
  flex-shrink: 0;
}
.btn-theme-submit {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.btn-theme-submit:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 5 维概览指标卡片 */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
  gap: 14px;
  margin: 16px 0;
}
.metric-card {
  background: #ffffff;
  border-radius: 8px;
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
}
.metric-icon-box {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.metric-lines .metric-icon-box { background: #f1f5f9; color: #475569; }
.metric-tree .metric-icon-box { background: #e0f2fe; color: #0284c7; }
.metric-demand .metric-icon-box { background: #e8f5e9; color: #008b4b; }
.metric-avail .metric-icon-box { background: #dcfce7; color: #16a34a; }
.metric-shortage .metric-icon-box { background: #f1f5f9; color: #64748b; }
.metric-shortage.has_shortage .metric-icon-box { background: #fef2f2; color: #ef4444; }

.metric-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.metric-label {
  font-size: 13px;
  color: #64748b;
}
.metric-val {
  font-size: 22px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.2;
}
.has_shortage .metric-val {
  color: #ef4444;
}
.code-mono {
  font-family: Consolas, Monaco, "Courier New", monospace;
}
.fw-bold {
  font-weight: 700;
}

/* 结果明细表格 */
.result-table ::v-deep th {
  background: #f8fafc !important;
  color: #334155 !important;
  font-weight: 600;
  font-size: 13px;
}
.item-code-chip {
  display: inline-block;
  padding: 2px 8px;
  background: #f1f5f9;
  color: #0f172a;
  border-radius: 4px;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  font-weight: 600;
  border: 1px solid #e2e8f0;
}
.text-muted {
  color: #94a3b8;
}
.text-success {
  color: #008b4b;
  font-weight: 600;
}
.shortage-badge {
  display: inline-block;
  padding: 2px 8px;
  background: #fef2f2;
  color: #ef4444;
  border: 1px solid #fecaca;
  border-radius: 12px;
  font-weight: 700;
  font-family: Consolas, monospace;
}
.status-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
}
.status-pill.enabled {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}
.status-pill.disabled {
  background: #f1f5f9;
  color: #94a3b8;
  border: 1px solid #e2e8f0;
}
.level-tag {
  display: inline-block;
  padding: 1px 6px;
  background: #e0f2fe;
  color: #0284c7;
  border-radius: 3px;
  font-family: Consolas, monospace;
  font-size: 12px;
  font-weight: 600;
}

/* 建议卡片 */
.suggestion-card {
  margin-top: 16px;
  padding: 14px 18px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
}
.suggestion-header {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
  color: #b45309;
}
.suggestion-header h4 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
}
.empty-suggestion {
  font-size: 13px;
  color: #047857;
  display: flex;
  align-items: center;
  gap: 6px;
}
.suggestion-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.suggestion-item {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #92400e;
}
.text-warning {
  color: #d97706;
}

/* 居中弹窗样式 (完全对齐 ERP 规范) */
.dialog-header-banner {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 12px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}
.dialog-banner-icon {
  width: 36px;
  height: 36px;
  background: #dcfce7;
  color: #008b4b;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.dialog-banner-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.dialog-banner-title {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}
.dialog-banner-desc {
  font-size: 12px;
  color: #008b4b;
}
.picker-filter-toolbar {
  display: flex;
  gap: 10px;
  align-items: center;
  margin-bottom: 12px;
}
.picker-input {
  flex: 1;
}
.picker-select {
  width: 140px;
}
.pager-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 14px;
  flex-wrap: wrap;
  gap: 12px;
}
.pager-total {
  font-size: 13px;
  color: #64748b;
}

/* 响应式断点 */
@media (max-width: 1200px) {
  .expand-form-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
@media (max-width: 768px) {
  .bom-expand-page {
    padding: 12px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-end;
  }
  .expand-form-grid {
    grid-template-columns: 1fr;
  }
  .metric-overview-grid {
    grid-template-columns: 1fr;
  }
}
</style>
