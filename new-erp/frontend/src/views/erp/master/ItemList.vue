<template>
  <div class="item-page-container">
    <!-- 页面全局头部：图标、标题、统计标签与主要操作 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-coin" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">物料管理</h1>
            <el-tag v-if="canViewItems" size="small" type="success" effect="plain" class="head-tag total-tag">共 {{ total.toLocaleString() }} 种物料</el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button v-if="canViewItems" size="small" icon="el-icon-refresh" class="btn-refresh" @click="load">刷新</el-button>
        <el-button v-if="canViewCategories" size="small" icon="el-icon-folder-opened" @click="openCategories">管理分类</el-button>
        <el-button v-if="canViewItems" size="small" icon="el-icon-upload2" class="btn-import" @click="openImport">导入物料</el-button>
        <el-button v-if="canViewItems" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openCreate">新增物料</el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 (遵照用户指令：所有页面统一采用此tip) -->
    <div v-if="canViewItems" class="erp-page-tip">
      <i class="el-icon-info" />
      <span>统一管理工厂物料和办公用品；顶部 Tag 快捷切换管理类型，新增和编辑时选择类型并配置对应属性。</span>
    </div>

    <!-- 模块选项卡容器 (对齐基础档案 archive-tabs 标签切换规范) -->
    <div v-if="canViewItems" class="tabs-card">
      <el-tabs v-model="activeScopeTab" class="archive-tabs" @tab-click="handleScopeTabClick">
        <el-tab-pane name="all">
          <span slot="label" class="custom-tab-item">
            <i class="el-icon-collection" />
            <span>全部物料</span>
            <span class="tab-badge code-mono">{{ scopeCount('all') }}</span>
          </span>
        </el-tab-pane>

        <el-tab-pane name="factory">
          <span slot="label" class="custom-tab-item">
            <i class="el-icon-office-building" />
            <span>工厂物料</span>
            <span class="tab-badge code-mono">{{ scopeCount('factory') }}</span>
          </span>
        </el-tab-pane>

        <el-tab-pane name="office">
          <span slot="label" class="custom-tab-item">
            <i class="el-icon-document" />
            <span>办公用品</span>
            <span class="tab-badge code-mono">{{ scopeCount('office') }}</span>
          </span>
        </el-tab-pane>
      </el-tabs>
    </div>

    <!-- 顶部概览指标卡片 -->
    <section v-if="canViewItems" class="metric-overview-grid">
      <div class="metric-card metric-all">
        <div class="metric-icon-box"><i class="el-icon-coin" /></div>
        <div class="metric-info">
          <span class="metric-label">{{ scopeLabel }}</span>
          <strong class="metric-val">{{ total }}</strong>
        </div>
      </div>
      <div class="metric-card metric-enabled">
        <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
        <div class="metric-info">
          <span class="metric-label">正常启用物料</span>
          <strong class="metric-val">{{ enabledCount }}</strong>
        </div>
      </div>
      <div class="metric-card metric-disabled">
        <div class="metric-icon-box"><i class="el-icon-circle-close" /></div>
        <div class="metric-info">
          <span class="metric-label">已停用物料</span>
          <strong class="metric-val">{{ disabledCount }}</strong>
        </div>
      </div>
      <div class="metric-card metric-cutting">
        <div class="metric-icon-box"><i class="el-icon-c-scale-to-original" /></div>
        <div class="metric-info">
          <span class="metric-label">{{ isOffice ? '管理库存用品' : '定长/下料物料' }}</span>
          <strong class="metric-val">{{ isOffice ? Number(stats.stock_managed || 0) : cuttingCount }}</strong>
        </div>
      </div>
    </section>

    <!-- 筛选工具栏与主表格卡片 -->
    <section v-if="canViewItems" class="table-container-card">
      <div class="filter-toolbar">
        <div class="filter-fields">
          <el-input
            v-model.trim="query.keyword"
            size="small"
            clearable
            prefix-icon="el-icon-search"
            placeholder="搜索物料编码、物料名称..."
            class="filter-input-search"
            @keyup.enter.native="search"
            @clear="search"
          />
          <el-select
            v-model="query.item_type"
            size="small"
            clearable
            placeholder="物料类型"
            class="filter-select-sm"
            @change="search"
          >
            <el-option v-for="type in itemTypes" :key="type.value" :label="type.label" :value="type.value" />
          </el-select>
          <el-select
            v-model="query.category_id"
            size="small"
            clearable
            filterable
            placeholder="物料分类"
            class="filter-select-md"
            @change="search"
          >
            <el-option v-for="row in selectableCategories" :key="row.id" :label="row.full_path" :value="row.id" />
          </el-select>
          <el-select
            v-model="query.unit_id"
            size="small"
            clearable
            placeholder="基本单位"
            class="filter-select-sm"
            @change="search"
          >
            <el-option v-for="row in businessUnits" :key="row.id" :label="`${row.unit_code} / ${row.unit_name}`" :value="row.id" />
          </el-select>
          <el-select
            v-model="query.status"
            size="small"
            clearable
            placeholder="启用状态"
            class="filter-select-xs"
            @change="search"
          >
            <el-option label="全部状态" value="" />
            <el-option label="已启用" value="enabled" />
            <el-option label="已停用" value="disabled" />
          </el-select>
        </div>

        <div class="filter-actions">
          <el-button size="small" type="primary" icon="el-icon-search" class="btn-theme-query" @click="search">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" @click="reset">重置</el-button>
          <el-button size="small" icon="el-icon-refresh" circle title="刷新列表" @click="load" />
        </div>
      </div>

      <!-- 物料主数据列表 -->
      <div class="main-table-wrap">
        <el-table
          v-loading="loading"
          :data="rows"
          border
          size="small"
          class="custom-item-table"
          :row-class-name="rowClass"
          empty-text="暂无物料档案，请点击右上角“新增物料”录入或“导入物料”。"
        >
          <el-table-column prop="item_code" label="Item编码" min-width="200">
            <template slot-scope="{ row }">
              <span class="item-code-link" :title="row.item_code" @click="openDetail(row)">
                <i class="el-icon-coin" /> {{ row.item_code }}
              </span>
            </template>
          </el-table-column>

          <el-table-column prop="item_name" label="物料名称 / 规格型号" min-width="170">
            <template slot-scope="{ row }">
              <div class="item-name-cell">
                <span class="item-name-text" :title="row.item_name">{{ row.item_name }}</span>
                <span v-if="row.spec || row.material_grade" class="item-spec-sub">
                  {{ [row.material_grade, row.spec].filter(Boolean).join(' · ') }}
                </span>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="物料类型" width="88" align="center">
            <template slot-scope="{ row }">
              <span class="type-badge">{{ itemTypeText(row.item_type) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="管理类型" width="100" align="center">
            <template slot-scope="{ row }">
              <el-tag size="mini" :type="recordScope(row) === 'office' ? 'warning' : 'success'" effect="plain">{{ recordScopeLabel(row) }}</el-tag>
            </template>
          </el-table-column>

          <el-table-column label="所属分类" min-width="115">
            <template slot-scope="{ row }">
              <span v-if="row.category" class="category-name" :title="row.category.category_name"><i class="el-icon-folder" /> {{ row.category.category_name }}</span>
              <span v-else class="text-muted">—</span>
            </template>
          </el-table-column>

          <el-table-column label="基本单位" width="88" align="center">
            <template slot-scope="{ row }">
              <span class="unit-badge" :title="canonicalUnitSymbol(row.unit)">{{ canonicalUnitSymbol(row.unit) }}</span>
            </template>
          </el-table-column>

          <el-table-column v-if="!isOffice" label="下料属性" min-width="115">
            <template slot-scope="{ row }">
              <el-tag v-if="recordScope(row) === 'factory'" size="mini" :type="isCuttingMaterial(row) ? 'warning' : 'info'" effect="plain">
                {{ cuttingModeText(row) }}
                <span v-if="cuttingMode(row) === 'length'"> · {{ Number(row.standard_stock_length_mm).toLocaleString('zh-CN') }}mm</span>
              </el-tag>
              <span v-else class="text-muted">—</span>
            </template>
          </el-table-column>

          <el-table-column v-if="!isOffice" label="关联SKU" width="88" align="center">
            <template slot-scope="{ row }">
              <span v-if="recordScope(row) === 'factory'" class="sku-count-chip">
                <i class="el-icon-connection" /> {{ Number(row.active_sku_relation_count || 0) }}
              </span>
              <span v-else class="text-muted">—</span>
            </template>
          </el-table-column>

          <el-table-column label="默认供应商" min-width="120">
            <template slot-scope="{ row }">
              <span v-if="row.default_supplier" class="supplier-text"><i class="el-icon-office-building" /> {{ row.default_supplier.supplier_name }}</span>
              <span v-else class="text-muted">未设置</span>
            </template>
          </el-table-column>

          <el-table-column label="状态" width="75" align="center">
            <template slot-scope="{ row }">
              <span class="status-pill" :class="row.status">{{ statusText(row.status) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="更新时间" width="135" align="center">
            <template slot-scope="{ row }">
              <span class="time-text">{{ formatDate(row.updated_at) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="操作" width="160" fixed="right" align="center">
            <template slot-scope="{ row }">
              <el-button type="text" size="small" icon="el-icon-view" class="action-link-theme" @click="openDetail(row)">详情</el-button>
              <el-button type="text" size="small" icon="el-icon-edit" class="action-link-theme" @click="openEdit(row)">编辑</el-button>
              <el-button
                type="text"
                size="small"
                :class="row.status === 'enabled' ? 'danger-link' : 'success-link'"
                @click="toggleStatus(row)"
              >
                {{ row.status === 'enabled' ? '停用' : '启用' }}
              </el-button>
              <el-button v-if="row.status !== 'enabled'" type="text" size="small" class="danger-link" @click="deleteItem(row)">删除</el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 表格底部分页栏 -->
      <div class="table-pagination-footer">
        <span class="total-text">共 <strong>{{ total.toLocaleString() }}</strong> 种物料</span>
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :current-page.sync="query.page"
          :page-size.sync="query.per_page"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          @current-change="load"
          @size-change="sizeChange"
        />
      </div>
    </section>

    <item-category-manager-dialog
      v-model="categoryDialogVisible"
      :management-scope="categoryDialogScope"
      @changed="onCategoriesChanged"
      @closed="onCategoriesClosed"
      @select-items="showCategoryItems"
    />

    <!-- 物料详情居中弹窗 (严格遵循 2026-09-24 规则：所有侧页全面改为四周留边居中弹窗) -->
    <el-dialog
      :visible.sync="detailDialogVisible"
      :title="selected.item_name ? `物料档案详情 - ${selected.item_name}` : '物料档案详情'"
      width="840px"
      custom-class="item-detail-modal"
      :close-on-click-modal="true"
      destroy-on-close
    >
      <div v-if="selected.id" class="modal-detail-content">
        <!-- 弹窗顶栏摘要 -->
        <div class="modal-summary-banner">
          <div class="banner-icon"><i class="el-icon-coin" /></div>
          <div class="banner-main">
            <div class="banner-title-line">
              <h2>{{ selected.item_name }}</h2>
              <span class="status-pill" :class="selected.status">{{ statusText(selected.status) }}</span>
              <el-tag size="mini" type="success" effect="plain">{{ itemTypeText(selected.item_type) }}</el-tag>
              <el-tag size="mini" :type="selectedIsOffice ? 'warning' : 'success'" effect="plain">{{ recordScopeLabel(selected) }}</el-tag>
            </div>
            <div class="banner-sub-meta">
              <span>Item编码：<strong>{{ selected.item_code }}</strong></span>
              <span>基本单位：<strong>{{ unitLabel(selected.unit) }}</strong></span>
              <span>分类：<strong>{{ categoryPath(selected.category_id) || selected.category?.category_name || '-' }}</strong></span>
            </div>
          </div>
        </div>

        <div class="detail-cards-stack">
          <!-- 属性卡片 1：基础规格信息 -->
          <section class="detail-card">
            <div class="card-title-bar">
              <span class="card-icon-badge"><i class="el-icon-document" /></span>
              <h3>基础规格信息</h3>
            </div>
            <div class="info-grid">
              <div class="info-item"><span class="info-label">管理类型</span><span class="info-val">{{ recordScopeLabel(selected) }}</span></div>
              <div class="info-item"><span class="info-label">规格型号</span><span class="info-val">{{ selected.spec || '-' }}</span></div>
              <div v-if="!selectedIsOffice" class="info-item"><span class="info-label">材质牌号</span><span class="info-val">{{ selected.material_grade || '-' }}</span></div>
              <div v-if="!selectedIsOffice" class="info-item"><span class="info-label">下料分类</span><span class="info-val">{{ cuttingModeText(selected) }}</span></div>
              <div v-if="!selectedIsOffice && cuttingMode(selected) === 'length'" class="info-item"><span class="info-label">标准定长</span><span class="info-val">{{ Number(selected.standard_stock_length_mm).toLocaleString('zh-CN') }} mm</span></div>
              <div v-if="!selectedIsOffice" class="info-item"><span class="info-label">关联SKU数</span><span class="info-val">{{ (selected.sku_relations || []).length }} 款</span></div>
              <div class="info-item"><span class="info-label">更新时间</span><span class="info-val">{{ formatDate(selected.updated_at) }}</span></div>
              <div class="info-item full-width"><span class="info-label">备注说明</span><span class="info-val">{{ selected.remark || selected.spec || '-' }}</span></div>
            </div>
          </section>

          <!-- 属性卡片 2：采购与库存属性 -->
          <section class="detail-card">
            <div class="card-title-bar">
              <span class="card-icon-badge"><i class="el-icon-box" /></span>
              <h3>采购与库存控制</h3>
            </div>
            <div class="info-grid">
              <div class="info-item"><span class="info-label">是否可采购</span><span class="info-val">{{ selected.is_purchase_item ? '是' : '否' }}</span></div>
              <div class="info-item"><span class="info-label">是否管理库存</span><span class="info-val">{{ selected.is_stock_item ? '是' : '否' }}</span></div>
              <div class="info-item"><span class="info-label">安全库存</span><span class="info-val">{{ Number(selected.safety_stock || 0).toFixed(unitPlaces(selected.unit)) }} {{ canonicalUnitSymbol(selected.unit) }}</span></div>
              <div class="info-item"><span class="info-label">默认供应商</span><span class="info-val">{{ (selected.default_supplier && selected.default_supplier.supplier_name) || '未设置' }}</span></div>
            </div>
          </section>

          <!-- 属性卡片 3：单件序列号追溯 -->
          <section class="detail-card">
            <div class="card-title-bar">
              <span class="card-icon-badge"><i class="el-icon-postcard" /></span>
              <h3>单件追溯规则</h3>
            </div>
            <div class="info-grid">
              <div class="info-item"><span class="info-label">追溯策略</span><span class="info-val">{{ serialTrackingText(selected) }}</span></div>
              <div class="info-item"><span class="info-label">编号前缀</span><span class="info-val font-mono">{{ selected.serial_number_prefix || selected.item_code || '-' }}</span></div>
              <div class="info-item full-width"><span class="info-label">入库要求</span><span class="info-val">{{ serialTrackingRequirement(selected) }}</span></div>
            </div>
          </section>

          <!-- 属性卡片 4：采购单位换算 -->
          <section class="detail-card">
            <div class="card-title-bar space-between">
              <div class="card-title-left">
                <span class="card-icon-badge"><i class="el-icon-refresh" /></span>
                <h3>采购单位换算</h3>
              </div>
              <el-button size="mini" type="primary" plain icon="el-icon-plus" @click="openConversionCreate">新增采购换算</el-button>
            </div>
            <el-table :data="conversions" border size="mini" empty-text="暂无采购单位换算，系统默认采用基本单位采购">
              <el-table-column label="采购单位" min-width="110">
                <template slot-scope="{ row }">{{ row.purchase_unit && unitLabel(row.purchase_unit) }}</template>
              </el-table-column>
              <el-table-column label="换算关系" min-width="180">
                <template slot-scope="{ row }">
                  <span class="font-mono">1 {{ row.purchase_unit && row.purchase_unit.symbol }} = {{ number(row.factor) }} {{ row.base_unit && row.base_unit.symbol }}</span>
                </template>
              </el-table-column>
              <el-table-column label="默认" width="60" align="center">
                <template slot-scope="{ row }">{{ row.is_default ? '是' : '否' }}</template>
              </el-table-column>
              <el-table-column label="实际折算" width="80" align="center">
                <template slot-scope="{ row }">{{ row.allow_actual_conversion ? '是' : '否' }}</template>
              </el-table-column>
              <el-table-column label="状态" width="70" align="center">
                <template slot-scope="{ row }">
                  <el-tag size="mini" :type="row.status === 'active' ? 'success' : 'info'" effect="plain">
                    {{ row.status === 'active' ? '启用' : '停用' }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column label="操作" width="110" align="center">
                <template slot-scope="{ row }">
                  <el-button v-if="row.status === 'active'" type="text" size="mini" @click="openConversionEdit(row)">编辑</el-button>
                  <el-button v-if="row.status === 'active'" type="text" size="mini" class="danger-link" @click="disableConversion(row)">停用</el-button>
                </template>
              </el-table-column>
            </el-table>
            <div class="mini-pager">
              <span>共 {{ conversionTotal }} 条换算</span>
              <el-pagination
                small
                layout="prev, pager, next"
                :current-page.sync="conversionQuery.page"
                :page-size="conversionQuery.per_page"
                :total="conversionTotal"
                @current-change="loadConversions"
              />
            </div>
            <p class="conversion-tip"><i class="el-icon-info" /> 采购换算仅对当前 Item 生效，不影响其他物料；库存台账始终以基本单位记账。</p>
          </section>

          <!-- 属性卡片 5：关联 SKU 档案 -->
          <section v-if="!selectedIsOffice" class="detail-card">
            <div class="card-title-bar">
              <span class="card-icon-badge"><i class="el-icon-connection" /></span>
              <h3>关联商品 SKU 列表</h3>
            </div>
            <el-table :data="selected.sku_relations || []" border size="mini" empty-text="当前物料尚未关联任何商品规格 SKU">
              <el-table-column label="SKU编码" min-width="140">
                <template slot-scope="{ row }">
                  <span class="font-mono">{{ row.sku && row.sku.sku_code }}</span>
                </template>
              </el-table-column>
              <el-table-column label="SKU名称" min-width="180">
                <template slot-scope="{ row }">{{ row.sku && row.sku.sku_name }}</template>
              </el-table-column>
              <el-table-column label="是否主物料" width="90" align="center">
                <template slot-scope="{ row }">
                  <el-tag size="mini" :type="row.is_primary ? 'success' : 'info'" effect="plain">
                    {{ row.is_primary ? '主物料' : '辅料' }}
                  </el-tag>
                </template>
              </el-table-column>
            </el-table>
          </section>
        </div>
      </div>

      <div slot="footer" class="dialog-footer">
        <el-button size="small" @click="detailDialogVisible = false">关闭</el-button>
        <el-button size="small" icon="el-icon-edit" @click="openEdit(selected)">编辑物料</el-button>
        <el-button v-if="!selectedIsOffice" size="small" type="primary" class="btn-theme-primary" @click="$router.push('/master/sku-item-relations')">
          去关联SKU
        </el-button>
      </div>
    </el-dialog>

    <!-- 采购换算编辑/新增弹窗 -->
    <el-dialog
      :visible.sync="conversionDialogVisible"
      :title="conversionForm.id ? '编辑采购单位换算' : '新增采购单位换算'"
      width="580px"
      append-to-body
      custom-class="conversion-modal"
      :close-on-click-modal="false"
    >
      <el-form ref="conversionForm" :model="conversionForm" :rules="conversionRules" label-position="top" size="small">
        <div class="readonly-unit-banner">
          <span>当前物料：<strong>{{ selected.item_name }}</strong>（{{ selected.item_code }}）</span>
          <span>库存基本单位：<strong>{{ unitLabel(selected.unit) }}</strong></span>
        </div>

        <el-form-item label="采购单位" prop="purchase_unit_id">
          <el-select v-model="conversionForm.purchase_unit_id" filterable class="full-width" placeholder="请选择采购业务所用单位">
            <el-option v-for="unit in enabledUnits" :key="unit.id" :label="`${unit.unit_code} ${unit.unit_name}`" :value="unit.id" />
          </el-select>
        </el-form-item>

        <el-form-item label="换算公式" prop="factor">
          <div class="formula-row">
            <el-input value="1" disabled class="formula-fixed" />
            <span class="formula-unit">{{ purchaseUnitSymbol }}</span>
            <b class="formula-equal">=</b>
            <el-input-number
              v-model="conversionForm.factor"
              :min="0.00000001"
              :precision="8"
              :controls="false"
              class="formula-number"
              @change="setConversionFactor"
              @blur="captureConversionFactor"
            />
            <span class="formula-unit">{{ canonicalUnitSymbol(selected.unit) }}</span>
          </div>
        </el-form-item>

        <div class="switch-row">
          <span>设为默认采购单位</span>
          <el-switch v-model="conversionForm.is_default" active-color="#008b4b" />
        </div>

        <div class="switch-row">
          <span>允许到货录入实际基本数量</span>
          <el-switch v-model="conversionForm.allow_actual_conversion" active-color="#008b4b" />
        </div>

        <el-form-item label="变更原因" prop="change_reason">
          <el-select v-model="conversionForm.change_reason" class="full-width">
            <el-option v-for="reason in conversionReasons" :key="reason" :label="reason" :value="reason" />
          </el-select>
        </el-form-item>

        <el-form-item label="备注说明">
          <el-input v-model="conversionForm.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="选填，记录换算依据" />
        </el-form-item>
      </el-form>

      <div slot="footer" class="dialog-footer">
        <el-button size="small" @click="conversionDialogVisible = false">取消</el-button>
        <el-button size="small" type="primary" :loading="saving" class="btn-theme-primary" @click="saveConversion">
          保存并生效
        </el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import cachedPageRoute from '../../../utils/cachedPageRoute'
import { routeMaterialScope, materialRecordScope, materialScopeLabel, materialListPath, materialTypesForScope } from '../../../utils/materialManagementScope.mjs'
import {
  deleteEntity,
  disableEntity,
  disableItemPurchaseConversion,
  enableEntity,
  getEntity,
  getItemCategoryTree,
  listEntity,
  listItemPurchaseConversions,
  saveItemPurchaseConversion
} from '../../../api/erp/master'

const emptyConversion = () => ({
  id: null,
  purchase_unit_id: null,
  factor: 1,
  is_default: false,
  allow_actual_conversion: false,
  effective_from: '',
  effective_to: '',
  change_reason: '新增采购换算',
  remark: ''
})

const scopeCountsCache = {
  all: 0,
  factory: 0,
  office: 0
}

export default {
  mixins: [cachedPageRoute],
  name: 'ItemList',
  components: {
    ItemCategoryManagerDialog: () => import('../../../components/master/ItemCategoryManagerDialog.vue')
  },
  data () {
    return {
      loading: false,
      loadVersion: 0,
      optionsVersion: 0,
      detailVersion: 0,
      listLoaded: false,
      saving: false,
      detailDialogVisible: false,
      categoryDialogVisible: false,
      categoryDialogScope: '',
      categoriesDirty: false,
      conversionDialogVisible: false,
      rows: [],
      total: 0,
      stats: {},
      selectedId: null,
      selected: {},
      conversions: [],
      conversionTotal: 0,
      conversionQuery: { page: 1, per_page: 5 },
      conversionForm: emptyConversion(),
      categoryTree: [],
      categories: [],
      units: [],
      suppliers: [],
      scopeCounts: { ...scopeCountsCache },
      scopeTabs: [
        { value: '', label: '全部物料', icon: 'el-icon-collection' },
        { value: 'factory', label: '工厂物料', icon: 'el-icon-office-building' },
        { value: 'office', label: '办公用品', icon: 'el-icon-document' }
      ],
      query: {
        management_scope: routeMaterialScope(this.$route),
        keyword: '',
        item_type: '',
        category_id: Number(this.$route.query.category_id) || '',
        unit_id: '',
        status: '',
        page: 1,
        per_page: 20
      },
      conversionReasons: [
        '新增采购换算',
        '包装规格调整',
        '供应方式变更',
        '主数据修正',
        '历史数据补全',
        '其他'
      ],
      conversionRules: {
        purchase_unit_id: [{ required: true, message: '请选择采购单位', trigger: 'change' }],
        factor: [{ required: true, message: '请输入大于0的换算因子', trigger: 'blur' }],
        change_reason: [{ required: true, message: '请选择变更原因', trigger: 'change' }]
      }
    }
  },
  computed: {
    canViewItems () { return this.$can('master.item.view') },
    canViewCategories () { return this.$can('item_category.view') },
    managementScope () { return this.query.management_scope || '' },
    activeScopeTab: {
      get () {
        return this.query.management_scope || 'all'
      },
      set (val) {
        const scope = val === 'all' ? '' : val
        this.selectScopeTab(scope)
      }
    },
    isOffice () { return this.managementScope === 'office' },
    scopeLabel () { return materialScopeLabel(this.managementScope) },
    listPath () { return materialListPath(this.managementScope) },
    itemTypes () { return materialTypesForScope(this.managementScope) },
    selectedIsOffice () { return materialRecordScope(this.selected) === 'office' },
    selectableCategories () {
      return this.categories.filter(row => row.is_leaf && row.status === 'enabled')
    },
    businessUnits () {
      return this.units.filter(row => !row.is_legacy)
    },
    enabledUnits () {
      return this.businessUnits.filter(row => row.status === 'enabled')
    },
    purchaseUnitSymbol () {
      const unit = this.units.find(row => Number(row.id) === Number(this.conversionForm.purchase_unit_id))
      return (unit && (unit.symbol || unit.unit_name)) || '采购单位'
    },
    enabledCount () {
      return Number(this.stats.enabled || 0)
    },
    disabledCount () {
      return Number(this.stats.disabled || 0)
    },
    cuttingCount () {
      return Number(this.stats.cutting || 0)
    },
    hasSearchFilters () {
      return Boolean(
        this.query.keyword ||
        this.query.item_type ||
        this.query.category_id ||
        this.query.unit_id ||
        this.query.status
      )
    }
  },
  watch: {
    'pageRoute.query.management_scope' () {
      this.query.management_scope = routeMaterialScope(this.pageRoute)
      this.changeScope()
    },
    'pageRoute.query.category_id' (id) {
      this.query.category_id = Number(id) || ''
      this.query.page = 1
      this.load()
    }
  },
  created () {
    this.load()
    this.loadOptions()
    if (this.canViewCategories && (!this.canViewItems || this.pageRoute.query.manage_categories === '1')) this.openCategories()
  },
  activated () {
    if (this.listLoaded) {
      this.load()
      this.loadOptions()
    }
  },
  methods: {
    async load () {
      if (!this.canViewItems) return
      const version = ++this.loadVersion
      this.loading = true
      try {
        const params = { ...this.query, include_stats: 1 }
        if (!params.management_scope) delete params.management_scope
        const { data } = await listEntity('items', params)
        if (version !== this.loadVersion) return
        this.rows = data.data || []
        this.total = Number(data.total || 0)
        this.stats = data.stats || {}
        if (this.stats.factory_total !== undefined && this.stats.factory_total !== null) {
          this.$set(this.scopeCounts, 'factory', Number(this.stats.factory_total || 0))
        }
        if (this.stats.office_total !== undefined && this.stats.office_total !== null) {
          this.$set(this.scopeCounts, 'office', Number(this.stats.office_total || 0))
        }
        if (this.stats.all_total !== undefined && this.stats.all_total !== null) {
          this.$set(this.scopeCounts, 'all', Number(this.stats.all_total || 0))
        }
        const currentScope = this.managementScope || 'all'
        if (!this.hasSearchFilters) {
          this.$set(this.scopeCounts, currentScope, this.total)
        }
        Object.assign(scopeCountsCache, this.scopeCounts)
        this.listLoaded = true
      } catch (e) {
        if (version === this.loadVersion) this.$message.error(e.userMessage || '物料列表加载失败')
      } finally {
        if (version === this.loadVersion) this.loading = false
      }
    },
    async loadScopeCounts () {
      if (!this.canViewItems) return
      try {
        const [factoryRes, officeRes] = await Promise.allSettled([
          listEntity('items', { management_scope: 'factory', per_page: 1, include_stats: 1 }),
          listEntity('items', { management_scope: 'office', per_page: 1, include_stats: 1 })
        ])
        if (factoryRes.status === 'fulfilled' && factoryRes.value && factoryRes.value.data) {
          const factoryCount = Number(factoryRes.value.data.total || 0)
          this.$set(this.scopeCounts, 'factory', factoryCount)
          scopeCountsCache.factory = factoryCount
        }
        if (officeRes.status === 'fulfilled' && officeRes.value && officeRes.value.data) {
          const officeCount = Number(officeRes.value.data.total || 0)
          this.$set(this.scopeCounts, 'office', officeCount)
          scopeCountsCache.office = officeCount
        }
        if (!this.scopeCounts.all) {
          const total = (this.scopeCounts.factory || 0) + (this.scopeCounts.office || 0)
          this.$set(this.scopeCounts, 'all', total)
          scopeCountsCache.all = total
        }
      } catch (_) {}
    },
    async loadOptions () {
      if (!this.canViewItems) return
      const version = ++this.optionsVersion
      const [categories, units, suppliers] = await Promise.all([
        this.canViewCategories ? getItemCategoryTree(this.scopeParams()) : Promise.resolve({ data: { data: [] } }),
        listEntity('units', { page: 1, per_page: 100, status: 'enabled' }),
        listEntity('suppliers', { page: 1, per_page: 100, status: 'enabled' })
      ])
      if (version !== this.optionsVersion) return
      this.categoryTree = categories.data.data || []
      this.categories = this.flatten(this.categoryTree)
      this.units = units.data.data || []
      this.suppliers = suppliers.data.data || []
    },
    flatten (tree) {
      const rows = []
      const visit = list => (list || []).forEach(row => {
        rows.push(row)
        visit(row.children)
      })
      visit(tree)
      return rows
    },
    search () {
      this.query.page = 1
      this.load()
    },
    scopeParams () {
      return this.managementScope ? { management_scope: this.managementScope } : {}
    },
    handleScopeTabClick (tab) {
      const scope = tab.name === 'all' ? '' : tab.name
      this.selectScopeTab(scope)
    },
    scopeCount (scope) {
      if (this.hasSearchFilters && (this.query.management_scope || 'all') === scope) {
        return this.total.toLocaleString()
      }
      const val = this.scopeCounts[scope]
      return (val !== null && val !== undefined ? Number(val) : 0).toLocaleString()
    },
    selectScopeTab (scope) {
      if ((this.query.management_scope || '') === (scope || '')) return
      this.query.management_scope = scope || ''
      this.changeScope()
    },
    changeScope () {
      this.detailVersion++
      this.query.category_id = ''
      this.query.item_type = ''
      this.categories = []
      this.categoryTree = []
      this.selected = {}
      this.selectedId = null
      this.detailDialogVisible = false
      this.loadOptions()
      this.search()
    },
    reset () {
      this.detailVersion++
      this.query = {
        management_scope: '',
        keyword: '',
        item_type: '',
        category_id: '',
        unit_id: '',
        status: '',
        page: 1,
        per_page: this.query.per_page
      }
      this.loadOptions()
      this.load()
    },
    sizeChange () {
      this.query.page = 1
      this.load()
    },
    async fetchItem (row) {
      const { data } = await getEntity('items', row.id, { management_scope: materialRecordScope(row) })
      return data
    },
    async openDetail (row) {
      if (!this.canViewItems) return
      const version = ++this.detailVersion
      this.selectedId = row.id
      this.selected = { ...row }
      this.conversions = []
      this.conversionTotal = 0
      this.detailDialogVisible = true
      try {
        const selected = await this.fetchItem(row)
        // 范围或当前记录变化后，旧请求不能替换新弹窗的物料和采购换算。
        if (version !== this.detailVersion || this.selectedId !== row.id || !this.detailDialogVisible) return
        this.selected = selected
        this.conversionQuery.page = 1
        await this.loadConversions()
      } catch (e) {
        if (version === this.detailVersion && this.selectedId === row.id && this.detailDialogVisible) {
          this.$message.error(e.userMessage || '物料详情加载失败')
        }
      }
    },
    openEdit (row) {
      if (!this.canViewItems) return
      this.detailDialogVisible = false
      this.$router.push(`${this.listPath}/${row.id}/edit`)
    },
    openCreate () {
      if (!this.canViewItems) return
      this.$router.push({ path: `${this.listPath}/new`, query: this.scopeParams() })
    },
    openCategories () {
      if (!this.canViewCategories) return
      this.categoryDialogScope = this.managementScope
      this.categoryDialogVisible = true
    },
    onCategoriesChanged () {
      this.categoriesDirty = true
    },
    async onCategoriesClosed () {
      if (!this.categoriesDirty || !this.canViewItems) return
      this.categoriesDirty = false
      await this.loadOptions()
      if (this.query.category_id && !this.categories.some(row => Number(row.id) === Number(this.query.category_id))) {
        this.query.category_id = ''
      }
      await this.load()
    },
    showCategoryItems (row) {
      if (!this.canViewItems) return
      this.query.management_scope = materialRecordScope(row)
      this.query.category_id = row.id
      this.query.item_type = ''
      this.query.page = 1
      this.loadOptions()
      this.load()
    },
    openImport () {
      if (!this.canViewItems) return
      this.$router.push({ path: '/master/imports', query: { type: 'Item', ...this.scopeParams() } })
    },
    async loadConversions () {
      if (!this.canViewItems) return
      if (!this.selected.id) return
      const itemId = this.selected.id
      const version = this.detailVersion
      const { data } = await listItemPurchaseConversions(itemId, this.conversionQuery)
      if (version !== this.detailVersion || this.selected.id !== itemId || !this.detailDialogVisible) return
      this.conversions = data.data || []
      this.conversionTotal = Number(data.total || 0)
    },
    openConversionCreate () {
      this.conversionForm = { ...emptyConversion(), is_default: this.conversionTotal === 0 }
      this.conversionDialogVisible = true
    },
    openConversionEdit (row) {
      this.conversionForm = {
        ...emptyConversion(),
        ...row,
        purchase_unit_id: Number(row.purchase_unit_id),
        factor: Number(row.factor)
      }
      this.conversionDialogVisible = true
    },
    setConversionFactor (value) {
      this.$set(this.conversionForm, 'factor', Number(value || 0))
    },
    captureConversionFactor (event) {
      const value = Number(event && event.target && event.target.value)
      if (Number.isFinite(value)) this.setConversionFactor(value)
    },
    saveConversion () {
      this.$refs.conversionForm.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          await saveItemPurchaseConversion(this.selected.id, this.conversionForm)
          this.conversionDialogVisible = false
          await this.loadConversions()
          this.$message.success('采购换算已保存并生效')
        } catch (e) {
          this.$message.error(e.userMessage || '采购换算保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    async disableConversion (row) {
      try {
        const { value } = await this.$prompt('请输入停用原因', '停用采购换算', {
          inputValidator: value => !!String(value || '').trim() || '停用原因不能为空'
        })
        await disableItemPurchaseConversion(this.selected.id, row.id, { change_reason: value })
        this.$message.success('采购换算已停用')
        await this.loadConversions()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '停用失败')
      }
    },
    async toggleStatus (row) {
      if (!this.canViewItems) return
      const enabling = row.status !== 'enabled'
      try {
        await this.$confirm(enabling ? '确认启用该物料？' : '确认停用该物料？', enabling ? '启用确认' : '停用确认', {
          type: enabling ? 'success' : 'warning'
        })
        await (enabling ? enableEntity : disableEntity)('items', row.id, { management_scope: materialRecordScope(row) })
        this.$message.success(enabling ? '物料已启用' : '物料已停用')
        await this.load()
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '操作失败')
      }
    },
    async deleteItem (row) {
      if (!this.canViewItems) return
      try {
        await this.$confirm(
          `确认删除物料 ${row.item_code} / ${row.item_name}？仅从未被 SKU、采购、库存、BOM 或供应商关系引用的停用物料可以删除。`,
          '删除物料',
          { type: 'warning', confirmButtonText: '确认删除' }
        )
        await deleteEntity('items', row.id, { management_scope: materialRecordScope(row) })
        this.$message.success('物料已删除')
        await this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '物料删除失败')
      }
    },
    rowClass ({ row }) {
      return row.id === this.selectedId ? 'selected-row' : ''
    },
    categoryPath (id) {
      return (this.categories.find(row => Number(row.id) === Number(id)) || {}).full_path || ''
    },
    recordScope (row) { return materialRecordScope(row) },
    recordScopeLabel (row) { return materialScopeLabel(materialRecordScope(row)) },
    itemTypeText (value) {
      return (materialTypesForScope('').find(type => type.value === value) || {}).label || value || '-'
    },
    cuttingMode (row) {
      return row && (row.cutting_mode || (row.is_length_cut_material ? 'length' : null))
    },
    isCuttingMaterial (row) {
      const mode = this.cuttingMode(row)
      return mode === 'sheet' || mode === 'length'
    },
    cuttingModeText (row) {
      return ({ sheet: '板材', length: '定长材料' })[this.cuttingMode(row)] || '非下料原料'
    },
    serialTrackingMode (row) {
      return row && (row.serial_tracking_mode || (row.is_serial_managed ? 'required' : 'none'))
    },
    serialTrackingText (row) {
      return ({ none: '仅批次追溯', optional: '按需逐件编号', required: '必须逐件编号' })[this.serialTrackingMode(row)] || '-'
    },
    serialTrackingRequirement (row) {
      return this.serialTrackingMode(row) === 'required'
        ? '合格入库数量必须与唯一SN数量一致'
        : this.serialTrackingMode(row) === 'optional'
          ? '本次启用SN时必须逐件完整登记'
          : '不录SN，按Item/仓库/库位/批次追溯'
    },
    statusText (value) {
      return value === 'enabled' ? '启用' : '停用'
    },
    legacyStatusText (row) {
      if (!row) return '非旧数据'
      if (row.legacy_id) return '已映射'
      if (row.data_source === 'legacy' || row.legacy_system || row.legacy_table || row.legacy_code) return '待治理'
      return '非旧数据'
    },
    canonicalUnit (unit) {
      return unit && (unit.standard_unit || unit.standardUnit || unit)
    },
    canonicalUnitSymbol (unit) {
      const current = this.canonicalUnit(unit)
      return current ? `${current.unit_code || ''} ${current.unit_name || ''}`.trim() : '-'
    },
    unitLabel (unit) {
      const current = this.canonicalUnit(unit)
      return current ? `${current.unit_code || ''} ${current.unit_name || ''} (${current.symbol || current.unit_name || ''})`.trim() : '-'
    },
    unitPlaces (unit) {
      const current = this.canonicalUnit(unit)
      return Number(current && current.decimal_places || 0)
    },
    number (value) {
      return Number(value || 0).toFixed(6).replace(/0+$/, '').replace(/\.$/, '')
    },
    formatDate (value) {
      return value ? String(value).replace('T', ' ').slice(0, 16) : '-'
    }
  }
}
</script>

<style scoped>
.item-page-container {
  padding: 16px 20px;
  background: #f5f7fa;
  min-height: calc(100vh - 84px);
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
  min-width: 0;
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

/* 模块选项卡容器 (完全对齐基础档案 archive-tabs 视觉与交互规范) */
.tabs-card {
  min-width: 0;
  margin-bottom: 14px;
}

.archive-tabs {
  min-width: 0;
}

.archive-tabs ::v-deep .el-tabs__header {
  margin: 0;
  background: #ffffff;
  padding: 6px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}

.archive-tabs ::v-deep .el-tabs__nav-wrap::after {
  display: none;
}

.archive-tabs ::v-deep .el-tabs__item {
  height: 40px;
  line-height: 40px;
  padding: 0 18px !important;
  font-size: 14px;
  font-weight: 500;
  color: #475569;
  border-radius: 6px;
  transition: color 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.archive-tabs ::v-deep .el-tabs__item:hover {
  color: #008b4b;
  background: #f0fdf4;
}

.archive-tabs ::v-deep .el-tabs__item.is-active {
  color: #008b4b;
  font-weight: 600;
}

.archive-tabs ::v-deep .el-tabs__active-bar {
  background: #008b4b;
  height: 3px;
  border-radius: 2px;
  transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1), width 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}

.archive-tabs ::v-deep .el-tabs__content {
  display: none;
}

.custom-tab-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  user-select: none;
}

.custom-tab-item i {
  font-size: 15px;
}

.tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 22px;
  height: 18px;
  line-height: 18px;
  padding: 0 5px;
  box-sizing: border-box;
  background: #f1f5f9;
  border-radius: 9px;
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
  margin-left: 2px;
  transition: background-color 0.2s, color 0.2s;
}

.archive-tabs ::v-deep .el-tabs__item.is-active .tab-badge {
  background: #dcfce7;
  color: #15803d;
}

.code-mono {
  font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, Courier, monospace;
  font-variant-numeric: tabular-nums;
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
.metric-cutting .metric-icon-box { background: #f0fdfa; color: #0f766e; }

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
  min-width: 0;
}

.filter-input-search {
  width: 200px;
}

.filter-select-sm {
  width: 110px;
}

.filter-select-md {
  width: 140px;
}

.filter-select-xs {
  width: 100px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

/* 表格主体 */
.main-table-wrap {
  width: 100%;
  overflow-x: auto;
}

.custom-item-table {
  width: 100%;
}

.font-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.3px;
}

.item-code-link {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.4px;
  font-variant-numeric: tabular-nums;
  color: #00763f;
  cursor: pointer;
  display: block;
  max-width: 100%;
  box-sizing: border-box;
  overflow: hidden;
  text-overflow: ellipsis;
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

.item-code-link:hover {
  background: #dcfce7;
  border-color: #86efac;
  color: #00562e;
  box-shadow: 0 1px 4px rgba(0, 118, 63, 0.15);
}

.item-code-link i {
  font-size: 13px;
  color: #008b4b;
  flex-shrink: 0;
}

.item-name-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.item-name-text {
  font-weight: 600;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.item-spec-sub {
  font-size: 11px;
  color: #64748b;
}

.spec-text {
  color: #475569;
  font-size: 12px;
}

.type-badge {
  font-size: 12px;
  color: #334155;
  background: #f1f5f9;
  padding: 2px 6px;
  border-radius: 4px;
}

.category-name {
  color: #475569;
  font-size: 12px;
  display: block;
  max-width: 100%;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  align-items: center;
  gap: 4px;
}

.unit-badge {
  background: #f8fafc;
  color: #334155;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 11px;
  border: 1px solid #e2e8f0;
  display: block;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.sku-count-chip {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #166534;
  padding: 2px 6px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 600;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.supplier-text {
  font-size: 12px;
  color: #475569;
  display: inline-flex;
  align-items: center;
  gap: 4px;
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

.time-text {
  font-size: 12px;
  color: #64748b;
}

.text-muted {
  color: #94a3b8;
}

.danger-link {
  color: #ef4444 !important;
}

.success-link {
  color: #008b4b !important;
}

.action-link-theme {
  color: #008b4b !important;
}

.action-link-theme:hover {
  color: #00763f !important;
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

/* 主题按钮与交互 */
::v-deep .btn-theme-primary,
.btn-theme-primary,
::v-deep .btn-theme-query,
.btn-theme-query {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  transition: all 0.2s ease;
}

::v-deep .btn-theme-primary:hover,
::v-deep .btn-theme-primary:focus,
::v-deep .btn-theme-query:hover,
::v-deep .btn-theme-query:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
  color: #ffffff !important;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.25);
}

::v-deep .el-button--text:not(.danger-link):not(.success-link) {
  color: #008b4b;
}

::v-deep .el-button--text:not(.danger-link):not(.success-link):hover {
  color: #00763f;
}

/* 分页激活态适配主题绿 */
::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
}

::v-deep .el-pagination.is-background .el-pager li:not(.disabled):hover {
  color: #008b4b !important;
}

/* 焦点高亮 */
::v-deep .el-input.is-active .el-input__inner,
::v-deep .el-input__inner:focus,
::v-deep .el-select .el-input.is-focus .el-input__inner {
  border-color: #008b4b !important;
  box-shadow: 0 0 0 2px rgba(0, 139, 75, 0.12);
}

/* 详情弹窗样式 */
::v-deep .item-detail-modal .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #f1f5f9;
}

::v-deep .item-detail-modal .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

::v-deep .item-detail-modal .el-dialog__body {
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
  flex-wrap: wrap;
}

.banner-title-line h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  min-width: 0;
  overflow-wrap: anywhere;
  color: #0f172a;
}

.banner-sub-meta {
  display: flex;
  align-items: center;
  gap: 16px;
  font-size: 12px;
  color: #64748b;
  flex-wrap: wrap;
}

.banner-sub-meta strong {
  color: #1e293b;
}

.detail-cards-stack {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.detail-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
}

.card-title-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.card-title-bar.space-between {
  justify-content: space-between;
}

.card-title-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-icon-badge {
  width: 24px;
  height: 24px;
  border-radius: 4px;
  background: #eaf7ef;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 13px;
}

.card-title-bar h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}

.info-grid {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 10px 18px;
}

.info-item {
  display: flex;
  align-items: baseline;
  font-size: 13px;
}

.info-item.full-width {
  grid-column: 1 / -1;
}

.info-label {
  width: 86px;
  color: #64748b;
  flex-shrink: 0;
}

.info-val {
  color: #0f172a;
  word-break: break-all;
}

.mini-pager {
  margin-top: 8px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  color: #64748b;
}

.conversion-tip {
  margin: 8px 0 0;
  font-size: 11px;
  color: #64748b;
  line-height: 1.4;
}

.conversion-tip i {
  color: #008b4b;
}

/* 换算弹窗 */
.readonly-unit-banner {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
  font-size: 12px;
  color: #475569;
  display: flex;
  flex-direction: column;
  gap: 4px;
  margin-bottom: 14px;
}

.formula-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.formula-fixed {
  width: 50px;
}

.formula-number {
  flex: 1;
}

.formula-unit {
  font-size: 12px;
  color: #334155;
  white-space: nowrap;
}

.formula-equal {
  font-size: 14px;
  color: #64748b;
}

.switch-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  height: 38px;
  font-size: 13px;
  color: #334155;
}

.full-width {
  width: 100%;
}

/* 响应式调整 (遵照用户指令：自适应所有不同型号与分辨率屏幕) */
@media (max-width: 1024px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .filter-input-search {
    width: 200px;
  }
}

@media (max-width: 768px) {
  .table-pagination-footer { min-width: 0; }
  .table-pagination-footer ::v-deep .el-pagination {
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 6px;
    min-width: 0;
    max-width: 100%;
    padding: 0;
    white-space: normal;
  }
  .table-pagination-footer ::v-deep .el-pagination__sizes,
  .table-pagination-footer ::v-deep .el-pagination__jump { margin: 0; }
  .item-page-container {
    padding: 10px 12px;
  }
  .archive-tabs ::v-deep .el-tabs__item {
    padding: 0 12px !important;
    font-size: 13px;
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
  .filter-fields {
    width: 100%;
  }
  .filter-input-search, .filter-select-sm, .filter-select-md, .filter-select-xs {
    width: 100%;
  }
  .filter-actions {
    width: 100%;
    justify-content: flex-end;
  }
  .info-grid {
    grid-template-columns: 1fr;
  }
}
</style>
