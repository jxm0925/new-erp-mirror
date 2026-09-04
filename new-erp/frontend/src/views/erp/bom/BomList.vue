<template>
  <section class="bom-page">
    <div class="bom-head">
      <div>
        <h1>BOM 管理</h1>
        <p>维护 SKU / 产出 Item 的生产用料结构、版本、审核、有效期与默认启用关系。</p>
      </div>
      <div class="head-actions">
        <el-button size="small" @click="$router.push('/bom/expand')">BOM 展开</el-button>
        <el-button size="small" type="success" icon="el-icon-plus" @click="$router.push('/bom/create')">新增 BOM</el-button>
      </div>
    </div>

    <div class="bom-filter">
      <el-input v-model="query.keyword" size="small" clearable placeholder="BOM编号 / 名称 / Item / SKU" @keyup.enter.native="search" />
      <el-select v-model="query.product_id" size="small" clearable filterable placeholder="归属商品">
        <el-option v-for="p in products" :key="p.id" :label="`${p.product_code} / ${p.product_name}`" :value="p.id" />
      </el-select>
      <el-select v-model="query.sku_id" size="small" clearable filterable placeholder="关联SKU">
        <el-option v-for="s in skus" :key="s.id" :label="`${s.sku_code} / ${s.sku_name}`" :value="s.id" />
      </el-select>
      <el-select v-model="query.output_item_id" size="small" clearable filterable placeholder="产出Item">
        <el-option v-for="i in items" :key="i.id" :label="`${i.item_code} / ${i.item_name}`" :value="i.id" />
      </el-select>
      <el-select v-model="query.status" size="small" clearable placeholder="状态">
        <el-option label="草稿" value="draft" />
        <el-option label="启用" value="active" />
        <el-option label="已停用" value="inactive" />
        <el-option label="已归档" value="archived" />
      </el-select>
      <el-select v-model="query.audit_status" size="small" clearable placeholder="审核状态">
        <el-option label="待审核" value="pending" />
        <el-option label="已审核" value="approved" />
        <el-option label="已驳回" value="rejected" />
      </el-select>
      <el-select v-model="query.bom_type" size="small" clearable placeholder="BOM类型">
        <el-option label="标准" value="standard" />
        <el-option label="定制" value="custom" />
        <el-option label="试制" value="trial" />
      </el-select>
      <el-select v-model="query.is_default" size="small" clearable placeholder="默认">
        <el-option label="默认" value="true" />
        <el-option label="非默认" value="false" />
      </el-select>
      <el-date-picker v-model="query.dateRange" size="small" type="daterange" range-separator="至" start-placeholder="更新时间起" end-placeholder="更新时间止" value-format="yyyy-MM-dd" />
      <div class="filter-actions">
        <el-button size="small" type="success" @click="search">查询</el-button>
        <el-button size="small" @click="reset">重置</el-button>
      </div>
    </div>

    <el-table :data="rows" border size="small" class="bom-table" v-loading="loading">
      <el-table-column prop="bom_no" label="BOM编号" width="142" show-overflow-tooltip />
      <el-table-column prop="bom_name" label="BOM名称" min-width="150" show-overflow-tooltip />
      <el-table-column label="归属商品" width="118" show-overflow-tooltip>
        <template slot-scope="{row}">{{ row.product ? row.product.product_name : '-' }}</template>
      </el-table-column>
      <el-table-column label="关联SKU" width="142" show-overflow-tooltip>
        <template slot-scope="{row}">{{ row.sku ? `${row.sku.sku_code} / ${row.sku.sku_name}` : '-' }}</template>
      </el-table-column>
      <el-table-column label="产出Item" width="150" show-overflow-tooltip>
        <template slot-scope="{row}">{{ row.output_item ? `${row.output_item.item_code} / ${row.output_item.item_name}` : '-' }}</template>
      </el-table-column>
      <el-table-column prop="version" label="版本" width="62" />
      <el-table-column label="默认" width="74">
        <template slot-scope="{row}">
          <el-tag size="mini" :type="row.is_default ? 'success' : 'info'">{{ row.is_default ? '★ 默认' : '否' }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="有效期" width="132" show-overflow-tooltip>
        <template slot-scope="{row}">
          <span>{{ dateText(row.effective_date) }} 至 {{ row.expire_date ? dateText(row.expire_date) : '长期' }}</span>
        </template>
      </el-table-column>
      <el-table-column label="状态" width="82">
        <template slot-scope="{row}">
          <el-tag size="mini" :type="displayStatusType(row)">{{ displayStatusText(row) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column label="审核" width="76">
        <template slot-scope="{row}">
          <el-tag size="mini" :type="displayAuditType(row)">{{ displayAuditText(row) }}</el-tag>
        </template>
      </el-table-column>
      <el-table-column prop="updated_at" label="更新时间" width="122" show-overflow-tooltip />
      <el-table-column label="操作" width="265">
        <template slot-scope="{row}">
          <el-button type="text" size="mini" @click="$router.push(`/bom/${row.id}/detail`)">查看</el-button>
          <el-button v-if="isDraftEditable(row)" type="text" size="mini" @click="$router.push(`/bom/${row.id}/edit`)">编辑</el-button>
          <el-button v-if="isDraftEditable(row)" type="text" size="mini" @click="action(row, 'submit')">提交审核</el-button>
          <el-button v-if="isPendingAudit(row)" type="text" size="mini" @click="action(row, 'approve')">审核通过</el-button>
          <el-button v-if="isPendingAudit(row)" type="text" size="mini" @click="action(row, 'reject')">驳回</el-button>
          <el-button v-if="isApprovedInactive(row)" type="text" size="mini" @click="action(row, 'activate')">启用</el-button>
          <el-button v-if="isActiveApproved(row)" type="text" size="mini" @click="action(row, 'deactivate')">停用</el-button>
          <el-button v-if="canSetDefault(row)" type="text" size="mini" @click="action(row, 'setDefault')">设默认</el-button>
          <el-button v-if="row.audit_status === 'approved'" type="text" size="mini" @click="copyVersion(row)">复制新版本</el-button>
          <el-button v-if="isActiveApproved(row) && isEffective(row)" type="text" size="mini" @click="$router.push({ path: '/bom/expand', query: { bom_id: row.id } })">展开</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination background small layout="total, sizes, prev, pager, next" :current-page.sync="page" :page-size.sync="perPage" :page-sizes="[10,20,50,100]" :total="total" @current-change="load" @size-change="load" />

    <el-dialog :visible.sync="opDialog.visible" title="BOM操作确认" width="420px" append-to-body>
      <div class="confirm-box">
        <div class="confirm-title">确认{{ opDialog.text }}？</div>
        <p>{{ opDialog.row ? opDialog.row.bom_no : '-' }}</p>
        <p class="confirm-tip">该操作会改变 BOM 状态，请确认当前业务节点正确。</p>
      </div>
      <span slot="footer">
        <el-button size="small" @click="opDialog.visible=false">取消</el-button>
        <el-button size="small" type="success" :loading="opDialog.loading" @click="confirmAction">确定</el-button>
      </span>
    </el-dialog>

    <el-dialog :visible.sync="versionDialog.visible" title="复制为新版本" width="420px" append-to-body>
      <el-form label-width="90px" size="small">
        <el-form-item label="原BOM">
          <span>{{ versionDialog.row ? versionDialog.row.bom_no : '-' }}</span>
        </el-form-item>
        <el-form-item label="新版本号">
          <el-input v-model="versionDialog.version" placeholder="请输入新版本号" />
        </el-form-item>
      </el-form>
      <span slot="footer">
        <el-button size="small" @click="versionDialog.visible=false">取消</el-button>
        <el-button size="small" type="success" :loading="versionDialog.loading" @click="confirmCopyVersion">确定</el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { listBoms, submitBom, approveBom, rejectBom, activateBom, deactivateBom, setDefaultBom, copyBomVersion } from '@/api/erp/bom'

export default {
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    page: 1,
    perPage: 20,
    products: [],
    skus: [],
    items: [],
    query: { keyword: '', product_id: '', sku_id: '', output_item_id: '', status: '', audit_status: '', bom_type: '', is_default: '', dateRange: [] },
    opDialog: { visible: false, loading: false, row: null, type: '', text: '' },
    versionDialog: { visible: false, loading: false, row: null, version: '' }
  }),
  created() {
    this.loadRefs()
    this.load()
  },
  methods: {
    async loadRefs() {
      const [p, s, i] = await Promise.all([
        listEntity('products', { per_page: 100 }),
        listEntity('skus', { per_page: 100 }),
        listEntity('items', { per_page: 100 })
      ])
      this.products = p.data.data || []
      this.skus = s.data.data || []
      this.items = i.data.data || []
    },
    params() {
      const [date_from, date_to] = this.query.dateRange || []
      return { ...this.query, date_from, date_to, dateRange: undefined, page: this.page, per_page: this.perPage }
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listBoms(this.params())
        this.rows = data.data || []
        this.total = data.total || 0
      } finally {
        this.loading = false
      }
    },
    search() { this.page = 1; this.load() },
    reset() {
      this.query = { keyword: '', product_id: '', sku_id: '', output_item_id: '', status: '', audit_status: '', bom_type: '', is_default: '', dateRange: [] }
      this.search()
    },
    statusText(v) {
      return ({ draft: '草稿', active: '启用', inactive: '已停用', archived: '已归档' })[v] || v
    },
    auditText(v) {
      return ({ pending: '待审核', approved: '已审核', rejected: '已驳回' })[v] || v
    },
    statusType(v) {
      return ({ active: 'success', inactive: 'warning', archived: 'info', draft: '' })[v] || ''
    },
    auditType(v) {
      return ({ approved: 'success', rejected: 'danger', pending: 'warning' })[v] || ''
    },
    displayStatusText(row) {
      if (this.isExpired(row)) return '已失效'
      if (this.isNotYetEffective(row)) return '未生效'
      if (this.isPendingAudit(row)) return '已提交'
      if (row.audit_status === 'approved' && row.status !== 'active') return '已审核'
      return this.statusText(row.status)
    },
    displayAuditText(row) {
      if (this.isDraftEditable(row)) return '未提交'
      return this.auditText(row.audit_status)
    },
    displayStatusType(row) {
      if (this.isExpired(row)) return 'danger'
      if (this.isNotYetEffective(row)) return 'warning'
      if (this.isPendingAudit(row)) return 'warning'
      if (row.audit_status === 'approved' && row.status !== 'active') return 'success'
      return this.statusType(row.status)
    },
    displayAuditType(row) {
      if (this.isDraftEditable(row)) return 'info'
      return this.auditType(row.audit_status)
    },
    isDraftEditable(row) { return row.status === 'draft' && !row.submitted_at },
    isPendingAudit(row) { return row.audit_status === 'pending' && Boolean(row.submitted_at) },
    isApprovedInactive(row) { return row.audit_status === 'approved' && row.status !== 'active' && row.status !== 'archived' },
    isActiveApproved(row) { return row.status === 'active' && row.audit_status === 'approved' },
    canSetDefault(row) { return this.isActiveApproved(row) && !row.is_default && this.isEffective(row) },
    isEffective(row) { return !this.isExpired(row) && !this.isNotYetEffective(row) },
    isExpired(row) {
      if (!row.expire_date) return false
      return this.toDateOnly(row.expire_date) < this.today()
    },
    isNotYetEffective(row) {
      if (!row.effective_date) return false
      return this.toDateOnly(row.effective_date) > this.today()
    },
    today() {
      const d = new Date()
      d.setHours(0, 0, 0, 0)
      return d
    },
    toDateOnly(value) {
      const d = new Date(value)
      d.setHours(0, 0, 0, 0)
      return d
    },
    dateText(value) {
      if (!value) return '-'
      return String(value).slice(0, 10)
    },
    action(row, type) {
      const text = { submit: '提交审核', approve: '审核通过', reject: '驳回', activate: '启用', deactivate: '停用', setDefault: '设为默认' }[type]
      this.opDialog = { visible: true, loading: false, row, type, text }
    },
    async confirmAction() {
      const { row, type, text } = this.opDialog
      if (!row || !type) return
      const api = { submit: submitBom, approve: approveBom, reject: rejectBom, activate: activateBom, deactivate: deactivateBom, setDefault: setDefaultBom }[type]
      this.opDialog.loading = true
      try {
        await api(row.id)
        this.$message.success(`${text}成功`)
        this.opDialog.visible = false
        this.load()
      } finally {
        this.opDialog.loading = false
      }
    },
    copyVersion(row) {
      this.versionDialog = { visible: true, loading: false, row, version: this.nextVersion(row.version) }
    },
    async confirmCopyVersion() {
      const { row, version } = this.versionDialog
      if (!row) return
      if (!version) return this.$message.error('请输入新版本号')
      this.versionDialog.loading = true
      try {
        const { data } = await copyBomVersion(row.id, { version })
        this.$message.success('已复制为新版本草稿')
        this.versionDialog.visible = false
        this.$router.push(`/bom/${data.data.id}/edit`)
      } finally {
        this.versionDialog.loading = false
      }
    },
    nextVersion(v) {
      const m = String(v || 'V1.0').match(/^(.*?)(\d+)$/)
      return m ? `${m[1]}${Number(m[2]) + 1}` : `${v}-2`
    }
  }
}
</script>

<style scoped>
.bom-page{padding:16px 18px 28px;background:#f6f8fa;min-height:calc(100vh - 52px);font-size:13px;color:#1f2937}
.bom-head{height:48px;display:flex;justify-content:space-between;align-items:flex-start}
.bom-head h1{margin:0;font-size:18px}.bom-head p{margin:4px 0 0;color:#6b7280}
.head-actions{display:flex;gap:8px}
.bom-filter{display:flex;flex-wrap:wrap;gap:8px;background:#fff;border:1px solid #e5e7eb;padding:12px;margin-bottom:12px}
.bom-filter>.el-input{width:230px}.bom-filter>.el-select{width:150px}.bom-filter>.el-date-editor{width:300px}
.filter-actions{display:flex;gap:8px}.bom-table{background:#fff}.el-pagination{margin-top:12px;text-align:right}
.confirm-box{font-size:13px;color:#374151}.confirm-title{font-weight:700;margin-bottom:8px}.confirm-tip{color:#d97706;margin-bottom:0}
@media(max-width:1400px){.bom-filter>.el-input{width:210px}.bom-filter>.el-select{width:140px}.bom-filter>.el-date-editor{width:280px}}
</style>
