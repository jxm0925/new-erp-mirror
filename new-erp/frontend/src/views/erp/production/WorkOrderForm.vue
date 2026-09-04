<template>
  <section class="production-page" v-loading="loading">
    <div class="page-heading"><div><p class="eyebrow">生产管理 / 工单管理</p><h1>新建备货工单</h1><p>生产计划和试制来源尚未正式开放；备货来源号由系统生成</p></div><el-button @click="$router.push('/production/work-orders')">返回列表</el-button></div>
    <section class="form-card"><el-form ref="form" :model="form" :rules="rules" label-position="top">
      <div class="form-grid">
        <el-form-item label="工单编号"><el-input v-model="form.work_order_no" disabled /></el-form-item>
        <el-form-item label="来源类型"><el-input value="备货" disabled /></el-form-item>
        <el-form-item label="备货来源号"><el-input value="保存时由系统生成 SPB 编号" disabled /></el-form-item>
        <el-form-item label="产出物料" prop="output_item_id"><el-select v-model="form.output_item_id" filterable remote :remote-method="searchItems" :loading="optionLoading" style="width:100%" @change="onItem"><el-option v-for="i in items" :key="i.id" :label="`${i.item_code} / ${i.item_name}`" :value="i.id" /></el-select></el-form-item>
        <el-form-item label="工艺路线" prop="production_routing_id"><el-select v-model="form.production_routing_id" filterable remote :remote-method="searchRoutings" :loading="optionLoading" style="width:100%" @change="onRouting"><el-option v-for="r in routings" :key="r.id" :label="`${r.routing_no} / ${r.routing_name} / V${r.version}${r.is_default?'（默认）':''}`" :value="r.id" /></el-select></el-form-item>
        <el-form-item label="备货目标路线工序" prop="target_routing_operation_id"><el-select v-model="form.target_routing_operation_id" style="width:100%"><el-option v-for="node in routeOperations" :key="node.id" :label="`${node.sequence} - ${node.operation && node.operation.operation_name}`" :value="node.id" /></el-select></el-form-item>
        <el-form-item label="计划数量" prop="target_qty"><el-input-number v-model="form.target_qty" :min="0.0001" :precision="4" style="width:100%" /></el-form-item>
        <el-form-item label="计划日期"><el-date-picker v-model="form.planned_date" type="date" value-format="yyyy-MM-dd" style="width:100%" /></el-form-item>
        <el-form-item label="生产批次"><el-input v-model="form.production_batch" /></el-form-item>
        <el-form-item label="负责人"><el-select v-model="form.responsible_user_legacy_id" clearable filterable remote :remote-method="searchUsers" :loading="optionLoading" style="width:100%"><el-option v-for="u in users" :key="u.user_id" :label="u.display_name" :value="u.user_id" /></el-select></el-form-item>
        <el-form-item label="生产地点 / 车间"><el-input v-model="form.production_location_name" /></el-form-item>
      </div>
      <div class="form-actions"><el-button @click="$router.push('/production/work-orders')">取消</el-button><el-button type="success" @click="save">保存草稿</el-button></div>
    </el-form></section>
  </section>
</template>
<script>
import { reserveProductionNumber, searchProductionOptions, getProductionRouting, createWorkOrderDraft } from '../../../api/erp/production'
import { listUsers } from '../../../api/erp/rbac'
const uuid = () => window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => { const r = Math.random() * 16 | 0; return (c === 'x' ? r : (r & 3 | 8)).toString(16) })
export default {
  name: 'WorkOrderForm',
  data: () => ({ loading: false, optionLoading: false, sessionId: '', reservationToken: '', items: [], routings: [], routeOperations: [], users: [], form: { work_order_no: '正在生成…', source_type: 'stock_prebuild', output_item_id: null, production_routing_id: null, target_routing_operation_id: null, target_qty: 1, planned_date: '', production_batch: '', responsible_user_legacy_id: null, production_location_name: '' }, rules: { output_item_id: [{ required: true, message: '请选择产出物料' }], production_routing_id: [{ required: true, message: '请选择工艺路线' }], target_routing_operation_id: [{ required: true, message: '请选择目标路线工序' }], target_qty: [{ required: true, message: '请输入计划数量' }] } }),
  async created () { this.loading = true; this.sessionId = uuid(); try { const n = await reserveProductionNumber('work_order', this.sessionId, this.$route.path); this.form.work_order_no = n.data.data.document_no; this.reservationToken = n.data.data.reservation_token; await Promise.all([this.searchItems(''), this.searchUsers('')]) } catch (e) { this.$message.error(e.userMessage || '新建工单数据加载失败') } finally { this.loading = false } },
  methods: {
    async searchItems (keyword) { this.optionLoading = true; try { const r = await searchProductionOptions('items', { keyword, per_page: 20 }); this.items = r.data.data || [] } finally { this.optionLoading = false } },
    async searchRoutings (keyword) { if (!this.form.output_item_id) return; this.optionLoading = true; try { const r = await searchProductionOptions('routings', { keyword, output_item_id: this.form.output_item_id, per_page: 20 }); this.routings = r.data.data || [] } finally { this.optionLoading = false } },
    async searchUsers (keyword) { this.optionLoading = true; try { const r = await listUsers({ scope: 'production', status: 'normal', keyword, page: 1, per_page: 20 }); this.users = r.data.data || r.data || [] } finally { this.optionLoading = false } },
    async onItem () { this.form.production_routing_id = null; this.form.target_routing_operation_id = null; this.routeOperations = []; await this.searchRoutings(''); const route = this.routings.find(r => r.is_default); if (route) { this.form.production_routing_id = route.id; await this.onRouting(route.id) } },
    async onRouting (id) { this.form.target_routing_operation_id = null; this.routeOperations = []; if (!id) return; try { const r = await getProductionRouting(id); this.routeOperations = r.data.data.operations || [] } catch (e) { this.$message.error(e.userMessage || '路线工序加载失败') } },
    save () { this.$refs.form.validate(async ok => { if (!ok) return; this.loading = true; try { const payload = { ...this.form, client_command_id: `work-order-create-${this.sessionId}`, creation_session_id: this.sessionId, reservation_token: this.reservationToken }; delete payload.work_order_no; const r = await createWorkOrderDraft(payload); this.$message.success('备货工单草稿已保存'); this.$router.push(`/production/work-orders/${r.data.data.id}`) } catch (e) { this.$message.error(e.userMessage || '保存失败') } finally { this.loading = false } }) }
  }
}
</script>
<style scoped src="./production-master.css"></style>
