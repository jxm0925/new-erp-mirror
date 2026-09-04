<template>
  <section class="product-form-page">
    <header class="page-head">
      <div><h1>主数据中心 <span>/</span> 商品管理 <span>/</span> {{ isEdit ? '编辑商品' : '新增商品' }}</h1></div>
      <div class="head-actions"><el-button size="small" @click="back">返回列表</el-button><el-button size="small" @click="save('draft')">保存草稿</el-button><el-button size="small" type="success" :loading="saving" @click="save('save')">{{ isEdit ? '保存修改' : '保存并新增SKU' }}</el-button></div>
    </header>
    <div class="notice"><i class="el-icon-info" /> 在此维护商品基础信息；SKU 规格组合在右侧矩阵生成。商品本身不维护订单属性。</div>
    <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small" class="form-grid">
      <section class="form-card base-card"><h2>基础信息</h2><div class="two-col">
        <el-form-item label="商品编码" prop="product_code"><el-input v-model.trim="form.product_code" disabled placeholder="正在预生成"><template slot="append">系统预生成</template></el-input></el-form-item>
        <el-form-item label="商品名称" prop="product_name"><el-input v-model.trim="form.product_name" maxlength="160" show-word-limit /></el-form-item>
        <el-form-item label="商品类型" prop="product_type"><el-select v-model="form.product_type" class="full"><el-option label="标准商品" value="standard"/><el-option label="套装商品" value="bundle"/></el-select></el-form-item>
        <el-form-item label="商品分类"><el-select v-model="form.category_id" clearable class="full" placeholder="请选择商品分类"><el-option v-for="item in categories" :key="item.id" :label="item.category_name" :value="item.id"/></el-select></el-form-item>
        <el-form-item label="计量单位" prop="unit_id"><el-select v-model="form.unit_id" clearable class="full" placeholder="SKU 将继承此销售单位"><el-option v-for="item in units" :key="item.id" :label="item.unit_name" :value="item.id"/></el-select></el-form-item>
        <el-form-item label="品牌"><el-input v-model.trim="form.brand" placeholder="选填"/></el-form-item>
        <el-form-item label="型号"><el-input v-model.trim="form.model" placeholder="选填"/></el-form-item>
        <el-form-item label="产地"><el-input v-model.trim="form.origin" placeholder="选填"/></el-form-item>
      </div>
      <el-form-item label="状态"><el-radio-group v-model="form.status"><el-radio label="enabled">启用</el-radio><el-radio label="disabled">停用</el-radio></el-radio-group></el-form-item>
      <el-form-item label="商品描述"><el-input v-model="form.description" type="textarea" :rows="5" maxlength="500" show-word-limit placeholder="填写商品适用范围、型号说明等"/></el-form-item>
      </section>

      <aside class="side-stack">
        <section class="form-card image-card"><h2>商品图片</h2><el-upload class="product-upload" action="#" :show-file-list="false" :http-request="uploadImage" accept="image/jpeg,image/png,image/webp,image/gif"><img v-if="form.image" :src="form.image" class="cover"/><div v-else class="image-empty"><i class="el-icon-plus"/><span>上传商品主图</span></div></el-upload><p>上传至 OSS，支持 JPG / PNG / WebP，最大 5MB</p></section>
        <section class="form-card matrix-card"><h2>SKU矩阵 <small>在商品内生成</small></h2><p class="matrix-tip">规格值用逗号分隔；所有 SKU 统一继承商品计量单位并保存为草稿。</p>
          <div v-for="(dimension,index) in matrix.dimensions" :key="index" class="dimension-row"><el-input v-model.trim="dimension.name" placeholder="规格名，例如：颜色"/><el-input v-model="dimension.valuesText" placeholder="规格值，例如：黑,白"/><el-button type="text" class="danger" @click="removeDimension(index)">删除</el-button></div>
          <el-button size="mini" icon="el-icon-plus" @click="matrix.dimensions.push({ name:'', valuesText:'' })">添加规格维度</el-button>
          <div class="matrix-settings"><el-form-item label="编码前缀"><el-input v-model.trim="matrix.codePrefix"/></el-form-item><el-form-item label="统一销售价格"><el-input-number v-model="matrix.sale_price" :min="0" :precision="2" controls-position="right"/></el-form-item><el-form-item label="继承销售单位"><el-tag :type="form.unit_id?'success':'danger'">{{ currentUnitName }}</el-tag></el-form-item></div>
          <div class="preview-head"><b>SKU预览（{{ matrixRows.length }}个）</b><el-button type="text" size="mini" @click="refreshPreview">刷新预览</el-button></div>
          <el-table :data="matrixRows" size="mini" border max-height="255" empty-text="请填写规格维度和值"><el-table-column prop="spec_text" label="规格组合" min-width="125"/><el-table-column prop="sku_code" label="SKU编码（预览）" min-width="150"/><el-table-column label="销售单位" width="90"><template>{{ currentUnitName }}</template></el-table-column><el-table-column prop="sale_price" label="销售价" width="95"/></el-table>
        </section>
      </aside>
    </el-form>
    <footer class="footer-bar"><div><b>{{ form.product_name || '未命名商品' }}</b><span v-if="matrixRows.length"> 将生成 {{ matrixRows.length }} 个草稿 SKU</span></div><div><el-button size="small" @click="back">取消</el-button><el-button size="small" @click="save('draft')">保存草稿</el-button><el-button type="success" size="small" :loading="saving" @click="save('save')">{{ isEdit ? '保存修改' : '保存并新增SKU' }}</el-button></div></footer>
  </section>
