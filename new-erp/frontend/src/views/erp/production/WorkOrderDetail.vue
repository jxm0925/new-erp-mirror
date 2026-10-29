<template>
  <div class="work-order-detail-page" v-loading="loading">
    <template v-if="wo">
      <!-- 页面全局头部卡片 -->
      <header class="page-head">
        <div class="head-left">
          <el-button
            size="small"
            icon="el-icon-back"
            class="btn-back"
            @click="$router.push('/production/work-orders')"
          >
            返回工单列表
          </el-button>
          <span class="head-icon"><i class="el-icon-s-order" /></span>
          <div class="head-title-wrap">
            <div class="title-row">
              <h1 class="page-title">生产工单详情</h1>
              <span class="code-mono sku-code-tag">
                <i class="el-icon-postcard" />
                {{ wo.work_order_no }}
              </span>
              <el-tag size="small" :type="unitStatusType(wo.status)" effect="plain">
                {{ wo.status_label || wo.status }}
              </el-tag>
              <el-tag v-if="wo.is_urgent || wo.priority === 4" size="small" :type="wo.priority === 4 ? 'info' : 'danger'" effect="dark" class="urgent-tag">
                {{ wo.priority_label || '加急' }}
              </el-tag>
              <span class="source-crumb-tag">
                <template v-if="wo.source_type === 'sales_order'">
                  <i class="el-icon-tickets text-primary" />
                  关联销售订单：<b class="code-mono lk-text" @click="openOrder">{{ wo.sales_order_no }}</b>
                  <template v-if="wo.sales_order_line_no">（第 {{ wo.sales_order_line_no }} 行）</template>
                  · {{ wo.customer_name }}
                </template>
                <template v-else-if="wo.source_type === 'work_order'">
                  <i class="el-icon-box text-muted" />
                  需求来源：半成品补做
                  <template v-if="wo.parent_work_order">
                    · 给总装工单 <b class="code-mono lk-text" @click="openWorkOrder(wo.parent_work_order.id)">{{ wo.parent_work_order.work_order_no }}</b>
                  </template>
                </template>
                <template v-else>
                  <i class="el-icon-box text-muted" />
                  需求来源：备货 · {{ wo.stock_reason_label || '常规备货' }}
                </template>
              </span>
            </div>
          </div>
        </div>

        <div class="head-actions">
          <el-button
            size="small"
            icon="el-icon-refresh"
            class="btn-refresh"
            :loading="loading"
            @click="load"
          >
            刷新
          </el-button>
          <el-button
            v-if="wo.status === 'planned' && $can('production.work_order.publish')"
            size="small"
            type="success"
            icon="el-icon-check"
            class="btn-theme-create"
            :loading="saving"
            @click="release"
          >
            下达工单
          </el-button>
          <el-button
            v-if="canAdjust && $can('production.work_order.edit')"
            size="small"
            icon="el-icon-edit"
            class="btn-action-light"
            @click="openAdjust"
          >
            调整计划
          </el-button>
          <el-button
            v-if="['released', 'in_progress'].includes(wo.status) && $can('production.work_order.edit')"
            size="small"
            type="warning"
            plain
            icon="el-icon-video-pause"
            class="btn-action-light"
            :loading="saving"
            @click="pause"
          >
            暂停生产
          </el-button>
          <el-button
            v-if="wo.status === 'paused' && $can('production.work_order.edit')"
            size="small"
            type="success"
            icon="el-icon-video-play"
            class="btn-theme-create"
            :loading="saving"
            @click="resume"
          >
            恢复生产
          </el-button>
          <el-button
            v-if="canCancel && $can('production.work_order.cancel')"
            size="small"
            type="danger"
            plain
            icon="el-icon-close"
            class="btn-action-light"
            :loading="saving"
            @click="cancel"
          >
            取消工单
          </el-button>
        </div>
      </header>

      <!-- 工单主档参数与生命周期进度概览卡片 -->
      <section class="detail-card overview-master-card">
        <div class="master-meta-grid">
          <div class="meta-cell meta-cell-primary">
            <span class="meta-label">制造产品 / 计划数量</span>
            <div class="meta-val-main">
              <strong class="product-title" :title="productName">{{ productName }}</strong>
              <span class="qty-pill code-mono">{{ qty(wo.planned_qty) }} {{ wo.unit_name || '台' }}</span>
            </div>
          </div>
          <div class="meta-cell">
            <span class="meta-label">SKU 规格编码</span>
            <div class="meta-val code-mono" :title="wo.sku_code || '—'">{{ wo.sku_code || '—' }}</div>
          </div>
          <div class="meta-cell">
            <span class="meta-label">工艺路线</span>
            <div class="meta-val" :title="wo.routing ? `${wo.routing.routing_name} V${wo.routing.version}` : '未配置工艺路线'">
              {{ wo.routing ? `${wo.routing.routing_name} V${wo.routing.version}` : '未配置工艺路线' }}
            </div>
          </div>
          <div class="meta-cell">
            <span class="meta-label">制造 BOM</span>
            <div class="meta-val" :title="wo.bom ? bomLabel(wo.bom.bom_name, wo.bom.bom_no, wo.bom.version) : '未配置 BOM'">
              {{ wo.bom ? bomLabel(wo.bom.bom_name, wo.bom.bom_no, wo.bom.version) : '未配置 BOM' }}
            </div>
          </div>
          <div class="meta-cell">
            <span class="meta-label">生产负责人</span>
            <div class="meta-val">{{ wo.owner_name || '未指定' }}</div>
          </div>
          <div class="meta-cell">
            <span class="meta-label">目标入库仓</span>
            <div class="meta-val">{{ wo.warehouse_name || '物料默认仓库' }}</div>
          </div>
        </div>

        <div class="schedule-stepper-row">
          <div class="stepper-wrap">
            <div class="stepper-title">生命周期状态演进</div>
            <div class="stepper-flow">
              <div
                v-for="(s, i) in steps"
                :key="s.value"
                class="step-node"
                :class="{
                  'step-active': s.value === stepValue,
                  'step-completed': i < stepIndex
                }"
              >
                <div class="node-circle font-mono">
                  <i v-if="i < stepIndex" class="el-icon-check" />
                  <span v-else>{{ i + 1 }}</span>
                </div>
                <span class="node-label">{{ s.label }}</span>
                <div v-if="i < steps.length - 1" class="node-divider" />
              </div>
            </div>
          </div>

          <div class="due-summary-box">
            <div class="due-box-header">
              <span class="due-box-label">计划完工交期进度</span>
              <span v-if="dueDiff !== null" :class="['due-diff-tag', dueDiff >= 0 ? 'text-success' : 'text-danger font-medium']">
                {{ dueDiff >= 0 ? `较约定交期提前 ${dueDiff} 天` : `较约定交期滞后 ${-dueDiff} 天` }}
              </span>
            </div>
            <div class="due-main-date code-mono">
              {{ wo.planned_finish_date || '未指定计划完工日' }}
            </div>
            <div class="schedule-progress-bar">
              <div class="progress-fill" :style="{ width: barPercent + '%' }" />
            </div>
            <div class="schedule-milestones">
              <span>计划开工：{{ shortDate(wo.planned_start_date) }}</span>
              <span>当前基准：{{ shortDate(today) }}</span>
              <span v-if="wo.required_date">合同交期：{{ shortDate(wo.required_date) }}</span>
            </div>
          </div>
        </div>
      </section>

      <!-- 双栏响应式主体工作区 -->
      <main class="content-grid">
        <!-- 左侧主体列：三大核心制程与齐套看板 -->
        <div class="main-column">
          <!-- 卡片 1：整机序列号在制进度与工序过站看板 -->
          <section class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-cpu" />
                <h2>整机序列号在制进度与过站看板</h2>
              </div>
              <div class="unit-status-counters">
                <span class="counter-pill pill-success">已入库 <b class="text-success">{{ unitCount('stored') }}</b></span>
                <span class="counter-pill">待入库 <b>{{ unitCount('completed') }}</b></span>
                <span class="counter-pill pill-primary">在制加工 <b class="text-primary">{{ unitCount('in_progress') }}</b></span>
                <span class="counter-pill pill-danger">质量返修 <b class="text-danger">{{ unitCount('rework') }}</b></span>
                <span class="counter-pill">待开工 <b>{{ unitCount('waiting') }}</b></span>
              </div>
            </div>

            <div v-if="!wo.units.length" class="empty-state-notice">
              <i class="el-icon-info" />
              <span>{{ wo.status === 'planned' ? '工单下达后将按设备自动生成整机序列号并开启逐台过站追溯' : '本工单未开启按设备追溯（计划数量非整数或超出逐台跟踪上限）' }}</span>
            </div>

            <div v-else class="unit-table-container">
              <table class="unit-matrix-table">
                <thead>
                  <tr class="table-group-header">
                    <th class="col-sn">产品序列号 (SN)</th>
                    <th :colspan="prodOps.length || 1" class="col-grp-prod">车间制造工序（本制令工单）</th>
                    <th v-if="shipOps.length" :colspan="shipOps.length" class="col-grp-ship">仓配与发运工序（销售交付阶段）</th>
                  </tr>
                  <tr class="table-col-header">
                    <th class="col-sn-sub">序列号 / 状态 / 绑定部件</th>
                    <th v-for="op in prodOps" :key="op.id" class="op-th">{{ op.operation_name }}</th>
                    <th v-if="!prodOps.length" class="op-th">—</th>
                    <th v-for="op in shipOps" :key="op.id" class="op-th ship-th">{{ op.operation_name }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="u in visibleUnits" :key="u.id" class="unit-matrix-row">
                    <td class="unit-sn-cell">
                      <div class="sn-head-line">
                        <div class="sn-text code-mono" @click="openSerial(u.serial_no)">
                          <i class="el-icon-postcard text-success" />
                          {{ u.serial_no || ('第 ' + u.unit_no + ' 台') }}
                        </div>
                        <div class="unit-sub-status">
                          <span :class="u.status === 'rework' ? 'text-danger font-medium' : 'text-muted'">
                            {{ u.status === 'rework' ? `返修第 ${u.rework_count || 1} 次` : u.status_label }}
                          </span>
                          <span v-if="u.current_operation_name && ['in_progress', 'rework'].includes(u.status)" class="cur-op-sub">
                            · {{ u.current_operation_name }}
                          </span>
                          <span v-if="u.rework_count && u.status !== 'rework'" class="rework-history-sub">
                            · 曾返修 {{ u.rework_count }} 次
                          </span>
                        </div>
                      </div>
                      <div v-if="u.components && u.components.length" class="unit-comp-tags">
                        <span v-for="c in u.components" :key="c.id" class="unit-comp-chip">
                          <span class="comp-chip-text">{{ c.component_item_name }} <b class="code-mono">{{ c.component_serial_no }}</b></span>
                          <el-button v-if="canSwap(u)" type="text" size="mini" class="comp-swap-btn" @click="openSwap(u, c)">互换</el-button>
                        </span>
                      </div>
                    </td>
                    <td v-for="(op, i) in prodOps" :key="op.id" class="matrix-cell-td">
                      <div :class="['matrix-cell', cellState(u, i)]">
                        {{ cellText(u, i) }}
                      </div>
                    </td>
                    <td v-if="!prodOps.length" class="matrix-cell-td">
                      <div class="matrix-cell">—</div>
                    </td>
                    <td v-for="op in shipOps" :key="op.id" class="matrix-cell-td ship-td">
                      <div class="matrix-cell cell-dash">
                        {{ u.status === 'stored' ? '待开发货单' : '—' }}
                      </div>
                    </td>
                  </tr>
                </tbody>
              </table>

              <div v-if="wo.units.length > unitLimit" class="more-expand-bar">
                <el-button type="text" size="small" icon="el-icon-arrow-down" class="action-link-theme" @click="unitLimit = wo.units.length">
                  展开查看全部 {{ wo.units.length }} 台设备序列号
                </el-button>
              </div>
              <div v-if="(wo.unbound_components || []).length" class="unbound-comp-panel">
                <div class="unbound-panel-title">
                  <i class="el-icon-warning-outline text-danger" />
                  <span>待登记装机部件编号（{{ wo.unbound_components.length }} 项）</span>
                </div>
                <div class="unbound-chip-list">
                  <div v-for="c in wo.unbound_components" :key="c.serial_id" class="unbound-chip-item">
                    <span class="text-danger font-medium">待登记编号</span>
                    <span class="text-muted">{{ c.component_item_name }} <b class="code-mono">{{ c.serial_no }}</b>（{{ c.issue_no }}）</span>
                    <el-button v-if="canAssign" type="text" size="mini" @click="openAssign(c)">装到设备</el-button>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- 卡片 2：工序加工与作业工时实时看板 -->
          <section v-if="prodOps.length && wo.status !== 'planned'" class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-time" />
                <h2>工序加工与作业工时实时看板</h2>
              </div>
              <span class="header-desc-hint">按过站报工实时汇总工时</span>
            </div>

            <div class="table-wrap">
              <el-table :data="prodOps" size="small" border class="ops-summary-table">
                <el-table-column label="工序名称" min-width="140">
                  <template slot-scope="{ row }">
                    <span class="font-medium">{{ row.operation_name }}</span>
                    <el-tag size="mini" :type="row.status === 'done' ? 'success' : (row.status === 'in_progress' ? 'warning' : 'info')" effect="plain" class="op-status-tag">
                      {{ opStatus(row.status) }}
                    </el-tag>
                  </template>
                </el-table-column>
                <el-table-column label="在制 / 滞留设备数" width="130" align="center">
                  <template slot-scope="{ row }">
                    <span class="code-mono font-medium">{{ row.here_qty || 0 }} 台</span>
                    <span v-if="row.rework_qty" class="text-danger ml-1">· 返工 {{ row.rework_qty }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="完工进度比例" width="140" align="center">
                  <template slot-scope="{ row }">
                    <span class="code-mono font-medium text-success">{{ qty(row.completed_qty) }}</span>
                    <span class="code-mono text-muted"> / {{ qty(wo.planned_qty) }}</span>
                  </template>
                </el-table-column>
                <el-table-column prop="owner_name" label="工序负责人" width="110" align="center">
                  <template slot-scope="{ row }">
                    <span>{{ row.owner_name || '待指派' }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="实时作业人员" min-width="140" show-overflow-tooltip>
                  <template slot-scope="{ row }">
                    <span v-if="row.timing_names && row.timing_names.length" class="text-primary font-medium">
                      <i class="el-icon-user" /> {{ row.timing_names.join('、') }}
                    </span>
                    <span v-else class="text-muted">—</span>
                  </template>
                </el-table-column>
                <el-table-column label="累计投入工时" width="120" align="center">
                  <template slot-scope="{ row }">
                    <span class="code-mono font-medium">{{ row.labor_minutes ? hours(row.labor_minutes) : '—' }}</span>
                  </template>
                </el-table-column>
                <el-table-column v-if="canDispatch" label="操作" width="90" align="center">
                  <template slot-scope="{ row }">
                    <a v-if="row.status !== 'done'" class="lk-text" @click="openDispatch(row)">{{ row.owner_name ? '改派' : '派工' }}</a>
                  </template>
                </el-table-column>
              </el-table>
            </div>
          </section>

          <!-- 卡片 3：工序物料齐套分析看板 -->
          <section class="detail-card">
            <div class="card-header">
              <div class="header-left-group">
                <div class="header-title">
                  <i class="el-icon-connection" />
                  <h2>工序物料齐套分析看板</h2>
                </div>
                <span class="header-desc-hint">按工艺路线将 BOM 分解至工序校验物料齐套；缺料提示预警</span>
              </div>
              <div class="card-header-actions">
                <work-order-cutting-panel :work-order="wo" @refresh="load" />
                <el-button
                  v-if="canPurchaseShortage"
                  size="mini"
                  type="warning"
                  plain
                  icon="el-icon-shopping-cart-full"
                  :disabled="saving"
                  @click="shortageDialog = true"
                >
                  缺料转采购
                </el-button>
              </div>
            </div>

            <div v-if="!wo.kitting.length" class="empty-state-notice">
              <i class="el-icon-box" />
              <span>本工单未配置工艺路线或制造 BOM，暂无法按工序测算物料齐套率</span>
            </div>

            <template v-else>
              <!-- 含领料需求工序卡片网格（内部采用紧凑表格对齐，让物料名称与数量紧邻易读） -->
              <div
                v-if="materialKittingOps.length"
                class="kitting-grid-wrap"
                :class="{ 'kitting-grid-single': materialKittingOps.length === 1 }"
              >
                <div
                  v-for="(k, idx) in materialKittingOps"
                  :key="k.operation_key"
                  class="kitting-op-card"
                  :class="{
                    'kitting-shortage': k.status === 'short',
                    'kitting-op-span-full': isFullSpanKittingCard(idx, k)
                  }"
                >
                  <div class="kitting-op-head">
                    <div class="kitting-op-title-group">
                      <span class="op-title font-medium">{{ k.operation_name }}</span>
                      <el-tag
                        size="mini"
                        :type="k.status === 'short' ? 'danger' : (k.status === 'ready' ? 'success' : 'info')"
                        effect="plain"
                      >
                        {{ kitLabel(k) }}
                      </el-tag>
                      <span class="op-mat-count text-muted">共 {{ k.materials.length }} 项物料</span>
                    </div>
                    <div class="kitting-op-actions">
                      <el-button
                        v-if="k.receive_pending && canReceiveMaterials"
                        type="text"
                        size="mini"
                        :disabled="saving"
                        @click="receiveMaterials(k)"
                      >
                        收料
                      </el-button>
                      <el-button
                        v-if="k.receive_pending && canReceiveMaterials && $can('production.serial.assign')"
                        type="text"
                        size="mini"
                        :disabled="saving"
                        @click="openSerialReceive(k)"
                      >
                        指定编号收料
                      </el-button>
                      <el-button
                        v-if="canReturnMaterials && kitIssued(k)"
                        type="text"
                        size="mini"
                        :disabled="saving"
                        @click="openReturn(k)"
                      >
                        退料
                      </el-button>
                    </div>
                  </div>
                  <div class="materials-table-scroll">
                    <table class="kitting-mat-table">
                      <thead>
                        <tr>
                          <th class="col-mat-name">物料名称</th>
                          <th class="col-mat-qty">齐套 / 需用数量</th>
                          <th class="col-mat-state">状态与提示</th>
                          <th class="col-mat-act">操作</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr
                          v-for="m in k.materials"
                          :key="m.id || m.component_item_id"
                          :class="{ 'row-shortage': m.participates_in_kitting && m.shortage_qty > 0 }"
                        >
                          <td class="col-mat-name" :title="m.component_item_name">
                            <span class="mat-name-text">{{ m.component_item_name }}</span>
                          </td>
                          <td class="col-mat-qty">
                            <span v-if="!m.participates_in_kitting" class="text-muted mat-auxiliary">辅料（免齐套）</span>
                            <span v-else class="mat-qty-badge code-mono" :class="m.shortage_qty > 0 ? 'qty-short' : 'qty-ok'">
                              {{ qty(m.covered_qty) }} / {{ qty(m.required_qty) }} {{ m.unit_name || '' }}
                            </span>
                            <div v-if="altQtyText(m.component_item_id, m.required_qty)" class="mat-alt-qty text-muted">{{ altQtyText(m.component_item_id, m.required_qty) }}</div>
                          </td>
                          <td class="col-mat-state">
                            <div class="material-sub-badges">
                              <span v-if="m.in_production_qty > 0" class="mat-sub-tag">在做 {{ qty(m.in_production_qty) }}</span>
                              <span v-if="queueHint(m)" class="text-danger mat-queue-hint">{{ queueHint(m) }}</span>
                              <span v-if="!(m.in_production_qty > 0) && !queueHint(m)" class="text-muted">
                                {{ !m.participates_in_kitting ? '—' : (m.shortage_qty > 0 ? `缺 ${qty(m.shortage_qty)} ${m.unit_name || ''}` : '已齐套') }}
                              </span>
                            </div>
                          </td>
                          <td class="col-mat-act">
                            <el-button
                              v-if="m.can_make && m.make_qty > 0 && $can('production.work_order.create')"
                              type="text"
                              size="mini"
                              class="btn-make-semi"
                              :disabled="saving"
                              @click="makeSemi(m)"
                            >
                              开半成品工单
                            </el-button>
                            <span v-else class="text-muted">—</span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>

              <!-- 免领料工序紧凑汇总条 -->
              <div v-if="noMaterialKittingOps.length" class="no-mat-ops-bar">
                <span class="no-mat-bar-label">
                  <i class="el-icon-circle-check text-success" />
                  免领料工序（无需领料）：
                </span>
                <div class="no-mat-bar-tags">
                  <span v-for="k in noMaterialKittingOps" :key="k.operation_key" class="no-mat-op-pill">
                    <b>{{ k.operation_name }}</b>
                    <span class="text-muted">· {{ kitLabel(k) }}</span>
                  </span>
                </div>
              </div>
            </template>

            <!-- 关联半成品工单、仓库配送、采购需求、退料记录分组面板 -->
            <div
              v-if="(wo.child_work_orders && wo.child_work_orders.length) || (wo.material_deliveries && wo.material_deliveries.length) || (wo.purchase_demands && wo.purchase_demands.length) || (wo.material_returns && wo.material_returns.length)"
              class="kitting-related-docs"
            >
              <div v-if="wo.child_work_orders && wo.child_work_orders.length" class="related-doc-block">
                <div class="related-doc-head">
                  <span class="related-doc-title"><i class="el-icon-s-operation" /> 关联半成品工单（{{ wo.child_work_orders.length }}）</span>
                </div>
                <div class="related-doc-list">
                  <div v-for="c in wo.child_work_orders" :key="c.id" class="related-doc-row">
                    <div class="related-doc-main">
                      <span class="mat-name">
                        半成品工单 <b class="code-mono lk-text" @click="openWorkOrder(c.id)">{{ c.work_order_no }}</b> · {{ c.item_name }}
                      </span>
                      <span class="mat-qty-inline code-mono">入库 {{ qty(c.stored_qty) }} / {{ qty(c.planned_qty) }} {{ c.unit_name || '' }}</span>
                    </div>
                    <el-tag size="mini" type="info" effect="plain">{{ c.status_label }}</el-tag>
                  </div>
                </div>
              </div>

              <div v-if="wo.material_deliveries && wo.material_deliveries.length" class="related-doc-block">
                <div class="related-doc-head">
                  <span class="related-doc-title"><i class="el-icon-truck" /> 仓库配送记录（{{ wo.material_deliveries.length }}）</span>
                </div>
                <div class="related-doc-list">
                  <div v-for="d in wo.material_deliveries" :key="'md-' + d.id" class="related-doc-row">
                    <div class="related-doc-main" :title="d.summary + (d.reject_reason ? '；拒收：' + d.reject_reason : '')">
                      <span class="mat-name">
                        仓库配送 <b class="code-mono">{{ d.delivery_no }}</b> · {{ d.operation_name }} · {{ d.summary }}
                      </span>
                    </div>
                    <div class="related-doc-right">
                      <span class="mat-qty code-mono">{{ d.status_label }}{{ d.reject_reason ? '（' + d.reject_reason + '）' : '' }}</span>
                      <template v-if="d.status === 'dispatched' && canReceiveMaterials">
                        <el-button type="text" size="mini" :disabled="saving" @click="signDelivery(d)">签收</el-button>
                        <el-button type="text" size="mini" class="text-danger" :disabled="saving" @click="rejectDelivery(d)">拒收</el-button>
                      </template>
                    </div>
                  </div>
                </div>
              </div>

              <div v-if="wo.purchase_demands && wo.purchase_demands.length" class="related-doc-block">
                <div class="related-doc-head">
                  <span class="related-doc-title"><i class="el-icon-shopping-bag-1" /> 缺料采购需求（{{ wo.purchase_demands.length }}）</span>
                </div>
                <div class="related-doc-list">
                  <div v-for="d in wo.purchase_demands" :key="'pd-' + d.id" class="related-doc-row">
                    <div class="related-doc-main">
                      <span class="mat-name">
                        采购需求 <b class="code-mono" :class="{ 'lk-text': $can('purchase.request') }" @click="openPurchaseRequest(d)">{{ d.request_no }}</b> · {{ d.item_name }}
                      </span>
                      <span class="mat-qty-inline code-mono">数量 {{ qty(d.qty) }}</span>
                    </div>
                    <span class="mat-qty code-mono">{{ d.request_status_label }} · {{ d.created_by_name || '' }}</span>
                  </div>
                </div>
              </div>

              <div v-if="wo.material_returns && wo.material_returns.length" class="related-doc-block">
                <div class="related-doc-head">
                  <span class="related-doc-title"><i class="el-icon-refresh-left" /> 工序退料记录（{{ wo.material_returns.length }}）</span>
                </div>
                <div class="related-doc-list">
                  <div v-for="r in wo.material_returns" :key="'mrt-' + r.id" class="related-doc-row">
                    <div class="related-doc-main" :title="r.summary + (r.reason ? '；' + r.reason : '')">
                      <span class="mat-name">
                        退料 <b class="code-mono">{{ r.return_no }}</b> · {{ r.operation_name }} · {{ r.return_type_label }} · {{ r.summary }}
                      </span>
                    </div>
                    <div class="related-doc-right">
                      <span class="mat-qty code-mono">{{ r.status_label }} · {{ r.requested_by_name || '' }}{{ r.review_remark ? '（' + r.review_remark + '）' : '' }}</span>
                      <el-button v-if="r.status === 'pending' && canCancelReturn(r)" type="text" size="mini" :disabled="saving" @click="cancelReturn(r)">撤回</el-button>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </section>

          <shortage-purchase-dialog :visible.sync="shortageDialog" :work-order-id="wo.id" :work-order-no="wo.work_order_no" @done="load" />
        </div>

        <!-- 右侧侧边栏：异常告警、业务单据、工艺图纸、材料成本与流转日志 -->
        <aside class="side-column">
          <!-- 卡片 4：异常告警与关注事项 -->
          <section class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-warning-outline" />
                <h2>异常告警与关注事项</h2>
              </div>
              <el-tag size="mini" :type="wo.attention && wo.attention.length ? 'danger' : 'success'" effect="plain">
                {{ wo.attention && wo.attention.length ? `待处置 ${wo.attention.length} 项` : '正常' }}
              </el-tag>
            </div>

            <div v-if="!wo.attention || !wo.attention.length" class="normal-status-box">
              <i class="el-icon-circle-check text-success" />
              <div class="normal-status-content">
                <strong>制程状态运行正常</strong>
                <p>当前无物料缺料预警、生产暂停或交期延误事项。</p>
              </div>
            </div>

            <div v-else class="attention-list">
              <div
                v-for="(a, i) in wo.attention"
                :key="i"
                class="attention-item"
                :class="a.type === 'danger' ? 'att-danger' : 'att-warning'"
              >
                <div class="att-head">
                  <i :class="a.type === 'danger' ? 'el-icon-error' : 'el-icon-warning'" />
                  <strong>{{ a.title }}</strong>
                </div>
                <p class="att-text">{{ a.text }}</p>
              </div>
            </div>
            <div v-if="wo.scrap_settle_allowed && $can('production.scrap.approve')" class="more-expand-bar">
              <el-button size="mini" type="warning" plain :loading="saving" @click="settleScrap">确认报废设备已处理，结束工单</el-button>
            </div>
          </section>

          <!-- 卡片 5：业务需求与单据关联 -->
          <section class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-tickets" />
                <h2>业务需求与单据关联</h2>
              </div>
            </div>

            <div class="spec-kv-list">
              <template v-if="wo.sales_order">
                <div class="spec-kv-row">
                  <span class="kv-label">销售订单号</span>
                  <span class="kv-val code-mono lk-text" @click="openOrder">
                    {{ wo.sales_order.sales_order_no }}
                  </span>
                </div>
                <div class="spec-kv-row">
                  <span class="kv-label">采购客户</span>
                  <span class="kv-val font-medium">{{ wo.sales_order.customer_name }}</span>
                </div>
                <div v-if="wo.sales_order.order_qty !== null" class="spec-kv-row">
                  <span class="kv-label">订单行订购数</span>
                  <span class="kv-val code-mono font-medium">
                    {{ qty(wo.sales_order.order_qty) }} {{ wo.sales_order.unit_name || '' }}
                    <span v-if="wo.sales_order.inventory_qty" class="text-muted font-normal ml-1">
                      （占用库存 {{ qty(wo.sales_order.inventory_qty) }}）
                    </span>
                  </span>
                </div>
                <div class="spec-kv-row">
                  <span class="kv-label">约定交付日期</span>
                  <span class="kv-val code-mono">{{ wo.sales_order.required_delivery_date || '—' }}</span>
                </div>
                <div v-if="wo.special_requirement" class="spec-kv-row">
                  <span class="kv-label">特殊定制要求</span>
                  <span class="kv-val">{{ wo.special_requirement }}</span>
                </div>
                <template v-if="wo.sales_order.funding">
                  <div class="spec-kv-row">
                    <span class="kv-label">收付款政策</span>
                    <span class="kv-val">{{ wo.sales_order.funding.policy_name || '未配置' }}</span>
                  </div>
                  <div class="spec-kv-row">
                    <span class="kv-label">开工资金门禁</span>
                    <span class="kv-val">
                      <span :class="wo.sales_order.funding.production_funds_satisfied ? 'text-success font-medium' : 'text-danger font-medium'">
                        <i :class="wo.sales_order.funding.production_funds_satisfied ? 'el-icon-circle-check' : 'el-icon-warning'" />
                        {{ wo.sales_order.funding.production_funds_satisfied ? '已满足开工定金门禁' : '定金未到账，限制开工' }}
                      </span>
                      <div v-if="wo.sales_order.funding.net_received_amount !== undefined" class="text-muted font-mono" style="font-size: 11px;">
                        已收定金金额：{{ money(wo.sales_order.funding.net_received_amount) }}
                      </div>
                    </span>
                  </div>
                </template>
              </template>

              <template v-else>
                <div class="spec-kv-row">
                  <span class="kv-label">备货原因</span>
                  <span class="kv-val font-medium">{{ wo.stock_reason_label || '常规备货' }}</span>
                </div>
                <div class="spec-kv-row">
                  <span class="kv-label">计划开工日期</span>
                  <span class="kv-val code-mono">{{ wo.planned_start_date || '—' }}</span>
                </div>
                <div class="spec-kv-row">
                  <span class="kv-label">计划完工日期</span>
                  <span class="kv-val code-mono">{{ wo.planned_finish_date || '—' }}</span>
                </div>
              </template>

              <div v-if="wo.remark" class="spec-kv-row">
                <span class="kv-label">制令备注</span>
                <span class="kv-val">{{ wo.remark }}</span>
              </div>
              <div class="spec-kv-row">
                <span class="kv-label">制令创建</span>
                <span class="kv-val text-muted">
                  {{ wo.created_by_name || '系统自动' }} · {{ shortDate(wo.created_at) }}
                </span>
              </div>
            </div>
          </section>

          <!-- 卡片 6：定制工艺与图纸 -->
          <section v-if="wo.tech" class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-picture-outline" />
                <h2>工艺图纸与定制要求</h2>
              </div>
              <el-tag size="mini" :type="wo.tech.status === 'pending' ? 'warning' : 'success'" effect="plain">
                {{ wo.tech.status_label || wo.tech.status }}
              </el-tag>
            </div>

            <div class="spec-kv-list">
              <div class="spec-kv-row">
                <span class="kv-label">工艺负责人</span>
                <span class="kv-val font-medium">{{ wo.tech.tech_owner_name || '待工艺认领' }}</span>
              </div>
              <div v-if="wo.tech.released_at" class="spec-kv-row">
                <span class="kv-label">工艺图纸下发时间</span>
                <span class="kv-val code-mono">{{ wo.tech.released_at.slice(5, 16) }}</span>
              </div>
              <div v-if="wo.tech.drawings_updated_at" class="alert-notice-box">
                <i class="el-icon-warning text-warning" />
                <span>下发后图纸已更新（{{ wo.tech.drawings_updated_at.slice(5, 16) }}），车间报工终端将提示图纸已更新。</span>
              </div>
              <div class="spec-kv-row">
                <span class="kv-label">设计图纸附件</span>
                <span class="kv-val">
                  <template v-if="wo.tech.drawings && wo.tech.drawings.length">
                    <a
                      v-for="g in wo.tech.drawings"
                      :key="g.id"
                      class="table-link drawing-link code-mono"
                      :href="g.url"
                      target="_blank"
                      rel="noopener"
                    >
                      <i class="el-icon-document" /> {{ g.drawing_name }} V{{ g.version_no }}
                    </a>
                  </template>
                  <span v-else class="text-muted">未上传技术图纸</span>
                </span>
              </div>
              <div v-if="$can(['production.tech', 'production.tech.manage'])" class="spec-kv-row">
                <span class="kv-label">工艺工作台</span>
                <span class="kv-val">
                  <a class="action-link-theme" @click="$router.push(`/production/tech/${wo.id}`)">前往定制工艺中心 →</a>
                </span>
              </div>
            </div>
          </section>

          <!-- 卡片 7：材料成本归集与定额 -->
          <section class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-wallet" />
                <h2>{{ hasActualMaterialCost ? '材料成本归集与定额' : '材料成本预算与定额' }}</h2>
              </div>
              <el-tag size="mini" type="info" effect="plain">{{ hasActualMaterialCost ? (wo.material_cost_collection_complete ? '实际归集 · 未结算' : '部分归集 · 未结算') : '暂估预算 · 未结算' }}</el-tag>
            </div>

            <template v-if="materialCostPerUnit !== null && materialCostPerUnit !== undefined">
              <div class="cost-stat-box">
                <span class="cost-lbl">{{ hasActualMaterialCost ? '每台已归集材料成本' : '每台材料暂估成本' }}</span>
                <div class="cost-num font-mono text-success">{{ money(materialCostPerUnit) }}</div>
                <div class="cost-total-sub text-muted">{{ hasActualMaterialCost ? '工单已归集材料' : '工单总材料暂估' }}：{{ money(materialCostTotal) }}</div>
              </div>
              <div class="spec-kv-row">
                <span class="kv-label">标准工时</span>
                <span class="kv-val code-mono font-medium">每台设备 {{ stdHours(wo.standard_minutes_per_unit) }} 小时</span>
              </div>
              <p class="cost-footnote text-muted">
                {{ hasActualMaterialCost ? (wo.material_cost_collection_complete ? '依据正式收料金额与已结算开料净耗汇总；人工、外协及后到费用仍待制造结算。' : '仅汇总已发生的收料与已结算开料净耗，按计划数量折算每台；其余材料及制造费用仍待归集和结算。') : '按现存加权平均成本或物料标准成本、最近采购价估算；实际材料按正式用料归集，人工及其他费用待制造结算。' }}
              </p>
            </template>
            <div v-else class="text-muted p-2">
              当前账号无权查看成本金额数据。标准工时：每台设备 {{ stdHours(wo.standard_minutes_per_unit) }} 小时。
            </div>
          </section>

          <!-- 卡片 8：操作与流转日志 -->
          <section class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-document-copy" />
                <h2>工单操作流转日志</h2>
              </div>
              <span class="header-desc-hint">最近 {{ wo.logs ? wo.logs.slice(0, 8).length : 0 }} 条</span>
            </div>

            <div v-if="!wo.logs || !wo.logs.length" class="text-muted p-2">暂无操作日志记录</div>
            <div v-else class="logs-timeline-list">
              <div v-for="log in wo.logs.slice(0, 8)" :key="log.id" class="log-timeline-row">
                <span class="log-date code-mono text-muted">{{ shortDate(log.created_at) }}</span>
                <div class="log-body">
                  <span class="log-operator font-medium">{{ log.operator_name || '系统自动' }}</span>
                  <span class="log-content">{{ log.content }}</span>
                </div>
              </div>
            </div>
          </section>
        </aside>
      </main>
    </template>

    <!-- 调整工单对话框 (对齐主数据中心规范) -->
    <el-dialog
      title="调整生产工单参数"
      :visible.sync="adjust.visible"
      width="600px"
      custom-class="master-style-dialog"
      :close-on-click-modal="false"
    >
      <el-form label-position="top" size="small" class="adj-form">
        <el-form-item label="生产负责人">
          <el-select
            v-model="adjust.owner_legacy_id"
            filterable
            remote
            clearable
            :remote-method="searchOwners"
            placeholder="搜索生产负责人姓名"
            @focus="searchOwners('')"
          >
            <el-option v-for="u in owners" :key="u.id" :label="u.name" :value="u.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="计划开工日期">
          <el-date-picker v-model="adjust.planned_start_date" type="date" value-format="yyyy-MM-dd" placeholder="选择计划开工日期" />
        </el-form-item>
        <el-form-item label="计划完工日期">
          <el-date-picker v-model="adjust.planned_finish_date" type="date" value-format="yyyy-MM-dd" placeholder="选择计划完工日期" />
        </el-form-item>
        <el-form-item label="优先级">
          <el-radio-group v-model="adjust.priority" size="small" :disabled="priorityLocked">
            <el-radio-button :label="1">特急</el-radio-button><el-radio-button :label="2">加急</el-radio-button><el-radio-button :label="3">普通</el-radio-button><el-radio-button :label="4">可延后</el-radio-button>
          </el-radio-group>
          <div v-if="priorityLocked" class="field-hint">订单工单跟着订单走，请销售负责人在订单上调整。</div>
        </el-form-item>
        <el-form-item v-if="wo && wo.source_type === 'stock' && wo.status === 'planned'" label="生产计划数量">
          <el-input-number v-model="adjust.planned_qty" :min="0.0001" :max="100000" controls-position="right" />
        </el-form-item>
        <template v-if="wo && !wo.started">
          <el-form-item label="制造 BOM（未开工前可调整更换）">
            <el-select v-model="adjust.bom_id" placeholder="选择适用的 BOM 版本">
              <el-option
                v-for="b in adjust.bomOptions"
                :key="b.id"
                :label="`${bomLabel(b.bom_name, b.bom_no, b.version)}${b.is_default ? '（默认）' : ''}`"
                :value="b.id"
              />
            </el-select>
          </el-form-item>
          <el-form-item label="工艺路线（未开工前可调整更换）">
            <el-select v-model="adjust.routing_id" placeholder="选择工艺路线版本">
              <el-option
                v-for="r in adjust.routingOptions"
                :key="r.id"
                :label="`${r.routing_name} V${r.version}${r.is_default ? '（默认）' : ''}`"
                :value="r.id"
              />
            </el-select>
          </el-form-item>
        </template>
        <el-form-item label="工单调整备注" class="full">
          <el-input v-model="adjust.remark" type="textarea" :rows="3" maxlength="500" placeholder="填写本次参数调整的业务原因..." />
        </el-form-item>
      </el-form>
      <p v-if="wo && wo.source_type === 'sales_order'" class="dialog-hint text-muted">
        * 销售订单关联工单的生产数量与销售订单严格绑定，如需修改数量请先发起销售订单变更。
      </p>
      <span slot="footer">
        <el-button size="small" @click="adjust.visible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :loading="saving" @click="saveAdjust">保存调整</el-button>
      </span>
    </el-dialog>

    <el-dialog
      title="指定编号收料"
      :visible.sync="serialReceive.visible"
      width="640px"
      custom-class="master-style-dialog"
      :close-on-click-modal="false"
    >
      <el-form label-position="top" size="small">
        <el-form-item label="收料设备数（不填收齐整单）">
          <el-input-number v-model="serialReceive.units" :min="1" :max="100000" controls-position="right" />
        </el-form-item>
        <el-form-item
          v-for="row in serialReceive.rows"
          :key="row.material_id"
          :label="`${row.component_item_name}（每台 ${qty(row.per_unit_qty)}，未收 ${qty(row.remaining_qty)} ${row.unit_name || ''}）`"
        >
          <el-select v-model="serialReceive.picked[row.material_id]" multiple filterable placeholder="按装配顺序选择编号，不选由系统分配">
            <el-option
              v-for="s in row.serials"
              :key="s.id"
              :label="`${s.serial_no} · ${s.warehouse_name || ''} ${s.location_code || ''}`"
              :value="s.id"
            />
          </el-select>
        </el-form-item>
      </el-form>
      <p v-if="!serialReceive.rows.length" class="dialog-hint text-muted">本道没有需要指定编号的追溯物料，直接收料即可。</p>
      <p class="dialog-hint text-muted">* 所选编号按顺序依次装到尚未配号的设备，数量须与本次应收数量一致；未指定的物料由系统按先入先用分配。</p>
      <span slot="footer">
        <el-button size="small" @click="serialReceive.visible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :loading="saving" @click="submitSerialReceive">确认收料</el-button>
      </span>
    </el-dialog>

    <el-dialog
      title="互换部件编号"
      :visible.sync="swap.visible"
      width="480px"
      custom-class="master-style-dialog"
      :close-on-click-modal="false"
    >
      <p v-if="swap.component" class="dialog-hint">
        {{ swap.unitSerial }} 当前装 {{ swap.component.component_item_name }} {{ swap.component.component_serial_no }}，与哪台设备互换？
      </p>
      <el-select v-model="swap.targetId" filterable size="small" placeholder="选择本工单另一台设备的同种部件">
        <el-option v-for="o in swapOptions" :key="o.id" :label="`${o.unit_serial} · ${o.component_serial_no}`" :value="o.id" />
      </el-select>
      <span slot="footer">
        <el-button size="small" @click="swap.visible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :loading="saving" :disabled="!swap.targetId" @click="submitSwap">确认互换</el-button>
      </span>
    </el-dialog>

    <el-dialog
      title="登记部件编号"
      :visible.sync="assign.visible"
      width="480px"
      custom-class="master-style-dialog"
      :close-on-click-modal="false"
    >
      <p v-if="assign.component" class="dialog-hint">
        把 {{ assign.component.component_item_name }} {{ assign.component.serial_no }} 装到哪一台设备？
      </p>
      <el-select v-model="assign.unitId" filterable size="small" placeholder="选择本工单还在生产中的一台设备">
        <el-option v-for="u in assignUnits" :key="u.id" :label="`${u.serial_no || ('第 ' + u.unit_no + ' 台')} · ${u.status_label}`" :value="u.id" />
      </el-select>
      <span slot="footer">
        <el-button size="small" @click="assign.visible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :loading="saving" :disabled="!assign.unitId" @click="submitAssign">确认登记</el-button>
      </span>
    </el-dialog>

    <el-dialog
      title="工序派工"
      :visible.sync="dispatch.visible"
      width="460px"
      custom-class="master-style-dialog"
      :close-on-click-modal="false"
    >
      <p class="dialog-hint">{{ dispatch.title }}。前道还没交过来也可以先派，设备到了接单人就能开始。</p>
      <el-select
        v-model="dispatch.employee"
        filterable
        remote
        :remote-method="searchDispatchPeople"
        size="small"
        placeholder="输入姓名搜索能做这道工序的人"
        style="width: 100%"
        @focus="searchDispatchPeople('')"
      >
        <el-option v-for="p in dispatch.people" :key="p.id" :label="p.name" :value="p.id" />
      </el-select>
      <span slot="footer">
        <el-button size="small" @click="dispatch.visible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-theme-create" :loading="saving" :disabled="!dispatch.employee" @click="saveDispatch">确认派工</el-button>
      </span>
    </el-dialog>

    <material-return-dialog
      :visible.sync="returnDialog.visible"
      :work-order-id="wo ? wo.id : 0"
      :operation-id="returnDialog.opId"
      :operation-name="returnDialog.opName"
      @done="d => { if (d) wo = d }"
    />
  </div>
</template>

<script>
import { getWorkOrder, getWorkOrderOptions, updateWorkOrder, releaseWorkOrder, pauseWorkOrder, resumeWorkOrder, cancelWorkOrder, createSemiWorkOrder, receiveOperationMaterials, getOperationSerialOptions, swapUnitComponents, assignUnitComponent, settleWorkOrderScrap, cancelMaterialReturn, materialDeliveryAction, taskAction, listTaskPeople } from '../../../api/erp/production'
import { HERO_PILL, qty, money, shortDate, bomLabel, errorText, isCancel, searchProductionUsers } from './mfg-helpers'
import WorkOrderCuttingPanel from './WorkOrderCuttingPanel.vue'
import MaterialReturnDialog from './MaterialReturnDialog.vue'
import ShortagePurchaseDialog from '../purchase/ShortagePurchaseDialog.vue'
import unitOptionsMixin from './unit-options-mixin'

const STEPS = [
  { value: 'planned', label: '计划制令' },
  { value: 'released', label: '工单已下达' },
  { value: 'in_progress', label: '车间生产中' },
  { value: 'completed', label: '整机已完工' },
  { value: 'closed', label: '工单已关闭' }
]

export default {
  name: 'WorkOrderDetail',
  components: { WorkOrderCuttingPanel, MaterialReturnDialog, ShortagePurchaseDialog },
  mixins: [unitOptionsMixin],
  data: () => ({
    loading: false,
    saving: false,
    wo: null,
    owners: [],
    unitLimit: 50,
    serialReceive: { visible: false, opId: null, units: undefined, rows: [], picked: {} },
    swap: { visible: false, unitId: null, unitSerial: '', component: null, targetId: null },
    assign: { visible: false, component: null, unitId: null },
    returnDialog: { visible: false, opId: 0, opName: '' },
    dispatch: { visible: false, op: null, title: '', employee: null, people: [] },
    shortageDialog: false,
    steps: STEPS,
    today: new Date().toISOString().slice(0, 10),
    adjust: {
      visible: false,
      owner_legacy_id: null,
      planned_start_date: '',
      planned_finish_date: '',
      priority: 3,
      planned_qty: 1,
      bom_id: null,
      routing_id: null,
      remark: '',
      bomOptions: [],
      routingOptions: []
    }
  }),
  computed: {
    priorityLocked() {
      const wo = this.wo
      return !!wo && (!!wo.sales_order_id || (wo.source_type === 'work_order' && !!wo.sales_order_no))
    },
    canDispatch() {
      return !!this.wo && ['released', 'in_progress'].includes(this.wo.status) && this.$can('production.task.assign')
    },
    swapOptions() {
      const c = this.swap.component
      if (!c || !this.wo) return []
      return (this.wo.units || []).filter(u => u.id !== this.swap.unitId && this.canSwap(u))
        .flatMap(u => (u.components || []).filter(x => x.component_item_id === c.component_item_id)
          .map(x => ({ id: x.id, unit_serial: u.serial_no, component_serial_no: x.component_serial_no })))
    },
    canAssign() {
      return !!this.wo && this.$can('production.serial.assign') && ['released', 'in_progress', 'paused'].includes(this.wo.status)
    },
    assignUnits() {
      return this.wo ? (this.wo.units || []).filter(u => this.canSwap(u)) : []
    },
    hasActualMaterialCost() {
      return this.wo && this.wo.actual_material_cost_amount !== null && this.wo.actual_material_cost_amount !== undefined
    },
    materialCostPerUnit() {
      return this.wo ? (this.hasActualMaterialCost ? this.wo.actual_material_cost_per_unit : this.wo.estimated_material_cost_per_unit) : null
    },
    materialCostTotal() {
      return this.wo ? (this.hasActualMaterialCost ? this.wo.actual_material_cost_amount : this.wo.estimated_material_cost_per_unit * this.wo.planned_qty) : null
    },
    canReceiveMaterials() {
      return this.$can('production.task.execute') || this.$can('production.work_order.edit')
    },
    canPurchaseShortage() {
      return this.wo && ['planned', 'released', 'in_progress', 'paused'].includes(this.wo.status) && this.$can('purchase.production_shortage.convert') &&
        ((this.wo.purchase_shortage || []).length > 0 || (this.wo.kitting || []).some(k => k.materials.some(m => m.purchasable && m.shortage_qty > 0)))
    },
    canReturnMaterials() {
      return this.wo && ['released', 'in_progress', 'paused'].includes(this.wo.status) &&
        (this.$can('production.material_return.request') || this.$can('production.material_return.approve'))
    },
    materialKittingOps() {
      if (!this.wo || !this.wo.kitting) return []
      return this.wo.kitting.filter(k => (k.materials && k.materials.length > 0) || k.receive_pending || this.kitIssued(k))
    },
    noMaterialKittingOps() {
      if (!this.wo || !this.wo.kitting) return []
      return this.wo.kitting.filter(k => (!k.materials || !k.materials.length) && !k.receive_pending && !this.kitIssued(k))
    },
    productName() {
      return this.wo ? (this.wo.product_name || this.wo.item_name) : ''
    },
    heroPill() {
      return HERO_PILL[this.wo.status] || 'p-n'
    },
    stepValue() {
      return this.wo.status === 'paused' ? (this.wo.started ? 'in_progress' : 'released') : this.wo.status
    },
    stepIndex() {
      return STEPS.findIndex(s => s.value === this.stepValue)
    },
    prodOps() {
      return (this.wo.operations || []).filter(o => o.execution_context === 'production')
    },
    shipOps() {
      return (this.wo.operations || []).filter(o => o.execution_context !== 'production')
    },
    visibleUnits() {
      return (this.wo.units || []).slice(0, this.unitLimit)
    },
    canAdjust() {
      return !['completed', 'closed', 'cancelled'].includes(this.wo.status)
    },
    canCancel() {
      const orderOpen = this.wo.source_type === 'sales_order' && (!this.wo.sales_order || this.wo.sales_order.order_status === 'confirmed')
      return ['planned', 'released', 'paused'].includes(this.wo.status) && !this.wo.started && !orderOpen
    },
    dueDiff() {
      if (!this.wo.required_date || !this.wo.planned_finish_date) return null
      return Math.round((new Date(this.wo.required_date) - new Date(this.wo.planned_finish_date)) / 86400000)
    },
    barPercent() {
      const start = this.wo.planned_start_date && new Date(this.wo.planned_start_date)
      const end = (this.wo.required_date || this.wo.planned_finish_date) && new Date(this.wo.required_date || this.wo.planned_finish_date)
      if (!start || !end || end <= start) return 0
      return Math.max(0, Math.min(100, Math.round((new Date(this.today) - start) / (end - start) * 100)))
    }
  },
  watch: {
    'wo.kitting'(kitting) {
      this.ensureUnitOptions((kitting || []).flatMap(k => (k.materials || []).map(m => m.component_item_id)))
    },
    '$route.params.id'() {
      this.load()
    }
  },
  created() {
    this.load()
  },
  methods: {
    qty,
    money,
    shortDate,
    bomLabel,
    isFullSpanKittingCard(idx, k) {
      const total = this.materialKittingOps.length
      if (total === 1) return true
      if (total % 2 === 1 && idx === total - 1 && k.materials && k.materials.length > 2) return true
      return false
    },
    openDispatch(row) {
      this.dispatch = { visible: true, op: row.id, title: `${row.operation_name} · ${row.owner_name ? '现在是 ' + row.owner_name + ' 在做' : '还没人接'}`, employee: null, people: [] }
    },
    async searchDispatchPeople(keyword) {
      if (!this.dispatch.op) return
      try {
        const { data } = await listTaskPeople(this.dispatch.op, { keyword: keyword || undefined })
        this.dispatch.people = data.data || []
      } catch (e) {
        this.dispatch.people = []
      }
    },
    async saveDispatch() {
      this.saving = true
      try {
        const { data } = await taskAction(this.dispatch.op, { action: 'assign', employee_legacy_id: this.dispatch.employee })
        this.$message.success(data.message || '已派工')
        this.dispatch.visible = false
        this.load()
      } catch (e) {
        this.$message.error(errorText(e, '派工失败'))
      } finally {
        this.saving = false
      }
    },
    async load() {
      this.loading = true
      try {
        const { data } = await getWorkOrder(this.$route.params.id)
        this.wo = data.data
      } catch (e) {
        this.$message.error(errorText(e, '工单详情加载失败'))
      } finally {
        this.loading = false
      }
    },
    unitStatusType(status) {
      const map = {
        planned: 'info',
        released: 'primary',
        in_progress: 'warning',
        paused: 'warning',
        completed: 'success',
        closed: 'info',
        cancelled: 'info'
      }
      return map[status] || 'info'
    },
    unitCount(status) {
      return (this.wo.units || []).filter(u => u.status === status).length
    },
    opStatus(status) {
      return ({ pending: '未开工', in_progress: '在制加工', done: '已完工' })[status] || status
    },
    stdHours(minutes) {
      return Number((Number(minutes || 0) / 60).toFixed(2))
    },
    hours(minutes) {
      return minutes >= 60 ? `${Number((minutes / 60).toFixed(1))} 小时` : `${Math.round(minutes)} 分钟`
    },
    unitOpIndex(u) {
      return this.prodOps.findIndex(o => o.id === u.current_operation_id)
    },
    cellState(u, i) {
      if (['stored', 'completed'].includes(u.status)) return 'done'
      if (u.status === 'scrapped' || u.status === 'cancelled') return 'off'
      if (u.status === 'waiting') return ''
      const cur = this.unitOpIndex(u)
      if (cur < 0) return i === 0 ? (u.status === 'rework' ? 'bad' : 'doing') : ''
      if (i < cur) return 'done'
      if (i === cur) return u.status === 'rework' ? 'bad' : 'doing'
      return ''
    },
    cellText(u, i) {
      const s = this.cellState(u, i)
      return ({ done: '✓ 合格', doing: '● 在制', bad: '返修中', off: u.status_label })[s] || '—'
    },
    kitLabel(k) {
      if (k.status === 'short') return `缺料 ${k.shortage_count} 项`
      return k.status === 'ready' ? '物料齐套' : '免领料工序'
    },
    openOrder() {
      if (this.wo.sales_order_id) {
        this.$router.push(`/sales/orders/${this.wo.sales_order_id}/detail`)
      }
    },
    openWorkOrder(id) {
      if (id) this.$router.push(`/production/work-orders/${id}`)
    },
    async signDelivery(d) {
      try {
        await this.$confirm(`核对 ${d.delivery_no} 的实物：${d.summary}。数量和物料都对就签收。`, '签收仓库送料', { confirmButtonText: '签收', type: 'info' })
      } catch (e) {
        return
      }
      await this.deliveryAction(d, 'sign', {})
    },
    async rejectDelivery(d) {
      let reason
      try {
        reason = (await this.$prompt(`${d.delivery_no}：${d.summary}。写明哪里不对（如少了几个、拿错型号），库管会补送或收回。`, '拒收', {
          inputValidator: v => !!(v && v.trim()) || '请写明拒收原因'
        })).value.trim()
      } catch (e) {
        return
      }
      await this.deliveryAction(d, 'reject', { reason })
    },
    async deliveryAction(d, action, data) {
      this.saving = true
      try {
        const res = await materialDeliveryAction(d.id, action, { ...data, with_detail: true })
        this.$message.success(res.data.message || '已处理')
        if (res.data.data && res.data.data.kitting) this.wo = res.data.data
        else this.load()
      } catch (e) {
        this.$message.error(errorText(e, '操作失败'))
      } finally {
        this.saving = false
      }
    },
    openPurchaseRequest(d) {
      if (d.purchase_request_id && d.request_status !== 'deleted' && this.$can('purchase.request')) this.$router.push(`/purchase/requests/${d.purchase_request_id}/detail`)
    },
    async makeSemi(m) {
      let value
      try {
        const res = await this.$prompt(`${m.component_item_name} 缺料，给下料组开半成品工单，做完入半成品仓后总装再收料。开多少？`, '开半成品工单', {
          inputValue: String(m.make_qty),
          inputValidator: v => Number(v) > 0 || '数量必须大于 0'
        })
        value = Number(res.value)
      } catch (e) {
        if (isCancel(e)) return
      }
      if (value > 0) {
        await this.run(() => createSemiWorkOrder(this.wo.id, { component_item_id: m.component_item_id, qty: value }), '半成品工单已开')
      }
    },
    async receiveMaterials(k) {
      let value
      try {
        const res = await this.$prompt(`${k.operation_name}收料：收料后从库存扣减，收够才能开工。收几台的料？不填就收齐整单。`, '工序收料', {
          inputPlaceholder: '不填 = 收齐整单',
          inputValidator: v => !v || (Number.isInteger(Number(v)) && Number(v) > 0) || '请填正整数设备数'
        })
        value = res.value
      } catch (e) {
        if (isCancel(e)) return
        return
      }
      const opId = Number(String(k.operation_key).replace(/^o/, ''))
      await this.run(() => receiveOperationMaterials(this.wo.id, opId, value ? { units: Number(value) } : {}), '已收料')
    },
    async openSerialReceive(k) {
      const opId = Number(String(k.operation_key).replace(/^o/, ''))
      try {
        const { data } = await getOperationSerialOptions(this.wo.id, opId)
        const rows = data.data || []
        const picked = {}
        rows.forEach(r => { picked[r.material_id] = [] })
        this.serialReceive = { visible: true, opId, units: undefined, rows, picked }
      } catch (e) {
        this.$message.error(errorText(e, '读取可选编号失败'))
      }
    },
    async submitSerialReceive() {
      const f = this.serialReceive
      const selections = f.rows.filter(r => (f.picked[r.material_id] || []).length)
        .map(r => ({ material_id: r.material_id, serial_ids: f.picked[r.material_id] }))
      const payload = {}
      if (f.units) payload.units = Number(f.units)
      if (selections.length) payload.serial_selections = selections
      if (await this.run(() => receiveOperationMaterials(this.wo.id, f.opId, payload), '已收料')) f.visible = false
    },
    kitIssued(k) {
      return (k.materials || []).some(m => m.issue_mode !== 'cutting' && Number(m.issued_qty) > 0)
    },
    openReturn(k) {
      this.returnDialog = { visible: true, opId: Number(String(k.operation_key).replace(/^o/, '')), opName: k.operation_name }
    },
    canCancelReturn(r) {
      const me = JSON.parse(localStorage.getItem('erp_user') || '{}')
      return this.$can('production.material_return.approve') || Number(me.legacy_id || me.id || 0) === Number(r.requested_by_legacy_id)
    },
    async cancelReturn(r) {
      try {
        await this.$confirm(`撤回退料申请 ${r.return_no}？`, '撤回退料', { type: 'warning' })
      } catch (e) {
        return
      }
      try {
        const { data } = await cancelMaterialReturn(r.id)
        this.$message.success(data.message || '已撤回')
        this.load()
      } catch (e) {
        this.$message.error(errorText(e, '撤回失败'))
      }
    },
    queueHint(m) {
      if (!(m.queue_shortage_qty > 0) || m.shortage_qty > 0) return ''
      const row = (this.wo.purchase_shortage || []).find(r => r.item_id === m.component_item_id)
      const ahead = row && row.ahead.length ? `（库存先给 ${row.ahead.map(a => a.work_order_no).join('、')}）` : ''
      return `排队后缺 ${qty(m.queue_shortage_qty)}${ahead}`
    },
    canSwap(u) {
      return this.$can('production.serial.assign') && ['released', 'in_progress', 'paused'].includes(this.wo.status) &&
        ['waiting', 'in_progress', 'rework', 'completed'].includes(u.status)
    },
    openSwap(u, c) {
      this.swap = { visible: true, unitId: u.id, unitSerial: u.serial_no, component: c, targetId: null }
    },
    async submitSwap() {
      const ok = await this.run(() => swapUnitComponents(this.wo.id, { first_id: this.swap.component.id, second_id: this.swap.targetId }), '部件编号已互换')
      if (ok) this.swap.visible = false
    },
    openAssign(c) {
      this.assign = { visible: true, component: c, unitId: null }
    },
    async submitAssign() {
      const ok = await this.run(() => assignUnitComponent(this.wo.id, { serial_id: this.assign.component.serial_id, unit_id: this.assign.unitId }), '部件编号已登记')
      if (ok) this.assign.visible = false
    },
    async settleScrap() {
      let remark = ''
      try {
        const r = await this.$prompt('报废设备上的料已退料“报废设备拆回”，或确认没有可回收的料。可填写说明：', '结束工单', { inputPlaceholder: '选填', confirmButtonText: '确认', cancelButtonText: '取消' })
        remark = r.value || ''
      } catch (e) {
        return
      }
      await this.run(() => settleWorkOrderScrap(this.wo.id, { remark }), '已确认')
    },
    openSerial(sn) {
      if (sn && this.$can('production.serial.view')) {
        this.$router.push({ path: '/production/serials', query: { sn } })
      }
    },
    async searchOwners(keyword) {
      try {
        this.owners = await searchProductionUsers(keyword)
      } catch (e) {
        this.owners = []
      }
    },
    async run(fn, okText) {
      this.saving = true
      try {
        const { data } = await fn()
        this.wo = data.data
        this.$message.success(data.message || okText)
        return true
      } catch (e) {
        this.$message.error(errorText(e, '操作失败'))
        if (e && e.response && e.response.status === 409) this.load()
        return false
      } finally {
        this.saving = false
      }
    },
    async release() {
      try {
        await this.$confirm(
          '下达后将自动生成整机序列号并允许车间开展报工。缺料提示不阻断下达，确认下达？',
          '生产制令下达确认',
          { confirmButtonText: '确认下达', type: 'info' }
        )
      } catch (e) {
        return
      }
      await this.run(() => releaseWorkOrder(this.wo.id, { expected_version: this.wo.business_version }), '工单已成功下达')
    },
    async pause() {
      let reason
      try {
        const res = await this.$prompt('暂停后车间将暂停报工过站，请录入暂停原因：', '暂停生产工单', {
          inputPlaceholder: '例如：客户临时申请变更外观配置',
          inputValidator: v => !!(v && v.trim()) || '请录入暂停原因'
        })
        reason = res.value
      } catch (e) {
        if (isCancel(e)) return
      }
      if (reason) {
        await this.run(() => pauseWorkOrder(this.wo.id, { expected_version: this.wo.business_version, reason: reason.trim() }), '工单已暂停生产')
      }
    },
    async resume() {
      await this.run(() => resumeWorkOrder(this.wo.id, { expected_version: this.wo.business_version }), '工单已恢复正常生产')
    },
    async cancel() {
      let reason
      try {
        const res = await this.$prompt('取消后关联序列号将作废终止，请填写取消原因：', '取消生产工单', {
          type: 'warning',
          inputPlaceholder: '请填写取消原因',
          inputValidator: v => !!(v && v.trim()) || '请填写取消原因'
        })
        reason = res.value
      } catch (e) {
        if (isCancel(e)) return
      }
      if (reason) {
        await this.run(() => cancelWorkOrder(this.wo.id, { expected_version: this.wo.business_version, reason: reason.trim() }), '工单已成功取消')
      }
    },
    async openAdjust() {
      const wo = this.wo
      Object.assign(this.adjust, {
        visible: true,
        owner_legacy_id: wo.owner_legacy_id,
        planned_start_date: wo.planned_start_date,
        planned_finish_date: wo.planned_finish_date,
        priority: wo.priority || 3,
        planned_qty: wo.planned_qty,
        bom_id: wo.bom_id,
        routing_id: wo.routing_id,
        remark: wo.remark || '',
        bomOptions: [],
        routingOptions: []
      })
      this.owners = wo.owner_legacy_id ? [{ id: wo.owner_legacy_id, name: wo.owner_name }] : []
      if (!wo.started) {
        try {
          const { data } = await getWorkOrderOptions(wo.id)
          this.adjust.bomOptions = data.data.bom_options || []
          this.adjust.routingOptions = data.data.routing_options || []
        } catch (e) {
          this.$message.warning(errorText(e, 'BOM / 工艺路线选项加载失败'))
        }
      }
    },
    async saveAdjust() {
      const a = this.adjust
      const wo = this.wo
      const payload = { expected_version: wo.business_version }
      const owner = this.owners.find(u => u.id === a.owner_legacy_id)
      if (a.owner_legacy_id !== wo.owner_legacy_id) {
        payload.owner_legacy_id = a.owner_legacy_id || null
        payload.owner_name = owner ? owner.name : null
      }
      ;['planned_start_date', 'planned_finish_date', 'remark'].forEach(f => {
        if ((a[f] || '') !== (wo[f] || '')) payload[f] = a[f] || null
      })
      if (!this.priorityLocked && a.priority !== (wo.priority || 3)) payload.priority = a.priority
      if (!wo.started) {
        if (a.bom_id !== wo.bom_id || (wo.status === 'planned' && (wo.block_reason || '').includes('虚拟件'))) payload.bom_id = a.bom_id
        if (a.routing_id !== wo.routing_id) payload.routing_id = a.routing_id
      }
      if (wo.source_type === 'stock' && wo.status === 'planned' && Number(a.planned_qty) !== Number(wo.planned_qty)) {
        payload.planned_qty = a.planned_qty
      }
      if (Object.keys(payload).length === 1) {
        this.$message.info('未检测到需要保存的修改项')
        return
      }
      if (await this.run(() => updateWorkOrder(wo.id, payload), '工单参数已成功调整')) {
        a.visible = false
      }
    }
  }
}
</script>

<style scoped>
.field-hint { color: #909399; font-size: 12px; line-height: 1.6; }
.work-order-detail-page {
  padding: 16px 20px 24px;
  background: #f8fafc;
  min-height: calc(100vh - 54px);
  box-sizing: border-box;
}

/* 顶部标头卡片 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #ffffff;
  padding: 14px 20px;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  border: 1px solid #e2e8f0;
  margin-bottom: 12px;
  gap: 16px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
}

.btn-back {
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #606266 !important;
  flex-shrink: 0;
}

.btn-back:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
  background: #f0fdf4 !important;
}

.head-icon {
  width: 38px;
  height: 38px;
  background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
  color: #008b4b;
  border-radius: 8px;
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
  gap: 8px;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.sku-code-tag {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 2px 10px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  color: #00763f;
  font-size: 13px;
  font-weight: 600;
  line-height: 1.4;
}

.urgent-tag {
  font-weight: 600;
}

.source-crumb-tag {
  font-size: 12px;
  color: #475569;
  background: #f8fafc;
  padding: 2px 8px;
  border-radius: 4px;
  border: 1px solid #e2e8f0;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  flex-shrink: 0;
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

.btn-action-light {
  border-radius: 4px;
  font-weight: 500;
}

/* 顶部工单主档与进度一体化卡片 */
.overview-master-card {
  margin-bottom: 16px;
  padding: 16px 20px;
}

.master-meta-grid {
  display: grid;
  grid-template-columns: 1.6fr 1.1fr 1.2fr 1.2fr 0.9fr 1fr;
  gap: 12px;
  padding-bottom: 14px;
  margin-bottom: 14px;
  border-bottom: 1px solid #f1f5f9;
}

.meta-cell {
  background: #f8fafc;
  border: 1px solid #eef2f6;
  border-radius: 6px;
  padding: 8px 12px;
  min-width: 0;
  display: flex;
  flex-direction: column;
  justify-content: center;
  gap: 4px;
}

.meta-cell-primary {
  background: linear-gradient(135deg, #f0fdf4 0%, #f8fafc 100%);
  border-color: #bbf7d0;
}

.meta-label {
  font-size: 11px;
  color: #64748b;
  line-height: 1.2;
}

.meta-val-main {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
}

.product-title {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.qty-pill {
  background: #008b4b;
  color: #ffffff;
  font-size: 12px;
  font-weight: 600;
  padding: 1px 8px;
  border-radius: 999px;
  flex-shrink: 0;
}

.meta-val {
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 进度与交期横幅行 */
.schedule-stepper-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 24px;
}

.stepper-wrap {
  flex: 1;
  min-width: 0;
}

.stepper-title {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  margin-bottom: 10px;
}

.stepper-flow {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.step-node {
  display: flex;
  align-items: center;
  gap: 6px;
}

.node-circle {
  width: 22px;
  height: 22px;
  border-radius: 50%;
  background: #f1f5f9;
  color: #94a3b8;
  display: grid;
  place-items: center;
  font-size: 11px;
  font-weight: 700;
}

.node-label {
  font-size: 13px;
  color: #64748b;
}

.step-completed .node-circle {
  background: #eaf7ef;
  color: #008b4b;
}

.step-completed .node-label {
  color: #1e293b;
}

.step-active .node-circle {
  background: #008b4b;
  color: #ffffff;
}

.step-active .node-label {
  color: #008b4b;
  font-weight: 700;
}

.node-divider {
  width: 24px;
  height: 2px;
  background: #e2e8f0;
  margin: 0 4px;
}

.step-completed .node-divider {
  background: #bbf7d0;
}

.due-summary-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 16px;
  min-width: 300px;
}

.due-box-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  margin-bottom: 4px;
}

.due-box-label {
  color: #64748b;
}

.due-diff-tag {
  font-size: 12px;
}

.due-main-date {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 6px;
}

.schedule-progress-bar {
  height: 6px;
  background: #e2e8f0;
  border-radius: 3px;
  overflow: hidden;
  margin-bottom: 6px;
}

.progress-fill {
  height: 100%;
  background: #008b4b;
  transition: width 0.3s ease;
}

.schedule-milestones {
  display: flex;
  justify-content: space-between;
  gap: 8px;
  font-size: 11px;
  color: #94a3b8;
}

/* 主内容双栏 */
.content-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 350px;
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
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  box-sizing: border-box;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 12px;
  margin-bottom: 14px;
  border-bottom: 1px solid #f1f5f9;
  flex-wrap: wrap;
  gap: 8px;
}

.header-left-group {
  display: flex;
  align-items: baseline;
  gap: 10px;
  flex-wrap: wrap;
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

.card-header-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.card-header-actions ::v-deep .cutting-panel .el-button {
  padding: 5px 10px;
  border: 1px solid #bbf7d0;
  background: #f0fdf4;
  color: #008b4b;
  border-radius: 4px;
  font-weight: 500;
}

.card-header-actions ::v-deep .cutting-panel .el-button:hover {
  background: #dcfce7;
  border-color: #86efac;
}

/* 序列号在制矩阵看板 */
.unit-status-counters {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.counter-pill {
  font-size: 12px;
  color: #475569;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  padding: 2px 8px;
  border-radius: 999px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.counter-pill.pill-success {
  background: #f0fdf4;
  border-color: #bbf7d0;
}

.counter-pill.pill-primary {
  background: #eff6ff;
  border-color: #bfdbfe;
}

.counter-pill.pill-danger {
  background: #fef2f2;
  border-color: #fecaca;
}

.counter-pill b {
  font-size: 13px;
}

.unit-table-container {
  overflow-x: auto;
}

.unit-matrix-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0 4px;
  min-width: 600px;
}

.unit-matrix-table th {
  font-size: 12px;
  color: #64748b;
  padding: 6px 8px;
  font-weight: 600;
}

.table-group-header th {
  background: #f8fafc;
  border-radius: 4px;
  text-align: center;
  font-size: 11px;
}

.table-group-header .col-sn,
.table-col-header .col-sn-sub {
  text-align: left;
}

.table-group-header .col-grp-prod {
  color: #008b4b;
}

.table-group-header .col-grp-ship {
  color: #2563eb;
}

.table-col-header th {
  border-bottom: 1px solid #e2e8f0;
  text-align: center;
}

.unit-matrix-row td {
  border-bottom: 1px solid #f1f5f9;
  padding-top: 6px;
  padding-bottom: 6px;
}

.unit-sn-cell {
  vertical-align: middle;
  padding: 6px 10px 6px 6px;
  min-width: 180px;
  max-width: 280px;
}

.sn-head-line {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.sn-text {
  font-size: 12px;
  font-weight: 700;
  color: #008b4b;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.sn-text:hover {
  text-decoration: underline;
}

.unit-sub-status {
  font-size: 11px;
}

.unit-comp-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  margin-top: 4px;
}

.unit-comp-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 1px 6px;
  font-size: 11px;
  color: #475569;
}

.comp-swap-btn {
  padding: 0 2px !important;
  font-size: 11px !important;
}

.matrix-cell-td {
  vertical-align: middle;
  padding: 4px 3px;
}

.matrix-cell {
  border-radius: 4px;
  background: #f1f5f9;
  color: #94a3b8;
  padding: 6px;
  font-size: 11px;
  text-align: center;
  min-height: 30px;
  box-sizing: border-box;
  display: flex;
  align-items: center;
  justify-content: center;
}

.matrix-cell.done {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #00763f;
  font-weight: 600;
}

.matrix-cell.doing {
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #b45309;
  font-weight: 600;
}

.matrix-cell.bad {
  background: #fef2f2;
  border: 1px solid #fecaca;
  color: #dc2626;
  font-weight: 600;
}

.matrix-cell.cell-dash {
  background: #ffffff;
  border: 1px dashed #cbd5e1;
  color: #94a3b8;
}

.matrix-cell.off {
  background: #f1f5f9;
  color: #94a3b8;
  text-decoration: line-through;
}

.more-expand-bar {
  text-align: center;
  padding-top: 10px;
}

.unbound-comp-panel {
  margin-top: 10px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 6px;
  padding: 8px 12px;
}

.unbound-panel-title {
  font-size: 12px;
  font-weight: 600;
  color: #b45309;
  display: flex;
  align-items: center;
  gap: 6px;
  margin-bottom: 6px;
}

.unbound-chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.unbound-chip-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #ffffff;
  border: 1px solid #fcd34d;
  border-radius: 4px;
  padding: 2px 8px;
  font-size: 12px;
}

/* 工序工时看板 */
.op-status-tag {
  margin-left: 6px;
}

/* 齐套网格看板 */
.kitting-grid-wrap {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
}

.kitting-grid-wrap.kitting-grid-single {
  grid-template-columns: 1fr;
}

.kitting-op-card {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
  background: #ffffff;
  display: flex;
  flex-direction: column;
}

.kitting-op-card.kitting-op-span-full {
  grid-column: 1 / -1;
}

.kitting-op-card.kitting-shortage {
  border-color: #fca5a5;
  background: #fffafa;
}

.kitting-op-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  padding-bottom: 8px;
  border-bottom: 1px solid #f1f5f9;
  margin-bottom: 8px;
  flex-wrap: wrap;
}

.kitting-op-title-group {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.op-mat-count {
  font-size: 11px;
  margin-left: 2px;
}

.kitting-op-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.kitting-op-actions .el-button {
  padding: 2px 4px;
}

.op-title {
  font-size: 13px;
  color: #0f172a;
}

/* 工序齐套紧凑表格：让物料名称与数量紧邻对齐，告别左右大跨度空白 */
.materials-table-scroll {
  max-height: 260px;
  overflow-y: auto;
  border: 1px solid #eef2f6;
  border-radius: 5px;
  background: #ffffff;
}

.kitting-mat-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  table-layout: fixed;
}

.kitting-mat-table thead th {
  position: sticky;
  top: 0;
  z-index: 1;
  background: #f8fafc;
  color: #64748b;
  font-weight: 600;
  font-size: 11px;
  text-align: left;
  padding: 6px 10px;
  border-bottom: 1px solid #e2e8f0;
}

.kitting-mat-table tbody td {
  padding: 6px 10px;
  border-bottom: 1px solid #f1f5f9;
  vertical-align: middle;
  color: #334155;
}

.kitting-mat-table tbody tr:last-child td {
  border-bottom: none;
}

.kitting-mat-table tbody tr:hover {
  background: #f8fafc;
}

.kitting-mat-table tbody tr.row-shortage {
  background: #fff5f5;
}

.kitting-mat-table .col-mat-name {
  width: 36%;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.kitting-mat-table .col-mat-qty {
  width: 24%;
  white-space: nowrap;
}

.kitting-mat-table .col-mat-state {
  width: 26%;
}

.kitting-mat-table .col-mat-act {
  width: 14%;
  text-align: right;
  white-space: nowrap;
}

.mat-name-text {
  font-weight: 500;
  color: #1e293b;
}

.mat-alt-qty { margin-top: 2px; font-size: 12px; }
.mat-qty-badge {
  display: inline-block;
  padding: 1px 7px;
  border-radius: 4px;
  font-size: 12px;
}

.mat-qty-badge.qty-ok {
  background: #f0fdf4;
  color: #00763f;
  font-weight: 600;
}

.mat-qty-badge.qty-short {
  background: #fef2f2;
  color: #dc2626;
  font-weight: 600;
}

.mat-auxiliary {
  font-size: 11px;
}

.material-sub-badges {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.mat-sub-tag {
  background: #eff6ff;
  color: #2563eb;
  padding: 0 5px;
  border-radius: 3px;
  font-size: 11px;
}

.mat-queue-hint {
  font-size: 11px;
}

.btn-make-semi {
  padding: 0 !important;
  font-size: 11px !important;
}

/* 免领料工序紧凑汇总条 */
.no-mat-ops-bar {
  margin-top: 10px;
  padding: 8px 12px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 12px;
}

.no-mat-bar-label {
  color: #475569;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.no-mat-bar-tags {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.no-mat-op-pill {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 2px 8px;
  color: #334155;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

/* 关联单据分组区 */
.kitting-related-docs {
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid #e2e8f0;
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px;
}

.related-doc-block {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 8px 12px;
}

.related-doc-head {
  margin-bottom: 6px;
  padding-bottom: 4px;
  border-bottom: 1px solid #eef2f6;
}

.related-doc-title {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.related-doc-title i {
  color: #008b4b;
}

.related-doc-list {
  display: flex;
  flex-direction: column;
  gap: 5px;
  max-height: 150px;
  overflow-y: auto;
}

.related-doc-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  font-size: 12px;
}

.related-doc-main {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  flex: 1;
  flex-wrap: wrap;
}

.mat-name {
  color: #334155;
  font-weight: 500;
}

.mat-qty-inline {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 0 6px;
  color: #00763f;
  font-weight: 600;
  font-size: 11px;
}

.mat-qty {
  flex-shrink: 0;
  color: #475569;
}

.related-doc-right {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

/* 成本与日志卡片 */
.cost-stat-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 14px;
  margin-bottom: 12px;
}

.cost-lbl {
  font-size: 12px;
  color: #64748b;
}

.cost-num {
  font-size: 22px;
  font-weight: 700;
  line-height: 1.2;
  margin-top: 4px;
}

.cost-total-sub {
  font-size: 12px;
  margin-top: 4px;
}

.cost-footnote {
  font-size: 12px;
  line-height: 1.5;
  margin-top: 10px;
}

.logs-timeline-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
  max-height: 220px;
  overflow-y: auto;
  padding-right: 4px;
}

.log-timeline-row {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  font-size: 12px;
  padding-bottom: 6px;
  border-bottom: 1px dashed #f1f5f9;
}

.log-date {
  flex-shrink: 0;
  font-size: 11px;
}

.log-body {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.log-operator {
  color: #334155;
}

.log-content {
  color: #64748b;
}

/* 右侧栏样式：标签定宽、数值紧邻左对齐，消除名称与数值之间的大跨度空白 */
.spec-kv-list {
  display: flex;
  flex-direction: column;
}

.spec-kv-row {
  display: flex;
  justify-content: flex-start;
  align-items: flex-start;
  padding: 7px 0;
  border-bottom: 1px solid #f1f5f9;
  gap: 12px;
  font-size: 13px;
}

.spec-kv-row:last-child {
  border-bottom: none;
}

.kv-label {
  color: #64748b;
  width: 96px;
  flex-shrink: 0;
}

.kv-val {
  color: #0f172a;
  text-align: left;
  flex: 1;
  min-width: 0;
  word-break: break-all;
}

.alert-notice-box {
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 6px;
  padding: 8px 10px;
  font-size: 12px;
  color: #92400e;
  display: flex;
  align-items: flex-start;
  gap: 6px;
  margin: 6px 0;
  line-height: 1.5;
}

.drawing-link {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin-right: 8px;
}

.normal-status-box {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 12px 14px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
}

.normal-status-box i {
  font-size: 24px;
}

.normal-status-content strong {
  font-size: 13px;
  color: #00763f;
}

.normal-status-content p {
  margin: 2px 0 0;
  font-size: 12px;
  color: #15803d;
}

.attention-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.attention-item {
  border-radius: 6px;
  padding: 10px 12px;
  border-left: 3px solid;
}

.att-danger {
  border-left-color: #ef4444;
  background: #fef2f2;
}

.att-danger .att-head {
  color: #b91c1c;
}

.att-warning {
  border-left-color: #f59e0b;
  background: #fffbeb;
}

.att-warning .att-head {
  color: #b45309;
}

.att-head {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
}

.att-text {
  margin: 4px 0 0 20px;
  font-size: 12px;
  color: #475569;
  line-height: 1.5;
}

.empty-state-notice {
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

.empty-state-notice i {
  font-size: 24px;
  color: #cbd5e1;
}

/* 弹窗调整表单 */
.adj-form {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 0 16px;
}

.adj-form .full {
  grid-column: 1 / -1;
}

.adj-form .el-select,
.adj-form .el-date-editor,
.adj-form .el-input-number {
  width: 100%;
}

.dialog-hint {
  font-size: 12px;
  margin-top: 8px;
  line-height: 1.5;
}

/* 通用工具类 */
.table-link {
  color: #008b4b;
  cursor: pointer;
  text-decoration: underline;
}

.lk-text {
  color: #008b4b;
  cursor: pointer;
  text-decoration: underline;
}

.action-link-theme {
  color: #008b4b;
  font-weight: 500;
  cursor: pointer;
}

.action-link-theme:hover {
  text-decoration: underline;
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

.font-normal {
  font-weight: 400;
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

.text-danger {
  color: #dc2626;
}

.text-muted {
  color: #94a3b8;
}

.text-truncate {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.ml-1 {
  margin-left: 4px;
}

.p-2 {
  padding: 8px;
}

/* 响应式断点 */
@media (max-width: 1280px) {
  .master-meta-grid {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
}

@media (max-width: 1100px) {
  .content-grid {
    grid-template-columns: 1fr;
  }
  .schedule-stepper-row {
    flex-direction: column;
    align-items: flex-start;
  }
  .due-summary-box {
    width: 100%;
    min-width: 0;
  }
}

@media (max-width: 768px) {
  .work-order-detail-page {
    padding: 12px 10px 20px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-start;
  }
  .master-meta-grid,
  .kitting-grid-wrap,
  .kitting-related-docs,
  .adj-form {
    grid-template-columns: 1fr;
  }
}
</style>
