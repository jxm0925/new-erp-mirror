<template>
  <el-dialog class="crm-dialog" :title="title" :visible.sync="visible" :width="['assign', 'transfer'].includes(action) ? '940px' : '700px'" append-to-body :close-on-click-modal="false" :before-close="close">
    <div v-loading="loading">
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
      <div class="action-summary-card">
        <div class="summary-main">
          <b>{{ lead.customer_name || '—' }}</b>
          <span class="summary-sub">{{ lead.demand_summary || lead.lead_no }}</span>
          <el-tag v-if="lead.status_label" size="mini" type="success">{{ lead.status_label }}</el-tag>
        </div>
        <div class="summary-meta">
          <span>线索编号：{{ lead.lead_no || '—' }}</span>
          <span>当前负责人：<b>{{ lead.owner_name || lead.pending_user_name || '待分配' }}</b></span>
          <span>所属部门：{{ lead.department_name || '—' }}</span>
          <span v-if="action === 'recycle'">最近联系：{{ date(lead.last_contact_at) }}</span>
          <span v-if="action === 'recycle'" :class="{ 'crm-overdue': lead.next_contact_at && new Date(lead.next_contact_at) < new Date() }">
            下次联系：{{ date(lead.next_contact_at) }}
          </span>
          <span v-if="action === 'claim'">领取后负责人：<b class="green-text">{{ currentUser.name || '当前销售本人' }}</b></span>
        </div>
      </div>

      <template v-if="['assign', 'transfer'].includes(action)">
        <div class="crm-filters assignee-filters">
          <span class="dept-chip"><i class="el-icon-office-building" /> 分配部门：<b>{{ lead.department_name || '—' }}</b></span>
          <el-input v-model.trim="peopleQuery.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="销售姓名 / 能力标签" @keyup.enter.native="searchPeople" @clear="searchPeople" />
          <el-button size="small" type="success" icon="el-icon-search" @click="searchPeople">查询</el-button>
        </div>
        <el-table v-loading="peopleLoading" :data="people" size="small" :row-class-name="personRowClass" @row-click="selectPerson">
          <el-table-column width="44">
            <template slot-scope="{row}">
              <el-radio v-model="form.to_user_legacy_id" :label="row.id" :disabled="!selectable(row)">&nbsp;</el-radio>
            </template>
          </el-table-column>
          <el-table-column prop="name" label="销售员" min-width="95">
            <template slot-scope="{row}"><b>{{ row.name }}</b></template>
          </el-table-column>
          <el-table-column label="所属小组" min-width="115">
            <template slot-scope="{row}">{{ row.group_name || row.department_name || '—' }}</template>
          </el-table-column>
          <el-table-column label="能力标签" min-width="190">
            <template slot-scope="{row}">
              <el-tag v-for="tag in allTags(row)" :key="tag" size="mini" type="success" class="crm-tag">{{ tag }}</el-tag>
              <span v-if="!allTags(row).length" class="crm-muted">—</span>
            </template>
          </el-table-column>
          <el-table-column label="在跟商机" min-width="90" align="right">
            <template slot-scope="{row}">{{ row.open_opportunities === null || row.open_opportunities === undefined ? '—' : row.open_opportunities }}</template>
          </el-table-column>
          <el-table-column label="待接收线索" min-width="100" align="right">
            <template slot-scope="{row}">{{ row.pending_leads === null || row.pending_leads === undefined ? '—' : row.pending_leads }}</template>
          </el-table-column>
          <el-table-column label="承接状态" min-width="105">
            <template slot-scope="{row}">
              <el-tag size="mini" :type="selectable(row) ? 'success' : 'info'">{{ selectable(row) ? '可接收' : '暂停接收' }}</el-tag>
            </template>
          </el-table-column>
        </el-table>
        <div class="crm-pager">
          <el-pagination background layout="total, sizes, prev, pager, next" :total="peopleTotal" :current-page.sync="peopleQuery.page" :page-size.sync="peopleQuery.per_page" :page-sizes="[10,20,50]" @size-change="searchPeople" @current-change="loadPeople" />
        </div>
      </template>

      <el-form size="small" label-width="92px" class="action-form" :disabled="saving || loading || !!error">
        <el-form-item v-if="['return','recycle','invalidate'].includes(action)" :label="action === 'return' ? '退回原因' : action === 'recycle' ? '回收原因' : '无效原因'" required>
          <el-select popper-class="crm-select-popper" v-model="form.reason" filterable allow-create default-first-option placeholder="选择或输入原因">
            <el-option v-for="item in reasonOptions" :key="item" :label="item" :value="item" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="['assign','transfer'].includes(action)" :label="action === 'assign' ? '分配说明' : '转交原因'" required>
          <el-input v-model.trim="form.reason" type="textarea" :rows="2" maxlength="500" placeholder="请填写分配说明或转交原因，供接收销售参考" />
        </el-form-item>
        <el-form-item v-if="['assign','transfer'].includes(action)" label="分配附件">
          <crm-attachments ref="attachments" v-model="attachments" @busy="uploading = $event" />
        </el-form-item>
        <el-form-item v-if="['return','recycle','invalidate'].includes(action)" label="详细说明" required>
          <el-input v-model.trim="form.note" type="textarea" :rows="2" maxlength="500" placeholder="请补充具体情况说明，将记录在操作日志中" />
        </el-form-item>
        <el-form-item v-if="action === 'transfer' && lead.customer" label="客户负责人">
          <el-checkbox v-model="form.transfer_customer" :disabled="!allowed.transfer_customer">
            同时移交客户 {{ lead.customer.customer_name }} 的负责人
          </el-checkbox>
          <small class="crm-sub">{{ allowed.transfer_customer ? '按独立客户转交权限办理' : '你没有客户转交权限；客户负责人保持不变' }}</small>
        </el-form-item>
        <el-form-item v-if="action === 'pool'" label="客户池" required>
          <el-select popper-class="crm-select-popper" v-model="form.customer_pool" placeholder="请选择目标客户池">
            <el-option v-for="item in pools" :key="item" :value="item" :label="item" />
          </el-select>
        </el-form-item>
        <el-form-item v-if="action === 'pool'" label="调整原因" required>
          <el-input v-model.trim="form.reason" type="textarea" :rows="2" maxlength="500" placeholder="请说明调整客户池的原因" />
        </el-form-item>
      </el-form>

      <div class="crm-note" :class="{ warning: ['recycle', 'invalidate', 'return'].includes(action) }">
        <i :class="['recycle', 'invalidate', 'return'].includes(action) ? 'el-icon-warning-outline' : 'el-icon-info'" />
        {{ explanation }}
      </div>
    </div>

    <span slot="footer" class="crm-footer">
      <el-button size="small" :disabled="saving" @click="close">取消</el-button>
      <el-button size="small" type="success" :loading="saving" :disabled="loading || peopleLoading || uploading || !!error || !allowed[action]" @click="save">
        {{ title.replace('线索','').replace('到公海','') === '回收' ? '确认回收' : '确认' + (labels[action] || '') }}
      </el-button>
    </span>

    <el-dialog class="crm-dialog" title="操作未成功" :visible.sync="conflict.visible" width="520px" append-to-body>
      <el-alert :title="conflict.message" type="error" :closable="false" show-icon />
      <div class="crm-note">请刷新列表后重新选择。</div>
      <span slot="footer">
        <el-button size="small" type="success" @click="refreshAfterConflict">刷新列表</el-button>
      </span>
    </el-dialog>
  </el-dialog>
