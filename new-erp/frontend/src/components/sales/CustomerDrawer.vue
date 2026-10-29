<template>
  <el-drawer
    :visible.sync="innerVisible"
    :with-header="false"
    size="650px"
    append-to-body
    custom-class="customer-form-drawer"
    @closed="handleClosed"
  >
    <div class="customer-form-shell">
      <header class="drawer-header">
        <div>
          <small class="drawer-crumb">销售管理 / 客户档案</small>
          <h2>{{ form.id ? '编辑客户' : '新增客户' }}</h2>
        </div>
        <el-button type="text" icon="el-icon-close" class="close-btn" @click="innerVisible = false" />
      </header>

      <div v-loading="loading" class="customer-form-body">
        <el-alert
          :closable="false"
          type="info"
          show-icon
          title="客户资料修改仅影响后续业务；已生成销售订单保留客户、联系人和收货地址快照。"
          class="alert-tip"
        />

        <!-- 智能识别快捷录入 -->
        <section class="form-section smart-recognize-section">
          <div class="form-section-title">
            <h3><i class="el-icon-magic-stick" /> 智能识别快捷回填</h3>
            <span class="section-sub-tip">可粘贴姓名、电话、收件地址快速解析</span>
          </div>
          <el-input
            v-model="rawInputText"
            type="textarea"
            :rows="2"
            placeholder="粘贴姓名、电话、地址（例：张三 13800138000 广东省深圳市南山区高新南道1号），自动识别并拆分填入"
            @input="recognizeInfo"
          />
        </section>

        <!-- 基础资料 -->
        <section class="form-section">
          <h3>基础资料</h3>
          <el-form :model="form" label-width="84px" size="small" class="responsive-form">
            <el-row :gutter="14">
              <el-col :xs="24" :sm="12">
                <el-form-item label="客户类型" required>
                  <el-radio-group v-model="form.customer_kind" size="small" class="kind-radio-group">
                    <el-radio-button label="individual">个人</el-radio-button>
                    <el-radio-button label="enterprise">企业</el-radio-button>
                  </el-radio-group>
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="客户状态" required>
                  <el-select v-model="form.status" style="width:100%">
                    <el-option label="启用" value="enabled" />
                    <el-option label="潜在" value="potential" />
                    <el-option label="停用" value="disabled" />
                    <el-option label="黑名单" value="blacklisted" />
                  </el-select>
                </el-form-item>
              </el-col>
            </el-row>

            <el-form-item :label="form.customer_kind === 'enterprise' ? '企业名称' : '客户名称'" required>
              <el-input
                v-model.trim="form.customer_name"
                :placeholder="form.customer_kind === 'enterprise' ? '请输入企业工商注册全称 / 公司名称' : '请输入客户姓名 / 买家名称'"
                clearable
              />
            </el-form-item>

            <el-row :gutter="14">
              <el-col :xs="24" :sm="12">
                <el-form-item label="客户编码">
                  <el-input v-model.trim="form.customer_code" disabled class="erp-system-number" placeholder="自动生成客户编码" />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="客户简称">
                  <el-input v-model.trim="form.customer_short_name" placeholder="选填，如品牌名或简写" clearable />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="14">
              <el-col :xs="24" :sm="12">
                <el-form-item label="所属行业">
                  <el-input v-model.trim="form.customer_type" placeholder="例如：工业制造 / 商业餐饮" clearable />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="平台买家ID">
                  <el-input v-model.trim="form.platform_buyer_id" placeholder="选填，电商/平台客户买家号" clearable />
                </el-form-item>
              </el-col>
            </el-row>

            <el-row :gutter="14">
              <el-col :xs="24" :sm="12">
                <el-form-item label="负责人">
                  <el-input v-model.trim="form.owner_name" placeholder="销售负责人姓名" clearable />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="负责人ID">
                  <el-input-number v-model="form.owner_legacy_id" :min="1" controls-position="right" style="width:100%" placeholder="销售人员ID" />
                </el-form-item>
              </el-col>
            </el-row>

            <el-form-item label="备注">
              <el-input v-model.trim="form.remark" type="textarea" :rows="2" maxlength="300" show-word-limit placeholder="客户合作注意事项或备注" />
            </el-form-item>
          </el-form>
        </section>

        <!-- 联系人资料 -->
        <section class="form-section">
          <div class="form-section-title">
            <h3><i class="el-icon-user" /> 联系人</h3>
            <el-button size="mini" type="success" plain icon="el-icon-plus" @click="addContact">新增联系人</el-button>
          </div>
          <div v-if="!form.contacts.length" class="form-blank">
            <span>暂未添加联系人，点击上方按钮新增联系人</span>
          </div>
          <article v-for="(contact, index) in form.contacts" :key="contact.localId" class="sub-form">
            <header>
              <b>联系人 {{ index + 1 }} <span v-if="contact.is_default" class="badge-default">默认</span></b>
              <div>
                <el-checkbox v-model="contact.is_default" @change="handleContactDefaultChange(index)">设为默认</el-checkbox>
                <el-button type="text" class="danger-link" @click="removeContact(index)">删除</el-button>
              </div>
            </header>
            <el-row :gutter="12">
              <el-col :xs="24" :sm="12">
                <el-input v-model.trim="contact.contact_name" size="small" placeholder="姓名（必填）" />
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-input v-model.trim="contact.position" size="small" placeholder="职位（选填）" />
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-input v-model.trim="contact.mobile" size="small" placeholder="手机号码" />
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-input v-model.trim="contact.phone" size="small" placeholder="固定电话" />
              </el-col>
              <el-col :xs="24" :sm="24">
                <el-input v-model.trim="contact.email" size="small" placeholder="电子邮箱" />
              </el-col>
            </el-row>
          </article>
        </section>

        <!-- 收货地址资料 -->
        <section class="form-section">
          <div class="form-section-title">
            <h3><i class="el-icon-location-outline" /> 收货地址</h3>
            <el-button size="mini" type="success" plain icon="el-icon-plus" @click="addAddress">新增地址</el-button>
          </div>
          <div v-if="!form.addresses.length" class="form-blank">
            <span>暂未添加收货地址，点击上方按钮新增收货地址</span>
          </div>
          <article v-for="(address, index) in form.addresses" :key="address.localId" class="sub-form">
            <header>
              <b>收货地址 {{ index + 1 }} <span v-if="address.is_default" class="badge-default">默认</span></b>
              <div>
                <el-checkbox v-model="address.is_default" @change="handleAddressDefaultChange(index)">设为默认</el-checkbox>
                <el-button type="text" class="danger-link" @click="removeAddress(index)">删除</el-button>
              </div>
            </header>
            <el-row :gutter="12">
              <el-col :xs="24" :sm="12">
                <el-input v-model.trim="address.receiver_name" size="small" placeholder="收货人姓名（必填）" />
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-input v-model.trim="address.receiver_phone" size="small" placeholder="收货电话" />
              </el-col>
              <el-col :xs="24" :sm="8">
                <el-input v-model.trim="address.province" size="small" placeholder="省" />
              </el-col>
              <el-col :xs="24" :sm="8">
                <el-input v-model.trim="address.city" size="small" placeholder="市" />
              </el-col>
              <el-col :xs="24" :sm="8">
                <el-input v-model.trim="address.district" size="small" placeholder="区/县" />
              </el-col>
              <el-col :xs="24" :sm="24">
                <el-input v-model.trim="address.detail_address" size="small" placeholder="详细地址（如街道、门牌号等，必填）" />
              </el-col>
            </el-row>
          </article>
        </section>

        <!-- 开票资料：销售申请开票时自动带出 -->
        <section class="form-section">
          <div class="form-section-title">
            <h3><i class="el-icon-tickets" /> 开票资料</h3>
            <span class="section-sub-tip">选填，订单里申请开票时自动带出</span>
          </div>
          <el-form label-position="top" size="small">
            <el-row :gutter="14">
              <el-col :xs="24" :sm="12">
                <el-form-item label="默认发票类型">
                  <el-radio-group v-model="form.invoice_type"><el-radio label="vat_normal">普票</el-radio><el-radio label="vat_special">专票</el-radio></el-radio-group>
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="收票邮箱">
                  <el-input v-model.trim="form.invoice_email" maxlength="160" placeholder="电子发票发送邮箱" clearable />
                </el-form-item>
              </el-col>
            </el-row>
            <el-row :gutter="14">
              <el-col :xs="24" :sm="12">
                <el-form-item label="发票抬头">
                  <el-input v-model.trim="form.invoice_title" maxlength="200" placeholder="不填默认用客户名称" clearable />
                </el-form-item>
              </el-col>
              <el-col :xs="24" :sm="12">
                <el-form-item label="纳税人识别号">
                  <el-input v-model.trim="form.invoice_tax_no" maxlength="60" placeholder="统一社会信用代码" clearable />
                </el-form-item>
              </el-col>
            </el-row>
            <el-form-item label="地址、电话">
              <el-input v-model.trim="form.invoice_address_phone" maxlength="300" placeholder="专票必填，如：上海市XX路1号 021-12345678" clearable />
            </el-form-item>
            <el-form-item label="开户行及账号">
              <el-input v-model.trim="form.invoice_bank_account" maxlength="300" placeholder="专票必填，如：工商银行XX支行 6222 0000 0000" clearable />
            </el-form-item>
          </el-form>
        </section>
      </div>

      <footer class="drawer-footer">
        <el-button size="small" @click="innerVisible = false">取消</el-button>
        <el-button size="small" type="success" :loading="saving" :disabled="loading || (!form.id && !numberReservation)" @click="save">
          {{ submitButtonText }}
        </el-button>
      </footer>
    </div>
  </el-drawer>
