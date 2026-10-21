<template>
  <div class="work-order-detail-page" v-loading="loading">
    <template v-if="wo">
      <!-- 页面全局头部卡片 (100% 对齐主数据中心规范) -->
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
              <el-tag v-if="wo.is_urgent" size="small" type="danger" effect="dark" class="urgent-tag">
                加急制令
              </el-tag>
              <span class="source-crumb-tag">
                <template v-if="wo.source_type === 'sales_order'">
                  <i class="el-icon-tickets text-primary" />
                  关联销售订单：<b class="code-mono lk-text" @click="openOrder">{{ wo.sales_order_no }}</b>
                  <template v-if="wo.sales_order_line_no">（第 {{ wo.sales_order_line_no }} 行）</template>
                  · {{ wo.customer_name }}
                </template>
                <template v-else>
                  <i class="el-icon-box text-muted" />
                  需求来源：常规备货 · {{ wo.stock_reason_label || '常规备货' }}
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

      <!-- 全局统一页面业务提示条 -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>
          制造产品：<b>{{ productName }}</b> × <b>{{ qty(wo.planned_qty) }} {{ wo.unit_name || '台' }}</b>
          ｜ SKU 规格编码：<b class="code-mono">{{ wo.sku_code || '—' }}</b>
          ｜ 工艺路线：<b>{{ wo.routing ? `${wo.routing.routing_name} V${wo.routing.version}` : '未配置工艺路线' }}</b>
          ｜ 制造 BOM：<b>{{ wo.bom ? bomLabel(wo.bom.bom_name, wo.bom.bom_no, wo.bom.version) : '未配置 BOM' }}</b>
          ｜ 生产负责人：<b>{{ wo.owner_name || '未指定' }}</b>
          ｜ 目标入库仓：<b>{{ wo.warehouse_name || '物料默认仓库' }}</b>
        </span>
      </div>

      <!-- 计划交期与制程进度横幅卡片 -->
      <section class="detail-card schedule-stepper-card">
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
      </section>

      <!-- 双栏响应式主体工作区 -->
      <main class="content-grid">
        <!-- 左侧主体列 -->
        <div class="main-column">
          <!-- 卡片 1：整机序列号在制进度与工序过站看板 -->
          <section class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-cpu" />
                <h2>整机序列号在制进度与过站看板</h2>
              </div>
              <div class="unit-status-counters">
                <span class="counter-pill">已入库 <b class="text-success">{{ unitCount('stored') }}</b></span>
                <span class="counter-pill">待入库 <b>{{ unitCount('completed') }}</b></span>
                <span class="counter-pill">在制加工 <b class="text-primary">{{ unitCount('in_progress') }}</b></span>
                <span class="counter-pill">质量返修 <b class="text-danger">{{ unitCount('rework') }}</b></span>
                <span class="counter-pill">待开工 <b>{{ unitCount('waiting') }}</b></span>
              </div>
            </div>

            <div v-if="!wo.units.length" class="empty-state-notice">
              <i class="el-icon-info" />
              <span>{{ wo.status === 'planned' ? '工单下达后将按台自动生成整机序列号并开启逐台过站追溯' : '本工单未开启按台追溯（计划数量非整数或超出逐台跟踪上限）' }}</span>
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
                    <th>序列号 / 状态</th>
                    <th v-for="op in prodOps" :key="op.id" class="op-th">{{ op.operation_name }}</th>
                    <th v-if="!prodOps.length" class="op-th">—</th>
                    <th v-for="op in shipOps" :key="op.id" class="op-th ship-th">{{ op.operation_name }}</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="u in visibleUnits" :key="u.id">
                    <td class="unit-sn-cell">
                      <div class="sn-text code-mono" @click="openSerial(u.serial_no)">
                        <i class="el-icon-postcard text-success" />
                        {{ u.serial_no || ('第 ' + u.unit_no + ' 台') }}
                      </div>
                      <div class="unit-sub-status">
                        <span :class="u.status === 'rework' ? 'text-danger font-medium' : 'text-muted'">
                          {{ u.status === 'rework' ? `返修第 ${u.rework_count || 1} 次` : u.status_label }}
                        </span>
                        <span v-if="u.current_operation_name && ['in_progress', 'rework'].includes(u.status)" class="cur-op-sub">
                          · 当前工序：{{ u.current_operation_name }}
                        </span>
                        <span v-if="u.rework_count && u.status !== 'rework'" class="rework-history-sub">
                          · 曾返修 {{ u.rework_count }} 次
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
                        {{ u.status === 'stored' ? '待开发货单' : '' }}
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
            </div>
          </section>

          <!-- 卡片 2：工序加工与作业工时实时看板 -->
          <section v-if="prodOps.length && wo.status !== 'planned'" class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-time" />
                <h2>工序加工与作业工时实时看板</h2>
              </div>
              <span class="header-desc-hint">基于车间工序过站报工实时数据自动汇总结算</span>
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
                <el-table-column label="在制 / 滞留台数" width="130" align="center">
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
              </el-table>
            </div>
          </section>

          <!-- 卡片 3：工序物料齐套分析看板 -->
          <section class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-connection" />
                <h2>工序物料齐套分析看板</h2>
              </div>
              <span class="header-desc-hint">按工艺路线将 BOM 分解至工序校验物料齐套；缺料提示预警</span>
            </div>

            <div v-if="!wo.kitting.length" class="empty-state-notice">
              <i class="el-icon-box" />
              <span>本工单未配置工艺路线或制造 BOM，暂无法按工序测算物料齐套率</span>
            </div>

            <div v-else class="kitting-grid-wrap">
              <div
                v-for="k in wo.kitting"
                :key="k.operation_key"
                class="kitting-op-card"
                :class="{ 'kitting-shortage': k.status === 'short' }"
              >
                <div class="kitting-op-head">
                  <span class="op-title font-medium">{{ k.operation_name }}</span>
                  <el-tag
                    size="mini"
                    :type="k.status === 'short' ? 'danger' : (k.status === 'ready' ? 'success' : 'info')"
                    effect="plain"
                  >
                    {{ kitLabel(k) }}
                  </el-tag>
                </div>
                <div class="materials-list">
                  <div
                    v-for="m in k.materials"
                    :key="m.id || m.component_item_id"
                    class="material-row"
                  >
                    <span class="mat-name text-truncate" :title="m.component_item_name">{{ m.component_item_name }}</span>
                    <span v-if="!m.participates_in_kitting" class="text-muted mat-auxiliary">辅料（免齐套）</span>
                    <span v-else class="mat-qty code-mono" :class="{ 'text-danger font-medium': m.shortage_qty > 0 }">
                      {{ qty(m.covered_qty) }} / {{ qty(m.required_qty) }} {{ m.unit_name || '' }}
                    </span>
                  </div>
                  <div v-if="!k.materials.length" class="no-material-row text-muted">
                    本工序无领料需求
                  </div>
                </div>
              </div>
            </div>
          </section>

          <!-- 卡片 4 与 卡片 5 并排：材料成本预算与操作动态日志 -->
          <div class="two-subcard-grid">
            <!-- 材料成本预算 -->
            <section class="detail-card">
              <div class="card-header">
                <div class="header-title">
                  <i class="el-icon-wallet" />
                  <h2>材料成本预算与定额</h2>
                </div>
                <el-tag size="mini" type="info" effect="plain">暂估预算 · 未结算</el-tag>
              </div>

              <template v-if="wo.estimated_material_cost_per_unit !== null && wo.estimated_material_cost_per_unit !== undefined">
                <div class="cost-stat-box">
                  <span class="cost-lbl">每台材料暂估成本</span>
                  <div class="cost-num font-mono text-success">{{ money(wo.estimated_material_cost_per_unit) }}</div>
                  <div class="cost-total-sub text-muted">工单总材料暂估：{{ money(wo.estimated_material_cost_per_unit * wo.planned_qty) }}</div>
                </div>
                <div class="spec-kv-row">
                  <span class="kv-label">单件标准工时定额</span>
                  <span class="kv-val code-mono font-medium">每台 {{ wo.standard_minutes_per_unit || 0 }} 分钟</span>
                </div>
                <p class="cost-footnote text-muted">
                  依据库存加权平均成本计算暂估；实际领料、直接人工及制费在制造过站后归集并统一核算结算。
                </p>
              </template>
              <div v-else class="text-muted p-2">
                当前账号无权查看成本金额数据。台件标准工时定额：每台 {{ wo.standard_minutes_per_unit || 0 }} 分钟。
              </div>
            </section>

            <!-- 操作与流转日志 -->
            <section class="detail-card">
              <div class="card-header">
                <div class="header-title">
                  <i class="el-icon-document-copy" />
                  <h2>工单操作流转日志</h2>
                </div>
                <span class="header-desc-hint">记录最近 {{ wo.logs ? wo.logs.slice(0, 8).length : 0 }} 条操作</span>
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
          </div>
        </div>

        <!-- 右侧侧边栏 -->
        <aside class="side-column">
          <!-- 卡片 6：定制技术与图纸 -->
          <section v-if="wo.tech" class="detail-card">
            <div class="card-header">
              <div class="header-title">
                <i class="el-icon-picture-outline" />
                <h2>技术图纸与定制要求</h2>
              </div>
              <el-tag size="mini" :type="wo.tech.status === 'pending' ? 'warning' : 'success'" effect="plain">
                {{ wo.tech.status_label || wo.tech.status }}
              </el-tag>
            </div>

            <div class="spec-kv-list">
              <div class="spec-kv-row">
                <span class="kv-label">技术负责人</span>
                <span class="kv-val font-medium">{{ wo.tech.tech_owner_name || '待技术认领' }}</span>
              </div>
              <div v-if="wo.tech.released_at" class="spec-kv-row">
                <span class="kv-label">图纸下发时间</span>
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
                <span class="kv-label">技术工作台</span>
                <span class="kv-val">
                  <a class="action-link-theme" @click="$router.push(`/production/tech/${wo.id}`)">前往定制技术中心 →</a>
                </span>
              </div>
            </div>
          </section>

          <!-- 卡片 7：异常告警与关注事项 -->
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
          </section>

          <!-- 卡片 8：业务需求与单据关联 -->
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
        <el-form-item label="生产加急标记">
          <el-switch v-model="adjust.is_urgent" active-text="加急制令" />
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
  </div>
