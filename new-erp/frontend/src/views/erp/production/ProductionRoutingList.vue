<template>
  <section class="production-page routing-list-page" v-loading="loading">
    <!-- 顶部统一标头 (.page-head) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-guide" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">工艺路线管理</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              共 {{ total }} 项
            </el-tag>
          </div>
          <div class="sub-row">
            维护产品制造标准工艺路线、工序流转顺序、用料定额与报工参数；同一业务路线采用固定编码与版本迭代
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">
          刷新
        </el-button>
        <el-button v-if="$can('production.routing.create')" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="$router.push('/production/routings/new')">
          新增工艺路线
        </el-button>
      </div>
    </header>

    <!-- 筛选卡片 -->
    <section class="filter-card">
      <div class="filter-grid">
        <label>
          <span>路线编码 / 名称</span>
          <el-input v-model.trim="filters.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="请输入路线编码或名称" @keyup.enter.native="search" />
        </label>
        <label>
          <span>状态</span>
          <el-select v-model="filters.status" size="small" clearable placeholder="全部状态">
            <el-option label="草稿" value="draft" />
            <el-option label="已生效" value="active" />
            <el-option label="已退役" value="retired" />
          </el-select>
        </label>
      </div>
      <div class="filter-actions">
        <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="search">查询</el-button>
        <el-button size="small" icon="el-icon-refresh-right" class="btn-theme-reset" @click="reset">重置</el-button>
      </div>
    </section>

    <!-- 表格卡片 -->
    <section class="table-card">
      <el-table :data="rows" border stripe highlight-current-row class="routing-table">
        <el-table-column prop="routing_no" label="路线编码" min-width="140">
          <template slot-scope="s">
            <span class="mono-code">{{ s.row.routing_no }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="routing_name" label="路线名称" min-width="160">
          <template slot-scope="s">
            <span class="routing-name">{{ s.row.routing_name }}</span>
          </template>
        </el-table-column>
        <el-table-column label="适用物料 / 类目" min-width="190">
          <template slot-scope="s">
            <div class="wrap-text">
              <span class="item-name">{{ (s.row.applicabilities || []).map(x => x.item ? x.item.item_name : (x.category ? x.category.category_name + '（类目）' : '')).filter(Boolean).join('、') || '-' }}</span>
              <small v-if="s.row.sku" class="item-code">限定 SKU：{{ s.row.sku.sku_name || s.row.sku.sku_code }}</small>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="版本" width="75" align="center">
          <template slot-scope="s">
            <el-tag size="mini" type="info" effect="plain" class="version-tag">V{{ s.row.version }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column prop="operations_count" label="工序数" width="75" align="center">
          <template slot-scope="s">
            <span>{{ s.row.operations_count }} 道</span>
          </template>
        </el-table-column>
        <el-table-column label="默认路线" width="85" align="center">
          <template slot-scope="s">
            <el-tag size="mini" :type="s.row.is_default ? 'success' : 'info'" :effect="s.row.is_default ? 'plain' : 'plain'">
              {{ s.row.is_default ? '默认' : '-' }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="状态" width="85" align="center">
          <template slot-scope="s">
            <el-tag size="mini" :type="s.row.status==='active'?'success':s.row.status==='retired'?'info':''">
              {{ statusText(s.row.status) }}
            </el-tag>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="280" align="center" fixed="right">
          <template slot-scope="s">
            <div class="inline-actions">
              <el-button type="text" @click="$router.push(`/production/routings/${s.row.id}`)">查看</el-button>
              <el-button v-if="s.row.status==='draft'&&$can('production.routing.edit')" type="text" @click="$router.push(`/production/routings/${s.row.id}/edit`)">编辑</el-button>
              <el-button v-if="s.row.status==='draft'&&$can('production.routing.activate')" type="text" @click="activate(s.row)">生效</el-button>
              <el-button v-if="s.row.status==='active'&&!s.row.is_default&&$can('production.routing.default')" type="text" @click="setDefault(s.row)">设为默认</el-button>
              <el-button v-if="$can('production.routing.create')" type="text" @click="copyVersion(s.row)">复制新版本</el-button>
              <el-button v-if="s.row.status==='active'&&$can('production.routing.activate')" type="text" class="danger-action" @click="retire(s.row)">退役</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <div class="table-footer">
        <span>共 {{ total }} 条</span>
        <el-pagination background layout="sizes, prev, pager, next" :current-page="page" :page-size="perPage" :page-sizes="[10, 15, 20]" :total="total" @current-change="changePage" @size-change="changePageSize" />
      </div>
    </section>
  </section>
</template>

<script>
import { listProductionRoutings, activateProductionRouting, setDefaultProductionRouting, copyProductionRouting, retireProductionRouting } from '../../../api/erp/production'

export default {
  name: 'ProductionRoutingList',
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    page: 1,
    perPage: 10,
    filters: { keyword: '', status: '' }
  }),
  created() {
    this.load()
  },
  methods: {
    statusText(v) {
      return ({ draft: '草稿', active: '已生效', retired: '已退役', disabled: '已退役' })[v] || '未知'
    },
    async load() {
      this.loading = true
      try {
        const r = await listProductionRoutings({ ...this.filters, page: this.page, per_page: this.perPage })
        this.rows = r.data.data || []
        this.total = r.data.total || 0
      } catch (e) {
        this.$message.error(e.userMessage || '工艺路线加载失败')
      } finally {
        this.loading = false
      }
    },
    search() {
      this.page = 1
      this.load()
    },
    reset() {
      this.filters = { keyword: '', status: '' }
      this.search()
    },
    changePage(v) {
      this.page = v
      this.load()
    },
    changePageSize(v) {
      this.perPage = v
      this.page = 1
      this.load()
    },
    async activate(row) {
      try {
        await this.$confirm('生效后路线结构不可直接修改，确认继续？', '生效工艺路线', { type: 'warning' })
        await activateProductionRouting(row.id, { client_command_id: `routing-activate-${row.id}-${Date.now()}`, expected_version: row.business_version })
        this.$message.success('工艺路线已生效')
        this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '生效失败')
      }
    },
    async setDefault(row) {
      try {
        await this.$confirm('确认将该版本设为默认生效路线？适用范围与它重叠的其他默认路线会被取消默认。', '设置默认路线')
        const r = await setDefaultProductionRouting(row.id, { client_command_id: `routing-default-${row.id}-${Date.now()}`, expected_version: row.business_version })
        const replaced = (r && r.data && r.data.data && r.data.data.replaced_defaults) || []
        this.$message.success(replaced.length ? `默认路线已更新，已取消 ${replaced.map(x => x.routing_no).join('、')} 的默认` : '默认路线已更新')
        this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '设置失败')
      }
    },
    async copyVersion(row) {
      try {
        await this.$confirm(`复制 ${row.routing_no} V${row.version} 为下一版本？`, '复制新版本')
        const r = await copyProductionRouting(row.id, { client_command_id: `routing-copy-${row.id}-${Date.now()}` })
        this.$message.success(`已生成 ${r.data.data.routing_no} V${r.data.data.version}`)
        this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '复制失败')
      }
    },
    async retire(row) {
      try {
        await this.$confirm('退役后不能再被新工单选用，历史工单不受影响。', '退役工艺路线', { type: 'warning' })
        await retireProductionRouting(row.id, { client_command_id: `routing-retire-${row.id}-${Date.now()}`, expected_version: row.business_version })
        this.$message.success('工艺路线已退役')
        this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '退役失败')
      }
    }
  }
}
</script>

