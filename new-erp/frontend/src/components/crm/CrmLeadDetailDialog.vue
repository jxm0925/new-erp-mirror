<template>
  <el-dialog class="crm-dialog" :visible.sync="visible" width="1140px" append-to-body :close-on-click-modal="false" :before-close="close">
    <div slot="title" class="detail-head">
      <div class="detail-head-main">
        <b>线索详情 · {{ lead.customer_name || '加载中' }}</b>
        <el-tag v-if="lead.status_label" size="mini" :type="statusType">{{ lead.status_label }}</el-tag>
        <span v-if="lead.confirmed_at" class="head-pill is-green"><i class="el-icon-check" /> 有效线索</span>
        <span v-if="lead.customer_kind" class="head-pill">{{ lead.customer_kind === 'enterprise' ? '企业客户' : '个人客户' }}</span>
        <span v-if="lead.lead_no" class="head-no">
          {{ lead.lead_no }}
          <i class="el-icon-document-copy crm-copy-btn" title="复制线索编号" @click="copyNo(lead.lead_no)" />
        </span>
      </div>
    </div>

    <div v-loading="loading" class="detail-container">
      <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
      <template v-if="lead.id && !error">
        <div v-if="lead.review" class="review-banner">
          <div class="review-banner-text">
            <i class="el-icon-warning-outline" />
            <div>
              <b>客户身份待核实：</b>
              <span>{{ lead.review.application_note || '当前线索存在疑似冲突客户，需核实后方可分配跟进。' }}</span>
            </div>
          </div>
          <el-button size="mini" type="warning" @click="$refs.review.openReview(lead.review)">
            {{ lead.review.can_resolve ? '立即处理核实' : '查看核实申请' }}
          </el-button>
        </div>

        <section class="lead-metrics">
          <div class="metric-item" :class="statusTone">
            <div class="m-label">当前状态</div>
            <div class="m-value">{{ lead.status_label || '—' }}</div>
            <div class="m-sub">{{ lead.entry_method === 'self' ? '销售自录' : '运营代录' }} · {{ lead.source_channel || '—' }}</div>
          </div>
          <div class="metric-item">
            <div class="m-label">负责销售 / 部门</div>
            <div class="m-value">{{ lead.pending_user_name ? lead.pending_user_name + '（待接收）' : (lead.owner_name || '待分配') }}</div>
            <div class="m-sub">{{ lead.department_name || '未指定部门' }}</div>
          </div>
          <div class="metric-item is-green">
            <div class="m-label">所在客户池</div>
            <div class="m-value">{{ lead.customer_pool || '未入池' }}</div>
            <div class="m-sub">{{ lead.is_new_inquiry ? '新咨询线索' : '老客/复购咨询' }}</div>
          </div>
          <div class="metric-item">
            <div class="m-label">预计成交额</div>
            <div class="m-value">{{ money(lead.expected_amount, lead.currency) }}</div>
            <div class="m-sub">{{ [lead.trade_type, lead.lead_type].filter(Boolean).join(' · ') || '普通咨询' }}</div>
          </div>
          <div class="metric-item" :class="{ 'is-red': isOverdue }">
            <div class="m-label">下次联系计划</div>
            <div class="m-value" :class="{ 'crm-overdue': isOverdue }">{{ date(lead.next_contact_at) }}</div>
            <div class="m-sub">最近联系：{{ date(lead.last_contact_at) }}</div>
          </div>
        </section>

        <div class="detail-grid">
          <section class="crm-box detail-card">
            <div class="card-head">
              <h3 class="crm-section-title">客户与联系信息</h3>
              <el-button
                v-if="lead.customer && lead.customer.visible !== false"
                type="text"
                size="mini"
                icon="el-icon-office-building"
                @click="showCustomer"
              >查看关联客户 ({{ lead.customer.customer_code || '详情' }})</el-button>
            </div>
            <div class="kv-grid">
              <div class="kv-row full">
                <span>客户名称</span>
                <span>
                  <b>{{ lead.customer_name || '—' }}</b>
                  <el-tag v-if="lead.customer" size="mini" type="success" class="inline-tag">
                    {{ lead.customer.customer_code || '已关联客户' }}
                  </el-tag>
                </span>
              </div>
              <div v-if="lead.customer_kind === 'enterprise'" class="kv-row full">
                <span>统一信用代码</span>
                <span>{{ lead.credit_code || '—' }}</span>
              </div>
              <div class="kv-row">
                <span>对接联系人</span>
                <span>{{ lead.contact_name || '—' }}{{ lead.contact_role ? `（${lead.contact_role}）` : '' }}</span>
              </div>
              <div class="kv-row">
                <span>手机号码</span>
                <span>{{ lead.mobile || '—' }}</span>
              </div>
              <div class="kv-row">
                <span>联系方式2</span>
                <span>{{ lead.contact_value2 || '—' }}</span>
              </div>
              <div class="kv-row">
                <span>微信号</span>
                <span>{{ lead.wechat || '—' }}</span>
              </div>
              <div class="kv-row full">
                <span>电子邮箱</span>
                <span>{{ lead.email || '—' }}</span>
              </div>
              <div class="kv-row full">
                <span>所在地区</span>
                <span>{{ [lead.province, lead.city, lead.district].filter(Boolean).join(' / ') || '—' }}</span>
              </div>
              <div class="kv-row full">
                <span>详细地址</span>
                <span>{{ lead.detail_address || lead.full_address || '—' }}</span>
              </div>
              <template v-if="lead.platform_legacy_id || lead.platform_buyer_id">
                <div class="kv-row">
                  <span>接待平台/店铺</span>
                  <span>{{ lead.platform_name || lead.platform_legacy_id || '—' }}</span>
                </div>
                <div class="kv-row">
                  <span>平台买家ID</span>
                  <span class="mono-text">{{ lead.platform_buyer_id || '—' }}</span>
                </div>
              </template>
            </div>
          </section>

          <section class="crm-box detail-card">
            <div class="card-head">
              <h3 class="crm-section-title">线索画像与分配流转</h3>
            </div>
            <div class="kv-grid">
              <div class="kv-row">
                <span>来源渠道</span>
                <span>{{ lead.source_channel || '—' }}</span>
              </div>
              <div class="kv-row">
                <span>录入方式</span>
                <span>{{ lead.entry_method === 'self' ? '销售自录' : '运营代录' }}</span>
              </div>
              <div class="kv-row">
                <span>贸易类型</span>
                <span>{{ lead.trade_type || '—' }}</span>
              </div>
              <div class="kv-row">
                <span>线索类型</span>
                <span>{{ lead.lead_type || '—' }}</span>
              </div>
              <div class="kv-row full">
                <span>业务线</span>
                <span>
                  <template v-if="(lead.business_lines || []).length">
                    <el-tag v-for="item in lead.business_lines" :key="item" size="mini" type="success" class="crm-tag">{{ item }}</el-tag>
                  </template>
                  <template v-else>—</template>
                </span>
              </div>
              <div class="kv-row full">
                <span>客户标签</span>
                <span>
                  <template v-if="(lead.customer_tags || []).length">
                    <el-tag v-for="item in lead.customer_tags" :key="item" size="mini" class="crm-tag">{{ item }}</el-tag>
                  </template>
                  <template v-else>—</template>
                </span>
              </div>
              <div class="kv-row full">
                <span>自定义标签</span>
                <span>
                  <template v-if="(lead.custom_tags || []).length">
                    <el-tag v-for="item in lead.custom_tags" :key="item" size="mini" type="info" class="crm-tag">{{ item }}</el-tag>
                  </template>
                  <template v-else>—</template>
                </span>
              </div>
              <div class="kv-row">
                <span>录入人 / 时间</span>
                <span>{{ lead.created_by_name || '—' }} · {{ date(lead.created_at) }}</span>
              </div>
              <div class="kv-row">
                <span>分配人 / 时间</span>
                <span>{{ lead.assigned_by_name || '—' }}{{ lead.assigned_at ? ' · ' + date(lead.assigned_at) : '' }}</span>
              </div>
              <div class="kv-row full">
                <span>分配说明</span>
                <span>{{ lead.assignment_reason || '—' }}</span>
              </div>
              <div v-if="(lead.assignment_attachments || []).length" class="kv-row full">
                <span>分配附件</span>
                <span><crm-attachments :value="lead.assignment_attachments" readonly /></span>
              </div>
              <div v-if="lead.recycle_reason" class="kv-row full">
                <span>公海回收</span>
                <span>{{ lead.recycle_reason }}（{{ lead.recycled_by_name || '—' }} · {{ date(lead.recycled_at) }}）</span>
              </div>
              <div v-if="lead.invalid_reason" class="kv-row full">
                <span>无效原因</span>
                <span class="crm-overdue">{{ lead.invalid_reason }}</span>
              </div>
            </div>
          </section>
        </div>

        <section class="crm-box demand-box">
          <h3 class="crm-section-title">需求与沟通详情</h3>
          <div class="kv-grid">
            <div class="kv-row full">
              <span>需求描述</span>
              <span class="pre-text">{{ lead.demand_summary || '—' }}</span>
            </div>
            <div class="kv-row full">
              <span>初次沟通内容</span>
              <span class="pre-text">{{ lead.content || '—' }}</span>
            </div>
            <div class="kv-row full">
              <span>下次联系计划</span>
              <span>
                <b :class="{ 'crm-overdue': isOverdue }">{{ date(lead.next_contact_at) }}</b>
                <span v-if="lead.next_plan"> · {{ lead.next_plan }}</span>
              </span>
            </div>
            <div class="kv-row full">
              <span>图片附件</span>
              <span>
                <crm-attachments v-if="(lead.attachments || []).length" :value="lead.attachments" readonly />
                <span v-else class="crm-muted">暂无附件</span>
              </span>
            </div>
          </div>
        </section>

        <section class="crm-box records-box">
          <div class="records-header">
            <el-tabs v-model="activeRecordTab" class="record-tabs">
              <el-tab-pane :label="`跟进记录 (${followupTotal})`" name="followups" />
              <el-tab-pane :label="`操作记录 (${logTotal})`" name="logs" />
            </el-tabs>
            <el-button v-if="allowed.follow" size="mini" type="success" icon="el-icon-plus" @click="editFollowup()">记录跟进</el-button>
          </div>

          <div v-show="activeRecordTab === 'followups'">
            <el-timeline v-if="followups.length" class="lead-timeline">
              <el-timeline-item
                v-for="item in followups"
                :key="item.id"
                :timestamp="`${date(item.contacted_at)} · ${item.created_by_name || item.operator_name || ''}`"
                placement="top"
                color="#008b4b"
              >
                <div class="followup-card">
                  <div class="followup-head">
                    <span class="method-badge">{{ item.method }}</span>
                    <b>对接人：{{ item.contact_name || '—' }}</b>
                    <el-tag size="mini" type="success">{{ item.result }}</el-tag>
                    <el-button v-if="item.can_edit" type="text" size="mini" icon="el-icon-edit" class="edit-btn" @click="editFollowup(item)">修改跟进</el-button>
                  </div>
                  <p class="follow-content">{{ item.content }}</p>
                  <div class="follow-next">
                    <span><i class="el-icon-time" /> 下次联系：<b>{{ date(item.next_contact_at) }}</b></span>
                    <span v-if="item.next_plan" class="sep">|</span>
                    <span v-if="item.next_plan">下次计划：{{ item.next_plan }}</span>
                  </div>
                  <crm-attachments v-if="(item.attachments || []).length" :value="item.attachments" readonly />
                </div>
              </el-timeline-item>
            </el-timeline>
            <div v-else class="crm-note empty-note">暂无跟进记录，接收线索后可通过右上角“记录跟进”登记联系情况</div>
            <div v-if="followupTotal > 0" class="crm-pager">
              <el-pagination background layout="total, prev, pager, next" :total="followupTotal" :current-page.sync="followupPage" :page-size="10" @current-change="loadFollowups" />
            </div>
          </div>

          <div v-show="activeRecordTab === 'logs'">
            <el-timeline v-if="logs.length" class="lead-timeline">
              <el-timeline-item
                v-for="item in logs"
                :key="item.id"
                :timestamp="`${date(item.created_at)} · ${item.operator_name || '系统'}`"
                placement="top"
                color="#008b4b"
              >
                <div class="log-card">
                  <b>{{ item.summary || item.action }}</b>
                  <small v-if="item.reason" class="crm-sub">原因/说明：{{ item.reason }}</small>
                </div>
              </el-timeline-item>
            </el-timeline>
            <div v-else class="crm-note empty-note">暂无操作记录</div>
            <div v-if="logTotal > 0" class="crm-pager">
              <el-pagination background layout="total, prev, pager, next" :total="logTotal" :current-page.sync="logPage" :page-size="10" @current-change="loadLogs" />
            </div>
          </div>
        </section>
      </template>
    </div>

    <div slot="footer" class="crm-footer">
      <el-button v-if="lead.customer && lead.customer.visible !== false" size="small" icon="el-icon-office-building" :disabled="loading" @click="showCustomer">查看客户</el-button>
      <el-button v-if="allowed.edit" size="small" icon="el-icon-edit" @click="$refs.form.open(lead.id)">编辑</el-button>
      <el-button v-if="allowed.return" size="small" icon="el-icon-refresh-left" @click="act('return')">退回</el-button>
      <el-button v-if="allowed.assign" size="small" type="success" icon="el-icon-s-promotion" @click="act('assign')">分配线索</el-button>
      <el-button v-if="allowed.accept" size="small" type="success" icon="el-icon-check" @click="act('accept')">接收线索</el-button>
      <el-button v-if="allowed.claim" size="small" type="success" icon="el-icon-thumb" @click="act('claim')">领取线索</el-button>
      <el-button v-if="allowed.follow" size="small" type="success" icon="el-icon-chat-line-round" @click="editFollowup()">记录跟进</el-button>
      <el-button v-if="allowed.confirm" size="small" icon="el-icon-circle-check" @click="act('confirm')">有效线索</el-button>
      <el-button v-if="allowed.convert" size="small" type="success" icon="el-icon-aim" @click="$refs.opportunityForm.open({lead})">转为商机</el-button>
      <el-button v-if="lead.opportunity_id" size="small" icon="el-icon-connection" @click="$refs.opportunityDetail.open(lead.opportunity_id)">查看商机</el-button>
      <el-dropdown v-if="allowed.transfer || allowed.recycle || allowed.pool || allowed.invalidate" @command="act">
        <el-button size="small">更多<i class="el-icon-arrow-down el-icon--right" /></el-button>
        <el-dropdown-menu slot="dropdown" class="crm-select-popper">
          <el-dropdown-item v-if="allowed.transfer" command="transfer">转交线索</el-dropdown-item>
          <el-dropdown-item v-if="allowed.pool" command="pool">调整客户池</el-dropdown-item>
          <el-dropdown-item v-if="allowed.recycle" command="recycle" class="crm-danger-link">回收到公海</el-dropdown-item>
          <el-dropdown-item v-if="allowed.invalidate" command="invalidate" class="crm-danger-link">标记无效</el-dropdown-item>
        </el-dropdown-menu>
      </el-dropdown>
      <el-button size="small" @click="close">关闭</el-button>
    </div>

    <template v-if="visible">
      <crm-lead-action-dialog ref="action" :key="`action-${session}`" @changed="changed" />
      <crm-followup-dialog ref="follow" :key="`follow-${session}`" @saved="changed" />
      <crm-lead-form-dialog ref="form" :key="`form-${session}`" @saved="changed" />
      <crm-identity-review-dialog ref="review" :key="`review-${session}`" @saved="changed" />
      <crm-opportunity-form-dialog ref="opportunityForm" :key="`opportunity-form-${session}`" @saved="opportunitySaved" />
      <crm-opportunity-detail-dialog ref="opportunityDetail" :key="`opportunity-detail-${session}`" @changed="changed" />
      <customer-detail-dialog ref="customer" :key="`customer-${session}`" />
    </template>
  </el-dialog>