</template>

<script>
import { getEntity, listEntity, saveEntity, uploadProductImage } from '../../../api/erp/master'
import { clearCreatePageReservation, reserveForCreatePage, reserveFreshDocumentNumber } from '../../../utils/documentNumberReservation'

const empty = () => ({ id:null, product_code:'', product_name:'', product_type:'standard', category_id:null, unit_id:null, brand:'', model:'', origin:'', image:'', description:'', status:'enabled' })

export default {
  name: 'ProductForm',
  data() {
    return {
      saving: false,
      loading: false,
      reservation: null,
      form: empty(),
      categories: [],
      units: [],
      matrix: { codePrefix:'SKU', sale_price:null, dimensions:[{name:'',valuesText:''}] },
      rules: {
        product_code: [{ required:true, message:'系统未取得商品编码，请重新打开新增页', trigger:'blur' }],
        product_name: [{ required:true, message:'请输入商品名称', trigger:'blur' }],
        product_type: [{ required:true, message:'请选择商品类型', trigger:'change' }],
        unit_id: [{ required:true, message:'请选择计量单位', trigger:'change' }]
      }
    }
  },
  computed: {
    isEdit() { return !!this.$route.params.id },
    currentUnitName() {
      const unit = this.units.find(item => Number(item.id) === Number(this.form.unit_id))
      return unit ? unit.unit_name : '请先选择商品计量单位'
    },
    matrixRows() {
      const dims = this.matrix.dimensions
        .map(d => ({ name:d.name.trim(), values:d.valuesText.split(/[,，]/).map(v => v.trim()).filter(Boolean) }))
        .filter(d => d.name && d.values.length)
      if (!dims.length || this.matrix.sale_price === null || this.matrix.sale_price === '') return []
      const group = (i, picked) => i >= dims.length ? [picked] : dims[i].values.flatMap(value => group(i + 1, [...picked, { name:dims[i].name, value }]))
      return group(0, []).map(combo => {
        const spec = combo.map(x => x.value).join(' / ')
        return { sku_code:'保存时系统预生成', sku_name:`${this.form.product_name || '商品'}-${spec}`, spec_text:spec, sale_price:this.matrix.sale_price }
      })
    }
  },
  async created() {
    await this.loadOptions()
    if (this.isEdit) await this.loadProduct()
    else await this.reserveProductNumber()
  },
  methods: {
    async reserveProductNumber() {
      try {
        this.reservation = await reserveForCreatePage('product', '/master/products/new')
        this.form.product_code = this.reservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || '商品编码预生成失败，请重新打开新增页')
      }
    },
    async loadOptions() {
      const [categories, units] = await Promise.all([
        listEntity('categories', { per_page:100, category_type:'product' }),
        listEntity('units', { per_page:100, status:'enabled' })
      ])
      this.categories = categories.data.data || []
      this.units = units.data.data || []
    },
    async loadProduct() {
      this.loading = true
      try {
        const { data } = await getEntity('products', this.$route.params.id)
        this.form = { ...empty(), ...data }
      } catch (e) {
        this.$message.error(e.userMessage || '商品不存在')
        this.back()
      } finally {
        this.loading = false
      }
    },
    removeDimension(index) {
      this.matrix.dimensions.splice(index, 1)
      if (!this.matrix.dimensions.length) this.matrix.dimensions.push({ name:'', valuesText:'' })
    },
    refreshPreview() { this.$forceUpdate() },
    async uploadImage(request) {
      try {
        const form = new FormData()
        form.append('image', request.file)
        const { data } = await uploadProductImage(form)
        this.form.image = data.data.url
        this.$message.success('商品图片已上传至 OSS')
      } catch (e) {
        this.$message.error(e.userMessage || '图片上传失败')
      }
    },
    back() { this.$router.push('/master/products') },
    async save(mode) {
      this.$refs.form.validate(async ok => {
        if (!ok) return
        if (mode === 'save' && !this.isEdit && this.matrix.dimensions.some(d => d.name || d.valuesText) && !this.matrixRows.length) {
          return this.$message.error('请补齐统一销售价格和规格值')
        }
        this.saving = true
        try {
          const productPayload = { ...this.form }
          if (!this.isEdit && this.reservation) {
            productPayload.reservation_token = this.reservation.reservation_token
            productPayload.creation_session_id = this.reservation.creation_session_id
          }
          const { data } = await saveEntity('products', productPayload)
          const product = data.data
          if (!this.isEdit) clearCreatePageReservation(this.reservation)

          if (mode === 'save' && this.matrixRows.length) {
            for (const row of this.matrixRows) {
              const skuReservation = await reserveFreshDocumentNumber('sku', '/master/products/new#sku-matrix')
              await saveEntity('skus', {
                product_id: product.id,
                ...row,
                sku_code: skuReservation.document_no,
                reservation_token: skuReservation.reservation_token,
                creation_session_id: skuReservation.creation_session_id,
                sales_unit_id: product.unit_id,
                order_line_type: 'physical',
                fulfillment_type: 'physical',
                is_sale_item: true,
                status: 'draft'
              })
            }
            this.$message.success(`商品已保存，并生成 ${this.matrixRows.length} 个草稿 SKU`)
          } else {
            this.$message.success('商品保存成功')
          }
          this.$router.push('/master/products')
        } catch (e) {
          this.$message.error(e.userMessage || '商品保存失败')
        } finally {
          this.saving = false
        }
      })
    }
  }
}
</script>

