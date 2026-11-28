<template>
  <span v-if="canOpen || error" class="daily-entries">
    <el-button
      v-if="canOpen"
      size="small"
      :icon="mode === 'sales' ? 'el-icon-notebook-2' : 'el-icon-data-analysis'"
      @click="open"
    >
      {{ title }}
    </el-button>
    <el-button v-if="error" size="small" icon="el-icon-refresh" @click="loadAccess">重试日报入口</el-button>

    <el-dialog
      v-if="visible"
      append-to-body
      :visible.sync="visible"
      :title="mode === 'sales' ? '销售每日作战表' : '经营作战总表'"
      width="min(1440px, 96vw)"
      top="2vh"
      :close-on-click-modal="false"
      :before-close="close"
      custom-class="crm-daily-dialog"
    >
      <div class="daily-tools">
        <div class="daily-tools-left">
          <span class="tool-label">统计日期</span>
          <el-date-picker
            :value="day"
            type="date"
            size="small"
            value-format="yyyy-MM-dd"
            :clearable="false"
            :disabled="busy"
            :picker-options="picker"
            @input="change('day', $event)"
          />

          <template v-if="mode === 'sales' && salesStaff.length > 1">
            <span class="tool-label">销售人员</span>
            <el-select
              :value="salesId"
              size="small"
              filterable
              style="width: 190px"
              :disabled="busy"
              @input="change('salesId', $event)"
            >
              <el-option
                v-for="s in salesStaff"
                :key="s.id"
                :value="s.id"
                :label="s.department_name ? `${s.name}（${s.department_name}）` : s.name"
              />
            </el-select>
          </template>

          <template v-if="mode === 'team'">
            <span class="tool-label">部门</span>
            <el-select
              :value="dept"
              size="small"
              filterable
              style="width: 230px"
              popper-class="crm-select-popper"
              :disabled="busy"
              @input="change('dept', $event)"
            >
              <el-option v-for="d in departmentOptions" :key="d.id" :value="d.id" :label="d.pathLabel" class="crm-department-option">
                <span class="crm-department-row" :class="{ 'is-root': d.depth === 0 }" :style="{ paddingLeft: `${d.depth * 18}px` }">
                  <span v-if="d.depth" class="crm-department-branch" aria-hidden="true">{{ d.branchPrefix }}</span>
                  <i v-else class="el-icon-office-building" aria-hidden="true" />
                  <span class="crm-department-name">{{ d.name }}</span>
                  <small v-if="d.hasChildren" class="crm-department-level">{{ d.parent_id ? '上级' : '总公司' }}</small>
                </span>
              </el-option>
            </el-select>
          </template>
        </div>

        <div class="daily-tools-right">
          <template v-if="aiProviders.length">
            <span class="tool-label"><i class="el-icon-cpu" /> AI 厂商/模型</span>
            <el-select
              v-model="aiServiceCode"
              size="small"
              style="width: 170px"
              :disabled="busy"
              @change="onAiVendorChange"
            >
              <el-option
                v-for="p in aiProviders"
                :key="p.service_code"
                :value="p.service_code"
                :label="p.vendor_name + (p.is_default ? '（默认）' : '')"
              />
            </el-select>
            <el-select
              v-model="aiModel"
              size="small"
              filterable
              allow-create
              default-first-option
              style="width: 190px"
              placeholder="选择或输入模型"
              :disabled="busy"
            >
              <el-option v-for="m in currentAiModels" :key="m" :value="m" :label="m" />
            </el-select>
          </template>
          <el-tag v-else size="small" type="info" effect="plain">
            未启用 AI 服务（可前往 系统管理 → 第三方服务配置）
          </el-tag>
        </div>
      </div>

      <component
        :is="mode === 'sales' ? 'crm-sales-daily' : 'crm-team-daily'"
        ref="sheet"
        :key="mode + '-' + day + '-' + dept + '-' + (salesId || 0)"
        :date="day"
        :dept="dept"
        :sales-id="salesId"
        :ai-service-code="aiServiceCode"
        :ai-model="aiModel"
        :has-ai="aiProviders.length > 0"
        @working="busy = $event"
        @project="openProject"
        @confirmed="$emit('confirmed')"
      />
    </el-dialog>
  </span>
