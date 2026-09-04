<template>
  <section class="pd-page" :class="{ 'drawer-open': drawerVisible }">
    <div class="pd-workspace">
      <div class="pd-head">
        <div>
          <h1>商品管理 <em>{{ total }}</em></h1>
          <p>商品是销售展示对象，SKU 承接订单履约，Item 承接采购、库存、生产和成本。</p>
        </div>
        <div class="pd-actions">
          <el-button size="small" icon="el-icon-upload2" @click="$router.push('/master/imports')">导入商品</el-button>
          <el-button size="small" type="success" icon="el-icon-plus" @click="openProductCreate">新增商品</el-button>
        </div>
      </div>

      <div class="pd-filter">
        <el-input v-model="filters.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="请输入商品编码/名称" @keyup.enter.native="applyFilters" @clear="applyFilters" />
        <el-select v-model="filters.category_id" size="small" clearable placeholder="分类" @change="applyFilters">
          <el-option v-for="c in categories" :key="c.id" :label="c.category_name" :value="c.id" />
        </el-select>
        <el-select v-model="filters.status" size="small" clearable placeholder="状态" @change="applyFilters">
          <el-option label="启用" value="enabled" />
          <el-option label="停用" value="disabled" />
        </el-select>
      </div>

      <div class="pd-table-card">
        <el-table
          v-loading="loading"
          :data="products"
          size="mini"
          border
          row-key="id"
          :expand-row-keys="expandedKeys"
          :row-class-name="rowClass"
          empty-text="暂无商品，请先新增商品或导入旧数据。"
          @row-click="openProductDetail"
          @expand-change="onExpandChange"
        >
          <el-table-column type="expand" width="34">
            <template slot-scope="{ row }">
              <div class="sku-expand">
                <div class="sku-expand-head">
                  <strong>SKU 列表（{{ skuTotal(row) }}）</strong>
                  <div>
                    <el-button size="mini" icon="el-icon-plus" @click.stop="openSkuCreate(row)">单独新增SKU</el-button>
                    <el-button size="mini" icon="el-icon-s-grid" @click.stop="openSkuMatrix(row)">生成SKU矩阵</el-button>
                  </div>
                </div>
                <el-table v-loading="skuPage(row).loading" :data="skuList(row)" size="mini" border empty-text="暂无 SKU" @row-click="openSkuEdit(row, $event)">
                  <el-table-column prop="sku_code" label="SKU编码" min-width="110" />
                  <el-table-column prop="spec_text" label="规格型号" min-width="140">
                    <template slot-scope="{ row: sku }">{{ sku.spec_text || sku.sku_name }}</template>
                  </el-table-column>
                  <el-table-column label="计量单位" min-width="82">
                    <template slot-scope="{ row: sku }">{{ row.unit ? row.unit.unit_name : '-' }}</template>
                  </el-table-column>
                  <el-table-column label="销售状态" min-width="82">
                    <template slot-scope="{ row: sku }"><span class="pd-dot" :class="sku.status">{{ statusText(sku.status) }}</span></template>
                  </el-table-column>
                  <el-table-column label="关联物料数" min-width="96" align="center">
                    <template slot-scope="{ row: sku }">{{ relationList(sku).length }}</template>
                  </el-table-column>
                  <el-table-column label="更新时间" min-width="140">
                    <template slot-scope="{ row: sku }">{{ formatDate(sku.updated_at) }}</template>
                  </el-table-column>
                  <el-table-column label="操作" width="174">
                    <template slot-scope="{ row: sku }">
                      <el-button type="text" size="mini" @click.stop="openSkuDetail(row, sku)">详情</el-button>
                      <el-button type="text" size="mini" @click.stop="openSkuEdit(row, sku)">编辑</el-button>
                      <el-button v-if="sku.status === 'enabled'" type="text" size="mini" class="danger-link" @click.stop="disableSku(sku)">停用</el-button>
                      <el-button v-else-if="sku.status === 'disabled'" type="text" size="mini" class="success-link" @click.stop="disableSku(sku)">启用</el-button>
                      <el-button v-if="sku.status !== 'enabled'" type="text" size="mini" class="danger-link" @click.stop="deleteSku(sku)">删除</el-button>
                    </template>
                  </el-table-column>
                </el-table>
                <div v-if="skuTotal(row) > 0" class="sku-pagination">
                  <span>共 {{ skuTotal(row) }} 条</span>
                  <el-pagination small layout="prev, pager, next, sizes" :current-page="skuPage(row).page" :page-size="skuPage(row).per_page" :page-sizes="[5,10,20]" :total="skuTotal(row)" @current-change="changeSkuPage(row, $event)" @size-change="changeSkuPageSize(row, $event)" />
                </div>
              </div>
            </template>
          </el-table-column>
          <el-table-column prop="product_code" label="商品编码" min-width="110" />
          <el-table-column prop="product_name" label="商品名称" min-width="180" />
          <el-table-column label="分类" min-width="110">
            <template slot-scope="{ row }">{{ row.category ? row.category.category_name : '-' }}</template>
          </el-table-column>
          <el-table-column label="SKU数量" min-width="80" align="center">
            <template slot-scope="{ row }">{{ skuTotal(row) }}</template>
          </el-table-column>
          <el-table-column label="状态" min-width="78">
            <template slot-scope="{ row }"><span class="pd-dot" :class="row.status">{{ statusText(row.status) }}</span></template>
          </el-table-column>
          <el-table-column label="更新时间" min-width="140">
            <template slot-scope="{ row }">{{ formatDate(row.updated_at) }}</template>
          </el-table-column>
          <el-table-column label="操作" width="174">
            <template slot-scope="{ row }">
              <el-button type="text" size="mini" @click.stop="openProductDetail(row)">详情</el-button>
              <el-button type="text" size="mini" @click.stop="openProductEdit(row)">编辑</el-button>
              <el-button type="text" size="mini" :class="row.status === 'enabled' ? 'danger-link' : 'success-link'" @click.stop="disableProduct(row)">{{ row.status === 'enabled' ? '停用' : '启用' }}</el-button>
              <el-button v-if="row.status !== 'enabled'" type="text" size="mini" class="danger-link" @click.stop="deleteProduct(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="pd-pagination">
          <span>共 {{ total }} 条</span>
          <el-pagination small layout="prev, pager, next, sizes" :current-page="filters.page" :page-size="filters.per_page" :page-sizes="[10,20,50]" :total="total" @current-change="changePage" @size-change="changePageSize" />
        </div>
      </div>
    </div>

    <aside v-if="drawerVisible" class="pd-drawer">
      <div class="drawer-head">
        <h2>{{ drawerTitle }}</h2>
        <i class="el-icon-close" @click="drawerVisible=false" />
      </div>

      <div class="drawer-body" v-if="drawerMode === 'product-detail'">
        <section class="drawer-card">
          <h3>基础信息 <i class="el-icon-arrow-up" /></h3>
          <dl>
            <dt>商品编码</dt><dd>{{ selectedProduct.product_code }}</dd>
            <dt>商品名称</dt><dd>{{ selectedProduct.product_name }}</dd>
            <dt>分类</dt><dd>{{ selectedProduct.category ? selectedProduct.category.category_name : '-' }}</dd>
            <dt>计量单位</dt><dd>{{ selectedProduct.unit ? selectedProduct.unit.unit_name : '-' }}</dd>
            <dt>型号</dt><dd>{{ selectedProduct.model || '-' }}</dd>
            <dt>状态</dt><dd><span class="pd-dot" :class="selectedProduct.status">{{ statusText(selectedProduct.status) }}</span></dd>
            <dt>创建时间</dt><dd>{{ formatDate(selectedProduct.created_at) }}</dd>
            <dt>更新时间</dt><dd>{{ formatDate(selectedProduct.updated_at) }}</dd>
            <dt>描述</dt><dd>{{ selectedProduct.description || '适用于销售展示与 SKU 聚合管理' }}</dd>
          </dl>
        </section>

        <section class="drawer-card">
          <h3>关系结构 <i class="el-icon-arrow-up" /></h3>
          <div class="tree-line">
            <b>Product 商品</b>
            <span>{{ selectedProduct.product_code }}　{{ selectedProduct.product_name }}</span>
          </div>
          <div class="tree-line child">
            <b>SKU 规格</b>
            <span v-for="sku in skuList(selectedProduct)" :key="sku.id">{{ sku.sku_code }}　{{ sku.spec_text || sku.sku_name }}</span>
          </div>
          <div class="tree-line child item">
            <b>Item 物料（件）</b>
            <span>共 {{ itemCount(selectedProduct) }} 个</span>
          </div>
        </section>

      </div>

      <el-form v-else-if="drawerMode.indexOf('product-') === 0" ref="productForm" :model="productForm" :rules="productRules" label-position="top" size="small" class="drawer-body drawer-form">
        <el-form-item label="商品编码" prop="product_code"><el-input v-model="productForm.product_code" :disabled="drawerMode==='product-edit'" /></el-form-item>
        <el-form-item label="商品名称" prop="product_name"><el-input v-model="productForm.product_name" /></el-form-item>
        <el-form-item label="商品类型" prop="product_type">
          <el-select v-model="productForm.product_type" class="full"><el-option label="标准商品" value="standard" /><el-option label="套装商品" value="bundle" /></el-select>
        </el-form-item>
        <el-form-item label="分类"><el-select v-model="productForm.category_id" clearable class="full"><el-option v-for="c in categories" :key="c.id" :label="c.category_name" :value="c.id" /></el-select></el-form-item>
        <el-form-item label="计量单位"><el-select v-model="productForm.unit_id" clearable class="full"><el-option v-for="u in units" :key="u.id" :label="u.unit_name" :value="u.id" /></el-select></el-form-item>
        <el-form-item label="型号"><el-input v-model="productForm.model" /></el-form-item>
        <el-form-item label="状态"><el-radio-group v-model="productForm.status"><el-radio label="enabled">启用</el-radio><el-radio label="disabled">停用</el-radio></el-radio-group></el-form-item>
        <el-form-item label="描述"><el-input v-model="productForm.description" type="textarea" :rows="4" /></el-form-item>
      </el-form>

      <div v-else-if="drawerMode === 'sku-matrix'" class="drawer-body drawer-form matrix-body">
        <section class="matrix-card">
          <h3>规格维度</h3>
          <p>用逗号分隔规格值，系统会按笛卡尔积生成 SKU。</p>
          <div v-for="(dim,index) in skuMatrix.dimensions" :key="index" class="matrix-dim">
            <el-input v-model="dim.name" size="small" placeholder="规格名，如颜色" />
            <el-input v-model="dim.valuesText" size="small" placeholder="规格值，如黑,白,红" />
            <el-button type="text" class="danger-link" @click="skuMatrix.dimensions.splice(index,1)">删除</el-button>
          </div>
          <el-button size="mini" icon="el-icon-plus" @click="addSkuDimension">新增规格维度</el-button>
        </section>
        <section class="matrix-card">
          <h3>生成规则</h3>
          <el-form label-width="82px" size="small">
            <el-form-item label="编码前缀"><el-input v-model="skuMatrix.codePrefix" /></el-form-item>
            <el-form-item label="销售价"><el-input-number v-model="skuMatrix.sale_price" :min="0" :precision="2" controls-position="right" /></el-form-item>
            <el-form-item label="销售单位"><el-tag size="small" :type="selectedProduct.unit_id ? 'success' : 'danger'">{{ selectedProduct.unit && selectedProduct.unit.unit_name || '请先维护商品计量单位' }}</el-tag><span class="matrix-hint">生成SKU统一继承商品计量单位</span></el-form-item>
            <el-form-item label="生成状态"><el-tag size="small" type="warning">统一保存为草稿</el-tag><span class="matrix-hint">实物SKU需在资料补全页绑定默认Item后再启用</span></el-form-item>
          </el-form>
        </section>
        <section class="matrix-card">
          <h3>矩阵预览（{{ skuMatrixRows.length }} 个 SKU）</h3>
          <el-table :data="skuMatrixRows" size="mini" border max-height="260" empty-text="请先维护规格维度和值">
            <el-table-column prop="sku_code" label="SKU编码" min-width="118" />
            <el-table-column prop="sku_name" label="SKU名称" min-width="130" show-overflow-tooltip />
            <el-table-column prop="spec_text" label="规格组合" min-width="120" show-overflow-tooltip />
            <el-table-column prop="sale_price" label="销售价" width="76" />
            <el-table-column label="生成状态" width="86" fixed="right">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="row.can_generate ? 'success' : 'warning'" effect="plain">{{ row.preview_status }}</el-tag>
              </template>
            </el-table-column>
          </el-table>
        </section>
      </div>

      <el-form v-else ref="skuForm" :model="skuForm" :rules="skuRules" label-position="top" size="small" class="drawer-body drawer-form">
        <el-form-item label="所属商品"><el-input :value="selectedProduct.product_name" disabled /></el-form-item>
        <el-form-item label="SKU编码" prop="sku_code"><el-input v-model="skuForm.sku_code" disabled placeholder="正在预生成"><template slot="append">系统预生成</template></el-input></el-form-item>
        <el-form-item label="SKU名称" prop="sku_name"><el-input v-model="skuForm.sku_name" /></el-form-item>
        <el-form-item label="规格型号"><el-input v-model="skuForm.spec_text" /></el-form-item>
        <el-form-item label="销售单位"><el-select v-model="skuForm.sales_unit_id" class="full" :disabled="skuForm.sales_unit_locked"><el-option v-for="unit in units" :key="unit.id" :label="`${unit.unit_code || ''} ${unit.unit_name}`.trim()" :value="unit.id" /></el-select><small v-if="skuForm.sales_unit_locked" class="field-tip">已有已确认订单，销售单位不可修改</small></el-form-item>
        <el-form-item label="销售价格"><el-input-number v-model="skuForm.sale_price" :min="0" :precision="2" controls-position="right" class="full" /></el-form-item>
        <el-form-item label="SKU图片">
          <div class="sku-image-editor">
            <el-image v-if="skuForm.image" :src="skuImageUrl" fit="cover" :preview-src-list="[skuImageUrl]" />
            <div v-else class="sku-image-empty"><i class="el-icon-picture-outline" /><span>暂无图片</span></div>
            <div><el-upload action="#" :show-file-list="false" accept="image/jpeg,image/png,image/webp,image/gif" :http-request="uploadSkuBasicImage"><el-button size="mini" icon="el-icon-upload2">{{ skuForm.image ? '替换图片' : '上传图片' }}</el-button></el-upload><el-button v-if="skuForm.image" type="text" size="mini" class="danger-link" @click="skuForm.image=''">清除图片</el-button><p class="field-tip">上传至 OSS，最大 5MB</p></div>
          </div>
        </el-form-item>
      </el-form>

      <div class="drawer-footer">
        <el-button size="small" @click="drawerVisible=false">取消</el-button>
        <template v-if="drawerMode === 'product-detail'">
          <el-button size="small" @click="openProductEdit(selectedProduct)">编辑商品</el-button>
          <el-button size="small" @click="openSkuCreate(selectedProduct)">单独新增SKU</el-button>
          <el-button size="small" type="success" @click="openSkuMatrix(selectedProduct)">生成SKU矩阵</el-button>
        </template>
        <el-button v-else size="small" type="success" :loading="saving" @click="saveDrawer">{{ drawerMode === 'sku-matrix' ? '批量生成' : '保存' }}</el-button>
      </div>
    </aside>
  </section>
