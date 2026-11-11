<template>
  <div class="crm-page">
    <header class="crm-head"><span class="crm-head-icon"><i class="el-icon-user" /></span><h1>团队管理</h1></header>
    <el-tabs v-model="tab" @tab-click="search"><el-tab-pane label="销售人员" name="staff" /><el-tab-pane v-if="abilities.grant" label="分配授权" name="grants" /></el-tabs>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <div v-loading="initialLoading" class="team-layout">
      <aside v-if="tab === 'staff'" class="crm-box team-departments"><h3 class="crm-section-title">部门组织</h3><el-tree :data="departmentTree" node-key="id" :props="{ label: 'name', children: 'children' }" default-expand-all highlight-current :expand-on-click-node="false" @node-click="chooseDepartment" /><el-button v-if="query.department_id" type="text" size="small" @click="chooseDepartment({id:null})">全部授权部门</el-button></aside>
      <main class="team-main">
        <div class="crm-inline"><span class="crm-muted">{{ tab === 'staff' ? departmentName + ' · 销售 ' + total + ' 人' : 'CRM 分配授权' }}</span><el-button v-if="tab === 'grants' && abilities.grant" class="crm-actions" size="small" type="success" icon="el-icon-plus" @click="openGrant()">新增授权</el-button></div>
        <section class="crm-filters"><el-input v-model.trim="query.keyword" size="small" clearable :placeholder="tab === 'staff' ? '销售姓名 / 能力标签' : '授权对象姓名'" @keyup.enter.native="search" /><label v-if="tab === 'staff'">承接状态<el-select popper-class="crm-select-popper" v-model="query.accepting" size="small" clearable placeholder="全部" @change="search"><el-option label="可接收" :value="1" /><el-option label="暂停接收" :value="0" /></el-select></label><label v-else>授权状态<el-select popper-class="crm-select-popper" v-model="query.status" size="small" clearable placeholder="全部" @change="search"><el-option label="启用" value="active" /><el-option label="停用" value="disabled" /></el-select></label><el-button size="small" type="success" @click="search">查询</el-button><el-button size="small" @click="reset">重置</el-button></section>
        <section class="crm-card">
          <el-table v-if="tab === 'staff'" key="staff" v-loading="loading" :data="rows" size="small">
            <el-table-column prop="name" label="销售姓名" min-width="100" /><el-table-column prop="department_name" label="所属小组" min-width="130" />
            <el-table-column label="负责区域" min-width="100"><template slot-scope="{row}">{{ row.region || '—' }}</template></el-table-column><el-table-column label="入职日期" min-width="110"><template slot-scope="{row}">{{ row.joined_at ? date(row.joined_at).slice(0,10) : '—' }}</template></el-table-column>
            <el-table-column label="能力标签" min-width="230"><template slot-scope="{row}"><el-tag v-for="tag in tags(row)" :key="tag" type="success" size="mini" class="crm-tag">{{ tag }}</el-tag><span v-if="!tags(row).length">—</span></template></el-table-column>
            <el-table-column label="在跟商机" min-width="90" align="right"><template slot-scope="{row}">{{ count(row.open_opportunities) }}</template></el-table-column><el-table-column label="待接收线索" min-width="100" align="right"><template slot-scope="{row}">{{ count(row.pending_leads) }}</template></el-table-column>
            <el-table-column label="承接状态" min-width="105"><template slot-scope="{row}"><el-tag size="mini" :type="row.is_effective && row.accepting ? 'success' : 'info'">{{ !row.is_effective ? '已停用' : row.accepting ? '可接收' : '暂停接收' }}</el-tag></template></el-table-column>
            <el-table-column v-if="abilities.capability" label="操作" min-width="110" fixed="right"><template slot-scope="{row}"><el-button type="text" size="mini" @click="openCapability(row)">编辑能力</el-button></template></el-table-column>
          </el-table>
          <el-table v-else key="grants" v-loading="loading" :data="rows" size="small">
            <el-table-column prop="user_name" label="授权对象" min-width="130" /><el-table-column label="目标部门" min-width="160"><template slot-scope="{row}">{{ (row.department_names || []).join('、') || '—' }}</template></el-table-column><el-table-column label="来源渠道" min-width="140"><template slot-scope="{row}">{{ (row.channels || []).join('、') || '全部' }}</template></el-table-column><el-table-column label="权限" min-width="220"><template slot-scope="{row}">{{ (row.actions || []).map(actionLabel).join('、') }}</template></el-table-column>
            <el-table-column label="可分配销售" min-width="170"><template slot-scope="{row}">{{ row.assignee_mode === 'all' ? '目标部门全部可接收销售' : (row.assignee_names || []).join('、') || '—' }}</template></el-table-column><el-table-column label="有效期" min-width="160"><template slot-scope="{row}">{{ shortDate(row.effective_from) }} 至 {{ row.effective_to ? shortDate(row.effective_to) : '长期' }}<small class="crm-sub">{{ row.status === 'disabled' ? '已停用' : row.is_effective ? '生效中' : '当前未生效' }}</small></template></el-table-column>
            <el-table-column label="操作" min-width="140" fixed="right"><template slot-scope="{row}"><div class="crm-inline"><el-button type="text" size="mini" @click="openGrant(row)">编辑</el-button><el-button type="text" size="mini" :loading="switching === row.id" @click="toggleGrant(row)">{{ row.status === 'active' ? '停用' : '启用' }}</el-button></div></template></el-table-column>
          </el-table>
          <footer class="crm-pager"><el-pagination background layout="total, sizes, prev, pager, next, jumper" :total="total" :current-page.sync="query.page" :page-size.sync="query.per_page" :page-sizes="[10,20,50,100]" @size-change="search" @current-change="load" /></footer>
        </section>
      </main>
    </div>
    <el-dialog class="crm-dialog" title="编辑销售能力" :visible.sync="capability.visible" width="960px" append-to-body :close-on-click-modal="false" :before-close="closeCapability">
      <div v-loading="capability.loading"><p class="crm-muted">{{ capability.person.name }} / {{ capability.person.department_name }}</p><h3 class="crm-section-title">能力标签</h3>
        <el-form size="small" label-width="106px" :disabled="capability.saving || capability.loading || !!capability.error"><div class="crm-form-grid"><el-form-item v-for="group in metadata.tag_groups || []" :key="group.value" :label="group.label"><div class="capability-tags"><el-tag v-for="tag in capability.form.tags[group.value] || []" :key="tag" size="small" type="success" closable class="crm-tag" @close="removeTag(group.value,tag)">{{ tag }}</el-tag><el-input v-if="tagInput.group === group.value" ref="tagInput" v-model.trim="tagInput.value" size="mini" class="tag-input" maxlength="80" @keyup.enter.native="addTag" @blur="addTag" /><el-button v-else type="text" size="mini" @click="showTagInput(group.value)">+ 添加</el-button></div></el-form-item></div>
          <h3 class="crm-section-title">确认信息</h3><div class="crm-form-grid"><el-form-item label="标签来源" required><el-select popper-class="crm-select-popper" v-model="capability.form.tag_source" filterable allow-create default-first-option><el-option label="部组长确认" value="部组长确认" /><el-option label="本人申报后确认" value="本人申报后确认" /><el-option label="历史资料核实" value="历史资料核实" /></el-select></el-form-item><el-form-item label="确认人"><el-input :value="(metadata.current_user || {}).name || '当前登录人员'" disabled /></el-form-item><el-form-item label="确认日期" required><el-date-picker popper-class="crm-date-popper" v-model="capability.form.confirmed_at" type="date" value-format="yyyy-MM-dd" :picker-options="confirmationOptions" /></el-form-item><el-form-item label="承接状态" required><el-radio-group v-model="capability.form.accepting" size="small"><el-radio-button :label="true">可接收</el-radio-button><el-radio-button :label="false">暂停接收</el-radio-button></el-radio-group></el-form-item><el-form-item label="备注" class="crm-span-all"><el-input v-model.trim="capability.form.remark" type="textarea" :rows="2" maxlength="500" /></el-form-item></div>
        </el-form><el-alert v-if="capability.error" :title="capability.error" type="error" :closable="false" show-icon /><div class="crm-note">能力标签由部组长确认，用于人工分配参考；在跟商机和待接收数量来自业务记录，不能手工填写。</div>
      </div><span slot="footer"><el-button size="small" :disabled="capability.saving" @click="closeCapability">取消</el-button><el-button size="small" type="success" :loading="capability.saving" :disabled="capability.loading || !!capability.error" @click="saveCapability">保存</el-button></span>
    </el-dialog>
    <el-dialog class="crm-dialog" title="CRM 分配授权" :visible.sync="grant.visible" width="1000px" append-to-body :close-on-click-modal="false" :before-close="closeGrant">
      <el-form label-width="100px" size="small" :disabled="grant.saving">
        <el-form-item label="授权对象" required><div class="crm-inline grant-user"><el-input :value="grant.userName" readonly placeholder="选择现有账号" /><el-button v-if="!grant.form.id" @click="openUsers">选择人员</el-button></div></el-form-item>
        <el-form-item label="目标部门" required><el-checkbox-group v-model="grant.form.department_ids" @change="grantDepartmentsChanged"><el-checkbox v-for="department in grantDepartments" :key="department.id" :label="department.id">{{ department.name }}</el-checkbox></el-checkbox-group></el-form-item>
        <el-form-item label="来源渠道" required><el-checkbox v-model="grant.allChannels" :disabled="!grantLimits.all_channels" @change="grant.form.channels = []">全部渠道</el-checkbox><el-checkbox-group v-if="!grant.allChannels" v-model="grant.form.channels"><el-checkbox v-for="channel in metadata.channels || []" :key="channel.value" :label="channel.value">{{ channel.label }}</el-checkbox></el-checkbox-group></el-form-item>
        <el-form-item label="权限" required><el-checkbox-group v-model="grant.form.actions"><el-checkbox v-for="action in metadata.actions || []" :key="action.value" :label="action.value">{{ action.label }}</el-checkbox></el-checkbox-group></el-form-item>
        <el-form-item label="可分配销售" required><el-radio-group v-model="grant.form.assignee_mode" @change="loadGrantStaff"><el-radio label="all" :disabled="grantLimits.assignee_mode === 'specified'">目标部门全部可接收销售</el-radio><el-radio label="specified">指定销售</el-radio></el-radio-group></el-form-item>
        <template v-if="grant.form.assignee_mode === 'specified'">
          <div class="crm-filters"><el-input v-model.trim="grant.peopleQuery.keyword" size="small" clearable placeholder="销售姓名 / 能力标签" @keyup.enter.native="searchGrantStaff" /><el-button size="small" type="success" @click="searchGrantStaff">查询</el-button><span class="crm-muted">已选 {{ grant.form.assignee_ids.length }} 人</span></div>
          <el-table v-loading="grant.peopleLoading" :data="grant.people" size="small"><el-table-column width="44"><template slot-scope="{row}"><el-checkbox :value="grant.form.assignee_ids.includes(row.id)" :disabled="!row.is_effective" @change="selectAssignee(row.id,$event)" /></template></el-table-column><el-table-column prop="name" label="销售人员" min-width="140" /><el-table-column prop="department_name" label="所属部门" min-width="160" /><el-table-column label="承接状态" min-width="200"><template slot-scope="{row}"><el-tag size="mini" :type="row.is_effective && row.accepting ? 'success' : 'info'">{{ !row.is_effective ? '已停用' : row.accepting ? '可接收' : '暂停接收' }}</el-tag><span v-if="row.is_effective && !row.accepting" class="crm-muted"> 暂停期间分配时不可选</span></template></el-table-column></el-table>
          <div class="crm-pager"><el-pagination background layout="total, sizes, prev, pager, next" :total="grant.peopleTotal" :current-page.sync="grant.peopleQuery.page" :page-size.sync="grant.peopleQuery.per_page" :page-sizes="[10,20,50]" @size-change="searchGrantStaff" @current-change="loadGrantStaff" /></div>
          <div v-if="grant.form.assignee_ids.length" class="crm-note"><span v-for="id in grant.form.assignee_ids" :key="id"><el-tag closable type="success" size="small" class="crm-tag" @close="selectAssignee(id,false)">{{ grant.selectedNames[id] || id }}</el-tag></span></div>
        </template>
        <el-form-item label="有效期" required><div class="grant-dates"><el-date-picker popper-class="crm-date-popper" v-model="grant.form.effective_from" type="date" value-format="yyyy-MM-dd" :picker-options="grantDateOptions" /><span>至</span><el-date-picker popper-class="crm-date-popper" v-model="grant.form.effective_to" type="date" value-format="yyyy-MM-dd" :picker-options="grantDateOptions" :disabled="grant.longTerm" placeholder="长期" /><el-checkbox v-model="grant.longTerm" :disabled="!!grantLimits.effective_to" @change="grant.form.effective_to = null">长期有效</el-checkbox></div></el-form-item>
      </el-form><div class="crm-note">分配授权不增加金额、成本或其他部门客户的查看权限；停用或暂停接收的销售在分配时自动不可选。</div>
      <span slot="footer"><el-button size="small" :disabled="grant.saving" @click="closeGrant">取消</el-button><el-button size="small" type="success" :loading="grant.saving" :disabled="grant.peopleLoading" @click="saveGrant">保存授权</el-button></span>
    </el-dialog>
    <el-dialog class="crm-dialog" title="选择授权对象" :visible.sync="users.visible" width="700px" append-to-body :close-on-click-modal="false">
      <div class="crm-filters"><el-input v-model.trim="users.query.keyword" size="small" clearable placeholder="姓名 / 账号" @keyup.enter.native="searchUsers" /><el-button type="success" size="small" @click="searchUsers">查询</el-button></div><el-table v-loading="users.loading" :data="users.rows" size="small" @row-click="users.selected = $event"><el-table-column width="44"><template slot-scope="{row}"><el-radio :value="users.selected && users.selected.id" :label="row.id" @change="users.selected = row">&nbsp;</el-radio></template></el-table-column><el-table-column prop="name" label="授权对象" /></el-table><div class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="users.total" :current-page.sync="users.query.page" :page-size="10" @current-change="loadUsers" /></div><span slot="footer"><el-button size="small" @click="users.visible = false">取消</el-button><el-button size="small" type="success" :disabled="!users.selected" @click="confirmUser">确定选择</el-button></span>
    </el-dialog>
  </div>
