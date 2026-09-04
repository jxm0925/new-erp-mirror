<template>
  <div class="customer-page">
    <main class="customer-workspace">
      <header class="customer-heading">
        <div>
          <div class="customer-crumb">销售管理 <span>/</span> 客户管理</div>
          <h1>客户管理</h1>
          <p>维护客户资料与合作信息，管理联系人与收货地址，支撑销售业务全流程使用。</p>
        </div>
        <div class="customer-actions">
          <el-button v-if="$can('sales.customer.create')" size="small" type="success" icon="el-icon-plus" @click="openCreate">新增客户</el-button>
          <el-button size="small" icon="el-icon-download" @click="exportPage">导出当前页</el-button>
          <el-button size="small" icon="el-icon-printer" @click="printPage">打印</el-button>
        </div>
      </header>

      <section class="customer-kpis" aria-label="客户状态统计">
        <article v-for="item in metrics" :key="item.key" class="customer-kpi">
          <span :class="['kpi-icon', item.key]"><i :class="item.icon" /></span>
          <div><small>{{ item.label }}</small><strong>{{ summary[item.key] || 0 }}</strong></div>
        </article>
      </section>

      <section class="customer-filter" aria-label="客户筛选">
        <label>客户名称/编码<el-input v-model.trim="query.keyword" size="small" clearable placeholder="请输入客户名称或客户编码" @keyup.enter.native="search" /></label>
        <label>电话<el-input v-model.trim="query.phone" size="small" clearable placeholder="请输入联系电话" @keyup.enter.native="search" /></label>
        <label>状态
          <el-select v-model="query.status" size="small">
            <el-option label="全部" value="" /><el-option label="启用" value="enabled" /><el-option label="潜在" value="potential" /><el-option label="停用" value="disabled" /><el-option label="黑名单" value="blacklisted" />
          </el-select>
        </label>
        <label>负责人
          <el-select v-model="query.owner_name" size="small" clearable placeholder="全部">
            <el-option v-for="name in ownerOptions" :key="name" :label="name" :value="name" />
          </el-select>
        </label>
        <div class="filter-buttons"><el-button size="small" type="success" @click="search">查询</el-button><el-button size="small" @click="reset">重置</el-button></div>
      </section>

      <section class="customer-table-card">
        <el-table v-loading="loading" :data="rows" size="small" highlight-current-row :row-class-name="rowClass" @row-click="select">
          <el-table-column width="44" align="center"><template slot-scope="{row}"><el-checkbox :value="selectedIds.includes(row.id)" @change="toggleSelection(row)" /></template></el-table-column>
          <el-table-column prop="customer_code" label="客户编码" width="112" show-overflow-tooltip />
          <el-table-column prop="customer_name" label="客户名称" min-width="170" show-overflow-tooltip />
          <el-table-column label="客户类型" width="96"><template slot-scope="{row}"><span class="type-tag">{{ row.customer_type || '未分类' }}</span></template></el-table-column>
          <el-table-column label="默认联系人" width="130"><template slot-scope="{row}"><b>{{ defaultContact(row).contact_name || '—' }}</b><small class="cell-sub">{{ defaultContact(row).mobile || defaultContact(row).phone || row.contact_phone || '—' }}</small></template></el-table-column>
          <el-table-column label="默认收货地址" min-width="210" show-overflow-tooltip><template slot-scope="{row}">{{ defaultAddress(row).full_address || row.full_address || '—' }}</template></el-table-column>
          <el-table-column prop="owner_name" label="负责人" width="82"><template slot-scope="{row}">{{ row.owner_name || '—' }}</template></el-table-column>
          <el-table-column label="状态" width="76"><template slot-scope="{row}"><span :class="['status-dot', row.status]" />{{ statusText(row.status) }}</template></el-table-column>
          <el-table-column label="最近订单" width="116"><template><span class="muted">—</span></template></el-table-column>
          <el-table-column label="更新时间" width="116"><template slot-scope="{row}">{{ dateTime(row.updated_at) }}</template></el-table-column>
          <el-table-column label="操作" width="118" fixed="right"><template slot-scope="{row}"><el-button type="text" size="mini" @click.stop="select(row)">查看</el-button><el-button v-if="$can('sales.customer.edit')" type="text" size="mini" @click.stop="openEdit(row)">编辑</el-button></template></el-table-column>
          <template slot="empty"><div class="customer-empty"><i class="el-icon-office-building" /><b>暂无客户资料</b><span>新增客户后，将在这里维护联系人和默认收货地址。</span><el-button v-if="$can('sales.customer.create')" type="success" size="small" @click="openCreate">新增客户</el-button></div></template>
        </el-table>
        <footer class="customer-pagination"><span>共 {{ total }} 条</span><el-pagination background layout="sizes, prev, pager, next, jumper" :current-page="query.page" :page-size="query.per_page" :page-sizes="[10,20,50,100]" :total="total" @size-change="changeSize" @current-change="changePage" /></footer>
      </section>
    </main>

    <aside class="customer-inspector" aria-label="客户详情">
      <div v-if="selected" class="inspector-content">
        <header class="inspector-title"><b>客户详情</b><el-button v-if="$can('sales.customer.edit')" type="text" size="small" icon="el-icon-edit" @click="openEdit(selected)">编辑</el-button></header>
        <section class="inspector-overview"><el-tag size="mini" :type="statusType(selected.status)">{{ statusText(selected.status) }}</el-tag><h2>{{ selected.customer_name }}</h2><p>{{ selected.customer_code }}</p><dl><dt>客户类型</dt><dd>{{ selected.customer_type || '—' }}</dd><dt>负责人</dt><dd>{{ selected.owner_name || '—' }}</dd><dt>联系电话</dt><dd>{{ defaultContact(selected).mobile || defaultContact(selected).phone || selected.contact_phone || '—' }}</dd><dt>客户备注</dt><dd>{{ selected.remark || '—' }}</dd></dl></section>
        <section class="inspector-block"><h3>默认联系人</h3><template v-if="defaultContact(selected).id"><b>{{ defaultContact(selected).contact_name }}<small>{{ defaultContact(selected).position || '联系人' }}</small></b><p>手机：{{ defaultContact(selected).mobile || '—' }}</p><p>电话：{{ defaultContact(selected).phone || '—' }}</p><p>邮箱：{{ defaultContact(selected).email || '—' }}</p></template><p v-else class="muted">尚未设置默认联系人</p></section>
        <section class="inspector-block"><h3>默认收货地址</h3><template v-if="defaultAddress(selected).id"><p class="address-main">{{ defaultAddress(selected).full_address }}</p><p>收货人：{{ defaultAddress(selected).receiver_name || '—' }}　{{ defaultAddress(selected).receiver_phone || '—' }}</p></template><p v-else class="muted">尚未设置默认收货地址</p></section>
        <footer class="inspector-actions"><el-button size="small" @click="openEdit(selected)">查看档案</el-button><el-button size="small" type="success" @click="createOrder">新增订单</el-button></footer>
      </div>
      <div v-else class="inspector-empty"><i class="el-icon-s-custom" /><b>选择一位客户</b><span>点击列表行可查看客户档案与默认收货信息。</span></div>
    </aside>

    <el-drawer :visible.sync="formVisible" :with-header="false" size="650px" append-to-body custom-class="customer-form-drawer" @closed="resetForm">
      <div class="customer-form-shell"><header><div><small>销售管理 / 客户管理</small><h2>{{ form.id ? '编辑客户' : '新增客户' }}</h2></div><el-button type="text" icon="el-icon-close" @click="formVisible=false" /></header>
        <div class="customer-form-body"><el-alert :closable="false" type="info" show-icon title="客户资料修改仅影响后续业务；已生成销售订单保留客户、联系人和收货地址快照。" />
          <section class="form-section"><h3>基础资料</h3><el-form :model="form" label-width="84px" size="small"><el-row :gutter="14"><el-col :span="12"><el-form-item label="客户编码"><el-input v-model.trim="form.customer_code" placeholder="留空自动生成" /></el-form-item></el-col><el-col :span="12"><el-form-item label="客户状态" required><el-select v-model="form.status" style="width:100%"><el-option label="启用" value="enabled" /><el-option label="潜在" value="potential" /><el-option label="停用" value="disabled" /><el-option label="黑名单" value="blacklisted" /></el-select></el-form-item></el-col></el-row><el-form-item label="客户名称" required><el-input v-model.trim="form.customer_name" placeholder="请输入客户全称" /></el-form-item><el-row :gutter="14"><el-col :span="12"><el-form-item label="客户简称"><el-input v-model.trim="form.customer_short_name" /></el-form-item></el-col><el-col :span="12"><el-form-item label="客户类型"><el-input v-model.trim="form.customer_type" placeholder="例如：工业制造" /></el-form-item></el-col></el-row><el-row :gutter="14"><el-col :span="12"><el-form-item label="负责人"><el-input v-model.trim="form.owner_name" placeholder="销售负责人" /></el-form-item></el-col><el-col :span="12"><el-form-item label="负责人ID"><el-input-number v-model="form.owner_legacy_id" :min="1" controls-position="right" style="width:100%" /></el-form-item></el-col></el-row><el-form-item label="备注"><el-input v-model.trim="form.remark" type="textarea" :rows="2" maxlength="300" show-word-limit /></el-form-item></el-form></section>
          <section class="form-section"><div class="form-section-title"><h3>联系人</h3><el-button size="mini" icon="el-icon-plus" @click="addContact">新增联系人</el-button></div><div v-if="!form.contacts.length" class="form-blank">暂未添加联系人</div><article v-for="(contact,index) in form.contacts" :key="contact.localId" class="sub-form"><header><b>联系人 {{ index + 1 }}</b><div><el-checkbox v-model="contact.is_default">设为默认</el-checkbox><el-button type="text" class="danger-link" @click="removeContact(index)">删除</el-button></div></header><el-row :gutter="12"><el-col :span="12"><el-input v-model.trim="contact.contact_name" size="small" placeholder="姓名（必填）" /></el-col><el-col :span="12"><el-input v-model.trim="contact.position" size="small" placeholder="职位" /></el-col><el-col :span="12"><el-input v-model.trim="contact.mobile" size="small" placeholder="手机" /></el-col><el-col :span="12"><el-input v-model.trim="contact.phone" size="small" placeholder="电话" /></el-col><el-col :span="24"><el-input v-model.trim="contact.email" size="small" placeholder="邮箱" /></el-col></el-row></article></section>
          <section class="form-section"><div class="form-section-title"><h3>收货地址</h3><el-button size="mini" icon="el-icon-plus" @click="addAddress">新增地址</el-button></div><div v-if="!form.addresses.length" class="form-blank">暂未添加收货地址</div><article v-for="(address,index) in form.addresses" :key="address.localId" class="sub-form"><header><b>收货地址 {{ index + 1 }}</b><div><el-checkbox v-model="address.is_default">设为默认</el-checkbox><el-button type="text" class="danger-link" @click="removeAddress(index)">删除</el-button></div></header><el-row :gutter="12"><el-col :span="12"><el-input v-model.trim="address.receiver_name" size="small" placeholder="收货人（必填）" /></el-col><el-col :span="12"><el-input v-model.trim="address.receiver_phone" size="small" placeholder="收货电话" /></el-col><el-col :span="8"><el-input v-model.trim="address.province" size="small" placeholder="省" /></el-col><el-col :span="8"><el-input v-model.trim="address.city" size="small" placeholder="市" /></el-col><el-col :span="8"><el-input v-model.trim="address.district" size="small" placeholder="区/县" /></el-col><el-col :span="24"><el-input v-model.trim="address.detail_address" size="small" placeholder="详细地址（必填）" /></el-col></el-row></article></section>
        </div><footer><el-button size="small" @click="formVisible=false">取消</el-button><el-button size="small" type="success" :loading="saving" @click="save">保存客户</el-button></footer></div>
    </el-drawer>
  </div>
