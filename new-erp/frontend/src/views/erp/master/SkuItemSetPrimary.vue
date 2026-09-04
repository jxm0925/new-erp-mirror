<template>
  <section class="set-page" v-loading="loading">
    <header class="page-header"><h1>{{ current ? '更换默认Item' : '设置默认Item' }}</h1><div><el-button @click="back">← 返回列表</el-button><el-button @click="back">取消</el-button><el-button v-if="$can(current ? 'sku_item_relation.change' : 'sku_item_relation.set')" type="success" :loading="saving" @click="save">保存并立即生效</el-button></div></header>
    <div class="notice">ⓘ 实物SKU必须设置唯一、启用的默认Item；服务和无需发货SKU无需设置Item。</div>
    <main v-if="sku" class="body">
      <aside class="sku-card"><h3>SKU只读信息</h3><dl><dt>Product</dt><dd>{{ product }}</dd><dt>SKU编码</dt><dd>{{ sku.sku_code }}</dd><dt>SKU名称</dt><dd>{{ sku.sku_name }}</dd><dt>规格型号</dt><dd>{{ sku.spec_text || '—' }}</dd><dt>销售单位</dt><dd>{{ sku.sales_unit?.unit_name || '—' }}</dd><dt>订单行类型</dt><dd>实物</dd></dl></aside>
      <section class="setting-card"><h3>默认Item设置</h3>
        <div class="selection">
          <section class="item-card"><b>当前默认Item（旧Item）</b><dl v-if="current"><dt>Item编码</dt><dd>{{ current.item_code }}</dd><dt>Item名称</dt><dd>{{ current.item_name }}</dd><dt>规格型号</dt><dd>{{ current.spec_text || current.spec_model || '—' }}</dd><dt>Item类型</dt><dd>{{ itemType(current.item_type) }}</dd><dt>库存单位</dt><dd>{{ current.unit?.unit_name || '—' }}</dd><dt>状态</dt><dd><span>启用</span></dd></dl><p v-else>暂未设置</p></section>
          <span class="arrow"><i class="el-icon-right" /></span>
          <div class="new-item">
            <label>新默认Item<el-select v-model="form.item_id" filterable remote clearable :remote-method="searchItems" :loading="itemsLoading" placeholder="搜索Item编码、名称、规格型号" @change="selectItem"><el-option v-for="x in items" :key="x.id" :value="x.id" :label="`${x.item_code}｜${x.item_name}`"/></el-select></label>
            <div class="item-pager">
              <el-button size="mini" :disabled="itemPage <= 1 || itemsLoading" @click="changeItemPage(-1)">上一页</el-button>
              <span>第 {{ itemPage }} / {{ itemLastPage }} 页</span>
              <el-button size="mini" :disabled="itemPage >= itemLastPage || itemsLoading" @click="changeItemPage(1)">下一页</el-button>
            </div>
            <small>支持搜索、分页，仅可选择启用状态Item（共 {{ itemTotal }} 条）</small>
          </div>
          <section class="item-card chosen"><b>已选择的新默认Item</b><dl v-if="chosen"><dt>Item编码</dt><dd>{{ chosen.item_code }}</dd><dt>Item名称</dt><dd>{{ chosen.item_name }}</dd><dt>规格型号</dt><dd>{{ chosen.spec_text || chosen.spec_model || '—' }}</dd><dt>Item类型</dt><dd>{{ itemType(chosen.item_type) }}</dd><dt>库存单位</dt><dd>{{ chosen.unit?.unit_name || '—' }}</dd><dt>状态</dt><dd><span>启用</span></dd></dl><p v-else>等待选择</p></section>
        </div>
        <section class="fulfillment-conversion">
          <h3>履约换算</h3>
          <div class="conversion-grid">
            <label>销售单位（只读）<el-input :value="sku.sales_unit && (sku.sales_unit.symbol || sku.sales_unit.unit_name) || '—'" disabled /></label>
            <label>Item库存基本单位（只读）<el-input :value="chosen && chosen.unit && (chosen.unit.symbol || chosen.unit.unit_name) || '—'" disabled /></label>
            <label class="factor-field">履约数量（必填）<el-input-number v-model="form.factor" :min="0.00000001" :precision="8" :controls="false" /></label>
          </div>
          <div class="formula-preview" v-if="chosen"><i class="el-icon-info" /> 销售 1 {{ sku.sales_unit && (sku.sales_unit.symbol || sku.sales_unit.unit_name) }} = 履约 {{ number(form.factor) }} {{ chosen.unit && (chosen.unit.symbol || chosen.unit.unit_name) }} Item</div>
          <div class="example-preview" v-if="chosen">换算示例：销售10{{ sku.sales_unit && (sku.sales_unit.symbol || sku.sales_unit.unit_name) }}时，Item基本需求量为 <b>{{ number(Number(form.factor || 0) * 10) }}</b> {{ chosen.unit && (chosen.unit.symbol || chosen.unit.unit_name) }}（只读）</div>
        </section>
        <div class="form-row"><label>变更原因 <b>*</b><el-select v-model="form.change_reason" placeholder="请选择变更原因"><el-option v-for="x in reasons" :key="x" :label="x" :value="x"/></el-select></label><label>备注 <b v-if="form.change_reason === '其他'">*</b><el-input type="textarea" :rows="3" maxlength="200" show-word-limit v-model="form.remark" :placeholder="form.change_reason === '其他' ? '变更原因选择“其他”时必填' : '请输入备注（选填）'"/></label></div>
        <div class="effective">生效说明　保存后立即生效，生效时间由服务器记录</div><div class="warning">! 保存后，旧的默认关系将变为非活动状态；历史记录与历史订单数据保持不变。</div>
      </section>
      <aside class="check-card"><h3>保存前检查</h3><p :class="ok('physical')">● SKU订单行类型为实物</p><p :class="ok('selected')">● 新Item已启用</p><p :class="ok('different')">● {{ current ? '新Item或履约因子已变更' : '当前无旧Item，可首次设置' }}</p><p :class="ok('selected')">● 当前关系可以保留历史</p><p :class="ok('single')">● 不存在其他启用默认Item</p><p :class="form.factor > 0 ? 'pass' : 'fail'">● 履约因子大于0、单位精度符合规则</p></aside>
    </main>
  </section>