</template>

<script>
import { getWorkOrder, getWorkOrderOptions, updateWorkOrder, releaseWorkOrder, pauseWorkOrder, resumeWorkOrder, cancelWorkOrder } from '../../../api/erp/production'
import { HERO_PILL, qty, money, shortDate, bomLabel, errorText, isCancel, searchProductionUsers } from './mfg-helpers'

const STEPS = [
  { value: 'planned', label: '计划制令' },
  { value: 'released', label: '工单已下达' },
  { value: 'in_progress', label: '车间生产中' },
  { value: 'completed', label: '整机已完工' },
  { value: 'closed', label: '工单已关闭' }
]

export default {
  name: 'WorkOrderDetail',
  data: () => ({
    loading: false,
    saving: false,
    wo: null,
    owners: [],
    unitLimit: 50,
    steps: STEPS,
    today: new Date().toISOString().slice(0, 10),
    adjust: {
      visible: false,
      owner_legacy_id: null,
      planned_start_date: '',
      planned_finish_date: '',
      is_urgent: false,
      planned_qty: 1,
      bom_id: null,
      routing_id: null,
      remark: '',
      bomOptions: [],
      routingOptions: []
    }
  }),
  computed: {
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
        is_urgent: wo.is_urgent,
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
      if (a.is_urgent !== wo.is_urgent) payload.is_urgent = a.is_urgent
      if (!wo.started) {
        if (a.bom_id !== wo.bom_id) payload.bom_id = a.bom_id
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
.work-order-detail-page {
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

.btn-back {
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #606266 !important;
}

.btn-back:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
  background: #f0fdf4 !important;
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

.urgent-tag {
  font-weight: 600;
}

.source-crumb-tag {
  font-size: 12px;
  color: #475569;
  background: #f1f5f9;
  padding: 3px 8px;
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

/* 全局统一提示条 */
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

/* 进度与交期横幅卡片 */
.schedule-stepper-card {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 24px;
  margin-bottom: 16px;
  padding: 16px 20px;
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
  padding: 12px 16px;
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
  font-size: 17px;
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
  margin-bottom: 14px;
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

/* 序列号在制矩阵看板 */
.unit-status-counters {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.counter-pill {
  font-size: 12px;
  color: #64748b;
}

.counter-pill b {
  font-size: 14px;
  margin-left: 2px;
}

.unit-table-container {
  overflow-x: auto;
}

.unit-matrix-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 4px;
  min-width: 600px;
}

.unit-matrix-table th {
  font-size: 12px;
  color: #64748b;
  padding: 6px;
  font-weight: 600;
}

.table-group-header th {
  background: #f8fafc;
  border-radius: 4px;
  text-align: center;
  font-size: 11px;
}

.table-group-header .col-sn {
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

.unit-sn-cell {
  vertical-align: top;
  padding: 6px;
  min-width: 150px;
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
  margin-top: 2px;
}

.matrix-cell-td {
  vertical-align: top;
  padding: 2px;
}

.matrix-cell {
  border-radius: 4px;
  background: #f1f5f9;
  color: #94a3b8;
  padding: 8px 6px;
  font-size: 11px;
  text-align: center;
  min-height: 32px;
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

/* 工序工时看板 */
.op-status-tag {
  margin-left: 6px;
}

/* 齐套网格看板 */
.kitting-grid-wrap {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}

.kitting-op-card {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
  background: #ffffff;
}

.kitting-op-card.kitting-shortage {
  border-color: #fca5a5;
  background: #fff8f8;
}

.kitting-op-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 8px;
  border-bottom: 1px solid #f1f5f9;
  margin-bottom: 8px;
}

.op-title {
  font-size: 13px;
  color: #0f172a;
}

.materials-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.material-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  gap: 8px;
}

.mat-name {
  color: #475569;
}

.mat-auxiliary {
  font-size: 11px;
}

.no-material-row {
  font-size: 12px;
  text-align: center;
  padding: 6px 0;
}

/* 成本与日志并排卡片 */
.two-subcard-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 16px;
}

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

/* 右侧栏样式 */
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
  margin-left: 8px;
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
@media (max-width: 1100px) {
  .content-grid {
    grid-template-columns: 1fr;
  }
  .schedule-stepper-card {
    flex-direction: column;
    align-items: flex-start;
  }
  .due-summary-box {
    width: 100%;
    min-width: 0;
  }
  .kitting-grid-wrap {
    grid-template-columns: repeat(2, minmax(0, 1fr));
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
  .two-subcard-grid {
    grid-template-columns: 1fr;
  }
  .adj-form {
    grid-template-columns: 1fr;
  }
  .kitting-grid-wrap {
    grid-template-columns: 1fr;
  }
}
</style>