</template>

<script>
import { createSalesCustomer, getSalesCustomer, updateSalesCustomer } from '@/api/erp/sales'
import { clearCreatePageReservation, reserveForCreatePage } from '@/utils/documentNumberReservation'

const blankContact = () => ({
  localId: `contact-${Date.now()}-${Math.random().toString(16).slice(2)}`,
  contact_name: '',
  position: '',
  mobile: '',
  phone: '',
  email: '',
  is_default: false,
  status: 'enabled'
})

const blankAddress = () => ({
  localId: `address-${Date.now()}-${Math.random().toString(16).slice(2)}`,
  receiver_name: '',
  receiver_phone: '',
  province: '',
  city: '',
  district: '',
  detail_address: '',
  is_default: false,
  status: 'enabled'
})

const blankForm = (kind = 'individual') => ({
  id: null,
  customer_kind: kind || 'individual',
  customer_code: '',
  customer_name: '',
  customer_short_name: '',
  customer_type: '',
  source_platform: '',
  platform_buyer_id: '',
  owner_name: '',
  owner_legacy_id: undefined,
  status: 'enabled',
  remark: '',
  invoice_type: 'vat_normal',
  invoice_title: '',
  invoice_tax_no: '',
  invoice_address_phone: '',
  invoice_bank_account: '',
  invoice_email: '',
  contacts: [],
  addresses: []
})