</template>

<script>
import { createSalesCustomer, getSalesCustomer, listSalesCustomers, updateSalesCustomer } from '@/api/erp/sales'

const blankContact = () => ({ localId: `contact-${Date.now()}-${Math.random()}`, contact_name: '', position: '', mobile: '', phone: '', email: '', is_default: false, status: 'enabled' })
const blankAddress = () => ({ localId: `address-${Date.now()}-${Math.random()}`, receiver_name: '', receiver_phone: '', province: '', city: '', district: '', detail_address: '', is_default: false, status: 'enabled' })
const blankForm = () => ({ id: null, customer_code: '', customer_name: '', customer_short_name: '', customer_type: '', owner_name: '', owner_legacy_id: undefined, status: 'enabled', remark: '', contacts: [], addresses: [] })

export default {
  data: () => ({ rows: [], total: 0, summary: {}, ownerOptions: [], selected: null, selectedIds: [], loading: false, saving: false, formVisible: false, form: blankForm(), query: { keyword: '', phone: '', status: '', owner_name: '', page: 1, per_page: 20 } }),
  computed: {
    metrics() { return [
      { key: 'total', label: '全部客户', icon: 'el-icon-office-building' }, { key: 'enabled', label: '启用客户', icon: 'el-icon-circle-check' }, { key: 'potential', label: '潜在客户', icon: 'el-icon-star-off' }, { key: 'disabled', label: '停用客户', icon: 'el-icon-remove-outline' }, { key: 'blacklisted', label: '黑名单客户', icon: 'el-icon-warning-outline' }
    ] }
  },
  created() { this.load() },
  methods: {
    statusText(status) { return ({ enabled: '启用', potential: '潜在', disabled: '停用', blacklisted: '黑名单' })[status] || '—' },
    statusType(status) { return ({ enabled: 'success', potential: 'warning', disabled: 'info', blacklisted: 'danger' })[status] || 'info' },
    defaultContact(row) { return (row.contacts || []).find(item => item.is_default) || (row.contacts || [])[0] || {} },
    defaultAddress(row) { return (row.addresses || []).find(item => item.is_default) || (row.addresses || [])[0] || {} },
    dateTime(value) { return value ? String(value).replace('T', ' ').slice(0, 16) : '—' },
    rowClass({ row }) { return this.selected && row.id === this.selected.id ? 'customer-selected-row' : '' },
    select(row) { this.selected = row },
    async load() { this.loading = true; try { const { data } = await listSalesCustomers(this.query); this.rows = data.data || []; this.total = data.total || 0; this.summary = data.summary || {}; this.ownerOptions = data.owner_options || []; if (this.selected) this.selected = this.rows.find(item => item.id === this.selected.id) || null; else this.selected = null } catch (error) { this.$message.error((error.response && error.response.data && error.response.data.message) || '客户列表加载失败') } finally { this.loading = false } },
    reset() { this.query = { keyword: '', phone: '', status: '', owner_name: '', page: 1, per_page: this.query.per_page }; this.load() },
    changePage(page) { this.query.page = page; this.load() }, changeSize(size) { this.query.per_page = size; this.query.page = 1; this.load() },
    openCreate() { this.form = blankForm(); this.formVisible = true },
    async openEdit(row) { try { const { data } = await getSalesCustomer(row.id); this.form = { ...blankForm(), ...data, contacts: (data.contacts || []).map(item => ({ ...item, localId: `contact-${item.id}` })), addresses: (data.addresses || []).map(item => ({ ...item, localId: `address-${item.id}` })) }; this.formVisible = true } catch (_) { this.$message.error('客户档案加载失败') } },
    resetForm() { this.form = blankForm(); this.saving = false }, addContact() { this.form.contacts.push(blankContact()) }, removeContact(index) { this.form.contacts.splice(index, 1) }, addAddress() { this.form.addresses.push(blankAddress()) }, removeAddress(index) { this.form.addresses.splice(index, 1) },
    normalizeDefaults(rows) { const selected = rows.find(item => item.is_default) || rows[0]; return rows.map(item => ({ ...item, is_default: !!selected && item.localId === selected.localId, status: item.status || 'enabled' })) },
    async save() { if (!this.form.customer_name) return this.$message.warning('请填写客户名称'); if (!this.form.status) return this.$message.warning('请选择客户状态'); if (this.form.contacts.some(item => !item.contact_name)) return this.$message.warning('请填写联系人姓名或删除该行'); if (this.form.addresses.some(item => !item.receiver_name || !item.detail_address)) return this.$message.warning('请完整填写收货人和详细地址'); this.saving = true; try { const payload = { ...this.form, contacts: this.normalizeDefaults(this.form.contacts).map(({ localId, ...item }) => item), addresses: this.normalizeDefaults(this.form.addresses).map(({ localId, ...item }) => item) }; const { data } = this.form.id ? await updateSalesCustomer(this.form.id, payload) : await createSalesCustomer(payload); this.$message.success(data.message || '客户已保存'); this.formVisible = false; await this.load(); this.selected = this.rows.find(item => item.id === data.data.id) || this.selected } catch (error) { const errors = error.response && error.response.data && error.response.data.errors; this.$message.error(errors ? Object.values(errors)[0][0] : ((error.response && error.response.data && error.response.data.message) || '客户保存失败')) } finally { this.saving = false } },
    createOrder() { this.$router.push({ path: '/sales/orders/create', query: { customer_id: this.selected.id } }) },
    exportPage() { const lines = [['客户编码', '客户名称', '客户类型', '默认联系人', '联系电话', '默认收货地址', '负责人', '状态'], ...this.rows.map(row => [row.customer_code, row.customer_name, row.customer_type || '', this.defaultContact(row).contact_name || '', this.defaultContact(row).mobile || this.defaultContact(row).phone || row.contact_phone || '', this.defaultAddress(row).full_address || row.full_address || '', row.owner_name || '', this.statusText(row.status)])]; const blob = new Blob(['\uFEFF' + lines.map(line => line.map(value => `"${String(value || '').replace(/"/g, '""')}"`).join(',')).join('\n')], { type: 'text/csv;charset=utf-8;' }); const link = document.createElement('a'); link.href = URL.createObjectURL(blob); link.download = `客户管理-${new Date().toISOString().slice(0, 10)}.csv`; link.click(); URL.revokeObjectURL(link.href) },
    printPage() { window.print() }
  }
}
</script>

