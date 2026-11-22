<template>
  <div v-loading="busy" class="team-cockpit-wrap">
    <el-alert v-if="error" type="error" :title="error" :closable="false" show-icon />

    <!-- 顶部操作栏 -->
    <div class="sheet-toolbar">
      <div class="sheet-toolbar-group">
        <span class="tool-label">汇报币种</span>
        <el-select v-model="form.currency" :disabled="!amountsAvailable" size="small" style="width: 92px" filterable allow-create>
          <el-option v-for="c in currencies" :key="c" :value="c" :label="c" />
        </el-select>
      </div>

      <div class="sheet-toolbar-group">
        <el-button size="small" icon="el-icon-refresh" @click="reload">刷新</el-button>
        <el-button v-if="ready" size="small" type="success" icon="el-icon-check" @click="save">保存经营总表</el-button>
        <el-dropdown v-if="ready" split-button type="primary" size="small" :disabled="!hasAi || busy"
          @click="analyze(true)" @command="analyze(false)">
          AI 预填空白项
          <el-dropdown-menu slot="dropdown"><el-dropdown-item command="preview">仅生成填写提示</el-dropdown-item></el-dropdown-menu>
        </el-dropdown>
        <el-button v-if="ready" size="small" icon="el-icon-download" @click="download">导出 Excel</el-button>
      </div>
    </div>

    <template v-if="ready">
      <crm-cockpit-v3 ref="cockpit" :data="data" :form="form.cockpit" :currency="form.currency" :date="date" @inspect="openSalesSheet" />
      <!-- 模块五：需要主管协调闭环 -->
      <section class="sheet-section">
        <div class="sheet-section-head">
          <h3><span class="section-badge">五</span> 需要主管协调事项 <el-tag size="mini" :type="pending > 0 ? 'danger' : 'info'">{{ pending }} 项待处理</el-tag></h3>
        </div>
        <div v-if="coordinationProjects.length" class="coord-launch-bar">
          <span class="tool-label">新增协调发起</span>
          <el-select v-model="coordinationProject" size="small" filterable style="min-width: 280px" placeholder="选择销售和项目">
            <el-option v-for="opt in coordinationProjects" :key="opt.value" :value="opt.value" :label="opt.label" />
          </el-select>
          <el-button size="small" type="success" plain icon="el-icon-plus" :disabled="!coordinationProject" @click="initiate">发起协调</el-button>
        </div>
        <p v-if="!helpList.length" class="daily-muted">当前没有待处理或当日更新的协调事项。</p>
        <div v-else class="coord-grid">
          <article v-for="h in helpList" :key="h.id" class="coord-card" :class="'status-' + h.help_status">
            <header class="coord-card-head">
              <div style="display: flex; align-items: center; gap: 8px; flex-wrap: wrap">
                <el-tag size="mini" :type="h.help_status === 'done' ? 'success' : (h.help_status === 'returned' ? 'warning' : (h.help_status === 'accepted' ? '' : 'danger'))">
                  {{ states[h.help_status] }}
                </el-tag>
                <b>{{ h.author_name }}</b>
                <span v-if="!h.subject">部门事项</span>
                <el-button v-else type="text" size="mini" style="padding: 0" @click="$emit('project', h.subject)">
                  {{ h.subject.customer_name }} · {{ h.subject.number }}
                </el-button>
              </div>
              <el-button v-if="['accepted', 'pending'].includes(h.help_status)" size="mini" type="primary" plain @click="handle(h)">
                办理协调
              </el-button>
            </header>
            <div class="coord-card-body">{{ h.help_request }}</div>
            <div class="daily-muted">
              {{ h.report_date }} 提出
              <span v-if="h.manager_name || h.manager_note"> · {{ h.manager_name }}：{{ h.manager_note }}</span>
              <span v-if="h.help_due"> · 期限 {{ h.help_due }}</span>
            </div>
          </article>
        </div>
      </section>
    </template>

    <!-- 办理协调事项弹窗 -->
    <el-dialog append-to-body width="460px" title="办理协调事项" :visible.sync="editor" :close-on-click-modal="false">
      <el-form label-position="top" size="small" v-loading="busy">
        <el-form-item label="办理动作">
          <el-select v-model="decision.status" style="width: 100%">
            <el-option value="accepted" label="我来协调" />
            <el-option value="returned" label="退回补充" />
            <el-option value="done" label="完成协调" />
          </el-select>
        </el-form-item>
        <el-form-item label="办理期限">
          <el-date-picker type="date" value-format="yyyy-MM-dd" v-model="decision.due" style="width: 100%" />
        </el-form-item>
        <el-form-item label="办理意见或结果">
          <el-input type="textarea" v-model="decision.note" maxlength="500" />
        </el-form-item>
        <el-button type="success" :disabled="busy" @click="resolve">确认办理</el-button>
      </el-form>
    </el-dialog>

    <!-- 下钻查看与批注个人作战表弹窗 -->
    <el-dialog
      v-if="inspectDialog && inspectingSales"
      append-to-body
      :visible.sync="inspectDialog"
      :title="`查阅销售作战表 — ${inspectingSales.name}（${date}）`"
      width="min(1380px, 95vw)"
      top="3vh"
      custom-class="crm-daily-dialog"
      @close="inspectingSales = null"
    >
      <crm-sales-daily
        :key="'inspect-' + inspectingSales.id + '-' + date"
        :date="date"
        :sales-id="inspectingSales.id"
        :ai-service-code="aiServiceCode"
        :ai-model="aiModel"
        :has-ai="hasAi"
        @changed="run(refreshMemberData)"
        @project="$emit('project', $event)"
      />
    </el-dialog>
  </div>