export default {
  name: 'CustomerDrawer',
  props: {
    visible: {
      type: Boolean,
      default: false
    },
    confirmText: {
      type: String,
      default: ''
    }
  },
  data: () => ({
    innerVisible: false,
    loading: false,
    saving: false,
    openSession: 0,
    numberReservation: null,
    rawInputText: '',
    recognizedCustomerName: '',
    form: blankForm()
  }),
  computed: {
    submitButtonText() {
      if (this.confirmText) return this.confirmText
      return this.form.id ? '保存修改' : '保存客户'
    }
  },
  watch: {
    visible(val) {
      this.innerVisible = val
    },
    innerVisible(val) {
      if (!val) this.openSession += 1
      this.$emit('update:visible', val)
    }
  },
  methods: {
    // 关闭或重开后，晚到请求不能回填或结束新客户会话。
    openForm(defaultKind = 'individual') {
      this.openSession += 1
      this.form = blankForm(defaultKind)
      this.numberReservation = null
      this.rawInputText = ''
      this.recognizedCustomerName = ''
      this.loading = false
      this.saving = false
      this.innerVisible = true
    },
    async openCreate(defaultKind = 'individual') {
      this.openForm(defaultKind)
      const session = this.openSession
      this.loading = true
      try {
        const reservation = await reserveForCreatePage('customer', '/sales/customers/create')
        if (session !== this.openSession || !this.innerVisible) return
        this.numberReservation = reservation
        this.form.customer_code = reservation.document_no
      } catch (error) {
        if (session !== this.openSession || !this.innerVisible) return
        this.$message.error(error.userMessage || '客户编码生成失败，请重新打开新增窗口')
      } finally {
        if (session === this.openSession) this.loading = false
      }
    },
    async openEdit(id) {
      this.openForm()
      const session = this.openSession
      this.loading = true
      try {
        const { data } = await getSalesCustomer(id)
        if (session !== this.openSession || !this.innerVisible) return
        this.form = {
          ...blankForm(data.customer_kind || 'individual'),
          ...data,
          customer_kind: data.customer_kind || 'individual',
          owner_legacy_id: data.owner_legacy_id ?? undefined,
          invoice_type: data.invoice_type || 'vat_normal',
          contacts: (data.contacts || []).map(item => ({ ...item, localId: `contact-${item.id}` })),
          addresses: (data.addresses || []).map(item => ({ ...item, localId: `address-${item.id}` }))
        }
      } catch (err) {
        if (session !== this.openSession || !this.innerVisible) return
        this.$message.error('客户档案加载失败')
        this.innerVisible = false
      } finally {
        if (session === this.openSession) this.loading = false
      }
    },
    handleClosed() {
      if (this.innerVisible) return
      this.form = blankForm()
      this.numberReservation = null
      this.rawInputText = ''
      this.recognizedCustomerName = ''
      this.saving = false
      this.loading = false
      this.$emit('closed')
    },
    addContact() {
      const isFirst = this.form.contacts.length === 0
      const contact = blankContact()
      if (isFirst) contact.is_default = true
      this.form.contacts.push(contact)
    },
    removeContact(index) {
      const removed = this.form.contacts.splice(index, 1)[0]
      if (removed && removed.is_default && this.form.contacts.length > 0) {
        this.form.contacts[0].is_default = true
      }
    },
    handleContactDefaultChange(index) {
      if (this.form.contacts[index].is_default) {
        this.form.contacts.forEach((c, i) => {
          if (i !== index) c.is_default = false
        })
      }
    },
    addAddress() {
      const isFirst = this.form.addresses.length === 0
      const address = blankAddress()
      if (isFirst) address.is_default = true
      this.form.addresses.push(address)
    },
    removeAddress(index) {
      const removed = this.form.addresses.splice(index, 1)[0]
      if (removed && removed.is_default && this.form.addresses.length > 0) {
        this.form.addresses[0].is_default = true
      }
    },
    handleAddressDefaultChange(index) {
      if (this.form.addresses[index].is_default) {
        this.form.addresses.forEach((a, i) => {
          if (i !== index) a.is_default = false
        })
      }
    },
    normalizeDefaults(rows) {
      if (!rows.length) return []
      const selected = rows.find(item => item.is_default) || rows[0]
      return rows.map(item => ({
        ...item,
        is_default: !!selected && item.localId === selected.localId,
        status: item.status || 'enabled'
      }))
    },
    recognizeInfo(value) {
      const raw = String(value || '').replace(/[，,]/g, ' ').replace(/\s+/g, ' ').trim()
      if (!raw) return
      const phoneMatch = raw.match(/1[3-9]\d{9}|(?:0\d{2,3}[-\s]?)?\d{7,8}/)
      const phone = phoneMatch ? phoneMatch[0].replace(/\s+/g, '') : ''
      const looksAddress = text => /(省|市|区|县|镇|乡|街道|路|街|号|村|小区|公司|园区|室|栋|单元|楼|座|巷|弄)/.test(text)
      let name = ''
      let address = ''
      if (phoneMatch) {
        const before = raw.slice(0, phoneMatch.index).trim()
        const after = raw.slice(phoneMatch.index + phoneMatch[0].length).trim()
        if (before && after) {
          if (before.length <= 8 && !looksAddress(before)) {
            name = before
            address = after
          } else if (after.length <= 8 && !looksAddress(after)) {
            name = after
            address = before
          } else {
            address = looksAddress(after) ? after : before
            name = (looksAddress(before) ? after : before).split(' ')[0]
          }
        } else {
          const remain = (before || after).trim()
          if (looksAddress(remain)) {
            address = remain
          } else {
            name = remain
          }
        }
      } else if (looksAddress(raw)) {
        address = raw
      } else {
        name = raw
      }

      if (name && (!this.form.customer_name || this.form.customer_name === this.recognizedCustomerName)) {
        this.form.customer_name = name
        this.recognizedCustomerName = name
      }
      if (name || phone) {
        let contact = this.form.contacts[0]
        if (!contact) {
          contact = blankContact()
          contact.is_default = true
          this.form.contacts.push(contact)
        }
        if (name) contact.contact_name = name
        if (phone) contact.mobile = phone
      }
      if (address || name || phone) {
        let addr = this.form.addresses[0]
        if (!addr) {
          addr = blankAddress()
          addr.is_default = true
          this.form.addresses.push(addr)
        }
        if (name) addr.receiver_name = name
        if (phone) addr.receiver_phone = phone
        if (address) {
          addr.detail_address = address
        }
      }
    },
    async save() {
      if (this.loading || this.saving || !this.innerVisible) return
      if (!this.form.id && !this.numberReservation) return this.$message.warning('请重新打开新增窗口生成客户编码')
      if (!this.form.customer_name) {
        return this.$message.warning(this.form.customer_kind === 'enterprise' ? '请填写企业客户全称' : '请填写客户姓名')
      }
      if (!this.form.status) {
        return this.$message.warning('请选择客户状态')
      }
      if (this.form.contacts.some(item => !item.contact_name)) {
        return this.$message.warning('请填写联系人姓名或删除空白行')
      }
      if (this.form.addresses.some(item => !item.receiver_name || !item.detail_address)) {
        return this.$message.warning('请完整填写收货人和详细地址或删除空白行')
      }

      const session = this.openSession
      const reservation = this.numberReservation
      this.saving = true
      try {
        const payload = {
          ...this.form,
          customer_kind: this.form.customer_kind || 'individual',
          owner_legacy_id: this.form.owner_legacy_id ?? null,
          contacts: this.normalizeDefaults(this.form.contacts).map(({ localId, ...item }) => item),
          addresses: this.normalizeDefaults(this.form.addresses).map(({ localId, ...item }) => item)
        }
        if (!payload.id) {
          payload.reservation_token = reservation.reservation_token
          payload.creation_session_id = reservation.creation_session_id
        }

        const res = this.form.id
          ? await updateSalesCustomer(this.form.id, payload)
          : await createSalesCustomer(payload)

        if (!payload.id) clearCreatePageReservation(reservation)
        if (session !== this.openSession || !this.innerVisible) return
        const savedData = res.data.data
        this.$message.success(res.data.message || '客户资料已保存')
        this.innerVisible = false
        this.$emit('saved', savedData)
      } catch (error) {
        if (session !== this.openSession || !this.innerVisible) return
        const errors = error.response && error.response.data && error.response.data.errors
        this.$message.error(errors ? Object.values(errors)[0][0] : ((error.response && error.response.data && error.response.data.message) || '客户保存失败'))
      } finally {
        if (session === this.openSession) this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.customer-form-shell {
  height: 100%;
  display: grid;
  grid-template-rows: auto 1fr auto;
  background: #f7f8fa;
  min-width: 0;
  box-sizing: border-box;
}
.drawer-header {
  padding: 16px 20px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  background: #fff;
  border-bottom: 1px solid #e7ebef;
}
.drawer-crumb {
  color: #89939e;
  font-size: 12px;
}
.drawer-header h2 {
  margin: 3px 0 0;
  font-size: 19px;
  color: #17212b;
  font-weight: 600;
}
.close-btn {
  font-size: 18px;
  color: #9aa4af;
  padding: 4px;
}
.close-btn:hover {
  color: #334155;
}
.customer-form-body {
  overflow-y: auto;
  padding: 14px 18px;
  box-sizing: border-box;
}
.alert-tip {
  margin-bottom: 12px;
}
.form-section {
  margin-bottom: 12px;
  padding: 14px 16px;
  background: #fff;
  border: 1px solid #e7ebef;
  border-radius: 6px;
}
.form-section h3 {
  margin: 0 0 12px;
  font-size: 14px;
  color: #1e293b;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 6px;
}
.form-section-title {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}
.form-section-title h3 {
  margin: 0;
}
.section-sub-tip {
  font-size: 12px;
  color: #94a3b8;
}
.smart-recognize-section {
  background: #fbfdfe;
  border-color: #d1fae5;
}
.smart-recognize-section h3 {
  color: #047857;
}
.kind-radio-group :deep(.el-radio-button__orig-radio:checked + .el-radio-button__inner) {
  background-color: #00984f;
  border-color: #00984f;
  box-shadow: -1px 0 0 0 #00984f;
}
.form-blank {
  padding: 18px 0;
  text-align: center;
  color: #9aa4af;
  font-size: 12px;
  background: #f8fafc;
  border: 1px dashed #e2e8f0;
  border-radius: 4px;
}
.sub-form {
  margin-top: 10px;
  padding: 12px;
  border: 1px solid #edf0f2;
  border-radius: 6px;
  background: #fcfdfe;
}
.sub-form:hover {
  border-color: #cbd5e1;
}
.sub-form > header {
  margin-bottom: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.sub-form > header b {
  font-size: 13px;
  color: #334155;
  display: flex;
  align-items: center;
  gap: 6px;
}
.badge-default {
  display: inline-block;
  padding: 1px 5px;
  font-size: 10px;
  font-weight: 500;
  color: #059669;
  background: #d1fae5;
  border-radius: 3px;
}
.sub-form :deep(.el-col) {
  margin-bottom: 8px;
}
.drawer-footer {
  padding: 12px 18px;
  display: flex;
  justify-content: flex-end;
  gap: 10px;
  background: #fff;
  border-top: 1px solid #e7ebef;
}
.drawer-footer .el-button {
  min-width: 80px;
}
.danger-link {
  color: #ef4444 !important;
  margin-left: 10px;
}
.danger-link:hover {
  color: #dc2626 !important;
}

@media (max-width: 768px) {
  .customer-form-drawer :deep(.el-drawer) {
    width: 100% !important;
  }
  .customer-form-body {
    padding: 10px;
  }
}
</style>
