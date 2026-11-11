<template>
  <el-dialog :visible.sync="visible" width="1180px" top="5vh" append-to-body custom-class="crm-customer-detail" :close-on-click-modal="false" @closed="reset">
    <template slot="title">
      <div v-if="customer" class="detail-title">
        <b>{{ customer.customer_name }}</b>
        <span class="muted">{{ customer.customer_code }} · {{ customer.customer_kind === 'enterprise' ? '企业' : '个人' }}{{ customer.customer_grade ? ' · ' + customer.customer_grade : '' }} · 负责销售 {{ customer.owner_name || '暂无' }}{{ customer.owner_department_name ? ' / ' + customer.owner_department_name : '' }}</span>
        <span class="title-actions">
          <el-button v-if="can('transfer')" size="small" icon="el-icon-sort" @click="openTransfer">转交</el-button>
          <el-button v-if="can('edit')" size="small" icon="el-icon-edit" @click="$emit('edit', customer)">编辑客户</el-button>
        </span>
      </div>
      <b v-else>客户详情</b>
    </template>

    <div v-loading="loading" class="detail-body">
      <template v-if="customer">
        <el-tabs v-model="tab" class="detail-tabs">
          <el-tab-pane label="总览" name="overview" />
          <el-tab-pane label="联系人与身份" name="identity" />
          <el-tab-pane v-if="!$can || $can('crm.lead.view')" label="线索与商机" name="leads" />
          <el-tab-pane label="跟进记录" name="followups" />
          <el-tab-pane label="协作与日志" name="collab" />
        </el-tabs>

        <section v-if="tab === 'overview'" class="overview">
          <div class="box">
            <div class="box-title">客户资料</div>
            <div class="kv">
              <div class="full"><span>公司背景</span><span>{{ customer.company_background || '未填写' }}</span></div>
              <div><span>客户等级</span><span>{{ customer.customer_grade || '未填写' }}</span></div>
              <div><span>行业</span><span>{{ customer.industry || '未填写' }}</span></div>
              <div><span>年采购力</span><span>{{ customer.purchasing_power || '未填写' }}</span></div>
              <div><span>业务线</span><span>{{ (customer.business_lines || []).join('、') || '未填写' }}</span></div>
              <div><span>首次来源</span><span>{{ customer.first_source || '—' }}</span></div>
              <div><span>客户状态</span><span>{{ statusText(customer.status) }}</span></div>
              <div><span>负责销售</span><span>{{ customer.owner_name || '暂无' }}{{ customer.owner_department_name ? ' / ' + customer.owner_department_name : '' }}</span></div>
              <div><span>协作人员</span><span>{{ activeCollaborators || '—' }}</span></div>
              <div><span>初次联系</span><span>{{ date(customer.first_contact_at) }}</span></div>
              <div><span>最近联系</span><span>{{ date(customer.last_contact_at) }}</span></div>
              <div><span>下次计划</span><span>{{ date(customer.next_contact_at) }}</span></div>
              <div v-if="customer.customer_kind === 'enterprise'"><span>信用代码</span><span>{{ customer.credit_code || '未填写' }}</span></div>
              <div class="full"><span>备注</span><span>{{ customer.remark || '—' }}</span></div>
            </div>
          </div>
          <div class="box">
            <div class="box-title">联系人</div>
            <el-table :data="enabledContacts" size="small">
              <el-table-column prop="contact_name" label="姓名" min-width="90" />
              <el-table-column label="身份" min-width="80"><template slot-scope="{row}">{{ row.position || '—' }}</template></el-table-column>
              <el-table-column label="手机" min-width="120"><template slot-scope="{row}">{{ row.mobile || '—' }}</template></el-table-column>
            </el-table>
            <div class="box-title">默认收货地址</div>
            <p class="plain">{{ defaultAddress ? defaultAddress.full_address + ' · ' + (defaultAddress.receiver_name || '') : '尚未设置' }}</p>
          </div>
        </section>

        <section v-if="tab === 'identity'" class="stack">
          <div class="section-head"><b class="sec">联系人</b></div>
          <el-table :data="enabledContacts" size="small">
            <el-table-column prop="contact_name" label="姓名" min-width="90" />
            <el-table-column label="身份" min-width="90"><template slot-scope="{row}">{{ row.position || '—' }}</template></el-table-column>
            <el-table-column label="手机" min-width="120"><template slot-scope="{row}">{{ row.mobile || '—' }}</template></el-table-column>
            <el-table-column label="联系方式2" min-width="130"><template slot-scope="{row}">{{ row.phone || '—' }}</template></el-table-column>
            <el-table-column label="微信号" min-width="110"><template slot-scope="{row}">{{ row.wechat || '—' }}</template></el-table-column>
            <el-table-column label="邮箱" min-width="150"><template slot-scope="{row}">{{ row.email || '—' }}</template></el-table-column>
            <el-table-column label="默认" width="70"><template slot-scope="{row}"><el-tag v-if="row.is_default" size="mini" type="success">是</el-tag><span v-else>否</span></template></el-table-column>
          </el-table>
          <p class="hint">联系人在“编辑客户”中维护。</p>

          <div class="section-head">
            <b class="sec">平台身份</b>
            <span class="hint">同一平台身份只能关联一个客户；不同电商平台的身份经人工确认后可归同一客户</span>
            <el-button v-if="can('bind_identity')" size="mini" type="success" icon="el-icon-plus" class="push" @click="openIdentity()">绑定平台身份</el-button>
          </div>
          <el-table :data="customer.identities || []" size="small">
            <el-table-column label="成交平台" min-width="170"><template slot-scope="{row}">{{ row.platform_name || '未知平台' }}</template></el-table-column>
            <el-table-column label="识别方式" min-width="150"><template slot-scope="{row}">{{ scopeText(row) }}</template></el-table-column>
            <el-table-column prop="buyer_id" label="买家ID" min-width="140" />
            <el-table-column label="状态" width="90"><template slot-scope="{row}"><el-tag size="mini" :type="row.status === 'confirmed' ? 'success' : 'warning'">{{ row.status === 'confirmed' ? '已确认' : '待核实' }}</el-tag></template></el-table-column>
            <el-table-column label="绑定来源" min-width="200"><template slot-scope="{row}">{{ sourceText(row.source) }}{{ row.bound_by_name ? ' · ' + row.bound_by_name : '' }}{{ row.bound_at ? ' · ' + date(row.bound_at) : '' }}</template></el-table-column>
            <el-table-column label="操作" width="130">
              <template slot-scope="{row}">
                <el-button v-if="can('bind_identity') && row.status === 'pending'" type="text" size="mini" @click="openIdentity(row)">核实身份</el-button>
                <el-button v-if="can('bind_identity') && row.status === 'confirmed'" type="text" size="mini" class="danger-link" @click="disableIdentity(row)">停用</el-button>
              </template>
            </el-table-column>
            <template slot="empty"><span class="muted">暂无平台身份</span></template>
          </el-table>

          <div class="section-head"><b class="sec">收货与开票</b></div>
          <div class="kv">
            <div><span>默认收货地址</span><span>{{ defaultAddress ? defaultAddress.full_address : '尚未设置' }}</span></div>
            <div><span>开票抬头</span><span>{{ customer.invoice_title || customer.customer_name }}{{ customer.invoice_tax_no ? ' · 税号已登记' : '' }}</span></div>
          </div>
        </section>

        <section v-if="tab === 'leads'" v-loading="leads.loading" class="stack">
          <div class="section-head"><b class="sec">线索</b><span class="hint">客户池挂在每条线索上，客户层只汇总显示</span></div>
          <el-table :data="leads.rows" size="small">
            <el-table-column prop="lead_no" label="线索编号" min-width="155" />
            <el-table-column prop="demand_summary" label="需求" min-width="180" />
            <el-table-column label="部门 / 负责人" min-width="150"><template slot-scope="{row}">{{ row.department_name || '—' }} / {{ row.owner_name || '待分配' }}</template></el-table-column>
            <el-table-column label="来源渠道 / 录入方式" min-width="160"><template slot-scope="{row}">{{ row.source_channel }}<div class="hint">{{ row.entry_method === 'self' ? '销售自录' : '运营代录' }}</div></template></el-table-column>
            <el-table-column label="状态" min-width="90"><template slot-scope="{row}"><el-tag size="mini" type="success">{{ row.status_label }}</el-tag></template></el-table-column>
            <el-table-column label="客户池" min-width="140"><template slot-scope="{row}">{{ row.customer_pool || '未设置' }}</template></el-table-column>
            <el-table-column label="创建时间" min-width="110"><template slot-scope="{row}">{{ date(row.created_at) }}</template></el-table-column>
            <el-table-column label="操作" width="70"><template slot-scope="{row}"><el-button type="text" size="mini" @click="$refs.leadDetail.open(row.id)">查看</el-button></template></el-table-column>
          </el-table>
          <el-pagination background layout="total, prev, pager, next" :total="leads.total" :page-size="10" :current-page.sync="leads.page" @current-change="loadLeads" />
        </section>

        <section v-if="tab === 'followups'" v-loading="followups.loading" class="stack">
          <div class="section-head"><b class="sec">跟进记录</b><el-button v-if="can('follow') && (!$can || $can('crm.lead.follow'))" size="mini" type="success" icon="el-icon-plus" class="push" @click="openFollowup()">记录跟进</el-button></div>
          <article v-for="row in followups.rows" :key="row.id" class="box followup-record">
            <div class="section-head"><b>{{ row.operator_name || row.created_by_name }} · {{ row.method }}</b><span class="muted">{{ dateTime(row.contacted_at) }}</span><el-tag v-if="row.subject_type === 'lead'" size="mini">线索 {{ row.lead_no || row.subject_id }}</el-tag><el-button v-if="row.can_edit" class="push" type="text" size="mini" @click="openFollowup(row)">修改</el-button></div>
            <p class="plain">{{ row.content }}</p>
            <div class="hint">对接人：{{ row.contact_name }} · 沟通结果：{{ row.result }}</div>
            <div class="hint">下次联系：{{ dateTime(row.next_contact_at) }} · {{ row.next_plan }}</div>
            <crm-attachments v-if="row.attachments && row.attachments.length" :value="row.attachments" readonly />
            <div v-if="row.version > 1" class="hint">已修改 {{ row.version - 1 }} 次<span v-if="row.updated_at"> · {{ dateTime(row.updated_at) }}</span></div>
          </article>
          <el-empty v-if="!followups.loading && !followups.rows.length" description="暂无跟进记录" :image-size="70" />
          <el-pagination background layout="total, prev, pager, next" :total="followups.total" :page-size="10" :current-page.sync="followups.page" @current-change="loadFollowups" />
        </section>

        <section v-if="tab === 'collab'" class="stack">
          <div class="section-head">
            <b class="sec">协作人员</b>
            <el-button v-if="can('collaborate')" size="mini" type="success" icon="el-icon-plus" class="push" @click="openCollab">添加协作</el-button>
          </div>
          <el-table :data="collaborators" size="small">
            <el-table-column prop="user_name" label="协作人员" min-width="90" />
            <el-table-column label="协作范围" min-width="180"><template slot-scope="{row}">{{ scopeSummary(row) }}</template></el-table-column>
            <el-table-column label="操作权限" min-width="140"><template slot-scope="{row}">{{ actionSummary(row) }}</template></el-table-column>
            <el-table-column label="有效期" min-width="180"><template slot-scope="{row}">{{ row.effective_from }} 至 {{ row.effective_to || '长期' }}</template></el-table-column>
            <el-table-column prop="reason" label="协作原因" min-width="180" />
            <el-table-column label="状态" width="80"><template slot-scope="{row}"><el-tag size="mini" :type="row.status === 'active' ? 'success' : 'info'">{{ row.status === 'active' ? '生效中' : '已结束' }}</el-tag></template></el-table-column>
            <el-table-column label="操作" width="90">
              <template slot-scope="{row}"><el-button v-if="can('collaborate') && row.status === 'active'" type="text" size="mini" class="danger-link" @click="endCollab(row)">结束协作</el-button></template>
            </el-table-column>
            <template slot="empty"><span class="muted">暂无协作人员</span></template>
          </el-table>

          <div class="section-head"><b class="sec">客户日志</b></div>
          <div class="timeline">
            <div v-for="log in logs.rows" :key="log.id" class="tl">
              <i />
              <span class="muted">{{ dateTime(log.created_at) }}</span>
              <span>{{ log.operator_name || '系统' }} {{ log.summary }}<em v-if="log.reason" class="muted">（{{ log.reason }}）</em></span>
            </div>
            <span v-if="!logs.rows.length" class="muted">暂无日志</span>
          </div>
          <el-pagination v-if="logs.total > 10" small background layout="total, prev, pager, next" :total="logs.total" :page-size="10" :current-page.sync="logs.page" @current-change="loadLogs" />
        </section>
      </template>
    </div>
    <span slot="footer"><el-button v-if="can('follow') && (!$can || $can('crm.lead.follow'))" size="small" type="success" @click="openFollowup()">记录跟进</el-button><el-button size="small" @click="visible = false">关闭</el-button></span>
    <crm-followup-dialog v-if="visible && customer" :key="'follow-' + openSession" ref="followup" @saved="followupSaved" />
    <crm-lead-detail-dialog v-if="visible && customer" :key="'lead-' + openSession" ref="leadDetail" @changed="refresh" />
    <crm-identity-review-dialog v-if="visible && customer" :key="'review-' + openSession" ref="identityReview" />

    <el-dialog title="转交负责销售" :visible.sync="transfer.visible" width="620px" append-to-body>
      <div class="kv single">
        <div><span>客户</span><span>{{ customer && customer.customer_name }}</span></div>
        <div><span>当前负责销售</span><span>{{ (customer && customer.owner_name) || '暂无' }}</span></div>
      </div>
      <el-form label-width="96px" size="small" class="dialog-form">
        <el-form-item label="新负责销售" required>
          <el-select v-model="transfer.to" filterable remote :remote-method="searchStaff" :loading="staff.loading" placeholder="输入姓名搜索在职销售" style="width:100%">
            <el-option v-for="item in staff.rows" :key="item.id" :label="item.name + (item.department_name ? ' / ' + item.department_name : '')" :value="item.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="转交原因" required>
          <el-input v-model.trim="transfer.reason" type="textarea" :rows="2" maxlength="500" placeholder="如：原负责销售调岗" />
        </el-form-item>
      </el-form>
      <p class="hint">转交只改变客户负责销售；已产生订单的销售归属和历史成交不变。</p>
      <span slot="footer">
        <el-button size="small" @click="transfer.visible = false">取消</el-button>
        <el-button size="small" type="success" :disabled="!transfer.to || !transfer.reason" :loading="submitting" @click="submitTransfer">确认转交</el-button>
      </span>
    </el-dialog>

    <el-dialog title="添加客户协作" :visible.sync="collab.visible" width="760px" append-to-body>
      <el-form label-width="96px" size="small" class="dialog-form">
        <el-form-item label="客户"><el-input :value="customer && customer.customer_name" disabled /></el-form-item>
        <el-form-item label="协作人员" required>
          <el-select v-model="collab.user" filterable remote :remote-method="searchStaff" :loading="staff.loading" placeholder="输入姓名搜索在职销售" style="width:100%">
            <el-option v-for="item in staff.rows" :key="item.id" :label="item.name + (item.department_name ? ' / ' + item.department_name : '')" :value="item.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="协作范围">
          <el-checkbox :value="true" disabled>客户基础信息</el-checkbox>
          <el-checkbox v-model="collab.order_amount">订单金额</el-checkbox>
          <el-checkbox v-model="collab.finance">财务收款</el-checkbox>
          <div class="hint">订单金额、财务收款需协作人员本身有金额查看权限才可共享；指定商机在商机功能上线后可选。</div>
        </el-form-item>
        <el-form-item label="操作权限">
          <el-checkbox :value="true" disabled>查看</el-checkbox>
          <el-checkbox v-model="collab.follow">记录跟进</el-checkbox>
          <el-checkbox v-model="collab.edit_master">修改客户主资料</el-checkbox>
        </el-form-item>
        <el-form-item label="有效期" required>
          <el-date-picker v-model="collab.from" type="date" value-format="yyyy-MM-dd" placeholder="开始日期" />
          <span class="range-sep">至</span>
          <el-date-picker v-model="collab.to" type="date" value-format="yyyy-MM-dd" placeholder="长期" />
        </el-form-item>
        <el-form-item label="协作原因" required>
          <el-input v-model.trim="collab.reason" type="textarea" :rows="2" maxlength="500" />
        </el-form-item>
      </el-form>
      <p class="hint">协作不改变客户负责销售和历史订单归属。</p>
      <span slot="footer">
        <el-button size="small" @click="collab.visible = false">取消</el-button>
        <el-button size="small" type="success" :disabled="!collab.user || !collab.from || !collab.reason" :loading="submitting" @click="submitCollab">确认协作</el-button>
      </span>
    </el-dialog>

    <el-dialog :title="identity.row ? '核实平台身份' : '绑定平台身份'" :visible.sync="identity.visible" width="620px" append-to-body>
      <div v-if="identity.row" class="kv single"><div><span>旧来源原值</span><span>{{ identity.row.raw_value || identity.row.buyer_id }}</span></div></div>
      <el-form label-width="96px" size="small" class="dialog-form">
        <el-form-item label="成交平台" required>
          <el-select v-model="identity.platform" filterable placeholder="选择店铺（主平台）" style="width:100%">
            <el-option v-for="item in platforms" :key="item.id" :label="item.name" :value="item.id" :disabled="!item.buyer_identity_scope">
              <span>{{ item.name }}</span><span class="opt-sub">{{ item.buyer_identity_scope === 'platform' ? '按电商平台：' + item.buyer_platform_group : item.buyer_identity_scope === 'shop' ? '按本店' : '未设置识别方式' }}</span>
            </el-option>
          </el-select>
          <div class="hint">未设置买家ID识别方式的平台不能绑定，需先在成交平台档案中设置。</div>
        </el-form-item>
        <el-form-item label="平台买家ID" required>
          <el-input v-model.trim="identity.buyer" maxlength="160" placeholder="昵称、订单号、店铺ID不能代替买家ID" />
        </el-form-item>
        <el-form-item :label="identity.row ? '核实说明' : '备注'" :required="!!identity.row">
          <el-input v-model.trim="identity.note" type="textarea" :rows="2" maxlength="500" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button size="small" @click="identity.visible = false">取消</el-button>
        <el-button size="small" type="success" :disabled="!identity.platform || !identity.buyer || (identity.row && !identity.note)" :loading="submitting" @click="submitIdentity">确认</el-button>
      </span>
    </el-dialog>
  </el-dialog>