</template>

<script>
import { dailyAccess } from '@/api/erp/crmDaily'
import { crmDepartmentTree } from '@/utils/crmDepartmentTree'
import CrmSalesDaily from './CrmSalesDaily.vue'
import CrmTeamDaily from './CrmTeamDaily.vue'

export default {
  components: { CrmSalesDaily, CrmTeamDaily },
  props: {
    mode: {
      type: String,
      required: true,
      validator: value => ['sales', 'team'].includes(value)
    }
  },
  data: () => ({
    access: { seller: false, departments: [], sales_staff: [], ai_providers: [], today: '' },
    day: '',
    dept: 0,
    salesId: 0,
    aiServiceCode: '',
    aiModel: '',
    visible: false,
    busy: false,
    error: false
  }),
  computed: {
    canOpen() {
      return this.mode === 'sales' ? this.access.seller : this.departmentOptions.length > 0
    },
    departmentOptions() {
      return crmDepartmentTree(this.access.departments || [])
    },
    title() {
      return this.mode === 'sales' ? '销售作战表' : '经营作战总表'
    },
    salesStaff() {
      return Array.isArray(this.access.sales_staff) ? this.access.sales_staff : []
    },
    aiProviders() {
      return Array.isArray(this.access.ai_providers) ? this.access.ai_providers : []
    },
    currentAiModels() {
      const p = this.aiProviders.find(item => item.service_code === this.aiServiceCode)
      if (!p) return []
      const list = Array.isArray(p.models) && p.models.length ? p.models : (p.model ? [p.model] : [])
      return Array.from(new Set(list.filter(Boolean)))
    },
    picker() {
      return {
        disabledDate: d => d.getTime() > new Date(this.access.today + 'T23:59:59').getTime()
      }
    }
  },
  created() {
    if (this.$can && (!this.$can('crm.lead.view') || (this.mode === 'team' && !this.$can('crm.team.view')))) return
    this.loadAccess()
  },
  methods: {
    async loadAccess() {
      try {
        this.access = (await dailyAccess()).data
        this.error = false
        this.initAiSelection()
      } catch (e) {
        this.error = e.response?.status !== 403
      }
    },
    initAiSelection() {
      const list = this.aiProviders
      if (!list.length) {
        this.aiServiceCode = ''
        this.aiModel = ''
        return
      }
      const def = list.find(p => p.is_default) || list[0]
      this.aiServiceCode = def.service_code
      this.aiModel = def.model || (def.models && def.models[0]) || ''
    },
    onAiVendorChange(code) {
      const p = this.aiProviders.find(item => item.service_code === code)
      this.aiModel = p ? (p.model || (p.models && p.models[0]) || '') : ''
    },
    open() {
      this.day = this.access.today
      this.dept = this.departmentOptions[0]?.id || 0
      const selfStaff = this.salesStaff.find(s => s.is_self) || this.salesStaff[0]
      this.salesId = selfStaff ? selfStaff.id : 0
      this.initAiSelection()
      this.busy = false
      this.visible = true
    },
    openProject(project) {
      this.$emit('project', { target_type: project.kind, target_id: project.id })
    },
    async discard() {
      if (this.busy) return false
      if (!this.$refs.sheet?.dirty) return true
      try {
        await this.$confirm('当前表单有未保存内容，确定离开？', '未保存的修改')
        return true
      } catch (_) {
        return false
      }
    },
    async change(field, value) {
      if (value !== this[field] && (await this.discard())) {
        this[field] = value
      }
    },
    async close(done) {
      if (await this.discard()) done()
    }
  }
}
</script>