</template>
<script>
import { getCrmTeamMetadata, listCrmTeamStaff, getCrmTeamStaff, saveCrmStaffCapability, listCrmGrants, saveCrmGrant, listCrmTeamUsers, crmError, crmDate } from '@/api/erp/crm'
import { erpDateTime } from '@/utils/dateTime'
import '@/styles/crm.css'
const blankQuery = (size = 10) => ({ keyword: '', accepting: null, status: '', department_id: null, page: 1, per_page: size })
const blankGrant = () => ({ id: null, user_legacy_id: null, department_ids: [], channels: [], actions: [], assignee_mode: 'all', assignee_ids: [], effective_from: erpDateTime(new Date().toISOString(), 10), effective_to: null, status: 'active' })
export default {
  name: 'CrmTeam',
  data: () => ({ tab: 'staff', metadata: {}, initialLoading: false, rows: [], total: 0, query: blankQuery(), loading: false, error: '', sequence: 0, switching: null, capSession: 0, grantSession: 0, grantPeopleSequence: 0, userSequence: 0, capability: { visible: false, loading: false, saving: false, error: '', person: {}, form: { tags: {} } }, tagInput: { group: '', value: '' }, grant: { visible: false, saving: false, form: blankGrant(), userName: '', allChannels: true, longTerm: true, people: [], peopleTotal: 0, peopleLoading: false, peopleQuery: { page: 1, per_page: 10, keyword: '' }, selectedNames: {} }, users: { visible: false, loading: false, selected: null, rows: [], total: 0, query: { keyword: '', page: 1, per_page: 10 } }, confirmationOptions: { disabledDate: date => date.getTime() > Date.now() } }),
  computed: {
    abilities() { return this.metadata.abilities || {} },
    grantLimits() { return this.metadata.grant_limits || {} },
    grantDepartments() { return (this.metadata.departments || []).filter(item => (this.grantLimits.department_ids || []).includes(Number(item.id))) },
    grantDateOptions() { return { disabledDate: date => (this.grantLimits.effective_from && date.getTime() < new Date(`${this.grantLimits.effective_from}T00:00:00`).getTime()) || (this.grantLimits.effective_to && date.getTime() > new Date(`${this.grantLimits.effective_to}T23:59:59`).getTime()) } },
    departmentName() { return ((this.metadata.departments || []).find(item => item.id === this.query.department_id) || {}).name || '全部授权部门' },
    departmentTree() { const rows = (this.metadata.departments || []).map(item => ({ ...item, children: [] })); const byId = new Map(rows.map(item => [Number(item.id), item])); const roots = []; rows.forEach(item => { const parent = byId.get(Number(item.parent_id)); if (parent && parent !== item) parent.children.push(item); else roots.push(item) }); return roots }
  },
  async created() { this.initialLoading = true; try { const { data } = await getCrmTeamMetadata(); this.metadata = data.data || data; await this.load() } catch (error) { this.error = crmError(error, '团队资料加载失败') } finally { this.initialLoading = false } },
  beforeDestroy() { this.sequence += 1; this.capSession += 1; this.grantSession += 1; this.userSequence += 1 },
  methods: {
    date: crmDate,
    shortDate(value) { return erpDateTime(value,10) || '—' },
    count(value) { return value === null || value === undefined ? '—' : value },
    tags(row) { return [...new Set(Object.values(row.tags || {}).flat())] },
    actionLabel(value) { return ((this.metadata.actions || []).find(item => item.value === value) || {}).label || value },
    chooseDepartment(row) { this.query.department_id = row.id; this.search() },
    search() { this.query.page = 1; this.load() },
    reset() { this.query = { ...blankQuery(this.query.per_page), department_id: this.query.department_id }; this.load() },
    async load() {
      const sequence = ++this.sequence
      this.loading = true
      this.error = ''
      try { const { data } = await (this.tab === 'staff' ? listCrmTeamStaff({ ...this.query }) : listCrmGrants({ ...this.query })); if (sequence !== this.sequence) return; this.rows = data.data || []; this.total = data.total || 0 } catch (error) { if (sequence === this.sequence) this.error = crmError(error, '团队列表加载失败') } finally { if (sequence === this.sequence) this.loading = false }
    },
    async openCapability(row) {
      const session = ++this.capSession
      this.capability = { visible: true, loading: true, saving: false, error: '', person: row, form: { tags: {} } }
      this.tagInput = { group: '', value: '' }
      try { const { data } = await getCrmTeamStaff(row.id); if (session !== this.capSession) return; const person = data.data || data; this.capability.person = person; this.capability.form = { tags: Object.fromEntries((this.metadata.tag_groups || []).map(group => [group.value, [...((person.tags || {})[group.value] || [])]])), tag_source: person.tag_source || '部组长确认', confirmed_at: erpDateTime(new Date().toISOString(),10), accepting: person.accepting, remark: person.remark || '', expected_version: person.version || 0 } } catch (error) { if (session === this.capSession) this.capability.error = crmError(error, '销售能力加载失败') } finally { if (session === this.capSession) this.capability.loading = false }
    },
    closeCapability() { if (this.capability.saving) return; this.capSession += 1; this.capability.visible = false },
    removeTag(group, value) { this.capability.form.tags[group] = this.capability.form.tags[group].filter(tag => tag !== value) },
    showTagInput(group) { this.addTag(); this.tagInput = { group, value: '' }; this.$nextTick(() => { const input = this.$refs.tagInput; if (input) (Array.isArray(input) ? input[0] : input).focus() }) },
    addTag() { const { group, value } = this.tagInput; if (group && value && !this.capability.form.tags[group].includes(value)) this.capability.form.tags[group].push(value); this.tagInput = { group: '', value: '' } },
    async saveCapability() {
      if (this.capability.saving || this.capability.loading || this.capability.error) return
      this.addTag()
      if (!this.capability.form.tag_source || !this.capability.form.confirmed_at) return this.$message.warning('请填写标签来源和确认日期')
      const session = this.capSession
      this.capability.saving = true
      try { await saveCrmStaffCapability(this.capability.person.id, this.capability.form); if (session !== this.capSession) return; this.capability.visible = false; this.$message.success('销售能力已保存'); this.load() } catch (error) { if (session === this.capSession) this.$message.error(crmError(error, '销售能力保存失败')) } finally { if (session === this.capSession) this.capability.saving = false }
    },
    async openGrant(row) {
      ++this.grantSession
      this.grant = { visible: true, saving: false, form: row ? this.grantPayload(row) : blankGrant(), userName: row ? row.user_name : '', allChannels: row ? !(row.channels || []).length : !!this.grantLimits.all_channels, longTerm: row ? !row.effective_to : !this.grantLimits.effective_to, people: [], peopleTotal: 0, peopleLoading: false, peopleQuery: { page: 1, per_page: 10, keyword: '' }, selectedNames: Object.fromEntries(row ? (row.assignees || []).map(person => [person.id, person.name]) : []) }
      if (!row) {
        if (this.grantLimits.assignee_mode === 'specified') this.grant.form.assignee_mode = 'specified'
        if (this.grantLimits.effective_from && this.grant.form.effective_from < this.grantLimits.effective_from) this.grant.form.effective_from = this.grantLimits.effective_from
        if (this.grantLimits.effective_to) this.grant.form.effective_to = this.grantLimits.effective_to
      }
      if (this.grant.form.assignee_mode === 'specified') this.loadGrantStaff()
    },
    grantPayload(row) { return { ...blankGrant(), id: row.id, user_legacy_id: row.user_legacy_id, department_ids: [...row.department_ids], channels: [...row.channels], actions: [...row.actions], assignee_mode: row.assignee_mode, assignee_ids: [...row.assignee_ids], effective_from: erpDateTime(row.effective_from,10), effective_to: row.effective_to ? erpDateTime(row.effective_to,10) : null, status: row.status, expected_version: row.version } },
    closeGrant() { if (this.grant.saving) return; this.grantSession += 1; this.grant.visible = false; this.users.visible = false; this.userSequence += 1 },
    grantDepartmentsChanged() { this.grant.form.assignee_ids = []; this.grant.selectedNames = {}; this.searchGrantStaff() },
    searchGrantStaff() { this.grant.peopleQuery.page = 1; this.loadGrantStaff() },
    async loadGrantStaff() {
      const session = this.grantSession
      const sequence = ++this.grantPeopleSequence
      if (this.grant.form.assignee_mode !== 'specified' || !this.grant.form.department_ids.length) { this.grant.people = []; this.grant.peopleTotal = 0; this.grant.peopleLoading = false; return }
      this.grant.peopleLoading = true
      try { const { data } = await listCrmTeamStaff({ ...this.grant.peopleQuery, department_ids: [...this.grant.form.department_ids], ...(this.grantLimits.assignee_mode === 'specified' ? { ids: this.grantLimits.assignee_ids || [] } : {}) }); if (session !== this.grantSession || sequence !== this.grantPeopleSequence) return; this.grant.people = data.data || []; this.grant.peopleTotal = data.total || 0; this.grant.people.forEach(person => this.$set(this.grant.selectedNames, person.id, person.name)) } catch (error) { if (session === this.grantSession && sequence === this.grantPeopleSequence) this.$message.error(crmError(error, '销售人员加载失败')) } finally { if (session === this.grantSession && sequence === this.grantPeopleSequence) this.grant.peopleLoading = false }
    },
    selectAssignee(id, checked) { this.grant.form.assignee_ids = checked ? [...new Set([...this.grant.form.assignee_ids,id])] : this.grant.form.assignee_ids.filter(value => value !== id) },
    openUsers() { this.users = { visible: true, loading: false, selected: null, rows: [], total: 0, query: { keyword: '', page: 1, per_page: 10 } }; this.loadUsers() },
    searchUsers() { this.users.query.page = 1; this.loadUsers() },
    async loadUsers() { const sequence = ++this.userSequence; this.users.loading = true; try { const { data } = await listCrmTeamUsers({ ...this.users.query }); if (sequence !== this.userSequence) return; this.users.rows = data.data || []; this.users.total = data.total || 0 } catch (error) { if (sequence === this.userSequence) this.$message.error(crmError(error, '授权对象加载失败')) } finally { if (sequence === this.userSequence) this.users.loading = false } },
    confirmUser() { if (!this.users.selected) return; this.grant.form.user_legacy_id = this.users.selected.id; this.grant.userName = this.users.selected.name; this.users.visible = false; this.userSequence += 1 },
    async saveGrant() {
      if (this.grant.saving || this.grant.peopleLoading) return
      const form = this.grant.form
      if (!form.user_legacy_id || !form.department_ids.length || !form.actions.length || !form.effective_from || (!this.grant.allChannels && !form.channels.length) || (!this.grant.longTerm && !form.effective_to) || (form.assignee_mode === 'specified' && !form.assignee_ids.length)) return this.$message.warning('请填写完整授权范围与有效期')
      const session = this.grantSession
      this.grant.saving = true
      try { await saveCrmGrant({ ...form, channels: this.grant.allChannels ? [] : form.channels, assignee_ids: form.assignee_mode === 'all' ? [] : form.assignee_ids, effective_to: this.grant.longTerm ? null : form.effective_to }); if (session !== this.grantSession) return; this.grant.visible = false; this.$message.success('分配授权已保存'); this.load() } catch (error) { if (session === this.grantSession) this.$message.error(crmError(error, '分配授权保存失败')) } finally { if (session === this.grantSession) this.grant.saving = false }
    },
    async toggleGrant(row) {
      if (this.switching) return
      const status = row.status === 'active' ? 'disabled' : 'active'
      try { await this.$confirm(`确认${status === 'disabled' ? '停用' : '启用'} ${row.user_name} 的 CRM 分配授权？`, '分配授权', { type: 'warning', confirmButtonText: '确认', cancelButtonText: '取消' }) } catch (error) { return }
      this.switching = row.id
      try { await saveCrmGrant({ ...this.grantPayload(row), status }); this.$message.success('授权状态已更新'); this.load() } catch (error) { this.$message.error(crmError(error, '授权状态修改失败')) } finally { this.switching = null }
    }
  }
}
</script>
<style scoped>
.team-layout { display: flex; align-items: flex-start; gap: 14px; min-width: 0; }
.team-departments { flex: 0 0 220px; min-height: 330px; }
.team-main { flex: 1; min-width: 0; display: flex; flex-direction: column; gap: 12px; }
.capability-tags { display: flex; align-items: center; flex-wrap: wrap; min-height: 32px; }
.tag-input { width: 130px; }
.grant-user { flex-wrap: nowrap; }
.grant-user .el-input { flex: 1; min-width: 0; }
.grant-dates { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.grant-dates .el-date-editor { flex: 1; width: auto; min-width: 150px; }
@media (max-width: 780px) { .team-layout { flex-direction: column; } .team-departments { flex: auto; width: 100%; min-height: 0; } .team-main { width: 100%; } }
</style>