</template>

<script>
import { listEntity, getEntity, saveEntity, disableEntity, enableEntity, deleteEntity, uploadSkuImage } from '../../../api/erp/master'
import { reserveFreshDocumentNumber } from '../../../utils/documentNumberReservation'
import { legacyMediaUrl } from '../../../utils/legacyMedia'

const emptyProduct = () => ({ id: null, product_code: '', product_name: '', product_type: 'standard', category_id: null, unit_id: null, model: '', description: '', status: 'enabled' })
const emptySku = () => ({ id: null, product_id: null, sku_code: '', sku_name: '', spec_text: '', sale_price: 0, reference_cost: 0, product_structure_type: 'single', production_policy: 'stock', fulfillment_type: 'physical', is_customizable: false, is_need_production: false, is_need_bom: false, is_sale_item: true, status: 'enabled' })

export default {
  name: 'ProductList',
  data() {
    return {
      loading: false, saving: false, drawerVisible: false, drawerMode: 'product-detail',
      products: [], categories: [], units: [], skuPages: {},
      selectedProduct: {}, selectedSku: {}, expandedKeys: [],
      filters: { keyword: '', category_id: '', status: '', page: 1, per_page: 10 }, total: 0,
      productForm: emptyProduct(), skuForm: emptySku(),
      skuMatrix: { codePrefix: '', sale_price: 0, status: 'enabled', dimensions: [] },
      productRules: { product_code: [{ required: true, message: '请输入商品编码', trigger: 'blur' }], product_name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }], product_type: [{ required: true, message: '请选择商品类型', trigger: 'change' }] },
      skuRules: { sku_code: [{ required: true, message: '请输入 SKU 编码', trigger: 'blur' }], sku_name: [{ required: true, message: '请输入 SKU 名称', trigger: 'blur' }] }
    }
  },
  computed: {
    drawerTitle() {
      return { 'product-detail': this.selectedProduct.product_name || '商品详情', 'product-create': '新增商品', 'product-edit': '编辑商品', 'sku-create': '新增 SKU', 'sku-edit': '编辑 SKU', 'sku-matrix': 'SKU矩阵生成' }[this.drawerMode] || '商品详情'
    },
    skuImageUrl() { return legacyMediaUrl(this.skuForm.image) },
    filteredProducts() { return this.products },
    pagedProducts() { return this.products },
    skuMatrixRows() {
      if (this.drawerMode !== 'sku-matrix') return []
      const dims = this.skuMatrix.dimensions
        .map(d => ({ name: d.name.trim(), values: d.valuesText.split(/[,，]/).map(v => v.trim()).filter(Boolean) }))
        .filter(d => d.name && d.values.length)
      if (!dims.length) return []
      const combine = (index, picked) => {
        if (index >= dims.length) return [picked]
        return dims[index].values.flatMap(v => combine(index + 1, [...picked, { name: dims[index].name, value: v }]))
      }
      const existedCodes = new Set(this.skuList(this.selectedProduct).map(s => String(s.sku_code || '').toUpperCase()))
      const existedSpecs = new Set(this.skuList(this.selectedProduct).map(s => this.normalSpec(s.spec_text || s.sku_name)))
      const seenCodes = new Set()
      const seenSpecs = new Set()
      return combine(0, []).map((combo, index) => {
        const spec = combo.map(x => x.value).join(' / ')
        const suffix = combo.map(x => this.shortSpec(x.value)).join('-') || String(index + 1).padStart(3, '0')
        const code = `${this.skuMatrix.codePrefix}-${suffix}`.toUpperCase()
        const specKey = this.normalSpec(spec)
        const duplicatedInPreview = seenCodes.has(code) || seenSpecs.has(specKey)
        const exists = existedCodes.has(code) || existedSpecs.has(specKey)
        seenCodes.add(code)
        seenSpecs.add(specKey)
        return {
          product_id: this.selectedProduct.id,
          sku_code: code,
          sku_name: `${this.selectedProduct.product_name}-${spec}`,
          spec_model: spec,
          sale_price: this.skuMatrix.sale_price,
          sales_unit_id: this.selectedProduct.unit_id || null,
          line_type: 'physical',
          is_sellable: true,
          status: 'draft',
          can_generate: !exists && !duplicatedInPreview,
          preview_status: exists ? '已存在' : duplicatedInPreview ? '重复' : '可生成'
        }
      })
    }
  },
  async created() {
    await this.fetchAll()
    await this.openRequestedSkuEditor()
  },
  watch: {
    filteredProducts(list) {
      if (!list.length) {
        this.selectedProduct = {}
        this.selectedSku = {}
        this.expandedKeys = []
        if (['product-detail', 'sku-matrix', 'sku-edit', 'sku-create'].includes(this.drawerMode)) this.drawerVisible = false
        return
      }
      if (this.selectedProduct.id && !list.some(p => Number(p.id) === Number(this.selectedProduct.id))) {
        this.selectedProduct = {}
        this.selectedSku = {}
        this.expandedKeys = []
        if (['product-detail', 'sku-matrix', 'sku-edit', 'sku-create'].includes(this.drawerMode)) this.drawerVisible = false
      }
    }
  },
  methods: {
    async openRequestedSkuEditor() {
      const skuId = Number(this.$route.query.edit_sku || 0)
      if (!skuId) return
      try {
        const skuResponse = await getEntity('skus', skuId)
        const sku = skuResponse.data
        let product = this.products.find(item => Number(item.id) === Number(sku.product_id)) || sku.product
        if (!product && sku.product_id) {
          const productResponse = await getEntity('products', sku.product_id)
          product = productResponse.data
        }
        if (!product) throw new Error('SKU 所属商品不存在')
        this.openSkuEdit(product, sku)
        this.$router.replace('/master/products')
      } catch (e) {
        this.$message.error(e.userMessage || e.message || 'SKU 基础信息加载失败')
        this.$router.replace('/master/products')
      }
    },
    async fetchAll() {
      this.loading = true
      try {
        const [products, categories, units] = await Promise.all([
          listEntity('products', { ...this.filters }),
          listEntity('categories', { per_page: 100, category_type: 'product' }),
          listEntity('units', { per_page: 100 })
        ])
        this.products = products.data.data || []
        this.total = products.data.total || 0
        this.categories = categories.data.data || []
        this.units = units.data.data || []
        if (!this.selectedProduct.id && this.products.length) {
          this.expandedKeys = [this.products[0].id]
          this.openProductDetail(this.products[0])
        }
        for (const expandedId of this.expandedKeys) {
          const expandedProduct = this.products.find(item => Number(item.id) === Number(expandedId))
          if (expandedProduct) await this.loadSkuPage(expandedProduct, this.skuPage(expandedProduct).page)
        }
        if (!this.products.length) { this.selectedProduct = {}; this.selectedSku = {}; this.expandedKeys = []; this.drawerVisible = false }
      } catch (e) { this.$message.error(e.userMessage || '商品数据加载失败') } finally { this.loading = false }
    },
    applyFilters() { this.filters.page = 1; this.fetchAll() },
    changePage(page) { this.filters.page = page; this.fetchAll() },
    changePageSize(size) { this.filters.per_page = size; this.filters.page = 1; this.fetchAll() },
    skuPage(row) { return this.skuPages[row.id] || { rows: [], total: Number(row.skus_count || 0), page: 1, per_page: 5, loading: false } },
    skuList(row) { return this.skuPage(row).rows },
    skuTotal(row) { const page = this.skuPages[row.id]; return page ? Number(page.total || 0) : Number(row.skus_count || 0) },
    async loadSkuPage(row, page = 1, perPage = null) {
      const current = this.skuPage(row)
      const state = { ...current, page, per_page: perPage || current.per_page || 5, loading: true }
      this.$set(this.skuPages, row.id, state)
      try {
        const response = await listEntity('skus', { product_id: row.id, page: state.page, per_page: state.per_page })
        this.$set(this.skuPages, row.id, { rows: response.data.data || [], total: Number(response.data.total || 0), page: Number(response.data.current_page || state.page), per_page: Number(response.data.per_page || state.per_page), loading: false })
      } catch (e) {
        this.$set(this.skuPages, row.id, { ...state, loading: false })
        this.$message.error(e.userMessage || 'SKU 列表加载失败')
      }
    },
    changeSkuPage(row, page) { this.loadSkuPage(row, page) },
    changeSkuPageSize(row, size) { this.loadSkuPage(row, 1, size) },
    relationList(sku) { return (sku.item_relations || sku.itemRelations || []).filter(relation => relation.status === 'active') },
    itemCount(product) { return this.skuList(product).reduce((n, sku) => n + this.relationList(sku).length, 0) },
    onExpandChange(row, expanded) { this.expandedKeys = expanded.map(item => item.id); if (expanded.some(item => Number(item.id) === Number(row.id))) this.loadSkuPage(row, this.skuPage(row).page); this.openProductDetail(row) },
    openProductDetail(row) { this.selectedProduct = { ...row }; this.drawerMode = 'product-detail'; this.drawerVisible = true },
    openProductCreate() { this.$router.push('/master/products/new') },
    openProductEdit(row) { this.$router.push(`/master/products/${row.id}/edit`) },
    openSkuCreate(product) { this.$router.push({ path: '/master/skus/new', query: { product_id: product.id } }) },
    openSkuMatrix(product) {
      this.selectedProduct = { ...product }
      this.skuMatrix = {
        codePrefix: product.product_code || 'SKU',
        sale_price: null,
        status: 'draft',
        dimensions: [
          { name: '', valuesText: '' }
        ]
      }
      this.drawerMode = 'sku-matrix'
      this.drawerVisible = true
    },
    addSkuDimension() { this.skuMatrix.dimensions.push({ name: '', valuesText: '' }) },
    openSkuDetail(product, sku) { this.$router.push(`/master/skus/${sku.id}`) },
    openSkuEdit(product, sku) {
      this.selectedProduct = { ...product }
      this.selectedSku = { ...sku }
      if (sku.sales_unit && !this.units.some(unit => Number(unit.id) === Number(sku.sales_unit.id))) this.units.push(sku.sales_unit)
      this.skuForm = { ...emptySku(), ...sku, product_id: product.id }
      this.drawerMode = 'sku-edit'
      this.drawerVisible = true
      this.$nextTick(() => this.$refs.skuForm && this.$refs.skuForm.clearValidate())
    },
    async uploadSkuBasicImage(option) {
      try {
        const data = new FormData()
        data.append('image', option.file)
        const response = await uploadSkuImage(data)
        this.skuForm.image = response.data.data.url
        option.onSuccess(response.data)
        this.$message.success('SKU 图片上传成功，保存后生效')
      } catch (e) {
        option.onError(e)
        this.$message.error(e.userMessage || 'SKU 图片上传失败')
      }
    },
    saveDrawer() {
      if (this.drawerMode.indexOf('product-') === 0) return this.saveProduct()
      if (this.drawerMode === 'sku-matrix') return this.saveSkuMatrix()
      return this.saveSku()
    },
    async saveProduct() {
      this.$refs.productForm.validate(async ok => {
        if (!ok) return
        this.saving = true
        try { await saveEntity('products', this.productForm); this.$message.success('商品保存成功'); await this.fetchAll(); this.openProductDetail(this.products.find(p => p.product_code === this.productForm.product_code) || this.selectedProduct) } catch (e) { this.$message.error(e.userMessage || '商品保存失败') } finally { this.saving = false }
      })
    },
    async saveSku() {
      this.$refs.skuForm.validate(async ok => {
        if (!ok) return
        this.saving = true
        try { await saveEntity('skus', { ...this.skuForm }); this.$message.success('SKU 保存成功'); await this.fetchAll(); const p = this.products.find(x => x.id === this.skuForm.product_id); if (p) this.openProductDetail(p) } catch (e) { this.$message.error(e.userMessage || 'SKU 保存失败') } finally { this.saving = false }
      })
    },
    async saveSkuMatrix() {
      const rows = this.skuMatrixRows
      if (!rows.length) return this.$message.error('请先维护规格维度和值')
      if (!this.selectedProduct.unit_id) return this.$message.error('请先在商品档案维护计量单位，SKU矩阵将统一继承该单位')
      if (this.skuMatrix.sale_price === null || this.skuMatrix.sale_price === '') return this.$message.error('请维护默认销售价格')
      const creatableRows = rows.filter(row => row.can_generate)
      if (!creatableRows.length) return this.$message.warning('没有可生成的 SKU，预览中的组合均已存在或重复')
      this.saving = true
      try {
        for (const row of creatableRows) {
          const { can_generate: canGenerate, preview_status: previewStatus, ...payload } = row
          const reservation = await reserveFreshDocumentNumber('sku', `/master/products/${this.selectedProduct.id}#sku-matrix`)
          await saveEntity('skus', {
            ...payload,
            sku_code: reservation.document_no,
            reservation_token: reservation.reservation_token,
            creation_session_id: reservation.creation_session_id
          })
        }
        this.$message.success(`已生成 ${creatableRows.length} 个 SKU，跳过 ${rows.length - creatableRows.length} 个已存在/重复项`)
        await this.fetchAll()
        const p = this.products.find(x => x.id === this.selectedProduct.id)
        if (p) this.openProductDetail(p)
      } catch (e) {
        this.$message.error(e.userMessage || 'SKU矩阵生成失败')
      } finally {
        this.saving = false
      }
    },
    shortSpec(value) {
      const map = { 黑: 'BLK', 白: 'WHT', 红: 'RED', 蓝: 'BLU', 绿: 'GRN', 黄: 'YLW' }
      return map[value] || String(value).replace(/\s+/g, '').slice(0, 8)
    },
    normalSpec(value) { return String(value || '').replace(/\s+/g, '').toLowerCase() },
    async toggleProductStatus(row) { const enabling=row.status!=='enabled'; try { await this.$confirm(enabling?'确认启用该商品？':'确认停用该商品？', enabling?'启用确认':'停用确认',{type:enabling?'success':'warning'}); await (enabling?enableEntity:disableEntity)('products',row.id); await this.fetchAll(); this.$message.success(enabling?'商品已启用':'商品已停用') } catch(e) { if(e!=='cancel')this.$message.error(e.userMessage||(enabling?'启用失败':'停用失败')) } },
    async toggleSkuStatus(row) { const enabling=row.status!=='enabled'; try { await this.$confirm(enabling?'确认启用该 SKU？':'确认停用该 SKU？', enabling?'启用确认':'停用确认',{type:enabling?'success':'warning'}); await (enabling?enableEntity:disableEntity)('skus',row.id); await this.fetchAll(); this.$message.success(enabling?'SKU 已启用':'SKU 已停用') } catch(e) { if(e!=='cancel')this.$message.error(e.userMessage||(enabling?'启用失败':'停用失败')) } },
    async deleteSku(row) {
      try {
        await this.$confirm(`\u786e\u8ba4\u5220\u9664 SKU\u300c${row.sku_code}\u300d\uff1f\u4ec5\u672a\u542f\u7528\u4e14\u4ece\u672a\u88ab\u8ba2\u5355\u3001BOM\u3001\u5b9a\u5236\u6216\u9ed8\u8ba4 Item \u5173\u7cfb\u5f15\u7528\u7684 SKU \u53ef\u4ee5\u5220\u9664\u3002`, '\u5220\u9664\u786e\u8ba4', { type: 'warning', confirmButtonText: '\u786e\u8ba4\u5220\u9664' })
        await deleteEntity('skus', row.id)
        this.$message.success('\u0053\u004b\u0055 \u5df2\u5220\u9664')
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '\u0053\u004b\u0055 \u5220\u9664\u5931\u8d25')
      }
    },
    async deleteProduct(row) {
      try {
        await this.$confirm(`确认删除商品「${row.product_code}」？只有停用且没有 SKU、订单或 BOM 引用的商品可以删除。`, '删除确认', { type: 'warning', confirmButtonText: '确认删除' })
        await deleteEntity('products', row.id)
        this.$message.success('商品已删除')
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '商品删除失败')
      }
    },
    disableProduct(row) { return this.toggleProductStatus(row) },
    disableSku(row) { return this.toggleSkuStatus(row) },
    rowClass({ row }) { return row.id === this.selectedProduct.id ? 'selected-row' : '' },
    statusText(status) { return ({ enabled: '启用', disabled: '停用', draft: '草稿' })[status] || status },
    formatDate(v) { return v ? String(v).replace('T', ' ').slice(0, 16) : '-' }
  }
}
</script>

