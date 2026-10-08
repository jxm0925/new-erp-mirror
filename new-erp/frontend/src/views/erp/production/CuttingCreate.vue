<template>
  <section class="production-page" v-loading="loading">
    <div class="page-heading"><div><p class="eyebrow">生产管理 / 下料管理</p><h1>安排正式需求下料</h1></div><div class="heading-actions"><el-button @click="$router.back()">返回</el-button><el-button type="success" :loading="publishing" @click="publish">{{ pending ? '重试原发布' : '发布下料单' }}</el-button></div></div>
    <section class="panel">
      <header><h2>正式需求</h2><el-button type="text" @click="loadDemands">刷新需求</el-button></header>
      <div class="filter-row"><el-input v-model="keyword" size="small" clearable placeholder="搜索需求号、工单、物料编码或规格" @keyup.enter.native="search" @clear="search" /><el-button size="small" type="success" @click="search">查询</el-button></div>
      <el-table :data="demands" border size="small" empty-text="暂无正式下料需求">
        <el-table-column label="选择" width="58" align="center"><template slot-scope="{row}"><el-checkbox :value="!!selected[String(row.id)]" :disabled="editLocked || row.revision_pending || !positive(row.remaining_demand_qty)" :aria-label="`选择${row.demand_no}`" @change="selectDemand(row,$event)" /></template></el-table-column>
        <el-table-column prop="demand_no" label="需求号" min-width="145" />
        <el-table-column label="使用工单" min-width="145"><template slot-scope="{row}">{{ row.consumer_work_order && row.consumer_work_order.work_order_no || '—' }}</template></el-table-column>
        <el-table-column label="产出物料" min-width="200"><template slot-scope="{row}"><div>{{ row.item && row.item.item_name }}</div><small>{{ row.item && row.item.spec }}</small></template></el-table-column>
        <el-table-column label="剩余可安排" min-width="130"><template slot-scope="{row}">{{ quantity(row.remaining_demand_qty) }} {{ row.base_unit_name }}</template></el-table-column>
        <el-table-column label="需求状态" min-width="100"><template slot-scope="{row}">{{ row.revision_pending ? '待确认变更' : '有效需求' }}</template></el-table-column>
      </el-table>
      <el-pagination background small layout="prev,pager,next,total" :current-page="page" :page-size="20" :total="total" @current-change="changePage" />
    </section>
    <section class="panel">
      <header><h2>本次下料安排</h2><span>{{ selectedRows.length }}条需求</span></header>
      <article v-for="row in selectedRows" :key="row.id" class="plan-card">
        <div class="plan-heading"><strong>{{ row.item && row.item.item_name }} · {{ row.demand_no }}</strong><el-button type="text" :disabled="editLocked" @click="selectDemand(row,false)">移除</el-button></div>
        <p>{{ row.consumer_work_order && row.consumer_work_order.work_order_no }} · {{ row.item && row.item.spec }}</p>
        <div class="plan-fields"><div><label>生产工单与原料需求</label><div v-if="row.producer" class="producer-summary">{{ row.producer.work_order_no }} · {{ row.producer.operation_name }}<br />{{ row.producer.input_item_code }} · {{ row.producer.input_item_name }}</div><el-button size="small" :disabled="editLocked" @click="openProducer(row)">{{ row.producer ? '更换生产来源' : '选择生产来源' }}</el-button></div><div><label :for="`cutting-plan-qty-${row.id}`">本次安排数量（{{ row.base_unit_name || '库存基本单位' }}）</label><el-input :id="`cutting-plan-qty-${row.id}`" v-model="row.planned_qty" size="small" :disabled="editLocked" inputmode="decimal" placeholder="请输入本次安排数量" /><small>剩余可安排 {{ quantity(row.remaining_demand_qty) }} {{ row.base_unit_name }}</small></div></div>
      </article>
      <el-empty v-if="!selectedRows.length" :image-size="60" description="请选择正式需求" />
    </section>
    <el-dialog title="选择生产工单与原料需求" :visible.sync="producerVisible" width="1000px" append-to-body custom-class="cutting-producer-dialog" :close-on-click-modal="false" @closed="resetProducer">
      <div class="filter-row"><el-input v-model="producerKeyword" size="small" clearable placeholder="搜索生产工单、工序、原料编码或规格" @keyup.enter.native="searchProducers" @clear="searchProducers" /><el-button size="small" type="success" @click="searchProducers">查询</el-button></div>
      <el-table :data="producers" v-loading="producerLoading" border size="small" empty-text="暂无符合该需求的生产来源">
        <el-table-column prop="work_order_no" label="生产工单" min-width="150" /><el-table-column prop="operation_name" label="生产工序" min-width="120" />
        <el-table-column label="原料需求" min-width="200"><template slot-scope="{row}"><div>{{ row.input_item_code }}</div><div>{{ row.input_item_name }}</div></template></el-table-column>
        <el-table-column label="原料需求量" min-width="130"><template slot-scope="{row}">{{ quantity(row.input_required_base_qty) }} {{ row.input_base_unit_name }}</template></el-table-column>
        <el-table-column label="选择" min-width="150"><template slot-scope="{row}"><el-button v-if="row.eligible" size="small" type="success" :disabled="editLocked" @click="chooseProducer(row)">选择</el-button><span v-else>{{ row.reason }}</span></template></el-table-column>
      </el-table>
      <el-pagination background small layout="prev,pager,next,total" :current-page="producerPage" :page-size="20" :total="producerTotal" @current-change="changeProducerPage" />
      <span slot="footer"><el-button @click="producerVisible=false">取消</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { listCuttingDemands, listCuttingDemandProducers, publishCuttingOrder } from '../../../api/erp/cutting'
