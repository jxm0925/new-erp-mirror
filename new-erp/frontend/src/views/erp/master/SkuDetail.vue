<template>
  <section class="sku-detail-page" v-loading="loading">
    <header class="page-head">
      <div class="head-title"><span class="back" @click="$router.push('/master/skus')">←</span><h1>SKU详情</h1><span class="sub">主数据与订单属性只读视图</span></div>
      <div><el-button size="small" @click="$router.push('/master/skus')">返回列表</el-button><el-button size="small" type="success" @click="$router.push('/master/skus/' + id + '/edit')">编辑 SKU</el-button></div>
    </header>

    <main v-if="sku" class="content-grid">
      <div class="main-column">
        <section class="card basic-card">
          <div class="card-title"><span>基础规格</span><el-tag size="mini" :type="sku.status === 'enabled' ? 'success' : sku.status === 'draft' ? 'warning' : 'info'">{{ statusText(sku.status) }}</el-tag></div>
          <div class="basic-body">
            <div class="image-wrap"><el-image v-if="imageUrl" :src="imageUrl" fit="cover" :preview-src-list="[imageUrl]" class="product-image"><div slot="error" class="image-empty">图片加载失败</div></el-image><div v-else class="product-image image-empty">暂无图片</div><span>SKU销售展示图</span></div>
            <dl class="detail-grid"><template v-for="field in fields"><dt :key="field.key + '-label'">{{ field.label }}</dt><dd :key="field.key">{{ field.value || '—' }}</dd></template></dl>
          </div>
          <el-alert :title="itemNotice" :type="noticeType" :closable="false" show-icon />
        </section>

        <section class="card">
          <div class="card-title">订单属性支持</div>
          <el-table :data="attrs" size="small" border><el-table-column prop="name" label="属性名称" /><el-table-column prop="support" label="订单行显示" /><el-table-column prop="required" label="是否必填" /><el-table-column prop="values" label="填写说明" /></el-table>
        </section>

        <section class="card">
          <div class="card-title">定制与交付要求</div>
          <div class="capabilities"><span :class="sku.allow_customized ? 'yes' : 'no'">普通定制：{{ sku.allow_customized ? '允许' : '不允许' }}</span><span :class="sku.allow_special_customized ? 'yes' : 'no'">特殊定制：{{ sku.allow_special_customized ? '允许' : '不允许' }}</span><template v-if="sku.allow_special_customized"><span>设计图纸：{{ sku.special_custom_drawing_required ? '特殊定制时必传' : '不强制' }}</span><span>客户技术协议：{{ sku.special_custom_agreement_required ? '特殊定制时必传' : '不强制' }}</span><span>配置说明：{{ sku.special_custom_description_required ? '特殊定制时必填' : '不强制' }}</span></template><span>交付前检验：{{ sku.delivery_inspection_required ? '需要' : '不需要' }}</span></div>
        </section>

        <section class="card">
          <div class="card-title">默认 Item 关系与历史</div>
          <el-table :data="relations" size="small" border empty-text="暂无 Item 关系"><el-table-column label="Item编码" min-width="140"><template slot-scope="{ row }">{{ row.item && row.item.item_code }}</template></el-table-column><el-table-column label="Item名称" min-width="160"><template slot-scope="{ row }">{{ row.item && row.item.item_name }}</template></el-table-column><el-table-column label="关系类型" width="100"><template slot-scope="{ row }">{{ row.is_primary ? '默认Item' : row.relation_type }}</template></el-table-column><el-table-column prop="effective_at" label="生效时间" min-width="150" /><el-table-column prop="expired_at" label="失效时间" min-width="150" /><el-table-column prop="operator_name" label="操作人" width="100" /><el-table-column label="状态" width="88"><template slot-scope="{ row }"><el-tag :type="row.status === 'active' ? 'success' : 'info'" size="mini">{{ row.status === 'active' ? '生效中' : '已失效' }}</el-tag></template></el-table-column></el-table>
        </section>
      </div>

      <aside class="preview-card">
        <div class="preview-title">订单行属性预览</div>
        <div class="preview-product"><el-image v-if="imageUrl" :src="imageUrl" fit="cover" /><div><b>{{ sku.product && sku.product.product_name }}</b><p>{{ sku.sku_name }}</p><span>{{ sku.spec_model || sku.spec_text || '—' }}</span></div></div>
        <div class="preview-row"><span>Product</span><b>{{ sku.product ? sku.product.product_code : '—' }}</b></div><div class="preview-row"><span>SKU</span><b>{{ sku.sku_code }}</b></div><div class="preview-row"><span>数量</span><b>请输入 {{ unitName }}</b></div><div class="preview-row"><span>销售单价</span><b>{{ money(sku.sale_price) }}</b></div><div class="preview-row"><span>备注</span><b>可填写</b></div>
        <div v-if="sku.electric_mode !== 'hidden'" class="preview-row"><span>电压 <em v-if="sku.electric_mode === 'required'">*</em></span><b>{{ modeText(sku.electric_mode) }}</b></div><div v-if="sku.need_pump_mode !== 'hidden'" class="preview-row"><span>原水泵控制 <em v-if="sku.need_pump_mode === 'required'">*</em></span><b>{{ modeText(sku.need_pump_mode) }}</b></div><div v-if="sku.allow_customized" class="preview-row"><span>普通定制</span><b>订单行可选择</b></div><template v-if="sku.allow_special_customized"><div class="preview-row"><span>特殊定制</span><b>订单行可选择</b></div><div v-if="sku.special_custom_drawing_required" class="preview-row"><span>设计图纸 <em>*</em></span><b>特殊定制时必传</b></div><div v-if="sku.special_custom_agreement_required" class="preview-row"><span>客户技术协议 <em>*</em></span><b>特殊定制时必传</b></div><div v-if="sku.special_custom_description_required" class="preview-row"><span>配置说明 <em>*</em></span><b>特殊定制时必填</b></div></template><div v-if="sku.delivery_inspection_required" class="preview-row"><span>交付前检验</span><b>需要</b></div>
      </aside>
    </main>
  </section>
