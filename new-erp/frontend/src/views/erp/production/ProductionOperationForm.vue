<template>
  <section class="production-page" v-loading="loading">
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-set-up" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ title }}</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              {{ form.operation_no }}
            </el-tag>
          </div>
          <div class="sub-row">工序编码由系统按编号规则预生成并保证全局唯一</div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" class="btn-back" @click="$router.push('/production/operations')">返回列表</el-button>
        <el-button v-if="!viewOnly" size="small" type="success" icon="el-icon-check" :loading="loading" @click="save">保存工序</el-button>
      </div>
    </header>

    <section class="form-card">
      <div class="card-head">
        <div class="card-title">工序属性配置</div>
      </div>
      <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small">
        <div class="form-grid">
          <el-form-item label="工序编码"><el-input v-model="form.operation_no" disabled class="erp-system-number" /></el-form-item>
          <el-form-item label="工序名称" prop="operation_name"><el-input v-model="form.operation_name" :disabled="viewOnly" maxlength="160" placeholder="请输入工序名称" /></el-form-item>
          <el-form-item label="排序"><el-input-number v-model="form.sort" :disabled="viewOnly" :min="0" :max="999999" controls-position="right" /></el-form-item>
          <el-form-item label="状态"><el-input :value="form.status==='disabled'?'已停用':'已启用'" disabled /></el-form-item>
          <el-form-item label="工序属性">
            <el-switch v-model="form.is_public" :disabled="viewOnly" active-color="#008b4b" active-text="公共工序" inactive-text="专用工序" />
            <p class="assignment-rule-hint">公共工序建立一份后，可被多个产品的工艺路线共享引用</p>
          </el-form-item>
          <el-form-item label="效率优先派工">
            <el-switch v-model="form.auto_assignment_enabled" :disabled="viewOnly" active-color="#008b4b" active-text="启用" inactive-text="关闭" />
            <p class="assignment-rule-hint">按相同产品、工艺合格记录智能推荐并派工；没有可比历史时保留手动接单。</p>
          </el-form-item>
        </div>
        <el-form-item label="工序说明"><el-input v-model="form.description" :disabled="viewOnly" type="textarea" :rows="4" maxlength="2000" show-word-limit placeholder="请输入工序说明（选填）" /></el-form-item>
        <div v-if="!viewOnly" class="form-actions">
          <el-button size="small" @click="$router.push('/production/operations')">取消</el-button>
          <el-button size="small" type="success" :loading="loading" @click="save">保存</el-button>
        </div>
      </el-form>
    </section>
  </section>
</template>
<script>
import { getProductionOperation, createProductionOperation, updateProductionOperation } from '../../../api/erp/production'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'
import { executeProductionDecision } from '../../../api/erp/production-decisions'

const asBoolean = value => value === true || value === 1 || value === '1'

export default {
  name: 'ProductionOperationForm',
  data: () => ({ loading: false, sessionId: '', reservationToken: '', reservation: null,
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
      this.loading = true
      try {
        this.reservation = await reserveForCreatePage('operation', this.$route.path)
        this.sessionId = this.reservation.creation_session_id
        this.form.operation_no = this.reservation.document_no; this.reservationToken = this.reservation.reservation_token
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
        if (!this.id) clearCreatePageReservation(this.reservation)
        this.$message.success('工序已保存'); this.$router.push('/production/operations')
      } catch (error) { this.$message.error(error.userMessage || '保存失败，请重试原操作') }
      finally { this.loading = false }
    }
  }
}
</script>
<style scoped src="./production-master.css"></style>
<style scoped>
.production-page {
  padding: 16px 20px 30px;
  background: #f8fafc;
  min-height: calc(100vh - 52px);
  box-sizing: border-box;
}

/* 顶部页面标头 (.page-head) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}
.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}
.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.head-title-wrap {
  display: flex;
  flex-direction: column;
  gap: 4px;
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
  color: #0f172a;
}
.head-tag {
  border-radius: 4px;
  font-weight: 500;
}
.sub-row {
  font-size: 13px;
  color: #64748b;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.btn-back {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.btn-back:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.form-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  padding: 20px 24px;
}
.card-head {
  padding-bottom: 14px;
  margin-bottom: 18px;
  border-bottom: 1px solid #e2e8f0;
}
.card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}
.card-title::before {
  content: "";
  display: inline-block;
  width: 3px;
  height: 14px;
  background: #008b4b;
  border-radius: 2px;
}
.assignment-rule-hint {
  color: #64748b;
  font-size: 12px;
  line-height: 1.5;
  margin: 6px 0 0;
}
.form-actions {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  margin-top: 24px;
  padding-top: 18px;
  border-top: 1px solid #f1f5f9;
}
@media (max-width: 500px) {
  .form-grid .el-input-number {
    width: 100%;
  }
}
</style>

