<template>
  <div>
    <el-dialog
      class="sales-picker-dialog"
      :title="mode === 'product' ? '选择Product' : '选择SKU'"
      :visible.sync="visible"
      width="980px"
      append-to-body
    >
      <div v-if="mode === 'product'" class="picker-filters">
        <el-input v-model="query.keyword" size="small" clearable placeholder="产品名称 / 产品编码" @keyup.enter.native="load" />
        <el-select v-model="query.category_id" size="small" clearable placeholder="产品分类">
          <el-option v-for="item in categories" :key="item.id" :label="item.category_name" :value="item.id" />
        </el-select>
        <el-select v-model="query.status" size="small" clearable placeholder="产品状态">
          <el-option label="启用" value="enabled" />
          <el-option label="停用" value="disabled" />
        </el-select>
        <el-button size="small" type="success" @click="load">查询</el-button>
      </div>

      <div v-else class="picker-filters">
        <el-input v-model="query.keyword" size="small" clearable placeholder="SKU / Product / 规格 / 编码 / 别名" @input="queueLoad" @keyup.enter.native="load" />
        <el-select v-model="query.status" size="small" clearable placeholder="销售状态">
          <el-option label="启用" value="enabled" />
          <el-option label="停用" value="disabled" />
        </el-select>
        <el-button size="small" type="success" @click="load">查询</el-button>
        <span class="picker-tip">{{ mode === 'global_sku' ? '全局 SKU 搜索，结果按后端分页返回' : '仅展示当前 Product 下可用 SKU' }}</span>
      </div>

      <el-table
        v-if="mode === 'product'"
        :data="rows"
        border
        size="mini"
        height="420"
        highlight-current-row
        @row-click="current = $event"
        @row-dblclick="confirm"
      >
        <el-table-column width="54" align="center">
          <template slot-scope="{row}"><el-radio v-model="currentId" :label="row.id">&nbsp;</el-radio></template>
        </el-table-column>
        <el-table-column label="产品图片" width="86">
          <template slot-scope="{row}">
            <div class="picker-img"><img v-if="imageUrl(row.image)" :src="imageUrl(row.image)"><i v-else class="el-icon-picture-outline" /></div>
          </template>
        </el-table-column>
        <el-table-column prop="product_name" label="产品名称" min-width="180" show-overflow-tooltip />
        <el-table-column prop="product_code" label="产品编码" width="150" />
        <el-table-column label="分类" width="130"><template slot-scope="{row}">{{ row.category && row.category.category_name || '-' }}</template></el-table-column>
        <el-table-column prop="product_type" label="产品类型" width="120" />
        <el-table-column label="状态" width="90"><template slot-scope="{row}"><el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'">{{ statusText(row.status) }}</el-tag></template></el-table-column>
      </el-table>

      <el-table
        v-else
        :data="rows"
        border
        size="mini"
        height="420"
        highlight-current-row
        @row-click="current = $event"
        @row-dblclick="confirm"
      >
        <el-table-column width="54" align="center">
          <template slot-scope="{row}"><el-radio v-model="currentId" :label="row.id">&nbsp;</el-radio></template>
        </el-table-column>
        <el-table-column label="SKU 图片" width="86">
          <template slot-scope="{row}">
            <div class="picker-img"><img v-if="imageUrl(row.image || (row.product && row.product.image))" :src="imageUrl(row.image || (row.product && row.product.image))"><i v-else class="el-icon-picture-outline" /></div>
          </template>
        </el-table-column>
        <el-table-column prop="sku_name" label="SKU名称" min-width="180" show-overflow-tooltip />
        <el-table-column prop="sku_code" label="SKU编码" width="150" />
        <el-table-column v-if="mode === 'global_sku'" label="所属 Product" min-width="150"><template slot-scope="{row}">{{ row.product && row.product.product_name || '-' }}</template></el-table-column>
        <el-table-column prop="spec_text" label="规格摘要" min-width="180" show-overflow-tooltip />
        <el-table-column v-if="mode === 'global_sku'" label="销售单位" width="96"><template slot-scope="{row}">{{ row.sales_unit && row.sales_unit.unit_name || '-' }}</template></el-table-column>
        <el-table-column v-if="mode === 'global_sku'" label="可用库存" width="100"><template slot-scope="{row}">{{ row.available_stock === null ? '-' : row.available_stock }}</template></el-table-column>
        <el-table-column label="默认配置" min-width="170">
          <template slot-scope="{row}">
            <el-tag v-if="row.allow_customized" size="mini">可普通定制</el-tag>
            <el-tag v-if="row.allow_special_customized" size="mini" type="warning">可特殊定制</el-tag>
            <el-tag v-if="row.is_need_production" size="mini" type="warning">需生产</el-tag>
            <el-tag v-if="row.is_need_bom" size="mini" type="success">需BOM</el-tag>
            <span v-if="!row.allow_customized && !row.allow_special_customized && !row.is_need_production && !row.is_need_bom" class="dash">标准</span>
          </template>
        </el-table-column>
        <el-table-column label="销售状态" width="90"><template slot-scope="{row}"><el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'">{{ statusText(row.status) }}</el-tag></template></el-table-column>
      </el-table>

      <div class="picker-footer">
        <el-pagination
          layout="total, sizes, prev, pager, next"
          :total="page.total"
          :page-size.sync="query.per_page"
          :current-page.sync="query.page"
          :page-sizes="[10, 20, 50]"
          @size-change="load"
          @current-change="load"
        />
        <div>
          <el-button size="small" @click="visible = false">取消</el-button>
          <el-button size="small" type="success" :disabled="!current" @click="confirm">确定选择</el-button>
        </div>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { searchSalesOrderSkus } from '@/api/erp/sales'
