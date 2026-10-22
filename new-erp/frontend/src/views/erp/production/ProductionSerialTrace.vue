<template>
  <div class="serial-trace-page" v-loading="loading">
    <!-- 页面全局头部卡片：白底圆角独立卡片 (100% 对齐主数据中心 / SkuList.vue 风格) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-search" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">序列号全生命周期追溯</h1>
            <span v-if="result && result.serial_no" class="code-mono sku-code-tag">
              <i class="el-icon-postcard" />
              {{ result.serial_no }}
            </span>
            <el-tag
              v-if="result"
              size="small"
              :type="unitStatusType(result.status)"
              effect="plain"
              class="head-status-tag"
            >
              {{ result.status_label || result.status }}
            </el-tag>
            <el-tag
              v-if="result && result.current_operation_name"
              size="small"
              type="warning"
              effect="plain"
              class="head-op-tag"
            >
              当前工序：{{ result.current_operation_name }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button
          size="small"
          icon="el-icon-refresh"
          class="btn-refresh"
          :loading="loading"
          @click="refresh"
        >
          刷新追溯
        </el-button>
        <el-button
          v-if="result && result.work_order"
          size="small"
          type="primary"
          plain
          icon="el-icon-document"
          class="btn-action-light"
          @click="openWo"
        >
          查看关联工单
        </el-button>
        <el-button
          v-if="result && result.sales_order"
          size="small"
          type="success"
          plain
          icon="el-icon-tickets"
          class="btn-action-light"
          @click="openSo"
        >
          查看销售订单
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面业务提示条 (对齐主数据中心规范) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>输入或扫描设备序列号（一机一码），全流程穿透查询生产制令主数据、工序过站履历、关键装配件批次及交付流向，实现全生命周期精准溯源。</span>
    </div>

    <!-- 检索与查询卡片 (对齐主数据中心 table-container-card 规范) -->
    <section class="table-container-card search-card">
      <div class="filter-toolbar">
        <div class="filter-search-group">
          <el-input
            v-model="keyword"
            size="small"
            clearable
            prefix-icon="el-icon-search"
            placeholder="输入产品序列号或使用扫码枪扫描录入，按回车检索（例如 SN2610-0015）..."
            class="filter-input-search font-mono"
            @keyup.enter.native="search"
            @clear="onClearSearch"
          />
          <el-button
            size="small"
            type="success"
            icon="el-icon-search"
            class="btn-theme-create"
            :loading="loading"
            @click="search"
          >
            精准检索
          </el-button>
          <el-button
            size="small"
            icon="el-icon-refresh-left"
            class="btn-reset"
            @click="reset"
          >
            重置
          </el-button>
        </div>
        <div v-if="result" class="filter-summary-info">
          <span class="summary-item">制令工单：<b class="code-mono lk-text" @click="openWo">{{ result.work_order.work_order_no }}</b></span>
          <span class="summary-divider">|</span>
          <span class="summary-item">制造产品：<b>{{ result.work_order.product_name || result.work_order.item_name }}</b></span>
          <template v-if="result.matched_component_serial_no">
            <span class="summary-divider">|</span>
            <span class="summary-item">部件 <b class="code-mono">{{ result.matched_component_serial_no }}</b> 装在本机</span>
          </template>
          <template v-if="result.installed_in_serial_no">
            <span class="summary-divider">|</span>
            <span class="summary-item">已装入整机：<b class="code-mono lk-text" @click="openSerialNo(result.installed_in_serial_no)">{{ result.installed_in_serial_no }}</b></span>
          </template>
        </div>
      </div>

      <!-- 快捷检索建议条 -->
      <div v-if="suggestions.length" class="suggestions-bar">
        <span class="sugg-label"><i class="el-icon-magic-stick" /> 建议匹配序列号：</span>
        <div class="sugg-chips">
          <span
            v-for="s in suggestions"
            :key="s"
            class="sugg-chip code-mono"
            @click="selectSuggestion(s)"
          >
            {{ s }}
          </span>
        </div>
      </div>
    </section>

    <!-- 顶部概览指标卡片 (仅在查询到有效序列号时展示，对齐主数据中心 metric-overview-grid 风格) -->
    <section v-if="result" class="metric-overview-grid">
      <div class="metric-card metric-product">
        <div class="metric-icon-box"><i class="el-icon-goods" /></div>
        <div class="metric-info">
          <span class="metric-label">制造产品 / SKU 编码</span>
          <strong class="metric-val text-truncate" :title="result.work_order.product_name || result.work_order.item_name">
            {{ result.work_order.product_name || result.work_order.item_name }}
          </strong>
          <span class="metric-sub code-mono">{{ result.work_order.sku_code || '标准制造件' }}</span>
        </div>
      </div>

      <div class="metric-card metric-status">
        <div class="metric-icon-box"><i class="el-icon-timer" /></div>
        <div class="metric-info">
          <span class="metric-label">当前状态 / 所在工序</span>
          <strong class="metric-val">
            {{ result.status_label || result.status }}
          </strong>
          <span class="metric-sub">
            {{ result.current_operation_name ? '当前工序：' + result.current_operation_name : '制程正常流转中' }}
          </span>
        </div>
      </div>

      <div class="metric-card metric-workorder" @click="openWo">
        <div class="metric-icon-box"><i class="el-icon-s-order" /></div>
        <div class="metric-info">
          <span class="metric-label">生产制令工单</span>
          <strong class="metric-val code-mono lk-text" :title="result.work_order.work_order_no">
            {{ result.work_order.work_order_no }}
          </strong>
          <span class="metric-sub">工单状态：{{ result.work_order.status_label || result.work_order.status }}</span>
        </div>
      </div>

      <div class="metric-card metric-source">
        <div class="metric-icon-box"><i class="el-icon-truck" /></div>
        <div class="metric-info">
          <span class="metric-label">业务流向 / 客户</span>
          <strong class="metric-val text-truncate" :title="result.sales_order ? result.sales_order.sales_order_no : '计划备货'">
            {{ result.sales_order ? result.sales_order.sales_order_no : '常规库存备货' }}
          </strong>
          <span class="metric-sub text-truncate">
            {{ result.sales_order ? result.sales_order.customer_name : '生产入库' }}
          </span>
        </div>
      </div>
    </section>

    <!-- 追溯主体双栏工作区 (对齐 SkuDetail.vue 的 content-grid / main-column / side-column) -->
    <main v-if="result" class="content-grid">
      <!-- 左侧主体列：全生命周期履历与关键部件追溯 -->
      <div class="main-column">
        <!-- 卡片 1：生产与流转全生命周期履历 -->
        <section class="detail-card">
          <div class="card-header">
            <div class="header-title">
              <i class="el-icon-time" />
              <h2>生产与流转全生命周期履历</h2>
            </div>
            <span class="header-desc-hint">涵盖制令下达、工序报工、返修检验至仓配发运全节点事实</span>
          </div>

          <div class="timeline-box">
            <!-- 已发生事件节点 -->
            <div
              v-for="(e, i) in result.events"
              :key="i"
              :class="['timeline-node', dotClass(e.action)]"
            >
              <div class="node-axis">
                <span class="node-dot" />
                <span class="node-line" />
              </div>
              <div class="node-card">
                <div class="node-meta-row">
                  <span class="node-time code-mono">{{ e.created_at || '—' }}</span>
                  <el-tag size="mini" :type="actionTagType(e.action)" effect="plain" class="node-action-tag">
                    {{ actionTagText(e.action) }}
                  </el-tag>
                  <span class="node-operator">
                    <i class="el-icon-user" /> {{ e.operator_name || '系统自动' }}
                  </span>
                </div>
                <div class="node-content-text">{{ e.content }}</div>
              </div>
            </div>

            <!-- 待执行生产工序 -->
            <div v-if="pendingOps.length" class="timeline-node node-future">
              <div class="node-axis">
                <span class="node-dot dot-future" />
                <span v-if="shipOps.length" class="node-line line-dashed" />
              </div>
              <div class="node-card card-future">
                <div class="future-head">
                  <i class="el-icon-video-play text-warning" />
                  <strong>待执行生产工序</strong>
                  <span class="future-tip">（后续车间报工过站）</span>
                </div>
                <div class="op-step-chips">
                  <span v-for="(op, idx) in pendingOps" :key="idx" class="op-chip">
                    <b class="chip-idx">{{ idx + 1 }}</b>
                    {{ op }}
                  </span>
                </div>
              </div>
            </div>

            <!-- 待执行仓配与发运 -->
            <div v-if="shipOps.length" class="timeline-node node-future">
              <div class="node-axis">
                <span class="node-dot dot-future" />
                <span class="node-line line-dashed" />
              </div>
              <div class="node-card card-future">
                <div class="future-head">
                  <i class="el-icon-truck text-primary" />
                  <strong>待执行仓配与发运</strong>
                  <span class="future-tip">（包装、发运及出库交接）</span>
                </div>
                <div class="op-step-chips">
                  <span v-for="(op, idx) in shipOps" :key="idx" class="op-chip chip-ship">
                    <i class="el-icon-check" />
                    {{ op }}
                  </span>
                </div>
              </div>
            </div>

            <!-- 售后维保服务档案接入 -->
            <div class="timeline-node node-service">
              <div class="node-axis">
                <span class="node-dot dot-service" />
              </div>
              <div class="node-card card-service">
                <div class="future-head">
                  <i class="el-icon-service text-info" />
                  <strong>售后维保与技术服务记录</strong>
                </div>
                <div class="service-desc-text">设备出库交付后，相关客户维保服务单、故障维修记录及备件更换事实将在此统一归集。</div>
              </div>
            </div>
          </div>
        </section>

        <!-- 卡片 2：关键部件与物料装配追溯 -->
        <section class="detail-card">
          <div class="card-header">
            <div class="header-title">
              <i class="el-icon-connection" />
              <h2>关键部件与物料装配追溯</h2>
            </div>
            <el-tag size="mini" type="info" effect="plain">
              已登记 {{ result.components ? result.components.length : 0 }} 项关键装配件
            </el-tag>
          </div>

          <div class="table-wrap">
            <el-table
              v-if="result.components && result.components.length"
              :data="result.components"
              size="small"
              border
              class="components-table"
            >
              <el-table-column prop="component_item_name" label="部件名称" min-width="140" show-overflow-tooltip />
              <el-table-column prop="component_spec" label="规格型号" min-width="130" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span>{{ row.component_spec || '—' }}</span>
                </template>
              </el-table-column>
              <el-table-column label="部件序列号 / 批次号" min-width="180" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span v-if="row.component_serial_no" class="code-mono font-medium text-success">
                    <i class="el-icon-postcard" /> {{ row.component_serial_no }}
                  </span>
                  <span v-else-if="row.batch_no" class="code-mono text-muted">
                    批次号：{{ row.batch_no }}
                  </span>
                  <span v-else class="text-muted">—</span>
                </template>
              </el-table-column>
              <el-table-column label="装配责任人" width="120" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span>{{ row.installed_by_name || '—' }}</span>
                </template>
              </el-table-column>
              <el-table-column label="装配时间" width="160" align="center">
                <template slot-scope="{ row }">
                  <span class="code-mono text-muted">{{ row.installed_at || '—' }}</span>
                </template>
              </el-table-column>
            </el-table>

            <div v-else class="empty-components-notice">
              <i class="el-icon-box" />
              <span>暂无关键部件装配追溯记录（总装工序收料时按台登记带编号的部件）</span>
            </div>
            <div v-if="result.removed_components && result.removed_components.length">
              <p v-for="(r, i) in result.removed_components" :key="i" class="text-muted">
                已拆下：{{ r.component_item_name }} {{ r.component_serial_no }}（{{ r.removed_at }}，{{ r.remark || r.reason }}）
              </p>
            </div>
          </div>
        </section>
      </div>

      <!-- 右侧侧边栏：技术规格与制令档案、业务协同快捷操作 -->
      <aside class="side-column">
        <!-- 卡片 3：整机技术规格与制令档案 -->
        <section class="detail-card">
          <div class="card-header">
            <div class="header-title">
              <i class="el-icon-postcard" />
              <h2>整机技术规格与制令档案</h2>
            </div>
          </div>

          <div class="spec-kv-list">
            <div class="spec-kv-row">
              <span class="kv-label">设备序列号 (SN)</span>
              <span class="kv-val code-mono highlight-sn">
                {{ result.serial_no }}
                <el-tooltip content="点击复制序列号" placement="top">
                  <i class="el-icon-document-copy copy-icon" @click="copyText(result.serial_no, '序列号')" />
                </el-tooltip>
              </span>
            </div>
            <div class="spec-kv-row">
              <span class="kv-label">制造产品</span>
              <span class="kv-val font-medium">{{ result.work_order.product_name || result.work_order.item_name || '—' }}</span>
            </div>
            <div class="spec-kv-row">
              <span class="kv-label">SKU 规格编码</span>
              <span class="kv-val code-mono">{{ result.work_order.sku_code || '—' }}</span>
            </div>
            <div v-if="result.work_order.sku_name" class="spec-kv-row">
              <span class="kv-label">规格名称</span>
              <span class="kv-val">{{ result.work_order.sku_name }}</span>
            </div>
            <div class="spec-kv-row">
              <span class="kv-label">定制与配置要求</span>
              <span class="kv-val" :class="{ 'text-muted': !result.work_order.special_requirement }">
                {{ result.work_order.special_requirement || '标准配置（无特殊定制要求）' }}
              </span>
            </div>
            <div class="spec-kv-row">
              <span class="kv-label">制造 BOM / 路线</span>
              <span class="kv-val">
                {{ result.work_order.bom_no ? bomLabel(result.work_order.bom_name, result.work_order.bom_no, result.work_order.bom_version) : '—' }}
                <span v-if="result.work_order.routing_name" class="routing-badge">
                  {{ result.work_order.routing_name }} V{{ result.work_order.routing_version }}
                </span>
              </span>
            </div>
            <div class="spec-kv-row">
              <span class="kv-label">质量返工记录</span>
              <span class="kv-val">
                <span v-if="result.rework_count > 0" class="rework-tag-warn">
                  <i class="el-icon-warning" /> 累计返修 {{ result.rework_count }} 次
                </span>
                <span v-else class="rework-tag-zero">
                  <i class="el-icon-circle-check" /> 0 次（全流程一次合格）
                </span>
              </span>
            </div>
            <div class="spec-kv-row">
              <span class="kv-label">生产制令状态</span>
              <span class="kv-val">
                <el-tag size="mini" effect="plain" type="info">{{ result.work_order.status_label || result.work_order.status }}</el-tag>
              </span>
            </div>
            <div class="spec-kv-row">
              <span class="kv-label">关联销售订单</span>
              <span class="kv-val">
                <template v-if="result.sales_order">
                  <a class="table-link font-medium code-mono" @click="openSo">
                    {{ result.sales_order.sales_order_no }}
                  </a>
                  <div class="customer-sub">{{ result.sales_order.customer_name }}</div>
                </template>
                <span v-else class="text-muted">常规库存备货（无销售订单）</span>
              </span>
            </div>
          </div>
        </section>

        <!-- 卡片 4：业务协同与快捷操作 -->
        <section class="detail-card">
          <div class="card-header">
            <div class="header-title">
              <i class="el-icon-s-operation" />
              <h2>业务协同与快捷操作</h2>
            </div>
          </div>

          <div class="action-btn-group">
            <el-button
              v-if="result.work_order && result.work_order.id"
              size="small"
              type="primary"
              plain
              icon="el-icon-document"
              class="action-block-btn"
              @click="openWo"
            >
              查看生产制令工单
            </el-button>
            <el-button
              v-if="result.sales_order && result.sales_order.id"
              size="small"
              type="success"
              plain
              icon="el-icon-tickets"
              class="action-block-btn"
              @click="openSo"
            >
              查看关联销售订单
            </el-button>
            <el-button
              size="small"
              icon="el-icon-document-copy"
              class="action-block-btn"
              @click="copyText(result.serial_no, '序列号')"
            >
              复制设备序列号
            </el-button>
            <el-button
              size="small"
              icon="el-icon-link"
              class="action-block-btn"
              @click="copyTraceLink"
            >
              复制此档案追溯链接
            </el-button>
          </div>
        </section>
      </aside>
    </main>

    <!-- 空状态卡片：未查询或未找到结果时展示 -->
    <section v-else class="table-container-card empty-card-wrap">
      <div class="empty-state-inner">
        <div class="empty-icon-box">
          <i :class="message ? 'el-icon-warning-outline text-warning' : 'el-icon-search text-muted'" />
        </div>
        <h3 class="empty-title">{{ message ? '未检索到有效档案' : '请输入设备序列号进行档案追溯' }}</h3>
        <p class="empty-desc">
          {{ message || '支持使用扫码枪快速扫描条码，或手动输入整机序列号（如 SN2610-0015），全流程穿透查询制令工单、工序过站履历与装配件溯源档案。' }}
        </p>

        <!-- 推荐序列号匹配 -->
        <div v-if="suggestions.length" class="empty-suggestions-panel">
          <div class="sugg-header">
            <i class="el-icon-magic-stick" />
            <span>推荐匹配的设备序列号（点击直接查询）：</span>
          </div>
          <div class="sugg-chips-grid">
            <span
              v-for="s in suggestions"
              :key="s"
              class="suggestion-pill code-mono"
              @click="selectSuggestion(s)"
            >
              <i class="el-icon-postcard" />
              {{ s }}
            </span>
          </div>
        </div>
      </div>
    </section>
  </div>
</template>

<script>
import { lookupSerial } from '../../../api/erp/production'
import { bomLabel, errorText } from './mfg-helpers'

export default {
  name: 'ProductionSerialTrace',
  data: () => ({
    keyword: '',
    loading: false,
    result: null,
    message: '',
    suggestions: []
  }),
  computed: {
    pendingOps() {
      if (!this.result || ['stored', 'completed', 'scrapped', 'cancelled'].includes(this.result.status)) return []
      const ops = this.result.operations.filter(o => o.execution_context === 'production').map(o => o.operation_name)
      const cur = ops.indexOf(this.result.current_operation_name)
      return cur >= 0 ? ops.slice(cur) : ops
    },
    shipOps() {
      if (!this.result || ['scrapped', 'cancelled'].includes(this.result.status)) return []
      return this.result.operations.filter(o => o.execution_context !== 'production').map(o => o.operation_name)
    }
  },
  watch: {
    '$route.query.sn'(v) {
      const sn = (v || '').trim()
      if (sn && sn !== this.keyword) {
        this.keyword = sn
        this.search()
      }
    }
  },
  created() {
    if (this.$route.query.sn) {
      this.keyword = String(this.$route.query.sn).trim()
      this.search()
    }
  },
  methods: {
    bomLabel,
    async search() {
      const sn = (this.keyword || '').trim()
      if (!sn) return this.$message.warning('请输入或扫描设备序列号')
      this.loading = true
      try {
        const { data } = await lookupSerial(sn)
        this.result = data.data
        this.message = data.data ? '' : (data.message || '未检索到匹配的序列号档案，请核对输入是否准确。')
        this.suggestions = data.suggestions || []
        if (this.$route.query.sn !== sn) {
          this.$router.replace({ query: { sn } }).catch(() => {})
        }
      } catch (e) {
        this.result = null
        this.message = errorText(e, '序列号档案查询失败，请稍后重试')
      } finally {
        this.loading = false
      }
    },
    refresh() {
      if (this.keyword) {
        this.search()
      } else {
        this.$message.info('请输入序列号后再执行刷新')
      }
    },
    onClearSearch() {
      if (!this.keyword) {
        this.reset()
      }
    },
    reset() {
      this.keyword = ''
      this.result = null
      this.message = ''
      this.suggestions = []
      if (this.$route.query.sn) {
        this.$router.replace({ query: {} }).catch(() => {})
      }
    },
    openSerialNo(sn) {
      this.keyword = sn
      this.search()
    },
    selectSuggestion(sn) {
      this.keyword = sn
      this.search()
    },
    openWo() {
      if (this.result && this.result.work_order && this.result.work_order.id) {
        this.$router.push(`/production/work-orders/${this.result.work_order.id}`)
      }
    },
    openSo() {
      if (this.result && this.result.sales_order && this.result.sales_order.id) {
        this.$router.push(`/sales/orders/${this.result.sales_order.id}/detail`)
      }
    },
    unitStatusType(status) {
      const map = {
        stored: 'success',
        completed: 'success',
        in_progress: 'warning',
        rework: 'danger',
        scrapped: 'danger',
        waiting: 'info',
        cancelled: 'info'
      }
      return map[status] || 'info'
    },
    dotClass(action) {
      if (['rejected', 'rework', 'cancelled', 'scrapped', 'unit_failed', 'unit_scrap_requested', 'unit_scrapped'].includes(action)) return 'bad'
      if (['paused', 'unit_scrap_rejected'].includes(action)) return 'doing'
      return 'done'
    },
    actionTagType(action) {
      if (['rejected', 'rework', 'cancelled', 'scrapped', 'unit_failed', 'unit_scrapped'].includes(action)) return 'danger'
      if (['paused', 'unit_scrap_requested', 'unit_scrap_rejected'].includes(action)) return 'warning'
      if (['completed', 'stored', 'unit_passed', 'unit_stored'].includes(action)) return 'success'
      if (['released', 'resumed'].includes(action)) return 'primary'
      return 'info'
    },
    actionTagText(action) {
      const map = {
        released: '制令下达',
        started: '开工作业',
        paused: '暂停生产',
        resumed: '恢复生产',
        completed: '完工过站',
        stored: '已入库',
        rework: '返工返修',
        rejected: '质检不合格',
        scrapped: '报废处置',
        cancelled: '制令取消',
        unit_passed: '工序合格',
        unit_failed: '质检不合格',
        unit_scrap_requested: '申请报废',
        unit_scrap_rejected: '报废驳回',
        unit_scrapped: '报废处置',
        unit_stored: '已入库'
      }
      return map[action] || '工序操作'
    },
    copyText(text, label = '内容') {
      if (!text) return
      if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(() => {
          this.$message.success(`${label}已成功复制到剪贴板`)
        }).catch(() => {
          this.legacyCopy(text, label)
        })
      } else {
        this.legacyCopy(text, label)
      }
    },
    legacyCopy(text, label) {
      const input = document.createElement('input')
      input.value = text
      document.body.appendChild(input)
      input.select()
      try {
        document.execCommand('copy')
        this.$message.success(`${label}已成功复制到剪贴板`)
      } catch (e) {
        this.$message.error('复制失败，请手动选择复制')
      }
      document.body.removeChild(input)
    },
    copyTraceLink() {
      if (!this.result || !this.result.serial_no) return
      const url = window.location.origin + window.location.pathname + '#/production/serials?sn=' + encodeURIComponent(this.result.serial_no)
      this.copyText(url, '追溯档案链接')
    }
  }
}
</script>