<style scoped>
.product-form-page{min-height:calc(100vh - 52px);padding:14px 18px 78px;background:#f6f8f9;color:#24313b;min-width:1080px}.page-head{height:42px;display:flex;justify-content:space-between;align-items:center}.page-head h1{margin:0;font-size:16px;font-weight:650}.page-head h1 span{margin:0 7px;color:#a5aeb7;font-weight:400}.head-actions{display:flex;gap:9px}.notice{padding:11px 14px;margin:7px 0 12px;background:#edf7ff;border:1px solid #d9ebf9;color:#617180;font-size:12px}.notice i{margin-right:6px;color:#3e9bde}.form-grid{display:grid;grid-template-columns:minmax(560px,1fr) minmax(480px,1.06fr);gap:12px;align-items:start}.form-card{border:1px solid #dfe6ea;border-radius:5px;background:#fff;padding:15px}.form-card h2{margin:0 0 14px;font-size:14px}.form-card h2 small{color:#85919a;font-size:11px;font-weight:400}.base-card{min-height:700px}.two-col{display:grid;grid-template-columns:1fr 1fr;gap:0 20px}.full{width:100%}.side-stack{display:grid;gap:12px}.image-card{min-height:225px}.image-card p,.matrix-tip{margin:8px 0 0;color:#84909a;font-size:11px}.product-upload{width:208px}.cover,.image-empty{width:208px;height:150px;object-fit:cover;border:1px dashed #cbd6dc;border-radius:4px}.image-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;color:#77848f;gap:9px}.image-empty i{font-size:26px}.matrix-card{min-height:462px}.dimension-row{display:grid;grid-template-columns:120px 1fr 38px;gap:8px;margin-bottom:8px}.danger{color:#df4a42}.matrix-settings{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:12px}.matrix-settings .el-form-item{margin:0}.matrix-settings .el-input-number{width:100%}.preview-head{display:flex;justify-content:space-between;align-items:center;margin:13px 0 7px;font-size:12px}.footer-bar{position:fixed;bottom:0;left:0;right:0;z-index:5;height:62px;padding:0 24px 0 218px;background:#fff;border-top:1px solid #dce3e7;display:flex;justify-content:space-between;align-items:center;color:#6d7982;font-size:12px}.footer-bar b{color:#2a3740;margin-right:6px}.footer-bar .el-button{margin-left:8px}@media(max-width:1240px){.product-form-page{min-width:940px}.form-grid{grid-template-columns:1fr 460px}.footer-bar{padding-left:18px}}
</style>
