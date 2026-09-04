<template>
  <section class="bom-detail-page" v-loading="loading">
    <div class="detail-head">
      <div><h1>{{ bom.bom_no || 'BOM详情' }} <el-tag v-if="bom.is_default" size="mini" type="success">★ 默认</el-tag></h1><p>{{ bom.bom_name }} · {{ bom.version }}</p></div>
      <div class="head-actions">
        <el-button size="small" @click="$router.push('/bom/boms')">返回列表</el-button>
        <el-button v-if="isDraftEditable" size="small" @click="$router.push(`/bom/${bom.id}/edit`)">编辑</el-button>
        <el-button v-if="isDraftEditable" size="small" type="success" @click="action('submit')">提交审核</el-button>
        <el-button v-if="isPendingAudit" size="small" type="success" @click="action('approve')">审核通过</el-button>
        <el-button v-if="isPendingAudit" size="small" @click="action('reject')">驳回</el-button>
        <el-button v-if="isApprovedInactive" size="small" type="success" @click="action('activate')">启用</el-button>
        <el-button v-if="bom.audit_status === 'approved'" size="small" @click="copyVersion">复制新版本</el-button>
        <el-button v-if="isActiveApproved" size="small" @click="action('deactivate')">停用</el-button>
        <el-button v-if="isActiveApproved && !bom.is_default" size="small" @click="action('setDefault')">设为默认</el-button>
        <el-button v-if="isActiveApproved" size="small" type="success" @click="$router.push({ path: '/bom/expand', query: { bom_id: bom.id } })">展开</el-button>
      </div>
    </div>
    <div class="detail-layout">
      <main>
        <section class="detail-card"><h3>基础信息</h3><dl class="info-grid">
          <dt>BOM编号</dt><dd>{{ bom.bom_no }}</dd><dt>BOM名称</dt><dd>{{ bom.bom_name }}</dd>
          <dt>BOM类型</dt><dd>{{ typeText(bom.bom_type) }}</dd><dt>版本</dt><dd>{{ bom.version }}</dd>
          <dt>状态</dt><dd><el-tag size="mini" :type="displayStatusType">{{ displayStatusText }}</el-tag></dd><dt>审核状态</dt><dd><el-tag size="mini" :type="displayAuditType">{{ displayAuditText }}</el-tag></dd>
          <dt>生效日期</dt><dd>{{ dateOnly(bom.effective_date) || '-' }}</dd><dt>失效日期</dt><dd>{{ dateOnly(bom.expire_date) || '长期有效' }}</dd>
        </dl></section>
        <section class="detail-card"><h3>通用信息 / 来源追溯</h3><dl class="info-grid">
          <dt>归属商品</dt><dd>{{ bom.product ? `${bom.product.product_code} / ${bom.product.product_name}` : '-' }}</dd>
          <dt>关联SKU</dt><dd>{{ bom.sku ? `${bom.sku.sku_code} / ${bom.sku.sku_name}` : '-' }}</dd>
          <dt>产出Item</dt><dd>{{ bom.output_item ? `${bom.output_item.item_code} / ${bom.output_item.item_name}` : '-' }}</dd>
          <dt>计量单位</dt><dd>{{ bom.output_item && bom.output_item.unit ? bom.output_item.unit.unit_name : '-' }}</dd>
          <dt>来源商品</dt><dd>{{ bom.source_product ? bom.source_product.product_name : '-' }}</dd>
          <dt>来源SKU</dt><dd>{{ bom.source_sku ? bom.source_sku.sku_name : '-' }}</dd>
          <dt>来源标准BOM</dt><dd>{{ bom.source_standard_bom ? `${bom.source_standard_bom.bom_no} / ${bom.source_standard_bom.version}` : '-' }}</dd>
          <dt>描述</dt><dd>{{ bom.custom_description || '-' }}</dd>
        </dl></section>
        <section class="detail-card"><h3>BOM明细</h3><el-table :data="bom.items || []" border size="small">
          <el-table-column prop="line_no" label="行号" width="70" /><el-table-column prop="component_item_code" label="物料Item" width="135" /><el-table-column prop="component_item_name" label="物料名称" min-width="170" show-overflow-tooltip /><el-table-column prop="qty" label="用量" width="90" /><el-table-column label="单位" width="80"><template slot-scope="{row}">{{ row.unit ? row.unit.unit_name : '-' }}</template></el-table-column><el-table-column prop="loss_rate" label="损耗率(%)" width="100" /><el-table-column prop="fixed_qty" label="固定用量" width="95" /><el-table-column label="可替代" width="80"><template slot-scope="{row}">{{ row.replaceable ? '是' : '否' }}</template></el-table-column><el-table-column prop="remark" label="备注" min-width="130" show-overflow-tooltip />
        </el-table></section>
      </main>
      <aside>
        <section class="detail-card"><h3>版本历史</h3><div v-for="v in bom.version_history || []" :key="v.id" class="version-box" :class="{current:v.id===bom.id}"><b>{{ v.version }} <el-tag v-if="v.is_default" size="mini" type="success">默认</el-tag></b><span>{{ v.bom_no }}</span><em>{{ statusText(v.status) }} / {{ auditText(v.audit_status) }}</em></div></section>
        <section class="detail-card"><h3>操作日志</h3><ul class="log-list"><li v-for="log in bom.logs || []" :key="log.id"><b>{{ actionText(log.action) }}</b><span>{{ log.message }}</span><small>{{ log.created_at }}</small></li></ul></section>
        <section class="detail-card"><h3>备注</h3><p class="remark">{{ bom.remark || '无' }}</p></section>
      </aside>
    </div>
  </section>