</template>
<script>
import { getDefaultSkuItemRelation, listEntity, setDefaultSkuItem } from '../../../api/erp/master'

export default {
  name: 'SkuItemSetPrimary',
  data () {
    return {
      loading: false,
      saving: false,
      itemsLoading: false,
      sku: null,
      current: null,
      currentRelation: null,
      activeRelationCount: 0,
      items: [],
      chosen: null,
      itemKeyword: '',
      itemPage: 1,
      itemPerPage: 10,
      itemTotal: 0,
      form: { item_id: null, factor: 1, change_reason: '首次设置', remark: '' },
      reasons: ['首次设置', '产品升级', '规格调整', '主数据修正', '原Item停用', '历史数据补全', '其他']
    }
  },
  computed: {
    product () { return this.sku?.product ? `${this.sku.product.product_code || '-'}｜${this.sku.product.product_name}` : '—' },
    itemLastPage () { return Math.max(1, Math.ceil(this.itemTotal / this.itemPerPage)) }
  },
  created () { this.load() },
  methods: {
    async load () {
      this.loading = true
      try {
        const detail = await getDefaultSkuItemRelation(this.$route.params.skuId)
        const d = detail.data.data
        this.sku = d.sku
        if (this.sku.line_type !== 'physical') {
          this.$message.warning('服务或无需发货SKU不允许设置默认Item')
          return this.back()
        }
        const active = (d.audit.relations || []).filter(x => x.status === 'active' && x.is_primary)
        this.activeRelationCount = active.length
        this.currentRelation = active[0] || null
        this.current = this.currentRelation?.item || null
        this.form.factor = Number(this.currentRelation?.qty || 1)
        this.form.change_reason = this.current ? '' : '首次设置'
        await this.fetchItems()
      } catch (e) {
        this.$message.error(e.userMessage || '页面加载失败')
      } finally { this.loading = false }
    },
    async searchItems (keyword) {
      this.itemKeyword = keyword || ''
      this.itemPage = 1
      await this.fetchItems()
    },
    async fetchItems () {
      this.itemsLoading = true
      try {
        const { data } = await listEntity('items', {
          page: this.itemPage,
          per_page: this.itemPerPage,
          status: 'enabled',
          keyword: this.itemKeyword
        })
        this.items = data.data || []
        this.itemTotal = Number(data.total || 0)
      } finally { this.itemsLoading = false }
    },
    async changeItemPage (step) {
      const target = this.itemPage + step
      if (target < 1 || target > this.itemLastPage) return
      this.itemPage = target
      this.form.item_id = null
      this.chosen = null
      await this.fetchItems()
    },
    selectItem () { this.chosen = this.items.find(x => x.id === this.form.item_id) || null },
    itemType (value) {
      return ({
        finished_product: '成品',
        semi_finished: '半成品',
        raw_material: '原材料',
        packaging: '包装物',
        service: '服务'
      })[value] || '—'
    },
    ok (name) {
      const good = name === 'physical' ||
        (name === 'selected' && !!this.chosen) ||
        (name === 'different' && (!this.current || this.current.id !== this.form.item_id || Math.abs(Number(this.currentRelation?.qty || 0) - Number(this.form.factor || 0)) > 0.00000001)) ||
        (name === 'single' && this.activeRelationCount <= 1)
      return good ? 'pass' : 'fail'
    },
    async save () {
      if (!this.form.item_id) return this.$message.warning('请选择新的默认Item')
      if (!(Number(this.form.factor) > 0)) return this.$message.warning('履约因子必须大于0')
      if (this.current && this.current.id === this.form.item_id && Math.abs(Number(this.currentRelation?.qty || 0) - Number(this.form.factor)) < 0.00000001) return this.$message.error('新Item和履约因子不能与当前关系完全相同')
      if (!this.form.change_reason) return this.$message.warning('请选择变更原因')
      if (this.form.change_reason === '其他' && !this.form.remark.trim()) return this.$message.warning('变更原因选择“其他”时必须填写备注')
      if (this.activeRelationCount > 1) return this.$message.error('当前存在多个启用默认Item，请先在完整性检查中修复')
      this.saving = true
      try {
        await setDefaultSkuItem(this.sku.id, this.form)
        this.$message.success('默认Item已保存并立即生效')
        this.$router.push(`/master/sku-item-relations/${this.sku.id}`)
      } catch (e) {
        this.$message.error(e.userMessage || '保存失败')
      } finally { this.saving = false }
    },
    number (value) { return Number(value || 0).toFixed(8).replace(/0+$/, '').replace(/\.$/, '') },
    back () { this.$router.push('/master/sku-item-relations') }
  }
}
</script>
<style scoped>.set-page{padding:22px;background:#f7f9fb;min-height:calc(100vh - 56px)}.page-header{display:flex;justify-content:space-between;align-items:center}.page-header h1{font-size:26px;margin:0}.page-header div{display:flex;gap:12px}.notice{margin:16px 0;padding:14px 18px;color:#2472d2;border:1px solid #9fc8ff;background:#eef7ff;border-radius:6px}.body{display:grid;grid-template-columns:286px 1fr 286px;gap:16px}.sku-card,.setting-card,.check-card{background:#fff;border:1px solid #e1e7ed;border-radius:7px;padding:18px}.sku-card h3,.setting-card h3,.check-card h3{margin:0 0 20px;font-size:18px}dl{display:grid;grid-template-columns:100px 1fr;gap:18px 8px;font-size:14px}dt{color:#556070}dd{margin:0;color:#273144}.selection{display:grid;grid-template-columns:240px 48px minmax(180px,1fr) 240px;gap:12px;align-items:center;border-bottom:1px solid #e8edf2;padding-bottom:22px}.item-card{border:1px solid #cfd8e3;border-radius:6px;padding:14px;min-height:190px}.item-card.chosen{border-color:#6dcc96;background:#fbfffc}.item-card dl{grid-template-columns:68px 1fr;gap:13px 7px;font-size:13px;margin-top:17px}.item-card span{color:#159447;border:1px solid #9ce0b6;background:#effaf2;padding:2px 6px;border-radius:4px}.arrow{text-align:center;font-size:36px;color:#9ba9b8}.new-item label{display:grid;gap:10px;font-weight:600}.new-item small{display:block;margin-top:9px;color:#8a96a4}.item-pager{display:flex;align-items:center;justify-content:space-between;gap:6px;margin-top:9px;color:#647184;font-size:12px}.item-pager .el-button{padding:6px 8px}.fulfillment-conversion{padding:16px 0;border-bottom:1px solid #e8edf2}.fulfillment-conversion h3{font-size:15px;margin:0 0 12px}.conversion-grid{display:grid;grid-template-columns:1fr 1fr 1.4fr;gap:14px}.conversion-grid label{display:grid;gap:8px}.factor-field .el-input-number{width:100%}.formula-preview{margin-top:12px;padding:12px;border:1px solid #9bdcb6;background:#f1fbf5;color:#187b44}.example-preview{margin-top:10px;padding:12px;border:1px solid #d5dce5;background:#f8fafc}.example-preview b{font-size:20px;color:#07883f}.form-row{display:grid;grid-template-columns:1fr 1.6fr;gap:28px;padding:18px 0}.form-row label{display:grid;gap:9px}.form-row b{color:#e33}.effective{border-top:1px solid #e8edf2;padding:14px 0;color:#596678}.warning{color:#9a5d05;background:#fff8e9;border:1px solid #f5ca82;padding:13px;border-radius:5px}.check-card p{padding:15px 0;border-bottom:1px solid #edf0f3;margin:0}.pass{color:#159447}.fail{color:#db3b2c}@media(max-width:1200px){.body{min-width:1100px}}</style>
