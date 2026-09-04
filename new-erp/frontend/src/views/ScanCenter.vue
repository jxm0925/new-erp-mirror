<template>
  <section class="page">
    <div class="page-head">
      <div><h1>旧系统扫描工作台</h1><p>只读分析 jiantan-erp，先看清旧数据，再决定新系统如何承接。</p></div>
      <el-tag type="success" effect="plain"><i class="el-icon-lock" /> 只读模式</el-tag>
    </div>

    <div class="stage-flow">
      <div v-for="(step, index) in steps" :key="step" :class="{ current: index === activeStep, done: index < activeStep }">
        <span>{{ index + 1 }}</span><strong>{{ step }}</strong>
      </div>
    </div>

    <div class="metrics">
      <article><span>扫描旧表</span><strong>{{ overview.table_count || 0 }}</strong><small>来自 SQL 结构</small></article>
      <article><span>已建立映射</span><strong>{{ overview.mapped_count || 0 }}</strong><small>表级或字段级</small></article>
      <article><span>已确认范围</span><strong>{{ overview.confirmed_count || 0 }}</strong><small>迁移 / 不迁移</small></article>
      <article class="risk"><span>待处理风险</span><strong>{{ overview.risk_count || 0 }}</strong><small>进入主数据前必须确认</small></article>
    </div>

    <el-tabs v-model="activeTab" @tab-click="onTab">
      <el-tab-pane label="表扫描工作台" name="tables" />
      <el-tab-pane label="字段字典" name="dictionary" />
      <el-tab-pane label="旧新字段映射" name="mappings" />
      <el-tab-pane label="数据风险审计" name="risks" />
      <el-tab-pane label="goods_id 全链路" name="goods-cross" />
      <el-tab-pane label="旧表关系图" name="relations" />
      <el-tab-pane label="迁移范围确认" name="scope" />
      <el-tab-pane label="待确认问题" name="questions" />
    </el-tabs>

    <div v-if="['tables','dictionary','scope'].includes(activeTab)" class="workspace">
      <div class="content-card">
        <div class="toolbar">
          <el-input v-model="query.keyword" prefix-icon="el-icon-search" placeholder="搜索旧表或业务含义" clearable @keyup.enter.native="loadTables" />
          <el-select v-model="query.module" clearable placeholder="全部模块"><el-option v-for="m in modules" :key="m" :label="moduleLabel(m)" :value="m" /></el-select>
          <el-button @click="loadTables">查询</el-button>
          <el-button type="primary" @click="selected && inspect(selected)">查看字段</el-button>
        </div>
        <el-table v-loading="loading" :data="tables" highlight-current-row @current-change="selected = $event" @row-dblclick="inspect">
          <el-table-column prop="table_name" label="旧表名" min-width="210" />
          <el-table-column prop="business_name" label="业务含义" width="140" />
          <el-table-column prop="field_count" label="字段数" width="80" />
          <el-table-column label="建议新模块" width="130"><template slot-scope="{row}">{{ moduleLabel(row.suggested_module) }}</template></el-table-column>
          <el-table-column label="风险" width="90"><template slot-scope="{row}"><el-tag :type="row.risk_level === 'high' ? 'danger' : 'warning'" size="mini">{{ row.risk_level === 'high' ? '高' : '中' }}</el-tag></template></el-table-column>
          <el-table-column label="迁移范围" width="130"><template><span class="muted">待业务确认</span></template></el-table-column>
          <el-table-column label="操作" width="100"><template slot-scope="{row}"><el-button type="text" @click="inspect(row)">字段字典</el-button></template></el-table-column>
        </el-table>
        <div v-if="!loading && !tables.length" class="empty"><i class="el-icon-document-delete" /><strong>没有匹配的旧表</strong><span>调整筛选条件后重试。</span></div>
      </div>
      <aside class="inspector">
        <template v-if="detail.table">
          <div class="inspector-head"><div><small>当前旧表</small><h3>{{ detail.table.table_name }}</h3></div><el-tag type="warning">{{ detail.table.field_count }} 字段</el-tag></div>
          <p>{{ detail.table.business_name }} · 建议进入 {{ moduleLabel(detail.table.suggested_module) }}</p>
          <h4>字段字典</h4>
          <div class="field-list"><button v-for="field in detail.fields" :key="field.field_name" @click="openMapping(detail.table, field)"><strong>{{ field.field_name }}</strong><span>{{ field.field_type }}</span><small>{{ field.field_comment || '含义待确认' }}</small></button></div>
        </template>
        <div v-else class="empty compact"><i class="el-icon-view" /><strong>选择一张旧表</strong><span>查看字段、风险与映射建议。</span></div>
      </aside>
    </div>

    <div v-else-if="activeTab === 'mappings'" class="content-card">
      <el-table :data="mappings"><el-table-column prop="legacy_table" label="旧表" /><el-table-column prop="legacy_field" label="旧字段" /><el-table-column prop="target_module" label="新模块" /><el-table-column prop="target_table" label="新表" /><el-table-column prop="target_field" label="新字段" /><el-table-column prop="mapping_type" label="映射类型" /><el-table-column prop="confirmation_status" label="确认状态" /></el-table>
      <div v-if="!mappings.length" class="empty"><i class="el-icon-connection" /><strong>尚未建立字段映射</strong><span>从字段字典中选择字段，建立第一条映射。</span></div>
    </div>

    <div v-else-if="activeTab === 'risks'" class="content-card">
      <el-table :data="risks"><el-table-column label="等级" width="90"><template slot-scope="{row}"><el-tag :type="row.level === 'high' ? 'danger' : 'warning'">{{ row.level === 'high' ? '高' : '中' }}</el-tag></template></el-table-column><el-table-column prop="table" label="旧表" min-width="180" /><el-table-column prop="field" label="字段" width="130" /><el-table-column prop="type" label="风险类型" width="170" /><el-table-column prop="message" label="判断依据" min-width="360" /><el-table-column label="处理状态" width="100"><template><span class="status-dot">待确认</span></template></el-table-column></el-table>
    </div>

    <div v-else-if="activeTab === 'goods-cross'" class="content-card">
      <div class="cross-summary">
        <span>订单行 <strong>{{ goodsAudit.summary.order_total || 0 }}</strong></span>
        <span>订单 SKU 孤儿 <strong class="danger">{{ goodsAudit.summary.order_sku_orphan || 0 }}</strong></span>
        <span>订单商品/SKU冲突 <strong class="danger">{{ goodsAudit.summary.order_mismatch || 0 }}</strong></span>
        <span>工单行 <strong>{{ goodsAudit.summary.work_total || 0 }}</strong></span>
        <span>工单 SKU 孤儿 <strong class="danger">{{ goodsAudit.summary.work_sku_orphan || 0 }}</strong></span>
        <span>工单商品/SKU冲突 <strong class="danger">{{ goodsAudit.summary.work_mismatch || 0 }}</strong></span>
      </div>
      <el-alert title="订单与工单 goods_id 指旧商品，sku_id 指旧 SKU；BOM goods_id 指旧 SKU。下列异常不得静默迁移。" type="warning" :closable="false" show-icon />
      <el-table :data="goodsAudit.rows" height="470">
        <el-table-column prop="source_table" label="来源表" width="175" />
        <el-table-column prop="source_no" label="来源单号" width="150" />
        <el-table-column prop="goods_id" label="旧 goods_id" width="100" />
        <el-table-column prop="sku_id" label="旧 sku_id" width="90" />
        <el-table-column prop="legacy_product_name" label="旧商品名称" min-width="150" />
        <el-table-column prop="legacy_sku_name" label="旧 SKU 名称" min-width="190" />
        <el-table-column prop="anomaly_type" label="异常类型" width="160" />
        <el-table-column label="可映射" width="110"><template slot-scope="{row}">P {{ row.can_map_product ? '✓' : '×' }} / SKU {{ row.can_map_sku ? '✓' : '×' }} / Item {{ row.can_map_item ? '✓' : '待定' }}</template></el-table-column>
        <el-table-column prop="status" label="处理状态" width="90" />
        <el-table-column prop="suggestion" label="处理建议" min-width="260" />
      </el-table>
    </div>

    <div v-else-if="activeTab === 'relations'" class="relation-board">
      <div v-for="group in relationGroups" :key="group.title" class="lane"><h3>{{ group.title }}</h3><div><article v-for="node in group.nodes" :key="node"><i class="el-icon-coin" /><strong>{{ node }}</strong><span>待扫描确认</span></article></div></div>
      <el-alert title="关系图仅展示候选关系；外键、业务编码和代码查询必须共同验证后才能确认。" type="warning" show-icon :closable="false" />
    </div>

    <div v-else class="content-card">
      <div class="toolbar">
        <el-select v-model="questionFilters.status" clearable placeholder="状态"><el-option v-for="v in ['open','checking','resolved','blocked','ignored']" :key="v" :label="v" :value="v" /></el-select>
        <el-select v-model="questionFilters.priority" clearable placeholder="优先级"><el-option v-for="v in ['P0','P1','P2']" :key="v" :label="v" :value="v" /></el-select>
        <el-input v-model="questionFilters.keyword" clearable placeholder="阶段 / 分类 / 确认人" />
      </div>
      <el-table :data="filteredQuestions">
        <el-table-column prop="question_no" label="编号" width="75" />
        <el-table-column prop="priority" label="优先级" width="70"><template slot-scope="{row}"><el-tag :type="row.priority === 'P0' ? 'danger' : row.priority === 'P1' ? 'warning' : 'info'" size="mini">{{ row.priority }}</el-tag></template></el-table-column>
        <el-table-column prop="impact_stage" label="影响阶段" width="125" />
        <el-table-column prop="category" label="分类" width="125" />
        <el-table-column prop="legacy_table" label="关联旧表" min-width="150" />
        <el-table-column prop="question" label="待确认问题" min-width="360" />
        <el-table-column prop="owner" label="建议确认人" width="170" />
        <el-table-column prop="status" label="状态" width="85" />
        <el-table-column prop="conclusion" label="确认结论" min-width="150" />
      </el-table>
    </div>

    <el-dialog title="建立旧新字段映射" :visible.sync="mappingVisible" width="580px">
      <el-form :model="mapping" label-width="100px">
        <el-form-item label="旧字段"><el-input :value="`${mapping.legacy_table}.${mapping.legacy_field}`" disabled /></el-form-item>
        <el-form-item label="新模块"><el-select v-model="mapping.target_module"><el-option label="主数据" value="master" /><el-option label="销售订单" value="sales" /><el-option label="库存" value="stock" /><el-option label="生产" value="production" /></el-select></el-form-item>
        <el-form-item label="新表"><el-input v-model="mapping.target_table" placeholder="例如 products" /></el-form-item>
        <el-form-item label="新字段"><el-input v-model="mapping.target_field" placeholder="例如 product_code" /></el-form-item>
        <el-form-item label="映射类型"><el-select v-model="mapping.mapping_type"><el-option label="一对一" value="one_to_one" /><el-option label="拆分" value="split" /><el-option label="合并" value="merge" /><el-option label="不迁移" value="ignore" /></el-select></el-form-item>
        <el-form-item label="确认状态"><el-radio-group v-model="mapping.confirmation_status"><el-radio label="pending">待确认</el-radio><el-radio label="confirmed">已确认</el-radio></el-radio-group></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="mappingVisible=false">取消</el-button><el-button type="primary" @click="submitMapping">保存映射</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { getFields, getGoodsCrossReference, getMappings, getOverview, getQuestions, getRisks, getTables, saveMapping } from '../api/scan'