<style scoped>
.serial-trace-page {
  padding: 16px 20px 24px;
  background: #f8fafc;
  min-height: calc(100vh - 54px);
  box-sizing: border-box;
}

/* 顶部标头卡片 (100% 对齐主数据中心 / SkuList.vue 风格) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #ffffff;
  padding: 16px 20px;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  border: 1px solid #e2e8f0;
  margin-bottom: 12px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.head-icon {
  width: 40px;
  height: 40px;
  background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
  color: #008b4b;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
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
  line-height: 1.2;
}

.sku-code-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  color: #00763f;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
}

.head-status-tag,
.head-op-tag {
  font-size: 12px;
  border-radius: 4px;
  height: 24px;
  line-height: 22px;
  padding: 0 8px;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-refresh {
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #606266 !important;
}

.btn-refresh:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
  background: #f0fdf4 !important;
}

.btn-action-light {
  border-radius: 4px;
  font-weight: 500;
}

/* 全局统一提示条 (对齐主数据中心规范) */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #00763f;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 13px;
  line-height: 1.5;
  margin-bottom: 14px;
}

.erp-page-tip i {
  font-size: 15px;
  flex-shrink: 0;
}

/* 检索与操作栏卡片 */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  overflow: hidden;
  margin-bottom: 16px;
}

