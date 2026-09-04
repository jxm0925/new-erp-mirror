<template>
  <section class="sup-page" :class="{ 'drawer-open': drawerVisible }">
    <div class="sup-workspace">
      <div class="sup-head">
        <div>
          <h1>供应商管理 <em>{{ pagination.total }}</em></h1>
          <p>维护供应商主档、可供物料类目、具体物料能力、报价和采购履历。</p>
        </div>
        <div class="sup-actions">
          <el-button size="small" icon="el-icon-upload2" @click="$router.push('/master/imports')">导入供应商</el-button>
          <el-button size="small" type="success" icon="el-icon-plus" @click="openCreate">新增供应商</el-button>
        </div>
      </div>

      <div class="sup-filter">
        <el-input v-model="filters.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="供应商编码/名称" @keyup.enter.native="query" />
        <el-input v-model="filters.contact" size="small" clearable prefix-icon="el-icon-search" placeholder="联系人/电话" @keyup.enter.native="query" />
        <el-select v-model="filters.supplier_type" size="small" clearable placeholder="供应商类型">
          <el-option label="生产制造商" value="manufacturer" />
          <el-option label="贸易商" value="trader" />
          <el-option label="服务商" value="service" />
        </el-select>
        <el-select v-model="filters.status" size="small" clearable placeholder="状态">
          <el-option label="启用" value="enabled" />
          <el-option label="停用" value="disabled" />
        </el-select>
        <span class="spacer" />
        <el-button size="small" @click="reset">重置</el-button>
        <el-button size="small" type="success" @click="query">查询</el-button>
      </div>

      <div class="sup-table-card">
        <el-table
          v-loading="loading"
          :data="suppliers"
          size="mini"
          border
          :row-class-name="rowClass"
          empty-text="暂无供应商，请先新增供应商或导入供应商。"
          @row-click="openDetail"
        >
          <el-table-column prop="supplier_code" label="供应商编码" min-width="116" />
          <el-table-column prop="supplier_name" label="供应商名称" min-width="180" show-overflow-tooltip />
          <el-table-column prop="short_name" label="简称" min-width="96" show-overflow-tooltip />
          <el-table-column label="类型" min-width="92"><template slot-scope="{ row }">{{ typeText(row.supplier_type) }}</template></el-table-column>
          <el-table-column prop="contact_name" label="联系人" min-width="84" />
          <el-table-column label="手机" min-width="116"><template slot-scope="{ row }">{{ maskPhone(row.contact_phone || row.phone) }}</template></el-table-column>
          <el-table-column prop="active_item_relation_count" label="具体物料" min-width="80" align="center" />
          <el-table-column prop="enabled_quotation_count" label="有效报价" min-width="80" align="center" />
          <el-table-column label="采购资格" min-width="94">
            <template slot-scope="{ row }">
              <el-tag size="mini" :type="eligible(row) ? 'success' : 'danger'">{{ eligible(row) ? '可采购' : '受限' }}</el-tag>
            </template>
          </el-table-column>
          <el-table-column label="状态" min-width="76">
            <template slot-scope="{ row }"><span class="sup-dot" :class="row.status">{{ statusText(row.status) }}</span></template>
          </el-table-column>
          <el-table-column label="更新时间" min-width="132"><template slot-scope="{ row }">{{ formatDate(row.updated_at) }}</template></el-table-column>
          <el-table-column label="操作" width="168" fixed="right">
            <template slot-scope="{ row }">
              <el-button type="text" size="mini" @click.stop="openDetail(row)">详情</el-button>
              <el-button type="text" size="mini" @click.stop="openEdit(row)">编辑</el-button>
              <el-button
                type="text"
                size="mini"
                :class="{ 'danger-link': row.status === 'enabled' }"
                @click.stop="toggleStatus(row)"
              >{{ row.status === 'enabled' ? '停用' : '启用' }}</el-button>
              <el-button v-if="row.status !== 'enabled'" type="text" size="mini" class="danger-link" @click.stop="deleteSupplier(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
        <div class="pager">
          <span>共 {{ pagination.total }} 条</span>
          <el-pagination
            small
            layout="prev, pager, next, sizes"
            :current-page="pagination.page"
            :page-size="pagination.perPage"
            :page-sizes="[10,20,50]"
            :total="pagination.total"
            @current-change="changePage"
            @size-change="changeSize"
          />
        </div>
      </div>
    </div>

    <aside v-if="drawerVisible" class="sup-drawer">
      <div class="drawer-head">
        <h2>{{ drawerTitle }} <small v-if="selectedSupplier.supplier_code">{{ selectedSupplier.supplier_code }}</small></h2>
        <i class="el-icon-close" @click="drawerVisible=false" />
      </div>

      <div v-if="drawerMode === 'detail'" v-loading="detailLoading" class="drawer-body">
        <section class="drawer-card">
          <h3>基础信息 <el-button type="text" size="mini" @click="openEdit(selectedSupplier)">编辑</el-button></h3>
          <dl>
            <dt>供应商名称</dt><dd>{{ selectedSupplier.supplier_name }}</dd>
            <dt>供应商类型</dt><dd>{{ typeText(selectedSupplier.supplier_type) }}</dd>
            <dt>审批状态</dt><dd>{{ approvalText(selectedSupplier.approval_status) }}</dd>
            <dt>合作状态</dt><dd>{{ cooperationText(selectedSupplier.cooperation_status) }}</dd>
            <dt>质量状态</dt><dd>{{ selectedSupplier.quality_status === 'frozen' ? '质量冻结' : '正常' }}</dd>
            <dt>采购限制</dt><dd>{{ selectedSupplier.purchase_restricted ? '限制采购' : '否' }}</dd>
          </dl>
        </section>
        <section class="drawer-card">
          <h3>联系方式与结算</h3>
          <dl>
            <dt>联系人</dt><dd>{{ selectedSupplier.contact_name || '-' }}</dd>
            <dt>手机</dt><dd>{{ maskPhone(selectedSupplier.contact_phone) }}</dd>
            <dt>电话</dt><dd>{{ selectedSupplier.phone || '-' }}</dd>
            <dt>邮箱</dt><dd>{{ selectedSupplier.email || '-' }}</dd>
            <dt>结算方式</dt><dd>{{ selectedSupplier.settlement_method || '-' }}</dd>
            <dt>付款方式</dt><dd>{{ selectedSupplier.payment_method || '-' }}</dd>
          </dl>
        </section>
        <section class="drawer-card">
          <h3>可供物料类目 <el-button type="text" size="mini" @click="openEdit(selectedSupplier)">维护</el-button></h3>
          <div class="tag-list">
            <el-tag v-for="scope in categoryCapabilities" :key="scope.id" size="mini">{{ categoryPath(scope.item_category_id) }}</el-tag>
            <span v-if="!categoryCapabilities.length" class="muted">未维护；物料类目只用于候选范围，不能替代具体物料供货关系。</span>
          </div>
        </section>
        <section class="drawer-card">
          <h3>具体物料能力（{{ relationPagination.total }}）<el-button size="mini" @click="openRelation">新增关系</el-button></h3>
          <el-table :data="relations" size="mini" border empty-text="暂无正式物料供货关系">
            <el-table-column prop="item.item_code" label="物料" min-width="105" show-overflow-tooltip />
            <el-table-column prop="item.item_name" label="名称" min-width="110" show-overflow-tooltip />
            <el-table-column label="来源" width="72"><template slot-scope="{ row }">{{ sourceText(row.capability_source) }}</template></el-table-column>
            <el-table-column label="状态" width="62"><template slot-scope="{ row }">{{ row.relation_status === 'active' ? '有效' : '失效' }}</template></el-table-column>
            <el-table-column label="操作" width="58"><template slot-scope="{ row }"><el-button v-if="row.relation_status==='active'" type="text" size="mini" @click="disableRelation(row)">停用</el-button></template></el-table-column>
          </el-table>
          <el-pagination
            v-if="relationPagination.total > relationPagination.perPage"
            small
            layout="prev, pager, next"
            :current-page="relationPagination.page"
            :page-size="relationPagination.perPage"
            :total="relationPagination.total"
            @current-change="loadRelations"
          />
        </section>
        <section class="drawer-card">
          <h3>供应商报价（{{ quotePagination.total }}）<el-button size="mini" @click="openQuote">新增/更新报价</el-button></h3>
          <el-table :data="quotations" size="mini" border empty-text="暂无有效报价">
            <el-table-column prop="item.item_code" label="物料" min-width="105" show-overflow-tooltip />
            <el-table-column label="价格" width="90"><template slot-scope="{ row }">{{ row.currency }} {{ money(row.price) }}</template></el-table-column>
            <el-table-column prop="lead_time_days" label="交期/天" width="62" />
            <el-table-column label="有效期" min-width="90"><template slot-scope="{ row }">{{ formatDateOnly(row.valid_until) }}</template></el-table-column>
            <el-table-column label="操作" width="58"><template slot-scope="{ row }"><el-button type="text" size="mini" @click="disableQuote(row)">停用</el-button></template></el-table-column>
          </el-table>
          <el-pagination
            v-if="quotePagination.total > quotePagination.perPage"
            small layout="prev, pager, next"
            :current-page="quotePagination.page" :page-size="quotePagination.perPage" :total="quotePagination.total"
            @current-change="loadQuotations"
          />
        </section>
        <section class="drawer-card">
          <h3>最近采购履历（{{ purchasePagination.total }}）</h3>
          <el-table :data="purchaseHistory" size="mini" border empty-text="暂无采购履历">
            <el-table-column prop="item.item_code" label="物料" min-width="105" show-overflow-tooltip />
            <el-table-column label="采购价" width="88"><template slot-scope="{ row }">{{ row.currency }} {{ money(row.price) }}</template></el-table-column>
            <el-table-column prop="effective_date" label="日期" width="92" />
          </el-table>
          <el-pagination
            v-if="purchasePagination.total > purchasePagination.perPage"
            small layout="prev, pager, next"
            :current-page="purchasePagination.page" :page-size="purchasePagination.perPage" :total="purchasePagination.total"
            @current-change="loadPurchaseHistory"
          />
        </section>
      </div>

      <el-form v-else-if="drawerMode === 'relation'" ref="relationForm" :model="relationForm" :rules="relationRules" label-position="top" size="small" class="drawer-body form-body">
        <el-form-item label="具体物料" prop="item_id">
          <el-select v-model="relationForm.item_id" filterable class="full" placeholder="请选择已启用采购物料">
            <el-option v-for="item in items" :key="item.id" :label="`${item.item_code} / ${item.item_name}`" :value="item.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="能力来源" prop="capability_source">
          <el-select v-model="relationForm.capability_source" class="full">
            <el-option label="人工确认" value="manual_confirmed" />
            <el-option label="采购历史" value="purchase_history" />
          </el-select>
        </el-form-item>
        <el-form-item label="是否默认供应商"><el-switch v-model="relationForm.is_default" /></el-form-item>
        <el-form-item label="生效日期"><el-date-picker v-model="relationForm.effective_at" value-format="yyyy-MM-dd" class="full" /></el-form-item>
        <el-form-item label="变更原因" prop="change_reason"><el-input v-model="relationForm.change_reason" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="relationForm.remark" type="textarea" :rows="3" /></el-form-item>
        <div class="info-note">品类只用于候选筛选；保存后才形成该供应商对具体物料的正式供货能力。</div>
      </el-form>

      <el-form v-else-if="drawerMode === 'quote'" ref="quoteForm" :model="quoteForm" :rules="quoteRules" label-position="top" size="small" class="drawer-body form-body">
        <el-form-item label="具体 Item" prop="item_id">
          <el-select v-model="quoteForm.item_id" filterable class="full" @change="loadQuoteConversions"><el-option v-for="item in items" :key="item.id" :label="`${item.item_code} / ${item.item_name}`" :value="item.id" /></el-select>
        </el-form-item>
        <el-form-item label="采购单位" prop="unit_id"><el-select v-model="quoteForm.unit_id" class="full"><el-option v-for="relation in quoteConversions" :key="relation.id" :label="relation.purchase_unit && `${relation.purchase_unit.unit_name} ${relation.purchase_unit.unit_code}`" :value="relation.purchase_unit_id" /></el-select></el-form-item>
        <div class="conversion-box">
          <div class="conversion-head"><b>换算</b><span>自动读取 Item 采购单位换算</span></div>
          <p>Item默认：1{{ quotePurchaseUnit }} = {{ quoteStandardFactor }}{{ quoteBaseUnit }}</p>
          <div class="quote-formula">{{ quoteFormulaText }}</div>
          <div class="factor-result"><span>基本单位　{{ quoteBaseUnit }}</span><span>因子来源　Item标准换算（只读）</span><span>基准单价（只读）　{{ quoteBaseUnitPrice === '-' ? '-' : `${quoteBaseUnitPrice} ${quoteForm.currency}/${quoteBaseUnit}` }}</span></div>
        </div>
        <div class="form-grid">
          <el-form-item label="报价" prop="price"><el-input v-model="quoteForm.price" type="number" min="0.0001" step="0.0001" class="full" /></el-form-item>
          <el-form-item label="币种"><el-select v-model="quoteForm.currency" class="full"><el-option label="CNY" value="CNY" /><el-option label="USD" value="USD" /></el-select></el-form-item>
          <el-form-item label="税价口径"><el-select v-model="quoteForm.tax_mode" class="full"><el-option label="含税" value="tax_included" /><el-option label="未税" value="tax_excluded" /></el-select></el-form-item>
          <el-form-item label="税率"><el-input-number v-model="quoteForm.tax_rate" :min="0" :max="100" :precision="2" class="full" /></el-form-item>
          <el-form-item label="交期（天）"><el-input v-model="quoteForm.lead_time_days" type="number" min="0" step="1" class="full" /></el-form-item>
          <el-form-item label="最小订购量"><el-input-number v-model="quoteForm.min_order_qty" :min="0" :precision="4" class="full" /></el-form-item>
          <el-form-item label="最大订购量"><el-input-number v-model="quoteForm.max_order_qty" :min="0" :precision="4" class="full" /></el-form-item>
        </div>
        <el-form-item label="有效期" required>
          <el-date-picker v-model="quoteDates" type="daterange" value-format="yyyy-MM-dd" start-placeholder="开始日期" end-placeholder="结束日期" class="full" />
        </el-form-item>
        <el-form-item label="变更原因"><el-input v-model="quoteForm.change_reason" /></el-form-item>
        <el-form-item label="备注"><el-input v-model="quoteForm.remark" type="textarea" :rows="3" /></el-form-item>
      </el-form>

      <el-form v-else ref="form" :model="form" :rules="rules" label-position="top" size="small" class="drawer-body form-body">
        <el-form-item label="供应商编码" prop="supplier_code">
          <el-input v-model="form.supplier_code" disabled><template slot="append">{{ drawerMode === 'create' ? '系统预生成' : '不可修改' }}</template></el-input>
        </el-form-item>
        <el-form-item label="供应商名称" prop="supplier_name"><el-input v-model="form.supplier_name" /></el-form-item>
        <div class="form-grid">
          <el-form-item label="简称"><el-input v-model="form.short_name" /></el-form-item>
          <el-form-item label="供应商类型"><el-select v-model="form.supplier_type" class="full"><el-option label="生产制造商" value="manufacturer" /><el-option label="贸易商" value="trader" /><el-option label="服务商" value="service" /></el-select></el-form-item>
          <el-form-item label="联系人"><el-input v-model="form.contact_name" /></el-form-item>
          <el-form-item label="手机"><el-input v-model="form.contact_phone" /></el-form-item>
          <el-form-item label="结算方式"><el-input v-model="form.settlement_method" /></el-form-item>
          <el-form-item label="付款方式"><el-input v-model="form.payment_method" /></el-form-item>
        </div>
        <el-form-item label="采购资格">
          <div class="eligibility-row">
            <el-select v-model="form.approval_status"><el-option label="已审批" value="approved" /><el-option label="待审批" value="pending" /><el-option label="已驳回" value="rejected" /></el-select>
            <el-select v-model="form.cooperation_status"><el-option label="正常合作" value="normal" /><el-option label="合作异常" value="abnormal" /><el-option label="终止合作" value="terminated" /></el-select>
          </div>
          <div class="switch-row"><span>黑名单</span><el-switch v-model="form.is_blacklisted" /><span>限制采购</span><el-switch v-model="form.purchase_restricted" /></div>
        </el-form-item>
        <el-form-item label="可供Item类目（仅用于采购候选）">
          <item-category-tree-picker v-model="selectedCategoryIds" :tree="categoryTree" multiple />
          <el-alert class="category-scope-alert" title="父级类目仅用于导航；可供范围不代表具体 Item 供货关系，不带价格，也不会成为默认供应商。" type="info" :closable="false" show-icon />
        </el-form-item>
        <el-form-item label="状态"><el-radio-group v-model="form.status"><el-radio label="enabled">启用</el-radio><el-radio label="disabled">停用</el-radio></el-radio-group></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="3" /></el-form-item>
      </el-form>

      <div class="drawer-footer">
        <el-button size="small" @click="drawerVisible=false">取消</el-button>
        <template v-if="drawerMode === 'detail'">
          <el-button size="small" @click="openRelation">新增Item关系</el-button>
          <el-button size="small" type="success" @click="openQuote">维护报价</el-button>
        </template>
        <el-button v-else size="small" type="success" :loading="saving" @click="saveCurrent">保存</el-button>
      </div>
    </aside>
  </section>