export default {
  data() {
    return {
      activeTab: 'tables', activeStep: 0, loading: false, overview: {}, tables: [], selected: null,
      detail: {}, mappings: [], risks: [], questions: [], mappingVisible: false,
      goodsAudit: { summary: {}, rows: [] },
      query: { keyword: '', module: '' },
      modules: ['product', 'order', 'stock', 'purchase', 'production', 'customer', 'supplier', 'finance', 'admin', 'other'],
      steps: ['扫描旧表', '确认字段语义', '审计数据风险', '确认迁移范围', '冻结阶段结论'],
      relationGroups: [
        { title: '销售与客户', nodes: ['客户', '销售订单', '订单明细'] },
        { title: '商品与供应', nodes: ['商品', 'SKU', '物料候选', '供应商'] },
        { title: '仓储与生产', nodes: ['仓库', '库存记录', 'BOM候选', '工单候选'] }
      ],
      mapping: {},
      questionFilters: { status: '', priority: '', keyword: '' }
    }
  },
  async created() {
    await Promise.all([this.loadOverview(), this.loadTables()])
  },
  computed: {
    filteredQuestions() {
      const keyword = this.questionFilters.keyword.trim().toLowerCase()
      return this.questions.filter(row =>
        (!this.questionFilters.status || row.status === this.questionFilters.status) &&
        (!this.questionFilters.priority || row.priority === this.questionFilters.priority) &&
        (!keyword || `${row.impact_stage} ${row.category} ${row.owner}`.toLowerCase().includes(keyword))
      )
    }
  },
  methods: {
    async loadOverview() { this.overview = await getOverview(); this.$emit('overview', this.overview) },
    async loadTables() { this.loading = true; try { const data = await getTables(this.query); this.tables = data.rows || [] } finally { this.loading = false } },
    async inspect(row) { this.selected = row; this.detail = await getFields(row.table_name) },
    async onTab() {
      const map = { tables: 0, dictionary: 1, mappings: 1, risks: 2, 'goods-cross': 2, relations: 2, scope: 3, questions: 4 }
      this.activeStep = map[this.activeTab]
      if (this.activeTab === 'mappings') { const d = await getMappings(); this.mappings = d.rows || [] }
      if (this.activeTab === 'risks') { const d = await getRisks(); this.risks = d.rows || [] }
      if (this.activeTab === 'goods-cross') { this.goodsAudit = await getGoodsCrossReference() }
      if (this.activeTab === 'questions') { const d = await getQuestions(); this.questions = d.rows || [] }
    },
    openMapping(table, field) {
      this.mapping = { legacy_table: table.table_name, legacy_field: field.field_name, target_module: 'master', target_table: '', target_field: '', mapping_type: 'one_to_one', migration_strategy: 'direct', confirmation_status: 'pending', remark: '' }
      this.mappingVisible = true
    },
    async submitMapping() { await saveMapping(this.mapping); this.$message.success('字段映射已保存'); this.mappingVisible = false; await this.loadOverview() },
    moduleLabel(value) { return ({ product: '商品', order: '销售订单', stock: '库存', purchase: '采购', production: '生产', customer: '客户', supplier: '供应商', finance: '财务', admin: '系统', other: '待确认' })[value] || value }
  }
}
</script>