<style scoped>
.customer-page{min-height:calc(100vh - 52px);padding:16px 18px;display:grid;grid-template-columns:minmax(0,1fr) 288px;gap:14px;background:#f7f8fa}.customer-workspace{min-width:0}.customer-heading{min-height:76px;display:flex;justify-content:space-between;align-items:flex-start}.customer-crumb{color:#6b7280;font-size:12px}.customer-crumb span{padding:0 8px;color:#b4bdc7}.customer-heading h1{margin:8px 0 2px;font-size:21px;line-height:1.1;color:#15212b}.customer-heading p{margin:0;color:#76818d;font-size:11px}.customer-actions{display:flex;gap:9px;padding-top:2px}.customer-kpis{display:grid;grid-template-columns:repeat(5,minmax(126px,1fr));gap:9px;margin-bottom:14px}.customer-kpi{height:80px;padding:14px;display:flex;align-items:center;gap:11px;background:#fff;border:1px solid #edf0f3;border-radius:7px;box-shadow:0 2px 7px rgba(17,24,39,.02)}.kpi-icon{width:32px;height:32px;display:grid;place-items:center;border-radius:50%;font-size:16px}.kpi-icon.total{color:#18a563;background:#e9fbf1}.kpi-icon.enabled{color:#18a563;background:#e9fbf1}.kpi-icon.potential{color:#2b9ed3;background:#ebf8ff}.kpi-icon.disabled{color:#f28a27;background:#fff3e7}.kpi-icon.blacklisted{color:#eb4b54;background:#fff0f0}.customer-kpi small{display:block;color:#67717c;font-size:11px}.customer-kpi strong{font-size:24px;line-height:1.2;color:#171e26}.customer-filter{display:grid;grid-template-columns:minmax(180px,1.25fr) minmax(140px,1fr) 132px 132px auto;gap:12px;align-items:end;margin-bottom:12px}.customer-filter label{display:grid;gap:5px;color:#3e4954;font-size:11px}.filter-buttons{display:flex;gap:8px}.customer-table-card{overflow:hidden;background:#fff;border:1px solid #e9edf1;border-radius:7px}.customer-table-card ::v-deep .el-table th.el-table__cell{height:44px;background:#fbfcfd;color:#29323b}.customer-table-card ::v-deep .el-table td.el-table__cell{padding:9px 0}.customer-table-card ::v-deep .customer-selected-row td{background:#f1faf5!important}.customer-table-card ::v-deep .el-table__fixed-right{height:100%!important}.type-tag{display:inline-block;padding:2px 6px;border-radius:3px;color:#3384dc;background:#edf5ff;font-size:10px}.cell-sub{display:block;margin-top:2px;color:#6f7984;font-weight:400;font-size:10px}.status-dot{display:inline-block;width:6px;height:6px;margin-right:5px;border-radius:50%;background:#9aa4af}.status-dot.enabled{background:#00a459}.status-dot.potential{background:#f19b26}.status-dot.disabled{background:#9aa4af}.status-dot.blacklisted{background:#e23e45}.muted{color:#9aa4af}.customer-pagination{min-height:64px;padding:11px 12px;display:flex;align-items:center;justify-content:space-between;border-top:1px solid #eef1f4;color:#66717b}.customer-pagination ::v-deep .el-pagination{padding:0}.customer-empty{height:330px;display:grid;place-content:center;justify-items:center;gap:9px;color:#98a2ad}.customer-empty i{font-size:34px;color:#b3bdc7}.customer-empty b{color:#4b5560;font-size:13px}.customer-empty span{font-size:11px}.customer-inspector{min-width:0;padding:0;background:#fff;border:1px solid #e7ebef;border-radius:7px;box-shadow:0 2px 8px rgba(17,24,39,.025)}.inspector-content{min-height:100%;display:flex;flex-direction:column}.inspector-title{height:52px;padding:0 14px;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid #edf0f2;font-size:15px}.inspector-overview,.inspector-block{padding:15px 14px;border-bottom:1px solid #edf0f2}.inspector-overview h2{margin:8px 0 2px;font-size:17px;line-height:1.35;color:#202830}.inspector-overview>p{margin:0 0 14px;color:#75808c}.inspector-overview dl{display:grid;grid-template-columns:68px minmax(0,1fr);gap:8px;margin:0;font-size:11px}.inspector-overview dt{color:#7d8792}.inspector-overview dd{margin:0;word-break:break-word}.inspector-block h3{margin:0 0 12px;font-size:13px}.inspector-block b{display:block;font-size:12px}.inspector-block b small{margin-left:6px;color:#8a949d;font-weight:400}.inspector-block p{margin:5px 0;color:#59636e;font-size:11px;line-height:1.55}.address-main{color:#2e3740!important;font-weight:600}.inspector-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:auto;padding:14px}.inspector-actions .el-button{margin:0}.inspector-empty{height:100%;min-height:410px;display:grid;place-content:center;justify-items:center;gap:8px;color:#9aa4af;text-align:center}.inspector-empty i{font-size:32px;color:#b9c2ca}.inspector-empty b{color:#56616b}.inspector-empty span{max-width:180px;font-size:11px}.customer-form-shell{height:100%;display:grid;grid-template-rows:auto 1fr auto;background:#f7f8fa}.customer-form-shell>header{padding:17px 20px;display:flex;justify-content:space-between;align-items:flex-start;background:#fff;border-bottom:1px solid #e7ebef}.customer-form-shell>header small{color:#89939e}.customer-form-shell>header h2{margin:3px 0 0;font-size:19px}.customer-form-body{overflow:auto;padding:14px 18px}.customer-form-body>.el-alert{margin-bottom:12px}.form-section{margin-bottom:12px;padding:14px;background:#fff;border:1px solid #e7ebef;border-radius:5px}.form-section h3{margin:0 0 12px;font-size:14px}.form-section-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:10px}.form-section-title h3{margin:0}.form-blank{padding:17px 0;text-align:center;color:#9aa4af;font-size:11px}.sub-form{margin-top:9px;padding:10px;border:1px solid #edf0f2;border-radius:4px;background:#fcfdfe}.sub-form>header{margin-bottom:9px;display:flex;justify-content:space-between;align-items:center}.sub-form>header b{font-size:12px}.sub-form ::v-deep .el-col{margin-bottom:8px}.sub-form ::v-deep .el-col:nth-last-child(-n+1){margin-bottom:0}.customer-form-shell>footer{padding:12px 18px;text-align:right;background:#fff;border-top:1px solid #e7ebef}.customer-form-shell>footer .el-button{min-width:76px}.danger-link{color:#e34b4f!important}@media(max-width:1280px){.customer-page{grid-template-columns:minmax(0,1fr)}.customer-inspector{display:none}}@media(max-width:980px){.customer-page{padding:12px;min-width:0}.customer-kpis{grid-template-columns:repeat(5,130px);overflow:auto}.customer-filter{grid-template-columns:1fr 1fr 130px 130px auto}.customer-heading p{display:none}}@media print{.erp-sidebar,.erp-topbar,.customer-actions,.customer-filter,.customer-inspector,.customer-pagination{display:none!important}.customer-page{display:block;padding:0;background:#fff}.customer-kpis{margin:10px 0}.customer-table-card{border:0}}
</style>
