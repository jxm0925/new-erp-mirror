<template>
  <span class="crm-progress-controls" v-loading="saving">
    <el-dropdown v-if="showPool && poolEditable" trigger="click" :disabled="saving" @visible-change="prepare" @command="change({ customer_pool: $event })">
      <el-tag size="mini" type="success" class="crm-progress-pill">{{ subject.customer_pool || '未入池' }} <i class="el-icon-arrow-down" /></el-tag>
      <el-dropdown-menu slot="dropdown" class="crm-select-popper"><el-dropdown-item v-for="name in pools" :key="name" :command="name" :disabled="name === subject.customer_pool">{{ name }}</el-dropdown-item></el-dropdown-menu>
    </el-dropdown>
    <el-tag v-else-if="showPool" size="mini" type="success">{{ subject.customer_pool || '未入池' }}</el-tag>
    <el-dropdown v-if="showStage && stageEditable && scope !== 'customer'" trigger="click" :disabled="saving" @visible-change="prepare" @command="changeStage">
      <el-tag size="mini" type="success" class="crm-progress-pill">{{ stageLabel }} <i class="el-icon-arrow-down" /></el-tag>
      <el-dropdown-menu slot="dropdown" class="crm-select-popper"><el-dropdown-item v-for="item in stages" :key="item.value" :command="item.value" :disabled="item.value === stage">{{ item.label }}</el-dropdown-item></el-dropdown-menu>
    </el-dropdown>
    <el-tag v-else-if="showStage && stageLabel" size="mini" type="success">{{ stageLabel }}</el-tag>
  </span>
</template>
<script>
import { getCrmProgressCatalog, updateCrmProgress, crmError } from '@/api/erp/crm'
import { poolNames, stageOptions } from '@/utils/crmProgress'
export default {
  name: 'CrmProgressControls',
  props: { subject: { type: Object, required: true }, scope: { type: String, required: true }, metadata: { type: Object, default: () => ({}) }, showStage: { type: Boolean, default: true }, showPool: { type: Boolean, default: true } },
  data: () => ({ saving: false, localMetadata: {}, loading: false }),
  computed: {
    options() { return Object.keys(this.metadata).length ? this.metadata : this.localMetadata },
    pools() { return poolNames(this.options, this.scope, this.subject.customer_pool) },
    stages() { return stageOptions(this.options, this.scope) },
    stage() { return this.scope === 'opportunity' ? this.subject.stage : this.scope === 'customer' ? this.subject.lifecycle_stage : this.subject.nine_step_stage },
    stageLabel() { return (this.stages.find(item => item.value === this.stage) || {}).label || this.subject.stage_label || this.stage || '' },
    poolEditable() { const a = this.subject.abilities || {}; return this.scope === 'customer' ? !!(a.edit || a.progress) : this.scope === 'opportunity' ? !!a.progress && this.subject.status === 'open' : !!a.pool && ['active', 'converted'].includes(this.subject.status) },
    stageEditable() { const a = this.subject.abilities || {}; return this.scope === 'opportunity' ? !!a.stage && this.subject.status === 'open' : this.scope === 'lead' && !!a.progress && this.subject.status === 'active' }
  },
  methods: {
    async prepare(visible) {
      if (!visible || Object.keys(this.options).length || this.loading) return
      this.loading = true
      try { const { data } = await getCrmProgressCatalog(); this.localMetadata = data.data || data }
      catch (error) { this.$message.error(crmError(error, '客户池加载失败')) }
      finally { this.loading = false }
    },
    changeStage(value) { const item = this.stages.find(item => item.value === value) || {}; return this.change({ [this.scope === 'opportunity' ? 'stage' : 'nine_step_stage']: value, ...(item.probability != null ? { win_probability: item.probability } : {}) }) },
    async change(patch) {
      if (this.saving || (Object.prototype.hasOwnProperty.call(patch, 'customer_pool') ? !this.poolEditable : !this.stageEditable)) return
      this.saving = true
      try { const { data } = await updateCrmProgress(this.scope, this.subject.id, { business_version: this.subject.version || this.subject.business_version || this.subject.crm_version, crm_version: this.subject.crm_version, ...patch }); this.$message.success('当前进度已更新'); this.$emit('changed', data.data || data) }
      catch (error) { this.$message.error(crmError(error, '进度更新失败')); this.$emit('refresh') }
      finally { this.saving = false }
    }
  }
}
</script>
<style scoped>
.crm-progress-controls {
  display: inline-flex;
  flex-wrap: wrap;
  gap: 5px;
  align-items: center;
  min-width: 0;
}
.crm-progress-pill {
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
}
</style>
