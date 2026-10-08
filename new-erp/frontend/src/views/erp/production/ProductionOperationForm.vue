<template>
  <section class="production-page" v-loading="loading">
    <div class="page-heading">
      <div><p class="eyebrow">生产管理 / 生产基础 / 工序管理</p><h1>{{ title }}</h1><p>工序编码由系统按编号规则预生成并保证唯一</p></div>
      <el-button @click="$router.push('/production/operations')">返回列表</el-button>
    </div>
    <section class="form-card">
      <el-form ref="form" :model="form" :rules="rules" label-position="top">
        <div class="form-grid">
          <el-form-item label="工序编码"><el-input v-model="form.operation_no" disabled /></el-form-item>
          <el-form-item label="工序名称" prop="operation_name"><el-input v-model="form.operation_name" :disabled="viewOnly" maxlength="160" /></el-form-item>
          <el-form-item label="排序"><el-input-number v-model="form.sort" :disabled="viewOnly" :min="0" :max="999999" /></el-form-item>
          <el-form-item label="状态"><el-input :value="form.status==='disabled'?'已停用':'已启用'" disabled /></el-form-item>
          <el-form-item label="公共工序">
            <el-switch v-model="form.is_public" :disabled="viewOnly" active-color="#008b4b" active-text="是" inactive-text="否" />
            <p class="assignment-rule-hint">工序只需建立一份，可被多条物料路线引用</p>
          </el-form-item>
          <el-form-item label="效率优先派工">
            <el-switch v-model="form.auto_assignment_enabled" :disabled="viewOnly" active-color="#008b4b" active-text="启用" inactive-text="关闭" />
            <p class="assignment-rule-hint">按相同产品、工艺、数量的独立合格历史推荐，本人接受后接单。没有可比历史时保留手动接单。</p>
          </el-form-item>
        </div>
        <el-form-item label="说明"><el-input v-model="form.description" :disabled="viewOnly" type="textarea" :rows="5" maxlength="2000" show-word-limit /></el-form-item>
        <div v-if="!viewOnly" class="form-actions"><el-button @click="$router.push('/production/operations')">取消</el-button><el-button type="success" :loading="loading" @click="save">保存</el-button></div>
      </el-form>
    </section>
  </section>
</template>
<script>
import { reserveProductionNumber, getProductionOperation, createProductionOperation, updateProductionOperation } from '../../../api/erp/production'
import { executeProductionDecision } from '../../../api/erp/production-assignments'

const uuid = () => window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
  const value = Math.random() * 16 | 0
  return (c === 'x' ? value : (value & 3 | 8)).toString(16)
})
const asBoolean = value => value === true || value === 1 || value === '1'

export default {
  name: 'ProductionOperationForm',
  data: () => ({ loading: false, sessionId: '', reservationToken: '',
    form: { operation_no: '正在生成…', operation_name: '', sort: 0, status: 'enabled', description: '', is_public: false, auto_assignment_enabled: false, business_version: 1 },
    rules: { operation_name: [{ required: true, message: '请输入工序名称', trigger: 'blur' }] } }),
  computed: {
    id() { return this.$route.params.id },
    viewOnly() { return Boolean(this.id) && !this.$route.path.endsWith('/edit') },
    title() { return this.id ? (this.viewOnly ? '查看工序' : '编辑工序') : '新增工序' }
  },
  created() { this.id ? this.load() : this.reserve() },
  methods: {
    async reserve() {
      this.loading = true; this.sessionId = uuid()
      try {
        const response = await reserveProductionNumber('operation', this.sessionId, this.$route.path)
        this.form.operation_no = response.data.data.document_no; this.reservationToken = response.data.data.reservation_token
      } catch (error) { this.$message.error(error.userMessage || '编号生成失败') }
      finally { this.loading = false }
    },
    async load() {
      this.loading = true
      try { const response = await getProductionOperation(this.id); this.form = { ...this.form, ...response.data.data, is_public: asBoolean(response.data.data.is_public), auto_assignment_enabled: asBoolean(response.data.data.auto_assignment_enabled) } }
      catch (error) { this.$message.error(error.userMessage || '工序加载失败') }
      finally { this.loading = false }
    },
    operationPayload() {
      const payload = { operation_name: this.form.operation_name, sort: this.form.sort, description: this.form.description,
        is_public: Boolean(this.form.is_public), auto_assignment_enabled: Boolean(this.form.auto_assignment_enabled) }
      if (this.id) payload.expected_version = this.form.business_version
      else Object.assign(payload, { creation_session_id: this.sessionId, reservation_token: this.reservationToken, status: 'enabled' })
      return payload
    },
    async save() {
      if (this.loading || this.viewOnly || !await this.$refs.form.validate().catch(() => false)) return
      this.loading = true
      try {
        await executeProductionDecision('operation_' + (this.id || this.sessionId), this.operationPayload(),
          payload => this.id ? updateProductionOperation(this.id, payload) : createProductionOperation(payload))
        this.$message.success('工序已保存'); this.$router.push('/production/operations')
      } catch (error) { this.$message.error(error.userMessage || '保存失败，请重试原操作') }
      finally { this.loading = false }
    }
  }
}
</script>
<style scoped src="./production-master.css"></style>
<style scoped>
.assignment-rule-hint{color:#77869a;font-size:12px;line-height:1.6;margin:8px 0 0}
@media(max-width:500px){.page-heading .el-button{margin:0}.form-grid .el-input-number{width:100%}}
</style>