<style scoped>
.pd-page{position:relative;min-height:calc(100vh - 52px);background:#f7f8f9;min-width:960px}.pd-workspace{padding:16px 18px;transition:padding-right .18s;min-width:900px}.pd-page.drawer-open .pd-workspace{padding-right:374px}.pd-head{height:64px;display:flex;align-items:flex-start;justify-content:space-between}.pd-head h1{margin:0;font-size:17px}.pd-head h1 em{margin-left:7px;color:#6c7882;font-style:normal;font-size:12px;font-weight:400}.pd-head p{margin:4px 0 0;color:#77828c;font-size:10px}.pd-actions{display:flex;gap:10px}.pd-filter{height:58px;display:flex;align-items:center;gap:12px}.pd-filter .el-input{width:260px}.pd-filter .el-select{width:170px}.pd-table-card{overflow:hidden;background:#fff;border:1px solid #dfe5e9;border-radius:4px}.pd-pagination{height:46px;padding:0 12px;display:flex;align-items:center;justify-content:flex-end;gap:22px;color:#69747d}.sku-expand{padding:10px;background:#fff}.sku-expand-head{height:34px;display:flex;align-items:center;justify-content:space-between}.sku-expand-head strong{font-size:12px}.pd-dot{display:inline-flex;align-items:center;gap:6px}.pd-dot:before{content:'';width:6px;height:6px;border-radius:50%;background:#07883f}.pd-dot.disabled:before{background:#9aa3ac}::v-deep .selected-row td{background:#edf8f1!important}::v-deep .selected-row td:first-child{box-shadow:inset 3px 0 0 #07883f}.pd-drawer{position:fixed;top:52px;right:0;bottom:0;z-index:9;width:356px;background:#fff;border-left:1px solid #dfe5e9;box-shadow:-8px 0 24px rgba(25,43,58,.08)}.drawer-head{height:58px;padding:0 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e3e8ec}.drawer-head h2{margin:0;font-size:16px}.drawer-head i{cursor:pointer;color:#65717b}.drawer-body{height:calc(100% - 122px);padding:10px 10px 84px;overflow:auto}.drawer-card,.matrix-card{margin-bottom:8px;padding:12px;border:1px solid #e1e7eb;border-radius:4px;background:#fff}.drawer-card h3,.matrix-card h3{margin:0 0 10px;display:flex;justify-content:space-between;font-size:12px}.matrix-card p{margin:0 0 10px;color:#737d87;font-size:11px}.matrix-dim{display:grid;grid-template-columns:76px 1fr 32px;gap:8px;margin-bottom:8px;align-items:center}.matrix-body .el-input-number{width:100%}.drawer-card dl{display:grid;grid-template-columns:86px 1fr;gap:9px 10px;margin:0}.drawer-card dt{color:#76818b}.drawer-card dd{margin:0;color:#2d3842}.tree-line{position:relative;padding:0 0 10px 20px;border-left:1px solid #bfc9d2}.tree-line:before{content:'';position:absolute;left:-4px;top:3px;width:7px;height:7px;border-radius:50%;background:#26313b}.tree-line b{display:block}.tree-line span{display:block;margin-top:5px;color:#58636d}.tree-line.child{margin-left:14px}.tree-line.item:before{background:#07883f}.drawer-form{padding:16px}.drawer-form .full{width:100%}.drawer-footer{position:absolute;left:0;right:0;bottom:0;height:64px;padding:12px;display:flex;gap:10px;background:#fff;border-top:1px solid #e3e8ec}.drawer-footer .el-button{flex:1}.danger-link{color:#e04444!important}.success-link{color:#07883f!important}@media(max-width:1180px){.pd-page.drawer-open .pd-workspace{padding-right:18px}.pd-drawer{width:380px}}
.sku-pagination{min-height:42px;padding:8px 4px 0;display:flex;align-items:center;justify-content:flex-end;gap:16px;color:#68737d;font-size:12px}::v-deep .sku-expand .el-table__body tr{cursor:pointer}.sku-image-editor{display:flex;gap:12px;align-items:center}.sku-image-editor>.el-image,.sku-image-empty{width:74px;height:74px;border:1px solid #dfe6ea;border-radius:4px}.sku-image-empty{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;color:#9aa4ad;background:#f7f9fa}.sku-image-empty i{font-size:22px}.field-tip{display:block;margin:5px 0 0;color:#8a959e;font-size:11px}
</style>