</template>

<script>
import { getEntity, listRelations } from '../../../api/erp/master'
import { legacyMediaUrl } from '../../../utils/legacyMedia'
export default { name: 'SkuDetail', data () { return { loading: false, sku: null, relations: [] } }, computed: { id () { return this.$route.params.id }, imageUrl () { return this.sku ? legacyMediaUrl(this.sku.image || (this.sku.product && this.sku.product.image)) : '' }, unitName () { return (this.sku.sales_unit && this.sku.sales_unit.unit_name) || this.sku.sales_unit_snapshot || '—' }, fields () { if (!this.sku) return []; return [{ key: 'sku_code', label: 'SKU编码', value: this.sku.sku_code }, { key: 'sku_name', label: 'SKU名称', value: this.sku.sku_name }, { key: 'product', label: '所属Product', value: this.sku.product ? `${this.sku.product.product_code}｜${this.sku.product.product_name}` : '' }, { key: 'spec', label: '规格型号', value: this.sku.spec_model || this.sku.spec_text }, { key: 'unit', label: '销售单位', value: this.unitName }, { key: 'type', label: '订单行类型', value: this.typeText(this.sku.line_type || this.sku.order_line_type) }, { key: 'sellable', label: '允许销售', value: this.sku.is_sellable ? '是' : '否' }, { key: 'sale_price', label: '默认销售价格', value: this.money(this.sku.sale_price) } ] }, valid () { return this.relations.some(row => row.status === 'active' && row.is_primary && row.item && row.item.status === 'enabled') }, lineType () { return this.sku.line_type || this.sku.order_line_type }, noticeType () { return this.lineType !== 'physical' ? 'info' : this.valid ? 'success' : 'warning' }, itemNotice () { return this.lineType !== 'physical' ? '当前订单行类型无需绑定Item。' : this.valid ? '当前SKU已设置有效默认Item。' : '当前实物SKU尚未设置有效默认Item，暂不可启用或进入库存、BOM和生产履约。' }, attrs () { const map = value => ({ hidden: ['不显示', '否', '—'], optional: ['显示', '否', '订单行可选填写'], required: ['显示', '是', '订单确认前必填'] })[value] || ['不显示', '否', '—']; const electric = map(this.sku.electric_mode); const pump = map(this.sku.need_pump_mode); return [{ name: '电压', support: electric[0], required: electric[1], values: electric[2] }, { name: '原水泵控制', support: pump[0], required: pump[1], values: this.sku.need_pump_mode === 'hidden' ? '—' : '需要 / 不需要' }] } }, async created () { this.loading = true; try { const [sku, relations] = await Promise.all([getEntity('skus', this.id), listRelations({ sku_id: this.id, per_page: 100 })]); this.sku = sku.data; this.relations = relations.data.data || [] } catch (error) { this.$message.error(error.userMessage || 'SKU详情加载失败') } finally { this.loading = false } }, methods: { typeText: value => ({ physical: '实物', service: '服务', no_delivery: '无需发货'})[value] || value, modeText: value => value === 'required' ? '必填' : '可选', statusText: value => ({ draft: '草稿', enabled: '启用', disabled: '停用' })[value] || value, money: value => value === null || value === undefined || value === '' ? '—' : `¥${Number(value).toFixed(2)}` } }
</script>