</template>

<script>
import { getCrmLead, listCrmLeadLogs, listCrmFollowups, crmError, crmDate } from '@/api/erp/crm'
import CrmAttachments from './CrmAttachments.vue'
import CrmLeadActionDialog from './CrmLeadActionDialog.vue'
import CrmLeadFormDialog from './CrmLeadFormDialog.vue'
import CrmFollowupDialog from './CrmFollowupDialog.vue'
import CrmIdentityReviewDialog from './CrmIdentityReviewDialog.vue'
import '@/styles/crm.css'

export default {
  name: 'CrmLeadDetailDialog',
  components: {
    CrmAttachments,
    CrmLeadActionDialog,
    CrmLeadFormDialog,
    CrmFollowupDialog,
    CrmIdentityReviewDialog,
    CrmOpportunityFormDialog: () => import('./CrmOpportunityFormDialog.vue'),
    CrmOpportunityDetailDialog: () => import('./CrmOpportunityDetailDialog.vue'),
    CustomerDetailDialog: () => import('@/components/sales/CustomerDetailDialog.vue')
  },
  data: () => ({
    visible: false,
    loading: false,
    session: 0,
    sequence: 0,
    lead: {},
    error: '',
    activeRecordTab: 'followups',
    logs: [],
    logPage: 1,
    logTotal: 0,
    followups: [],
    followupPage: 1,
    followupTotal: 0,
    logSequence: 0,
    followupSequence: 0
  }),
  computed: {
    allowed() { return this.lead.abilities || {} },
    statusType() {
      if (['pending_acceptance', 'pending_assignment', 'draft'].includes(this.lead.status)) return 'warning'
      if (this.lead.status === 'pending_review' || this.lead.status === 'invalid') return 'danger'
      if (this.lead.status === 'public_pool') return 'info'
      return 'success'
    },
    statusTone() {
      if (['pending_acceptance', 'pending_assignment', 'draft'].includes(this.lead.status)) return 'is-amber'
      if (this.lead.status === 'pending_review' || this.lead.status === 'invalid') return 'is-red'
      return 'is-green'
    },
    isOverdue() {
      return !!(this.lead.next_contact_at && this.lead.status === 'active' && new Date(this.lead.next_contact_at).getTime() < Date.now())
    }
  },
  beforeDestroy() { this.session += 1 },
  methods: {
    date: crmDate,
    money(value, currency) {
      return value === null || value === undefined ? '—' : `${currency || 'CNY'} ${Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 2 })}`
    },
    copyNo(text) {
      if (!text) return
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => this.$message.success(`线索编号已复制：${text}`))
      }
    },
    open(id) {
      this.session += 1
      this.visible = true
      this.lead = {}
      this.logs = []
      this.followups = []
      this.logPage = 1
      this.followupPage = 1
      this.activeRecordTab = 'followups'
      return this.load(id)
    },
    async load(id) {
      const session = this.session
      const sequence = ++this.sequence
      this.loading = true
      this.error = ''
      try {
        const { data } = await getCrmLead(id)
        if (session !== this.session || sequence !== this.sequence) return
        this.lead = data.data || data
        await Promise.all([this.loadLogs(), this.loadFollowups()])
        if (session === this.session && !this.followups.length && this.logs.length) {
          this.activeRecordTab = 'logs'
        }
      } catch (error) {
        if (session === this.session && sequence === this.sequence) this.error = crmError(error, '线索详情加载失败')
      } finally {
        if (session === this.session && sequence === this.sequence) this.loading = false
      }
    },
    async loadLogs() {
      const session = this.session
      const sequence = ++this.logSequence
      try {
        const { data } = await listCrmLeadLogs(this.lead.id, { page: this.logPage, per_page: 10 })
        if (session === this.session && sequence === this.logSequence) {
          this.logs = data.data || []
          this.logTotal = data.total || 0
        }
      } catch (error) {
        if (session === this.session && sequence === this.logSequence) this.$message.error(crmError(error, '操作记录加载失败'))
      }
    },
    async loadFollowups() {
      if (!this.lead.customer_id) {
        this.followups = []
        this.followupTotal = 0
        return
      }
      const session = this.session
      const sequence = ++this.followupSequence
      try {
        const { data } = await listCrmFollowups({ subject_type: 'lead', subject_id: this.lead.id, page: this.followupPage, per_page: 10 })
        if (session === this.session && sequence === this.followupSequence) {
          this.followups = data.data || []
          this.followupTotal = data.total || 0
        }
      } catch (error) {
        if (session === this.session && sequence === this.followupSequence) this.$message.error(crmError(error, '跟进记录加载失败'))
      }
    },
    close() {
      this.session += 1
      this.visible = false
    },
    act(action) {
      this.$refs.action.open(action, this.lead)
    },
    editFollowup(row) {
      this.$refs.follow.open({
        subject_type: 'lead',
        subject_id: this.lead.id,
        customer_id: this.lead.customer_id,
        customer_name: this.lead.customer_name,
        lead_no: this.lead.lead_no,
        contact_name: this.lead.contact_name
      }, row)
    },
    showCustomer() {
      this.$refs.customer.open(this.lead.customer_id)
    },
    changed() {
      this.$emit('changed')
      if (this.visible) this.load(this.lead.id)
    },
    opportunitySaved(row) {
      this.changed()
      if (row && row.id && this.visible) this.$refs.opportunityDetail.open(row.id)
    }
  }
}
</script>

