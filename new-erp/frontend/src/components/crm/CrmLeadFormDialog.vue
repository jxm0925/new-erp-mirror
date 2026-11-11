<template>
  <el-dialog class="crm-dialog" :visible.sync="visible" width="1320px" append-to-body :close-on-click-modal="false" :before-close="close">
    <div slot="title" class="form-dlg-head">
      <b>{{ form.id ? '编辑线索' : '新增线索' }}</b>
      <span v-if="currentUser.name" class="form-head-sub">
        录入人：{{ currentUser.name }}{{ currentUser.department_name ? ' / ' + currentUser.department_name : '' }}
      </span>
      <el-tag size="mini" :type="isSales ? 'success' : 'info'">{{ isSales ? '销售自录' : '运营代录' }}</el-tag>
      <el-tag v-if="identityLocked" size="mini" type="warning">已正式关联客户，身份与归属字段已锁定</el-tag>
    </div>

    <div v-loading="loading">
      <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" show-icon />
      <el-form :model="form" size="small" label-width="88px" :disabled="loading || saving || !!loadError">
        <div class="lead-columns">
          <section class="crm-box form-col-box">
            <h3 class="crm-section-title">客户与联系</h3>
            <el-form-item label="客户类型" required>
              <el-radio-group v-model="form.customer_kind" :disabled="identityLocked">
                <el-radio label="individual">个人</el-radio>
                <el-radio label="enterprise">企业</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item :label="form.customer_kind === 'enterprise' ? '企业名称' : '客户姓名'" required>
              <div class="crm-inline name-field">
                <el-input v-model.trim="form.customer_name" :disabled="identityLocked" maxlength="160" placeholder="请输入客户名称" />
                <el-button v-if="!identityLocked && (!$can || $can('sales.customer'))" type="text" icon="el-icon-search" @click="$refs.picker.open()">选择已有客户</el-button>
              </div>
              <div v-if="form.customer_id" class="linked-cust-bar">
                <span><i class="el-icon-circle-check" /> 已关联已有客户</span>
                <el-button v-if="!identityLocked" type="text" size="mini" class="crm-danger-link" @click="form.customer_id = null">取消关联</el-button>
              </div>
            </el-form-item>
            <el-form-item v-if="form.customer_kind === 'enterprise'" label="信用代码">
              <el-input v-model.trim="form.credit_code" :disabled="identityLocked" maxlength="32" placeholder="统一社会信用代码（选填）" />
            </el-form-item>
            <el-form-item label="对接人">
              <el-input v-model.trim="form.contact_name" maxlength="80" placeholder="默认同客户姓名" />
            </el-form-item>
            <el-form-item label="对接人身份">
              <el-input v-model.trim="form.contact_role" maxlength="80" placeholder="如：老板 / 采购 / 项目经理" />
            </el-form-item>
            <el-form-item label="手机">
              <el-input v-model.trim="form.mobile" maxlength="80" placeholder="真实手机号码，录入不打码" />
            </el-form-item>
            <el-form-item label="联系方式2">
              <el-input v-model.trim="form.contact_value2" placeholder="备用电话 / 座机 / 其他" maxlength="80" />
            </el-form-item>
            <el-form-item label="微信号">
              <el-input v-model.trim="form.wechat" maxlength="80" placeholder="客户微信号（选填）" />
            </el-form-item>
            <el-form-item label="邮箱">
              <el-input v-model.trim="form.email" maxlength="160" placeholder="客户邮箱（选填）" />
            </el-form-item>
            <el-form-item label="地区">
              <customer-region-select v-model="form.region_id" :legacy-label="[form.province, form.city, form.district].filter(Boolean).join(' / ')" />
            </el-form-item>
            <el-form-item label="详细地址">
              <el-input v-model.trim="form.detail_address" maxlength="500" placeholder="街道、门牌或项目现场地址（选填）" />
            </el-form-item>
          </section>

          <section class="crm-box form-col-box">
            <h3 class="crm-section-title">线索业务</h3>
            <el-form-item label="贸易类型">
              <el-select popper-class="crm-select-popper" v-model="form.trade_type" clearable filterable allow-create default-first-option>
                <el-option v-for="item in metadata.trade_types || []" :key="item" :value="item" :label="item" />
              </el-select>
            </el-form-item>
            <el-form-item label="所属部门" required>
              <el-input v-if="isSales || identityLocked" :value="form.department_name || currentUser.department_name" disabled />
              <el-select popper-class="crm-select-popper" v-else v-model="form.department_legacy_id" filterable placeholder="选择线索归属部门">
                <el-option v-for="item in metadata.departments || []" :key="item.id" :value="item.id" :label="item.name" />
              </el-select>
            </el-form-item>
            <el-form-item label="负责人">
              <el-input :value="isSales ? `${currentUser.name || ''}（本人）` : form.owner_name || '待分配'" disabled />
              <small v-if="isSales" class="crm-sub">销售自录默认由本人直接负责跟进</small>
            </el-form-item>
            <el-form-item label="来源渠道" required>
              <el-select popper-class="crm-select-popper" v-model="form.source_channel" :disabled="identityLocked" filterable placeholder="请选择来源渠道" @change="sourceChanged">
                <el-option v-for="item in metadata.source_channels || []" :key="item" :value="item" :label="item" />
              </el-select>
            </el-form-item>
            <el-form-item label="录入方式">
              <el-input :value="isSales ? '销售自录' : '运营代录'" disabled />
            </el-form-item>
            <el-form-item label="线索类型">
              <el-select popper-class="crm-select-popper" v-model="form.lead_type" filterable allow-create default-first-option clearable placeholder="如：普通咨询">
                <el-option v-for="item in metadata.lead_types || []" :key="item" :value="item" :label="item" />
              </el-select>
            </el-form-item>
            <el-form-item label="客户标签">
              <el-select popper-class="crm-select-popper" v-model="form.customer_tags" multiple filterable allow-create default-first-option placeholder="可多选或输入新标签">
                <el-option v-for="item in metadata.customer_tags || []" :key="item" :value="item" :label="item" />
              </el-select>
            </el-form-item>
            <el-form-item label="自定义标签">
              <el-select popper-class="crm-select-popper" v-model="form.custom_tags" multiple filterable allow-create default-first-option placeholder="个人跟进标记，可输入回车创建">
                <el-option v-for="item in metadata.custom_tags || []" :key="item" :value="item" :label="item" />
              </el-select>
            </el-form-item>
            <el-form-item label="是否新咨询">
              <el-radio-group v-model="form.is_new_inquiry">
                <el-radio :label="true">是</el-radio>
                <el-radio :label="false">否</el-radio>
              </el-radio-group>
            </el-form-item>
            <el-form-item label="业务线">
              <el-select popper-class="crm-select-popper" v-model="form.business_lines" multiple filterable allow-create default-first-option placeholder="可多选业务线">
                <el-option v-for="item in metadata.business_lines || []" :key="item" :value="item" :label="item" />
              </el-select>
            </el-form-item>
            <el-form-item label="客户池">
              <el-select popper-class="crm-select-popper" v-model="form.customer_pool" clearable placeholder="选择线索所在客户池">
                <el-option v-for="item in metadata.customer_pools || []" :key="item" :value="item" :label="item" />
              </el-select>
            </el-form-item>
            <el-form-item label="预计成交额">
              <div class="lead-money">
                <el-select popper-class="crm-select-popper" v-model="form.currency">
                  <el-option v-for="item in ['CNY','USD','EUR','HKD']" :key="item" :value="item" :label="item" />
                </el-select>
                <el-input-number v-model="form.expected_amount" :min="0" :precision="2" :controls="false" placeholder="预估金额" />
              </div>
            </el-form-item>
          </section>

          <section class="crm-box form-col-box">
            <h3 class="crm-section-title">沟通与附件</h3>
            <div v-if="online" class="online-identity-card">
              <div class="online-title">
                <i class="el-icon-shopping-bag-1" />
                <b>线上平台客户身份</b>
                <el-tag :type="platform.buyer_identity_scope === 'unset' || !platform.buyer_identity_scope ? 'warning' : 'success'" size="mini">
                  {{ scopeLabel }}
                </el-tag>
              </div>
              <el-form-item label="接待店铺" required>
                <el-select popper-class="crm-select-popper" v-model="form.platform_legacy_id" filterable :disabled="identityLocked" placeholder="请选择接待店铺">
                  <el-option v-for="item in platforms" :key="item.id" :value="item.id" :label="item.name" />
                </el-select>
              </el-form-item>
              <el-form-item label="所属平台">
                <el-input :value="platform.buyer_platform_group || '—'" disabled />
              </el-form-item>
              <el-form-item label="平台买家ID" required>
                <el-input v-model.trim="form.platform_buyer_id" :disabled="identityLocked" maxlength="160" placeholder="必填，用于唯一客户身份识别" />
                <small class="crm-sub">请填写真实平台买家ID；昵称、订单号、店铺ID不能代替</small>
              </el-form-item>
              <div v-if="!platform.buyer_identity_scope || platform.buyer_identity_scope === 'unset'" class="crm-note warning">
                该平台未设置识别方式，暂时只能保存草稿。请联系成交平台档案维护人员设置后正式录入。
              </div>
            </div>

            <div class="stack-field">
              <div class="field-top-label">需求描述</div>
              <el-input v-model.trim="form.demand_summary" type="textarea" :rows="3" maxlength="2000" placeholder="简要概括客户咨询的产品、场景或项目需求" />
            </div>
            <div class="stack-field">
              <div class="field-top-label">沟通内容</div>
              <el-input v-model.trim="form.content" type="textarea" :rows="4" maxlength="20000" placeholder="详细记录客户咨询原话、技术参数、安装条件等沟通详情" />
            </div>
            <div class="stack-field">
              <div class="field-top-label">图片附件</div>
              <crm-attachments ref="attachments" v-model="attachments" images @busy="uploading = $event" />
            </div>
            <div class="stack-field">
              <div class="field-top-label">下次联系时间</div>
              <el-date-picker popper-class="crm-date-popper" v-model="form.next_contact_at" type="datetime" value-format="yyyy-MM-dd HH:mm:ss" placeholder="选择下次跟进时间" />
              <small class="crm-sub">保存后按该时间生成联系排期提醒</small>
            </div>
            <div class="stack-field">
              <div class="field-top-label">下次计划</div>
              <el-input v-model.trim="form.next_plan" type="textarea" :rows="2" maxlength="2000" placeholder="下次联系的具体行动计划（如发送报价、回访确认）" />
            </div>
          </section>
        </div>
      </el-form>
    </div>

    <div slot="footer" class="crm-footer">
      <span class="crm-muted">查重通过后正式录入；发现疑似客户时需先人工确认，不会自动覆盖已有客户主资料</span>
      <el-button size="small" :disabled="saving" @click="close">取消</el-button>
      <el-button v-if="!identityLocked" size="small" :loading="saving" :disabled="loading || uploading || !!loadError" @click="save('draft')">保存草稿</el-button>
      <el-button
        type="success"
        size="small"
        :loading="saving"
        :disabled="loading || uploading || !!loadError || (online && (!platform.buyer_identity_scope || platform.buyer_identity_scope === 'unset'))"
        @click="save('submit')"
      >{{ identityLocked ? '保存修改' : '查重并录入' }}</el-button>
    </div>

    <customer-picker ref="picker" @select="selectCustomer" />

    <el-dialog class="crm-dialog" :title="duplicates.reviewRequired ? '客户身份需要核实' : '查重结果'" :visible.sync="duplicates.visible" width="1000px" append-to-body :close-on-click-modal="false">
      <el-alert :title="duplicates.message" :type="duplicates.reviewRequired ? 'error' : 'warning'" :closable="false" show-icon />
      <div class="crm-note">
        本次录入：<b>{{ form.customer_name }}</b> · {{ form.mobile || '未填手机' }} · 来源：{{ form.source_channel || '—' }}
      </div>
      <el-table :data="duplicates.rows" size="small">
        <el-table-column label="疑似客户" min-width="180">
          <template slot-scope="{row}">
            <b>{{ row.visible === false ? '其他部门客户' : row.customer_name }}</b>
            <small class="crm-sub">{{ row.visible === false ? '详情无权查看' : row.customer_code }}</small>
          </template>
        </el-table-column>
        <el-table-column label="匹配依据" min-width="160">
          <template slot-scope="{row}">
            <el-tag v-for="label in row.reason_labels" :key="label" size="mini" type="warning" class="crm-tag">{{ label }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="所属部门 / 负责人" min-width="160">
          <template slot-scope="{row}">
            {{ row.owner_department_name || '—' }} / {{ row.visible === false ? '无权查看' : (row.owner_name || '—') }}
          </template>
        </el-table-column>
        <el-table-column label="最近联系" min-width="110">
          <template slot-scope="{row}">{{ row.last_contact_at || '—' }}</template>
        </el-table-column>
        <el-table-column label="操作" min-width="140">
          <template slot-scope="{row}">
            <el-button v-if="row.visible !== false && !duplicates.reviewRequired" size="mini" type="success" :loading="saving" @click="chooseDuplicate(row)">是这个客户</el-button>
            <el-button v-else type="text" @click="duplicates.reviewMode = true">申请核实</el-button>
          </template>
        </el-table-column>
      </el-table>
      <el-form size="small" label-width="90px" class="duplicate-form">
        <el-form-item v-if="duplicates.requiresConfirmation && !duplicates.reviewMode && !duplicates.reviewRequired" label="新建原因" required>
          <el-input v-model.trim="duplicates.reason" type="textarea" :rows="2" maxlength="500" placeholder="请说明为何上述疑似客户均不是本次咨询客户" />
        </el-form-item>
        <el-form-item v-if="duplicates.reviewMode || duplicates.reviewRequired" label="申请说明" required>
          <el-input v-model.trim="duplicates.note" type="textarea" :rows="3" maxlength="500" placeholder="请填写核实申请说明，由对应部门负责人核实归属" />
        </el-form-item>
      </el-form>
      <div slot="footer" class="crm-footer">
        <el-button size="small" :disabled="saving" @click="duplicates.visible = false">返回修改</el-button>
        <el-button size="small" :loading="saving" @click="requestReview">{{ duplicates.reviewMode || duplicates.reviewRequired ? '提交核实申请' : '暂存待核实' }}</el-button>
        <el-button v-if="duplicates.requiresConfirmation && !duplicates.reviewRequired && !duplicates.reviewMode" size="small" type="success" :loading="saving" :disabled="!duplicates.reason" @click="createDespiteDuplicates">都不是，新建客户</el-button>
      </div>
    </el-dialog>
  </el-dialog>
</template>

<script>
import { getCrmLeadMetadata, getCrmLead, saveCrmLead, crmError } from '@/api/erp/crm'
import { erpDateTime } from '@/utils/dateTime'
import CustomerRegionSelect from '@/components/sales/CustomerRegionSelect.vue'
import CustomerPicker from '@/components/sales/CustomerPicker.vue'
import CrmAttachments from './CrmAttachments.vue'
import '@/styles/crm.css'

const blank = () => ({
  id: null,
  version: null,
  status: 'draft',
  customer_id: null,
  customer_kind: 'individual',
  customer_name: '',
  contact_id: null,
  contact_name: '',
  contact_role: '',
  mobile: '',
  contact_value2: '',
  wechat: '',
  email: '',
  credit_code: '',
  region_id: null,
  province: '',
  city: '',
  district: '',
  detail_address: '',
  full_address: '',
  trade_type: '',
  department_legacy_id: null,
  source_channel: '',
  lead_type: '',
  customer_tags: [],
  custom_tags: [],
  is_new_inquiry: true,
  business_lines: [],
  customer_pool: '',
  expected_amount: null,
  currency: 'CNY',
  content: '',
  demand_summary: '',
  next_contact_at: '',
  next_plan: '',
  platform_legacy_id: null,
  platform_buyer_id: ''
})

export default {
  name: 'CrmLeadFormDialog',
  components: { CustomerRegionSelect, CustomerPicker, CrmAttachments },
  data: () => ({
    visible: false,
    loading: false,
    saving: false,
    uploading: false,
    session: 0,
    form: blank(),
    metadata: {},
    platforms: [],
    attachments: [],
    loadError: '',
    duplicates: { visible: false, rows: [], message: '', reviewRequired: false, requiresConfirmation: false, reviewMode: false, reason: '', note: '' }
  }),
  computed: {
    currentUser() { return this.metadata.current_user || {} },
    isSales() { return !!this.currentUser.is_sales },
    online() { return this.form.source_channel === '线上平台' || !!this.form.platform_legacy_id },
    platform() { return this.platforms.find(item => item.id === this.form.platform_legacy_id) || {} },
    scopeLabel() { return ({ platform: '按平台识别', shop: '按店铺识别' })[this.platform.buyer_identity_scope] || '未设置识别方式' },
    identityLocked() { return !!this.form.id && this.form.status !== 'draft' }
  },
  beforeDestroy() { this.session += 1 },
  methods: {
    async open(id) {
      const session = ++this.session
      this.form = blank()
      this.attachments = []
      this.visible = true
      this.loading = true
      this.saving = false
      this.uploading = false
      this.loadError = ''
      this.duplicates.visible = false
      this.$nextTick(() => { if (this.$refs.attachments) this.$refs.attachments.reset() })
      try {
        const [meta, lead] = await Promise.all([getCrmLeadMetadata(), id ? getCrmLead(id) : Promise.resolve(null)])
        if (session !== this.session) return
        this.metadata = meta.data.data || meta.data
        this.platforms = this.metadata.platforms || []
        if (lead) {
          this.form = { ...blank(), ...(lead.data.data || lead.data) }
          this.form.next_contact_at = erpDateTime(this.form.next_contact_at)
          this.attachments = [...(this.form.attachments || [])]
        } else {
          this.form.department_legacy_id = this.currentUser.department_legacy_id
          this.form.customer_pool = (this.metadata.customer_pools || []).find(item => item === '新线索快转池') || ''
          this.form.trade_type = (this.metadata.trade_types || [])[0] || ''
        }
      } catch (error) {
        if (session === this.session) this.loadError = crmError(error, '线索资料加载失败')
      } finally {
        if (session === this.session) this.loading = false
      }
    },
    sourceChanged() {
      if (this.form.source_channel !== '线上平台') {
        this.form.platform_legacy_id = null
        this.form.platform_buyer_id = ''
      }
    },
    close() {
      if (this.saving) return
      this.session += 1
      this.visible = false
      this.duplicates.visible = false
      if (this.$refs.attachments) this.$refs.attachments.reset()
    },
    selectCustomer(row) {
      this.form.customer_id = row.id
      this.form.customer_kind = row.customer_kind
      this.form.customer_name = row.customer_name
      this.form.contact_name = row.contact_name || ''
      this.form.mobile = row.contact_phone || ''
      this.form.contact_id = null
    },
    async chooseDuplicate(row) {
      const session = this.session
      let confirmation = {}
      if (this.duplicates.rows.some(item => item.id !== row.id) && this.duplicates.requiresConfirmation) {
        try {
          const result = await this.$prompt('请说明其余疑似客户为何不是本次咨询客户', '确认客户关联', {
            inputType: 'textarea',
            inputValidator: value => !!value && !!value.trim() && value.length <= 500,
            inputErrorMessage: '请填写 500 字以内的确认说明',
            confirmButtonText: '确认关联',
            cancelButtonText: '返回'
          })
          confirmation = { duplicate_confirmed: true, duplicate_reason: result.value.trim() }
        } catch (error) {
          return
        }
      }
      if (session !== this.session) return
      this.form.customer_id = row.id
      return this.save('submit', confirmation)
    },
    createDespiteDuplicates() {
      return this.save('submit', { duplicate_confirmed: true, duplicate_reason: this.duplicates.reason })
    },
    requestReview() {
      if (!this.duplicates.reviewMode && !this.duplicates.reviewRequired) {
        this.duplicates.reviewMode = true
        return
      }
      if (!this.duplicates.note) return this.$message.warning('请填写申请说明')
      return this.save('review', { review_note: this.duplicates.note })
    },
    async save(mode, extra = {}) {
      if (this.saving || this.loading || this.uploading || this.loadError) return
      if (mode !== 'draft' && (!this.form.customer_name || !this.form.department_legacy_id || !this.form.source_channel)) {
        return this.$message.warning('请填写客户姓名、所属部门和来源渠道')
      }
      if (mode === 'submit' && this.online && !this.form.platform_buyer_id) {
        return this.$message.warning('请填写平台买家ID，可先保存草稿')
      }
      const session = this.session
      this.saving = true
      try {
        const { data } = await saveCrmLead({ ...this.form, ...extra, mode, attachment_ids: this.attachments.map(file => file.id) })
        if (session !== this.session) return
        this.visible = false
        this.duplicates.visible = false
        this.$message.success(mode === 'draft' ? '草稿已保存' : mode === 'review' ? '核实申请已提交' : '线索已保存')
        this.$emit('saved', data.data || data)
      } catch (error) {
        if (session !== this.session) return
        const data = error.response && error.response.data
        if (data && data.duplicates) {
          this.duplicates = {
            visible: true,
            rows: data.duplicates,
            message: data.message,
            reviewRequired: !!data.review_required,
            requiresConfirmation: !!data.requires_confirmation,
            reviewMode: false,
            reason: '',
            note: ''
          }
        } else {
          this.$message.error(crmError(error, '线索保存失败'))
        }
      } finally {
        if (session === this.session) this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.form-dlg-head {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.form-dlg-head b {
  font-size: 16px;
  color: #1e293b;
}
.form-head-sub {
  font-size: 12px;
  color: #64748b;
}
.lead-columns {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.05fr) minmax(0, 0.95fr);
  gap: 14px;
}
.form-col-box {
  background: #ffffff;
}
.name-field {
  flex-wrap: nowrap;
  gap: 8px;
}
.name-field .el-input {
  flex: 1;
  min-width: 0;
}
.linked-cust-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 4px;
  padding: 2px 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  font-size: 12px;
  color: #00763f;
}
.lead-money {
  display: flex;
  gap: 8px;
}
.lead-money .el-select {
  width: 95px;
  flex: none;
}
.lead-money .el-input-number {
  flex: 1;
  min-width: 0;
}
.online-identity-card {
  padding: 12px;
  margin-bottom: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}
.online-title {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 10px;
  font-size: 13px;
  color: #1e293b;
}
.online-title i {
  color: #008b4b;
}
.stack-field {
  margin-bottom: 12px;
}
.stack-field:last-child {
  margin-bottom: 0;
}
.field-top-label {
  font-size: 13px;
  font-weight: 500;
  color: #475569;
  margin-bottom: 6px;
}
.duplicate-form {
  margin-top: 18px;
}
@media (max-width: 1180px) {
  .lead-columns {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
