<template>
  <section class="wl-page">
    <div class="wl-workspace">
      <!-- 页面全局头部：图标、标题、统计标签与主要操作 -->
      <header class="page-head">
        <div class="head-left">
          <span class="head-icon"><i class="el-icon-office-building" /></span>
          <div class="head-title-wrap">
            <div class="title-row">
              <h1 class="page-title">仓库与库位管理</h1>
              <el-tag size="small" type="success" effect="plain" class="total-tag">共 {{ warehousePage.total }} 个实体仓库</el-tag>
              <el-tag v-if="selectedWarehouse.id" size="small" type="info" effect="plain" class="sub-total-tag">
                当前仓库 {{ locationStats.total || 0 }} 个库位
              </el-tag>
            </div>
          </div>
        </div>
        <div class="head-actions">
          <el-button size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openWarehouseCreate">新增仓库</el-button>
        </div>
      </header>

      <!-- 全局统一业务提示条 -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>维护企业实体仓库与精细化库位档案。左侧维护物理仓库（原材料仓、成品仓、线边仓等），右侧展示当前选中主仓库完整属性与货架/通道/层位细分库位，支持出入库扫码寻位与混放控制。</span>
      </div>

      <!-- 主工作区：左侧仓库档案列表 + 右侧当前主仓库看板 -->
      <div class="board-layout-grid">
        <!-- 左侧：实体仓库档案列表卡片 -->
        <aside class="warehouse-card">
          <div class="card-header">
            <div class="card-header-left">
              <span class="card-title-icon"><i class="el-icon-office-building" /></span>
              <span class="card-title-text">实体仓库档案</span>
            </div>
            <div class="card-header-right">
              <el-radio-group v-model="viewMode" size="mini" class="view-toggle">
                <el-radio-button label="card"><i class="el-icon-menu" title="卡片视图" /></el-radio-button>
                <el-radio-button label="table"><i class="el-icon-s-grid" title="表格视图" /></el-radio-button>
              </el-radio-group>
            </div>
          </div>

          <!-- 搜索筛选栏 -->
          <div class="card-search-bar">
            <el-input
              v-model.trim="warehouseKeyword"
              size="small"
              clearable
              prefix-icon="el-icon-search"
              placeholder="搜索编码 / 名称 / 负责人..."
            />
          </div>

          <!-- 视图 A：卡片式列表（默认推荐：每个字段完整呈现，无截断、无横向滚动条） -->
          <div v-if="viewMode === 'card'" v-paged-scroll="loadMoreWarehouses" v-loading="loading" class="wh-card-list">
            <div
              v-for="wh in filteredWarehouses"
              :key="wh.id"
              class="wh-item-card"
              :class="{ 'is-active': wh.id === selectedWarehouse.id }"
              @click="selectWarehouse(wh)"
            >
              <div class="wh-card-top">
                <div class="wh-card-title-row">
                  <h3 class="wh-card-title">{{ wh.warehouse_name }}</h3>
                  <el-tag size="mini" :type="wh.status === 'enabled' ? 'success' : 'info'" effect="light">
                    {{ statusText(wh.status) }}
                  </el-tag>
                </div>
                <div class="wh-card-badges-row">
                  <span class="font-tabular code-chip">{{ wh.warehouse_code }}</span>
                  <el-tag size="mini" :type="warehouseTypeTag(wh.warehouse_type)" effect="plain">
                    {{ whTypeText(wh.warehouse_type) }}
                  </el-tag>
                  <span class="wh-card-loc-pill font-tabular" title="该仓库包含的库位数">
                    <i class="el-icon-files" /> {{ locationCount(wh.id) }} 库位
                  </span>
                  <span class="wh-card-area-pill font-tabular" title="规划的物理库区数">
                    <i class="el-icon-folder" /> {{ warehouseAreaCount(wh.id) }} 库区
                  </span>
                </div>
              </div>

              <!-- 仓库备注/定位说明摘要 -->
              <div v-if="wh.remark" class="wh-card-remark" :title="wh.remark">
                <i class="el-icon-document" /> {{ wh.remark }}
              </div>

              <div class="wh-card-bottom">
                <div class="wh-card-manager">
                  <i class="el-icon-user" />
                  <span>负责人：<strong>{{ managerName(wh) || '未指定' }}</strong></span>
                </div>
                <div class="wh-card-actions" @click.stop>
                  <el-button type="text" size="mini" class="btn-action-edit" icon="el-icon-edit" @click="editWarehouse(wh)">编辑</el-button>
                  <el-button
                    type="text"
                    size="mini"
                    :class="wh.status === 'enabled' ? 'btn-action-disable' : 'btn-action-enable'"
                    @click="toggleWarehouse(wh)"
                  >
                    {{ wh.status === 'enabled' ? '停用' : '启用' }}
                  </el-button>
                  <el-button
                    v-if="wh.status !== 'enabled'"
                    type="text"
                    size="mini"
                    class="btn-action-delete"
                    icon="el-icon-delete"
                    @click="deleteWarehouse(wh)"
                  >
                    删除
                  </el-button>
                </div>
              </div>
            </div>

            <div v-if="!filteredWarehouses.length" class="empty-wh-list">
              <i class="el-icon-office-building" />
              <p>暂无匹配仓库数据</p>
            </div>
          </div>

          <!-- 视图 B：宽幅表格视图（可自由横向滚动） -->
          <div v-else class="table-wrap">
            <el-table
              v-loading="loading"
              :data="filteredWarehouses"
              v-paged-scroll="loadMoreWarehouses"
              size="small"
              border
              highlight-current-row
              :row-class-name="warehouseRowClass"
              empty-text="暂无仓库数据"
              class="enterprise-table"
              @row-click="selectWarehouse"
            >
              <el-table-column prop="warehouse_code" label="仓库编码" width="125">
                <template slot-scope="{ row }">
                  <span class="font-tabular code-chip">{{ row.warehouse_code }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="warehouse_name" label="仓库名称" min-width="130" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span class="wh-name-text" :class="{ 'is-active': row.id === selectedWarehouse.id }">{{ row.warehouse_name }}</span>
                </template>
              </el-table-column>

              <el-table-column label="类型" width="92" align="center">
                <template slot-scope="{ row }">
                  <el-tag size="mini" :type="warehouseTypeTag(row.warehouse_type)" effect="plain" class="type-tag">
                    {{ whTypeText(row.warehouse_type) }}
                  </el-tag>
                </template>
              </el-table-column>

              <el-table-column label="库区" width="65" align="right">
                <template slot-scope="{ row }">
                  <span class="font-tabular stat-count">{{ warehouseAreaCount(row.id) }}</span>
                </template>
              </el-table-column>

              <el-table-column label="库位" width="65" align="right">
                <template slot-scope="{ row }">
                  <span class="font-tabular stat-count" :class="{ 'has-val': locationCount(row.id) > 0 }">
                    {{ locationCount(row.id) }}
                  </span>
                </template>
              </el-table-column>

              <el-table-column prop="manager" label="负责人" width="85" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span>{{ managerName(row) || '-' }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="remark" label="说明与定位" min-width="120" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span class="text-muted">{{ row.remark || '-' }}</span>
                </template>
              </el-table-column>

              <el-table-column label="状态" width="70" align="center">
                <template slot-scope="{ row }">
                  <el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'" effect="light" class="status-tag">
                    {{ statusText(row.status) }}
                  </el-tag>
                </template>
              </el-table-column>

              <el-table-column label="操作" width="125" align="center">
                <template slot-scope="{ row }">
                  <div class="row-actions">
                    <el-button type="text" size="mini" class="btn-action-edit" icon="el-icon-edit" @click.stop="editWarehouse(row)">编辑</el-button>
                    <el-button
                      type="text"
                      size="mini"
                      :class="row.status === 'enabled' ? 'btn-action-disable' : 'btn-action-enable'"
                      @click.stop="toggleWarehouse(row)"
                    >
                      {{ row.status === 'enabled' ? '停用' : '启用' }}
                    </el-button>
                    <el-button
                      v-if="row.status !== 'enabled'"
                      type="text"
                      size="mini"
                      class="btn-action-delete"
                      icon="el-icon-delete"
                      @click.stop="deleteWarehouse(row)"
                    >
                      删除
                    </el-button>
                  </div>
                </template>
              </el-table-column>
            </el-table>
          </div>

          <footer class="card-footer">
            <span class="footer-count-text">共 <strong>{{ warehousePage.total }}</strong> 个仓库</span>
          </footer>
        </aside>

        <!-- 右侧：当前主仓库全景档案与库位看板 -->
        <main class="location-card">
          <!-- 当前选中的主仓库全景档案卡片（四维档案全量呈现、实时库存资产联动、规划库区穿透） -->
          <div v-if="selectedWarehouse.id" class="wh-profile-card">
            <!-- 1. 顶部身份全景栏 -->
            <div class="wh-profile-top">
              <div class="wh-profile-main">
                <span class="wh-profile-avatar"><i class="el-icon-office-building" /></span>
                <div class="wh-profile-title-block">
                  <div class="wh-title-row">
                    <h2 class="wh-main-title">{{ selectedWarehouse.warehouse_name }}</h2>
                    <span class="font-tabular wh-main-code">{{ selectedWarehouse.warehouse_code }}</span>
                    <el-tag size="small" :type="warehouseTypeTag(selectedWarehouse.warehouse_type)" effect="plain" class="wh-type-badge">
                      {{ whTypeText(selectedWarehouse.warehouse_type) }}
                    </el-tag>
                    <span class="wh-status-pill" :class="selectedWarehouse.status">
                      <i class="status-indicator-dot" />
                      {{ statusText(selectedWarehouse.status) }}
                    </span>
                    <span class="wh-id-pill font-tabular">#{{ selectedWarehouse.id }}</span>
                  </div>
                  <p class="wh-type-desc">
                    <i class="el-icon-info" />
                    {{ warehouseTypeDesc(selectedWarehouse.warehouse_type) }}
                  </p>
                </div>
              </div>

              <!-- 快捷操作栏 -->
              <div class="wh-profile-actions">
                <el-button size="small" icon="el-icon-refresh" class="btn-wh-refresh" :loading="inventoryLoading" @click="refreshWarehouseProfile">
                  刷新
                </el-button>
                <el-button size="small" icon="el-icon-edit" class="btn-wh-edit" @click="editWarehouse(selectedWarehouse)">
                  编辑主仓库
                </el-button>
                <el-button size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openLocationCreate">
                  新增库位
                </el-button>
              </div>
            </div>

            <!-- 2. 四维全景档案矩阵（基础档案、权责时效、空间规划、实物库存） -->
            <div class="wh-specs-grid">
              <!-- 模块 1：基础档案 -->
              <div class="spec-card">
                <div class="spec-card-head">
                  <span class="spec-card-title"><i class="el-icon-tickets" /> 基础档案</span>
                  <span class="spec-card-tag">主数据</span>
                </div>
                <div class="spec-card-body">
                  <div class="spec-row">
                    <span class="spec-k">仓库编码</span>
                    <span class="spec-v font-tabular code-text">{{ selectedWarehouse.warehouse_code }}</span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">仓库全称</span>
                    <span class="spec-v font-medium text-dark">{{ selectedWarehouse.warehouse_name }}</span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">业务分类</span>
                    <span class="spec-v">{{ whTypeText(selectedWarehouse.warehouse_type) }}</span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">实体状态</span>
                    <span class="spec-v" :class="selectedWarehouse.status === 'enabled' ? 'text-success' : 'text-muted'">
                      {{ selectedWarehouse.status === 'enabled' ? '正常启用' : '已停用' }}
                    </span>
                  </div>
                </div>
              </div>

              <!-- 模块 2：人员与时效 -->
              <div class="spec-card">
                <div class="spec-card-head">
                  <span class="spec-card-title"><i class="el-icon-user" /> 人员与时效</span>
                  <span class="spec-card-tag">权责追溯</span>
                </div>
                <div class="spec-card-body">
                  <div class="spec-row">
                    <span class="spec-k">仓管主管</span>
                    <span class="spec-v font-medium text-dark">
                      <i class="el-icon-user-solid user-icon" /> {{ managerName(selectedWarehouse) || '未指定负责人' }}
                    </span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">库区映射</span>
                    <span class="spec-v text-success"><i class="el-icon-check" /> 物理库区已对齐</span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">建档时间</span>
                    <span class="spec-v font-tabular text-muted">{{ formatDate(selectedWarehouse.created_at) }}</span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">最近维护</span>
                    <span class="spec-v font-tabular text-muted">{{ formatDate(selectedWarehouse.updated_at) }}</span>
                  </div>
                </div>
              </div>

              <!-- 模块 3：库区空间规划 -->
              <div class="spec-card">
                <div class="spec-card-head">
                  <span class="spec-card-title"><i class="el-icon-files" /> 库位空间规划</span>
                  <span class="spec-card-tag font-tabular">{{ locationStats.total || 0 }} 个库位</span>
                </div>
                <div class="spec-card-body">
                  <div class="spec-row">
                    <span class="spec-k">规划库区</span>
                    <span class="spec-v font-tabular">{{ areaOptions.length }} 个物理库区</span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">通道与货架</span>
                    <span class="spec-v font-tabular">{{ uniqueAisleCount }} 通道 · {{ uniqueRackCount }} 货架</span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">混放策略</span>
                    <span class="spec-v font-tabular">
                      <span class="text-success">{{ mixedLocationCount }}</span> 混放 /
                      <span>{{ dedicatedLocationCount }}</span> 专放
                    </span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">库位启用率</span>
                    <span class="spec-v font-tabular text-success">{{ locationEnableRate }}% 正常在用</span>
                  </div>
                </div>
              </div>

              <!-- 模块 4：实物库存资产 -->
              <div class="spec-card">
                <div class="spec-card-head">
                  <span class="spec-card-title"><i class="el-icon-box" /> 实物库存资产</span>
                  <span class="spec-card-tag">动态联动</span>
                </div>
                <div class="spec-card-body">
                  <div class="spec-row">
                    <span class="spec-k">在库物料</span>
                    <span class="spec-v font-tabular font-bold">{{ inventoryStats.item_count || 0 }} <small class="unit-text">种 SKU</small></span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">在库批次</span>
                    <span class="spec-v font-tabular font-bold">{{ inventoryStats.balance_line_count || 0 }} <small class="unit-text">批</small></span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">在库实物总量</span>
                    <span class="spec-v font-tabular font-bold text-success">{{ inventoryStats.total_qty || 0 }}</span>
                  </div>
                  <div class="spec-row">
                    <span class="spec-k">账面库存估值</span>
                    <span class="spec-v font-tabular font-bold text-dark">¥{{ formatCurrency(inventoryStats.inventory_value) }}</span>
                  </div>
                </div>
              </div>
            </div>

            <!-- 3. 规划库区快捷穿透条（快速过滤下方库位） -->
            <div class="wh-zone-nav-bar">
              <div class="zone-nav-left">
                <span class="zone-nav-title"><i class="el-icon-guide" /> 规划库区穿透：</span>
                <div class="zone-pills-list">
                  <button
                    type="button"
                    class="zone-pill-btn"
                    :class="{ 'is-active': !filters.area }"
                    @click="selectFilterArea('')"
                  >
                    全部库位 <span class="zone-count-pill">{{ locationStats.total || 0 }}</span>
                  </button>
                  <button
                    v-for="area in areaBreakdown"
                    :key="area.name"
                    type="button"
                    class="zone-pill-btn"
                    :class="{ 'is-active': filters.area === area.name }"
                    @click="selectFilterArea(area.name)"
                  >
                    {{ area.name }} <span class="zone-count-pill">{{ area.count }}</span>
                  </button>
                  <span v-if="!areaBreakdown.length" class="text-muted text-xs">（当前仓库尚未划分明确区域，编辑库位时可录入区域）</span>
                </div>
              </div>
              <div class="zone-nav-right">
                <span class="capacity-stat font-tabular">
                  <i class="el-icon-pie-chart" /> 标准库容总量：<strong>{{ totalCapacity.toLocaleString() }}</strong>
                </span>
              </div>
            </div>

            <!-- 4. 仓库补充说明与车间物理定位指引 -->
            <div class="wh-profile-remark-banner">
              <div class="remark-left">
                <i class="el-icon-location-information remark-icon" />
                <div class="remark-text-block">
                  <span class="remark-tag">仓库物理定位与管理指引</span>
                  <span class="remark-content-text">{{ selectedWarehouse.remark || '未录入车间具体物理位置与管理说明，点击上方「编辑主仓库」可快速补充位置说明、环境温湿度或出入库指引。' }}</span>
                </div>
              </div>
              <div class="remark-rules-badges">
                <span class="rule-badge"><i class="el-icon-check" /> ISO四级库位规范</span>
                <span class="rule-badge"><i class="el-icon-check" /> FIFO先进先出批次</span>
                <span class="rule-badge"><i class="el-icon-check" /> 扫码寻位与上架</span>
              </div>
            </div>
          </div>

          <!-- 库位筛选工具栏 -->
          <div class="location-filter-toolbar">
            <div class="filter-fields">
              <div class="filter-item">
                <span class="filter-label">库位查询</span>
                <el-input
                  v-model.trim="filters.keyword"
                  size="small"
                  clearable
                  prefix-icon="el-icon-search"
                  placeholder="库位编码 / 库位名称..."
                />
              </div>
              <div class="filter-item">
                <span class="filter-label">区域</span>
                <el-select v-model="filters.area" size="small" clearable placeholder="全部区域">
                  <el-option v-for="a in areaOptions" :key="a" :label="a" :value="a" />
                </el-select>
              </div>
              <div class="filter-item">
                <span class="filter-label">状态</span>
                <el-select v-model="filters.status" size="small" clearable placeholder="全部状态">
                  <el-option label="正常启用" value="enabled" />
                  <el-option label="已停用" value="disabled" />
                </el-select>
              </div>
            </div>
            <div class="filter-actions">
              <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
            </div>
          </div>

          <!-- 库位数据表格 -->
          <div v-if="!selectedWarehouse.id" class="empty-wh-container">
            <i class="el-icon-office-building empty-icon" />
            <p>请先在左侧选择仓库，或点击右上角新增仓库。</p>
          </div>

          <div v-else class="table-wrap">
            <el-table
              v-loading="loading"
              :data="filteredLocations"
              v-paged-scroll="loadMoreLocations"
              size="small"
              border
              empty-text="当前仓库下暂无库位档案，请点击上方「新增库位」录入。"
              class="enterprise-table"
              @row-click="editLocation"
            >
              <el-table-column prop="location_code" label="库位编码" width="130">
                <template slot-scope="{ row }">
                  <span class="font-tabular code-chip" @click.stop="editLocation(row)">{{ row.location_code }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="location_name" label="库位名称" min-width="150" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span class="loc-name-text" title="点击编辑库位">{{ row.location_name }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="area" label="区域" width="80" align="center">
                <template slot-scope="{ row }">
                  <span v-if="row.area" class="area-badge">{{ row.area }}</span>
                  <span v-else class="text-muted">-</span>
                </template>
              </el-table-column>

              <el-table-column prop="aisle" label="通道" width="70" align="center">
                <template slot-scope="{ row }">
                  <span class="font-tabular">{{ row.aisle || '-' }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="rack" label="货架" width="70" align="center">
                <template slot-scope="{ row }">
                  <span class="font-tabular">{{ row.rack || '-' }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="level" label="层位" width="70" align="center">
                <template slot-scope="{ row }">
                  <span class="font-tabular">{{ row.level || '-' }}</span>
                </template>
              </el-table-column>

              <el-table-column prop="standard_capacity" label="标准容量" width="95" align="right">
                <template slot-scope="{ row }">
                  <span class="font-tabular">{{ Number(row.standard_capacity || 0).toLocaleString() }}</span>
                </template>
              </el-table-column>

              <el-table-column label="混放控制" width="95" align="center">
                <template slot-scope="{ row }">
                  <el-tag size="mini" :type="row.allow_mixed ? 'success' : 'info'" effect="plain">
                    {{ row.allow_mixed ? '允许混放' : '单品专放' }}
                  </el-tag>
                </template>
              </el-table-column>

              <el-table-column label="状态" width="80" align="center">
                <template slot-scope="{ row }">
                  <el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'" effect="light" class="status-tag">
                    {{ statusText(row.status) }}
                  </el-tag>
                </template>
              </el-table-column>

              <el-table-column label="操作" width="150" align="center">
                <template slot-scope="{ row }">
                  <div class="row-actions">
                    <el-button type="text" size="mini" class="btn-action-edit" icon="el-icon-edit" @click.stop="editLocation(row)">编辑</el-button>
                    <el-button
                      type="text"
                      size="mini"
                      :class="row.status === 'enabled' ? 'btn-action-disable' : 'btn-action-enable'"
                      @click.stop="toggleLocation(row)"
                    >
                      {{ row.status === 'enabled' ? '停用' : '启用' }}
                    </el-button>
                    <el-button
                      v-if="row.status !== 'enabled'"
                      type="text"
                      size="mini"
                      class="btn-action-delete"
                      icon="el-icon-delete"
                      @click.stop="deleteLocation(row)"
                    >
                      删除
                    </el-button>
                  </div>
                </template>
              </el-table-column>
            </el-table>
          </div>

          <footer class="card-footer">
            <span class="footer-count-text">当前仓库共 <strong>{{ locationPage.total }}</strong> 个库位</span>
          </footer>
        </main>
      </div>
    </div>

    <!-- 弹窗 1：新增 / 编辑仓库档案（免滚动紧凑弹窗） -->
    <el-dialog
      :visible.sync="warehouseDialogVisible"
      :title="warehouseDialogTitle"
      width="min(580px, calc(100vw - 32px))"
      top="12vh"
      custom-class="erp-modal-dialog warehouse-account-dialog"
      :close-on-click-modal="false"
    >
      <el-form ref="warehouseForm" :model="warehouseForm" :rules="warehouseRules" label-position="top" size="small" class="compact-modal-form">
        <div class="form-grid-2">
          <el-form-item label="仓库编码" prop="warehouse_code">
            <el-input v-model.trim="warehouseForm.warehouse_code" :disabled="!!warehouseForm.id" class="font-tabular" placeholder="如 WH-RAW-01" />
          </el-form-item>
          <el-form-item label="仓库名称" prop="warehouse_name">
            <el-input v-model.trim="warehouseForm.warehouse_name" placeholder="如 原材料一号总仓" />
          </el-form-item>
        </div>

        <div class="form-grid-2">
          <el-form-item label="仓库类型">
            <el-select v-model="warehouseForm.warehouse_type" class="full">
              <el-option label="综合仓 (多业务混合周转)" value="general" />
              <el-option label="原材料仓 (采购原料与备料)" value="raw_material" />
              <el-option label="成品仓 (完工产出与发货)" value="finished_goods" />
              <el-option label="半成品仓 (工序周转缓冲)" value="semi_finished" />
              <el-option label="线边仓 (工位即时投料)" value="wip" />
              <el-option label="包材仓 (包装辅料专储)" value="packaging" />
              <el-option label="不良品仓 (隔离质检与报废)" value="defective" />
            </el-select>
          </el-form-item>
          <el-form-item label="仓管负责人" prop="manager_user_id">
            <el-input :value="managerLabel" readonly placeholder="请选择员工" @click.native="managerPickerVisible = true" @keydown.native.enter.prevent="managerPickerVisible = true">
              <el-button slot="append" icon="el-icon-user" aria-label="选择仓管负责人" @click="managerPickerVisible = true" />
            </el-input>
            <el-button v-if="warehouseForm.manager_user_id || warehouseForm.manager" type="text" size="mini" @click="clearManager">清除负责人</el-button>
          </el-form-item>
        </div>

        <el-form-item label="仓库启用状态">
          <el-radio-group v-model="warehouseForm.status" class="status-radio-compact">
            <el-radio label="enabled"><span class="text-success"><i class="el-icon-circle-check" /> 正常启用</span></el-radio>
            <el-radio label="disabled"><span class="text-muted"><i class="el-icon-circle-close" /> 停用仓库</span></el-radio>
          </el-radio-group>
        </el-form-item>

        <el-form-item label="备注说明 / 地址定位">
          <el-input v-model="warehouseForm.remark" type="textarea" :rows="3" placeholder="补充说明仓库地址、管理规范或定位说明..." />
        </el-form-item>
      </el-form>

      <div slot="footer" class="dialog-footer">
        <el-button size="small" class="btn-dialog-cancel" @click="warehouseDialogVisible = false">取消</el-button>
        <el-button size="small" type="success" :loading="saving" class="btn-theme-create" icon="el-icon-check" @click="saveWarehouse">
          保存仓库档案
        </el-button>
      </div>
    </el-dialog>

    <warehouse-manager-picker :visible.sync="managerPickerVisible" :current="managerSelection" @selected="setManager" />

    <!-- 弹窗 2：新增 / 编辑库位档案（免滚动紧凑弹窗） -->
    <el-dialog
      :visible.sync="locationDialogVisible"
      :title="locationDialogTitle"
      width="680px"
      top="10vh"
      custom-class="erp-modal-dialog"
      :close-on-click-modal="false"
    >
      <el-form ref="locationForm" :model="locationForm" :rules="locationRules" label-position="top" size="small" class="compact-modal-form">
        <!-- 所属仓库徽标条 -->
        <div class="warehouse-anchor-banner">
          <i class="el-icon-office-building" />
          <span>所属仓库：<strong>{{ selectedWarehouse.warehouse_code || '-' }} · {{ selectedWarehouse.warehouse_name || '未选择' }}</strong> ({{ whTypeText(selectedWarehouse.warehouse_type) }})</span>
        </div>

        <div class="form-grid-2">
          <el-form-item label="库位编码" prop="location_code">
            <el-input v-model.trim="locationForm.location_code" :disabled="!!locationForm.id" class="font-tabular" placeholder="如 A-01-01-01" />
          </el-form-item>
          <el-form-item label="库位名称" prop="location_name">
            <el-input v-model.trim="locationForm.location_name" placeholder="如 A区1号架1层位" />
          </el-form-item>
        </div>

        <div class="form-grid-4">
          <el-form-item label="区域 (Area)">
            <el-input v-model.trim="locationForm.area" placeholder="如 A区" />
          </el-form-item>
          <el-form-item label="通道 (Aisle)">
            <el-input v-model.trim="locationForm.aisle" class="font-tabular" placeholder="如 01" />
          </el-form-item>
          <el-form-item label="货架 (Rack)">
            <el-input v-model.trim="locationForm.rack" class="font-tabular" placeholder="如 01" />
          </el-form-item>
          <el-form-item label="层位 (Level)">
            <el-input v-model.trim="locationForm.level" class="font-tabular" placeholder="如 01" />
          </el-form-item>
        </div>

        <div class="form-grid-3">
          <el-form-item label="标准容量">
            <el-input-number v-model="locationForm.standard_capacity" :min="0" class="full font-tabular" controls-position="right" />
          </el-form-item>
          <el-form-item label="是否允许混放">
            <div class="switch-box-compact">
              <el-switch v-model="locationForm.allow_mixed" active-color="#008b4b" />
              <span class="text-xs">{{ locationForm.allow_mixed ? '允许混放' : '单品专放' }}</span>
            </div>
          </el-form-item>
          <el-form-item label="库位状态">
            <el-radio-group v-model="locationForm.status" class="status-radio-compact">
              <el-radio label="enabled"><span class="text-success">启用</span></el-radio>
              <el-radio label="disabled"><span class="text-muted">停用</span></el-radio>
            </el-radio-group>
          </el-form-item>
        </div>

        <el-form-item label="备注说明">
          <el-input v-model="locationForm.remark" type="textarea" :rows="2" placeholder="补充说明该库位承重、存取方式或特殊存放限制..." />
        </el-form-item>

        <div class="dialog-note">
          <i class="el-icon-info" />
          <span>保存后更新库位基础档案，不影响历史库存结余与出入库流转记录。</span>
        </div>
      </el-form>

      <div slot="footer" class="dialog-footer">
        <el-button size="small" class="btn-dialog-cancel" @click="locationDialogVisible = false">取消</el-button>
        <el-button size="small" type="success" :loading="saving" class="btn-theme-create" icon="el-icon-check" @click="saveLocation">
          保存库位档案
        </el-button>
      </div>
    </el-dialog>
  </section>
</template>

<script>
import pagedScroll from '../../../directives/pagedScroll'
import { createPageState, queryPage } from '../../../utils/pagedQuery'
import { deleteEntity, disableEntity, enableEntity, getEntity, listEntity, saveEntity } from '../../../api/erp/master'
import { listInventoryBalances } from '../../../api/erp/inventory'
import WarehouseManagerPicker from '../../../components/master/WarehouseManagerPicker.vue'

const emptyWh = () => ({
  id: null,
  warehouse_code: '',
  warehouse_name: '',
  warehouse_type: 'general',
  manager: '',
  manager_user_id: null,
  manager_user: null,
  expected_manager_user_id: null,
  status: 'enabled',
  remark: ''
})

const emptyLoc = () => ({
  id: null,
  warehouse_id: null,
  location_code: '',
  location_name: '',
  area: '',
  aisle: '',
  rack: '',
  level: '',
  standard_capacity: 0,
  allow_mixed: false,
  status: 'enabled',
  remark: ''
})

export default {
  name: 'WarehouseLocationBoard',
  directives: { pagedScroll },
  components: { WarehouseManagerPicker },
  data () {
    return {
      loading: false,
      saving: false,
      managerPickerVisible: false,
      viewMode: 'card',
      warehouseDialogVisible: false,
      locationDialogVisible: false,
      warehouses: [],
      warehousePage: createPageState(50),
      locationPage: createPageState(50),
      inventorySequence: 0,
      warehouseSearchTimer: null,
      locationSearchTimer: null,
      locations: [],
      selectedWarehouse: {},
      warehouseForm: emptyWh(),
      locationForm: emptyLoc(),
      warehouseKeyword: '',
      filters: { keyword: '', area: '', status: '' },
      inventoryLoading: false,
      inventoryStats: {
        item_count: 0,
        balance_line_count: 0,
        total_qty: 0,
        inventory_value: 0
      },
      warehouseRules: {
        warehouse_code: [{ required: true, message: '请输入仓库编码', trigger: 'blur' }],
        warehouse_name: [{ required: true, message: '请输入仓库名称', trigger: 'blur' }]
      },
      locationRules: {
        location_code: [{ required: true, message: '请输入库位编码', trigger: 'blur' }],
        location_name: [{ required: true, message: '请输入库位名称', trigger: 'blur' }]
      }
    }
  },
  computed: {
    locationStats () { return this.locationPage.warehouseStats },
    managerSelection () {
      return this.warehouseForm.manager_user ? { ...this.warehouseForm.manager_user, id: this.warehouseForm.manager_user_id } : null
    },
    managerLabel () {
      const account = this.warehouseForm.manager_user
      if (!account) return this.warehouseForm.manager ? `${this.warehouseForm.manager}（请选择员工账号）` : ''
      const disabled = !['normal', 'active'].includes((account.status || '').trim().toLowerCase())
      return `${account.nickname || account.username}（${account.username || account.legacy_id}）${disabled ? ' · 已停用' : ''}`
    },
    warehouseDialogTitle () {
      return this.warehouseForm.id ? `编辑仓库档案 - ${this.warehouseForm.warehouse_name}` : '新增实体仓库档案'
    },
    locationDialogTitle () {
      return this.locationForm.id ? `编辑库位档案 - ${this.locationForm.location_code}` : '新增库位档案'
    },
    filteredWarehouses () {
      return this.warehouses
    },
    currentLocations () {
      return this.locations
    },
    enabledLocationCount () {
      return Number(this.locationStats.enabled || 0)
    },
    disabledLocationCount () {
      return Number(this.locationStats.disabled || 0)
    },
    mixedLocationCount () {
      return Number(this.locationStats.mixed || 0)
    },
    dedicatedLocationCount () {
      return Math.max(0, Number(this.locationStats.total || 0) - this.mixedLocationCount)
    },
    totalCapacity () {
      return Number(this.locationStats.capacity || 0)
    },
    areaBreakdown () {
      return this.locationStats.areas || []
    },
    uniqueAisleCount () {
      return Number(this.locationStats.aisle_count || 0)
    },
    uniqueRackCount () {
      return Number(this.locationStats.rack_count || 0)
    },
    locationEnableRate () {
      return this.locationStats.total ? Math.round(this.enabledLocationCount / this.locationStats.total * 100) : 0
    },
    filteredLocations () {
      return this.currentLocations
    },
    areaOptions () {
      return this.areaBreakdown.map(area => area.name)
    }
  },
  watch: {
    warehouseKeyword () {
      clearTimeout(this.warehouseSearchTimer)
      this.warehouseSearchTimer = setTimeout(() => this.loadWarehouses(), 250)
    },
    filters: {
      deep: true,
      handler () {
        clearTimeout(this.locationSearchTimer)
        this.locationSearchTimer = setTimeout(() => this.loadLocations(), 200)
      }
    }
  },
  beforeDestroy () {
    clearTimeout(this.warehouseSearchTimer)
    clearTimeout(this.locationSearchTimer)
  },
  created () {
    this.fetchAll()
  },
  methods: {
    managerName (warehouse) {
      if (!warehouse) return ''
      const account = warehouse.manager_user
      return (account && (account.nickname || account.username)) || warehouse.manager || ''
    },
    setManager (account) {
      this.warehouseForm.manager_user_id = account.id
      this.warehouseForm.manager_user = { ...account, legacy_id: account.id }
      this.warehouseForm.manager = account.nickname || account.username
    },
    clearManager () {
      this.warehouseForm.manager_user_id = null; this.warehouseForm.manager_user = null; this.warehouseForm.manager = ''
    },
    async loadWarehouses (append = false) {
      try {
        const data = await queryPage(this.warehousePage, params => listEntity('warehouses', params), {
          keyword: this.warehouseKeyword, include_location_summary: 1
        }, append)
        if (data) this.warehouses = this.warehousePage.rows
      } catch (e) { this.$message.error(e.userMessage || '仓库列表加载失败') }
    },
    loadMoreWarehouses () { return this.loadWarehouses(true) },
    async loadLocations (append = false) {
      const warehouseId = this.selectedWarehouse.id
      if (!warehouseId) { this.locations = []; this.locationPage = createPageState(50); return }
      const state = this.locationPage
      try {
        const data = await queryPage(state, params => listEntity('locations', params), {
          warehouse_id: warehouseId, ...this.filters, include_stats: 1
        }, append)
        if (data && state === this.locationPage) this.locations = state.rows
      } catch (e) { this.$message.error(e.userMessage || '库位加载失败') }
    },
    loadMoreLocations () { return this.loadLocations(true) },
    async fetchAll () {
      this.loading = true
      try {
        await this.loadWarehouses()
        const selectedId = this.selectedWarehouse.id
        let selected = this.warehouses.find(row => Number(row.id) === Number(selectedId))
        // 当前仓库可能在列表后续页或搜索范围之外，刷新不能悄悄切换成第一页仓库。
        if (!selected && selectedId) {
          try { selected = (await getEntity('warehouses', selectedId, { include_location_summary: 1 })).data }
          catch (e) { if (!e.response || e.response.status !== 404) throw e }
        }
        await this.selectWarehouse(selected || this.warehouses[0] || {})
      } catch (e) { this.$message.error(e.userMessage || '仓库与库位加载失败') }
      finally { this.loading = false }
    },
    async fetchInventoryStats (warehouseId) {
      const sequence = ++this.inventorySequence
      if (!warehouseId) {
        this.inventoryStats = { item_count: 0, balance_line_count: 0, total_qty: 0, inventory_value: 0 }
        return
      }
      this.inventoryLoading = true
      try {
        const { data } = await listInventoryBalances({ warehouse_id: warehouseId, per_page: 5, include_quantity_summary: 1 })
        if (sequence !== this.inventorySequence) return
        const stats = data.stats || {}
        const quantities = stats.quantity_by_unit || []
        this.inventoryStats = {
          item_count: stats.item_count || 0,
          balance_line_count: stats.balance_line_count || 0,
          total_qty: quantities.length ? quantities.map(row => `${String(row.quantity).replace(/(\.\d*?[1-9])0+$|\.0+$/, '$1')} ${row.unit_name}`).join(' / ') : 0,
          inventory_value: Number(stats.inventory_value || 0)
        }
      } catch (e) {
        if (sequence !== this.inventorySequence) return
        this.inventoryStats = { item_count: '—', balance_line_count: '—', total_qty: '—', inventory_value: null }
        this.$message.error(e.userMessage || '库存统计加载失败')
      } finally { if (sequence === this.inventorySequence) this.inventoryLoading = false }
    },
    async refreshWarehouseProfile () {
      await this.fetchAll()
      this.$message.success('主仓库全量档案与在库指标已刷新')
    },
    async selectWarehouse (row) {
      const changed = Number(row.id) !== Number(this.selectedWarehouse.id)
      this.selectedWarehouse = { ...row }
      if (changed) {
        this.filters.area = ''
        this.locationPage = createPageState(50)
        this.locations = []
      }
      await this.$nextTick()
      clearTimeout(this.locationSearchTimer)
      await Promise.all([this.loadLocations(), this.fetchInventoryStats(row.id)])
    },
    selectFilterArea (area) {
      this.filters.area = area === this.filters.area ? '' : area
    },
    reset () {
      this.filters = { keyword: '', area: '', status: '' }
    },
    openWarehouseCreate () {
      this.warehouseForm = emptyWh()
      this.warehouseDialogVisible = true
      this.$nextTick(() => { if (this.$refs.warehouseForm) this.$refs.warehouseForm.clearValidate() })
    },
    editWarehouse (row) {
      this.warehouseForm = { ...emptyWh(), ...row, expected_manager_user_id: row.manager_user_id || null }
      this.warehouseDialogVisible = true
      this.$nextTick(() => { if (this.$refs.warehouseForm) this.$refs.warehouseForm.clearValidate() })
    },
    saveWarehouse () {
      if (this.saving) return
      if (this.warehouseForm.manager && !this.warehouseForm.manager_user_id) return this.$message.warning('请重新选择仓管负责人，或清除原负责人')
      this.$refs.warehouseForm.validate(async ok => {
        if (!ok) return
        this.saving = true
        try {
          const { manager, manager_user: managerUser, locations, ...payload } = this.warehouseForm
          await saveEntity('warehouses', payload)
          this.$message.success('仓库档案已保存')
          this.warehouseDialogVisible = false
          await this.fetchAll()
        } catch (e) {
          this.$message.error(e.userMessage || '仓库保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    openLocationCreate () {
      if (!this.selectedWarehouse.id) return this.$message.warning('请先在左侧选择仓库')
      this.locationForm = { ...emptyLoc(), warehouse_id: this.selectedWarehouse.id }
      this.locationDialogVisible = true
      this.$nextTick(() => { if (this.$refs.locationForm) this.$refs.locationForm.clearValidate() })
    },
    editLocation (row) {
      this.locationForm = {
        ...emptyLoc(),
        ...row,
        allow_mixed: !!row.allow_mixed,
        standard_capacity: Number(row.standard_capacity || 0)
      }
      this.locationDialogVisible = true
      this.$nextTick(() => { if (this.$refs.locationForm) this.$refs.locationForm.clearValidate() })
    },
    saveLocation () {
      this.$refs.locationForm.validate(async ok => {
        if (!ok) return
        this.saving = true
        try {
          await saveEntity('locations', this.locationForm)
          this.$message.success('库位档案已保存')
          this.locationDialogVisible = false
          await this.fetchAll()
        } catch (e) {
          this.$message.error(e.userMessage || '库位保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    async toggleWarehouse (row) {
      const enabling = row.status !== 'enabled'
      try {
        await this.$confirm(enabling ? `确认启用仓库 ${row.warehouse_code} / ${row.warehouse_name}？` : `确认停用仓库 ${row.warehouse_code} / ${row.warehouse_name}？`, '仓库状态确认', { type: 'warning' })
        if (enabling) await enableEntity('warehouses', row.id)
        else await disableEntity('warehouses', row.id)
        this.$message.success(enabling ? '仓库已启用' : '仓库已停用')
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '仓库状态更新失败')
      }
    },
    async deleteWarehouse (row) {
      try {
        await this.$confirm(`确认删除仓库 ${row.warehouse_code} / ${row.warehouse_name}？若已有库位或历史出入库记录，系统将安全拦截。`, '删除仓库', {
          type: 'warning',
          confirmButtonText: '确认删除'
        })
        await deleteEntity('warehouses', row.id)
        this.$message.success('仓库已删除')
        if (this.selectedWarehouse.id === row.id) this.selectedWarehouse = {}
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '仓库删除失败')
      }
    },
    async toggleLocation (row) {
      const enabling = row.status !== 'enabled'
      try {
        await this.$confirm(enabling ? `确认启用库位 ${row.location_code}？` : `确认停用库位 ${row.location_code}？`, '库位状态确认', { type: 'warning' })
        if (enabling) await enableEntity('locations', row.id)
        else await disableEntity('locations', row.id)
        this.$message.success(enabling ? '库位已启用' : '库位已停用')
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '库位状态更新失败')
      }
    },
    async deleteLocation (row) {
      try {
        await this.$confirm(`确认删除库位 ${row.location_code} / ${row.location_name}？若当前库位存在库存结余，系统将安全拦截。`, '删除库位', {
          type: 'warning',
          confirmButtonText: '确认删除'
        })
        await deleteEntity('locations', row.id)
        this.$message.success('库位已删除')
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '库位删除失败')
      }
    },
    locationCount (id) {
      const warehouse = this.warehouses.find(row => Number(row.id) === Number(id))
      return Number((warehouse && warehouse.locations_count) || 0)
    },
    warehouseAreaCount (whId) {
      const warehouse = this.warehouses.find(row => Number(row.id) === Number(whId))
      return Number((warehouse && warehouse.area_count) || 0)
    },
    warehouseRowClass ({ row }) {
      return row.id === this.selectedWarehouse.id ? 'selected-row' : ''
    },
    whTypeText (v) {
      return ({
        general: '综合仓',
        raw_material: '原材料仓',
        finished_goods: '成品仓',
        semi_finished: '半成品仓',
        wip: '线边仓',
        packaging: '包材仓',
        defective: '不良品仓'
      })[v] || v || '-'
    },
    warehouseTypeTag (v) {
      const map = {
        general: 'info',
        raw_material: 'success',
        finished_goods: 'warning',
        semi_finished: 'primary',
        wip: '',
        packaging: 'info',
        defective: 'danger'
      }
      return map[v] || 'info'
    },
    warehouseTypeDesc (v) {
      const descs = {
        general: '多功能综合仓：支持原材料进厂、生产工单领料下料、半成品周转与成品产出等多业务混合流转。',
        raw_material: '原材料总仓：专项用于采购原料收货、来料批次质检、原料库位存放与车间生产工单投料发料。',
        finished_goods: '完工成品总仓：用于承接车间检验合格成品入库、打包封箱暂存与销售出库拣货发运。',
        semi_finished: '半成品周转仓：用于工序流转在制品缓冲、半成品检验状态暂存与后续装配投产。',
        wip: '车间线边仓：紧邻制造装配工位，存放当前正在执行生产任务的即时物料与料箱。',
        packaging: '辅料包材仓：集中存放纸箱、外箱、缠绕膜、防护气泡袋等物流打包辅助物料。',
        defective: '不良品隔离仓：物理隔离待判定物料、来料检验不合格品、生产报废品与客户退货待检品。'
      }
      return descs[v] || '企业实体仓储空间，用于物料与货品规范化出入库管理与精准批次追溯。'
    },
    statusText (v) {
      return v === 'disabled' ? '停用' : '启用'
    },
    formatDate (v) {
      return v ? String(v).replace('T', ' ').slice(0, 16) : '-'
    },
    formatCurrency (val) {
      const num = Number(val || 0)
      return num.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    }
  }
}
</script>

<style scoped>
.wl-page {
  position: relative;
  min-height: calc(100vh - 52px);
  background: #f8fafc;
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
}

.wl-workspace {
  padding: 16px 20px 24px;
  width: 100%;
  min-width: 0;
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
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.total-tag,
.sub-total-tag {
  border-radius: 4px;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
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

.btn-theme-outline {
  border-color: #86efac !important;
  color: #166534 !important;
  background: #f0fdf4 !important;
}

.btn-theme-outline:hover {
  border-color: #008b4b !important;
  color: #00763f !important;
  background: #dcfce7 !important;
}

/* 布局栅格：左仓库 (420px) + 右库位与主仓库详情 (1fr) */
.board-layout-grid {
  display: grid;
  grid-template-columns: 420px 1fr;
  gap: 16px;
  align-items: start;
}

.warehouse-card,
.location-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
}

.card-header {
  padding: 12px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  background: #fafbfc;
  border-bottom: 1px solid #e2e8f0;
}

.card-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-title-icon {
  color: #008b4b;
  font-size: 16px;
}

.card-title-text {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}

.card-search-bar {
  padding: 10px 14px;
  background: #ffffff;
  border-bottom: 1px solid #f1f5f9;
}

.view-toggle ::v-deep .el-radio-button__inner {
  padding: 5px 8px;
}

.view-toggle ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background-color: #008b4b;
  border-color: #008b4b;
  box-shadow: -1px 0 0 0 #008b4b;
}

/* 左侧仓库卡片式列表 */
.wh-card-list {
  max-height: 720px;
  overflow-y: auto;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 10px;
  background: #f8fafc;
}

.wh-item-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 14px;
  cursor: pointer;
  transition: all 0.15s ease;
  position: relative;
}

.wh-item-card:hover {
  border-color: #86efac;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.08);
}

.wh-item-card.is-active {
  background: #f0fdf4;
  border-color: #86efac;
  box-shadow: inset 4px 0 0 #008b4b, 0 2px 8px rgba(0, 139, 75, 0.1);
}

.wh-card-top {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 8px;
}

.wh-card-title-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.wh-card-title {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}

.wh-item-card.is-active .wh-card-title {
  color: #00763f;
}

.wh-card-badges-row {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.wh-card-loc-pill,
.wh-card-area-pill {
  font-size: 11px;
  color: #475569;
  background: #f1f5f9;
  padding: 1px 6px;
  border-radius: 3px;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

.wh-card-area-pill {
  color: #047857;
  background: #ecfdf5;
  border: 1px solid #d1fae5;
}

.wh-card-remark {
  font-size: 11px;
  color: #64748b;
  background: #f8fafc;
  padding: 4px 8px;
  border-radius: 4px;
  border-left: 2px solid #10b981;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  gap: 4px;
}

.wh-card-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 8px;
  border-top: 1px dashed #e2e8f0;
  font-size: 12px;
  color: #64748b;
}

.wh-card-manager {
  display: flex;
  align-items: center;
  gap: 4px;
}

.wh-card-manager strong {
  color: #1e293b;
}

.wh-card-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.empty-wh-list {
  padding: 40px 20px;
  text-align: center;
  color: #94a3b8;
  font-size: 12px;
}

.empty-wh-list i {
  font-size: 32px;
  margin-bottom: 8px;
  color: #cbd5e1;
}

/* 右侧主仓库全景卡片 */
.wh-profile-card {
  padding: 16px 20px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
}

.wh-profile-top {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.wh-profile-main {
  display: flex;
  align-items: flex-start;
  gap: 14px;
  flex: 1;
  min-width: 0;
}

.wh-profile-avatar {
  width: 48px;
  height: 48px;
  border-radius: 8px;
  background: linear-gradient(135deg, #f0fdf4 0%, #dcfce7 100%);
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 26px;
  border: 1px solid #bbf7d0;
  flex-shrink: 0;
  box-shadow: 0 1px 3px rgba(0, 139, 75, 0.08);
}

.wh-profile-title-block {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex: 1;
  min-width: 0;
}

.wh-title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.wh-main-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
  letter-spacing: -0.01em;
}

.wh-main-code {
  padding: 2px 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  font-size: 13px;
  font-weight: 600;
  color: #00763f;
}

.wh-type-badge {
  font-weight: 500;
}

.wh-status-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  font-size: 12px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 12px;
}

.wh-status-pill.enabled {
  background: #f0fdf4;
  color: #166534;
  border: 1px solid #bbf7d0;
}

.wh-status-pill.disabled {
  background: #f1f5f9;
  color: #64748b;
  border: 1px solid #cbd5e1;
}

.status-indicator-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #10b981;
}

.wh-status-pill.disabled .status-indicator-dot {
  background: #94a3b8;
}

.wh-id-pill {
  font-size: 11px;
  color: #94a3b8;
  background: #f8fafc;
  padding: 2px 6px;
  border-radius: 3px;
  border: 1px solid #e2e8f0;
}

.wh-type-desc {
  margin: 2px 0 0 0;
  font-size: 12px;
  color: #64748b;
  line-height: 1.5;
  display: flex;
  align-items: center;
  gap: 5px;
}

.wh-type-desc i {
  color: #008b4b;
}

.wh-profile-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.btn-wh-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
  background: #ffffff !important;
}

.btn-wh-refresh:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
  background: #f0fdf4 !important;
}

.btn-wh-edit {
  border-color: #cbd5e1 !important;
  color: #008b4b !important;
  background: #ffffff !important;
}

.btn-wh-edit:hover {
  border-color: #008b4b !important;
  background: #f0fdf4 !important;
}

/* 四维全景档案矩阵网格 */
.wh-specs-grid {
  margin-top: 14px;
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
}

.spec-card {
  background: #fafbfc;
  border: 1px solid #e2e8f0;
  border-top: 3px solid #008b4b;
  border-radius: 6px;
  padding: 10px 12px;
  transition: all 0.2s ease;
}

.spec-card:hover {
  background: #ffffff;
  border-color: #bbf7d0;
  border-top-color: #008b4b;
  box-shadow: 0 2px 8px rgba(0, 139, 75, 0.08);
}

.spec-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
  padding-bottom: 6px;
  border-bottom: 1px dashed #e2e8f0;
}

.spec-card-title {
  font-size: 12px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 5px;
}

.spec-card-title i {
  color: #008b4b;
  font-size: 13px;
}

.spec-card-tag {
  font-size: 10px;
  color: #008b4b;
  background: #f0fdf4;
  padding: 1px 6px;
  border-radius: 10px;
  border: 1px solid #dcfce7;
}

.spec-card-body {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.spec-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  gap: 8px;
}

.spec-k {
  color: #64748b;
  flex-shrink: 0;
}

.spec-v {
  color: #0f172a;
  text-align: right;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.code-text {
  color: #00763f;
  font-weight: 600;
}

.user-icon {
  color: #008b4b;
  margin-right: 2px;
}

.unit-text {
  font-size: 10px;
  font-weight: normal;
  color: #64748b;
  margin-left: 2px;
}

/* 规划库区快捷穿透条 */
.wh-zone-nav-bar {
  margin-top: 12px;
  padding: 8px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.zone-nav-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  flex: 1;
}

.zone-nav-title {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}

.zone-nav-title i {
  color: #008b4b;
}

.zone-pills-list {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.zone-pill-btn {
  border: 1px solid #cbd5e1;
  background: #ffffff;
  color: #334155;
  padding: 3px 10px;
  border-radius: 14px;
  font-size: 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  transition: all 0.15s ease;
  font-family: inherit;
}

.zone-pill-btn:hover {
  border-color: #008b4b;
  color: #008b4b;
  background: #f0fdf4;
}

.zone-pill-btn.is-active {
  background: #008b4b;
  border-color: #008b4b;
  color: #ffffff;
  font-weight: 600;
}

.zone-count-pill {
  font-size: 10px;
  padding: 0 5px;
  border-radius: 10px;
  background: #f1f5f9;
  color: #475569;
  font-family: inherit;
}

.zone-pill-btn.is-active .zone-count-pill {
  background: rgba(255, 255, 255, 0.25);
  color: #ffffff;
}

.zone-nav-right {
  flex-shrink: 0;
}

.capacity-stat {
  font-size: 12px;
  color: #475569;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.capacity-stat i {
  color: #008b4b;
}

.capacity-stat strong {
  color: #0f172a;
}

/* 仓库物理定位指引与规则 */
.wh-profile-remark-banner {
  margin-top: 10px;
  padding: 8px 14px;
  background: #fafbfc;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
}

.remark-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  min-width: 0;
}

.remark-icon {
  color: #008b4b;
  font-size: 16px;
  flex-shrink: 0;
}

.remark-text-block {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 12px;
}

.remark-tag {
  font-weight: 600;
  color: #047857;
  background: #ecfdf5;
  padding: 1px 6px;
  border-radius: 3px;
  border: 1px solid #d1fae5;
  white-space: nowrap;
}

.remark-content-text {
  color: #334155;
  line-height: 1.4;
}

.remark-rules-badges {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  flex-shrink: 0;
}

.rule-badge {
  font-size: 11px;
  color: #047857;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 8px;
  border-radius: 10px;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  white-space: nowrap;
}

/* 库位筛选工具栏 */
.location-filter-toolbar {
  padding: 10px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  background: #fafbfc;
  border-bottom: 1px solid #e2e8f0;
}

.filter-fields {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-label {
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
}

.filter-item .el-input {
  width: 180px;
}

.filter-item .el-select {
  width: 125px;
}

.filter-actions {
  margin-left: auto;
}

.btn-theme-reset {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 表格主体通用 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
}

.enterprise-table ::v-deep th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
  font-size: 12px;
  padding: 10px 0;
}

.enterprise-table ::v-deep td {
  padding: 9px 0;
  font-size: 13px;
  color: #1e293b;
}

/* 字体与编码徽标：自然UI字体、tabular-nums高清晰 */
.font-tabular,
.code-chip {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
}

.code-chip {
  display: inline-block;
  padding: 2px 7px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  color: #00763f;
  font-size: 12px;
  font-weight: 600;
  white-space: nowrap;
}

.wh-name-text {
  font-weight: 600;
  color: #0f172a;
  cursor: pointer;
  transition: color 0.15s;
}

.wh-name-text:hover,
.wh-name-text.is-active {
  color: #008b4b;
}

.loc-name-text {
  font-weight: 600;
  color: #0f172a;
  cursor: pointer;
  transition: color 0.15s;
}

.loc-name-text:hover {
  color: #008b4b;
}

.area-badge {
  display: inline-block;
  padding: 1px 6px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 3px;
  font-size: 11px;
  color: #475569;
}

.stat-count {
  font-size: 12px;
  color: #94a3b8;
}

.stat-count.has-val {
  color: #008b4b;
  font-weight: 600;
}

.type-tag,
.status-tag {
  border-radius: 4px;
  font-size: 11px;
}

.text-muted {
  color: #94a3b8;
}

.text-xs {
  font-size: 11px;
}

/* 操作列排版：无挤压、绝对无蓝色 */
.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.row-actions .el-button--text {
  padding: 2px 4px !important;
  font-size: 12px !important;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 2px;
  margin-left: 0 !important;
}

.btn-action-edit {
  color: #008b4b !important;
}

.btn-action-edit:hover {
  color: #00763f !important;
}

.btn-action-disable {
  color: #d97706 !important;
}

.btn-action-disable:hover {
  color: #b45309 !important;
}

.btn-action-enable {
  color: #008b4b !important;
}

.btn-action-enable:hover {
  color: #00763f !important;
}

.btn-action-delete {
  color: #ef4444 !important;
}

.btn-action-delete:hover {
  color: #dc2626 !important;
}

/* 选中行高亮 */
.enterprise-table ::v-deep .selected-row td {
  background: #f0fdf4 !important;
}

.enterprise-table ::v-deep .selected-row td:first-child {
  box-shadow: inset 4px 0 0 #008b4b;
}

/* 卡片底部统计 */
.card-footer {
  padding: 10px 16px;
  background: #ffffff;
  border-top: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.footer-count-text {
  font-size: 12px;
  color: #64748b;
}

.footer-count-text strong {
  color: #0f172a;
}

.empty-wh-container {
  padding: 60px 20px;
  text-align: center;
  color: #94a3b8;
}

.empty-icon {
  font-size: 44px;
  color: #cbd5e1;
  margin-bottom: 12px;
}

/* 弹窗系统（免滚动紧凑卡片排版） */
.erp-modal-dialog ::v-deep .el-dialog {
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
}

.erp-modal-dialog ::v-deep .el-dialog__header {
  padding: 14px 20px;
  border-bottom: 1px solid #e2e8f0;
  background: #ffffff;
}

.erp-modal-dialog ::v-deep .el-dialog__title {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.erp-modal-dialog ::v-deep .el-dialog__body {
  padding: 16px 20px 8px;
  background: #ffffff;
  overflow: visible;
}

.erp-modal-dialog ::v-deep .el-dialog__footer {
  padding: 12px 20px;
  border-top: 1px solid #e2e8f0;
  background: #f8fafc;
}

::v-deep .warehouse-account-dialog .el-dialog__body {
  max-height: calc(76vh - 120px);
  overflow-y: auto;
}

.dialog-footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: 10px;
}

.compact-modal-form .el-form-item {
  margin-bottom: 14px;
}

.compact-modal-form ::v-deep .el-form-item__label {
  padding-bottom: 2px;
  font-size: 12px;
  font-weight: 500;
  color: #475569;
  line-height: 1.4;
}

.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 16px;
}

.form-grid-3 {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0 16px;
}

.form-grid-4 {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 0 12px;
}

.full {
  width: 100%;
}

.status-radio-compact {
  height: 32px;
  display: flex;
  align-items: center;
  gap: 16px;
}

.text-success {
  color: #008b4b !important;
}

.switch-box-compact {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 32px;
}

.warehouse-anchor-banner {
  padding: 8px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #166534;
  font-size: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 14px;
}

.warehouse-anchor-banner i {
  color: #008b4b;
  font-size: 14px;
}

.dialog-note {
  padding: 8px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  color: #64748b;
  font-size: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 4px;
}

.btn-dialog-cancel {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-dialog-cancel:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 响应式断点适配 */
@media (max-width: 1440px) {
  .wh-specs-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 1100px) {
  .board-layout-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .wl-workspace {
    padding: 12px;
  }

  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }

  .head-actions {
    width: 100%;
    justify-content: flex-end;
  }

  .wh-profile-top {
    flex-direction: column;
    align-items: flex-start;
  }

  .wh-profile-actions {
    width: 100%;
    justify-content: flex-start;
    flex-wrap: wrap;
  }

  .wh-specs-grid {
    grid-template-columns: 1fr;
  }

  .wh-zone-nav-bar {
    flex-direction: column;
    align-items: stretch;
  }

  .wh-profile-remark-banner {
    flex-direction: column;
    align-items: stretch;
  }

  .location-filter-toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .filter-fields {
    flex-direction: column;
    align-items: stretch;
  }

  .filter-item {
    width: 100%;
  }

  .filter-item .el-input,
  .filter-item .el-select {
    width: 100%;
  }

  .filter-actions {
    margin-left: 0;
    justify-content: flex-end;
    width: 100%;
    display: flex;
  }

  .form-grid-2,
  .form-grid-3,
  .form-grid-4 {
    grid-template-columns: 1fr;
  }
}
</style>