import { pendingAssemblyCommand, executeAssemblyCommand, productionQuantity } from '../../../api/erp/assembly-production'

export default {
  name: 'CuttingCreate',
  data: () => ({ loading: false, publishing: false, demands: [], selected: {}, page: 1, total: 0, keyword: '', pending: null,
    producerVisible: false, producerDemandId: null, producers: [], producerLoading: false, producerPage: 1, producerTotal: 0, producerKeyword: '' }),
  computed: { selectedRows () { return Object.values(this.selected) }, editLocked () { return this.publishing || Boolean(this.pending) } },
  created () {
    this.restorePending()
    this.loadDemands()
  },
  methods: {
    quantity: productionQuantity,
    restoreSelection (rows) { this.selected = {}; for (const row of rows || []) this.$set(this.selected, String(row.id), JSON.parse(JSON.stringify(row))) },
    restorePending () { this.pending = pendingAssemblyCommand('cutting-formal-create'); if (this.pending) this.restoreSelection(this.pending.display_rows) },
    positive (value) { return /^(0|[1-9]\d*)(\.\d+)?$/.test(String(value)) && /[1-9]/.test(String(value)) },
    search () { this.page = 1; return this.loadDemands() },
    changePage (page) { this.page = page; return this.loadDemands() },
    async loadDemands () {
      const sequence = this.sequence = (this.sequence || 0) + 1
      this.loading = true
      try { const { data } = await listCuttingDemands({ page: this.page, per_page: 20, status: 'ACTIVE', keyword: this.keyword.trim() }); if (sequence === this.sequence) { this.demands = data.data || []; this.total = Number(data.meta?.total || 0) } }
      catch (error) { if (sequence === this.sequence) this.$message.error(error.userMessage || '正式需求加载失败') }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    selectDemand (row, checked) {
      if (this.editLocked) return
      if (!checked) return this.$delete(this.selected, String(row.id))
      if (row.revision_pending || !this.positive(row.remaining_demand_qty)) return
      if (this.selectedRows.length >= 100) return this.$message.error('一次最多安排100条正式需求')
      this.$set(this.selected, String(row.id), { ...JSON.parse(JSON.stringify(row)), producer: null, planned_qty: '' })
    },
    openProducer (row) { if (this.editLocked) return; this.resetProducer(); this.producerDemandId = row.id; this.producerVisible = true; this.loadProducers() },
    resetProducer () { this.producerSequence = (this.producerSequence || 0) + 1; this.producerDemandId = null; this.producers = []; this.producerPage = 1; this.producerTotal = 0; this.producerKeyword = ''; this.producerLoading = false },
    searchProducers () { this.producerPage = 1; return this.loadProducers() },
    changeProducerPage (page) { this.producerPage = page; return this.loadProducers() },
    async loadProducers () {
      const sequence = this.producerSequence = (this.producerSequence || 0) + 1
      this.producerLoading = true
      try { const { data } = await listCuttingDemandProducers(this.producerDemandId, { page: this.producerPage, per_page: 20, keyword: this.producerKeyword.trim() }); if (sequence === this.producerSequence && this.producerVisible) { this.producers = data.data || []; this.producerTotal = Number(data.meta?.total || 0) } }
      catch (error) { if (sequence === this.producerSequence) this.$message.error(error.userMessage || '生产来源加载失败') }
      finally { if (sequence === this.producerSequence) this.producerLoading = false }
    },
    chooseProducer (row) { if (!row.eligible || this.editLocked || !this.selected[String(this.producerDemandId)]) return; this.selected[String(this.producerDemandId)].producer = JSON.parse(JSON.stringify(row)); this.producerVisible = false },
    async publish () {
      if (this.publishing) return
      if (!this.pending && !this.selectedRows.length) return this.$message.error('请先选择正式需求')
      if (!this.pending && this.selectedRows.some(row => !row.producer || !/^(0|[1-9]\d{0,19})(\.\d{1,8})?$/.test(String(row.planned_qty)) || !this.positive(row.planned_qty))) return this.$message.error('请为每条需求选择生产来源并填写本次安排数量')
      this.publishing = true
      this.producerVisible = false
      try {
        const displayRows = JSON.parse(JSON.stringify(this.pending?.display_rows || this.selectedRows))
        this.restoreSelection(displayRows)
        const plans = this.pending?.payload?.plans || displayRows.map(row => ({ work_order_id: row.producer.work_order_id, stage_id: row.producer.stage_id, planned_qty: String(row.planned_qty),
          target_material_requirement_id: row.producer.target_material_requirement_id, input_material_requirement_id: row.producer.input_material_requirement_id,
          ...(row.producer.configuration_id ? { configuration_id: row.producer.configuration_id } : {}) }))
        const { data } = await executeAssemblyCommand('cutting-formal-create', { purpose: 'FORMAL', expected_version: 0, plans }, publishCuttingOrder, displayRows)
        this.$message.success('下料单已发布'); this.$router.push(`/production/cutting/${data.data.cutting_order_id}`)
      } catch (error) { this.restorePending(); this.$message.error(error.userMessage || '发布失败，请核对正式需求') }
      finally { this.publishing = false }
    }
  }
}
</script>

<style scoped>
.production-page {
  padding: 16px 20px 28px;
  min-width: 0;
  box-sizing: border-box;
}
.page-heading, .heading-actions, .panel header, .filter-row, .plan-heading {
  display: flex;
  flex-wrap: wrap;
  gap: 12px;
  min-width: 0;
  align-items: center;
}
.page-heading, .panel header, .plan-heading {
  justify-content: space-between;
}
.page-heading {
  margin-bottom: 14px;
  height: auto;
  min-height: 0;
}
.heading-actions .el-button {
  margin-left: 0;
}
.eyebrow {
  margin: 0;
  color: #64717d;
  font-size: 12px;
}
.page-heading h1 {
  margin: 4px 0 0;
  font-size: 22px;
}
.panel {
  background: #fff;
  border: 1px solid #e6ebf0;
  border-radius: 8px;
  padding: 14px;
  margin-bottom: 14px;
  min-width: 0;
}
.panel header {
  margin-bottom: 12px;
  height: auto;
  min-height: 0;
}
.panel h2 {
  margin: 0;
  font-size: 16px;
}
.filter-row {
  margin-bottom: 16px;
}
.filter-row .el-input {
  flex: 1 1 240px;
  min-width: 0;
}
.el-pagination {
  margin-top: 14px;
  max-width: 100%;
  white-space: normal;
}
.plan-card {
  padding: 14px;
  border: 1px solid #e6ebf0;
  border-radius: 6px;
  margin-top: 12px;
  min-width: 0;
  overflow-wrap: anywhere;
}
.plan-card p, .plan-card small, .plan-card label {
  color: #728098;
  font-size: 12px;
}
.plan-fields {
  display: grid;
  grid-template-columns: repeat(2,minmax(0,1fr));
  gap: 16px;
}
.plan-fields>div {
  min-width: 0;
}
.plan-fields label {
  display: block;
  margin-bottom: 8px;
}
.producer-summary {
  font-size: 13px;
  line-height: 1.6;
  margin-bottom: 8px;
}
.plan-card small {
  display: block;
  margin-top: 6px;
}
@media(max-width:780px) {
  .production-page {
    padding: 12px;
  }
  .plan-fields {
    grid-template-columns: minmax(0,1fr);
  }
}
</style>
<style>
.production-page .el-button--text,
.cutting-producer-dialog .el-button--text {
  color: #008b4b;
}
.production-page .el-checkbox__input.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}
.production-page .el-checkbox__input.is-focus .el-checkbox__inner,
.production-page .el-input__inner:focus,
.cutting-producer-dialog .el-input__inner:focus {
  border-color: #008b4b;
}
.cutting-producer-dialog {
  max-width: calc(100vw - 32px);
  max-height: 85vh;
  margin: 5vh auto 0 !important;
  display: flex;
  flex-direction: column;
}
.cutting-producer-dialog .el-dialog__body {
  overflow: auto;
  min-height: 0;
}
.cutting-producer-dialog .el-dialog__footer {
  flex-shrink: 0;
}
@media(max-width:430px) {
  .cutting-producer-dialog .el-dialog__body, .cutting-producer-dialog .el-dialog__footer {
    padding: 12px;
  }
}
</style>