</template>

<script>
import { erpDateTime } from '@/utils/dateTime'
import { listCrmLeads, listCrmFollowups } from '@/api/erp/crm'
import CrmFollowupDialog from '@/components/crm/CrmFollowupDialog.vue'
import CrmLeadDetailDialog from '@/components/crm/CrmLeadDetailDialog.vue'
import CrmAttachments from '@/components/crm/CrmAttachments.vue'
import CrmIdentityReviewDialog from '@/components/crm/CrmIdentityReviewDialog.vue'
import {
  getSalesCustomer, listCustomerLogs, listCustomerCollaborators, addCustomerCollaborator, endCustomerCollaborator,
  transferCustomer, listCrmSalesStaff, listCrmCustomerPlatforms, bindCustomerIdentity, verifyCustomerIdentity, disableCustomerIdentity
} from '@/api/erp/sales'

const today = () => new Date().toISOString().slice(0, 10)

export default {
  name: 'CustomerDetailDialog',
  components: { CrmFollowupDialog, CrmLeadDetailDialog, CrmAttachments, CrmIdentityReviewDialog },
  data: () => ({
    visible: false,
    loading: false,
    submitting: false,
    openSession: 0,
    customerId: null,
    customer: null,
    tab: 'overview',
    collaborators: [],
    logs: { rows: [], total: 0, page: 1 },
    leads: { rows: [], total: 0, page: 1, loading: false },
    followups: { rows: [], total: 0, page: 1, loading: false },
    platforms: [],
    staff: { rows: [], loading: false },
    transfer: { visible: false, to: null, reason: '' },
    collab: { visible: false, user: null, order_amount: false, finance: false, follow: true, edit_master: false, from: '', to: '', reason: '' },
    identity: { visible: false, row: null, platform: null, buyer: '', note: '' }
  }),
  computed: {
    enabledContacts() { return ((this.customer && this.customer.contacts) || []).filter(item => item.status !== 'disabled') },
    defaultAddress() {
      const rows = ((this.customer && this.customer.addresses) || []).filter(item => item.status !== 'disabled')
      return rows.find(item => item.is_default) || rows[0] || null
    },
    activeCollaborators() {
      return this.collaborators.filter(item => item.status === 'active').map(item => item.user_name).join('、')
    }
  },
  watch: {
    visible(value) {
      if (!value) {
        this.openSession += 1
        this.reset()
      }
    },
    tab(value) {
      if (value === 'collab') this.loadCollab()
      if (value === 'leads') this.loadLeads()
      if (value === 'followups') this.loadFollowups()
    }
  },
  methods: {
    async open(id, tab = 'overview') {
      this.openSession += 1
      const session = this.openSession
      this.clearCustomer()
      this.customerId = id
      this.visible = true
      this.tab = tab
      await this.load(id)
      if (this.isCurrent(session, id) && tab === 'collab') this.loadCollab()
      if (this.isCurrent(session, id) && tab === 'leads') this.loadLeads()
      if (this.isCurrent(session, id) && tab === 'followups') this.loadFollowups()
    },
    reset() {
      if (this.visible) return
      this.clearCustomer()
    },
    clearCustomer() {
      this.customerId = null
      this.customer = null
      this.collaborators = []
      this.logs = { rows: [], total: 0, page: 1 }
      this.leads = { rows: [], total: 0, page: 1, loading: false }
      this.followups = { rows: [], total: 0, page: 1, loading: false }
      this.transfer.visible = false
      this.collab.visible = false
      this.identity.visible = false
      this.staff = { rows: [], loading: false }
      this.loading = false
      this.submitting = false
    },
    isCurrent(session, id) {
      return this.visible && session === this.openSession && id === this.customerId
    },
    async load(id) {
      id = id || this.customerId
      if (!this.visible || !id) return
      if (id !== this.customerId) return this.open(id, this.tab)
      const session = this.openSession
      this.loading = true
      try {
        const { data } = await getSalesCustomer(id)
        if (!this.isCurrent(session, id)) return
        this.customer = data
        this.collaborators = data.collaborators || []
      } catch (error) {
        if (!this.isCurrent(session, id)) return
        this.$message.error(this.errorText(error, '客户加载失败'))
        this.visible = false
      } finally {
        if (this.isCurrent(session, id)) this.loading = false
      }
    },
    async refresh() {
      const session = this.openSession
      const id = this.customerId
      await this.load()
      if (!this.isCurrent(session, id)) return
      if (this.tab === 'collab') await this.loadCollab()
      if (this.tab === 'leads') await this.loadLeads()
      if (this.tab === 'followups') await this.loadFollowups()
      if (this.isCurrent(session, id)) this.$emit('changed', this.customer)
    },
    async loadLeads() {
      if (!this.customer) return
      const session = this.openSession
      const id = this.customerId
      const page = this.leads.page
      this.leads.loading = true
      try {
        const { data } = await listCrmLeads({ customer_id: id, page, per_page: 10 })
        if (this.isCurrent(session, id) && page === this.leads.page) this.leads = { rows: data.data || [], total: data.total || 0, page, loading: false }
      } catch (error) {
        if (this.isCurrent(session, id)) this.$message.error(this.errorText(error, '线索加载失败'))
      } finally {
        if (this.isCurrent(session, id) && page === this.leads.page) this.leads.loading = false
      }
    },
    async loadFollowups() {
      if (!this.customer) return
      const session = this.openSession
      const id = this.customerId
      const page = this.followups.page
      this.followups.loading = true
      try {
        const { data } = await listCrmFollowups({ subject_type: 'customer', subject_id: id, page, per_page: 10 })
        if (this.isCurrent(session, id) && page === this.followups.page) this.followups = { rows: data.data || [], total: data.total || 0, page, loading: false }
      } catch (error) {
        if (this.isCurrent(session, id)) this.$message.error(this.errorText(error, '跟进记录加载失败'))
      } finally {
        if (this.isCurrent(session, id) && page === this.followups.page) this.followups.loading = false
      }
    },
    openFollowup(row) {
      if (!this.customer || !this.$refs.followup) return
      this.$refs.followup.open({ subject_type: row ? row.subject_type : 'customer', subject_id: row ? row.subject_id : this.customerId,
        customer_id: this.customerId, customer_name: this.customer.customer_name, contacts: this.enabledContacts }, row)
    },
    async followupSaved(row) {
      if (!this.visible || !row || Number(row.customer_id) !== Number(this.customerId)) return
      this.followups.page = 1
      this.tab = 'followups'
      await this.refresh()
    },
    async loadCollab() {
      if (!this.customer) return
      const session = this.openSession
      const id = this.customerId
      try {
        const { data } = await listCustomerCollaborators(id)
        if (!this.isCurrent(session, id)) return
        this.collaborators = data.data || []
        await this.loadLogs()
      } catch (error) {
        if (this.isCurrent(session, id)) this.$message.error(this.errorText(error, '协作加载失败'))
      }
    },
    async loadLogs() {
      if (!this.customer) return
      const session = this.openSession
      const id = this.customerId
      const page = this.logs.page
      try {
        const { data } = await listCustomerLogs(id, { page, per_page: 10 })
        if (!this.isCurrent(session, id) || page !== this.logs.page) return
        this.logs = { rows: data.data || [], total: data.total || 0, page: data.current_page || 1 }
      } catch (error) {
        if (this.isCurrent(session, id)) this.$message.error(this.errorText(error, '日志加载失败'))
      }
    },
    can(ability) { return !!(this.customer && this.customer.abilities && this.customer.abilities[ability]) },
    statusText(status) { return ({ enabled: '启用', potential: '潜在', disabled: '停用', blacklisted: '黑名单' })[status] || '—' },
    date(value) { return value ? String(value).slice(0, 10) : '—' },
    dateTime(value) { return value ? erpDateTime(value, 16) : '—' },
    scopeText(row) {
      if (row.status === 'pending') return '待核实'
      return row.scope === 'platform' ? `按电商平台：${row.platform_group || '—'}` : row.scope === 'shop' ? '按本店' : '—'
    },
    sourceText(source) { return ({ manual: '人工绑定', order: '建单登记', legacy: '旧ERP迁入', confirm: '人工确认' })[source] || source || '—' },
    scopeSummary(row) {
      const scope = row.scope || {}
      return ['客户基础信息', scope.order_amount ? '订单金额' : '', scope.finance ? '财务收款' : ''].filter(Boolean).join('、')
    },
    actionSummary(row) {
      const actions = row.actions || {}
      return ['查看', actions.follow ? '记录跟进' : '', actions.edit_master ? '修改客户主资料' : ''].filter(Boolean).join('、')
    },
    errorText(error, fallback) {
      const body = (error.response && error.response.data) || {}
      return body.errors ? Object.values(body.errors)[0][0] : (body.message || fallback)
    },
    async searchStaff(keyword) {
      const session = this.openSession
      const id = this.customerId
      this.staff.loading = true
      try {
        const { data } = await listCrmSalesStaff({ keyword, per_page: 20 })
        if (!this.isCurrent(session, id)) return
        this.staff.rows = data.data || []
      } catch (error) {
        if (this.isCurrent(session, id)) this.$message.error(this.errorText(error, '销售人员加载失败'))
      } finally {
        if (this.isCurrent(session, id)) this.staff.loading = false
      }
    },
    openTransfer() {
      this.transfer = { visible: true, to: null, reason: '' }
      this.searchStaff('')
    },
    async submitTransfer() {
      const id = this.customerId
      await this.submit(() => transferCustomer(id, { to_user_legacy_id: this.transfer.to, reason: this.transfer.reason, crm_version: this.customer.crm_version }), () => { this.transfer.visible = false })
    },
    openCollab() {
      this.collab = { visible: true, user: null, order_amount: false, finance: false, follow: true, edit_master: false, from: today(), to: '', reason: '' }
      this.searchStaff('')
    },
    async submitCollab() {
      const c = this.collab
      const id = this.customerId
      await this.submit(() => addCustomerCollaborator(id, {
        user_legacy_id: c.user, scope: { order_amount: c.order_amount, finance: c.finance }, actions: { follow: c.follow, edit_master: c.edit_master },
        effective_from: c.from, effective_to: c.to || null, reason: c.reason
      }), () => { this.collab.visible = false })
    },
    async endCollab(row) {
      const session = this.openSession
      const id = this.customerId
      try {
        const { value } = await this.$prompt(`结束 ${row.user_name} 的协作，请填写原因`, '结束协作', { inputValidator: v => !!(v && v.trim()) || '请填写结束原因', confirmButtonText: '确认结束', cancelButtonText: '取消' })
        if (!this.isCurrent(session, id)) return
        await this.submit(() => endCustomerCollaborator(id, row.id, { reason: value.trim() }))
      } catch (e) { /* 取消 */ }
    },
    async openIdentity(row = null) {
      const session = this.openSession
      const id = this.customerId
      this.identity = { visible: true, row, platform: row ? row.platform_legacy_id : null, buyer: row ? (row.buyer_id || '') : '', note: '' }
      if (!this.platforms.length) {
        try {
          const { data } = await listCrmCustomerPlatforms()
          if (this.isCurrent(session, id)) this.platforms = data.data || []
        } catch (error) {
          if (this.isCurrent(session, id)) this.$message.error(this.errorText(error, '成交平台加载失败'))
        }
      }
    },
    async submitIdentity() {
      const i = this.identity
      const id = this.customerId
      const payload = { platform_legacy_id: i.platform, buyer_id: i.buyer, note: i.note || null }
      await this.submit(() => (i.row ? verifyCustomerIdentity(id, i.row.id, payload) : bindCustomerIdentity(id, payload)), () => { this.identity.visible = false })
    },
    async disableIdentity(row) {
      const session = this.openSession
      const id = this.customerId
      try {
        const { value } = await this.$prompt(`停用 ${row.platform_name} 买家ID ${row.buyer_id}，请填写原因`, '停用平台身份', { inputValidator: v => !!(v && v.trim()) || '请填写停用原因', confirmButtonText: '确认停用', cancelButtonText: '取消' })
        if (!this.isCurrent(session, id)) return
        await this.submit(() => disableCustomerIdentity(id, row.id, { reason: value.trim() }))
      } catch (e) { /* 取消 */ }
    },
    async submit(request, done) {
      if (!this.customer || !this.visible || this.loading || this.submitting) return
      const session = this.openSession
      const id = this.customerId
      this.submitting = true
      try {
        const { data } = await request()
        if (!this.isCurrent(session, id)) return
        this.$message.success(data.message || '已保存')
        if (done) done()
        await this.refresh()
      } catch (error) {
        if (!this.isCurrent(session, id)) return
        if (error.response && error.response.data && error.response.data.crm_identity_conflict && this.identity.visible) {
          this.$refs.identityReview.openRequest({ purpose: 'duplicate', subject_type: 'customer', subject_id: id,
            input_snapshot: { customer_name: this.customer.customer_name, customer_kind: this.customer.customer_kind,
              platform_legacy_id: this.identity.platform, platform_buyer_id: this.identity.buyer,
              phones: this.enabledContacts.flatMap(item => [item.mobile, item.phone]).filter(Boolean) } })
          return
        }
        this.$message.error(this.errorText(error, '操作失败'))
      } finally {
        if (this.isCurrent(session, id)) this.submitting = false
      }
    }
  }
}
</script>