</template>

<script>
import { getCrmLead, getCrmLeadMetadata, listCrmLeadAssignees, actCrmLead, crmDate, crmError } from '@/api/erp/crm'
import CrmAttachments from './CrmAttachments.vue'
import '@/styles/crm.css'

const labels = {
  assign: '分配',
  accept: '接收',
  return: '退回',
  transfer: '转交',
  recycle: '回收',
  claim: '领取',
  pool: '调整客户池',
  confirm: '确认基础线索',
  invalidate: '标记无效'
}

export default {
  name: 'CrmLeadActionDialog',
  components: { CrmAttachments },
  data: () => ({
    visible: false,
    loading: false,
    saving: false,
    uploading: false,
    attachments: [],
    session: 0,
    peopleSequence: 0,
    action: '',
    lead: {},
    currentUser: {},
    pools: [],
    form: {},
    people: [],
    peopleTotal: 0,
    peopleLoading: false,
    peopleQuery: { page: 1, per_page: 10, keyword: '' },
    error: '',
    conflict: { visible: false, message: '' },
    labels
  }),
  computed: {
    title() {
      return this.action === 'recycle' ? '回收到公海' : (labels[this.action] || '') + (['pool', 'confirm', 'invalidate'].includes(this.action) ? '' : '线索')
    },
    allowed() { return this.lead.abilities || {} },
    reasonOptions() {
      return this.action === 'return'
        ? ['不在本人负责范围', '客户资料不完整', '重复线索', '其他']
        : ['超期未跟进', '暂缓采购', '原负责人停用', '客户无需求', '其他']
    },
    explanation() {
      return ({
        assign: '分配后进入新负责人的待接收列表，销售可确认接收或填写原因退回。',
        transfer: '转交后进入新负责人的待接收列表；已产生订单的销售归属和历史成交不变。',
        return: '退回后线索回到待分配，由分配人重新处理；退回记录保留在操作记录中。',
        recycle: '自动回收未开启，本次为人工回收。回收后原销售不再负责该线索；客户负责人不变。',
        claim: '多人同时领取时，只有先提交的一方成功。',
        accept: '接收后由你负责该线索的日常跟进。',
        pool: '客户池只调整本条线索的经营分组。',
        confirm: '确认客户和基本咨询有效，保留确认时间和确认人。',
        invalidate: '标记无效后保留线索和操作记录。'
      })[this.action] || ''
    }
  },
  beforeDestroy() {
    this.session += 1
    this.peopleSequence += 1
  },
  methods: {
    date: crmDate,
    allTags(row) {
      return [...new Set(Object.values(row.tags || {}).flat())]
    },
    selectable(row) {
      return row.id !== this.lead.owner_legacy_id && row.is_effective !== false && row.accepting !== false && row.selectable !== false
    },
    personRowClass({ row }) {
      if (this.form.to_user_legacy_id && row.id === this.form.to_user_legacy_id) return 'crm-selected-row'
      return ''
    },
    selectPerson(row) {
      if (this.selectable(row)) this.form.to_user_legacy_id = row.id
    },
    async open(action, lead) {
      const session = ++this.session
      this.action = action
      this.lead = {}
      this.form = { to_user_legacy_id: null, reason: '', note: '', transfer_customer: false, customer_pool: '' }
      this.people = []
      this.peopleTotal = 0
      this.peopleQuery = { page: 1, per_page: 10, keyword: '' }
      this.visible = true
      this.loading = true
      this.saving = false
      this.uploading = false
      this.attachments = []
      this.$nextTick(() => { if (this.$refs.attachments) this.$refs.attachments.reset() })
      this.error = ''
      this.conflict.visible = false
      try {
        const [response, meta] = await Promise.all([getCrmLead(typeof lead === 'object' ? lead.id : lead), getCrmLeadMetadata()])
        if (session !== this.session) return
        this.lead = response.data.data || response.data
        this.attachments = [...(this.lead.assignment_attachments || [])]
        this.currentUser = (meta.data.data || meta.data).current_user || {}
        this.pools = (meta.data.data || meta.data).customer_pools || []
        this.form.customer_pool = this.lead.customer_pool || ''
        if (!this.allowed[action]) this.error = '该线索状态或权限已变化，请刷新后重新选择。'
        else if (['assign', 'transfer'].includes(action)) await this.loadPeople()
      } catch (error) {
        if (session === this.session) this.error = crmError(error, '线索加载失败')
      } finally {
        if (session === this.session) this.loading = false
      }
    },
    searchPeople() {
      this.peopleQuery.page = 1
      return this.loadPeople()
    },
    async loadPeople() {
      const session = this.session
      const sequence = ++this.peopleSequence
      this.peopleLoading = true
      try {
        const { data } = await listCrmLeadAssignees(this.lead.id, { ...this.peopleQuery, action: this.action })
        if (session !== this.session || sequence !== this.peopleSequence) return
        this.people = data.data || []
        this.peopleTotal = data.total || 0
      } catch (error) {
        if (session === this.session && sequence === this.peopleSequence) this.$message.error(crmError(error, '销售列表加载失败'))
      } finally {
        if (session === this.session && sequence === this.peopleSequence) this.peopleLoading = false
      }
    },
    close() {
      if (this.saving) return
      this.session += 1
      this.peopleSequence += 1
      this.visible = false
      if (this.$refs.attachments) this.$refs.attachments.reset()
    },
    refreshAfterConflict() {
      this.conflict.visible = false
      this.visible = false
      this.session += 1
      this.$emit('changed')
    },
    async save() {
      if (this.saving || this.loading || this.uploading || this.error || !this.allowed[this.action]) return
      if (['assign', 'transfer'].includes(this.action) && !this.form.to_user_legacy_id) return this.$message.warning('请选择接收销售')
      if (['assign', 'transfer', 'return', 'recycle', 'invalidate', 'pool'].includes(this.action) && !this.form.reason) return this.$message.warning('请填写原因或说明')
      if (['return', 'recycle', 'invalidate'].includes(this.action) && !this.form.note) return this.$message.warning('请填写说明')
      if (this.action === 'pool' && !this.form.customer_pool) return this.$message.warning('请选择客户池')
      const session = this.session
      this.saving = true
      try {
        await actCrmLead(this.lead.id, this.action, {
          ...this.form,
          version: this.lead.version,
          customer_version: this.lead.customer && this.lead.customer.crm_version,
          ...(['assign', 'transfer'].includes(this.action) ? { assignment_attachment_ids: this.attachments.map(file => file.id) } : {})
        })
        if (session !== this.session) return
        this.visible = false
        this.$message.success('操作成功')
        this.$emit('changed')
      } catch (error) {
        if (session !== this.session) return
        const message = crmError(error, '操作失败')
        if ([409, 422].includes(error.response && error.response.status) && (this.action === 'claim' || /刷新|其他人|已被/.test(message))) {
          this.conflict = { visible: true, message }
        } else {
          this.$message.error(message)
        }
      } finally {
        if (session === this.session) this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.action-summary-card {
  padding: 12px 14px;
  margin-bottom: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}
.summary-main {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 14px;
  color: #1e293b;
}
.summary-sub {
  color: #475569;
  font-size: 13px;
}
.summary-meta {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
  margin-top: 6px;
  font-size: 12px;
  color: #64748b;
}
.summary-meta b {
  color: #1e293b;
}
.green-text {
  color: #00763f !important;
}
.assignee-filters {
  margin-bottom: 10px;
}
.dept-chip {
  font-size: 13px;
  color: #475569;
}
.dept-chip b {
  color: #00763f;
}
.action-form {
  margin-top: 12px;
}
</style>
