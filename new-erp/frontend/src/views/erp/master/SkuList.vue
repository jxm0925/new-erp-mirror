<template>
  <div class="sku-page-container">
    <!-- 页面全局头部：图标、标题、统计标签与主要操作 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-collection" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">SKU档案</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">共 {{ total }} 款 SKU</el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="fetch">刷新</el-button>
        <el-button size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="$router.push('/master/skus/new')">
          新增 SKU
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>维护商品销售层级的 SKU 规格主档案。实物 SKU 在启用并投入库存与工单生产履约前，必须绑定唯一且有效的默认物料（Item）；服务与无需发货类 SKU 则无需关联实物料。</span>
    </div>

    <!-- 顶部概览指标卡片 -->
    <section class="metric-overview-grid">
      <div class="metric-card metric-all">
        <div class="metric-icon-box"><i class="el-icon-collection" /></div>
        <div class="metric-info">
          <span class="metric-label">全部 SKU 档案</span>
          <strong class="metric-val code-mono">{{ total }}</strong>
        </div>
      </div>

      <div class="metric-card metric-enabled">
        <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
        <div class="metric-info">
          <span class="metric-label">正常启用 SKU</span>
          <strong class="metric-val code-mono">{{ enabledCount }}</strong>
        </div>
      </div>

      <div class="metric-card metric-draft">
        <div class="metric-icon-box"><i class="el-icon-edit-outline" /></div>
        <div class="metric-info">
          <span class="metric-label">草稿 / 待补全</span>
          <strong class="metric-val code-mono">{{ draftCount }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-warning"
        :class="{ active: query.missing_default_item }"
        title="点击快速筛选缺少默认 Item 的实物 SKU"
        @click="filterMissingItem"
      >
        <div class="metric-icon-box"><i class="el-icon-warning-outline" /></div>
        <div class="metric-info">
          <span class="metric-label">缺少默认 Item (预警)</span>
          <strong class="metric-val code-mono text-danger">{{ missingItemCount }}</strong>
        </div>
        <i class="el-icon-arrow-right card-arrow" />
      </div>
    </section>

    <!-- 筛选工具栏与主表格卡片 -->
    <section class="table-container-card">
      <div class="filter-toolbar">
        <div class="filter-fields">
          <el-input
            v-model="query.keyword"
            size="small"
            clearable
            prefix-icon="el-icon-search"
            placeholder="搜索 SKU 编码 / 名称 / 规格型号..."
            class="filter-input-search"
            @keyup.enter.native="search"
          />

          <el-select v-model="query.order_line_type" size="small" clearable placeholder="订单行类型" class="filter-select">
            <el-option label="实物 (需备货/发货)" value="physical" />
            <el-option label="服务 (虚拟服务)" value="service" />
            <el-option label="无需发货 (随单交付)" value="no_delivery" />
          </el-select>

          <el-select v-model="query.status" size="small" clearable placeholder="销售状态" class="filter-select">
            <el-option label="正常启用" value="enabled" />
            <el-option label="草稿待定" value="draft" />
            <el-option label="已停用" value="disabled" />
          </el-select>

          <el-checkbox v-model="query.missing_default_item" class="filter-checkbox" @change="search">
            仅看缺少默认 Item
          </el-checkbox>
        </div>

        <div class="filter-buttons">
          <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="search">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
        </div>
      </div>

      <!-- 数据主表格 -->
      <div class="table-body">
        <el-table
          v-loading="loading"
          :data="rows"
          size="small"
          stripe
          border
          highlight-current-row
          class="sku-data-table"
        >
          <!-- 图片缩略图 -->
          <el-table-column label="图片" width="76" align="center">
            <template slot-scope="{ row }">
              <el-image
                v-if="imageUrl(row)"
                class="sku-thumb"
                :src="imageUrl(row)"
                fit="cover"
                :preview-src-list="[imageUrl(row)]"
              >
                <div slot="error" class="image-fallback"><i class="el-icon-picture-outline" /></div>
              </el-image>
              <div v-else class="image-fallback"><i class="el-icon-picture-outline" /></div>
            </template>
          </el-table-column>

          <!-- SKU 编码 -->
          <el-table-column label="SKU 编码" min-width="150" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="code-mono sku-code-chip" @click="$router.push('/master/skus/' + row.id)">
                <i class="el-icon-postcard" />
                {{ row.sku_code }}
              </span>
            </template>
          </el-table-column>

          <!-- SKU 名称与规格 -->
          <el-table-column label="SKU 名称 / 规格" min-width="190" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <div class="sku-name-cell">
                <span class="sku-name-text" :title="row.sku_name">{{ row.sku_name }}</span>
                <span v-if="row.spec_model || row.spec_text" class="sku-spec-sub">
                  {{ row.spec_model || row.spec_text }}
                </span>
              </div>
            </template>
          </el-table-column>

          <!-- 所属商品 (Product) -->
          <el-table-column label="所属商品 (Product)" min-width="210" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <div v-if="row.product" class="product-cell">
                <span class="code-mono product-code-tag">{{ row.product.product_code }}</span>
                <span class="product-name-text" :title="row.product.product_name">{{ row.product.product_name }}</span>
              </div>
              <span v-else class="text-muted">—</span>
            </template>
          </el-table-column>

          <!-- 销售单位与价格 -->
          <el-table-column label="销售单位 / 默认销售价" min-width="170">
            <template slot-scope="{ row }">
              <div v-if="row.sale_price !== null && row.sale_price !== ''" class="price-cell">
                <span class="price-val code-mono">¥ {{ Number(row.sale_price).toFixed(2) }}</span>
                <span class="unit-tag">{{ unitName(row) }}</span>
              </div>
              <div v-else class="price-incomplete">
                <el-tag type="warning" size="mini" effect="plain">待设置价格</el-tag>
                <span class="unit-tag">{{ unitName(row) }}</span>
              </div>
            </template>
          </el-table-column>

          <!-- 订单行类型 -->
          <el-table-column label="订单行类型" width="110" align="center">
            <template slot-scope="{ row }">
              <span class="line-type-tag" :class="lineTypeClass(row.line_type || row.order_line_type)">
                {{ typeText(row.line_type || row.order_line_type) }}
              </span>
            </template>
          </el-table-column>

          <!-- 默认关联 Item -->
          <el-table-column label="默认物料 (Item)" min-width="160">
            <template slot-scope="{ row }">
              <div v-if="hasDefault(row)" class="item-bound-box" @click="goToItem(defaultItem(row).item)">
                <i class="el-icon-box" />
                <span class="code-mono">{{ defaultItem(row).item.item_code }}</span>
              </div>
              <el-tag
                v-else-if="(row.line_type || row.order_line_type) === 'physical' && row.status === 'enabled'"
                type="danger"
                size="mini"
                effect="plain"
                class="missing-item-tag"
              >
                <i class="el-icon-warning" /> 缺少默认Item
              </el-tag>
              <span v-else class="text-muted">—</span>
            </template>
          </el-table-column>

          <!-- 状态 -->
          <el-table-column label="销售状态" width="100" align="center">
            <template slot-scope="{ row }">
              <span class="status-badge" :class="row.status">
                <i :class="row.status === 'enabled' ? 'el-icon-circle-check' : row.status === 'draft' ? 'el-icon-edit' : 'el-icon-circle-close'" />
                {{ statusText(row.status) }}
              </span>
            </template>
          </el-table-column>

          <!-- 操作列 -->
          <el-table-column label="操作" fixed="right" width="180" align="center">
            <template slot-scope="{ row }">
              <div class="row-actions">
                <el-button type="text" size="small" icon="el-icon-view" class="action-link-theme" @click.stop="$router.push('/master/skus/' + row.id)">
                  详情
                </el-button>
                <el-button type="text" size="small" icon="el-icon-edit" class="action-link-theme" @click.stop="$router.push('/master/skus/' + row.id + '/edit')">
                  编辑
                </el-button>
                <el-button
                  type="text"
                  size="small"
                  :class="row.status === 'enabled' ? 'danger-link' : 'success-link'"
                  @click.stop="toggleStatus(row)"
                >
                  {{ row.status === 'enabled' ? '停用' : '启用' }}
                </el-button>
                <el-button
                  v-if="row.status !== 'enabled'"
                  type="text"
                  size="small"
                  class="danger-link"
                  @click.stop="deleteSku(row)"
                >
                  删除
                </el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 底部分页栏 -->
      <div class="pager-row">
        <span class="total-text">共 {{ total }} 款 SKU 档案</span>
        <el-pagination
          small
          background
          layout="prev, pager, next, sizes, jumper"
          :current-page="query.page"
          :page-size="query.per_page"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          @current-change="changePage"
          @size-change="changeSize"
        />
      </div>
    </section>
  </div>
</template>

<script>
import { deleteEntity, disableEntity, enableEntity, listEntity } from '../../../api/erp/master'
import { legacyMediaUrl } from '../../../utils/legacyMedia'
import { materialListPath } from '../../../utils/materialManagementScope.mjs'

export default {
  name: 'SkuList',
  data() {
    return {
      loading: false,
      rows: [],
      total: 0,
      stats: {},
      query: {
        keyword: '',
        order_line_type: '',
        status: '',
        missing_default_item: false,
        page: 1,
        per_page: 20
      }
    }
  },
  computed: {
    enabledCount () {
      return Number(this.stats.enabled || 0)
    },
    draftCount () {
      return Number(this.stats.draft || 0)
    },
    missingItemCount () {
      return Number(this.stats.missing_item || 0)
    }
  },
  created() {
    this.fetch()
  },
  methods: {
    async fetch() {
      this.loading = true
      try {
        const response = await listEntity('skus', { ...this.query, include_stats: 1 })
        this.rows = response.data.data || []
        this.total = response.data.total || 0
        this.stats = response.data.stats || {}
      } catch (error) {
        this.$message.error(error.userMessage || 'SKU 加载失败')
      } finally {
        this.loading = false
      }
    },
    search() {
      this.query.page = 1
      this.fetch()
    },
    reset() {
      this.query = {
        keyword: '',
        order_line_type: '',
        status: '',
        missing_default_item: false,
        page: 1,
        per_page: 20
      }
      this.fetch()
    },
    filterMissingItem() {
      this.query.missing_default_item = !this.query.missing_default_item
      this.search()
    },
    changePage(page) {
      this.query.page = page
      this.fetch()
    },
    changeSize(size) {
      this.query.per_page = size
      this.query.page = 1
      this.fetch()
    },
    async toggleStatus(row) {
      const enabling = row.status !== 'enabled'
      try {
        await this.$confirm(
          enabling ? '确认启用该 SKU 销售规格？' : '确认停用该 SKU？停用后将不能创建新的销售单据。',
          enabling ? '启用 SKU' : '停用 SKU',
          { type: 'warning' }
        )
        await (enabling ? enableEntity : disableEntity)('skus', row.id)
        this.$message.success(enabling ? 'SKU 已成功启用' : 'SKU 已停用')
        await this.fetch()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || 'SKU 状态更新失败')
      }
    },
    async deleteSku(row) {
      try {
        await this.$confirm(
          `确认删除 SKU ${row.sku_code}？仅从未被订单、BOM、定制或默认 Item 关系引用的停用/草稿 SKU 可以删除。`,
          '删除 SKU',
          { type: 'warning', confirmButtonText: '确认删除' }
        )
        await deleteEntity('skus', row.id)
        this.$message.success('SKU 已删除')
        await this.fetch()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || 'SKU 删除失败')
      }
    },
    goToItem(item) {
      if (item && item.id) {
        const scope = item.management_scope || (item.item_type === 'office_consumable' ? 'office' : 'factory')
        this.$router.push(`${materialListPath(scope)}/${item.id}/edit`)
      }
    },
    imageUrl(row) {
      return legacyMediaUrl(row.image || (row.product && row.product.image))
    },
    defaultItem(row) {
      return (row.item_relations || []).find(
        item => item.status === 'active' && item.is_primary && item.item && item.item.status === 'enabled'
      )
    },
    hasDefault(row) {
      return !!this.defaultItem(row)
    },
    productText(row) {
      return row.product ? `${row.product.product_code} ｜ ${row.product.product_name}` : '—'
    },
    unitName(row) {
      return (row.sales_unit && row.sales_unit.unit_name) || row.sales_unit_snapshot || '—'
    },
    typeText(value) {
      return { physical: '实物', service: '服务', no_delivery: '无需发货' }[value] || value || '实物'
    },
    lineTypeClass(value) {
      return { physical: 'type-physical', service: 'type-service', no_delivery: 'type-no-delivery' }[value] || 'type-physical'
    },
    statusText(value) {
      return { draft: '草稿待定', enabled: '正常启用', disabled: '已停用' }[value] || value
    }
  }
}
</script>