<style scoped src="./production-master.css"></style>
<style scoped>
.routing-list-page {
  padding: 16px 20px 30px;
  background: #f8fafc;
  min-height: calc(100vh - 52px);
  box-sizing: border-box;
}

/* 顶部页面标头 (.page-head) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
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
  gap: 4px;
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
.sub-row {
  font-size: 13px;
  color: #64748b;
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
}
.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 筛选工具栏 */
.filter-card {
  padding: 14px 18px;
  margin-bottom: 14px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: flex-end;
  gap: 16px;
  flex-wrap: wrap;
}
.filter-grid {
  display: flex;
  gap: 16px;
  flex-wrap: wrap;
  flex: 1;
}
.filter-grid label {
  min-width: 200px;
}
.filter-grid label > span {
  display: block;
  margin-bottom: 6px;
  color: #475569;
  font-size: 12px;
  font-weight: 600;
}
.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 0;
  white-space: nowrap;
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
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 表格卡片 */
.table-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  padding: 0;
  overflow: hidden;
}
.routing-table ::v-deep th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
  height: 44px;
  padding: 6px 0;
}
.routing-table ::v-deep td {
  padding: 10px 0;
}
.routing-table ::v-deep .cell {
  padding-left: 12px;
  padding-right: 12px;
}

.mono-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  color: #0f172a;
  font-weight: 600;
}
.routing-name {
  font-weight: 600;
  color: #1e293b;
}
.wrap-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.item-name {
  color: #1e293b;
  font-weight: 500;
}
.item-code {
  font-family: ui-monospace, SFMono-Regular, monospace;
  color: #64748b;
  font-size: 11px;
}
.version-tag {
  font-weight: 600;
}
.inline-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  white-space: nowrap;
}
.inline-actions .el-button {
  padding: 0;
  font-size: 12px;
  color: #008b4b;
}
.inline-actions .el-button:hover {
  color: #00763f;
}
.danger-action {
  color: #dc2626 !important;
}
.danger-action:hover {
  color: #b91c1c !important;
}

.table-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 16px;
  background: #ffffff;
  border-top: 1px solid #f1f5f9;
  color: #64748b;
  font-size: 13px;
}
.table-footer ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff;
}

@media (max-width: 767px) {
  .routing-list-page {
    padding: 12px;
  }
  .filter-card {
    flex-direction: column;
    align-items: stretch;
  }
  .filter-actions {
    justify-content: flex-end;
  }
}
</style>