<style scoped>
.detail-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-right: 28px;
}
.detail-head-main {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.detail-head-main b {
  font-size: 16px;
  color: #1e293b;
}
.head-pill {
  padding: 1px 8px;
  border-radius: 4px;
  font-size: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  color: #475569;
}
.head-pill.is-green {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #00763f;
}
.head-no {
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  color: #64748b;
}

.detail-container {
  display: flex;
  flex-direction: column;
  gap: 14px;
}
.review-banner {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 10px 14px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
  color: #92400e;
  font-size: 13px;
}
.review-banner-text {
  display: flex;
  align-items: center;
  gap: 8px;
}
.review-banner-text i {
  font-size: 18px;
  color: #d97706;
}

.lead-metrics {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 10px;
}
.metric-item {
  padding: 10px 12px;
  border-radius: 8px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  min-width: 0;
}
.metric-item.is-green {
  background: #f0fdf4;
  border-color: #dcfce7;
}
.metric-item.is-amber {
  background: #fffbeb;
  border-color: #fef3c7;
}
.metric-item.is-red {
  background: #fef2f2;
  border-color: #fee2e2;
}
.m-label {
  font-size: 12px;
  color: #64748b;
}
.m-value {
  margin-top: 4px;
  font-size: 15px;
  font-weight: 700;
  color: #1e293b;
  word-break: break-word;
}
.m-sub {
  margin-top: 3px;
  font-size: 11px;
  color: #64748b;
}

.detail-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}
.detail-card {
  display: flex;
  flex-direction: column;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 10px;
}
.card-head .crm-section-title {
  margin: 0;
}

