<template>
  <section class="relation-page">
    <!-- 页面全局头部：图标、标题、统计标签与主要操作 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-connection" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">SKU–Item默认关系</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">共 {{ total }} 项关系</el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="load">刷新</el-button>
        <el-button
          v-if="$can('sku_item_relation.audit')"
          size="small"
          icon="el-icon-circle-check"
          class="btn-integrity"
          @click="openIntegrityModal"
        >
          关系完整性检查
        </el-button>
      </div>
    </header>

    <!-- 全局统一业务提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>维护实物 SKU 唯一有效默认 Item（物料）映射绑定，打通采购、库存结存、BOM 分解及工单履约主数据链路。</span>
    </div>

    <!-- 概览指标卡片网格 -->
    <section class="metric-overview-grid">
      <div
        class="metric-card metric-physical"
        :class="{ active: filters.line_type === 'physical' && !filters.relation_status }"
        title="点击筛选全部实物 SKU"
        @click="filterCard('physical', '')"
      >
        <div class="metric-icon-box"><i class="el-icon-goods" /></div>
        <div class="metric-info">
          <span class="metric-label">实物 SKU</span>
          <strong class="metric-val code-mono">{{ summary.physical || 0 }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-configured"
        :class="{ active: filters.relation_status === 'normal' }"
        title="点击筛选正常已绑定默认 Item 的 SKU"
        @click="filterCard('physical', 'normal')"
      >
        <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
        <div class="metric-info">
          <span class="metric-label">已设置默认 Item</span>
          <strong class="metric-val code-mono text-success">{{ summary.configured || 0 }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-missing"
        :class="{ active: filters.relation_status === 'missing' }"
        title="点击筛选缺少默认 Item 的实物 SKU"
        @click="filterCard('physical', 'missing')"
      >
        <div class="metric-icon-box"><i class="el-icon-warning-outline" /></div>
        <div class="metric-info">
          <span class="metric-label">缺少默认 Item</span>
          <strong class="metric-val code-mono text-warning">{{ summary.missing || 0 }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-abnormal"
        :class="{ active: filters.relation_status === 'abnormal' }"
        title="点击筛选关系异常的 SKU"
        @click="filterCard('physical', 'abnormal')"
      >
        <div class="metric-icon-box"><i class="el-icon-circle-close" /></div>
        <div class="metric-info">
          <span class="metric-label">关系异常</span>
          <strong class="metric-val code-mono text-danger">{{ summary.abnormal || 0 }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-not-required"
        :class="{ active: filters.relation_status === 'not_required' }"
        title="点击筛选无需设置 Item 的服务与虚拟 SKU"
        @click="filterCard('', 'not_required')"
      >
        <div class="metric-icon-box"><i class="el-icon-remove-outline" /></div>
        <div class="metric-info">
          <span class="metric-label">无需 Item</span>
          <strong class="metric-val code-mono text-muted">{{ summary.not_required || 0 }}</strong>
        </div>
      </div>
    </section>

    <!-- 筛选过滤栏卡片 -->
    <section class="filter-card">
      <div class="filter-inputs">
        <div class="filter-item">
          <span class="filter-label">所属商品</span>
          <el-select v-model="filters.product_id" size="small" clearable filterable placeholder="请选择商品" class="filter-select-lg">
            <el-option v-for="p in products" :key="p.id" :label="`${p.product_code || '-'}｜${p.product_name}`" :value="p.id" />
          </el-select>
        </div>
        <div class="filter-item">
          <span class="filter-label">SKU检索</span>
          <el-input v-model="filters.sku_keyword" size="small" placeholder="编码 / 名称" clearable prefix-icon="el-icon-search" class="filter-input" @keyup.enter.native="search" />
        </div>
        <div class="filter-item">
          <span class="filter-label">Item检索</span>
          <el-input v-model="filters.item_keyword" size="small" placeholder="编码 / 名称" clearable prefix-icon="el-icon-search" class="filter-input" @keyup.enter.native="search" />
        </div>
        <div class="filter-item">
          <span class="filter-label">行类型</span>
          <el-select v-model="filters.line_type" size="small" clearable placeholder="全部" class="filter-select-sm">
            <el-option label="实物" value="physical" />
            <el-option label="服务" value="service" />
            <el-option label="无需发货" value="no_delivery" />
          </el-select>
        </div>
        <div class="filter-item">
          <span class="filter-label">SKU状态</span>
          <el-select v-model="filters.sku_status" size="small" clearable placeholder="全部" class="filter-select-xs">
            <el-option label="启用" value="enabled" />
            <el-option label="停用" value="disabled" />
          </el-select>
        </div>
        <div class="filter-item">
          <span class="filter-label">Item状态</span>
          <el-select v-model="filters.item_status" size="small" clearable placeholder="全部" class="filter-select-xs">
            <el-option label="启用" value="enabled" />
            <el-option label="停用" value="disabled" />
          </el-select>
        </div>
        <div class="filter-item">
          <span class="filter-label">关系状态</span>
          <el-select v-model="filters.relation_status" size="small" clearable placeholder="全部" class="filter-select-sm">
            <el-option label="正常" value="normal" />
            <el-option label="缺失" value="missing" />
            <el-option label="异常" value="abnormal" />
            <el-option label="无需Item" value="not_required" />
          </el-select>
        </div>
      </div>
      <div class="filter-actions">
        <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="search">查询</el-button>
        <el-button size="small" icon="el-icon-refresh-right" class="btn-theme-reset" @click="reset">重置</el-button>
      </div>
    </section>

    <!-- 主表格容器卡片（全宽响应式） -->
    <section class="table-card">
      <div class="table-wrap">
        <el-table
          v-loading="loading"
          :data="rows"
          size="small"
          border
          highlight-current-row
          class="relation-data-table"
        >
          <el-table-column prop="sku.sku_code" label="SKU编码" min-width="120" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="sku-code-chip" @click.stop="$router.push(`/master/skus/${row.sku.id}`)">
                <i class="el-icon-box" />
                <span class="code-mono">{{ row.sku && row.sku.sku_code }}</span>
              </span>
            </template>
          </el-table-column>
          <el-table-column prop="sku.sku_name" label="SKU名称" min-width="140" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="sku-name-text">{{ row.sku && row.sku.sku_name }}</span>
            </template>
          </el-table-column>
          <el-table-column label="所属Product" min-width="150" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="product-text">{{ productText(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="订单行类型" width="95" align="center">
            <template slot-scope="{ row }">
              <span :class="['type-badge', `type-${row.line_type}`]">{{ lineType(row.line_type) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="默认Item编码" min-width="120" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span v-if="row.default_item && row.default_item.item_code" class="item-code-chip" @click.stop="$router.push(defaultItemPath(row.default_item))">
                <i class="el-icon-coin" />
                <span class="code-mono">{{ row.default_item.item_code }}</span>
              </span>
              <span v-else class="text-placeholder">—</span>
            </template>
          </el-table-column>
          <el-table-column label="默认Item名称" min-width="140" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="item-name-text">{{ row.default_item && row.default_item.item_name || '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="Item状态" width="85" align="center">
            <template slot-scope="{ row }">
              <span v-if="row.default_item" :class="['status-badge', row.default_item.status]">
                <i :class="row.default_item.status === 'enabled' ? 'el-icon-circle-check' : 'el-icon-circle-close'" />
                {{ row.default_item.status === 'enabled' ? '启用' : '停用' }}
              </span>
              <span v-else class="text-placeholder">—</span>
            </template>
          </el-table-column>
          <el-table-column label="关系状态" width="95" align="center">
            <template slot-scope="{ row }">
              <span :class="['relation-badge', relationStatusClass(row.audit && row.audit.check_status)]">
                {{ relationLabel(row.audit && row.audit.check_status) }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="生效时间" width="135" align="center">
            <template slot-scope="{ row }">
              <span class="time-text code-mono">{{ date(row.default_relation && row.default_relation.effective_at) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="更新时间" width="135" align="center">
            <template slot-scope="{ row }">
              <span class="time-text code-mono">{{ date(row.default_relation && row.default_relation.updated_at) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="操作" width="170" fixed="right" align="center">
            <template slot-scope="{ row }">
              <div class="row-actions">
                <el-button
                  v-if="canSet(row)"
                  type="text"
                  size="small"
                  class="btn-action-primary"
                  @click.stop="openSetItemModal(row)"
                >
                  {{ row.default_item ? '更换默认Item' : '设置默认Item' }}
                </el-button>
                <el-button type="text" size="small" class="btn-action-view" @click.stop="openHistoryModal(row)">
                  查看历史
                </el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
      </div>
      <!-- 底部分页 -->
      <div class="pager-row">
        <span class="total-text">共 {{ total }} 条关系记录</span>
        <el-pagination
          background
          layout="sizes, prev, pager, next, jumper"
          :current-page="page"
          :page-size="perPage"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          @current-change="go"
          @size-change="resize"
        />
      </div>
    </section>

    <!-- 弹窗 1：更换 / 设置默认Item弹窗 -->
    <el-dialog
      :title="setModal.isChange ? '更换默认Item' : '设置默认Item'"
      :visible.sync="setModal.visible"
      width="780px"
      custom-class="relation-modal"
      :close-on-click-modal="false"
      destroy-on-close
    >
      <div v-loading="setModal.loading" class="modal-body-wrap">
        <div class="modal-tip-notice">
          <i class="el-icon-info" />
          <span>实物 SKU 必须设置唯一、启用的默认 Item；服务和无需发货 SKU 无需设置。变更将记录审计历史。</span>
        </div>

        <!-- SKU 基础信息只读网格 -->
        <section v-if="setModal.sku" class="sku-summary-card">
          <div class="summary-header">
            <i class="el-icon-goods" />
            <span class="summary-title">SKU 基础信息</span>
          </div>
          <div class="summary-grid">
            <div class="summary-item">
              <span class="summary-label">所属商品</span>
              <span class="summary-val">{{ setModalSkuProduct }}</span>
            </div>
            <div class="summary-item">
              <span class="summary-label">SKU 编码</span>
              <span class="summary-val code-mono">{{ setModal.sku.sku_code }}</span>
            </div>
            <div class="summary-item">
              <span class="summary-label">SKU 名称</span>
              <span class="summary-val">{{ setModal.sku.sku_name }}</span>
            </div>
            <div class="summary-item">
              <span class="summary-label">规格型号</span>
              <span class="summary-val">{{ setModal.sku.spec_text || '—' }}</span>
            </div>
            <div class="summary-item">
              <span class="summary-label">销售单位</span>
              <span class="summary-val">{{ setModal.sku.sales_unit && setModal.sku.sales_unit.unit_name || '—' }}</span>
            </div>
            <div class="summary-item">
              <span class="summary-label">订单行类型</span>
              <span class="summary-val type-tag">实物</span>
            </div>
          </div>
        </section>

        <!-- 新默认 Item 搜索选择栏（居顶独立，左右卡片高度完全对称） -->
        <section class="item-search-section">
          <div class="search-label-row">
            <span class="search-label">
              <i class="el-icon-search" />
              选择新默认 Item <b class="text-danger">*</b>
            </span>
            <span class="search-tip">支持按 Item 编码、名称、规格型号检索（仅限启用状态 Item）</span>
          </div>
          <el-select
            v-model="setModal.form.item_id"
            v-paged-scroll="loadMoreModalItems"
            filterable
            remote
            clearable
            :remote-method="searchModalItems"
            :loading="setModal.itemPage.loading"
            placeholder="输入 Item 编码 / 名称 / 规格型号快速搜索并选定"
            class="full-width-select"
            size="small"
            @change="onSelectModalItem"
          >
            <el-option
              v-for="item in setModal.items"
              :key="item.id"
              :value="item.id"
              :label="`${item.item_code} ｜ ${item.item_name} ${item.spec ? '（' + (item.spec) + '）' : ''}`"
            />
          </el-select>
        </section>

        <!-- 默认 Item 左右对称对比配置网格 -->
        <section class="item-compare-card">
          <!-- 左侧：当前默认 Item -->
          <div class="compare-col current-item-col">
            <div class="col-head">
              <span class="col-title"><i class="el-icon-time" /> 当前默认 Item（旧 Item）</span>
              <span v-if="setModal.current" class="pill-tag pill-current">当前绑定</span>
              <span v-else class="pill-tag pill-none">未配置</span>
            </div>
            <div v-if="setModal.current" class="item-details-box">
              <div class="item-row"><span class="lbl">Item编码</span><b class="val code-mono">{{ setModal.current.item_code }}</b></div>
              <div class="item-row"><span class="lbl">Item名称</span><span class="val">{{ setModal.current.item_name }}</span></div>
              <div class="item-row"><span class="lbl">规格型号</span><span class="val">{{ setModal.current.spec || '—' }}</span></div>
              <div class="item-row"><span class="lbl">Item类型</span><span class="val">{{ itemType(setModal.current.item_type) }}</span></div>
              <div class="item-row"><span class="lbl">库存单位</span><span class="val">{{ setModal.current.unit && setModal.current.unit.unit_name || '—' }}</span></div>
              <div class="item-row"><span class="lbl">换算因子</span><span class="val code-mono">{{ formatNumber(setModal.currentRelation && setModal.currentRelation.qty || 1) }}</span></div>
              <div class="item-row"><span class="lbl">启用状态</span><span class="val badge-active">启用</span></div>
            </div>
            <div v-else class="empty-item-box">
              <i class="el-icon-warning-outline" />
              <span>当前尚未配置默认 Item</span>
            </div>
          </div>

          <div class="compare-arrow">
            <i class="el-icon-right" />
          </div>

          <!-- 右侧：已选择的新默认 Item -->
          <div class="compare-col new-item-col">
            <div class="col-head">
              <span class="col-title"><i class="el-icon-circle-check" /> 变更后新默认 Item（新 Item）</span>
              <span v-if="setModal.chosen" class="pill-tag pill-chosen">已选定</span>
              <span v-else class="pill-tag pill-none">待选定</span>
            </div>
            <div v-if="setModal.chosen" class="item-details-box chosen-box">
              <div class="item-row"><span class="lbl">Item编码</span><b class="val code-mono text-success">{{ setModal.chosen.item_code }}</b></div>
              <div class="item-row"><span class="lbl">Item名称</span><span class="val">{{ setModal.chosen.item_name }}</span></div>
              <div class="item-row"><span class="lbl">规格型号</span><span class="val">{{ setModal.chosen.spec || '—' }}</span></div>
              <div class="item-row"><span class="lbl">Item类型</span><span class="val">{{ itemType(setModal.chosen.item_type) }}</span></div>
              <div class="item-row"><span class="lbl">库存单位</span><span class="val">{{ setModal.chosen.unit && setModal.chosen.unit.unit_name || '—' }}</span></div>
              <div class="item-row"><span class="lbl">换算因子</span><span class="val code-mono text-success">{{ formatNumber(setModal.form.factor) }}</span></div>
              <div class="item-row"><span class="lbl">启用状态</span><span class="val badge-active">启用</span></div>
            </div>
            <div v-else class="empty-item-box awaiting">
              <i class="el-icon-search" />
              <span>请在上方搜索并选定新 Item</span>
              <small class="empty-sub">选定后右侧将对称展示新 Item 完整属性</small>
            </div>
          </div>
        </section>

        <!-- 履约换算配置与实际换算用例 -->
        <section class="conversion-section">
          <div class="section-title">
            <i class="el-icon-sort" />
            <span>履约单位换算与实际换算用例</span>
          </div>
          <div class="conversion-grid">
            <div class="form-field">
              <label class="field-label">销售单位（只读）</label>
              <el-input :value="setModalSalesUnitText" disabled size="small" />
            </div>
            <div class="form-field">
              <label class="field-label">Item库存基本单位（只读）</label>
              <el-input :value="setModalItemUnitText" disabled size="small" />
            </div>
            <div class="form-field">
              <label class="field-label">履约数量/换算因子 <b class="text-danger">*</b></label>
              <el-input-number
                v-model="setModal.form.factor"
                :min="0.00000001"
                :precision="8"
                :controls="false"
                size="small"
                class="factor-input"
              />
            </div>
          </div>

          <!-- 换算公式与实际业务用例展示卡片 -->
          <div class="conversion-usecase-card">
            <div class="formula-banner">
              <span class="formula-tag">换算基准公式</span>
              <span class="formula-text">
                销售 <b>1</b> {{ setModalSalesUnitText }} = 履约消耗 <b>{{ formatNumber(setModal.form.factor) }}</b> {{ setModalItemUnitText }}
              </span>
            </div>

            <div class="usecase-detail-wrap">
              <div class="usecase-head">
                <i class="el-icon-s-opportunity" />
                <strong>实际业务换算用例与场景测算：</strong>
              </div>
              <div class="usecase-grid">
                <div class="usecase-item">
                  <div class="usecase-pill">用例 1：单件零售下单</div>
                  <div class="usecase-desc">
                    客户在销售订单中购买 <b>1</b> {{ setModalSalesUnitText }}，生产工单投料与仓库领料出库将自动计算并扣减
                    <span class="highlight-factor">{{ formatNumber(setModal.form.factor) }}</span>
                    <b>{{ setModalItemUnitText }}</b> 物料库存。
                  </div>
                </div>

                <div class="usecase-item">
                  <div class="usecase-pill">用例 2：小批量订单（10 {{ setModalSalesUnitText }}）</div>
                  <div class="usecase-desc">
                    客户在销售订单中订购 <b>10</b> {{ setModalSalesUnitText }}，MRP 需求分析与工单履约将自动计算需求量为
                    <span class="highlight-factor">{{ formatNumber(Number(setModal.form.factor || 0) * 10) }}</span>
                    <b>{{ setModalItemUnitText }}</b>。
                  </div>
                </div>

                <div class="usecase-item">
                  <div class="usecase-pill">用例 3：大批量采购（100 {{ setModalSalesUnitText }}）</div>
                  <div class="usecase-desc">
                    客户在销售订单中批量订购 <b>100</b> {{ setModalSalesUnitText }}，系统履约出库将精准核算扣减
                    <span class="highlight-factor">{{ formatNumber(Number(setModal.form.factor || 0) * 100) }}</span>
                    <b>{{ setModalItemUnitText }}</b> 基础库存。
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- 变更原因与备注 -->
        <section class="reason-section">
          <div class="reason-row">
            <div class="form-field field-reason">
              <label class="field-label">变更原因 <b class="text-danger">*</b></label>
              <el-select v-model="setModal.form.change_reason" placeholder="请选择变更原因" size="small" class="full-width-select">
                <el-option v-for="r in setModal.reasons" :key="r" :label="r" :value="r" />
              </el-select>
            </div>
            <div class="form-field field-remark">
              <label class="field-label">备注说明 <b v-if="setModal.form.change_reason === '其他'" class="text-danger">*</b></label>
              <el-input
                v-model="setModal.form.remark"
                type="textarea"
                :rows="2"
                maxlength="200"
                show-word-limit
                size="small"
                :placeholder="setModal.form.change_reason === '其他' ? '选择“其他”时必须说明具体原因' : '请输入变更备注（选填）'"
              />
            </div>
          </div>
        </section>
      </div>

      <div slot="footer" class="modal-footer">
        <el-button size="small" @click="setModal.visible = false">取消</el-button>
        <el-button
          size="small"
          type="success"
          class="btn-theme-create"
          :loading="setModal.saving"
          @click="saveSetItem"
        >
          保存并立即生效
        </el-button>
      </div>
    </el-dialog>

    <!-- 弹窗 2：查看历史与变更日志弹窗 -->
    <!-- Element UI 的 destroy-on-close 会通过修改 key 重建内容；此处嵌套 Tabs/Table
         在关闭重建时可导致渲染进程卡死。保留组件实例，数据由 openHistoryModal 每次重置并重取。 -->
    <el-dialog
      title="SKU 默认Item关系历史与审计日志"
      :visible.sync="historyModal.visible"
      width="900px"
      custom-class="relation-modal"
    >
      <div v-loading="historyModal.loading" class="modal-body-wrap">
        <!-- SKU 顶部概况横幅 -->
        <div v-if="historyModal.sku" class="history-sku-header">
          <div class="header-left">
            <span class="header-code code-mono">{{ historyModal.sku.sku_code }}</span>
            <span class="header-name">{{ historyModal.sku.sku_name }}</span>
            <el-tag size="mini" type="info">{{ historyModalSkuProduct }}</el-tag>
          </div>
          <div class="header-right">
            <span class="lbl">当前默认 Item：</span>
            <span v-if="historyModalCurrent" class="current-item-chip">
              <b class="code-mono">{{ historyModalCurrent.item_code }}</b> ({{ historyModalCurrent.item_name }})
            </span>
            <span v-else class="text-placeholder">未设置</span>
          </div>
        </div>

        <!-- 重复关系待修复提示条与快速处理 -->
        <div v-if="historyModal.audit && historyModal.audit.check_status === 'duplicate'" class="duplicate-repair-alert">
          <div class="repair-alert-title">
            <i class="el-icon-warning" />
            <span>检测到重复默认 Item 异常：该 SKU 存在多个启用的默认关系，请选择保留项</span>
          </div>
          <div class="repair-form-row">
            <el-select
              v-model="historyModal.repairForm.keep_relation_id"
              size="small"
              placeholder="选择保留的有效默认 Item"
              class="repair-select"
            >
              <el-option
                v-for="rel in historyDuplicateRelations"
                :key="rel.id"
                :label="relationOptionLabel(rel)"
                :value="rel.id"
              />
            </el-select>
            <el-select
              v-model="historyModal.repairForm.change_reason"
              size="small"
              placeholder="变更原因"
              class="repair-reason-select"
            >
              <el-option v-for="r in historyModal.reasons" :key="r" :label="r" :value="r" />
            </el-select>
            <el-input
              v-model="historyModal.repairForm.remark"
              size="small"
              placeholder="处理说明（可选）"
              class="repair-remark-input"
            />
            <el-button
              type="danger"
              size="small"
              :loading="historyModal.repairing"
              @click="resolveDuplicateInHistory"
            >
              一键修复
            </el-button>
          </div>
        </div>

        <!-- 历史切换 Tabs -->
        <el-tabs v-model="historyModal.activeTab" class="history-tabs">
          <el-tab-pane label="默认Item关系版本历史" name="relations">
            <el-table :data="historyModal.relations" border size="small" class="history-table">
              <el-table-column label="Item编码" prop="item.item_code" width="130" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span class="code-mono">{{ row.item && row.item.item_code || '—' }}</span>
                </template>
              </el-table-column>
              <el-table-column label="Item名称" prop="item.item_name" min-width="140" show-overflow-tooltip />
              <el-table-column label="规格型号" min-width="120" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span>{{ row.item && (row.item.spec) || '—' }}</span>
                </template>
              </el-table-column>
              <el-table-column label="履约因子" width="90" align="center">
                <template slot-scope="{ row }">
                  <span class="code-mono">{{ formatNumber(row.qty) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="状态" width="85" align="center">
                <template slot-scope="{ row }">
                  <span :class="['relation-badge', row.status === 'active' ? 'status-normal' : 'status-none']">
                    {{ row.status === 'active' ? '生效中' : '已失效' }}
                  </span>
                </template>
              </el-table-column>
              <el-table-column label="生效时间" width="140" align="center">
                <template slot-scope="{ row }">
                  <span class="code-mono">{{ date(row.effective_at) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="失效时间" width="140" align="center">
                <template slot-scope="{ row }">
                  <span class="code-mono">{{ date(row.expired_at) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="操作人" prop="operator_name" width="100" show-overflow-tooltip />
              <el-table-column label="变更原因" prop="change_reason" min-width="110" show-overflow-tooltip />
            </el-table>
          </el-tab-pane>

          <el-tab-pane label="变更操作审计日志" name="logs">
            <el-table :data="historyModal.logs" border size="small" class="history-table">
              <el-table-column label="操作时间" prop="created_at" width="145" align="center">
                <template slot-scope="{ row }">
                  <span class="code-mono">{{ date(row.created_at) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="操作人" prop="operator_name" width="100" show-overflow-tooltip />
              <el-table-column label="操作类型" prop="action" width="110" align="center" />
              <el-table-column label="旧Item编码" width="125" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span class="code-mono">{{ row.old_item && row.old_item.item_code || '—' }}</span>
                </template>
              </el-table-column>
              <el-table-column label="新Item编码" width="125" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span class="code-mono">{{ row.new_item && row.new_item.item_code || '—' }}</span>
                </template>
              </el-table-column>
              <el-table-column label="变更原因" prop="change_reason" min-width="120" show-overflow-tooltip />
              <el-table-column label="备注说明" prop="remark" min-width="140" show-overflow-tooltip />
            </el-table>
          </el-tab-pane>
        </el-tabs>
      </div>

      <div slot="footer" class="modal-footer">
        <el-button size="small" @click="historyModal.visible = false">关闭</el-button>
      </div>
    </el-dialog>

    <!-- 弹窗 3：关系完整性检查弹窗 -->
    <el-dialog
      title="SKU–Item 默认关系完整性检查"
      :visible.sync="integrityModal.visible"
      width="1040px"
      custom-class="relation-modal"
      destroy-on-close
    >
      <div v-loading="integrityModal.loading" class="modal-body-wrap">
        <!-- 统计指标小卡片 -->
        <div class="integrity-metric-grid">
          <div class="int-card blue">
            <span class="int-label">已检查关系</span>
            <strong class="int-val code-mono">{{ integrityModal.summary.checked || 0 }}</strong>
          </div>
          <div class="int-card green">
            <span class="int-label">正常有效</span>
            <strong class="int-val code-mono text-success">{{ integrityModal.summary.normal || 0 }}</strong>
          </div>
          <div class="int-card orange">
            <span class="int-label">待修复异常</span>
            <strong class="int-val code-mono text-warning">{{ integrityModal.summary.fix || 0 }}</strong>
          </div>
          <div class="int-card gray">
            <span class="int-label">无需Item</span>
            <strong class="int-val code-mono text-muted">{{ integrityModal.summary.none || 0 }}</strong>
          </div>
        </div>

        <!-- 过滤器与重新检查操作栏 -->
        <div class="integrity-toolbar">
          <el-radio-group v-model="integrityModal.tab" size="small" @change="onIntegrityTabChange">
            <el-radio-button label="all">全部 ({{ integrityModal.summary.checked || 0 }})</el-radio-button>
            <el-radio-button label="fix">待修复 ({{ integrityModal.summary.fix || 0 }})</el-radio-button>
            <el-radio-button label="normal">正常 ({{ integrityModal.summary.normal || 0 }})</el-radio-button>
            <el-radio-button label="not_required">无需Item ({{ integrityModal.summary.none || 0 }})</el-radio-button>
          </el-radio-group>
          <el-button
            size="small"
            type="success"
            icon="el-icon-refresh"
            class="btn-theme-create"
            :loading="integrityModal.loading"
            @click="loadIntegrityData"
          >
            重新检查
          </el-button>
        </div>

        <!-- 检查结果表格 -->
        <el-table :data="integrityModal.rows" border size="small" class="integrity-table">
          <el-table-column label="SKU编码" prop="sku.sku_code" width="125" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="code-mono">{{ row.sku && row.sku.sku_code }}</span>
            </template>
          </el-table-column>
          <el-table-column label="SKU名称" prop="sku.sku_name" min-width="130" show-overflow-tooltip />
          <el-table-column label="所属Product" min-width="140" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span>{{ row.product ? `${row.product.product_code || '-'}｜${row.product.product_name}` : '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="订单行类型" width="90" align="center">
            <template slot-scope="{ row }">
              <span :class="['type-badge', `type-${row.sku && row.sku.line_type}`]">{{ lineType(row.sku && row.sku.line_type) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="当前默认Item" min-width="130" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="code-mono">{{ getIntegrityItemsText(row) }}</span>
            </template>
          </el-table-column>
          <el-table-column label="检查状态" width="95" align="center">
            <template slot-scope="{ row }">
              <span :class="['relation-badge', integrityStatusClass(row.audit && row.audit.check_status)]">
                {{ integrityLabel(row.audit && row.audit.check_status) }}
              </span>
            </template>
          </el-table-column>
          <el-table-column label="异常原因" prop="audit.reason" min-width="160" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span :class="{ 'text-danger': row.audit && row.audit.reason }">{{ row.audit && row.audit.reason || '—' }}</span>
            </template>
          </el-table-column>
          <el-table-column label="修复操作" width="130" align="center" fixed="right">
            <template slot-scope="{ row }">
              <template v-if="row.audit && row.audit.check_status === 'missing'">
                <el-button type="text" size="small" class="btn-action-primary" @click="handleIntegrityFix(row)">
                  设置默认Item
                </el-button>
              </template>
              <template v-else-if="row.audit && row.audit.check_status === 'item_disabled'">
                <el-button type="text" size="small" class="btn-action-primary" @click="handleIntegrityFix(row)">
                  更换默认Item
                </el-button>
              </template>
              <template v-else-if="row.audit && row.audit.check_status === 'duplicate'">
                <el-button type="text" size="small" class="text-danger" @click="openHistoryModal(row)">
                  处理重复关系
                </el-button>
              </template>
              <template v-else-if="row.audit && row.audit.check_status === 'wrong_binding'">
                <el-button type="text" size="small" class="text-danger" @click="repairWrongBinding(row)">
                  解除错误绑定
                </el-button>
              </template>
              <span v-else class="text-placeholder">—</span>
            </template>
          </el-table-column>
        </el-table>

        <!-- 底部分页 -->
        <div class="modal-pager">
          <span class="total-text">共 {{ integrityModal.total }} 项检查记录</span>
          <el-pagination
            background
            layout="prev, pager, next"
            :current-page="integrityModal.page"
            :page-size="integrityModal.perPage"
            :total="integrityModal.total"
            @current-change="onIntegrityPageChange"
          />
        </div>
      </div>

      <div slot="footer" class="modal-footer">
        <el-button size="small" @click="integrityModal.visible = false">关闭</el-button>
      </div>
    </el-dialog>
  </section>
</template>

<script>
import pagedScroll from '../../../directives/pagedScroll'
import { materialListPath } from '../../../utils/materialManagementScope.mjs'
import { createPageState, queryPage, includeSelected } from '../../../utils/pagedQuery'
import {
  listDefaultSkuItemRelations,
  getDefaultSkuItemRelation,
  getSkuItemRelationHistory,
  setDefaultSkuItem,
  auditDefaultSkuItemRelations,
  resolveDuplicateSkuItemRelation,
  removeWrongSkuItemBinding,
  listEntity
} from '../../../api/erp/master'

export default {
  name: 'SkuItemRelationList',
  directives: { pagedScroll },
  data () {
    return {
      loading: false,
      rows: [],
      products: [],
      page: 1,
      perPage: 10,
      total: 0,
      summary: {},
      filters: {
        product_id: '',
        sku_keyword: '',
        item_keyword: '',
        line_type: '',
        sku_status: '',
        item_status: '',
        relation_status: ''
      },

      // 弹窗 1：更换 / 设置默认Item
      setModal: {
        visible: false,
        loading: false,
        saving: false,
        itemPage: createPageState(50),
        isChange: false,
        sku: null,
        current: null,
        currentRelation: null,
        activeRelationCount: 0,
        items: [],
        chosen: null,
        itemKeyword: '',
        form: {
          item_id: null,
          factor: 1,
          change_reason: '',
          remark: ''
        },
        reasons: ['首次设置', '产品升级', '规格调整', '主数据修正', '原Item停用', '历史数据补全', '其他']
      },

      // 弹窗 2：查看历史与变更日志
      historyModal: {
        visible: false,
        loading: false,
        repairing: false,
        activeTab: 'relations',
        sku: null,
        audit: {},
        relations: [],
        logs: [],
        repairForm: {
          keep_relation_id: null,
          change_reason: '',
          remark: ''
        },
        reasons: ['首次设置', '产品升级', '规格调整', '主数据修正', '原Item停用', '历史数据补全', '其他']
      },

      // 弹窗 3：关系完整性检查
      integrityModal: {
        visible: false,
        loading: false,
        tab: 'all',
        page: 1,
        perPage: 10,
        total: 0,
        rows: [],
        summary: {
          checked: 0,
          normal: 0,
          fix: 0,
          none: 0
        }
      }
    }
  },
  computed: {
    setModalSkuProduct () {
      if (!this.setModal.sku || !this.setModal.sku.product) return '—'
      const p = this.setModal.sku.product
      return `${p.product_code || '-'}｜${p.product_name}`
    },
    setModalSalesUnitText () {
      const u = this.setModal.sku && this.setModal.sku.sales_unit
      return u ? (u.symbol || u.unit_name) : '件'
    },
    setModalItemUnitText () {
      const u = this.setModal.chosen && this.setModal.chosen.unit
      return u ? (u.symbol || u.unit_name) : '个'
    },
    historyModalSkuProduct () {
      if (!this.historyModal.sku || !this.historyModal.sku.product) return '—'
      const p = this.historyModal.sku.product
      return `${p.product_code || '-'}｜${p.product_name}`
    },
    historyModalCurrentRelation () {
      const list = (this.historyModal.audit && this.historyModal.audit.relations) || []
      return list.find(x => x.status === 'active') || list[0] || null
    },
    historyModalCurrent () {
      return this.historyModalCurrentRelation && this.historyModalCurrentRelation.item
    },
    historyDuplicateRelations () {
      const list = (this.historyModal.audit && this.historyModal.audit.relations) || []
      return list.filter(x => x.status === 'active' && x.is_primary)
    }
  },
  created () {
    this.loadProducts()
    this.load()
  },
  methods: {
    defaultItemPath(item) {
      return materialListPath(item.management_scope || (item.item_type === 'office_consumable' ? 'office' : 'factory'))
    },
    async loadProducts () {
      try {
        const { data } = await listEntity('products', { per_page: 100 })
        this.products = data.data || []
      } catch (e) {}
    },
    async load () {
      this.loading = true
      try {
        const { data } = await listDefaultSkuItemRelations({
          ...this.filters,
          page: this.page,
          per_page: this.perPage
        })
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
      } catch (e) {
        this.$message.error(e.userMessage || '默认关系加载失败')
      } finally {
        this.loading = false
      }
    },
    search () {
      this.page = 1
      this.load()
    },
    reset () {
      Object.keys(this.filters).forEach(k => { this.filters[k] = '' })
      this.search()
    },
    go (p) {
      this.page = p
      this.load()
    },
    resize (n) {
      this.perPage = n
      this.page = 1
      this.load()
    },
    filterCard (lineType, relationStatus) {
      if (this.filters.line_type === lineType && this.filters.relation_status === relationStatus) {
        this.filters.line_type = ''
        this.filters.relation_status = ''
      } else {
        this.filters.line_type = lineType
        this.filters.relation_status = relationStatus
      }
      this.search()
    },
    productText (r) {
      return r.product ? `${r.product.product_code || '-'}｜${r.product.product_name}` : '—'
    },
    lineType (v) {
      return ({ physical: '实物', service: '服务', no_delivery: '无需发货' })[v] || '—'
    },
    relationLabel (v) {
      return ({ normal: '正常', missing: '缺失', duplicate: '异常', item_disabled: '异常', wrong_binding: '异常', abnormal: '异常', not_required: '无需Item' })[v] || '—'
    },
    relationStatusClass (status) {
      if (status === 'normal') return 'status-normal'
      if (status === 'missing') return 'status-missing'
      if (['duplicate', 'item_disabled', 'wrong_binding', 'abnormal'].includes(status)) return 'status-abnormal'
      if (status === 'not_required') return 'status-none'
      return ''
    },
    date (v) {
      return v ? String(v).slice(0, 16).replace('T', ' ') : '—'
    },
    formatNumber (val) {
      return Number(val || 0).toFixed(8).replace(/0+$/, '').replace(/\.$/, '')
    },
    canSet (r) {
      return r.line_type === 'physical' && this.$can(r.default_item ? 'sku_item_relation.change' : 'sku_item_relation.set')
    },
    itemType (value) {
      return ({
        finished_product: '成品',
        semi_finished: '半成品',
        raw_material: '原材料',
        packaging: '包装物',
        service: '服务'
      })[value] || '—'
    },

    // ==========================================
    // 弹窗 1：更换 / 设置默认Item相关方法
    // ==========================================
    async openSetItemModal (row) {
      const skuId = row.sku ? row.sku.id : row.id
      this.setModal.visible = true
      this.setModal.loading = true
      this.setModal.sku = null
      this.setModal.current = null
      this.setModal.currentRelation = null
      this.setModal.chosen = null
      this.setModal.items = []
      this.setModal.itemKeyword = ''
      this.setModal.itemPage = createPageState(50)
      this.setModal.form = {
        item_id: null,
        factor: 1,
        change_reason: '',
        remark: ''
      }

      try {
        const detail = await getDefaultSkuItemRelation(skuId)
        const d = detail.data.data
        this.setModal.sku = d.sku
        if (this.setModal.sku.line_type !== 'physical') {
          this.$message.warning('服务或无需发货 SKU 不允许设置默认 Item')
          this.setModal.visible = false
          return
        }
        const active = (d.audit.relations || []).filter(x => x.status === 'active' && x.is_primary)
        this.setModal.activeRelationCount = active.length
        this.setModal.currentRelation = active[0] || null
        this.setModal.current = this.setModal.currentRelation ? this.setModal.currentRelation.item : null
        this.setModal.isChange = !!this.setModal.current
        this.setModal.form.factor = Number(this.setModal.currentRelation?.qty || 1)
        this.setModal.form.change_reason = this.setModal.current ? '' : '首次设置'

        // 默认预加载启用的 Item 列表
        await this.fetchModalItems('')
      } catch (e) {
        this.$message.error(e.userMessage || '加载 SKU 关系详情失败')
        this.setModal.visible = false
      } finally {
        this.setModal.loading = false
      }
    },
    async searchModalItems (keyword) {
      this.setModal.itemKeyword = keyword || ''
      await this.fetchModalItems(this.setModal.itemKeyword)
    },
    async fetchModalItems (keyword, append = false) {
      const state = this.setModal.itemPage
      if (!append) this.setModal.items = includeSelected([], this.setModal.chosen)
      try {
        const data = await queryPage(state, params => listEntity('items', params), { management_scope: 'factory', status: 'enabled', keyword: keyword || '' }, append)
        if (data && state === this.setModal.itemPage) this.setModal.items = includeSelected(state.rows, this.setModal.chosen)
      } catch (e) { this.$message.error(e.userMessage || '物料候选加载失败') }
    },
    loadMoreModalItems () {
      if (!this.setModal.visible) return
      return this.fetchModalItems(this.setModal.itemKeyword, true)
    },
    onSelectModalItem (itemId) {
      this.setModal.chosen = this.setModal.items.find(x => Number(x.id) === Number(itemId)) || null
    },
    async saveSetItem () {
      const { form, current, currentRelation, activeRelationCount, sku } = this.setModal
      if (!form.item_id) return this.$message.warning('请选择新的默认 Item')
      if (!(Number(form.factor) > 0)) return this.$message.warning('履约数量换算因子必须大于 0')
      if (
        current &&
        current.id === form.item_id &&
        Math.abs(Number(currentRelation?.qty || 0) - Number(form.factor)) < 0.00000001
      ) {
        return this.$message.error('新 Item 和履约因子不能与当前关系完全相同')
      }
      if (!form.change_reason) return this.$message.warning('请选择变更原因')
      if (form.change_reason === '其他' && !String(form.remark || '').trim()) {
        return this.$message.warning('变更原因选择“其他”时必须填写备注说明')
      }
      if (activeRelationCount > 1) {
        return this.$message.error('当前存在多个启用默认 Item，请先在完整性检查中处理重复关系')
      }

      this.setModal.saving = true
      try {
        await setDefaultSkuItem(sku.id, form)
        this.$message.success('默认 Item 已保存并立即生效')
        this.setModal.visible = false
        this.load()
        if (this.integrityModal.visible) {
          this.loadIntegrityData()
        }
      } catch (e) {
        this.$message.error(e.userMessage || '保存默认 Item 失败')
      } finally {
        this.setModal.saving = false
      }
    },

    // ==========================================
    // 弹窗 2：查看历史与变更日志相关方法
    // ==========================================
    async openHistoryModal (row) {
      const skuId = row.sku ? row.sku.id : row.id
      this.historyModal.visible = true
      this.historyModal.loading = true
      this.historyModal.activeTab = 'relations'
      this.historyModal.sku = null
      this.historyModal.audit = {}
      this.historyModal.relations = []
      this.historyModal.logs = []
      this.historyModal.repairForm = { keep_relation_id: null, change_reason: '', remark: '' }

      try {
        const [detailRes, historyRes] = await Promise.all([
          getDefaultSkuItemRelation(skuId),
          getSkuItemRelationHistory(skuId)
        ])
        this.historyModal.sku = detailRes.data.data.sku
        this.historyModal.audit = detailRes.data.data.audit || {}
        this.historyModal.relations = historyRes.data.data.relations || []
        this.historyModal.logs = historyRes.data.data.logs || []
      } catch (e) {
        this.$message.error(e.userMessage || '历史记录加载失败')
      } finally {
        this.historyModal.loading = false
      }
    },
    relationOptionLabel (relation) {
      const item = relation.item || {}
      return `${item.item_code || '—'}｜${item.item_name || '—'}｜${item.spec || '—'}`
    },
    async resolveDuplicateInHistory () {
      const { keep_relation_id, change_reason, remark } = this.historyModal.repairForm
      if (!keep_relation_id || !change_reason) {
        return this.$message.warning('请选择保留的默认 Item 及变更原因')
      }
      if (change_reason === '其他' && !String(remark || '').trim()) {
        return this.$message.warning('变更原因选择“其他”时必须填写备注说明')
      }

      this.historyModal.repairing = true
      try {
        await resolveDuplicateSkuItemRelation(this.historyModal.sku.id, this.historyModal.repairForm)
        this.$message.success('重复关系已修复，已保留唯一有效默认 Item')
        // 重新拉取历史弹窗数据与主列表
        const [detailRes, historyRes] = await Promise.all([
          getDefaultSkuItemRelation(this.historyModal.sku.id),
          getSkuItemRelationHistory(this.historyModal.sku.id)
        ])
        this.historyModal.audit = detailRes.data.data.audit || {}
        this.historyModal.relations = historyRes.data.data.relations || []
        this.historyModal.logs = historyRes.data.data.logs || []
        this.load()
        if (this.integrityModal.visible) {
          this.loadIntegrityData()
        }
      } catch (e) {
        this.$message.error(e.userMessage || '处理重复关系失败')
      } finally {
        this.historyModal.repairing = false
      }
    },

    // ==========================================
    // 弹窗 3：关系完整性检查相关方法
    // ==========================================
    openIntegrityModal () {
      this.integrityModal.visible = true
      this.integrityModal.page = 1
      this.loadIntegrityData()
    },
    async loadIntegrityData () {
      this.integrityModal.loading = true
      try {
        const { data } = await auditDefaultSkuItemRelations({
          page: this.integrityModal.page,
          per_page: this.integrityModal.perPage,
          status: this.integrityModal.tab
        })
        this.integrityModal.rows = data.data || []
        this.integrityModal.total = data.total || 0
        this.integrityModal.summary = data.summary || { checked: 0, normal: 0, fix: 0, none: 0 }
      } catch (e) {
        this.$message.error(e.userMessage || '完整性检查加载失败')
      } finally {
        this.integrityModal.loading = false
      }
    },
    onIntegrityTabChange () {
      this.integrityModal.page = 1
      this.loadIntegrityData()
    },
    onIntegrityPageChange (p) {
      this.integrityModal.page = p
      this.loadIntegrityData()
    },
    getIntegrityItemsText (row) {
      if (!row.audit || !row.audit.relations) return '—'
      const codes = row.audit.relations.map(x => x.item && x.item.item_code).filter(Boolean)
      return codes.length ? codes.join('、') : '—'
    },
    integrityLabel (v) {
      return ({
        normal: '正常',
        missing: '待修复',
        duplicate: '待修复',
        item_disabled: '待修复',
        wrong_binding: '待修复',
        not_required: '无需Item'
      })[v] || '—'
    },
    integrityStatusClass (status) {
      if (status === 'normal') return 'status-normal'
      if (status === 'not_required') return 'status-none'
      return 'status-abnormal'
    },
    handleIntegrityFix (row) {
      this.openSetItemModal(row)
    },
    async repairWrongBinding (row) {
      try {
        await this.$confirm('确认解除该服务/无需发货 SKU 的错误 Item 绑定？处理后立即生效并记录操作日志。', '解除错误绑定', {
          type: 'warning',
          confirmButtonText: '确定解除',
          cancelButtonText: '取消'
        })
        const { value } = await this.$prompt('请输入本次解除绑定的修复说明', '修复说明', {
          inputPlaceholder: '例如：服务或无需发货 SKU 不应绑定实物 Item',
          inputValidator: v => !!String(v || '').trim() || '请填写修复说明',
          confirmButtonText: '提交',
          cancelButtonText: '取消'
        })
        await removeWrongSkuItemBinding(row.sku.id, { change_reason: '错误绑定修复', remark: String(value).trim() })
        this.$message.success('已成功解除错误绑定')
        this.loadIntegrityData()
        this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') {
          this.$message.error(e.userMessage || '解除错误绑定失败')
        }
      }
    }
  }
}
</script>

<style scoped>
.relation-page {
  padding: 16px 20px 30px;
  background: #f8fafc;
  min-height: calc(100vh - 52px);
  color: #1f2937;
  box-sizing: border-box;
}

/* 页面头部：标准卡片规范 */
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

.btn-integrity {
  border-color: #cbd5e1 !important;
  color: #334155 !important;
  font-weight: 500;
}

.btn-integrity:hover {
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

/* 全局统一业务提示条 */
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

/* 概览统计指标卡片网格 */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(5, 1fr);
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
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  transition: all 0.2s ease;
  cursor: pointer;
  box-sizing: border-box;
}

.metric-card:hover {
  border-color: #86efac;
  box-shadow: 0 4px 12px rgba(0, 139, 75, 0.08);
  transform: translateY(-1px);
}

.metric-card.active {
  border-color: #008b4b;
  background: #f0fdf4;
  box-shadow: 0 2px 8px rgba(0, 139, 75, 0.12);
}

.metric-icon-box {
  width: 42px;
  height: 42px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}

.metric-physical .metric-icon-box {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}

.metric-configured .metric-icon-box {
  background: #ecfdf5;
  color: #059669;
  border: 1px solid #a7f3d0;
}

.metric-missing .metric-icon-box {
  background: #fffbeb;
  color: #d97706;
  border: 1px solid #fde68a;
}

.metric-abnormal .metric-icon-box {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fecaca;
}

.metric-not-required .metric-icon-box {
  background: #f3f4f6;
  color: #6b7280;
  border: 1px solid #e5e7eb;
}

.metric-info {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
  min-width: 0;
}

.metric-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.metric-val {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.text-success { color: #008b4b !important; }
.text-warning { color: #d97706 !important; }
.text-danger { color: #dc2626 !important; }
.text-muted { color: #64748b !important; }

/* 筛选过滤卡片 */
.filter-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 18px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  box-sizing: border-box;
}

.filter-inputs {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
  flex: 1;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 6px;
}

.filter-label {
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
}

.filter-input {
  width: 140px;
}

.filter-select-lg {
  width: 190px;
}

.filter-select-sm {
  width: 110px;
}

.filter-select-xs {
  width: 90px;
}

.filter-actions {
  display: flex;
  align-items: center;
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
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 表格卡片（全宽容器） */
.table-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
  min-width: 0;
  width: 100%;
  box-sizing: border-box;
}

.table-wrap {
  width: 100%;
  overflow-x: auto;
}

.relation-data-table ::v-deep th {
  background: #f8fafc !important;
  color: #334155 !important;
  font-weight: 600;
  font-size: 12px;
  padding: 10px 0;
}

.relation-data-table ::v-deep td {
  padding: 8px 0;
  font-size: 13px;
  color: #1e293b;
}

.sku-code-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 7px;
  border-radius: 4px;
  color: #00763f;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.sku-code-chip:hover {
  background: #dcfce7;
  border-color: #86efac;
}

.item-code-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  padding: 2px 7px;
  border-radius: 4px;
  color: #334155;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.item-code-chip:hover {
  background: #f1f5f9;
  border-color: #cbd5e1;
  color: #008b4b;
}

.sku-name-text,
.item-name-text {
  font-size: 13px;
  font-weight: 500;
  color: #0f172a;
}

.product-text {
  font-size: 12px;
  color: #475569;
}

.time-text {
  font-size: 12px;
  color: #64748b;
}

.text-placeholder {
  color: #94a3b8;
}

/* 徽章样式 */
.type-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 500;
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

.type-no_delivery {
  background: #fff7ed;
  color: #c2410c;
  border: 1px solid #ffedd5;
}

.status-badge {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: 12px;
  font-weight: 500;
}

.status-badge.enabled {
  color: #008b4b;
}

.status-badge.disabled {
  color: #94a3b8;
}

.relation-badge {
  display: inline-block;
  padding: 2px 7px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}

.status-normal {
  color: #00763f;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
}

.status-missing {
  color: #c2410c;
  background: #fff7ed;
  border: 1px solid #ffedd5;
}

.status-abnormal {
  color: #dc2626;
  background: #fef2f2;
  border: 1px solid #fecaca;
}

.status-none {
  color: #64748b;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
}

.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.btn-action-primary {
  color: #008b4b !important;
  font-weight: 600;
}

.btn-action-primary:hover {
  color: #00763f !important;
}

.btn-action-view {
  color: #64748b !important;
}

.btn-action-view:hover {
  color: #008b4b !important;
}

/* 底部分页 */
.pager-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 18px;
  background: #ffffff;
  border-top: 1px solid #e2e8f0;
  box-sizing: border-box;
}

.total-text {
  font-size: 13px;
  color: #64748b;
}

/* 等宽数字字体 */
.code-mono {
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.2px;
}

/* ==========================================
   弹窗通用样式与组件布局
   ========================================== */
::v-deep .relation-modal {
  border-radius: 10px;
  overflow: hidden;
  max-width: 95vw;
}

::v-deep .relation-modal .el-dialog__header {
  background: #f8fafc;
  padding: 16px 20px;
  border-bottom: 1px solid #e2e8f0;
}

::v-deep .relation-modal .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

::v-deep .relation-modal .el-dialog__body {
  padding: 18px 22px;
  max-height: 72vh;
  overflow-y: auto;
}

::v-deep .relation-modal .el-dialog__footer {
  padding: 12px 20px;
  border-top: 1px solid #e2e8f0;
  background: #f8fafc;
}

.modal-body-wrap {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.modal-tip-notice {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 12px;
}

.modal-tip-notice i {
  font-size: 14px;
  flex-shrink: 0;
}

/* SKU 概览卡片 */
.sku-summary-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
}

.summary-header {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 8px;
  color: #008b4b;
  font-size: 13px;
  font-weight: 600;
}

.summary-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px 14px;
}

.summary-item {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.summary-label {
  font-size: 11px;
  color: #64748b;
}

.summary-val {
  font-size: 13px;
  font-weight: 500;
  color: #1e293b;
  word-break: break-all;
}

.type-tag {
  display: inline-block;
  padding: 1px 6px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 3px;
  color: #00763f;
  font-size: 11px;
  width: fit-content;
}

/* 新默认 Item 搜索选择栏 */
.item-search-section {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
}

.search-label-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 8px;
  flex-wrap: wrap;
  gap: 6px;
}

.search-label {
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}

.search-label i {
  color: #008b4b;
}

.search-tip {
  font-size: 12px;
  color: #64748b;
}

/* Item 对比区域：确保左右高度严格一致 */
.item-compare-card {
  display: grid;
  grid-template-columns: 1fr 32px 1fr;
  gap: 12px;
  align-items: stretch;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px;
}

.compare-col {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 250px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
  box-sizing: border-box;
}

.col-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 14px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
}

.col-title {
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}

.col-title i {
  color: #008b4b;
}

.pill-tag {
  font-size: 11px;
  padding: 1px 8px;
  border-radius: 10px;
  font-weight: 500;
}

.pill-current {
  background: #f1f5f9;
  color: #475569;
  border: 1px solid #cbd5e1;
}

.pill-chosen {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
}

.pill-none {
  background: #fff7ed;
  color: #c2410c;
  border: 1px solid #ffedd5;
}

.item-details-box {
  flex: 1;
  padding: 12px 14px;
  font-size: 12px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  gap: 7px;
}

.item-details-box.chosen-box {
  background: #f0fdf4;
}

.item-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding-bottom: 4px;
  border-bottom: 1px dashed rgba(226, 232, 240, 0.8);
}

.item-row:last-child {
  border-bottom: none;
  padding-bottom: 0;
}

.item-row .lbl {
  color: #64748b;
  flex-shrink: 0;
}

.item-row .val {
  color: #0f172a;
  text-align: right;
  word-break: break-all;
}

.empty-item-box {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 24px;
  color: #94a3b8;
  font-size: 13px;
  text-align: center;
}

.empty-item-box i {
  font-size: 26px;
  color: #cbd5e1;
}

.empty-item-box.awaiting i {
  color: #94a3b8;
}

.empty-sub {
  font-size: 11px;
  color: #94a3b8;
}

.compare-arrow {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  color: #008b4b;
}

.full-width-select {
  width: 100%;
}

/* 履约换算配置与实际用例卡片 */
.conversion-section {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px;
}

.section-title {
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
  margin-bottom: 10px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.section-title i {
  color: #008b4b;
}

.conversion-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}

.form-field {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.field-label {
  font-size: 12px;
  color: #475569;
}

.factor-input {
  width: 100% !important;
}

.conversion-usecase-card {
  margin-top: 12px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
}

.formula-banner {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 10px 14px;
  background: #f0fdf4;
  border-bottom: 1px solid #bbf7d0;
  flex-wrap: wrap;
}

.formula-tag {
  background: #008b4b;
  color: #ffffff;
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 4px;
}

.formula-text {
  font-size: 13px;
  color: #00763f;
}

.formula-text b {
  font-size: 14px;
}

.usecase-detail-wrap {
  padding: 12px 14px;
}

.usecase-head {
  display: flex;
  align-items: center;
  gap: 6px;
  color: #1e293b;
  font-size: 13px;
  margin-bottom: 10px;
}

.usecase-head i {
  color: #d97706;
  font-size: 15px;
}

.usecase-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}

.usecase-item {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.usecase-pill {
  font-size: 11px;
  font-weight: 600;
  color: #334155;
  background: #e2e8f0;
  padding: 2px 6px;
  border-radius: 4px;
  width: fit-content;
}

.usecase-desc {
  font-size: 12px;
  color: #475569;
  line-height: 1.5;
}

.highlight-factor {
  font-weight: 700;
  color: #008b4b;
  font-size: 13px;
  font-family: monospace;
}

/* 原因说明区 */
.reason-section {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
}

.reason-row {
  display: grid;
  grid-template-columns: 200px 1fr;
  gap: 12px;
}

/* 历史弹窗头部 */
.history-sku-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 14px;
  flex-wrap: wrap;
  gap: 10px;
}

.history-sku-header .header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.history-sku-header .header-code {
  font-weight: 700;
  color: #0f172a;
}

.history-sku-header .header-name {
  color: #475569;
  font-size: 13px;
}

.history-sku-header .header-right {
  font-size: 12px;
  display: flex;
  align-items: center;
  gap: 4px;
}

.current-item-chip {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 8px;
  border-radius: 4px;
  color: #00763f;
}

/* 重复关系修复条 */
.duplicate-repair-alert {
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 8px;
  padding: 12px 14px;
}

.repair-alert-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #b45309;
  margin-bottom: 8px;
}

.repair-form-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.repair-select {
  flex: 1.5;
  min-width: 180px;
}

.repair-reason-select {
  flex: 1;
  min-width: 120px;
}

.repair-remark-input {
  flex: 1.5;
  min-width: 150px;
}

.history-table ::v-deep th {
  background: #f8fafc !important;
  font-size: 12px;
}

/* 完整性检查弹窗 */
.integrity-metric-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
}

.int-card {
  padding: 12px 16px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  background: #ffffff;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.int-label {
  font-size: 12px;
  color: #64748b;
}

.int-val {
  font-size: 22px;
  font-weight: 700;
  color: #0f172a;
}

.integrity-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
}

.integrity-table ::v-deep th {
  background: #f8fafc !important;
  font-size: 12px;
}

.modal-pager {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-top: 10px;
}

/* 响应式断点适配规则 (遵循全局响应式设计强制规则) */
@media (max-width: 1400px) {
  .metric-overview-grid {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (max-width: 900px) {
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
    grid-template-columns: repeat(2, 1fr);
  }

  .summary-grid {
    grid-template-columns: repeat(2, 1fr);
  }

  .item-compare-card {
    grid-template-columns: 1fr;
  }

  .compare-arrow {
    transform: rotate(90deg);
  }

  .conversion-grid {
    grid-template-columns: 1fr;
  }

  .usecase-grid {
    grid-template-columns: 1fr;
  }

  .reason-row {
    grid-template-columns: 1fr;
  }

  .integrity-metric-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 680px) {
  .relation-page {
    padding: 10px 12px;
  }

  .metric-overview-grid {
    grid-template-columns: 1fr;
  }

  .filter-inputs {
    flex-direction: column;
    align-items: stretch;
    width: 100%;
  }

  .filter-item {
    width: 100%;
    flex-direction: column;
    align-items: stretch;
  }

  .filter-input,
  .filter-select-lg,
  .filter-select-sm,
  .filter-select-xs {
    width: 100% !important;
  }

  .filter-actions {
    width: 100%;
    justify-content: flex-end;
  }

  .pager-row {
    flex-direction: column;
    align-items: stretch;
    gap: 8px;
  }

  .summary-grid {
    grid-template-columns: 1fr;
  }

  .integrity-metric-grid {
    grid-template-columns: 1fr;
  }

  .repair-form-row {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