<style scoped>
.sku-page-container {
  min-height: calc(100vh - 52px);
  padding: 16px 20px 30px;
  background: #f5f7fa;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  color: #1f2937;
  box-sizing: border-box;
}

/* 高清等宽数字与编码字体规范 */
.code-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif !important;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  letter-spacing: 0.5px;
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

.head-tag,
.total-tag {
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

/* 全局统一页面提示条 */
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

/* 顶部概览指标卡片网格 */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 14px;
}

.metric-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 18px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  transition: all 0.2s ease;
  box-sizing: border-box;
}

.metric-card.active {
  border-color: #ef4444;
  background: #fef2f2;
}

.metric-card.metric-warning {
  cursor: pointer;
}

.metric-card.metric-warning:hover {
  border-color: #f87171;
  box-shadow: 0 4px 12px rgba(239, 68, 68, 0.1);
  transform: translateY(-1px);
}

.metric-icon-box {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}

.metric-all .metric-icon-box {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}

.metric-enabled .metric-icon-box {
  background: #ecfdf5;
  color: #10b981;
  border: 1px solid #a7f3d0;
}

.metric-draft .metric-icon-box {
  background: #fffbeb;
  color: #f59e0b;
  border: 1px solid #fde68a;
}

.metric-warning .metric-icon-box {
  background: #fef2f2;
  color: #ef4444;
  border: 1px solid #fecaca;
}