</template>

<script>
import {
  listEntity, saveEntity, disableEntity, enableEntity, deleteEntity, getItemCategoryTree,
  getSupplierCapabilities, listSupplierItemRelations, saveSupplierItemRelation, disableSupplierItemRelation,
  listSupplierQuotations, saveSupplierQuotation, disableSupplierQuotation, listSupplierPurchaseHistory,
  listItemPurchaseConversionOptions
} from '../../../api/erp/master'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'
import ItemCategoryTreePicker from '../../../components/master/ItemCategoryTreePicker.vue'

const emptyForm = () => ({
  id: null, supplier_code: '', supplier_name: '', short_name: '', supplier_type: 'manufacturer',
  contact_name: '', contact_phone: '', phone: '', email: '', address: '', default_tax_rate: 0,
  settlement_method: '月结30天', payment_method: '银行转账', bank_name: '', bank_account: '',
  level: '', approval_status: 'approved', is_blacklisted: false, cooperation_status: 'normal',
  purchase_restricted: false, quality_status: 'normal', quality_frozen_until: null,
  status: 'enabled', remark: '', category_ids: []
})
const emptyRelation = () => ({ item_id: null, capability_source: 'manual_confirmed', is_default: false, effective_at: '', change_reason: '人工确认供货能力', remark: '' })
const emptyQuote = () => ({ item_id: null, unit_id: null, price: null, currency: 'CNY', tax_mode: 'tax_included', tax_rate: 13, lead_time_days: 0, min_order_qty: 1, max_order_qty: null, change_reason: '供应商报价更新', remark: '' })