.kv-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  overflow: hidden;
}
.kv-row {
  display: grid;
  grid-template-columns: 98px minmax(0, 1fr);
  border-bottom: 1px solid #eef2f7;
  font-size: 12px;
}
.kv-row.full {
  grid-column: 1 / -1;
}
.kv-row > span {
  padding: 8px 10px;
  line-height: 1.55;
  word-break: break-word;
}
.kv-row > span:first-child {
  background: #f8fafc;
  color: #64748b;
  border-right: 1px solid #eef2f7;
}
.kv-row > span:last-child {
  color: #1e293b;
}
.inline-tag {
  margin-left: 6px;
}
.mono-text {
  font-family: Consolas, Monaco, monospace;
}
.pre-text {
  white-space: pre-wrap;
}

.records-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
}
.record-tabs {
  flex: 1;
  min-width: 0;
}
.record-tabs :deep(.el-tabs__header) {
  margin: 0;
}

.lead-timeline {
  padding-left: 4px;
}
.followup-card,
.log-card {
  padding: 10px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}
.followup-head {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.method-badge {
  padding: 1px 8px;
  border-radius: 4px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #00763f;
  font-size: 12px;
  font-weight: 600;
}
.edit-btn {
  margin-left: auto;
  padding: 0;
}
.follow-content {
  margin: 8px 0;
  white-space: pre-wrap;
  line-height: 1.7;
  color: #1e293b;
}
.follow-next {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 12px;
  color: #64748b;
  margin-bottom: 6px;
}
.sep {
  color: #cbd5e1;
}
.empty-note {
  text-align: center;
}

@media (max-width: 1180px) {
  .lead-metrics {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .detail-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
@media (max-width: 780px) {
  .lead-metrics {
    grid-template-columns: minmax(0, 1fr);
  }
  .kv-grid {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
