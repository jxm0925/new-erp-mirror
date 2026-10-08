<template>
  <section class="sku-editor-page" v-loading="loading">
    <product-sku-picker ref="productPicker" @select="selectProduct" />

    <!-- 顶部统一页面头部卡片 -->
    <header class="page-head">
      <div class="head-left">
        <el-button size="small" icon="el-icon-back" class="btn-back" @click="back">返回</el-button>
        <span class="head-icon"><i class="el-icon-collection" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ completionMode ? 'SKU资料补全' : (editing ? '编辑 SKU' : '新增 SKU') }}</h1>
            <el-tag v-if="editing && form.sku_code" size="small" type="info" effect="plain" class="head-tag code-mono">
              {{ form.sku_code }}
            </el-tag>
            <el-tag v-if="editing" size="small" :type="form.status === 'enabled' ? 'success' : form.status === 'draft' ? 'warning' : 'info'" effect="plain">
              {{ form.status === 'enabled' ? '正常启用' : form.status === 'draft' ? '草稿待定' : '已停用' }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <template v-if="completionMode">
          <el-button size="small" class="btn-theme-draft" @click="save('draft')">保存草稿</el-button>
          <el-button size="small" type="success" class="btn-theme-create" @click="save('enabled')">补全并启用</el-button>
        </template>
        <template v-else>
          <el-button v-if="!editing || form.status === 'draft'" size="small" class="btn-theme-draft" @click="save('draft')">保存草稿</el-button>
          <el-button v-if="!editing || form.status !== 'enabled'" size="small" type="success" class="btn-theme-create" @click="save('enabled')">
            {{ editing ? '重新启用' : '保存并启用' }}
          </el-button>
          <el-button v-if="editing && form.status === 'enabled'" size="small" type="success" class="btn-theme-create" @click="save('enabled')">
            保存修改
          </el-button>
          <el-button v-if="editing && form.status === 'enabled'" size="small" type="danger" plain class="btn-danger-plain" @click="save('disabled')">
            停用
          </el-button>
        </template>
      </div>
    </header>

    <!-- 顶部统一提示条 -->
    <div v-if="completionMode" class="erp-page-tip tip-warning">
      <i class="el-icon-warning-outline" />
      <span>当前 SKU 的新系统资料尚未完整维护。补全销售单位、价格并配置默认 Item 后启用，才能投入后续订单履约与生产。</span>
    </div>
    <div v-else class="erp-page-tip">
      <i class="el-icon-info" />
      <span>维护 SKU 规格层级主档案。订单属性仅控制销售订单行显示与必填门禁，不影响 BOM 与工艺路线自动分解。</span>
    </div>

    <!-- 表单主体网格（左侧编辑，右侧实时订单行属性预览） -->
    <div class="editor-layout">
      <div class="editor-main">
        <!-- 基础规格面板 -->
        <section class="panel basic-panel">
          <div class="panel-header">
            <i class="el-icon-postcard" />
            <h2>基础规格信息</h2>
          </div>
          <div class="basic-grid">
            <div class="form-column">
              <div class="form-row">
                <label><i>*</i> SKU编码</label>
                <el-input v-model="form.sku_code" size="small" disabled placeholder="正在预生成">
                  <template slot="append">系统预生成</template>
                </el-input>
              </div>
              <div class="form-row">
                <label><i>*</i> SKU名称</label>
                <el-input v-model="form.sku_name" size="small" placeholder="例如：标准型净水机滤芯套装" />
              </div>
              <div class="form-row">
                <label><i>*</i> 所属Product</label>
                <el-input :value="productText" size="small" readonly placeholder="请选择启用状态 Product">
                  <el-button slot="append" icon="el-icon-search" @click="$refs.productPicker.openProduct()">选择</el-button>
                </el-input>
              </div>
              <div class="form-row">
                <label><i>*</i> 规格型号</label>
                <el-input v-model="form.spec_model" size="small" placeholder="例如：RO-400G / 220V" />
              </div>
              <div class="form-row sales-unit-row">
                <label><i>*</i> 销售单位</label>
                <div class="field-wrap">
                  <el-select v-model="form.sales_unit_id" :disabled="form.sales_unit_locked" size="small" class="fill">
                    <el-option v-for="u in salesUnitOptions" :key="u.id" :value="u.id" :label="unitLabel(u)" />
                  </el-select>
                  <small class="field-sub">销售订单数量按此单位录入，小数位数 {{ selectedUnitPlaces }}</small>
                  <p v-if="form.sales_unit_locked" class="unit-lock"><i class="el-icon-lock" /> 已有生效订单，销售单位锁定不可修改</p>
                </div>
              </div>
              <div class="form-row">
                <label>销售单价</label>
                <div class="price-input-wrap">
                  <el-input-number v-model="form.sale_price" size="small" :min="0" :precision="2" controls-position="right" class="price-input" />
                  <span class="unit-suffix">CNY</span>
                </div>
              </div>
              <div class="form-row image-row">
                <label>SKU 图片</label>
                <div class="image-editor">
                  <el-image v-if="form.image" class="sku-image-preview" :src="imageUrl" fit="cover" :preview-src-list="[imageUrl]" />
                  <div v-else class="sku-image-placeholder"><i class="el-icon-picture-outline" /><span>暂无图片</span></div>
                  <div class="image-actions">
                    <el-upload action="#" :show-file-list="false" accept="image/jpeg,image/png,image/webp,image/gif" :before-upload="validateImage" :http-request="handleImageUpload">
                      <el-button size="mini" plain icon="el-icon-upload2">{{ form.image ? '替换图片' : '上传图片' }}</el-button>
                    </el-upload>
                    <el-button v-if="form.image" size="mini" type="text" class="clear-image" @click="clearImage">清除</el-button>
                    <span class="upload-hint">支持 JPG / PNG / WebP / GIF，文件 ≤ 5MB</span>
                  </div>
                </div>
              </div>
            </div>

            <div class="form-column line-config">
              <div class="form-row vertical">
                <label><i>*</i> 订单行类型</label>
                <el-radio-group v-model="form.line_type" size="small">
                  <el-radio-button label="physical">实物 (需发货)</el-radio-button>
                  <el-radio-button label="service">服务 (虚拟)</el-radio-button>
                  <el-radio-button label="no_delivery">无需发货</el-radio-button>
                </el-radio-group>
              </div>
              <div class="form-row vertical">
                <label><i>*</i> 是否允许销售</label>
                <el-radio-group v-model="form.is_sellable" size="small">
                  <el-radio :label="true">允许销售</el-radio>
                  <el-radio :label="false">禁止销售</el-radio>
                </el-radio-group>
              </div>
            </div>
          </div>
        </section>

        <!-- 订单属性支持 -->
        <section class="panel attribute-panel">
          <div class="panel-header">
            <i class="el-icon-s-operation" />
            <h2>订单属性支持</h2>
          </div>
          <p class="section-note">仅控制销售订单行表单中对应维度的输入门禁与显示，不影响生产物料配料。</p>
          <div class="attribute-rows-wrap">
            <div class="attribute-row">
              <label>电压</label>
              <el-select v-model="form.electric_mode" size="small" class="attr-select">
                <el-option label="不显示" value="hidden" />
                <el-option label="可选" value="optional" />
                <el-option label="必填" value="required" />
              </el-select>
              <span class="attr-desc">设置销售订单行中“电压”参数是否展示；选择必填时未输入将拦截提交。</span>
            </div>
            <div class="attribute-row">
              <label>原水泵控制</label>
              <el-select v-model="form.need_pump_mode" size="small" class="attr-select">
                <el-option label="不显示" value="hidden" />
                <el-option label="可选" value="optional" />
                <el-option label="必填" value="required" />
              </el-select>
              <span class="attr-desc">设置销售订单行中“原水泵控制”参数的录入模式，由业务人员按单据选配。</span>
            </div>
          </div>
        </section>

        <!-- 定制与交付要求 -->
        <section class="panel custom-panel">
          <div class="panel-header">
            <i class="el-icon-s-claim" />
            <h2>定制与交付控制</h2>
          </div>
          <p class="section-note">控制订单行的定制附件门禁与交付前质检要求，确保非标制造合规流转。</p>
          <div class="custom-grid">
            <div class="form-row vertical">
              <label>普通定制</label>
              <el-radio-group v-model="form.allow_customized" size="small">
                <el-radio :label="true">允许</el-radio>
                <el-radio :label="false">不允许</el-radio>
              </el-radio-group>
            </div>
            <div class="form-row vertical">
              <label>特殊定制</label>
              <el-radio-group v-model="form.allow_special_customized" size="small">
                <el-radio :label="true">允许</el-radio>
                <el-radio :label="false">不允许</el-radio>
              </el-radio-group>
            </div>
            <template v-if="form.allow_special_customized">
              <div class="form-row vertical switch-field">
                <label>必须上传设计图纸</label>
                <el-switch v-model="form.special_custom_drawing_required" active-color="#008b4b" />
              </div>
              <div class="form-row vertical switch-field">
                <label>必须上传客户技术协议</label>
                <el-switch v-model="form.special_custom_agreement_required" active-color="#008b4b" />
              </div>
              <div class="form-row vertical switch-field">
                <label>必须填写配置说明</label>
                <el-switch v-model="form.special_custom_description_required" active-color="#008b4b" />
              </div>
            </template>
            <div class="form-row vertical">
              <label>交付前检验</label>
              <el-radio-group v-model="form.delivery_inspection_required" size="small">
                <el-radio :label="true">需要检验</el-radio>
                <el-radio :label="false">无需检验</el-radio>
              </el-radio-group>
            </div>
          </div>
        </section>

        <!-- 默认 Item 绑定 -->
        <section v-if="form.line_type === 'physical'" class="panel item-panel">
          <div class="panel-header">
            <i class="el-icon-connection" />
            <h2>默认物料（Item）关系</h2>
          </div>
          <p class="section-note">实物 SKU 保存草稿可暂不绑定；正式启用必须绑定唯一有效默认物料以支持出入库与工单拆解。</p>
          <div class="item-picker-line">
            <label>默认 Item</label>
            <el-input size="small" readonly :value="defaultItemText" placeholder="点击右侧按钮从已启用物料库中选择" @click.native="picker=true">
              <el-button slot="append" icon="el-icon-search" class="btn-picker-open" @click="picker=true">选择物料</el-button>
            </el-input>
          </div>
          <el-table :data="defaultRelation ? [defaultRelation.item] : []" size="small" border class="item-summary-table" empty-text="当前尚未绑定默认 Item 物料">
            <el-table-column prop="item_code" label="Item编码" min-width="140">
              <template slot-scope="{ row }">
                <span class="code-mono text-success">{{ row.item_code }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="item_name" label="Item名称" min-width="170" show-overflow-tooltip />
            <el-table-column prop="spec" label="规格型号" min-width="140" show-overflow-tooltip />
            <el-table-column label="物料类型" width="100" align="center">
              <template slot-scope="{ row }">
                <span class="type-pill">{{ itemTypeText(row.item_type) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="启用状态" width="90" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'" effect="plain">
                  {{ row.status === 'enabled' ? '已启用' : '未启用' }}
                </el-tag>
              </template>
            </el-table-column>
          </el-table>
        </section>
        <div v-else class="item-not-required-card">
          <i class="el-icon-circle-check" />
          <span>当前订单行类型为非实物类（服务/无需发货），无需配置默认 Item 物料。</span>
        </div>
      </div>

      <!-- 右侧订单行实时属性卡片 -->
      <aside class="preview-panel">
        <div class="preview-header">
          <i class="el-icon-view" />
          <h3>销售订单行显示预览</h3>
        </div>
        <p class="preview-help">此预览展示该 SKU 添加至销售订单行后的字段呈现与必填门禁效果。</p>
        
        <div class="preview-card-body">
          <div class="preview-field">
            <span class="preview-lbl">所属商品</span>
            <span class="preview-val">{{ productText }}</span>
          </div>
          <div class="preview-field">
            <span class="preview-lbl">SKU 编码</span>
            <span class="preview-val code-mono">{{ form.sku_code || '—' }}</span>
          </div>
          <div class="preview-field">
            <span class="preview-lbl">数量录入</span>
            <div class="preview-val">输入数量 <b class="code-mono">{{ selectedUnitName || '—' }}</b></div>
          </div>
          <div class="preview-field">
            <span class="preview-lbl">单价 (CNY)</span>
            <div class="preview-val">{{ form.sale_price === null || form.sale_price === '' ? '—' : Number(form.sale_price).toFixed(2) }} <b>¥</b></div>
          </div>
          <div v-if="form.electric_mode !== 'hidden'" class="preview-field">
            <span class="preview-lbl">电压 <i v-if="form.electric_mode === 'required'">*</i></span>
            <div class="preview-val">请选择（{{ form.electric_mode === 'required' ? '必填' : '可选' }}）</div>
          </div>
          <div v-if="form.need_pump_mode !== 'hidden'" class="preview-field">
            <span class="preview-lbl">原水泵控制 <i v-if="form.need_pump_mode === 'required'">*</i></span>
            <div class="preview-val">请选择（{{ form.need_pump_mode === 'required' ? '必填' : '可选' }}）</div>
          </div>
          <div v-if="form.allow_customized" class="preview-field">
            <span class="preview-lbl">普通定制</span>
            <div class="preview-val text-success">可勾选定制</div>
          </div>
          <template v-if="form.allow_special_customized">
            <div class="preview-field">
              <span class="preview-lbl">特殊定制</span>
              <div class="preview-val text-success">可勾选特殊定制</div>
            </div>
            <div v-if="form.special_custom_drawing_required" class="preview-field">
              <span class="preview-lbl">设计图纸 <i>*</i></span>
              <div class="preview-val text-warning">特殊定制时必须上传图纸</div>
            </div>
            <div v-if="form.special_custom_agreement_required" class="preview-field">
              <span class="preview-lbl">技术协议 <i>*</i></span>
              <div class="preview-val text-warning">特殊定制时必须上传协议</div>
            </div>
            <div v-if="form.special_custom_description_required" class="preview-field">
              <span class="preview-lbl">配置说明 <i>*</i></span>
              <div class="preview-val text-warning">特殊定制时必须填写说明</div>
            </div>
          </template>
          <div v-if="form.delivery_inspection_required" class="preview-field">
            <span class="preview-lbl">交付前检验</span>
            <div class="preview-val text-danger">强制要求发货前终检</div>
          </div>
        </div>

        <div class="preview-note">
          <strong><i class="el-icon-info" /> 订单履约链路提示</strong>
          <p>客户提交销售订单后，系统将依据绑定的默认 Item 自动核算库存锁定量并生成生产工单投料计划。</p>
        </div>
      </aside>
    </div>

    <!-- 弹窗：选择默认 Item -->
    <el-dialog title="选择默认 Item 物料" :visible.sync="picker" width="840px" append-to-body custom-class="picker-dialog">
      <div class="picker-toolbar">
        <el-input
          v-model="itemQuery.keyword"
          size="small"
          placeholder="按 Item 编码 / 名称 / 规格检索"
          clearable
          prefix-icon="el-icon-search"
          class="picker-input"
          @keyup.enter.native="searchItems"
        />
        <el-select v-model="itemQuery.item_type" size="small" clearable placeholder="物料类型" class="picker-select">
          <el-option label="成品" value="finished_product" />
          <el-option label="半成品" value="semi_finished" />
          <el-option label="原材料" value="raw_material" />
          <el-option label="包装物" value="packaging" />
          <el-option label="服务" value="service" />
        </el-select>
        <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-create" @click="searchItems">查询</el-button>
      </div>

      <el-table :data="items" size="small" border class="picker-table" @row-dblclick="chooseItem">
        <el-table-column prop="item_code" label="Item编码" width="130" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <span class="code-mono">{{ row.item_code }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="item_name" label="Item名称" min-width="150" show-overflow-tooltip />
        <el-table-column prop="spec" label="规格型号" min-width="120" show-overflow-tooltip />
        <el-table-column label="物料类型" width="90" align="center">
          <template slot-scope="{ row }">{{ itemTypeText(row.item_type) }}</template>
        </el-table-column>
        <el-table-column label="库存单位" width="85" align="center">
          <template slot-scope="{ row }">{{ row.unit && row.unit.unit_name || '—' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="75" align="center">
          <template slot-scope="{ row }">
            <el-tag type="success" size="mini" effect="plain">{{ row.status === 'enabled' ? '启用' : '停用' }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="80" align="center">
          <template slot-scope="{ row }">
            <el-button type="text" size="small" class="action-link-theme" @click="chooseItem(row)">选择</el-button>
          </template>
        </el-table-column>
      </el-table>

      <div class="picker-pager">
        <el-pagination
          small
          background
          layout="total, prev, pager, next"
          :current-page="itemQuery.page"
          :page-size="itemQuery.per_page"
          :total="itemTotal"
          @current-change="changeItemPage"
        />
      </div>
    </el-dialog>
  </section>
</template>

<script>
import { listEntity, getEntity, saveEntity, replacePrimaryRelation, uploadSkuImage } from '../../../api/erp/master'
import { legacyMediaUrl } from '../../../utils/legacyMedia'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'
import ProductSkuPicker from '../../../components/sales/ProductSkuPicker.vue'

const empty = () => ({
  sku_code: '',
  sku_name: '',
  product_id: null,
  spec_model: '',
  image: '',
  sales_unit_id: null,
  sale_price: null,
  line_type: 'physical',
  is_sellable: true,
  allow_customized: false,
  allow_special_customized: false,
  special_custom_drawing_required: false,
  special_custom_agreement_required: false,
  special_custom_description_required: false,
  delivery_inspection_required: false,
  electric_mode: 'hidden',
  need_pump_mode: 'hidden',
  status: 'draft'
})

export default {
  name: 'SkuForm',
  components: { ProductSkuPicker },
  data () {
    return {
      loading: false,
      reservation: null,
      form: empty(),
      products: [],
      units: [],
      defaultRelation: null,
      picker: false,
      items: [],
      itemTotal: 0,
      itemQuery: { keyword: '', item_type: '', page: 1, per_page: 10 }
    }
  },
  computed: {
    editing () { return !!this.$route.params.id },
    productText () {
      const p = this.products.find(x => x.id === this.form.product_id)
      return p ? `${p.product_code}｜${p.product_name}` : '—'
    },
    defaultItemText () {
      return this.defaultRelation && this.defaultRelation.item
        ? `${this.defaultRelation.item.item_code} | ${this.defaultRelation.item.item_name}`
        : ''
    },
    currentSalesUnit () {
      return this.units.find(x => Number(x.id) === Number(this.form.sales_unit_id)) ||
        (this.form.sales_unit && Number(this.form.sales_unit.id) === Number(this.form.sales_unit_id) ? this.form.sales_unit : null)
    },
    salesUnitOptions () {
      return this.currentSalesUnit && this.currentSalesUnit.is_legacy ? [this.currentSalesUnit, ...this.units] : this.units
    },
    selectedUnitName () {
      const unit = this.canonicalUnit(this.currentSalesUnit)
      return unit ? (unit.symbol || unit.unit_name) : '—'
    },
    selectedUnitPlaces () {
      const unit = this.canonicalUnit(this.currentSalesUnit)
      return Number(unit && unit.decimal_places || 0)
    },
    imageUrl () { return legacyMediaUrl(this.form.image) },
    completionMode () {
      return this.$route.path.endsWith('/complete') || (this.editing && this.form.status === 'draft' && !this.isOrderReady)
    },
    isOrderReady () {
      return !!(this.form.sales_unit_id && this.form.sale_price !== null && this.form.sale_price !== '' && (this.form.line_type !== 'physical' || this.defaultRelation))
    }
  },
  created () { this.init() },
  methods: {
    back () {
      this.$router.push(this.$route.query.from === 'product' ? '/master/products' : '/master/skus')
    },
    canonicalUnit (unit) { return unit && (unit.standard_unit || unit.standardUnit || unit) },
    unitLabel (unit) {
      const current = this.canonicalUnit(unit)
      return current ? `${current.unit_code || ''} ${current.unit_name || ''} (${current.symbol || current.unit_name || ''})`.trim() : '—'
    },
    async init () {
      this.loading = true
      try {
        const [products, units] = await Promise.all([
          listEntity('products', { status: 'enabled', page: 1, per_page: 100 }),
          listEntity('units', { status: 'enabled', page: 1, per_page: 100 })
        ])
        this.products = products.data.data || []
        this.units = units.data.data || []
        if (this.editing) {
          const r = await getEntity('skus', this.$route.params.id)
          this.form = {
            ...empty(),
            ...r.data,
            spec_model: r.data.spec_model || r.data.spec_text || '',
            line_type: r.data.line_type || r.data.order_line_type,
            is_sellable: r.data.is_sellable
          }
          if (r.data.product && !this.products.some(item => item.id === r.data.product.id)) {
            this.products.push(r.data.product)
          }
          this.defaultRelation = (this.form.item_relations || []).find(x => x.status === 'active' && x.is_primary && x.item && x.item.status === 'enabled') || null
        } else {
          const productId = Number(this.$route.query.product_id || 0)
          if (productId) {
            let product = this.products.find(item => Number(item.id) === productId)
            if (!product) {
              const response = await getEntity('products', productId)
              product = response.data
              if (product && product.status === 'enabled') this.products.push(product)
            }
            if (product && product.status === 'enabled') {
              this.form.product_id = product.id
              if (product.unit_id) this.form.sales_unit_id = product.unit_id
            }
          }
          this.reservation = await reserveForCreatePage('sku', '/master/skus/new')
          this.form.sku_code = this.reservation.document_no
        }
      } catch (error) {
        this.$message.error(error.userMessage || 'SKU资料加载失败')
      } finally {
        this.loading = false
      }
    },
    selectProduct ({ mode, row }) {
      if (mode !== 'product') return
      this.form.product_id = row.id
      if (!this.products.some(item => item.id === row.id)) this.products.push(row)
    },
    async loadItems () {
      const r = await listEntity('items', { ...this.itemQuery, status: 'enabled', management_scope: 'factory' })
      this.items = r.data.data || []
      this.itemTotal = r.data.total || 0
    },
    searchItems () {
      this.itemQuery.page = 1
      this.loadItems()
    },
    changeItemPage (page) {
      this.itemQuery.page = page
      this.loadItems()
    },
    itemTypeText (value) {
      return ({ finished_product: '成品', semi_finished: '半成品', raw_material: '原材料', packaging: '包装物', service: '服务' })[value] || value || '—'
    },
    chooseItem (row) {
      this.defaultRelation = { item: row }
      this.picker = false
    },
    validateImage (file) {
      const allowed = ['image/jpeg', 'image/png', 'image/webp', 'image/gif']
      if (!allowed.includes(file.type)) {
        this.$message.error('仅支持 JPG、PNG、WebP、GIF 图片')
        return false
      }
      if (file.size > 5 * 1024 * 1024) {
        this.$message.error('图片不能超过 5MB')
        return false
      }
      return true
    },
    async handleImageUpload (option) {
      try {
        const data = new FormData()
        data.append('image', option.file)
        const response = await uploadSkuImage(data)
        this.form.image = response.data.data.url
        option.onSuccess(response.data)
        this.$message.success('图片上传成功，保存 SKU 后生效')
      } catch (error) {
        option.onError(error)
        this.$message.error(error.userMessage || '图片上传失败')
      }
    },
    clearImage () { this.form.image = '' },
    currentPrimaryItemId () {
      const current = (this.form.item_relations || []).find(x => x.status === 'active' && x.is_primary && x.item && x.item.status === 'enabled')
      return current && current.item_id
    },
    async save (status) {
      if (!this.form.sku_code || !this.form.sku_name || !this.form.product_id || !this.form.spec_model) {
        return this.$message.error('请完成 SKU 编码、名称、所属 Product 与规格型号')
      }
      this.loading = true
      try {
        const selectedItemId = this.defaultRelation && this.defaultRelation.item && this.defaultRelation.item.id
        const relationChanged = !!(selectedItemId && Number(selectedItemId) !== Number(this.currentPrimaryItemId()))
        const stageBeforeEnable = status === 'enabled' && (!this.editing || relationChanged)
        const firstStatus = stageBeforeEnable ? 'draft' : status
        const payload = { ...this.form, status: firstStatus }
        if (!this.editing && this.reservation) {
          payload.reservation_token = this.reservation.reservation_token
          payload.creation_session_id = this.reservation.creation_session_id
        }
        const r = await saveEntity('skus', payload)
        const sku = r.data.data
        if (!this.editing) clearCreatePageReservation(this.reservation)
        if (relationChanged) {
          await replacePrimaryRelation({
            sku_id: sku.id,
            item_id: selectedItemId,
            factor: 1,
            change_reason: this.editing ? '主数据修正' : '首次设置'
          })
        }
        if (stageBeforeEnable) {
          await saveEntity('skus', { ...sku, ...this.form, id: sku.id, status: 'enabled' })
        }
        this.$message.success(status === 'enabled' ? 'SKU 已成功启用' : status === 'disabled' ? 'SKU 已停用' : '草稿保存成功')
        this.$router.push({
          path: '/master/skus/' + sku.id,
          query: this.$route.query.from === 'product' ? { from: 'product' } : {}
        })
      } catch (e) {
        this.$message.error(e.userMessage || '保存失败')
      } finally {
        this.loading = false
      }
    }
  },
  watch: {
    picker (v) { if (v) this.loadItems() }
  }
}
</script>

<style scoped>
.sku-editor-page {
  padding: 16px 20px 30px;
  background: #f8fafc;
  min-height: calc(100vh - 52px);
  color: #1f2937;
  box-sizing: border-box;
}

/* 页面头部：标准白底卡片 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.btn-back {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-back:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.head-title-wrap {
  display: flex;
  flex-direction: column;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}

.head-tag {
  border-radius: 4px;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-theme-draft {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-theme-draft:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.btn-danger-plain {
  border-color: #fca5a5 !important;
  color: #dc2626 !important;
}

.btn-danger-plain:hover {
  background: #fef2f2 !important;
  border-color: #ef4444 !important;
}

/* 统一提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 13px;
  line-height: 1.5;
  margin-bottom: 14px;
}

.erp-page-tip i {
  font-size: 15px;
  color: #008b4b;
  flex-shrink: 0;
}

.tip-warning {
  background: #fffbeb !important;
  border-color: #fde68a !important;
  color: #b45309 !important;
}

.tip-warning i {
  color: #d97706 !important;
}

/* 布局网格 */
.editor-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 16px;
  align-items: start;
}

.editor-main {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
}

/* 卡片面板 */
.panel {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 18px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  box-sizing: border-box;
}

.panel-header {
  display: flex;
  align-items: center;
  gap: 8px;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #f1f5f9;
}

.panel-header i {
  color: #008b4b;
  font-size: 17px;
}

.panel-header h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.section-note {
  font-size: 12px;
  color: #64748b;
  margin: -6px 0 14px;
  line-height: 1.5;
}

/* 基础规格网格 */
.basic-grid {
  display: grid;
  grid-template-columns: 1.2fr 1fr;
  gap: 24px;
}

.form-column {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.form-row {
  display: grid;
  grid-template-columns: 100px 1fr;
  align-items: center;
  gap: 10px;
}

.form-row label {
  font-size: 13px;
  color: #475569;
  text-align: right;
  white-space: nowrap;
}

.form-row label i {
  color: #ef4444;
  font-style: normal;
  margin-right: 2px;
}

.form-row.vertical {
  grid-template-columns: 100px 1fr;
  align-items: flex-start;
}

.form-row.vertical label {
  padding-top: 6px;
}

.field-wrap {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field-sub {
  font-size: 11px;
  color: #94a3b8;
}

.unit-lock {
  margin: 2px 0 0;
  font-size: 11px;
  color: #d97706;
  display: flex;
  align-items: center;
  gap: 4px;
}

.fill {
  width: 100%;
}

.price-input-wrap {
  position: relative;
  width: 100%;
}

.price-input {
  width: 100%;
}

.unit-suffix {
  position: absolute;
  right: 42px;
  top: 50%;
  transform: translateY(-50%);
  font-size: 12px;
  color: #64748b;
  pointer-events: none;
}

/* 图片上传区 */
.image-row {
  align-items: flex-start;
}

.image-row > label {
  padding-top: 6px;
}

.image-editor {
  display: flex;
  align-items: center;
  gap: 12px;
}

.sku-image-preview,
.sku-image-placeholder {
  width: 60px;
  height: 60px;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
  flex-shrink: 0;
}

.sku-image-placeholder {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 2px;
  background: #f8fafc;
  color: #94a3b8;
  font-size: 11px;
}

.sku-image-placeholder i {
  font-size: 20px;
}

.image-actions {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.upload-hint {
  font-size: 11px;
  color: #94a3b8;
}

.clear-image {
  color: #ef4444 !important;
  padding: 0 !important;
}

/* 属性设置 */
.attribute-rows-wrap {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.attribute-row {
  display: grid;
  grid-template-columns: 100px 140px 1fr;
  align-items: center;
  gap: 12px;
}

.attribute-row label {
  font-size: 13px;
  color: #475569;
  text-align: right;
}

.attr-select {
  width: 100%;
}

.attr-desc {
  font-size: 12px;
  color: #64748b;
}

/* 定制设置网格 */
.custom-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px 20px;
}

.switch-field {
  align-items: center !important;
}

.switch-field label {
  padding-top: 0 !important;
}

/* Item 绑定面板 */
.item-picker-line {
  display: grid;
  grid-template-columns: 100px 1fr;
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
}

.item-picker-line label {
  font-size: 13px;
  color: #475569;
  text-align: right;
}

.btn-picker-open {
  background: #f0fdf4 !important;
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
}

.item-summary-table ::v-deep th {
  background: #f8fafc !important;
  font-size: 12px;
}

.type-pill {
  font-size: 11px;
  padding: 1px 6px;
  background: #f1f5f9;
  border-radius: 3px;
  color: #475569;
}

.item-not-required-card {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  color: #64748b;
  font-size: 13px;
}

.item-not-required-card i {
  color: #008b4b;
  font-size: 16px;
}

/* 右侧预览面板 */
.preview-panel {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 18px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  position: sticky;
  top: 14px;
}

.preview-header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding-bottom: 10px;
  margin-bottom: 8px;
  border-bottom: 1px solid #f1f5f9;
}

.preview-header i {
  color: #008b4b;
  font-size: 16px;
}

.preview-header h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}

.preview-help {
  margin: 0 0 14px;
  font-size: 12px;
  color: #64748b;
  line-height: 1.5;
}

.preview-card-body {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.preview-field {
  display: grid;
  grid-template-columns: 85px 1fr;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}

.preview-lbl {
  color: #64748b;
  text-align: right;
}

.preview-lbl i {
  color: #ef4444;
  font-style: normal;
}

.preview-val {
  min-height: 32px;
  box-sizing: border-box;
  padding: 6px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  background: #f8fafc;
  color: #1e293b;
  font-size: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  word-break: break-all;
}

.preview-note {
  margin-top: 18px;
  padding: 12px 14px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 12px;
  line-height: 1.6;
}

.preview-note strong {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #008b4b;
  margin-bottom: 4px;
}

.preview-note p {
  margin: 0;
  color: #166534;
}

/* 弹窗选择物料 */
::v-deep .picker-dialog {
  border-radius: 8px;
}

.picker-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.picker-input {
  flex: 1;
}

.picker-select {
  width: 130px;
}

.picker-table ::v-deep th {
  background: #f8fafc !important;
  font-size: 12px;
}

.picker-pager {
  display: flex;
  justify-content: flex-end;
  padding-top: 12px;
}

.picker-pager ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
}

.action-link-theme {
  color: #008b4b !important;
  font-weight: 500;
}

.code-mono {
  font-family: monospace;
}

.text-success { color: #008b4b !important; }
.text-warning { color: #d97706 !important; }
.text-danger { color: #dc2626 !important; }

/* 响应式断点适配规则 */
@media (max-width: 1180px) {
  .editor-layout {
    grid-template-columns: 1fr;
  }
  .preview-panel {
    position: static;
  }
}

@media (max-width: 780px) {
  .basic-grid,
  .custom-grid {
    grid-template-columns: 1fr;
  }
  .attribute-row {
    grid-template-columns: 1fr;
  }
  .attribute-row label,
  .form-row label,
  .item-picker-line label {
    text-align: left;
  }
  .form-row,
  .item-picker-line {
    grid-template-columns: 1fr;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-end;
    flex-wrap: wrap;
  }
}
</style>
