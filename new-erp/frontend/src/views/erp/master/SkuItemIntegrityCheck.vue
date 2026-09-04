<template><section class="audit-page"><header><div><h1>关系完整性检查结果</h1><p>只读审计 SKU 默认Item关系，检查不会自动修改任何数据</p></div><div><el-button @click="$router.push('/master/sku-item-relations')">← 返回关系列表</el-button><el-button v-if="$can('sku_item_relation.audit')" type="success" :loading="loading" @click="load">⟳ 重新检查</el-button></div></header><section class="cards"><div class="card blue"><small>已检查</small><b>{{summary.checked||0}}</b></div><div class="card green"><small>正常</small><b>{{summary.normal||0}}</b></div><div class="card orange"><small>待修复</small><b>{{summary.fix||0}}</b></div><div class="card purple"><small>无需Item</small><b>{{summary.none||0}}</b></div></section><section class="tabs"><el-radio-group v-model="tab" @change="load"><el-radio-button label="all">全部 {{summary.checked||0}}</el-radio-button><el-radio-button label="fix">待修复 {{summary.fix||0}}</el-radio-button><el-radio-button label="normal">正常 {{summary.normal||0}}</el-radio-button><el-radio-button label="not_required">无需Item {{summary.none||0}}</el-radio-button></el-radio-group></section><main><section class="table"><el-table v-loading="loading" :data="rows" border size="small"><el-table-column label="SKU编码" prop="sku.sku_code"/><el-table-column label="SKU名称" prop="sku.sku_name"/><el-table-column label="Product"><template slot-scope="{row}">{{row.product?.product_code||'—'}}｜{{row.product?.product_name||'—'}}</template></el-table-column><el-table-column label="订单行类型"><template slot-scope="{row}">{{type(row.sku.line_type)}}</template></el-table-column><el-table-column label="当前默认Item"><template slot-scope="{row}">{{row.audit.relations?.map(x=>x.item?.item_code).filter(Boolean).join('、')||'—'}}</template></el-table-column><el-table-column label="检查状态"><template slot-scope="{row}"><span :class="status(row.audit.check_status)">{{label(row.audit.check_status)}}</span></template></el-table-column><el-table-column label="异常原因" prop="audit.reason"/><el-table-column label="修复入口"><template slot-scope="{row}"><el-button v-if="row.audit.check_status==='missing' && $can('sku_item_relation.set')" type="text" @click="set(row)">设置默认Item</el-button><el-button v-else-if="row.audit.check_status==='item_disabled' && $can('sku_item_relation.change')" type="text" @click="set(row)">更换默认Item</el-button><el-button v-else-if="row.audit.check_status==='duplicate' && $can('sku_item_relation.repair')" type="text" @click="detail(row)">处理重复关系</el-button><el-button v-else-if="row.audit.check_status==='wrong_binding' && $can('sku_item_relation.repair')" type="text" @click="repair(row)">解除错误关系</el-button><span v-else>—</span></template></el-table-column><el-table-column label="检查时间" prop="audit.checked_at" width="158"/></el-table><div class="pager"><span>共 {{total}} 条</span><el-pagination background layout="sizes,prev,pager,next,jumper" :total="total" :current-page="page" :page-size="perPage" @current-change="go" @size-change="resize"/></div></section><aside><h3>检查口径</h3><ol><li>实物SKU缺少默认Item</li><li>实物SKU存在多个启用默认Item</li><li>默认Item已停用</li><li>服务SKU错误绑定Item</li><li>无需发货SKU错误绑定Item</li></ol><p>ⓘ 重新检查只读执行；修复必须由具有权限的人员明确确认。</p></aside></main></section></template>
<script>
import { auditDefaultSkuItemRelations, removeWrongSkuItemBinding } from '../../../api/erp/master'

export default {
  name: 'SkuItemIntegrityCheck',
  data () { return { loading: false, rows: [], page: 1, perPage: 20, total: 0, tab: 'all', summary: {} } },
  created () { this.load() },
  methods: {
    async load () {
      this.loading = true
      try {
        const { data } = await auditDefaultSkuItemRelations({ page: this.page, per_page: this.perPage, status: this.tab })
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
      } catch (e) {
        this.$message.error(e.userMessage || '检查失败')
      } finally { this.loading = false }
    },
    go (v) { this.page = v; this.load() },
    resize (v) { this.perPage = v; this.page = 1; this.load() },
    type (v) { return ({ physical: '实物', service: '服务', no_delivery: '无需发货' })[v] || '—' },
    label (v) { return ({ normal: '正常', missing: '待修复', duplicate: '待修复', item_disabled: '待修复', wrong_binding: '待修复', not_required: '无需Item' })[v] || '—' },
    status (v) { return ['normal', 'not_required'].includes(v) ? 'good' : 'bad' },
    set (r) { this.$router.push(`/master/sku-item-relations/${r.sku.id}/set-primary`) },
    detail (r) { this.$router.push(`/master/sku-item-relations/${r.sku.id}`) },
    async repair (r) {
      try {
        await this.$confirm('确认解除该服务/无需发货SKU的错误Item绑定？处理后立即生效并写入操作日志。', '解除错误关系', { type: 'warning' })
        const { value } = await this.$prompt('请输入本次修复说明', '修复说明', {
          inputPlaceholder: '例如：服务SKU不应绑定实物Item',
          inputValidator: value => !!String(value || '').trim() || '请填写修复说明'
        })
        await removeWrongSkuItemBinding(r.sku.id, { change_reason: '错误绑定修复', remark: String(value).trim() })
        this.$message.success('已解除错误绑定')
        this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '处理失败')
      }
    }
  }
}
</script>
<style scoped>.audit-page{padding:22px;background:#f8fafc;min-height:calc(100vh - 56px);min-width:1220px}header{display:flex;justify-content:space-between}h1{margin:0 0 7px;font-size:26px}header p{margin:0;color:#667085}.cards{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin:20px 0}.card{height:112px;border:1px solid #e4e9ef;background:#fff;border-radius:8px;padding:18px;display:grid}.card b{font-size:32px}.green b{color:#0c9b50}.blue b{color:#1976e9}.orange b{color:#ed7a10}.purple b{color:#7949dc}.tabs{background:#fff;border:1px solid #e4e9ef;border-radius:8px;padding:14px;margin-bottom:18px}main{display:grid;grid-template-columns:minmax(1050px,1fr) 280px;gap:20px}.table,aside{background:#fff;border:1px solid #e4e9ef;border-radius:8px;padding:14px}.table{overflow-x:auto}.table ::v-deep .el-table{min-width:1080px;color:#283548}.table ::v-deep .el-table__cell{padding:12px 0}.table ::v-deep .cell{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#283548}.pager{display:flex;justify-content:space-between;padding-top:14px;min-width:1080px}.good{color:#0f984b;background:#effaf2;border:1px solid #b4e5c6;border-radius:4px;padding:3px 7px}.bad{color:#e33a31;background:#fff0ef;border:1px solid #ffc8c3;border-radius:4px;padding:3px 7px}aside h3{margin-top:4px;font-size:19px}aside li{margin:17px 0;line-height:1.5}aside p{color:#2878d6;background:#f1f7ff;border:1px solid #a9cdfd;padding:12px;border-radius:4px}@media(max-width:1500px){.audit-page{overflow-x:auto}}</style>
