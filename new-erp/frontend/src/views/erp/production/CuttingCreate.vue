<template>
  <section class="production-page" v-loading="loading">
    <div class="page-heading">
      <div>
        <p class="eyebrow">生产管理 / 下料管理</p>
        <h1>新增 / 安排下料单</h1>
      </div>
      <div class="heading-actions">
        <el-button @click="$router.back()">返回</el-button>
        <el-button type="success" :loading="publishing" @click="publish">发布下料单</el-button>
      </div>
    </div>

    <section class="panel">
      <header><h2>正式需求</h2><el-button type="text" @click="loadDemands">刷新需求</el-button></header>
      <el-table :data="demands" border height="280" @selection-change="onDemandSelect" empty-text="暂无正式下料需求">
        <el-table-column type="selection" width="48" />
        <el-table-column prop="demand_no" label="需求号" min-width="140" />
        <el-table-column label="工单" min-width="140">
          <template slot-scope="{ row }">{{ row.work_order_no || row.source_work_order_no || '-' }}</template>
        </el-table-column>
        <el-table-column label="物料" min-width="180">
          <template slot-scope="{ row }">{{ row.item_name || row.component_item_name_snapshot || row.item_code || '-' }}</template>
        </el-table-column>
        <el-table-column label="剩余可安排" width="110">
          <template slot-scope="{ row }">{{ row.remaining_qty || row.available_qty || row.open_qty || '-' }}</template>
        </el-table-column>
      </el-table>
    </section>

    <section class="panel">
      <header>
        <h2>材料安排</h2>
        <el-button type="primary" plain :disabled="!selectedDemands.length" @click="materialVisible = true">选择材料</el-button>
      </header>
      <el-table :data="selectedMaterials" border empty-text="请选择正式需求后添加材料">
        <el-table-column prop="label" label="材料" min-width="220" />
        <el-table-column prop="kind_label" label="类型" width="120" />
        <el-table-column prop="qty_text" label="数量 / 规格" min-width="160" />
        <el-table-column label="操作" width="90">
          <template slot-scope="{ $index }">
            <el-button type="text" @click="selectedMaterials.splice($index, 1)">移除</el-button>
          </template>
        </el-table-column>
      </el-table>
    </section>

    <section class="panel">
      <header><h2>发布前核对</h2></header>
      <ul class="checklist">
        <li>来源：{{ selectedDemands.length ? `已选 ${selectedDemands.length} 条正式需求` : '尚未选择正式需求' }}</li>
        <li>材料：{{ selectedMaterials.length ? `已选 ${selectedMaterials.length} 项` : '尚未选择材料' }}</li>
        <li>下料执行任务将在发布后独立生成，不占用产品工序任务。</li>
      </ul>
    </section>

    <cutting-material-selector
      :visible.sync="materialVisible"
      @confirm="onMaterialsConfirm"
    />
  </section>
</template>

<script>
import { listCuttingDemands, publishCuttingOrder } from '../../../api/erp/cutting'
import CuttingMaterialSelector from './CuttingMaterialSelector.vue'

export default {
  name: 'CuttingCreate',
  components: { CuttingMaterialSelector },
  data: () => ({
    loading: false,
    publishing: false,
    demands: [],
    selectedDemands: [],
    selectedMaterials: [],
    materialVisible: false
  }),
  created() { this.loadDemands() },
  methods: {
    async loadDemands() {
      this.loading = true
      try {
        const { data } = await listCuttingDemands({ page: 1, per_page: 50, open_only: 1 })
        const payload = data.data || data
        this.demands = Array.isArray(payload) ? payload : (payload.data || [])
      } catch (error) {
        this.demands = []
        this.$message.error(error.userMessage || '正式需求加载失败')
      } finally {
        this.loading = false
      }
    },
    onDemandSelect(rows) { this.selectedDemands = rows },
    onMaterialsConfirm(rows) {
      const mapped = rows.map(row => ({
        ...row,
        label: row.physical_no || row.batch_no || row.item_name || row.label || ('材料#' + row.id),
        kind_label: row.kind_label || row.material_kind || (row.physical_no ? '钢板/实物' : '批次'),
        qty_text: row.qty_text || row.spec || row.dimension_text || '-'
      }))
      const key = x => String(x.physical_material_id || x.id || x.label)
      const existing = new Set(this.selectedMaterials.map(key))
      mapped.forEach(row => { if (!existing.has(key(row))) this.selectedMaterials.push(row) })
      this.materialVisible = false
    },
    async publish() {
      if (!this.selectedDemands.length) return this.$message.error('请先选择正式需求')
      if (!this.selectedMaterials.length) return this.$message.error('请先选择材料')
      this.publishing = true
      try {
        const plans = this.selectedDemands.map(x => {
            const plan = {
              work_order_id: Number(x.work_order_id || x.source_work_order_id),
              stage_id: Number(x.stage_id || x.routing_operation_id || x.operation_stage_id),
              planned_qty: String(x.remaining_qty || x.available_qty || x.open_qty || x.planned_qty || '1'),
              target_material_requirement_id: Number(x.target_material_requirement_id || x.source_requirement_id || x.formal_requirement_id || x.id),
              input_material_requirement_id: Number(x.input_material_requirement_id || x.material_requirement_id || 0)
            }
            if (x.configuration_id) plan.configuration_id = Number(x.configuration_id)
            return plan
          }).filter(p => p.work_order_id && p.stage_id && p.input_material_requirement_id)
        if (!plans.length) {
          this.$message.error('所选需求缺少工单/工序/投入物料需求字段，无法发布')
          return
        }
        const payload = {
          expected_version: 0,
          plans,
          // 材料占用仍走发布后的 reserve/issue；此处先校验已选材料便于现场排产
          selected_materials: this.selectedMaterials.map(x => ({
            physical_material_id: x.physical_material_id || x.id,
            batch_no: x.batch_no,
            quantity: x.quantity
          }))
        }
        const { data } = await publishCuttingOrder(payload)
        this.$message.success(data.message || '下料单已发布')
        const id = data.data && (data.data.id || data.data.cutting_order_id)
        if (id) this.$router.push(`/production/cutting/${id}`)
        else this.$router.push('/production/cutting')
      } catch (error) {
        this.$message.error(error.userMessage || '发布失败，请核对需求与材料后重试')
      } finally {
        this.publishing = false
      }
    }
  }
}
</script>

<style scoped>
.production-page { padding: 16px 20px 28px; }
.page-heading { display: flex; justify-content: space-between; margin-bottom: 14px; }
.eyebrow { margin: 0; color: #64717d; font-size: 12px; }
.page-heading h1 { margin: 4px 0 0; font-size: 22px; }
.panel { background: #fff; border: 1px solid #e6ebf0; border-radius: 8px; padding: 14px; margin-bottom: 14px; }
.panel header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.panel h2 { margin: 0; font-size: 16px; }
.checklist { margin: 0; padding-left: 18px; color: #334155; line-height: 1.8; }
</style>