<style>
.daily-entries {
  display: inline-flex;
  gap: 6px;
}
.crm-daily-dialog {
  border-radius: 12px !important;
  overflow: hidden;
}
.crm-daily-dialog .el-dialog__header {
  border-bottom: 1px solid #e2e8f0;
  padding: 14px 24px 12px;
  background: #ffffff;
}
.crm-daily-dialog .el-dialog__title {
  color: #133a2c;
  font-weight: 700;
  font-size: 16px;
  letter-spacing: 0.2px;
}
.crm-daily-dialog .el-dialog__body {
  padding: 0 24px 26px;
  max-height: 86vh;
  overflow: auto;
  background: #f4f7f6;
}
.crm-daily-dialog .el-select .el-input.is-focus .el-input__inner,
.crm-daily-dialog .el-select .el-input__inner:focus,
.crm-daily-dialog .el-input__inner:focus,
.crm-daily-dialog .el-textarea__inner:focus {
  border-color: #008b4b !important;
}
.crm-daily-dialog .el-dialog__body::-webkit-scrollbar {
  width: 7px;
  height: 7px;
}
.crm-daily-dialog .el-dialog__body::-webkit-scrollbar-thumb {
  background: #cbd5e1;
  border-radius: 999px;
}
.crm-daily-dialog .el-dialog__body::-webkit-scrollbar-thumb:hover {
  background: #94a3b8;
}
.crm-daily-dialog .daily-tools {
  position: sticky;
  top: 0;
  z-index: 30;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  padding: 10px 24px;
  margin: 0 -24px 14px;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(8px);
  border-bottom: 1px solid #e2e8f0;
  box-shadow: 0 2px 8px rgba(15, 23, 42, 0.04);
}
.crm-daily-dialog .daily-tools-left,
.crm-daily-dialog .daily-tools-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.crm-daily-dialog .tool-label {
  font-size: 12px;
  color: #475569;
  font-weight: 600;
}
.crm-daily-dialog .sheet-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  margin: 0 0 14px;
  padding: 10px 16px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  box-shadow: 0 1px 2px rgba(15, 23, 42, 0.03);
}
.crm-daily-dialog .sheet-toolbar-group {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.crm-daily-dialog .sheet-toolbar .el-dropdown .el-button--success {
  background-color: #0d9488;
  border-color: #0d9488;
}
.crm-daily-dialog .sheet-toolbar .el-dropdown .el-button--success:hover,
.crm-daily-dialog .sheet-toolbar .el-dropdown .el-button--success:focus {
  background-color: #0f766e;
  border-color: #0f766e;
}
.crm-daily-dialog .sheet-section {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 16px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.03);
}
.crm-daily-dialog .sheet-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding-bottom: 10px;
  margin-bottom: 14px;
  border-bottom: 1px solid #f1f5f9;
  flex-wrap: wrap;
}
.crm-daily-dialog .subsection-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  margin: 14px 0 8px;
  padding: 6px 12px;
  background: #f8fafc;
  border-left: 3px solid #008b4b;
  border-radius: 0 6px 6px 0;
  flex-wrap: wrap;
}
.crm-daily-dialog .subpanel-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 700;
  color: #334155;
  margin: 0 0 8px;
}
.crm-daily-dialog .subpanel-title::before {
  content: '';
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #008b4b;
}
.crm-daily-dialog h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
  display: flex;
  align-items: center;
  gap: 8px;
}
.crm-daily-dialog .section-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 24px;
  height: 24px;
  padding: 0 7px;
  border-radius: 6px;
  background: #e8f7ef;
  color: #00763f;
  font-size: 12px;
  font-weight: 700;
}
.crm-daily-dialog .risk-pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 9px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 700;
  line-height: 1.5;
  width: fit-content;
}
.crm-daily-dialog .risk-pill.is-high {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecaca;
}
.crm-daily-dialog .risk-pill.is-mid {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
}
.crm-daily-dialog .risk-pill.is-low,
.crm-daily-dialog .risk-pill.is-ok {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
}
.crm-daily-dialog .risk-pill.is-muted {
  background: #f1f5f9;
  color: #64748b;
  border: 1px solid #e2e8f0;
}
.crm-daily-dialog .daily-muted {
  font-size: 12px;
  color: #64748b;
}
.crm-daily-dialog .daily-fields {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
  gap: 12px;
}
.crm-daily-dialog .daily-fields label {
  display: block;
  font-size: 12px;
  color: #334155;
  font-weight: 600;
}
.crm-daily-dialog .daily-fields label > :last-child {
  display: block;
  margin-top: 5px;
}
.crm-daily-dialog .daily-item {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin: 8px 0;
  padding: 10px 14px;
  background: #f8fafc;
  font-size: 13px;
  line-height: 1.55;
}
.crm-daily-dialog .daily-item header {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 6px;
}
.crm-daily-dialog .combat-banner {
  background: linear-gradient(135deg, #133a2c 0%, #1d5740 100%);
  color: #ffffff;
  border-radius: 10px;
  padding: 14px 18px;
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  flex-wrap: wrap;
  box-shadow: 0 4px 12px rgba(19, 58, 44, 0.16);
}
.crm-daily-dialog .combat-banner-input .el-input__inner {
  background: rgba(255, 255, 255, 0.08);
  border-color: rgba(255, 255, 255, 0.22);
  color: #ffffff;
  font-size: 17px;
  font-weight: 700;
  border-radius: 6px;
  height: 36px;
  line-height: 36px;
}
.crm-daily-dialog .combat-banner-input .el-input__inner:hover,
.crm-daily-dialog .combat-banner-input .el-input__inner:focus {
  background: rgba(255, 255, 255, 0.16);
  border-color: rgba(255, 255, 255, 0.45);
}
.crm-daily-dialog .ai-panel {
  border: 1px solid #b7e4cb;
  background: linear-gradient(180deg, #effaf4 0%, #f8fdfb 100%);
}
.crm-daily-dialog .combat-table {
  border-radius: 6px;
  overflow: hidden;
  border-color: #e2e8f0;
  font-variant-numeric: tabular-nums;
}
.crm-daily-dialog .combat-table .el-table__header th {
  background: #f8fafc !important;
  color: #334155;
  font-size: 12px;
  font-weight: 700;
  padding: 8px 0;
  border-bottom: 1px solid #cbd5e1;
}
.crm-daily-dialog .combat-table .el-table__cell {
  padding: 4px 0;
  font-size: 12.5px;
  color: #1e293b;
}
.crm-daily-dialog .combat-table .el-table__body tr:hover > td.el-table__cell {
  background-color: #f0fdf4 !important;
}
.crm-daily-dialog .combat-table .el-input__inner,
.crm-daily-dialog .combat-table .el-textarea__inner {
  background: transparent;
  border-color: transparent;
  border-radius: 4px;
  color: #1e293b;
  font-size: 12.5px;
  padding-left: 6px;
  padding-right: 6px;
  transition: background-color 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
}
.crm-daily-dialog .combat-table .el-select {
  width: 100%;
}
.crm-daily-dialog .combat-table .el-input {
  width: 100%;
}
.crm-daily-dialog .combat-table .el-select .el-input__inner {
  padding-right: 22px;
}
.crm-daily-dialog .combat-table .el-table__row:hover .el-input:not(.is-disabled) .el-input__inner,
.crm-daily-dialog .combat-table .el-table__row:hover .el-textarea:not(.is-disabled) .el-textarea__inner {
  border-color: #cbd5e1;
  background: rgba(255, 255, 255, 0.85);
}
.crm-daily-dialog .combat-table .el-input:not(.is-disabled) .el-input__inner:hover,
.crm-daily-dialog .combat-table .el-textarea:not(.is-disabled) .el-textarea__inner:hover {
  border-color: #94a3b8;
  background: #ffffff;
}
.crm-daily-dialog .combat-table .el-input:not(.is-disabled) .el-input__inner:focus,
.crm-daily-dialog .combat-table .el-textarea:not(.is-disabled) .el-textarea__inner:focus {
  border-color: #008b4b;
  background: #ffffff;
  box-shadow: 0 0 0 2px rgba(0, 139, 75, 0.12);
}
.crm-daily-dialog .combat-table .el-input.is-disabled .el-input__inner,
.crm-daily-dialog .combat-table .el-textarea.is-disabled .el-textarea__inner {
  background: transparent;
  color: #334155;
  -webkit-text-fill-color: #334155;
  cursor: default;
}
.crm-daily-dialog .num-pos {
  color: #00763f;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.crm-daily-dialog .num-neg {
  color: #dc2626;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}
.crm-daily-dialog .tab-num {
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  color: #0f172a;
}
.crm-daily-dialog .el-alert {
  margin: 12px 0;
  border-radius: 8px;
}
</style>