export default {
  name: 'SupplierList',
  components: { ItemCategoryTreePicker },
  data() {
    return {
      loading: false, detailLoading: false, saving: false, drawerVisible: false, drawerMode: 'detail',
      suppliers: [], items: [], units: [], categories: [], selectedSupplier: {}, categoryCapabilities: [],
      relations: [], quotations: [], purchaseHistory: [], reservation: null, quoteConversions: [],
      categoryKeyword: '', selectedCategoryIds: [], categoryTree: [],
      form: emptyForm(), relationForm: emptyRelation(), quoteForm: emptyQuote(), quoteDates: [],
      filters: { keyword: '', contact: '', supplier_type: '', status: '', category_id: this.$route.query.category_id ? Number(this.$route.query.category_id) : '' },
      pagination: { page: 1, perPage: 20, total: 0 },
      relationPagination: { page: 1, perPage: 5, total: 0 },
      quotePagination: { page: 1, perPage: 5, total: 0 },
      purchasePagination: { page: 1, perPage: 5, total: 0 },
      rules: {
        supplier_code: [{ required: true, message: '系统未取得供应商编码，请重新打开新增页', trigger: 'blur' }],
        supplier_name: [{ required: true, message: '请输入供应商名称', trigger: 'blur' }]
      },
      relationRules: {
        item_id: [{ required: true, message: '请选择具体 Item', trigger: 'change' }],
        capability_source: [{ required: true, message: '请选择能力来源', trigger: 'change' }],
        change_reason: [{ required: true, message: '请输入变更原因', trigger: 'blur' }]
      },
      quoteRules: {
        item_id: [{ required: true, message: '请选择具体 Item', trigger: 'change' }],
        unit_id: [{ required: true, message: '请选择采购单位', trigger: 'change' }],
        price: [{ required: true, message: '请输入大于 0 的有效报价', trigger: 'blur' }]
      }
    }
  },
  computed: {
    drawerTitle() {
      const titles = { detail: this.selectedSupplier.supplier_name || '供应商详情', create: '新增供应商', edit: '编辑供应商', relation: '新增 Item 供货关系', quote: '维护供应商报价' }
      return titles[this.drawerMode]
    },
    selectedCategoryRows() {
      const selected = new Set(this.selectedCategoryIds.map(Number))
      return this.categories.filter(category => selected.has(Number(category.id)))
    },
    quoteConversion() { return this.quoteConversions.find(row => Number(row.purchase_unit_id) === Number(this.quoteForm.unit_id)) },
    quoteStandardFactor() { return Number(this.quoteConversion && this.quoteConversion.factor || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    quoteFinalFactor() { return Number(this.quoteConversion && this.quoteConversion.factor || 0) },
    quotePurchaseUnit() { return this.quoteConversion && this.quoteConversion.purchase_unit ? this.quoteConversion.purchase_unit.unit_name : '-' },
    quoteBaseUnit() { return this.quoteConversion && this.quoteConversion.base_unit ? this.quoteConversion.base_unit.unit_name : '-' },
    quoteBaseUnitPrice() { return this.quoteFinalFactor > 0 && Number(this.quoteForm.price || 0) > 0 ? (Number(this.quoteForm.price) / this.quoteFinalFactor).toLocaleString('zh-CN', { minimumFractionDigits: 4, maximumFractionDigits: 8 }) : '-' },
    quoteFormulaText() {
      if (!this.quoteConversion || this.quoteFinalFactor <= 0 || Number(this.quoteForm.price || 0) <= 0) return '请先选择 Item、采购单位并填写有效报价'
      const price = Number(this.quoteForm.price).toLocaleString('zh-CN', { maximumFractionDigits: 4 })
      const factor = Number(this.quoteFinalFactor).toLocaleString('zh-CN', { maximumFractionDigits: 8 })
      const tax = this.quoteForm.tax_mode === 'tax_included' ? `含税 ${Number(this.quoteForm.tax_rate || 0)}%` : '未税'
      return `${price} ${this.quoteForm.currency}/${this.quotePurchaseUnit} ÷ ${factor} ${this.quoteBaseUnit}/${this.quotePurchaseUnit} = ${this.quoteBaseUnitPrice} ${this.quoteForm.currency}/${this.quoteBaseUnit}（${tax}）`
    }
  },
  created() {
    this.fetchOptions()
    this.fetchAll()
  },
  methods: {
    async fetchOptions() {
      try {
        const [items, units, categories] = await Promise.all([
          listEntity('items', { status: 'enabled', is_purchase_item: 1, per_page: 100 }),
          listEntity('units', { status: 'enabled', per_page: 100 }),
          getItemCategoryTree()
        ])
        this.items = items.data.data || []
        this.units = units.data.data || []
        this.categoryTree = categories.data.data || []
        this.categories = this.flattenCategories(this.categoryTree)
      } catch (e) {
        this.$message.error(e.userMessage || '供应商基础选项加载失败')
      }
    },
    async fetchAll() {
      this.loading = true
      try {
        const response = await listEntity('suppliers', {
          page: this.pagination.page, per_page: this.pagination.perPage,
          keyword: this.filters.keyword, contact_keyword: this.filters.contact,
          supplier_type: this.filters.supplier_type, status: this.filters.status, category_id: this.filters.category_id
        })
        this.suppliers = response.data.data || []
        this.pagination.total = response.data.total || 0
      } catch (e) {
        this.$message.error(e.userMessage || '供应商数据加载失败')
      } finally {
        this.loading = false
      }
    },
    query() { this.pagination.page = 1; this.fetchAll() },
    reset() { this.filters = { keyword: '', contact: '', supplier_type: '', status: '', category_id: '' }; this.query() },
    changePage(page) { this.pagination.page = page; this.fetchAll() },
    changeSize(size) { this.pagination.perPage = size; this.pagination.page = 1; this.fetchAll() },
    flattenCategories(tree) { const rows = []; const visit = list => (list || []).forEach(row => { rows.push(row); visit(row.children) }); visit(tree); return rows },
    categoryPath(id) { return (this.categories.find(row => Number(row.id) === Number(id)) || {}).full_path || '-' },
    async openDetail(row) {
      this.selectedSupplier = { ...row }
      this.drawerMode = 'detail'
      this.drawerVisible = true
      await this.loadSupplierDetail()
    },
    async loadSupplierDetail() {
      if (!this.selectedSupplier.id) return
      this.detailLoading = true
      try {
        const [capabilities, relations, quotations, history] = await Promise.all([
          getSupplierCapabilities(this.selectedSupplier.id),
          listSupplierItemRelations(this.selectedSupplier.id, { page: 1, per_page: this.relationPagination.perPage }),
          listSupplierQuotations(this.selectedSupplier.id, { page: 1, per_page: this.quotePagination.perPage }),
          listSupplierPurchaseHistory(this.selectedSupplier.id, { page: 1, per_page: this.purchasePagination.perPage })
        ])
        this.selectedSupplier = capabilities.data.data
        this.categoryCapabilities = this.selectedSupplier.category_capabilities || []
        this.applyPage(relations.data, this.relations, 'relations', this.relationPagination)
        this.applyPage(quotations.data, this.quotations, 'quotations', this.quotePagination)
        this.applyPage(history.data, this.purchaseHistory, 'purchaseHistory', this.purchasePagination)
      } catch (e) {
        this.$message.error(e.userMessage || '供应商能力信息加载失败')
      } finally {
        this.detailLoading = false
      }
    },
    applyPage(payload, current, property, pagination) {
      this[property] = payload.data || current
      pagination.page = payload.current_page || 1
      pagination.total = payload.total || 0
    },
    async loadRelations(page) {
      const response = await listSupplierItemRelations(this.selectedSupplier.id, { page, per_page: this.relationPagination.perPage })
      this.applyPage(response.data, this.relations, 'relations', this.relationPagination)
    },
    async loadQuotations(page) {
      const response = await listSupplierQuotations(this.selectedSupplier.id, { page, per_page: this.quotePagination.perPage })
      this.applyPage(response.data, this.quotations, 'quotations', this.quotePagination)
    },
    async loadPurchaseHistory(page) {
      const response = await listSupplierPurchaseHistory(this.selectedSupplier.id, { page, per_page: this.purchasePagination.perPage })
      this.applyPage(response.data, this.purchaseHistory, 'purchaseHistory', this.purchasePagination)
    },
    async openCreate() {
      this.form = emptyForm()
      this.categoryKeyword = ''
      this.selectedCategoryIds = []
      this.drawerMode = 'create'
      this.drawerVisible = true
      try {
        this.reservation = await reserveForCreatePage('supplier', '/master/suppliers/create')
        this.form.supplier_code = this.reservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || '供应商编码预生成失败')
      }
      this.$nextTick(() => { if (this.$refs.form) this.$refs.form.clearValidate() })
    },
    async openEdit(row) {
      this.selectedSupplier = { ...row }
      if (!row.category_capabilities) await this.loadSupplierDetail()
      this.form = { ...emptyForm(), ...this.selectedSupplier }
      this.categoryKeyword = ''
      this.drawerMode = 'edit'
      this.drawerVisible = true
      const ids = (this.selectedSupplier.category_capabilities || []).map(scope => scope.item_category_id)
      this.selectedCategoryIds = ids.map(Number)
      this.$nextTick(() => { if (this.$refs.form) this.$refs.form.clearValidate() })
    },
    openRelation() {
      this.relationForm = emptyRelation()
      this.drawerMode = 'relation'
      this.drawerVisible = true
    },
    openQuote() {
      this.quoteForm = emptyQuote()
      this.quoteConversions = []
      this.quoteDates = []
      this.drawerMode = 'quote'
      this.drawerVisible = true
    },
    async loadQuoteConversions() {
      this.quoteConversions = []
      this.quoteForm.unit_id = null
      if (!this.quoteForm.item_id) return
      const { data } = await listItemPurchaseConversionOptions(this.quoteForm.item_id, { page: 1, per_page: 100 })
      this.quoteConversions = data.data || []
      const selected = this.quoteConversions.find(row => row.is_default) || this.quoteConversions[0]
      if (selected) this.quoteForm.unit_id = selected.purchase_unit_id
    },
    saveCurrent() {
      if (this.drawerMode === 'relation') return this.saveRelation()
      if (this.drawerMode === 'quote') return this.saveQuote()
      return this.saveSupplier()
    },
    saveSupplier() {
      this.$refs.form.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          const payload = { ...this.form, category_ids: Array.from(new Set(this.selectedCategoryIds.map(Number))) }
          if (this.drawerMode === 'create' && this.reservation) {
            payload.reservation_token = this.reservation.reservation_token
            payload.creation_session_id = this.reservation.creation_session_id
          }
          const response = await saveEntity('suppliers', payload)
          if (this.drawerMode === 'create') clearCreatePageReservation(this.reservation)
          this.reservation = null
          this.$message.success('供应商已保存')
          await this.fetchAll()
          await this.openDetail(response.data.data)
        } catch (e) {
          this.$message.error(e.userMessage || '供应商保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    saveRelation() {
      this.$refs.relationForm.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          await saveSupplierItemRelation(this.selectedSupplier.id, { ...this.relationForm, relation_status: 'active' })
          this.$message.success('具体 Item 供货关系已保存')
          this.drawerMode = 'detail'
          await this.loadSupplierDetail()
        } catch (e) {
          this.$message.error(e.userMessage || 'Item 供货关系保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    saveQuote() {
      this.$refs.quoteForm.validate(async valid => {
        if (!valid) return
        if (!this.quoteDates || this.quoteDates.length !== 2) return this.$message.warning('请选择报价有效期')
        this.saving = true
        try {
          await saveSupplierQuotation(this.selectedSupplier.id, {
            ...this.quoteForm,
            max_order_qty: Number(this.quoteForm.max_order_qty) > 0 ? this.quoteForm.max_order_qty : null,
            valid_from: this.quoteDates[0], valid_until: this.quoteDates[1]
          })
          this.$message.success('报价已保存，并形成具体 Item 能力关系')
          this.drawerMode = 'detail'
          await this.loadSupplierDetail()
        } catch (e) {
          this.$message.error(e.userMessage || '供应商报价保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    async disableRelation(row) {
      try {
        const result = await this.$prompt('停用后不会删除历史。请输入停用原因：', '停用 Item 关系', { inputPattern: /\S+/, inputErrorMessage: '必须填写原因' })
        await disableSupplierItemRelation(this.selectedSupplier.id, row.id, { reason: result.value })
        this.$message.success('Item 关系已停用')
        await this.loadSupplierDetail()
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '停用失败')
      }
    },
    async disableQuote(row) {
      try {
        const result = await this.$prompt('请输入报价停用原因，系统会保留报价历史：', '停用报价', { inputPattern: /\S+/, inputErrorMessage: '必须填写原因' })
        await disableSupplierQuotation(this.selectedSupplier.id, row.id, { reason: result.value })
        this.$message.success('报价已停用')
        await this.loadSupplierDetail()
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '报价停用失败')
      }
    },
    async toggleStatus(row) {
      try {
        await this.$confirm(`确认${row.status === 'enabled' ? '停用' : '启用'}供应商 ${row.supplier_code} / ${row.supplier_name}？`, '状态确认', { type: 'warning' })
        if (row.status === 'enabled') await disableEntity('suppliers', row.id)
        else await enableEntity('suppliers', row.id)
        this.$message.success('供应商状态已更新')
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '状态更新失败')
      }
    },
    async deleteSupplier(row) {
      try {
        await this.$confirm(`确认删除供应商 ${row.supplier_code} / ${row.supplier_name}？仅从未进入采购业务的停用供应商可以删除。`, '删除供应商', { type: 'warning', confirmButtonText: '确认删除' })
        await deleteEntity('suppliers', row.id)
        this.$message.success('供应商已删除')
        if (this.selectedSupplier.id === row.id) { this.drawerVisible = false; this.selectedSupplier = {} }
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '供应商删除失败')
      }
    },
    eligible(row) {
      return row.status === 'enabled' && (row.approval_status || 'approved') === 'approved' &&
        !row.is_blacklisted && (row.cooperation_status || 'normal') === 'normal' &&
        !row.purchase_restricted && (row.quality_status || 'normal') !== 'frozen'
    },
    typeText(v) { return ({ manufacturer: '生产制造商', trader: '贸易商', service: '服务商' })[v] || v || '-' },
    statusText(v) { return v === 'disabled' ? '停用' : '启用' },
    approvalText(v) { return ({ approved: '已审批', pending: '待审批', rejected: '已驳回' })[v || 'approved'] },
    cooperationText(v) { return ({ normal: '正常合作', abnormal: '合作异常', terminated: '终止合作' })[v || 'normal'] },
    sourceText(v) { return ({ manual_confirmed: '人工', quotation: '报价', purchase_history: '历史' })[v] || v },
    maskPhone(v) { return v ? String(v).replace(/(\d{3})\d+(\d{4})/, '$1****$2') : '-' },
    formatDate(v) { return v ? String(v).replace('T', ' ').slice(0, 16) : '-' },
    formatDateOnly(v) { return v ? String(v).slice(0, 10) : '长期' },
    money(v) { return Number(v || 0).toFixed(2) },
    rowClass({ row }) { return row.id === this.selectedSupplier.id ? 'selected-row' : '' }
  }
}
</script>

<style scoped>
.sup-page{position:relative;min-height:calc(100vh - 52px);background:#f7f8f9;min-width:960px}.sup-workspace{padding:16px 18px;transition:padding-right .18s;min-width:900px}.sup-page.drawer-open .sup-workspace{padding-right:430px}.sup-head{height:62px;display:flex;justify-content:space-between}.sup-head h1{margin:0;font-size:17px}.sup-head em{font-style:normal;color:#6d7780;font-size:12px}.sup-head p{margin:4px 0;color:#77828c;font-size:10px}.sup-actions{display:flex;gap:10px}.sup-filter{min-height:58px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}.sup-filter .el-input{width:180px}.sup-filter .el-select{width:150px}.spacer{flex:1}.sup-table-card{overflow:hidden;background:#fff;border:1px solid #dfe5e9;border-radius:4px}.pager{height:48px;padding:0 12px;display:flex;justify-content:flex-end;align-items:center;gap:22px;color:#69747d}.sup-dot{display:inline-flex;align-items:center;gap:6px}.sup-dot:before{content:'';width:6px;height:6px;border-radius:50%;background:#07883f}.sup-dot.disabled:before{background:#9aa3ac}.sup-drawer{position:fixed;top:52px;right:0;bottom:0;width:412px;background:#fff;border-left:1px solid #dfe5e9;z-index:9;box-shadow:-8px 0 24px rgba(25,43,58,.08)}.drawer-head{height:58px;padding:0 14px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e7eb}.drawer-head h2{margin:0;font-size:16px}.drawer-head small{font-weight:400;color:#6c7780}.drawer-head i{cursor:pointer}.drawer-body{height:calc(100% - 122px);padding:10px 8px 84px;overflow:auto}.drawer-card{margin-bottom:8px;padding:12px;border:1px solid #e1e7eb;border-radius:4px}.drawer-card h3{margin:0 0 10px;display:flex;justify-content:space-between;align-items:center;font-size:12px}.drawer-card dl{display:grid;grid-template-columns:86px 1fr;gap:9px 10px;margin:0}.drawer-card dt{color:#76818b}.drawer-card dd{margin:0}.form-body{padding:16px 18px 84px}.full{width:100%}.form-grid{display:grid;grid-template-columns:1fr 1fr;gap:0 10px}.eligibility-row,.switch-row{display:flex;gap:8px;align-items:center}.eligibility-row .el-select{width:50%}.switch-row{margin-top:10px;color:#65717b}.category-picker{width:100%}.category-picker-toolbar{display:flex;align-items:center;gap:10px;margin-bottom:8px}.category-picker-toolbar .el-input{flex:1}.category-tree{max-height:210px;overflow:auto;border:1px solid #e1e7eb;border-radius:4px;padding:8px}.category-selected{display:flex;align-items:center;gap:6px;flex-wrap:wrap;margin-top:8px}.category-selected-label,.category-empty{color:#7b8791;font-size:11px}.category-empty{margin-top:7px;line-height:18px}.tag-list{display:flex;gap:6px;flex-wrap:wrap}.muted{color:#8b959e;font-size:11px}.info-note{padding:10px;border:1px solid #a9cef8;background:#f3f8ff;color:#3974ad;border-radius:4px}.drawer-footer{position:absolute;left:0;right:0;bottom:0;height:64px;padding:12px;display:flex;gap:10px;background:#fff;border-top:1px solid #e2e7eb}.drawer-footer .el-button{flex:1}::v-deep .selected-row td{background:#edf8f1!important}::v-deep .selected-row td:first-child{box-shadow:inset 3px 0 0 #07883f}.danger-link{color:#e04444!important}@media(max-width:1180px){.sup-page.drawer-open .sup-workspace{padding-right:18px}.sup-drawer{width:412px}}
.category-scope-alert{margin-top:10px}
.conversion-box{margin:0 0 14px;padding:12px;border:1px solid #dfe6ee;border-radius:4px;background:#f8fafc}.conversion-head{display:flex;justify-content:space-between;align-items:center}.conversion-box p{margin:10px 0;color:#526174}.quote-formula{margin:10px 0;padding:9px 10px;border:1px solid #bfe4ce;border-radius:4px;background:#eefaf3;color:#08783d;font-weight:600;font-size:12px}.factor-result{display:grid;grid-template-columns:1fr 1.2fr 1.6fr;gap:8px;padding-top:10px;border-top:1px dashed #d8e0e8;color:#42526a;font-size:11px}
</style>