.search-card {
  padding: 0;
}

.filter-toolbar {
  padding: 14px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.filter-search-group {
  display: flex;
  align-items: center;
  gap: 10px;
  flex: 1;
  min-width: 320px;
  flex-wrap: wrap;
}

.filter-input-search {
  width: 380px;
  max-width: 100%;
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

.btn-reset {
  border-color: #dcdfe6 !important;
  color: #606266 !important;
}

.btn-reset:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
}

.filter-summary-info {
  display: flex;
  align-items: center;
  gap: 10px;
  font-size: 13px;
  color: #475569;
}

.summary-divider {
  color: #cbd5e1;
}

.lk-text {
  color: #008b4b;
  cursor: pointer;
  text-decoration: underline;
}

.suggestions-bar {
  padding: 10px 18px;
  background: #f8fafc;
  border-top: 1px solid #f1f5f9;
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.sugg-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 500;
}

.sugg-chips {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.sugg-chip {
  display: inline-flex;
  align-items: center;
  padding: 3px 8px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  color: #1e293b;
  font-size: 12px;
  cursor: pointer;
  transition: all 0.15s ease;
}

.sugg-chip:hover {
  border-color: #008b4b;
  color: #008b4b;
  background: #f0fdf4;
}

/* 顶部概览指标卡片 (对齐主数据中心风格) */
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

.metric-product .metric-icon-box { background: #eaf7ef; color: #008b4b; }
.metric-status .metric-icon-box { background: #f0fdf4; color: #059669; }
.metric-workorder .metric-icon-box { background: #eff6ff; color: #2563eb; }
.metric-source .metric-icon-box { background: #faf5ff; color: #9333ea; }

.metric-info {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.metric-label {
  font-size: 12px;
  color: #64748b;
}

.metric-val {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.3;
  margin-top: 2px;
}

.metric-sub {
  font-size: 12px;
  color: #94a3b8;
  margin-top: 2px;
}

/* 主内容网格 (双栏响应式) */
.content-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 360px;
  gap: 16px;
  align-items: start;
}

.main-column {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

.side-column {
  display: flex;
  flex-direction: column;
  gap: 16px;
  min-width: 0;
}

/* 详情卡片通用 */
.detail-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 18px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  box-sizing: border-box;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #f1f5f9;
  flex-wrap: wrap;
  gap: 8px;
}

.header-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.header-title i {
  color: #008b4b;
  font-size: 17px;
}

.header-title h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.header-desc-hint {
  font-size: 12px;
  color: #94a3b8;
}

/* 时间线容器 */
.timeline-box {
  display: flex;
  flex-direction: column;
  position: relative;
  padding-left: 6px;
}

.timeline-node {
  display: grid;
  grid-template-columns: 24px 1fr;
  gap: 12px;
  position: relative;
  padding-bottom: 16px;
}

.timeline-node:last-child {
  padding-bottom: 0;
}

.node-axis {
  display: flex;
  flex-direction: column;
  align-items: center;
  position: relative;
}

.node-dot {
  width: 14px;
  height: 14px;
  border-radius: 50%;
  border: 2px solid #cbd5e1;
  background: #ffffff;
  margin-top: 3px;
  flex-shrink: 0;
  z-index: 1;
}

.timeline-node.done .node-dot {
  background: #008b4b;
  border-color: #bbf7d0;
}

.timeline-node.bad .node-dot {
  background: #ef4444;
  border-color: #fecaca;
}

.timeline-node.doing .node-dot {
  background: #f59e0b;
  border-color: #fde68a;
}

.dot-future {
  background: #ffffff;
  border-color: #94a3b8;
  border-style: dashed;
}

.dot-service {
  background: #f1f5f9;
  border-color: #cbd5e1;
}

.node-line {
  width: 2px;
  background: #e2e8f0;
  flex: 1;
  margin-top: 4px;
  margin-bottom: -4px;
}

.line-dashed {
  background: transparent;
  border-left: 2px dashed #cbd5e1;
}

.timeline-node:last-child .node-line {
  display: none;
}

.node-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 14px;
}

.node-meta-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 4px;
}

.node-time {
  font-size: 12px;
  color: #64748b;
}

.node-action-tag {
  font-size: 11px;
  height: 20px;
  line-height: 18px;
  padding: 0 6px;
  border-radius: 3px;
}

.node-operator {
  font-size: 12px;
  color: #475569;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.node-content-text {
  font-size: 13px;
  font-weight: 600;
  color: #0f172a;
  line-height: 1.5;
}

.card-future {
  background: #ffffff;
  border: 1px dashed #cbd5e1;
}

.future-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #334155;
  margin-bottom: 8px;
}

.future-tip {
  font-size: 12px;
  color: #94a3b8;
  font-weight: 400;
}

.op-step-chips {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.op-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 10px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 12px;
  color: #334155;
  font-weight: 500;
}

.chip-idx {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  background: #e2e8f0;
  color: #475569;
  border-radius: 50%;
  font-size: 11px;
}

.chip-ship {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #00763f;
}

.card-service {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
}

.service-desc-text {
  font-size: 12px;
  color: #64748b;
  line-height: 1.5;
}

/* 关键部件表格 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
}

.components-table {
  width: 100%;
}

.empty-components-notice {
  padding: 24px;
  text-align: center;
  color: #94a3b8;
  font-size: 13px;
  background: #f8fafc;
  border-radius: 6px;
  border: 1px dashed #e2e8f0;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.empty-components-notice i {
  font-size: 24px;
  color: #cbd5e1;
}

/* 规格参数列表 */
.spec-kv-list {
  display: flex;
  flex-direction: column;
}

.spec-kv-row {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  padding: 9px 0;
  border-bottom: 1px solid #f1f5f9;
  gap: 12px;
  font-size: 13px;
}

.spec-kv-row:last-child {
  border-bottom: none;
}

.kv-label {
  color: #64748b;
  flex-shrink: 0;
}

.kv-val {
  color: #0f172a;
  text-align: right;
  word-break: break-all;
}

.highlight-sn {
  font-weight: 700;
  color: #008b4b;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.copy-icon {
  cursor: pointer;
  color: #64748b;
  font-size: 14px;
  transition: color 0.15s ease;
}

.copy-icon:hover {
  color: #008b4b;
}

.routing-badge {
  display: inline-block;
  padding: 1px 6px;
  background: #f1f5f9;
  border-radius: 3px;
  font-size: 11px;
  color: #475569;
  margin-left: 4px;
}

.rework-tag-warn {
  color: #dc2626;
  font-weight: 600;
}

.rework-tag-zero {
  color: #059669;
  font-weight: 500;
}

.customer-sub {
  font-size: 12px;
  color: #64748b;
  margin-top: 2px;
}

.table-link {
  color: #008b4b;
  cursor: pointer;
  text-decoration: underline;
}

/* 快捷操作 */
.action-btn-group {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.action-block-btn {
  width: 100%;
  margin-left: 0 !important;
  text-align: center;
}

/* 空状态卡片 */
.empty-card-wrap {
  padding: 48px 24px;
  text-align: center;
}

.empty-state-inner {
  max-width: 600px;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  align-items: center;
}

.empty-icon-box {
  width: 60px;
  height: 60px;
  border-radius: 12px;
  background: #f1f5f9;
  display: grid;
  place-items: center;
  font-size: 28px;
  margin-bottom: 16px;
}

.empty-title {
  margin: 0 0 8px;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.empty-desc {
  margin: 0 0 20px;
  font-size: 13px;
  color: #64748b;
  line-height: 1.6;
}

.empty-suggestions-panel {
  width: 100%;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 18px;
  text-align: left;
}

.sugg-header {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #334155;
  margin-bottom: 10px;
}

.sugg-chips-grid {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.suggestion-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 12px;
  color: #00763f;
  cursor: pointer;
  transition: all 0.15s ease;
}

.suggestion-pill:hover {
  border-color: #008b4b;
  background: #f0fdf4;
  transform: translateY(-1px);
}

/* 基础通用类 */
.text-truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.code-mono,
.font-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.3px;
}

.font-medium {
  font-weight: 600;
}

.text-success {
  color: #008b4b;
}

.text-warning {
  color: #d97706;
}

.text-primary {
  color: #2563eb;
}

.text-info {
  color: #0284c7;
}

.text-muted {
  color: #94a3b8;
}

/* 响应式断点 */
@media (max-width: 1100px) {
  .content-grid {
    grid-template-columns: 1fr;
  }
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-start;
    flex-wrap: wrap;
  }
  .filter-search-group {
    width: 100%;
  }
  .filter-input-search {
    width: 100%;
  }
}

@media (max-width: 480px) {
  .serial-trace-page {
    padding: 12px 10px 20px;
  }
  .metric-overview-grid {
    grid-template-columns: 1fr;
  }
  .spec-kv-row {
    flex-direction: column;
    gap: 4px;
  }
  .kv-val {
    text-align: left;
  }
}
</style>
