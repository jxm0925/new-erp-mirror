<template>
  <div class="product-page-container">
    <!-- 页面全局头部：图标、标题、统计标签与主要操作 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-goods" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">商品档案</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">共 {{ total }} 款商品</el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="fetchAll">刷新</el-button>
        <el-button size="small" icon="el-icon-upload2" class="btn-import" @click="$router.push({ path: '/master/imports', query: { type: 'Product' } })">导入商品</el-button>
        <el-button size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openProductCreate">新增商品</el-button>
      </div>
    </header>

    <!-- 统一页面提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>维护标准商品与套装商品档案，管理规格维度与笛卡尔积 SKU，打通采购、销售与生产主数据链路。</span>
    </div>

    <!-- 顶部概览指标卡片 -->
    <section class="metric-overview-grid">
      <div class="metric-card metric-all">
        <div class="metric-icon-box"><i class="el-icon-s-goods" /></div>
        <div class="metric-info">
          <span class="metric-label">全部商品</span>
          <strong class="metric-val">{{ total }}</strong>
        </div>
      </div>
      <div class="metric-card metric-enabled">
        <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
        <div class="metric-info">
          <span class="metric-label">已启用商品</span>
          <strong class="metric-val">{{ enabledCount }}</strong>
        </div>
      </div>
      <div class="metric-card metric-disabled">
        <div class="metric-icon-box"><i class="el-icon-circle-close" /></div>
        <div class="metric-info">
          <span class="metric-label">已停用商品</span>
          <strong class="metric-val">{{ disabledCount }}</strong>
        </div>
      </div>
      <div class="metric-card metric-skus">
        <div class="metric-icon-box"><i class="el-icon-box" /></div>
        <div class="metric-info">
          <span class="metric-label">当前页SKU总计</span>
          <strong class="metric-val">{{ totalSkusCount }}</strong>
        </div>
      </div>
    </section>

    <!-- 搜索筛选与操作栏 -->
    <section class="table-container-card">
      <div class="filter-toolbar">
        <div class="filter-fields">
          <el-input
            v-model="filters.keyword"
            size="small"
            clearable
            prefix-icon="el-icon-search"
            placeholder="搜索商品编码、商品名称、型号..."
            class="filter-input-search"
            @keyup.enter.native="applyFilters"
            @clear="applyFilters"
          />
          <el-select
            v-model="filters.category_id"
            size="small"
            clearable
            placeholder="所属分类"
            class="filter-select"
            @change="applyFilters"
          >
            <el-option v-for="c in categories" :key="c.id" :label="c.category_name" :value="c.id" />
          </el-select>
          <el-select
            v-model="filters.status"
            size="small"
            clearable
            placeholder="销售状态"
            class="filter-select-sm"
            @change="applyFilters"
          >
            <el-option label="全部状态" value="" />
            <el-option label="已启用" value="enabled" />
            <el-option label="已停用" value="disabled" />
          </el-select>
        </div>
        <div class="filter-actions">
          <el-button size="small" type="primary" icon="el-icon-search" @click="applyFilters">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" @click="resetFilters">重置</el-button>
          <el-button size="small" icon="el-icon-refresh" circle title="刷新列表" @click="fetchAll" />
        </div>
      </div>

      <!-- 主数据商品列表 -->
      <div class="main-table-wrap">
        <el-table
          v-loading="loading"
          :data="products"
          size="small"
          border
          row-key="id"
          :expand-row-keys="expandedKeys"
          class="custom-product-table"
          empty-text="暂无商品档案，请点击上方“新增商品”录入或“导入商品”。"
          @expand-change="onExpandChange"
        >
          <!-- 展开行：对应商品的 SKU 规格子表格 -->
          <el-table-column type="expand" width="48">
            <template slot-scope="{ row }">
              <div class="sku-nested-panel">
                <div class="nested-panel-header">
                  <div class="nested-title">
                    <span class="icon-chip"><i class="el-icon-box" /></span>
                    <strong>「{{ row.product_name }}」规格 SKU 列表</strong>
                    <el-tag size="mini" type="info" effect="plain">共 {{ skuTotal(row) }} 条规格</el-tag>
                    <span class="sub-hint"><i class="el-icon-info" /> 点击行或操作按钮可直达 SKU 独立详情与编辑页</span>
                  </div>
                  <div class="nested-actions">
                    <el-button size="mini" type="primary" plain icon="el-icon-plus" @click.stop="openSkuCreate(row)">单独新增SKU</el-button>
                    <el-button size="mini" type="success" plain icon="el-icon-s-grid" @click.stop="openSkuMatrix(row)">生成SKU矩阵</el-button>
                  </div>
                </div>

                <el-table
                  v-loading="skuPage(row).loading"
                  :data="skuList(row)"
                  size="mini"
                  border
                  class="sku-inner-table"
                  empty-text="当前商品尚未添加规格 SKU，可点击右上角新增或矩阵生成。"
                  @row-click="onSkuRowClick(row, $event)"
                >
                  <el-table-column prop="sku_code" label="SKU编码" min-width="130">
                    <template slot-scope="{ row: sku }">
                      <span class="sku-code-badge font-mono">{{ sku.sku_code }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column prop="spec_text" label="规格型号 / 名称" min-width="160">
                    <template slot-scope="{ row: sku }">
                      <span class="sku-spec-text">{{ sku.spec_text || sku.sku_name || '-' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="计量单位" width="95" align="center">
                    <template slot-scope="{ row: sku }">
                      <span class="unit-badge">{{ (sku.sales_unit && sku.sales_unit.unit_name) || (row.unit && row.unit.unit_name) || '-' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="默认售价" width="115" align="right">
                    <template slot-scope="{ row: sku }">
                      <span class="price-val">¥{{ Number(sku.sale_price || 0).toFixed(2) }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="关联Item物料" width="125" align="center">
                    <template slot-scope="{ row: sku }">
                      <el-tag v-if="relationList(sku).length" size="mini" type="success" effect="plain">已绑定 {{ relationList(sku).length }} 个物料</el-tag>
                      <el-tag v-else size="mini" type="warning" effect="plain">未绑定物料</el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column label="状态" width="80" align="center">
                    <template slot-scope="{ row: sku }">
                      <span class="status-pill" :class="sku.status">{{ statusText(sku.status) }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="操作" min-width="160" align="center">
                    <template slot-scope="{ row: sku }">
                      <el-button type="text" size="mini" icon="el-icon-view" @click.stop="openSkuDetail(sku)">详情</el-button>
                      <el-button type="text" size="mini" icon="el-icon-edit" @click.stop="openSkuEdit(sku)">编辑</el-button>
                      <el-button
                        type="text"
                        size="mini"
                        :class="sku.status === 'enabled' ? 'danger-link' : 'success-link'"
                        @click.stop="disableSku(sku)"
                      >
                        {{ sku.status === 'enabled' ? '停用' : '启用' }}
                      </el-button>
                      <el-button v-if="sku.status !== 'enabled'" type="text" size="mini" class="danger-link" @click.stop="deleteSku(sku)">删除</el-button>
                    </template>
                  </el-table-column>
                </el-table>

                <div v-if="skuTotal(row) > 5" class="nested-pagination">
                  <span>共 {{ skuTotal(row) }} 条规格 SKU</span>
                  <el-pagination
                    small
                    layout="prev, pager, next, sizes"
                    :current-page="skuPage(row).page"
                    :page-size="skuPage(row).per_page"
                    :page-sizes="[5, 10, 20]"
                    :total="skuTotal(row)"
                    @current-change="changeSkuPage(row, $event)"
                    @size-change="changeSkuPageSize(row, $event)"
                  />
                </div>
              </div>
            </template>
          </el-table-column>

          <el-table-column prop="product_code" label="商品编码" min-width="140">
            <template slot-scope="{ row }">
              <span class="product-code-link font-mono" @click.stop="openProductDetail(row)">
                <i class="el-icon-goods" /> {{ row.product_code }}
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="product_name" label="商品名称" min-width="200">
            <template slot-scope="{ row }">
              <div class="product-name-cell">
                <span class="p-name" :title="row.product_name">{{ row.product_name }}</span>
                <span v-if="row.model" class="p-model"><i class="el-icon-price-tag" /> {{ row.model }}</span>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="所属分类" min-width="120">
            <template slot-scope="{ row }">
              <el-tag v-if="row.category" size="small" type="info" effect="plain" class="category-tag">
                <i class="el-icon-folder" /> {{ row.category.category_name }}
              </el-tag>
              <span v-else class="text-muted">—</span>
            </template>
          </el-table-column>
          <el-table-column label="商品类型" width="105" align="center">
            <template slot-scope="{ row }">
              <el-tag size="mini" :type="row.product_type === 'bundle' ? 'warning' : 'primary'" effect="plain">
                {{ row.product_type === 'bundle' ? '套装商品' : '标准商品' }}
              </el-tag>
            </template>
          </el-table-column>
          <el-table-column label="计量单位" width="95" align="center">
            <template slot-scope="{ row }">
              <span class="unit-badge">{{ row.unit ? row.unit.unit_name : '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="SKU规格数" width="125" align="center">
            <template slot-scope="{ row }">
              <button type="button" class="sku-count-chip" title="点击展开/收起 SKU 列表" @click.stop="toggleRowExpand(row)">
                <i class="el-icon-menu" /> {{ skuTotal(row) }} 款 SKU
              </button>
            </template>
          </el-table-column>
          <el-table-column label="销售状态" width="95" align="center">
            <template slot-scope="{ row }">
              <span class="status-pill" :class="row.status">{{ statusText(row.status) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="更新时间" width="145" align="center">
            <template slot-scope="{ row }">
              <span class="time-text">{{ formatDate(row.updated_at) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="160" align="center">
            <template slot-scope="{ row }">
              <el-button type="text" size="small" icon="el-icon-view" @click.stop="openProductDetail(row)">详情</el-button>
              <el-button type="text" size="small" icon="el-icon-edit" @click.stop="openProductEdit(row)">编辑</el-button>
              <el-button
                type="text"
                size="small"
                :class="row.status === 'enabled' ? 'danger-link' : 'success-link'"
                @click.stop="disableProduct(row)"
              >
                {{ row.status === 'enabled' ? '停用' : '启用' }}
              </el-button>
              <el-button v-if="row.status !== 'enabled'" type="text" size="small" class="danger-link" @click.stop="deleteProduct(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 分页栏 -->
      <div class="table-pagination-footer">
        <span class="total-text">共 <strong>{{ total }}</strong> 款商品</span>
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :current-page="filters.page"
          :page-size="filters.per_page"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          @current-change="changePage"
          @size-change="changePageSize"
        />
      </div>
    </section>

    <!-- 商品详情模态弹窗 (替换原有侧边抽屉) -->
    <el-dialog
      :visible.sync="detailDialogVisible"
      title="商品档案详情"
      width="780px"
      custom-class="product-detail-modal"
      :close-on-click-modal="true"
      destroy-on-close
    >
      <div v-if="selectedProduct.id" class="product-modal-content">
        <!-- 弹窗头部高亮看板 -->
        <div class="modal-summary-banner">
          <div class="banner-icon"><i class="el-icon-goods" /></div>
          <div class="banner-main">
            <div class="banner-title-line">
              <h2>{{ selectedProduct.product_name }}</h2>
              <span class="status-pill" :class="selectedProduct.status">{{ statusText(selectedProduct.status) }}</span>
              <el-tag size="mini" :type="selectedProduct.product_type === 'bundle' ? 'warning' : 'primary'" effect="plain">
                {{ selectedProduct.product_type === 'bundle' ? '套装商品' : '标准商品' }}
              </el-tag>
            </div>
            <div class="banner-sub-meta">
              <span>商品编码：<strong>{{ selectedProduct.product_code }}</strong></span>
              <span v-if="selectedProduct.model">型号：<strong>{{ selectedProduct.model }}</strong></span>
              <span>计量单位：<strong>{{ selectedProduct.unit ? selectedProduct.unit.unit_name : '-' }}</strong></span>
            </div>
          </div>
        </div>

        <!-- 详细信息卡片 -->
        <div class="modal-detail-sections">
          <div class="detail-section-card">
            <h4 class="sec-title"><i class="el-icon-document" /> 基本档案资料</h4>
            <div class="property-grid">
              <div class="prop-item"><span class="prop-label">商品编码</span><span class="prop-value font-mono">{{ selectedProduct.product_code }}</span></div>
              <div class="prop-item"><span class="prop-label">商品名称</span><span class="prop-value">{{ selectedProduct.product_name }}</span></div>
              <div class="prop-item"><span class="prop-label">所属分类</span><span class="prop-value">{{ selectedProduct.category ? selectedProduct.category.category_name : '-' }}</span></div>
              <div class="prop-item"><span class="prop-label">计量单位</span><span class="prop-value">{{ selectedProduct.unit ? selectedProduct.unit.unit_name : '-' }}</span></div>
              <div class="prop-item"><span class="prop-label">规格型号</span><span class="prop-value">{{ selectedProduct.model || '-' }}</span></div>
              <div class="prop-item"><span class="prop-label">销售状态</span><span class="prop-value">{{ statusText(selectedProduct.status) }}</span></div>
              <div class="prop-item"><span class="prop-label">创建时间</span><span class="prop-value">{{ formatDate(selectedProduct.created_at) }}</span></div>
              <div class="prop-item"><span class="prop-label">更新时间</span><span class="prop-value">{{ formatDate(selectedProduct.updated_at) }}</span></div>
              <div class="prop-item full-width"><span class="prop-label">商品描述</span><span class="prop-value text-desc">{{ selectedProduct.description || '暂无描述信息' }}</span></div>
            </div>
          </div>

          <div class="detail-section-card">
            <div class="sec-title-bar">
              <h4 class="sec-title"><i class="el-icon-connection" /> SKU 规格结构速览（共 {{ skuTotal(selectedProduct) }} 条）</h4>
              <el-button type="text" size="mini" icon="el-icon-plus" @click="openSkuCreate(selectedProduct)">新增SKU</el-button>
            </div>
            <div v-if="skuList(selectedProduct).length" class="modal-sku-preview-table">
              <el-table :data="skuList(selectedProduct)" size="mini" border max-height="220">
                <el-table-column prop="sku_code" label="SKU编码" width="140">
                  <template slot-scope="{ row }"><span class="font-mono">{{ row.sku_code }}</span></template>
                </el-table-column>
                <el-table-column label="规格型号 / 名称" min-width="140">
                  <template slot-scope="{ row }">{{ row.spec_text || row.sku_name || '-' }}</template>
                </el-table-column>
                <el-table-column label="售价" width="90" align="right">
                  <template slot-scope="{ row }">¥{{ Number(row.sale_price || 0).toFixed(2) }}</template>
                </el-table-column>
                <el-table-column label="状态" width="75" align="center">
                  <template slot-scope="{ row }">
                    <span class="status-pill" :class="row.status">{{ statusText(row.status) }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="操作" width="110" align="center">
                  <template slot-scope="{ row }">
                    <el-button type="text" size="mini" @click="openSkuDetail(row)">详情</el-button>
                    <el-button type="text" size="mini" @click="openSkuEdit(row)">编辑</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </div>
            <p v-else class="empty-sku-hint">该商品暂未配置规格 SKU，可点击下方按钮单独新增或批量生成 SKU 矩阵。</p>
          </div>
        </div>
      </div>
      <div slot="footer" class="dialog-footer">
        <el-button size="small" @click="detailDialogVisible = false">关闭</el-button>
        <el-button size="small" icon="el-icon-s-grid" @click="openSkuMatrix(selectedProduct)">生成SKU矩阵</el-button>
        <el-button size="small" icon="el-icon-plus" @click="openSkuCreate(selectedProduct)">新增SKU</el-button>
        <el-button size="small" type="primary" icon="el-icon-edit" @click="openProductEdit(selectedProduct)">编辑此商品</el-button>
      </div>
    </el-dialog>

    <!-- SKU 矩阵生成弹窗 -->
    <el-dialog
      :visible.sync="matrixDialogVisible"
      title="生成 SKU 规格矩阵"
      width="820px"
      custom-class="sku-matrix-modal"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <div v-if="selectedProduct.id" class="matrix-modal-content">
        <div class="matrix-tips-box">
          <i class="el-icon-info" />
          <span>系统将依据下方配置的规格维度按笛卡尔积排列组合批量生成 SKU。生成的 SKU 将继承商品计量单位 <strong>{{ selectedProduct.unit && selectedProduct.unit.unit_name || '未维护' }}</strong>，初始状态为草稿。</span>
        </div>

        <section class="matrix-block">
          <div class="block-title-row">
            <h5>规格维度定义</h5>
            <el-button size="mini" type="primary" plain icon="el-icon-plus" @click="addSkuDimension">新增规格维度</el-button>
          </div>
          <div v-for="(dim, index) in skuMatrix.dimensions" :key="index" class="dimension-edit-row">
            <span class="dim-num">维度 {{ index + 1 }}</span>
            <el-input v-model="dim.name" size="small" placeholder="规格名称（如：颜色、尺码）" style="width: 180px;" />
            <el-input v-model="dim.valuesText" size="small" placeholder="规格取值，用逗号分隔（如：哑光黑,亮光银,曜石蓝）" style="flex: 1;" />
            <el-button type="text" class="danger-link" icon="el-icon-delete" @click="skuMatrix.dimensions.splice(index, 1)">删除</el-button>
          </div>
        </section>

        <section class="matrix-block">
          <h5>生成规则</h5>
          <el-form label-width="90px" size="small" class="matrix-rules-form" inline>
            <el-form-item label="编码前缀">
              <el-input v-model="skuMatrix.codePrefix" placeholder="如 SKU" style="width: 160px;" />
            </el-form-item>
            <el-form-item label="默认售价">
              <el-input-number v-model="skuMatrix.sale_price" :min="0" :precision="2" controls-position="right" style="width: 140px;" />
            </el-form-item>
          </el-form>
        </section>

        <section class="matrix-block">
          <h5>组合实时预览（{{ skuMatrixRows.length }} 个 SKU）</h5>
          <el-table :data="skuMatrixRows" size="mini" border max-height="240" empty-text="请在上方输入规格维度和值以实时预览笛卡尔积组合">
            <el-table-column prop="sku_code" label="SKU编码预览" min-width="140" />
            <el-table-column prop="sku_name" label="SKU名称" min-width="160" show-overflow-tooltip />
            <el-table-column prop="spec_model" label="规格组合" min-width="140" show-overflow-tooltip />
            <el-table-column prop="sale_price" label="销售价" width="90" align="right">
              <template slot-scope="{ row }">¥{{ Number(row.sale_price || 0).toFixed(2) }}</template>
            </el-table-column>
            <el-table-column label="状态" width="90" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="row.can_generate ? 'success' : 'warning'" effect="plain">
                  {{ row.preview_status }}
                </el-tag>
              </template>
            </el-table-column>
          </el-table>
        </section>
      </div>
      <div slot="footer" class="dialog-footer">
        <el-button size="small" @click="matrixDialogVisible = false">取消</el-button>
        <el-button size="small" type="success" :loading="saving" :disabled="!skuMatrixRows.length" icon="el-icon-check" @click="saveSkuMatrix">
          确认批量生成（{{ creatableMatrixCount }} 个）
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { saveProductSkuMatrix } from '../../../api/erp/master'
import { listEntity, saveEntity, disableEntity, enableEntity, deleteEntity } from '../../../api/erp/master'
import { reserveFreshDocumentNumber } from '../../../utils/documentNumberReservation'

export default {
  name: 'ProductList',
  data() {
    return {
      loading: false,
      saving: false,
      detailDialogVisible: false,
      matrixDialogVisible: false,
      products: [],
      categories: [],
      units: [],
      skuPages: {},
      selectedProduct: {},
      expandedKeys: [],
      filters: { keyword: '', category_id: '', status: '', page: 1, per_page: 10 },
      total: 0,
      stats: {},
      skuMatrix: { codePrefix: '', sale_price: 0, status: 'enabled', dimensions: [] }
    }
  },
  computed: {
    enabledCount () {
      return Number(this.stats.enabled || 0)
    },
    disabledCount () {
      return Number(this.stats.disabled || 0)
    },
    totalSkusCount() {
      return this.products.reduce((acc, cur) => acc + Number(cur.skus_count || 0), 0)
    },
    creatableMatrixCount() {
      return this.skuMatrixRows.filter(r => r.can_generate).length
    },
    skuMatrixRows() {
      if (!this.matrixDialogVisible) return []
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
  },
  methods: {
    async fetchAll() {
      this.loading = true
      try {
        const [products, categories, units] = await Promise.all([
          listEntity('products', { ...this.filters, include_stats: 1 }),
          listEntity('categories', { per_page: 100, category_type: 'product' }),
          listEntity('units', { per_page: 100 })
        ])
        this.products = products.data.data || []
        this.total = products.data.total || 0
        this.stats = products.data.stats || {}
        this.categories = categories.data.data || []
        this.units = units.data.data || []

        for (const expandedId of this.expandedKeys) {
          const expandedProduct = this.products.find(item => Number(item.id) === Number(expandedId))
          if (expandedProduct) await this.loadSkuPage(expandedProduct, this.skuPage(expandedProduct).page)
        }
      } catch (e) {
        this.$message.error(e.userMessage || '商品数据加载失败')
      } finally {
        this.loading = false
      }
    },
    applyFilters() {
      this.filters.page = 1
      this.fetchAll()
    },
    resetFilters() {
      this.filters = { keyword: '', category_id: '', status: '', page: 1, per_page: this.filters.per_page }
      this.fetchAll()
    },
    changePage(page) {
      this.filters.page = page
      this.fetchAll()
    },
    changePageSize(size) {
      this.filters.per_page = size
      this.filters.page = 1
      this.fetchAll()
    },
    skuPage(row) {
      return this.skuPages[row.id] || { rows: [], total: Number(row.skus_count || 0), page: 1, per_page: 5, loading: false }
    },
    skuList(row) {
      return this.skuPage(row).rows
    },
    skuTotal(row) {
      const page = this.skuPages[row.id]
      return page ? Number(page.total || 0) : Number(row.skus_count || 0)
    },
    async loadSkuPage(row, page = 1, perPage = null) {
      const current = this.skuPage(row)
      const state = { ...current, page, per_page: perPage || current.per_page || 5, loading: true }
      this.$set(this.skuPages, row.id, state)
      try {
        const response = await listEntity('skus', { product_id: row.id, page: state.page, per_page: state.per_page })
        this.$set(this.skuPages, row.id, {
          rows: response.data.data || [],
          total: Number(response.data.total || 0),
          page: Number(response.data.current_page || state.page),
          per_page: Number(response.data.per_page || state.per_page),
          loading: false
        })
      } catch (e) {
        this.$set(this.skuPages, row.id, { ...state, loading: false })
        this.$message.error(e.userMessage || 'SKU 列表加载失败')
      }
    },
    changeSkuPage(row, page) {
      this.loadSkuPage(row, page)
    },
    changeSkuPageSize(row, size) {
      this.loadSkuPage(row, 1, size)
    },
    relationList(sku) {
      return (sku.item_relations || sku.itemRelations || []).filter(relation => relation.status === 'active')
    },
    onExpandChange(row, expanded) {
      this.expandedKeys = expanded.map(item => item.id)
      if (expanded.some(item => Number(item.id) === Number(row.id))) {
        this.loadSkuPage(row, this.skuPage(row).page)
      }
    },
    toggleRowExpand(row) {
      const isExpanded = this.expandedKeys.includes(row.id)
      if (isExpanded) {
        this.expandedKeys = this.expandedKeys.filter(id => id !== row.id)
      } else {
        this.expandedKeys.push(row.id)
        this.loadSkuPage(row, this.skuPage(row).page)
      }
    },
    openProductDetail(row) {
      this.selectedProduct = { ...row }
      this.detailDialogVisible = true
      this.loadSkuPage(row, 1)
    },
    openProductCreate() {
      this.$router.push('/master/products/new')
    },
    openProductEdit(row) {
      this.detailDialogVisible = false
      this.$router.push(`/master/products/${row.id}/edit`)
    },
    openSkuCreate(product) {
      this.detailDialogVisible = false
      this.$router.push({ path: '/master/skus/new', query: { product_id: product.id, from: 'product' } })
    },
    openSkuMatrix(product) {
      this.selectedProduct = { ...product }
      this.skuMatrix = {
        codePrefix: product.product_code || 'SKU',
        sale_price: null,
        status: 'draft',
        dimensions: [
          { name: '规格', valuesText: '' }
        ]
      }
      this.matrixDialogVisible = true
    },
    addSkuDimension() {
      this.skuMatrix.dimensions.push({ name: '', valuesText: '' })
    },
    openSkuDetail(sku) {
      this.detailDialogVisible = false
      this.$router.push({ path: `/master/skus/${sku.id}`, query: { from: 'product' } })
    },
    openSkuEdit(sku) {
      this.detailDialogVisible = false
      this.$router.push({ path: `/master/skus/${sku.id}/edit`, query: { from: 'product' } })
    },
    onSkuRowClick(row, sku) {
      this.openSkuDetail(sku)
    },
    async saveSkuMatrix() {
      if (this.saving) return
      const rows = this.skuMatrixRows
      if (!rows.length) return this.$message.error('请先维护规格维度和值')
      if (!this.selectedProduct.unit_id) return this.$message.error('请先在商品档案维护计量单位，SKU矩阵将统一继承该单位')
      if (this.skuMatrix.sale_price === null || this.skuMatrix.sale_price === '') return this.$message.error('请维护默认销售价格')
      const creatableRows = rows.filter(row => row.can_generate)
      if (!creatableRows.length) return this.$message.warning('没有可生成的 SKU，预览中的组合均已存在或重复')
      this.saving = true
      try {
        const productId = this.selectedProduct.id
        const matrix = []
        for (const row of creatableRows) {
          const reservation = await reserveFreshDocumentNumber('sku', `/master/products/${productId}#sku-matrix`)
          matrix.push({ sku_name: row.sku_name, spec_text: row.spec_model, sale_price: row.sale_price,
            sku_code: reservation.document_no, reservation_token: reservation.reservation_token,
            creation_session_id: reservation.creation_session_id })
        }
        await saveProductSkuMatrix(productId, matrix)
        this.$message.success(`已生成 ${creatableRows.length} 个 SKU，跳过 ${rows.length - creatableRows.length} 个已存在/重复项`)
        this.matrixDialogVisible = false
        await this.fetchAll()
        const p = this.products.find(x => x.id === this.selectedProduct.id)
        if (p) this.loadSkuPage(p, 1)
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
    normalSpec(value) {
      return String(value || '').replace(/\s+/g, '').toLowerCase()
    },
    async toggleProductStatus(row) {
      const enabling = row.status !== 'enabled'
      try {
        await this.$confirm(
          enabling ? '确认启用该商品？' : '确认停用该商品？',
          enabling ? '启用确认' : '停用确认',
          { type: enabling ? 'success' : 'warning' }
        )
        await (enabling ? enableEntity : disableEntity)('products', row.id)
        await this.fetchAll()
        this.$message.success(enabling ? '商品已启用' : '商品已停用')
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || (enabling ? '启用失败' : '停用失败'))
      }
    },
    async toggleSkuStatus(row) {
      const enabling = row.status !== 'enabled'
      try {
        await this.$confirm(
          enabling ? '确认启用该 SKU？' : '确认停用该 SKU？',
          enabling ? '启用确认' : '停用确认',
          { type: enabling ? 'success' : 'warning' }
        )
        await (enabling ? enableEntity : disableEntity)('skus', row.id)
        await this.fetchAll()
        this.$message.success(enabling ? 'SKU 已启用' : 'SKU 已停用')
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || (enabling ? '启用失败' : '停用失败'))
      }
    },
    async deleteSku(row) {
      try {
        await this.$confirm(
          `确认删除 SKU「${row.sku_code}」？仅未启用且从未被订单、BOM、定制或默认 Item 关系引用的 SKU 可以删除。`,
          '删除确认',
          { type: 'warning', confirmButtonText: '确认删除' }
        )
        await deleteEntity('skus', row.id)
        this.$message.success('SKU 已删除')
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || 'SKU 删除失败')
      }
    },
    async deleteProduct(row) {
      try {
        await this.$confirm(
          `确认删除商品「${row.product_code}」？只有停用且没有 SKU、订单或 BOM 引用的商品可以删除。`,
          '删除确认',
          { type: 'warning', confirmButtonText: '确认删除' }
        )
        await deleteEntity('products', row.id)
        this.$message.success('商品已删除')
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '商品删除失败')
      }
    },
    disableProduct(row) {
      return this.toggleProductStatus(row)
    },
    disableSku(row) {
      return this.toggleSkuStatus(row)
    },
    statusText(status) {
      return ({ enabled: '启用', disabled: '停用', draft: '草稿' })[status] || status
    },
    formatDate(v) {
      return v ? String(v).replace('T', ' ').slice(0, 16) : '-'
    }
  }
}
</script>

<style scoped>
.product-page-container {
  padding: 16px 20px;
  background: #f8fafc;
  min-height: calc(100vh - 90px);
  box-sizing: border-box;
}

/* 页面头部 */
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

.btn-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-refresh:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.btn-import {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-import:hover {
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

/* 概览统计指标卡 */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 16px;
}

.metric-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  transition: transform 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}

.metric-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
  border-color: #cbd5e1;
}

.metric-icon-box {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  font-size: 20px;
  flex-shrink: 0;
}

.metric-all .metric-icon-box { background: #eaf7ef; color: #008b4b; }
.metric-enabled .metric-icon-box { background: #f0fdf4; color: #059669; }
.metric-disabled .metric-icon-box { background: #f1f5f9; color: #64748b; }
.metric-skus .metric-icon-box { background: #f0fdfa; color: #0f766e; }

.metric-info {
  display: flex;
  flex-direction: column;
}

.metric-label {
  font-size: 12px;
  color: #64748b;
}

.metric-val {
  font-size: 22px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

/* 主数据卡片容器 */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  overflow: hidden;
}

/* 筛选工具栏 */
.filter-toolbar {
  padding: 14px 18px;
  background: #ffffff;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.filter-fields {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  flex: 1;
}

.filter-input-search {
  width: 280px;
}

.filter-select {
  width: 170px;
}

.filter-select-sm {
  width: 120px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

/* 表格主体 */
.main-table-wrap {
  width: 100%;
  overflow-x: auto;
}

.custom-product-table {
  width: 100%;
}

.font-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.3px;
}

.product-code-link {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.4px;
  font-variant-numeric: tabular-nums;
  color: #00763f;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border-radius: 4px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  transition: all 0.15s ease;
  line-height: 1.4;
  white-space: nowrap;
}

.product-code-link:hover {
  background: #dcfce7;
  border-color: #86efac;
  color: #00562e;
  box-shadow: 0 1px 4px rgba(0, 118, 63, 0.15);
}

.product-code-link i {
  font-size: 13px;
  color: #008b4b;
  flex-shrink: 0;
}

.product-name-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.p-name {
  font-weight: 600;
  color: #0f172a;
}

.p-model {
  font-size: 11px;
  color: #64748b;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.category-tag {
  border-radius: 4px;
  font-size: 12px;
}

.unit-badge {
  background: #f1f5f9;
  color: #475569;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
}

.sku-count-chip {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #166534;
  padding: 3px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  transition: all 0.2s;
}

.sku-count-chip:hover {
  background: #dcfce7;
  border-color: #86efac;
}

.status-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 500;
}

.status-pill:before {
  content: '';
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #008b4b;
}

.status-pill.disabled:before {
  background: #94a3b8;
}

.status-pill.draft:before {
  background: #f59e0b;
}

.time-text {
  font-size: 12px;
  color: #64748b;
}

.text-muted {
  color: #94a3b8;
}

/* 嵌套 SKU 面板 */
.sku-nested-panel {
  padding: 14px 16px;
  background: #f8fafc;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
  margin: 6px 10px;
}

.nested-panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
  gap: 12px;
  flex-wrap: wrap;
}

.nested-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #0f172a;
}

.icon-chip {
  width: 22px;
  height: 22px;
  background: #e2e8f0;
  border-radius: 4px;
  display: grid;
  place-items: center;
  font-size: 13px;
  color: #475569;
}

.sub-hint {
  font-size: 11px;
  color: #94a3b8;
  margin-left: 8px;
}

.nested-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.sku-inner-table {
  background: #ffffff;
}

.sku-code-badge {
  color: #0f172a;
  font-weight: 600;
}

.sku-spec-text {
  color: #334155;
  font-size: 12px;
}

.price-val {
  color: #b91c1c;
  font-weight: 600;
  font-family: ui-monospace, SFMono-Regular, monospace;
}

.nested-pagination {
  margin-top: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  color: #64748b;
}

/* 表格底部分页栏 */
.table-pagination-footer {
  padding: 12px 18px;
  background: #ffffff;
  border-top: 1px solid #f1f5f9;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.total-text {
  font-size: 13px;
  color: #64748b;
}

.total-text strong {
  color: #0f172a;
}

/* 查询按钮与主要操作按钮定制为 ERP 主题绿 */
::v-deep .filter-actions .el-button--primary,
::v-deep .nested-actions .el-button--primary,
::v-deep .table-container-card .el-button--primary,
::v-deep .product-detail-modal .el-button--primary {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  transition: all 0.2s ease;
}

::v-deep .filter-actions .el-button--primary:hover,
::v-deep .filter-actions .el-button--primary:focus,
::v-deep .nested-actions .el-button--primary:hover,
::v-deep .nested-actions .el-button--primary:focus,
::v-deep .table-container-card .el-button--primary:hover,
::v-deep .table-container-card .el-button--primary:focus,
::v-deep .product-detail-modal .el-button--primary:hover,
::v-deep .product-detail-modal .el-button--primary:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
  color: #ffffff !important;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.25);
}

::v-deep .filter-actions .el-button--primary:active,
::v-deep .nested-actions .el-button--primary:active {
  background-color: #006233 !important;
  border-color: #006233 !important;
}

/* 朴素主要按钮（如展开面板中的单独新增SKU） */
::v-deep .el-button--primary.is-plain {
  background-color: #eaf7ef !important;
  border-color: #b7ebc7 !important;
  color: #008b4b !important;
}

::v-deep .el-button--primary.is-plain:hover,
::v-deep .el-button--primary.is-plain:focus {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.2);
}

/* 分页组件：当前激活页码与悬停样式适配 ERP 主题绿 */
::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
}

::v-deep .el-pagination.is-background .el-pager li:not(.disabled):hover {
  color: #008b4b !important;
}

::v-deep .el-pagination .el-select .el-input.is-focus .el-input__inner,
::v-deep .el-pagination__sizes .el-input .el-input__inner:focus,
::v-deep .el-pagination__editor.el-input .el-input__inner:focus {
  border-color: #008b4b !important;
}

/* 输入框与选择器聚焦适配主题绿 */
::v-deep .el-input.is-active .el-input__inner,
::v-deep .el-input__inner:focus,
::v-deep .el-select .el-input.is-focus .el-input__inner {
  border-color: #008b4b !important;
  box-shadow: 0 0 0 2px rgba(0, 139, 75, 0.12);
}

/* 标签样式适配 */
::v-deep .el-tag--primary.el-tag--plain {
  background-color: #eaf7ef !important;
  border-color: #b7ebc7 !important;
  color: #008b4b !important;
}

/* 表格文本按钮与操作高亮 */
::v-deep .el-button--text:not(.danger-link):not(.success-link) {
  color: #008b4b;
}

::v-deep .el-button--text:not(.danger-link):not(.success-link):hover,
::v-deep .el-button--text:not(.danger-link):not(.success-link):focus {
  color: #00763f;
}

/* 商品详情模态弹窗样式 */
::v-deep .product-detail-modal .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
}

::v-deep .product-detail-modal .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

::v-deep .product-detail-modal .el-dialog__body {
  padding: 18px 20px;
  background: #f8fafc;
  max-height: 72vh;
  overflow-y: auto;
}

.modal-summary-banner {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  margin-bottom: 14px;
}

.banner-icon {
  width: 44px;
  height: 44px;
  border-radius: 10px;
  background: #eaf7ef;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}

.banner-main {
  flex: 1;
  min-width: 0;
}

.banner-title-line {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 4px;
}

.banner-title-line h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.banner-sub-meta {
  display: flex;
  align-items: center;
  gap: 16px;
  font-size: 12px;
  color: #64748b;
}

.banner-sub-meta strong {
  color: #1e293b;
}

.modal-detail-sections {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.detail-section-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
}

.sec-title-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.sec-title {
  margin: 0 0 10px;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}

.property-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px 18px;
}

.prop-item {
  display: flex;
  align-items: baseline;
  font-size: 13px;
}

.prop-item.full-width {
  grid-column: 1 / -1;
}

.prop-label {
  width: 80px;
  color: #64748b;
  flex-shrink: 0;
}

.prop-value {
  color: #0f172a;
  word-break: break-all;
}

.text-desc {
  color: #475569;
  line-height: 1.5;
}

.empty-sku-hint {
  margin: 10px 0;
  font-size: 12px;
  color: #94a3b8;
  text-align: center;
}

/* SKU 矩阵模态框 */
.matrix-tips-box {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #166534;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 12px;
  line-height: 1.5;
  margin-bottom: 14px;
  display: flex;
  gap: 8px;
  align-items: flex-start;
}

.matrix-tips-box i {
  font-size: 15px;
  margin-top: 2px;
}

.matrix-block {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 14px;
  margin-bottom: 12px;
}

.matrix-block h5 {
  margin: 0 0 10px;
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
}

.block-title-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.dimension-edit-row {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 8px;
}

.dim-num {
  font-size: 12px;
  color: #64748b;
  width: 50px;
  flex-shrink: 0;
}

.danger-link {
  color: #ef4444 !important;
}

.success-link {
  color: #16a34a !important;
}

.sku-nested-panel {
  background: #f8fafc;
  padding: 14px 16px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  box-sizing: border-box;
  width: 100%;
}

.nested-panel-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}

.nested-title {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 13px;
  color: #1e293b;
}

.nested-title .icon-chip {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 4px;
  background: #eaf7ef;
  color: #008b4b;
  font-size: 13px;
}

.nested-title .sub-hint {
  font-size: 12px;
  color: #64748b;
  margin-left: 4px;
}

.nested-actions {
  display: flex;
  gap: 8px;
}

.sku-inner-table {
  width: 100% !important;
  background: #ffffff;
  border-radius: 6px;
  overflow: hidden;
}

.nested-pagination {
  margin-top: 10px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  color: #64748b;
}

/* 响应式适配 */
@media (max-width: 1024px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .filter-input-search {
    width: 220px;
  }
}

@media (max-width: 768px) {
  .product-page-container {
    padding: 10px 12px;
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
  .metric-overview-grid {
    grid-template-columns: 1fr;
  }
  .filter-input-search {
    width: 100%;
  }
  .filter-select, .filter-select-sm {
    width: 100%;
  }
  .property-grid {
    grid-template-columns: 1fr;
  }
}
</style>