<style scoped>
.detail-title { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; padding-right: 30px; min-width: 0; }
.detail-title b { font-size: 18px; color: #1e293b; }
.title-actions { margin-left: auto; display: flex; gap: 8px; }
.muted { color: #94a3b8; }
.hint { color: #64748b; font-size: 12px; margin: 4px 0 0; }
.detail-body { min-height: 360px; max-height: 68vh; overflow-y: auto; box-sizing: border-box; }
.overview { display: grid; grid-template-columns: minmax(0, 1.4fr) minmax(0, 1fr); gap: 14px; }
.box { border: 1px solid #e2e8f0; border-radius: 8px; padding: 12px 14px; display: flex; flex-direction: column; gap: 10px; min-width: 0; }
.box-title { font-weight: 700; color: #1e293b; }
.plain { margin: 0; color: #334155; }
.kv { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); border: 1px solid #e2e8f0; border-radius: 6px; overflow: hidden; }
.kv.single { grid-template-columns: minmax(0, 1fr); margin-bottom: 12px; }
.kv > div { display: grid; grid-template-columns: 110px minmax(0, 1fr); border-bottom: 1px solid #eef2f7; }
.kv > div.full { grid-column: 1 / -1; }
.kv > div > span { padding: 8px 12px; line-height: 1.55; word-break: break-word; }
.kv > div > span:first-child { background: #f8fafc; color: #64748b; }
.kv > div > span:last-child { color: #1e293b; font-weight: 500; }
.stack { display: flex; flex-direction: column; gap: 10px; }
.section-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-top: 6px; }
.sec { padding-left: 9px; border-left: 3px solid #008b4b; line-height: 16px; color: #1e293b; }
.push { margin-left: auto; }
.timeline { display: flex; flex-direction: column; gap: 8px; }
.tl { display: grid; grid-template-columns: 12px 130px minmax(0, 1fr); gap: 8px; align-items: start; }
.tl i { width: 9px; height: 9px; border-radius: 50%; border: 2px solid #008b4b; margin-top: 5px; }
.tl em { font-style: normal; }
.danger-link { color: #ef4444 !important; }
.dialog-form { margin-top: 8px; }
.range-sep { margin: 0 8px; color: #64748b; }
.opt-sub { float: right; color: #94a3b8; font-size: 12px; margin-left: 12px; }
@media (max-width: 1180px) {
  .overview { grid-template-columns: minmax(0, 1fr); }
}
</style>

<style>
@media (max-width: 1220px) {
  .crm-customer-detail { width: 95% !important; }
}
</style>