.metric-info {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
}

.metric-label {
  font-size: 12px;
  color: #6b7280;
  font-weight: 500;
}

.metric-val {
  font-size: 22px;
  font-weight: 700;
  color: #111827;
}

.text-danger {
  color: #ef4444 !important;
}

.card-arrow {
  color: #9ca3af;
  font-size: 14px;
  transition: transform 0.2s ease;
}

.metric-card.metric-warning:hover .card-arrow {
  color: #ef4444;
  transform: translateX(3px);
}

/* 筛选与主表格卡片容器 */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
  box-sizing: border-box;
}

.filter-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 18px;
  background: #fafbfc;
  border-bottom: 1px solid #edf1f5;
  flex-wrap: wrap;
  gap: 12px;
}

.filter-fields {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.filter-input-search {
  width: 250px;
}

.filter-select {
  width: 140px;
}

.filter-checkbox ::v-deep .el-checkbox__label {
  font-size: 13px;
  color: #4b5563;
  font-weight: 500;
}

.filter-checkbox ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}

.filter-buttons {
  display: flex;
  gap: 8px;
}

.btn-theme-search {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

.btn-theme-search:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.btn-theme-reset {
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #4b5563 !important;
}

.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 表格定制 */
.table-body {
  padding: 0;
}

.sku-data-table ::v-deep th {
  background: #f8fafc !important;
  color: #374151 !important;
  font-weight: 600;
  height: 40px;
}

.sku-thumb {
  width: 44px;
  height: 44px;
  border-radius: 6px;
  border: 1px solid #e5e7eb;
  transition: transform 0.2s ease;
  cursor: pointer;
}

.sku-thumb:hover {
  transform: scale(1.08);
}

.image-fallback {
  width: 44px;
  height: 44px;
  border-radius: 6px;
  display: inline-grid;
  place-items: center;
  background: #f3f4f6;
  color: #9ca3af;
  font-size: 18px;
  border: 1px solid #e5e7eb;
}

.sku-code-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 8px;
  border-radius: 4px;
  color: #00763f;
  font-size: 13px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.sku-code-chip:hover {
  background: #dcfce7;
  border-color: #86efac;
}

.sku-name-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.sku-name-text {
  font-size: 13px;
  font-weight: 600;
  color: #111827;
}

.sku-spec-sub {
  font-size: 11px;
  color: #6b7280;
}

.product-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.product-code-tag {
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  padding: 1px 6px;
  border-radius: 3px;
  color: #475569;
  font-size: 11px;
}

.product-name-text {
  font-size: 12px;
  color: #334155;
}

.price-cell {
  display: flex;
  align-items: center;
  gap: 6px;
}

.price-val {
  font-size: 14px;
  font-weight: 700;
  color: #008b4b;
}

.unit-tag {
  font-size: 11px;
  color: #6b7280;
  background: #f3f4f6;
  padding: 1px 6px;
  border-radius: 3px;
}

.price-incomplete {
  display: flex;
  align-items: center;
  gap: 6px;
}

.line-type-tag {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}

.type-physical {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
}

.type-service {
  background: #faf5ff;
  color: #7c3aed;
  border: 1px solid #e9d5ff;
}

.type-no-delivery {
  background: #fff7ed;
  color: #ea580c;
  border: 1px solid #ffedd5;
}

.item-bound-box {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 8px;
  border-radius: 4px;
  color: #00763f;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.item-bound-box:hover {
  background: #dcfce7;
  border-color: #86efac;
}

.missing-item-tag {
  cursor: default;
}

.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 12px;
  font-weight: 500;
}

