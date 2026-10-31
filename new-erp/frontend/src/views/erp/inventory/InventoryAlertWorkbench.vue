<template>
  <section class="alert-workbench" v-loading="loading">
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-bell" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">库存预警</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">共 {{ total }} 条预警记录</el-tag>
            <el-tag v-if="stats.active > 0" size="small" type="warning" effect="plain" class="sub-total-tag">
              {{ stats.active }} 条活跃预警
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button size="small" icon="el-icon-setting" @click="openConfiguration()">预警规则配置</el-button>
        <el-button size="small" type="success" class="btn-theme-create" icon="el-icon-shopping-cart-2" :disabled="!selected.length" @click="createRequest">
          批量生成采购需求<span v-if="selected.length">（{{ selected.length }}）</span>
        </el-button>
      </div>
    </header>

    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>业务说明：库存预警基于真实可用库存（账面库存 − 已锁定/预留 − 不合格/待处理）计算；点击下方指标卡片可快速筛选对应预警，点击任意行或“查看详情 / 规则配置”即可直接弹窗处理。</span>
    </div>

    <div class="metric-overview-grid">
      <article class="metric-card" :class="{ active: filters.is_active === 'true' && !filters.alert_status }" @click="filterByMetric('active')">
        <div class="metric-icon warning"><i class="el-icon-bell" /></div>
        <div class="metric-copy">
          <span>活跃预警总数</span>
          <strong>{{ stats.active || 0 }}</strong>
          <small>当前未解除预警物料</small>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.alert_status === 'out_of_stock' }" @click="filterByMetric('out_of_stock')">
        <div class="metric-icon danger"><i class="el-icon-warning" /></div>
        <div class="metric-copy">
          <span>缺货预警（严重）</span>
          <strong class="danger-num">{{ stats.out || 0 }}</strong>
          <small>可用库存 ≤ 0，急需补货</small>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.alert_status === 'low_stock' }" @click="filterByMetric('low_stock')">
        <div class="metric-icon amber"><i class="el-icon-warning-outline" /></div>
        <div class="metric-copy">
          <span>低库存预警</span>
          <strong class="warning-num">{{ stats.low || 0 }}</strong>
          <small>可用库存 ≤ 安全库存</small>
        </div>
      </article>
      <article class="metric-card" :class="{ active: filters.alert_status === 'over_stock' }" @click="filterByMetric('over_stock')">
        <div class="metric-icon primary"><i class="el-icon-top" /></div>
        <div class="metric-copy">
          <span>超储预警</span>
          <strong>{{ stats.over || 0 }}</strong>
          <small>可用库存 ＞ 最高库存</small>
        </div>
      </article>
    </div>

    <section class="filter-card">
      <el-form inline size="small" class="filter-form">
        <el-form-item label="预警状态">
          <el-select v-model="filters.alert_status" clearable placeholder="全部状态" @change="search">
            <el-option label="缺货" value="out_of_stock" />
            <el-option label="低库存" value="low_stock" />
            <el-option label="超储" value="over_stock" />
            <el-option label="正常" value="normal" />
          </el-select>
        </el-form-item>
        <el-form-item label="物料检索">
          <el-input v-model="filters.keyword" placeholder="物料编码 / 名称" clearable @keyup.enter.native="search" @clear="search" />
        </el-form-item>
        <el-form-item label="物料分类">
          <paged-record-select v-model="filters.category_id" entity="categories" :params="{ category_type: 'item' }" title="选择物料分类" placeholder="全部分类" />
        </el-form-item>
        <el-form-item label="仓库">
          <paged-record-select v-model="filters.warehouse_id" entity="warehouses" title="选择仓库" placeholder="全部仓库" />
        </el-form-item>
        <el-form-item label="处理状态">
          <el-select v-model="filters.is_active" clearable placeholder="全部" @change="search">
            <el-option label="活跃预警" value="true" />
            <el-option label="已解除" value="false" />
          </el-select>
        </el-form-item>
        <div class="filter-btns">
          <el-button type="success" size="small" class="btn-theme-create" @click="search">查询</el-button>
          <el-button size="small" @click="reset">重置</el-button>
        </div>
      </el-form>
    </section>

    <section class="table-card">
      <div class="table-toolbar">
        <span class="toolbar-summary">共 <b>{{ total }}</b> 条预警记录<template v-if="selected.length">，已勾选 <b>{{ selected.length }}</b> 条</template></span>
        <span class="toolbar-hint">提示：点击行可直接打开预警详情与库存水位分析</span>
      </div>
      <el-table
        ref="table"
        :data="rows"
        border
        size="small"
        row-key="id"
        class="alert-table"
        @selection-change="selected = $event"
        @row-click="viewDetail"
      >
        <el-table-column type="selection" width="44" align="center" />
        <el-table-column label="物料信息" min-width="210">
          <template slot-scope="{ row }">
            <div class="item-cell">
              <div class="item-top">
                <el-button type="text" class="code-link" @click.stop="viewDetail(row)">{{ (row.item && row.item.item_code) || '-' }}</el-button>
                <span v-if="!row.is_read" class="unread-dot" title="未读预警" />
              </div>
              <div class="item-name">{{ (row.item && row.item.item_name) || '-' }}</div>
              <div v-if="row.item && (row.item.spec || (row.item.category && row.item.category.category_name))" class="item-sub">
                {{ [row.item.spec, row.item.category && row.item.category.category_name].filter(Boolean).join(' · ') }}
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="所属仓库" min-width="125">
          <template slot-scope="{ row }">
            <div class="wh-cell">
              <strong>{{ (row.warehouse && row.warehouse.warehouse_name) || '公司全局' }}</strong>
              <small v-if="row.warehouse && row.warehouse.warehouse_code">{{ row.warehouse.warehouse_code }}</small>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="可用 / 账面库存" min-width="175">
          <template slot-scope="{ row }">
            <div class="stock-cell">
              <div class="avail-line">
                <span>可用</span>
                <strong :class="availTextClass(row.alert_status, row.severity)">{{ number(row.available_qty) }} {{ rowUnit(row) }}</strong>
              </div>
              <div class="sub-stock-line">
                账面 {{ number(row.quantity_on_hand) }} · 锁定 {{ number(row.quantity_locked) }}
                <template v-if="Number(row.quantity_defective || 0) > 0"> · 不良 {{ number(row.quantity_defective) }}</template>
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="预警阈值与水位" min-width="195">
          <template slot-scope="{ row }">
            <div class="threshold-cell">
              <div class="threshold-nums">
                <span>最低 <b>{{ number(row.min_stock_snapshot) }}</b></span>
                <span>安全 <b>{{ number(row.safety_stock_snapshot) }}</b></span>
                <span v-if="row.max_stock_snapshot !== null && row.max_stock_snapshot !== undefined">最高 <b>{{ number(row.max_stock_snapshot) }}</b></span>
              </div>
              <div class="water-bar">
                <i :class="barFillClass(row.alert_status, row.severity)" :style="{ width: rowWaterPercent(row) + '%' }" />
              </div>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="建议补货" min-width="115" align="right">
          <template slot-scope="{ row }">
            <strong class="replenish-num">{{ number(row.suggested_replenishment_qty_snapshot) }} {{ rowUnit(row) }}</strong>
            <div v-if="rowShortage(row) > 0" class="shortage-sub">缺口 {{ number(rowShortage(row)) }}</div>
          </template>
        </el-table-column>
        <el-table-column label="当前状态 / 触发时间" min-width="165">
          <template slot-scope="{ row }">
            <div class="status-cell">
              <el-tag size="mini" :type="tag(row.alert_status, row.severity)" @click.native.stop="viewDetail(row)">
                {{ label(row.alert_status, row.severity) }}
              </el-tag>
              <span class="time-sub">{{ date(row.last_changed_at) }}</span>
            </div>
          </template>
        </el-table-column>
        <el-table-column label="操作" width="215" fixed="right">
          <template slot-scope="{ row }">
            <div class="op-links" @click.stop>
              <el-button type="text" size="mini" class="op-link" @click="viewDetail(row)">查看详情</el-button>
              <el-button type="text" size="mini" class="op-link" @click="openConfiguration(row)">规则配置</el-button>
              <el-button v-if="!row.purchase_request_id && row.is_active" type="text" size="mini" class="op-link primary" @click="createRequestFor(row)">生成需求</el-button>
              <el-button v-else-if="row.purchase_request_id" type="text" size="mini" class="op-link" @click="openPurchaseRequest(row)">查看需求</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager">
        <span>共 {{ total }} 条</span>
        <el-pagination
          background
          small
          layout="prev, pager, next, sizes, jumper"
          :current-page="page"
          :page-size="perPage"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          @current-change="changePage"
          @size-change="changeSize"
        />
      </div>
    </section>

    <!-- 预警详情与水位分析弹窗 -->
    <el-dialog
      :title="detailAlert.id ? `库存预警详情 · ${(detailItem.item_code || '')} ${(detailItem.item_name || '')}` : '库存预警详情'"
      :visible.sync="detailVisible"
      width="920px"
      top="5vh"
      custom-class="master-style-dialog"
      :close-on-click-modal="false"
    >
      <div v-loading="detailLoading" class="detail-dialog-body">
        <div class="detail-summary-grid">
          <article class="summary-box">
            <h4>物料与仓库</h4>
            <dl>
              <dt>物料编码</dt><dd class="mono">{{ detailItem.item_code || '-' }}</dd>
              <dt>物料名称</dt><dd>{{ detailItem.item_name || '-' }}</dd>
              <dt>物料分类</dt><dd>{{ (detailItem.category && detailItem.category.category_name) || '-' }}</dd>
              <dt>所属仓库</dt><dd>{{ detailWarehouse.warehouse_name || '公司全局' }}</dd>
            </dl>
          </article>
          <article class="summary-box">
            <h4>当前库存事实</h4>
            <div class="facts-split">
              <dl>
                <dt>账面库存</dt><dd>{{ number(detailAlert.quantity_on_hand) }} {{ detailUnit }}</dd>
                <dt>已锁定/预留</dt><dd>{{ number(detailAlert.quantity_locked) }} {{ detailUnit }}</dd>
                <dt>不良/待处理</dt><dd>{{ number(detailAlert.quantity_defective) }} {{ detailUnit }}</dd>
              </dl>
              <div class="avail-highlight">
                <span>真实可用库存</span>
                <strong :class="detailSeverityClass">{{ number(detailAlert.available_qty) }} {{ detailUnit }}</strong>
              </div>
            </div>
          </article>
          <article class="summary-box">
            <h4>当前预警与处置</h4>
            <dl>
              <dt>预警等级</dt>
              <dd><el-tag size="mini" :type="detailTagType">{{ detailStateLabel }}</el-tag></dd>
              <dt>触发依据</dt><dd>{{ detailStateBasis }}</dd>
              <dt>最新变化</dt><dd>{{ date(detailAlert.last_changed_at) }}</dd>
              <dt>采购需求</dt>
              <dd>
                <el-button v-if="detailAlert.purchase_request_id" type="text" size="mini" @click="openPurchaseRequest(detailAlert)">
                  {{ (detailRequest && detailRequest.request_no) || '查看采购需求' }}
                </el-button>
                <span v-else class="muted-txt">尚未生成采购需求草稿</span>
              </dd>
            </dl>
          </article>
        </div>

        <section class="threshold-panel">
          <div class="panel-title-row">
            <h4>预警阈值与水位尺</h4>
            <span>{{ detailHandlingText }}</span>
          </div>
          <div class="threshold-kpi-row">
            <div><span>最低库存</span><b>{{ number(detailAlert.min_stock_snapshot) }} {{ detailUnit }}</b></div>
            <div><span>安全库存</span><b>{{ number(detailAlert.safety_stock_snapshot) }} {{ detailUnit }}</b></div>
            <div><span>最高库存</span><b>{{ number(detailAlert.max_stock_snapshot) }} {{ detailUnit }}</b></div>
            <div><span>当前可用</span><b class="safe">{{ number(detailAlert.available_qty) }} {{ detailUnit }}</b></div>
            <div><span>安全缺口</span><b class="danger">{{ detailShortage }} {{ detailUnit }}</b></div>
            <div><span>建议补货</span><b class="primary">{{ number(detailAlert.suggested_replenishment_qty_snapshot) }} {{ detailUnit }}</b></div>
          </div>
          <div class="scale-track">
            <i class="red-zone" /><i class="orange-zone" /><i class="green-zone" />
            <em :style="detailPointerStyle" />
            <div class="scale-labels">
              <span>0</span>
              <span>最低 {{ number(detailAlert.min_stock_snapshot) }}</span>
              <span>安全 {{ number(detailAlert.safety_stock_snapshot) }}</span>
              <span>最高 {{ number(detailAlert.max_stock_snapshot) }}</span>
              <span>当前可用 {{ number(detailAlert.available_qty) }}</span>
            </div>
          </div>
        </section>

        <section class="history-panel">
          <h4>状态变化历史（共 {{ historyTotal }} 条）</h4>
          <el-table :data="histories" border size="mini" max-height="240" empty-text="暂无状态变化记录">
            <el-table-column label="时间" width="155">
              <template slot-scope="{ row }">{{ date(row.occurred_at) }}</template>
            </el-table-column>
            <el-table-column label="原状态" width="115">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="tag(historyOldStatus(row), historyOldSeverity(row))">
                  {{ label(historyOldStatus(row), historyOldSeverity(row)) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="新状态" width="115">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="tag(historyStatus(row), historySeverity(row))">
                  {{ label(historyStatus(row), historySeverity(row)) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="可用库存快照" width="120" align="right">
              <template slot-scope="{ row }">{{ number(row.quantity_snapshot && row.quantity_snapshot.available) }} {{ detailUnit }}</template>
            </el-table-column>
            <el-table-column label="变化原因说明" min-width="240">
              <template slot-scope="{ row }">{{ historyReason(row) }}</template>
            </el-table-column>
          </el-table>
          <div v-if="historyTotal > historyPerPage" class="dialog-pager">
            <el-pagination
              background
              small
              layout="prev, pager, next"
              :total="historyTotal"
              :page-size="historyPerPage"
              :current-page="historyPage"
              @current-change="loadDetailHistory"
            />
          </div>
        </section>
      </div>
      <span slot="footer" class="dialog-footer">
        <el-button size="small" @click="detailVisible = false">关闭</el-button>
        <el-button v-if="detailItem.id" size="small" icon="el-icon-setting" @click="openConfigurationFromDetail">配置预警规则</el-button>
        <el-button v-if="!detailAlert.purchase_request_id && detailAlert.is_active" size="small" type="success" class="btn-theme-create" icon="el-icon-shopping-cart-2" @click="createRequestFromDetail">生成采购需求</el-button>
        <el-button v-else-if="detailAlert.purchase_request_id" size="small" type="success" class="btn-theme-create" @click="openPurchaseRequest(detailAlert)">查看采购需求</el-button>
      </span>
    </el-dialog>

    <!-- 预警规则配置弹窗 -->
    <el-dialog
      :title="configItem.id ? `库存预警规则配置 · ${configItem.item_code || ''} ${configItem.item_name || ''}` : '库存预警规则配置'"
      :visible.sync="configVisible"
      width="860px"
      top="6vh"
      custom-class="master-style-dialog"
      :close-on-click-modal="false"
    >
      <div v-loading="configLoading" class="config-dialog-body">
        <div class="config-item-bar">
          <div class="picker-col">
            <label>配置目标物料</label>
            <paged-record-select
              v-model="configTargetItemId"
              entity="items"
              :selected-rows="configItem.id ? [configItem] : []"
              title="选择库存物料"
              placeholder="请选择要配置预警规则的物料"
              @change="onConfigItemChange"
            />
          </div>
          <div v-if="configItem.id" class="item-meta-pills">
            <span>编码：<b>{{ configItem.item_code }}</b></span>
            <span>名称：<b>{{ configItem.item_name }}</b></span>
            <span>分类：<b>{{ (configItem.category && configItem.category.category_name) || '-' }}</b></span>
            <span>基本单位：<b>{{ configUnit }}</b></span>
          </div>
        </div>

        <template v-if="configItem.id">
          <section class="config-sec">
            <div class="sec-head">
              <div>
                <h4>公司默认预警阈值</h4>
                <small>未单独设置仓库覆盖规则时，默认按以下阈值判断库存水位</small>
              </div>
              <el-switch v-model="configForm.enabled" active-color="#07883f" active-text="启用预警" inactive-text="停用" />
            </div>
            <div class="threshold-form-grid">
              <label>
                <span class="req">最低库存（严重低库存线）</span>
                <el-input-number v-model="configForm.min_stock" :min="0" size="small" controls-position="right" />
                <em class="unit-suffix">{{ configUnit }}</em>
              </label>
              <label>
                <span class="req">安全库存（低库存预警线）</span>
                <el-input-number v-model="configForm.safety_stock" :min="0" size="small" controls-position="right" />
                <em class="unit-suffix">{{ configUnit }}</em>
              </label>
              <label>
                <span>最高库存（超储预警线）</span>
                <el-input-number v-model="configForm.max_stock" :min="0" size="small" controls-position="right" />
                <em class="unit-suffix">{{ configUnit }}</em>
              </label>
              <label>
                <span class="req">建议补货数量</span>
                <el-input-number v-model="configForm.suggested_replenishment_qty" :min="0" size="small" controls-position="right" />
                <em class="unit-suffix">{{ configUnit }}</em>
              </label>
            </div>
          </section>

          <section class="config-sec">
            <div class="sec-head">
              <div>
                <h4>分仓库覆盖规则（选填）</h4>
                <small>指定仓库优先使用仓库规则，未配置的仓库沿用公司默认阈值</small>
              </div>
              <el-button type="text" size="small" class="add-override-btn" icon="el-icon-plus" @click="addOverride">新增仓库规则</el-button>
            </div>
            <el-table :data="configOverrides" border size="mini" empty-text="暂未配置分仓库覆盖规则，全部仓库适用公司默认规则">
              <el-table-column label="适用仓库" min-width="165">
                <template slot-scope="{ row }">
                  <paged-record-select
                    v-model="row.warehouse_id"
                    entity="warehouses"
                    :selected-rows="row.warehouse ? [row.warehouse] : []"
                    title="选择仓库"
                    placeholder="请选择仓库"
                  />
                </template>
              </el-table-column>
              <el-table-column label="最低库存" min-width="115">
                <template slot-scope="{ row }">
                  <el-input-number v-model="row.min_stock" :min="0" size="mini" controls-position="right" />
                </template>
              </el-table-column>
              <el-table-column label="安全库存" min-width="115">
                <template slot-scope="{ row }">
                  <el-input-number v-model="row.safety_stock" :min="0" size="mini" controls-position="right" />
                </template>
              </el-table-column>
              <el-table-column label="最高库存" min-width="115">
                <template slot-scope="{ row }">
                  <el-input-number v-model="row.max_stock" :min="0" size="mini" controls-position="right" />
                </template>
              </el-table-column>
              <el-table-column label="建议补货" min-width="115">
                <template slot-scope="{ row }">
                  <el-input-number v-model="row.suggested_replenishment_qty" :min="0" size="mini" controls-position="right" />
                </template>
              </el-table-column>
              <el-table-column label="操作" width="68" align="center">
                <template slot-scope="{ $index }">
                  <el-button type="text" size="mini" class="danger-link" @click="configOverrides.splice($index, 1)">删除</el-button>
                </template>
              </el-table-column>
            </el-table>
          </section>

          <div class="rule-check-banner" :class="{ invalid: !configValid }">
            <i :class="configValid ? 'el-icon-circle-check' : 'el-icon-warning-outline'" />
            <span v-if="configValid">阈值校验通过：最低库存 ≤ 安全库存{{ configForm.max_stock !== null && configForm.max_stock !== '' ? ' ≤ 最高库存' : '' }}，保存后立即按真实可用库存评估预警状态。</span>
            <span v-else>请填写最低库存、安全库存与建议补货数量，并确保：最低库存 ≤ 安全库存 ≤ 最高库存（如填写）。</span>
          </div>
        </template>
        <el-empty v-else description="请先在上方选择需要配置库存预警规则的物料" :image-size="72" />
      </div>
      <span slot="footer" class="dialog-footer">
        <el-button size="small" @click="configVisible = false">取消</el-button>
        <el-button size="small" :disabled="!configItem.id" :loading="configSaving" @click="saveConfig(false)">保存草稿</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :disabled="!configItem.id" :loading="configSaving" @click="saveConfig(true)">保存并启用</el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { erpDateTime } from '../../../utils/dateTime'
import { listInventoryAlerts, getInventoryAlert, markInventoryAlertRead, createPurchaseRequestFromInventoryAlert, getInventoryAlertPolicy, saveInventoryAlertPolicy, activateInventoryAlertPolicy, disableInventoryAlertPolicy } from '@/api/erp/inventory'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'

const emptyConfigForm = () => ({
  enabled: true,
  min_stock: null,
  safety_stock: null,
  max_stock: null,
  suggested_replenishment_qty: null
})

export default {
  name: 'InventoryAlertWorkbench',
  components: { PagedRecordSelect },
  data: () => ({
    loading: false,
    rows: [],
    selected: [],
    page: 1,
    perPage: 10,
    total: 0,
    serverStats: { active: 0, out: 0, low: 0, over: 0 },
    filters: { alert_status: '', keyword: '', category_id: '', warehouse_id: '', is_active: 'true' },
    detailVisible: false,
    detailLoading: false,
    detailAlert: {},
    detailItem: {},
    detailWarehouse: {},
    detailEvaluation: {},
    histories: [],
    historyPage: 1,
    historyPerPage: 10,
    historyTotal: 0,
    configVisible: false,
    configLoading: false,
    configSaving: false,
    configTargetItemId: null,
    configItem: {},
    configForm: emptyConfigForm(),
    configOverrides: []
  }),
  computed: {
    stats() {
      return this.serverStats
    },
    detailUnit() {
      const u = this.detailItem.unit || {}
      return u.symbol || u.unit_name || 'PCS'
    },
    detailRequest() {
      return this.detailAlert.purchase_request || {}
    },
    detailTagType() {
      return this.tag(this.detailEvaluation.status || this.detailAlert.alert_status, this.detailEvaluation.severity || this.detailAlert.severity)
    },
    detailSeverityClass() {
      return this.detailTagType === 'danger' ? 'danger' : this.detailTagType === 'warning' ? 'warning' : 'safe'
    },
    detailStateLabel() {
      return this.label(this.detailEvaluation.status || this.detailAlert.alert_status, this.detailEvaluation.severity || this.detailAlert.severity)
    },
    detailStateBasis() {
      const e = this.detailEvaluation || {}
      const a = this.number(this.detailAlert.available_qty)
      if (e.basis === 'available_lte_zero') return `可用库存 ${a} ${this.detailUnit} ≤ 0`
      if (e.basis === 'available_lte_min') return `可用库存 ${a} ${this.detailUnit} ≤ 最低库存 ${this.number(this.detailAlert.min_stock_snapshot)} ${this.detailUnit}`
      if (e.basis === 'available_lte_safety') return `可用库存 ${a} ${this.detailUnit} ≤ 安全库存 ${this.number(this.detailAlert.safety_stock_snapshot)} ${this.detailUnit}`
      if (e.basis === 'available_gt_max') return `可用库存 ${a} ${this.detailUnit} ＞ 最高库存 ${this.number(this.detailAlert.max_stock_snapshot)} ${this.detailUnit}`
      return `可用库存 ${a} ${this.detailUnit} ＞ 安全库存 ${this.number(this.detailAlert.safety_stock_snapshot)} ${this.detailUnit}`
    },
    detailShortage() {
      return Math.max(0, Number(this.detailAlert.safety_stock_snapshot || 0) - Number(this.detailAlert.available_qty || 0)).toFixed(0)
    },
    detailPointerStyle() {
      const max = Math.max(1, Number(this.detailAlert.max_stock_snapshot || this.detailAlert.safety_stock_snapshot || 1))
      return { left: `${Math.min(100, Math.max(0, (Number(this.detailAlert.available_qty || 0) / max) * 100))}%` }
    },
    detailHandlingText() {
      if (this.detailAlert.purchase_request_id) {
        return `已生成采购需求 ${(this.detailRequest && this.detailRequest.request_no) || ''}，请按采购流程审核处理`
      }
      return `建议补货 ${this.number(this.detailAlert.suggested_replenishment_qty_snapshot)} ${this.detailUnit}，恢复至安全库存水位`
    },
    configUnit() {
      return (this.configItem.unit && (this.configItem.unit.symbol || this.configItem.unit.unit_name)) || 'PCS'
    },
    configValid() {
      const f = this.configForm
      const required = [f.min_stock, f.safety_stock, f.suggested_replenishment_qty].every(v => v !== null && v !== '' && Number(v) >= 0)
      const maxEmpty = f.max_stock === null || f.max_stock === '' || f.max_stock === undefined
      return required && Number(f.min_stock) <= Number(f.safety_stock) && (maxEmpty || Number(f.safety_stock) <= Number(f.max_stock))
    }
  },
  created() {
    this.load()
  },
  methods: {
    async load() {
      this.loading = true
      try {
        const r = await listInventoryAlerts({ ...this.filters, page: this.page, per_page: this.perPage })
        this.rows = r.data.data || []
        this.total = r.data.total || 0
        this.serverStats = r.data.stats || { active: 0, out: 0, low: 0, over: 0 }
        this.selected = []
      } catch (e) {
        this.$message.error(e.userMessage || '库存预警加载失败')
      } finally {
        this.loading = false
      }
    },
    search() {
      this.page = 1
      this.load()
    },
    reset() {
      this.filters = { alert_status: '', keyword: '', category_id: '', warehouse_id: '', is_active: 'true' }
      this.search()
    },
    filterByMetric(type) {
      if (type === 'active') {
        this.filters.alert_status = ''
        this.filters.is_active = 'true'
      } else {
        this.filters.alert_status = this.filters.alert_status === type ? '' : type
        this.filters.is_active = 'true'
      }
      this.search()
    },
    changePage(page) {
      this.page = page
      this.load()
    },
    changeSize(size) {
      this.perPage = size
      this.page = 1
      this.load()
    },
    rowUnit(row) {
      const u = (row && row.item && row.item.unit) || {}
      return u.symbol || u.unit_name || ''
    },
    rowShortage(row) {
      return Math.max(0, Number(row.safety_stock_snapshot || 0) - Number(row.available_qty || 0))
    },
    rowWaterPercent(row) {
      const max = Math.max(1, Number(row.max_stock_snapshot || row.safety_stock_snapshot || 1))
      return Math.min(100, Math.max(4, Math.round((Number(row.available_qty || 0) / max) * 100)))
    },
    availTextClass(status, severity) {
      if (status === 'out_of_stock' || severity === 'critical') return 'danger-num'
      if (status === 'low_stock') return 'warning-num'
      return 'safe-num'
    },
    barFillClass(status, severity) {
      if (status === 'out_of_stock' || severity === 'critical') return 'fill-danger'
      if (status === 'low_stock') return 'fill-warning'
      if (status === 'over_stock') return 'fill-info'
      return 'fill-safe'
    },
    tag(status, severity) {
      if (status === 'out_of_stock' || severity === 'critical') return 'danger'
      if (status === 'low_stock') return 'warning'
      if (status === 'over_stock') return 'info'
      return 'success'
    },
    label(status, severity) {
      if (!status) return '-'
      if (status === 'low_stock') return severity === 'critical' ? '低库存（严重）' : '低库存（一般）'
      return ({ out_of_stock: '缺货（严重）', over_stock: '超储', normal: '正常' })[status] || status
    },
    async viewDetail(row) {
      if (!row || !row.id) return
      this.detailVisible = true
      this.historyPage = 1
      await this.loadDetail(row.id, 1)
    },
    async loadDetail(alertId, historyPage = 1) {
      this.detailLoading = true
      this.historyPage = historyPage
      try {
        const r = await getInventoryAlert(alertId, { per_page: this.historyPerPage, history_page: this.historyPage })
        this.detailAlert = r.data.data || {}
        this.detailItem = this.detailAlert.item || {}
        this.detailWarehouse = this.detailAlert.warehouse || {}
        this.detailEvaluation = r.data.evaluation || {}
        const h = r.data.histories || {}
        this.histories = h.data || []
        this.historyTotal = h.total || 0
        this.historyPage = h.current_page || this.historyPage
        if (this.detailAlert.id && !this.detailAlert.is_read) {
          await markInventoryAlertRead(this.detailAlert.id)
          this.detailAlert.is_read = true
          const targetRow = this.rows.find(x => Number(x.id) === Number(this.detailAlert.id))
          if (targetRow) targetRow.is_read = true
          window.dispatchEvent(new Event('erp:inventory-alert-read'))
        }
      } catch (e) {
        this.$message.error(e.userMessage || '库存预警详情加载失败')
      } finally {
        this.detailLoading = false
      }
    },
    loadDetailHistory(page) {
      if (this.detailAlert.id) this.loadDetail(this.detailAlert.id, page)
    },
    openConfigurationFromDetail() {
      const itemId = this.detailItem.id || this.detailAlert.item_id
      this.detailVisible = false
      this.openConfiguration({ item_id: itemId, item: this.detailItem })
    },
    async createRequestFromDetail() {
      if (!this.detailAlert.id) return
      await this.createRequestFor(this.detailAlert)
    },
    async openConfiguration(row) {
      let targetItemId = row && row.item_id ? row.item_id : null
      if (!targetItemId && this.selected.length === 1) {
        targetItemId = this.selected[0].item_id
      }
      this.configVisible = true
      this.configTargetItemId = targetItemId || null
      this.configItem = (row && row.item) || (this.selected.length === 1 && this.selected[0].item) || {}
      this.configForm = emptyConfigForm()
      this.configOverrides = []
      if (targetItemId) {
        await this.loadConfigPolicy(targetItemId)
      }
    },
    async onConfigItemChange(itemId) {
      if (!itemId) {
        this.configItem = {}
        this.configForm = emptyConfigForm()
        this.configOverrides = []
        return
      }
      await this.loadConfigPolicy(itemId)
    },
    async loadConfigPolicy(itemId) {
      this.configLoading = true
      try {
        const r = await getInventoryAlertPolicy(itemId)
        this.configItem = r.data.item || {}
        this.configTargetItemId = this.configItem.id || itemId
        const policies = r.data.policies || []
        const company = policies.find(x => x.scope_key === 'company')
        this.configForm = company ? { ...emptyConfigForm(), ...company, enabled: Boolean(company.is_enabled) } : emptyConfigForm()
        this.configOverrides = policies.filter(x => x.scope_key !== 'company').map(x => ({ ...x, enabled: Boolean(x.is_enabled) }))
      } catch (e) {
        this.$message.error(e.userMessage || '预警规则加载失败')
      } finally {
        this.configLoading = false
      }
    },
    addOverride() {
      this.configOverrides.push({ ...emptyConfigForm(), warehouse_id: null })
    },
    async saveConfig(activate) {
      if (!this.configItem.id) return
      if (!this.configValid) {
        return this.$message.error('最低库存、安全库存、建议补货数量为必填；最高库存为空或不小于安全库存。')
      }
      this.configSaving = true
      try {
        if (activate && !this.configForm.enabled) {
          await disableInventoryAlertPolicy(this.configItem.id)
          this.$message.success('库存预警规则已停用')
        } else {
          const fn = activate ? activateInventoryAlertPolicy : saveInventoryAlertPolicy
          await fn(this.configItem.id, { ...this.configForm, enabled: activate ? true : this.configForm.enabled })
          for (const row of this.configOverrides.filter(x => x.warehouse_id)) {
            await fn(this.configItem.id, { ...row, enabled: true })
          }
          this.$message.success(activate ? '库存预警规则已保存并启用' : '库存预警规则草稿已保存')
        }
        this.configVisible = false
        await this.load()
      } catch (e) {
        this.$message.error(e.userMessage || '保存预警规则失败')
      } finally {
        this.configSaving = false
      }
    },
    async createRequestFor(row) {
      try {
        const result = await createPurchaseRequestFromInventoryAlert(row.id)
        const request = result.data.data
        this.$message.success('已生成采购需求草稿')
        this.detailVisible = false
        this.$router.push(`/purchase/requests/${request.id}/edit`)
      } catch (e) {
        this.$message.error(e.userMessage || '生成采购需求失败')
      }
    },
    async createRequest() {
      if (!this.selected.length) return
      try {
        const result = await Promise.all(this.selected.map(row => createPurchaseRequestFromInventoryAlert(row.id)))
        const request = result[0].data.data
        this.$message.success(`已生成 ${result.length} 条采购需求草稿`)
        this.$router.push(`/purchase/requests/${request.id}/edit`)
      } catch (e) {
        this.$message.error(e.userMessage || '生成采购需求失败')
      }
    },
    openPurchaseRequest(row) {
      if (row && row.purchase_request_id) {
        this.detailVisible = false
        this.$router.push(`/purchase/requests/${row.purchase_request_id}/edit`)
      }
    },
    number(v) {
      return Number(v || 0).toLocaleString('zh-CN', { maximumFractionDigits: 6 })
    },
    date(value) {
      return value ? erpDateTime(value, 19) : '-'
    },
    historyOldStatus(row) {
      return row.display_old_status === undefined ? row.old_status : row.display_old_status
    },
    historyOldSeverity(row) {
      return row.display_old_severity === undefined ? row.old_severity : row.display_old_severity
    },
    historyStatus(row) {
      return (row.evaluation && row.evaluation.status) || row.new_status
    },
    historySeverity(row) {
      return (row.evaluation && row.evaluation.severity) || row.new_severity
    },
    historyReason(row) {
      const s = (row.quantity_snapshot || {}).available
      const status = this.historyStatus(row)
      const severity = this.historySeverity(row)
      if (status === 'out_of_stock') return `可用库存 ${this.number(s)} ${this.detailUnit}，已无可用库存。`
      if (status === 'low_stock' && severity === 'critical') return `可用库存 ${this.number(s)} ${this.detailUnit} ≤ 最低库存 ${this.number(this.detailAlert.min_stock_snapshot)} ${this.detailUnit}。`
      if (status === 'low_stock') return `可用库存 ${this.number(s)} ${this.detailUnit} 低于安全库存 ${this.number(this.detailAlert.safety_stock_snapshot)} ${this.detailUnit}。`
      if (status === 'normal') return `可用库存 ${this.number(s)} ${this.detailUnit} 已恢复至安全库存以上。`
      if (status === 'over_stock') return `可用库存 ${this.number(s)} ${this.detailUnit} ＞ 最高库存 ${this.number(this.detailAlert.max_stock_snapshot)} ${this.detailUnit}。`
      return row.change_reason || '-'
    }
  }
}
</script>

<style scoped>
.alert-workbench { min-height: calc(100vh - 52px); padding: 16px 20px 28px; background: #f6f8fa; color: #1f2937; box-sizing: border-box; font-size: 13px; }
.page-head { display: flex; justify-content: space-between; align-items: center; gap: 16px; padding: 16px 20px; background: #ffffff; border: 1px solid #e2e8f0; border-radius: 8px; margin-bottom: 14px; box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03); }
.head-left { display: flex; align-items: center; gap: 12px; }
.head-icon { width: 38px; height: 38px; border-radius: 8px; background: #f0fdf4; color: #008b4b; display: flex; align-items: center; justify-content: center; font-size: 20px; flex-shrink: 0; }
.head-title-wrap { display: flex; flex-direction: column; min-width: 0; }
.title-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.page-title { margin: 0; font-size: 18px; font-weight: 700; color: #0f172a; }
.head-tag, .total-tag, .sub-total-tag { border-radius: 4px; font-weight: 500; }
.head-actions { display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
.btn-refresh { border-color: #cbd5e1 !important; color: #475569 !important; }
.btn-refresh:hover { border-color: #008b4b !important; color: #008b4b !important; }
.btn-theme-create { background: #008b4b !important; border-color: #008b4b !important; color: #fff !important; }
.btn-theme-create:hover, .btn-theme-create:focus { background: #00753f !important; border-color: #00753f !important; color: #fff !important; }
.erp-page-tip { display: flex; align-items: center; gap: 8px; padding: 10px 14px; margin-bottom: 12px; background: #f0f9f4; border: 1px solid #cce8d8; border-radius: 8px; color: #1f4d36; font-size: 13px; }
.erp-page-tip i { color: #008b4b; font-size: 16px; flex-shrink: 0; }
.metric-overview-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; margin-bottom: 12px; }
.metric-card { display: flex; align-items: center; gap: 12px; padding: 14px 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; cursor: pointer; transition: all .18s ease; }
.metric-card:hover { border-color: #86efac; box-shadow: 0 4px 12px rgba(15, 23, 42, .05); }
.metric-card.active { border-color: #008b4b; background: #f3fbf6; box-shadow: 0 0 0 1px #008b4b inset; }
.metric-icon { width: 42px; height: 42px; border-radius: 10px; display: grid; place-items: center; font-size: 20px; flex-shrink: 0; }
.metric-icon.warning { color: #d97706; background: #fef3c7; }
.metric-icon.danger { color: #dc2626; background: #fee2e2; }
.metric-icon.amber { color: #f59e0b; background: #fffbeb; }
.metric-icon.primary { color: #008b4b; background: #e8f7ee; }
.metric-copy { display: flex; flex-direction: column; min-width: 0; }
.metric-copy span { color: #6b7280; font-size: 12px; }
.metric-copy strong { font-size: 22px; line-height: 1.25; color: #111827; font-weight: 700; margin: 2px 0; }
.metric-copy small { color: #9ca3af; font-size: 11px; }
.danger-num { color: #dc2626 !important; }
.warning-num { color: #d97706 !important; }
.safe-num { color: #008b4b !important; }

.filter-card, .table-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 10px; margin-bottom: 12px; }
.filter-card { padding: 12px 16px; }
.filter-form { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; }
.filter-form .el-form-item { margin-bottom: 0; margin-right: 0; }
.filter-form .el-input, .filter-form .el-select, .filter-form .paged-record-select { width: 170px; }
.filter-btns { display: flex; gap: 8px; }

.table-card { overflow: hidden; }
.table-toolbar { display: flex; justify-content: space-between; align-items: center; padding: 10px 16px; border-bottom: 1px solid #eef2f6; background: #fbfcfd; font-size: 12px; color: #4b5563; }
.toolbar-summary b { color: #008b4b; }
.toolbar-hint { color: #9ca3af; }
.alert-table { cursor: pointer; width: 100%; }
.table-card >>> .el-table th { background: #f8fafc; color: #475569; font-weight: 600; }
.item-cell { display: flex; flex-direction: column; gap: 2px; }
.item-top { display: flex; align-items: center; gap: 6px; }
.code-link { padding: 0; font-weight: 700; color: #008b4b; font-family: monospace; }
.unread-dot { width: 7px; height: 7px; border-radius: 50%; background: #ef4444; display: inline-block; }
.item-name { font-weight: 600; color: #111827; line-height: 1.35; }
.item-sub { font-size: 11px; color: #6b7280; }
.wh-cell { display: flex; flex-direction: column; gap: 2px; }
.wh-cell strong { color: #1f2937; font-weight: 600; }
.wh-cell small { color: #9ca3af; font-family: monospace; font-size: 11px; }
.stock-cell { display: flex; flex-direction: column; gap: 3px; }
.avail-line { display: flex; align-items: baseline; gap: 6px; }
.avail-line span { color: #6b7280; font-size: 12px; }
.avail-line strong { font-size: 14px; font-weight: 700; }
.sub-stock-line { color: #6b7280; font-size: 11px; }
.threshold-cell { display: flex; flex-direction: column; gap: 5px; }
.threshold-nums { display: flex; flex-wrap: wrap; gap: 8px; font-size: 12px; color: #6b7280; }
.threshold-nums b { color: #1f2937; }
.water-bar { height: 6px; background: #eef2f6; border-radius: 999px; overflow: hidden; }
.water-bar i { display: block; height: 100%; border-radius: 999px; }
.fill-danger { background: #ef4444; }
.fill-warning { background: #f59e0b; }
.fill-info { background: #3b82f6; }
.fill-safe { background: #10b981; }
.replenish-num { color: #111827; font-size: 13px; }
.shortage-sub { font-size: 11px; color: #dc2626; }
.status-cell { display: flex; flex-direction: column; align-items: flex-start; gap: 4px; }
.time-sub { font-size: 11px; color: #9ca3af; }
.op-links { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.op-link { padding: 0; color: #008b4b; font-size: 12px; }
.op-link.primary { font-weight: 600; }
.pager { display: flex; align-items: center; justify-content: space-between; padding: 12px 16px; color: #6b7280; font-size: 12px; }

/* 详情弹窗 */
.detail-dialog-body { display: flex; flex-direction: column; gap: 12px; }
.detail-summary-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; }
.summary-box { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; background: #fbfcfd; }
.summary-box h4 { margin: 0 0 10px; font-size: 13px; color: #111827; border-bottom: 1px solid #eef2f6; padding-bottom: 6px; }
.summary-box dl { display: grid; grid-template-columns: 78px 1fr; gap: 6px 8px; margin: 0; font-size: 12px; }
.summary-box dt { color: #6b7280; }
.summary-box dd { margin: 0; color: #111827; font-weight: 600; overflow-wrap: anywhere; }
.facts-split { display: grid; grid-template-columns: 1fr 110px; gap: 8px; align-items: center; }
.avail-highlight { border-left: 1px dashed #cbd5e1; padding-left: 10px; text-align: center; display: flex; flex-direction: column; gap: 4px; }
.avail-highlight span { font-size: 11px; color: #6b7280; }
.avail-highlight strong { font-size: 18px; line-height: 1.2; }
.avail-highlight .danger { color: #dc2626; }
.avail-highlight .warning { color: #d97706; }
.avail-highlight .safe { color: #008b4b; }
.muted-txt { color: #9ca3af; font-weight: 400; }
.threshold-panel, .history-panel { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; background: #fff; }
.panel-title-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; margin-bottom: 10px; }
.panel-title-row h4, .history-panel h4 { margin: 0; font-size: 13px; color: #111827; }
.panel-title-row span { font-size: 12px; color: #008b4b; }
.threshold-kpi-row { display: grid; grid-template-columns: repeat(6, minmax(0, 1fr)); gap: 8px; margin-bottom: 12px; }
.threshold-kpi-row > div { background: #f8fafc; border: 1px solid #eef2f6; border-radius: 6px; padding: 8px 10px; display: flex; flex-direction: column; gap: 4px; }
.threshold-kpi-row span { font-size: 11px; color: #6b7280; }
.threshold-kpi-row b { font-size: 13px; color: #111827; }
.threshold-kpi-row b.safe { color: #008b4b; }
.threshold-kpi-row b.danger { color: #dc2626; }
.threshold-kpi-row b.primary { color: #0284c7; }
.scale-track { position: relative; padding-top: 8px; }
.scale-track > i { display: inline-block; height: 6px; }
.red-zone { width: 25%; background: #ef4444; border-radius: 3px 0 0 3px; }
.orange-zone { width: 20%; background: #f59e0b; }
.green-zone { width: 55%; background: #10b981; border-radius: 0 3px 3px 0; }
.scale-track em { position: absolute; top: -2px; width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 8px solid #111827; transform: translateX(-50%); }
.scale-labels { display: flex; justify-content: space-between; font-size: 11px; color: #6b7280; margin-top: 6px; }
.history-panel h4 { margin-bottom: 8px; }
.dialog-pager { padding-top: 8px; text-align: right; }

/* 配置弹窗 */
.config-dialog-body { display: flex; flex-direction: column; gap: 14px; }
.config-item-bar { display: flex; flex-wrap: wrap; align-items: flex-end; justify-content: space-between; gap: 12px; padding: 12px 14px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 8px; }
.picker-col { display: flex; flex-direction: column; gap: 6px; min-width: 280px; }
.picker-col label { font-size: 12px; font-weight: 600; color: #374151; }
.item-meta-pills { display: flex; flex-wrap: wrap; gap: 8px 16px; font-size: 12px; color: #4b5563; }
.item-meta-pills b { color: #111827; }
.config-sec { border: 1px solid #e5e7eb; border-radius: 8px; padding: 12px 14px; background: #fff; }
.sec-head { display: flex; justify-content: space-between; align-items: center; gap: 12px; margin-bottom: 12px; }
.sec-head h4 { margin: 0 0 2px; font-size: 14px; color: #111827; }
.sec-head small { color: #6b7280; font-size: 12px; }
.threshold-form-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 12px; }
.threshold-form-grid label { position: relative; display: flex; flex-direction: column; gap: 6px; font-size: 12px; color: #374151; }
.threshold-form-grid .req::after { content: ' *'; color: #ef4444; }
.threshold-form-grid .el-input-number { width: 100%; }
.unit-suffix { position: absolute; right: 34px; bottom: 7px; font-style: normal; font-size: 11px; color: #9ca3af; pointer-events: none; }
.add-override-btn { color: #008b4b; font-weight: 600; }
.danger-link { color: #dc2626; }
.rule-check-banner { display: flex; align-items: center; gap: 8px; padding: 10px 12px; border-radius: 6px; background: #f0f9f4; border: 1px solid #cce8d8; color: #1f4d36; font-size: 12px; }
.rule-check-banner.invalid { background: #fff7ed; border-color: #fed7aa; color: #9a3412; }

@media (max-width: 1100px) {
  .metric-overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .detail-summary-grid { grid-template-columns: 1fr; }
  .threshold-kpi-row { grid-template-columns: repeat(3, minmax(0, 1fr)); }
  .threshold-form-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 720px) {
  .alert-workbench { padding: 12px; }
  .page-head { flex-direction: column; }
  .metric-overview-grid { grid-template-columns: 1fr; }
}
</style>