<style scoped>
.sku-detail-page{min-height:calc(100vh - 52px);padding:18px;background:#f6f8f8}.page-head,.head-title{display:flex;align-items:center;gap:10px}.page-head{justify-content:space-between}.back{cursor:pointer;color:#334155;font-size:22px}.page-head h1{margin:0;color:#1f2d3d;font-size:18px}.sub{color:#8a96a3;font-size:12px}.content-grid{display:grid;grid-template-columns:minmax(650px,1fr) 330px;gap:12px;margin-top:14px}.card,.preview-card{padding:16px;margin-bottom:12px;background:#fff;border:1px solid #e4e9eb;border-radius:3px}.card-title,.preview-title{display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;color:#243542;font-size:14px;font-weight:700}.basic-body{display:flex;gap:20px;margin-bottom:16px}.image-wrap{flex:0 0 130px;text-align:center;color:#82909b;font-size:12px}.product-image{width:120px;height:120px;border:1px solid #e4e9eb;border-radius:4px}.image-empty{display:flex;align-items:center;justify-content:center;color:#9aa6b2;background:#f3f5f7}.detail-grid{flex:1;display:grid;grid-template-columns:100px minmax(180px,1fr) 100px minmax(180px,1fr);gap:12px 10px;margin:0;font-size:13px}.detail-grid dt{color:#7d8994}.detail-grid dd{margin:0;color:#263640}.capabilities{display:flex;flex-wrap:wrap;gap:8px}.capabilities span{padding:5px 8px;background:#f5f7f9;border-radius:3px;color:#526477;font-size:12px}.capabilities .yes{color:#16865a;background:#ecf8f2}.capabilities .no{color:#8693a0}.preview-card{height:max-content}.preview-product{display:flex;gap:10px;padding-bottom:14px;border-bottom:1px solid #edf0f2}.preview-product .el-image{flex:0 0 58px;width:58px;height:58px;border:1px solid #e4e9eb}.preview-product p{margin:5px 0;color:#465967;font-size:12px}.preview-product span{color:#83909c;font-size:12px}.preview-row{display:flex;justify-content:space-between;gap:10px;padding:10px 0;color:#687581;font-size:13px;border-bottom:1px solid #f0f2f4}.preview-row b{color:#2b3e4a;font-weight:500;text-align:right}.preview-row em{color:#e8574f;font-style:normal}@media(max-width:1180px){.content-grid{grid-template-columns:minmax(560px,1fr) 280px}.detail-grid{grid-template-columns:90px 1fr}}
</style>