</template>

<script>
import { getBom, submitBom, approveBom, rejectBom, activateBom, deactivateBom, setDefaultBom, copyBomVersion } from '@/api/erp/bom'
export default {
  data: () => ({ loading: false, bom: {} }),
  created() { this.load() },
  computed: {
    isDraftEditable() { return this.bom.status === 'draft' && !this.bom.submitted_at },
    isPendingAudit() { return this.bom.audit_status === 'pending' && Boolean(this.bom.submitted_at) },
    isApprovedInactive() { return this.bom.audit_status === 'approved' && this.bom.status !== 'active' && this.bom.status !== 'archived' },
    isActiveApproved() { return this.bom.status === 'active' && this.bom.audit_status === 'approved' },
    displayStatusText() { if (this.isPendingAudit) return '已提交'; if (this.bom.audit_status === 'approved' && this.bom.status !== 'active') return '已审核'; return this.statusText(this.bom.status) },
    displayAuditText() { if (this.isDraftEditable) return '未提交'; return this.auditText(this.bom.audit_status) },
    displayStatusType() { if (this.isPendingAudit) return 'warning'; if (this.bom.audit_status === 'approved' && this.bom.status !== 'active') return 'success'; return this.statusType(this.bom.status) },
    displayAuditType() { if (this.isDraftEditable) return 'info'; return this.auditType(this.bom.audit_status) }
  },
  methods: {
    async load() { this.loading = true; try { const { data } = await getBom(this.$route.params.id); this.bom = data } finally { this.loading = false } },
    async action(type) { const api = { submit: submitBom, approve: approveBom, reject: rejectBom, activate: activateBom, deactivate: deactivateBom, setDefault: setDefaultBom }[type]; const text = { submit: '提交审核', approve: '审核通过', reject: '驳回', activate: '启用', deactivate: '停用', setDefault: '设为默认' }[type]; await this.$confirm(`确认${text}？`, 'BOM操作确认', { type: 'warning' }); await api(this.bom.id); this.$message.success(`${text}成功`); this.load() },
    async copyVersion() { const { value } = await this.$prompt('请输入新版本号', '复制为新版本', { inputValue: this.nextVersion(this.bom.version) }); const { data } = await copyBomVersion(this.bom.id, { version: value }); this.$message.success('已复制为新版本草稿'); this.$router.push(`/bom/${data.data.id}/edit`) },
    typeText(v) { return ({ standard: '标准', custom: '定制', trial: '试制' })[v] || v }, statusText(v) { return ({ draft: '草稿', active: '启用', inactive: '已停用', archived: '已归档' })[v] || v }, auditText(v) { return ({ pending: '待审核', approved: '已审核', rejected: '已驳回' })[v] || v }, statusType(v) { return ({ active: 'success', inactive: 'warning', archived: 'info' })[v] || '' }, auditType(v) { return ({ approved: 'success', rejected: 'danger', pending: 'warning' })[v] || '' }, actionText(v) { return ({ create: '新增', update: '编辑', submit: '提交审核', approve: '审核通过', reject: '驳回', activate: '启用', deactivate: '停用', set_default: '设默认', copy_version: '复制新版本' })[v] || v }, dateOnly(v) { return v ? String(v).slice(0, 10) : '' }, nextVersion(v) { const m = String(v || 'V1.0').match(/^(.*?)(\d+)$/); return m ? `${m[1]}${Number(m[2]) + 1}` : `${v}-2` }
  }
}
</script>

<style scoped>
.bom-detail-page{padding:16px 18px 28px;background:#f6f8fa;min-height:calc(100vh - 52px);font-size:13px}.detail-head{min-height:50px;display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.detail-head h1{margin:0;font-size:18px}.detail-head p{margin:4px 0 0;color:#6b7280}.head-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.detail-layout{display:grid;grid-template-columns:minmax(780px,1fr) 330px;gap:14px}.detail-card{background:#fff;border:1px solid #e5e7eb;border-radius:4px;padding:14px;margin-bottom:14px}.detail-card h3{margin:0 0 12px;font-size:13px}.info-grid{display:grid;grid-template-columns:90px 1fr 90px 1fr;gap:10px;margin:0}.info-grid dt{color:#6b7280}.info-grid dd{margin:0;font-weight:600}.version-box{display:grid;gap:4px;border-left:3px solid #d1d5db;background:#f8faf9;padding:8px;margin-bottom:8px}.version-box.current{border-left-color:#10b981}.version-box em{font-style:normal;color:#6b7280}.log-list{list-style:none;padding:0;margin:0;display:grid;gap:8px}.log-list li{border-left:3px solid #10b981;background:#f8faf9;padding:8px;display:grid;gap:3px}.log-list small{color:#8b949e}.remark{margin:0;color:#4b5563;line-height:1.6}
</style>