.status-badge.enabled {
  color: #008b4b;
}

.status-badge.draft {
  color: #d97706;
}

.status-badge.disabled {
  color: #9ca3af;
}

.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.action-link-theme {
  color: #008b4b !important;
  font-weight: 500;
}

.action-link-theme:hover {
  color: #00763f !important;
}

.success-link {
  color: #008b4b !important;
  font-weight: 500;
}

.success-link:hover {
  color: #00763f !important;
}

.danger-link {
  color: #ef4444 !important;
  font-weight: 500;
}

.danger-link:hover {
  color: #dc2626 !important;
}

.text-muted {
  color: #9ca3af;
}

/* 底部分页栏与主题色定制 */
.pager-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 18px;
  background: #ffffff;
  border-top: 1px solid #edf1f5;
  box-sizing: border-box;
}

.pager-row ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
}

.pager-row ::v-deep .el-pagination.is-background .el-pager li:not(.disabled):hover {
  color: #008b4b !important;
}

.pager-row ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active:hover {
  color: #ffffff !important;
}

.pager-row ::v-deep .el-pagination.is-background .btn-next:hover:not([disabled]),
.pager-row ::v-deep .el-pagination.is-background .btn-prev:hover:not([disabled]) {
  color: #008b4b !important;
}

.pager-row ::v-deep .el-pagination .el-select .el-input.is-focus .el-input__inner,
.pager-row ::v-deep .el-pagination .el-select .el-input .el-input__inner:focus {
  border-color: #008b4b !important;
}

.pager-row ::v-deep .el-pagination__editor.el-input .el-input__inner:focus {
  border-color: #008b4b !important;
}

.total-text {
  font-size: 13px;
  color: #6b7280;
}

/* 响应式断点适配规则 (遵循全局响应式规范) */
@media (max-width: 1400px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .sku-page-container {
    padding: 12px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .head-actions {
    width: 100%;
  }
  .head-actions .el-button {
    flex: 1;
  }
  .metric-overview-grid {
    grid-template-columns: 1fr;
  }
  .filter-toolbar {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-fields {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-input-search,
  .filter-select {
    width: 100%;
  }
  .filter-buttons {
    width: 100%;
    justify-content: flex-end;
  }
}
</style>