import { statusText } from '@/utils/erpStatus'
import { legacyMediaUrl } from '@/utils/legacyMedia'

export default {
  data: () => ({
    visible: false,
    mode: 'product',
    productId: null,
    rows: [],
    categories: [],
    current: null,
    searchTimer: null,
    requestToken: 0,
    query: { keyword: '', category_id: '', status: 'enabled', page: 1, per_page: 10 },
    page: { total: 0 }
  }),
  computed: {
    currentId: {
      get() { return this.current && this.current.id },
      set(id) { this.current = this.rows.find(row => row.id === id) || null }
    }
  },
  methods: {
    statusText,
    imageUrl: legacyMediaUrl,
    async openProduct() {
      this.mode = 'product'
      this.productId = null
      this.current = null
      this.query = { keyword: '', category_id: '', status: 'enabled', page: 1, per_page: 10 }
      this.visible = true
      await this.loadCategories()
      await this.load()
    },
    async openSku(productId) {
      if (!productId) {
        this.$message.warning('请先选择产品')
        return
      }
      this.mode = 'sku'
      this.productId = productId
      this.current = null
      this.query = { keyword: '', category_id: '', status: 'enabled', page: 1, per_page: 10 }
      this.visible = true
      await this.load()
    },
    async openGlobalSku() {
      this.mode = 'global_sku'
      this.productId = null
      this.current = null
      this.query = { keyword: '', category_id: '', status: 'enabled', page: 1, per_page: 10 }
      this.visible = true
      await this.load()
    },
    queueLoad() {
      clearTimeout(this.searchTimer)
      this.searchTimer = setTimeout(() => { this.query.page = 1; this.load() }, 280)
    },
    async loadCategories() {
      const { data } = await listEntity('categories', { category_type: 'product', status: 'enabled', per_page: 100 })
      this.categories = data.data || []
    },
    async load() {
      const requestToken = ++this.requestToken
      const params = { ...this.query, order_available: 1 }
      if (this.mode === 'global_sku') {
        const { data } = await searchSalesOrderSkus(params)
        if (requestToken !== this.requestToken) return
        this.rows = data.data || []
        this.page = { total: data.total || 0 }
        return
      }
      if (this.mode === 'sku') {
        params.product_id = this.productId
        params.order_available = 1
      }
      if (!params.category_id) delete params.category_id
      const { data } = await listEntity(this.mode === 'product' ? 'products' : 'skus', params)
      if (requestToken !== this.requestToken) return
      this.rows = data.data || []
      this.page = { total: data.total || 0 }
    },
    confirm() {
      if (!this.current) return
      this.$emit('select', { mode: this.mode, row: this.current })
      this.visible = false
    }
  }
}
</script>

<style scoped>
.picker-filters{display:grid;grid-template-columns:1fr 160px 140px 82px auto;gap:10px;margin-bottom:12px;align-items:center}.picker-tip{color:#64748b}.picker-img{width:54px;height:44px;border:1px solid #e4e9f0;border-radius:4px;display:grid;place-items:center;color:#94a3b8;background:#f8fafc}.picker-img img{max-width:100%;max-height:100%;object-fit:cover}.picker-footer{display:flex;justify-content:space-between;align-items:center;margin-top:12px}.dash{color:#94a3b8}.sales-picker-dialog :deep(.el-dialog__header){border-bottom:1px solid #edf0f4}.sales-picker-dialog :deep(.el-table th){background:#f8fafc;color:#334155}.sales-picker-dialog :deep(.el-button--success){background:#00984f;border-color:#00984f}
</style>