</template>

<script>
import api from '@/api/erp/master'
import {
  readDaily,
  saveDaily,
  analyzeDaily,
  resolveDailyHelp,
  exportDaily,
  helpStatus
} from '@/api/erp/crmDaily'
import CrmSalesDaily from './CrmSalesDaily.vue'
import CrmCockpitV3 from './CrmCockpitV3.vue'

export default {
  components: { CrmSalesDaily, CrmCockpitV3 },
  props: {
    date: { type: String, required: true },
    dept: { type: Number, required: true },
    aiServiceCode: { type: String, default: '' },
    aiModel: { type: String, default: '' },
    hasAi: { type: Boolean, default: true }
  },
  data: () => ({
    data: {},
    form: { currency: 'CNY', cockpit: {} },
    ready: false,
    busy: false,
    baseline: '',
    error: '',
    editor: false,
    current: null,
    decision: {},
    states: helpStatus,
    currencies: ['CNY', 'USD', 'EUR', 'GBP', 'HKD', 'JPY'],
    inspectDialog: false,
    coordinationProject: '',
    inspectingSales: null
  }),
  computed: {
    dirty() {
      return this.ready && this.baseline !== JSON.stringify(this.form)
    },
    path() {
      return 'team/' + this.dept
    },
    staffList() {
      return Array.isArray(this.data.staff) ? this.data.staff : []
    },
    coordinationProjects() {
      return this.staffList.filter(row => row.submitted).flatMap(row => row.projects
        .filter(p => !this.hasHelp(row.id, p.key))
        .map(project => ({ row, project, value: row.id + '/' + project.key,
          label: [row.name, project.subject.customer_name, project.subject.number].join(' · ') })))
    },
    helpList() {
      return Array.isArray(this.data.help) ? this.data.help : []
    },
    pending() {
      return this.helpList.filter(h => ['pending', 'accepted'].includes(h.help_status)).length
    },
    amountsAvailable() {
      return this.data.amounts != null
    }
  },
  created() {
    this.reload()
  },
  methods: {
    apply(data) {
      this.data = data
      const b = data.body || {}
      const stored = b.cockpit || {}
      const cockpit = {
        settings: { ...(stored.settings || {}) }, conclusions: { ...(stored.conclusions || {}) },
        personnel: (stored.personnel || []).map(p => ({ ...p })),
        coaching: ['P1','P2','P3'].map(priority => ({ ...(stored.coaching || []).find(p => p.priority === priority), priority }))
      }
      this.form = { currency: b.currency || 'CNY', cockpit }
      this.baseline = JSON.stringify(this.form)
      this.ready = true
    },
    async run(work) {
      if (this.busy) return
      this.busy = true
      this.error = ''
      this.$emit('working', true)
      try {
        await work()
      } catch (e) {
        this.error = e.userMessage || e.response?.data?.message || e.message || '操作失败'
        this.$message.error(this.error)
      } finally {
        this.busy = false
        this.$emit('working', false)
      }
    },
    async reload() {
      if (this.dirty) {
        try {
          await this.$confirm('重新加载将放弃未保存的修改。', '重新加载')
        } catch (_) {
          return
        }
      }
      return this.run(async () => this.apply((await readDaily(this.path, this.date)).data))
    },
    buildSavePayload() {
      return {
        template_version: 3, currency: this.form.currency, cockpit: this.form.cockpit, date: this.date, version: this.data.version
      }
    },
    async write() {
      const { data } = await saveDaily(this.path, this.buildSavePayload())
      this.apply(data)
    },
    save() {
      return this.run(async () => {
        await this.write()
        this.$message.success('经营总表已保存')
      })
    },
    analyze(prefill = false) {
      return this.run(async () => {
        if (!this.data.version || this.dirty) await this.write()
        const extra = {}
        if (this.aiServiceCode) extra.service_code = this.aiServiceCode
        if (this.aiModel) extra.model = this.aiModel
        this.apply((await analyzeDaily(this.path, this.date, this.data.version, extra)).data)
        if (prefill) {
          await this.$nextTick()
          this.$refs.cockpit.adopt()
        } else {
          this.$message.success('填写提示已生成，可在经营结论和辅导表中采用')
        }
      })
    },
    hasHelp(owner, key) {
      return this.helpList.some(h => h.owner_id == owner && h.subject?.key === key && h.report_date === this.date)
    },
    async initiate() {
      const selection = this.coordinationProjects.find(opt => opt.value === this.coordinationProject)
      if (!selection) return
      const { row, project: p } = selection
      let note
      try {
        note = (
          await this.$prompt('这项协调要解决什么问题？', '发起协调', {
            inputValue: p.blocker || '',
            inputPattern: /\S/,
            inputErrorMessage: '请填写具体事项'
          })
        ).value
      } catch (_) {
        return
      }
      return this.run(async () => {
        if (this.dirty) await this.write()
        await api.post('/v1/erp/crm/daily-reports/' + this.path + '/coordination', {
          date: this.date,
          owner: row.id,
          key: p.key,
          note
        })
        this.coordinationProject = ''
        this.apply((await readDaily(this.path, this.date)).data)
      })
    },
    handle(h) {
      this.current = h
      this.decision = {
        status: h.help_status === 'accepted' ? 'done' : 'accepted',
        due: h.help_due || this.date,
        note: h.manager_note || ''
      }
      this.editor = true
    },
    resolve() {
      return this.run(async () => {
        if (this.dirty) await this.write()
        await resolveDailyHelp(this.dept, this.current, this.decision)
        this.editor = false
        this.apply((await readDaily(this.path, this.date)).data)
        this.$message.success('协调事项已更新')
      })
    },
    async refreshMemberData() {
      const { data } = await readDaily(this.path, this.date)
      this.data = { ...this.data, staff: data.staff, help: data.help }
    },
    openSalesSheet(row) {
      this.inspectingSales = row
      this.inspectDialog = true
    },
    download() {
      return this.run(async () => {
        const { data } = await readDaily(this.path, this.date)
        if (!this.dirty) this.apply(data)
        else this.data = { ...data, version: this.data.version }
        await this.$nextTick()
        return exportDaily('简云智水C端3.0经营驾驶舱-' + this.date, this.$refs.cockpit.exportSheets())
      })
    }
  }
}
</script>

<style scoped>
.coord-launch-bar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
  padding: 10px 12px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
}
.coord-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
  gap: 10px;
}
.coord-card {
  border: 1px solid #e2e8f0;
  border-left: 4px solid #94a3b8;
  border-radius: 8px;
  padding: 10px 14px;
  background: #ffffff;
}
.coord-card.status-pending {
  border-left-color: #dc2626;
  background: #fefafa;
}
.coord-card.status-accepted {
  border-left-color: #0284c7;
  background: #f8fcff;
}
.coord-card.status-returned {
  border-left-color: #d97706;
  background: #fffcf5;
}
.coord-card.status-done {
  border-left-color: #008b4b;
  background: #f7fcf9;
}
.coord-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 6px;
}
.coord-card-body {
  font-size: 13px;
  color: #1e293b;
  line-height: 1.5;
  margin-bottom: 6px;
}
@media (max-width: 680px) {
  .coord-grid {
    grid-template-columns: 1fr;
  }
}
</style>
