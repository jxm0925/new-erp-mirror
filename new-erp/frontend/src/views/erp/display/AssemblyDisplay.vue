<template>
  <main class="assembly-display">
    <!-- 1. 顶部公共看板导航栏 -->
    <header class="kanban-topbar">
      <div class="brand-box">
        <div class="brand-logo">简谈</div>
        <div>
          <div class="brand-title">
            <h1>车间订单与生产看板</h1>
            <span class="public-badge">免登录公共屏 · 订单下挂工单</span>
          </div>
          <div class="brand-sub">实时同步：当前订单工作 · 下挂主/子工单进度 · 销售备注与定制要求 · 设计图纸</div>
        </div>
      </div>

      <!-- 生产阶段 / 类别切换页签 -->
      <nav class="stage-tabs" aria-label="工单类别">
        <button
          v-for="item in categories"
          :key="item.value"
          type="button"
          class="stage-tab"
          :class="{ active: filters.type === item.value }"
          @click="changeFilter('type', item.value)"
        >
          {{ item.label }}
        </button>
      </nav>

      <div class="topbar-right">
        <div class="stat-pill" title="当前分类今天新建的生产工单数">
          <span>今日工单</span>
          <strong class="code-mono">{{ stats ? stats.today_orders : '—' }} 单</strong>
        </div>
        <div class="stat-pill" title="今日当前分类新建工单所关联订单的整单应收金额（按订单去重）">
          <span>关联销售额</span>
          <strong class="code-mono">{{ stats ? money(stats.sales_amount, 0) : '—' }}</strong>
        </div>
        <button
          v-if="pendingChanges.length"
          class="pending-changes-btn"
          type="button"
          @click="openPendingChange"
        >
          <i class="el-icon-warning" /> 待查看修改 {{ pendingChanges.length }}
        </button>
        <button
          class="voice-btn"
          :class="{ on: soundEnabled }"
          type="button"
          :title="soundError || (soundEnabled ? '声音已启用，点击关闭' : '点击启用声音提醒')"
          @click="toggleSound"
        >
          <i :class="soundEnabled ? 'el-icon-bell' : 'el-icon-close-notification'" />
          {{ soundEnabled ? '语音已开启' : '开启语音' }}
        </button>
        <div class="live-clock">
          <span class="pulse-dot" :class="{ disconnected: connectionState !== 'connected' }" :title="connectionLabel" />
          <span>{{ dateLabel }}</span>
          <b class="code-mono">{{ timeLabel }}</b>
        </div>
      </div>
    </header>

    <!-- 2. 当天交期 / 待接单语音催办提醒条 -->
    <div v-if="urgentOrders.length" class="urgent-alert-strip" role="status">
      <div class="urgent-left">
        <span class="urgent-tag"><i class="el-icon-warning" /> 当天交期 / 待接单催办 ({{ urgentOrders.length }})</span>
        <button
          v-for="order in urgentOrders"
          :key="order.id"
          type="button"
          class="urgent-chip"
          @click="openDetailById(order.id)"
        >
          <b v-if="order.sales_order_no" class="code-mono">{{ order.sales_order_no }}</b>
          <span class="code-mono">{{ order.work_order_no }}</span>
          <span v-if="order.product_name">({{ order.product_name }})</span>
          <span>已等待 <b>{{ waitMinutes(order) }}</b> 分钟</span>
        </button>
      </div>
      <span class="connection-hint">{{ connectionLabel }}</span>
    </div>

    <!-- 3. 状态筛选与综合搜索栏 -->
    <section class="toolbar-wrap" aria-label="订单与工单筛选">
      <div class="toolbar-top-row">
        <!-- 工单状态页签 -->
        <nav class="status-pills" aria-label="工单状态">
          <button
            v-for="item in statuses"
            :key="item.value"
            type="button"
            class="status-pill"
            :class="{ active: filters.status === item.value }"
            @click="changeFilter('status', item.value)"
          >
            {{ item.label }}
          </button>
        </nav>

        <!-- 订单标记筛选 -->
        <div class="order-type-pills">
          <span class="filter-label">订单类型：</span>
          <button
            v-for="item in orderTypes"
            :key="item.value"
            type="button"
            class="type-pill"
            :class="{ active: filters.order_type === item.value }"
            @click="changeFilter('order_type', item.value)"
          >
            {{ item.label }}
          </button>
        </div>
      </div>

      <!-- 搜索表单 -->
      <form class="search-form-row" @submit.prevent="search">
        <el-input
          v-model="filters.order_no"
          size="small"
          clearable
          prefix-icon="el-icon-tickets"
          placeholder="订单号(SO) / 工单号(WO)"
          class="filter-input"
          @clear="search"
        />
        <el-input
          v-model="filters.customer"
          size="small"
          clearable
          prefix-icon="el-icon-user"
          placeholder="客户姓名 / 电话"
          class="filter-input"
          @clear="search"
        />
        <el-input
          v-model="filters.product_name"
          size="small"
          clearable
          prefix-icon="el-icon-box"
          placeholder="产品名称 / 半成品名称"
          class="filter-input"
          @clear="search"
        />
        <div class="date-range-box">
          <el-date-picker
            v-model="filters.create_from"
            size="small"
            type="date"
            value-format="yyyy-MM-dd"
            placeholder="创建开始日期"
            popper-class="assembly-date-picker"
            clearable
            @change="search"
          />
          <span class="date-sep">至</span>
          <el-date-picker
            v-model="filters.create_to"
            size="small"
            type="date"
            value-format="yyyy-MM-dd"
            placeholder="创建结束日期"
            popper-class="assembly-date-picker"
            clearable
            @change="search"
          />
        </div>
        <el-button size="small" type="success" icon="el-icon-search" native-type="submit" :loading="loading" class="btn-search">
          查询
        </el-button>
        <el-button size="small" icon="el-icon-refresh-left" @click="resetFilters">
          重置
        </el-button>
      </form>
    </section>

    <!-- 4. 主工作区：全宽以订单为主卡片，内部树形下挂主工单与半成品子工单 -->
    <section v-loading="loading" class="workspace-single">
      <div v-if="listError" class="display-error" role="alert">
        <i class="el-icon-warning-outline" /> {{ listError }}
        <el-button type="text" @click="loadList">重新加载</el-button>
      </div>

      <div v-else-if="!loading && !rows.length" class="empty-card">
        <i class="el-icon-document-Remove" />
        <p>暂无符合筛选条件的订单与生产工单</p>
        <el-button size="small" @click="resetFilters">清空筛选条件</el-button>
      </div>

      <article
        v-for="row in rows"
        :key="row.order_group_key || row.id"
        class="order-group-card"
        :class="{ 'is-new-order': isOrderNew(row) }"
      >
        <!-- 4.1 订单卡片顶栏 -->
        <div class="order-card-header" @click="openDetail(row, firstWorkOrder(row))">
          <div class="order-head-left">
            <span v-if="isOrderNew(row)" class="new-badge">新下达</span>
            <span class="order-source-badge" :class="{ stock: row.source_type === 'stock' }">
              {{ row.source_label || (row.sales_order_no ? '销售订单' : '库存备货') }}
            </span>
            <strong class="order-no-title code-mono">
              {{ row.display_order_no || row.sales_order_no || row.work_order_no || '—' }}
            </strong>

            <!-- 订单标签（加急 / 定制 / 延迟 / 普通） -->
            <span
              v-for="(tag, idx) in row.tags || []"
              :key="tag.code || idx"
              class="badge"
              :class="badgeClass(tag)"
            >
              {{ tag.label }}
            </span>

            <span v-if="row.salesperson" class="badge badge-sales">
              销售: {{ row.salesperson }}
            </span>
            <span v-if="row.customer_name" class="badge badge-customer">
              客户: {{ row.customer_name }}
            </span>
            <span v-if="row.platform_name" class="badge badge-platform">
              {{ row.platform_name }}
            </span>
          </div>

          <div class="order-head-right">
            <span
              v-if="row.required_delivery_date"
              class="delivery-pill code-mono"
              :class="{ 'urgent-due': row.is_overdue || hasUrgentTag(row) }"
            >
              <i class="el-icon-date" /> 交期: {{ row.required_delivery_date }}
              <template v-if="row.due_hint"> ({{ row.due_hint }})</template>
            </span>
            <span class="wo-summary-pill">
              主工单 {{ row.main_work_order_count != null ? row.main_work_order_count : 1 }} 张
              <template v-if="row.sub_work_order_count"> · 子工单 {{ row.sub_work_order_count }} 张</template>
              · 图纸 {{ (row.order_drawings || []).length }} 份
            </span>
            <button
              type="button"
              class="btn-open-modal"
              @click.stop="openDetail(row, firstWorkOrder(row))"
            >
              <i class="el-icon-view" /> 查看详情与图纸
            </button>
          </div>
        </div>

        <!-- 4.2 订单级醒目销售备注、关键技术配置与内联设计图缩略图 -->
        <div class="order-brief-banner">
          <div class="remarks-area">
            <div class="tech-spec-chips">
              <span v-if="row.electric_label" class="spec-chip voltage">
                ⚡ 电压: {{ row.electric_label }}
              </span>
              <span
                v-if="row.need_pump_label"
                class="spec-chip"
                :class="row.need_pump_label.includes('需要') ? 'pump-yes' : 'pump-no'"
              >
                💧 {{ row.need_pump_label }}
              </span>
              <span v-if="row.logistics_requirement" class="spec-chip logistics">
                🚚 物流/包装: {{ row.logistics_requirement }}
              </span>
              <button
                v-if="row.has_changed"
                type="button"
                class="diff-alert-btn"
                @click.stop="openOrderChangeCompare(row)"
              >
                ⚠️ 销售已修改 ({{ row.change_count }}次<template v-if="row.latest_change_by"> · {{ row.latest_change_by }}</template>) · 点击查看修改对比 &gt;
              </button>
            </div>

            <div class="remark-line">
              <span class="remark-tag">销售整单备注</span>
              <span :class="row.order_remark || row.order_note ? 'remark-text-strong' : 'text-muted'">
                {{ row.order_remark || row.order_note || '无整单特殊备注' }}
              </span>
            </div>

            <div v-if="row.customer_remark" class="remark-line">
              <span class="remark-tag">客户备注</span>
              <span class="remark-text-strong">{{ row.customer_remark }}</span>
            </div>

            <div
              v-for="(lineRem, lIdx) in row.line_remarks || []"
              :key="'lr-' + lIdx"
              class="remark-line"
            >
              <span class="remark-tag custom-tag">订单行与定制</span>
              <span>{{ lineRem }}</span>
            </div>
          </div>

          <!-- 订单及下挂工单设计图纸缩略图直出 -->
          <div class="inline-drawings-strip">
            <template v-if="(row.order_drawings || []).length">
              <div
                v-for="(drawing, dIdx) in (row.order_drawings || []).slice(0, 5)"
                :key="drawing.id || drawing.url || dIdx"
                class="drawing-mini-card"
                :title="'点击查看图纸：' + (drawing.name || '设计图')"
                @click.stop="openDetailWithDrawing(row, drawing)"
              >
                <div class="drawing-mini-thumb">
                  <span class="drawing-scope-tag" :class="{ sub: drawing.scope_type === 'sub' }">
                    {{ drawing.scope_type === 'sub' ? '子工单图' : (drawing.scope_type === 'main' ? '主工单图' : '订单图') }}
                  </span>
                  <img
                    v-if="isImageFile(drawing) && !failedThumbnails[drawing.url]"
                    :src="safeFileUrl(drawing)"
                    :alt="drawing.name"
                    @error="$set(failedThumbnails, drawing.url, true)"
                  >
                  <div v-else class="drawing-file-icon">
                    <i :class="isPdfFile(drawing) ? 'el-icon-document-checked' : 'el-icon-picture-outline'" />
                    <small>{{ fileExtLabel(drawing) }}</small>
                  </div>
                </div>
                <div class="drawing-mini-name">{{ drawing.name || '设计图纸' }}</div>
              </div>
              <button
                v-if="(row.order_drawings || []).length > 5"
                type="button"
                class="more-drawings-btn"
                @click.stop="openDetail(row, firstWorkOrder(row))"
              >
                +{{ row.order_drawings.length - 5 }} 份图纸
              </button>
            </template>
            <div v-else class="no-drawing-pill">
              <i class="el-icon-picture-outline" /> 暂无图纸附件
            </div>
          </div>
        </div>

        <!-- 4.3 订单下挂工单树（主工单 + 树形缩进半成品子工单） -->
        <div class="nested-workorders-section">
          <div class="nested-wo-header">
            <span>📋 下挂生产工单树（点击任意工单行弹出详情与对应图纸）</span>
            <span class="header-sub-meta">下单时间：{{ formatDateTime(row.created_at) }}</span>
          </div>

          <div class="wo-tree-list">
            <div
              v-for="wo in rowWorkOrders(row)"
              :key="wo.id"
              class="wo-node-card"
              :class="{ 'is-child': wo.is_sub }"
              @click="openDetail(row, wo)"
            >
              <div class="wo-top-row">
                <div class="wo-identity">
                  <span class="wo-role-tag" :class="wo.is_sub ? 'sub' : 'main'">
                    {{ wo.role_label || (wo.is_sub ? '子工单 · 半成品' : '主工单 · 总装') }}
                  </span>
                  <span class="wo-no code-mono">{{ wo.work_order_no }}</span>
                  <span class="wo-product-name">{{ wo.product_name }}</span>
                  <span class="wo-qty-badge code-mono">× {{ wo.planned_qty }} {{ wo.unit_name }}</span>
                  <span v-if="wo.specification || wo.item_code" class="wo-spec-pill">
                    规格: {{ wo.specification || '标准' }}<template v-if="wo.item_code"> · {{ wo.item_code }}</template>
                  </span>
                </div>

                <div class="wo-status-group">
                  <span class="wo-state-badge" :class="stageClass(wo.status_label)">
                    {{ wo.status_label }}
                    <template v-if="wo.todo_qty != null">
                      (待做 {{ wo.todo_qty }} / 已完成 {{ wo.completed_qty }})
                    </template>
                  </span>
                  <span v-if="wo.kitting_text" class="kitting-badge" :class="wo.kitting_type || 'ready'">
                    {{ wo.kitting_text }}
                  </span>
                </div>
              </div>

              <div class="wo-ops-row">
                <div class="ops-chain">
                  <template v-if="wo.operations && wo.operations.length">
                    <template v-for="(op, opIdx) in wo.operations">
                      <span v-if="opIdx > 0" :key="'arr-' + opIdx" class="op-arrow">→</span>
                      <span :key="'op-' + (op.id || opIdx)" class="op-step" :class="op.state">
                        <b>{{ op.name }}</b>
                        <span v-if="op.planned !== '-'" class="code-mono">{{ op.completed }}/{{ op.planned }}</span>
                        <span>· {{ op.worker }}</span>
                      </span>
                    </template>
                  </template>
                  <span v-else class="op-step wait">{{ row.stage_name || wo.status_label }}</span>
                </div>

                <div class="wo-meta-right">
                  <span v-if="wo.semi_text" class="semi-summary-text">{{ wo.semi_text }}</span>
                  <span v-if="wo.wo_remark" class="wo-inline-remark" :title="wo.wo_remark">
                    <i class="el-icon-warning-outline" /> {{ wo.wo_remark }}
                  </span>
                  <button
                    v-if="wo.units && wo.units.length"
                    type="button"
                    class="unit-sn-trigger code-mono"
                    @click.stop="toggleSnExpand(wo.id)"
                  >
                    编号({{ wo.units.length }}) {{ expandedSnWoIds[wo.id] ? '▲' : '▼' }}
                  </button>
                  <button
                    type="button"
                    class="btn-wo-detail"
                    @click.stop="openDetail(row, wo)"
                  >
                    详情与图纸 &gt;
                  </button>
                </div>
              </div>

              <!-- 单台/单件序列号 (SN) 展开抽屉 -->
              <div v-if="expandedSnWoIds[wo.id] && wo.units && wo.units.length" class="sn-drawer" @click.stop>
                <span class="sn-drawer-label">单件/单台追踪：</span>
                <span v-for="u in wo.units" :key="u.id || u.serial_no" class="sn-chip code-mono">
                  <b>{{ u.serial_no }}</b>
                  <span>工序: {{ u.current_op }}</span>
                  <span class="sn-state">{{ u.status_label }}</span>
                </span>
              </div>
            </div>
          </div>
        </div>
      </article>

      <!-- 底部分页栏 (默认 10 条/页) -->
      <footer class="display-pagination">
        <div>
          共 {{ total == null ? '—' : total }} 单
          <span class="page-size">10 单/页</span>
        </div>
        <el-pagination
          background
          :current-page="page"
          :page-size="10"
          :total="total || 0"
          :pager-count="5"
          layout="prev, pager, next"
          @current-change="changePage"
        />
      </footer>
    </section>

    <!-- 5. 居中大弹窗：订单/工单详情与设计图纸检视台 (原右侧详情页改为弹窗) -->
    <el-dialog
      :title="detailDialogTitle"
      :visible.sync="detailVisible"
      width="1180px"
      top="5vh"
      custom-class="assembly-dialog assembly-detail-dialog"
      :close-on-click-modal="false"
      @closed="afterDetailClosed"
    >
      <div v-loading="detailLoading" class="dialog-content">
        <div v-if="detailError" class="dialog-error" role="alert">
          {{ detailError }}
          <el-button type="text" @click="loadDetail">重新加载</el-button>
        </div>

        <template v-else-if="detail">
          <!-- 弹窗顶部：同订单下主/子工单快速切换栏 -->
          <div v-if="detailWorkOrders.length > 1" class="modal-wo-switcher">
            <span class="switcher-label">切换本单下挂工单：</span>
            <button
              v-for="wo in detailWorkOrders"
              :key="wo.id"
              type="button"
              class="wo-switch-pill"
              :class="{ active: activeModalWo && activeModalWo.id === wo.id }"
              @click="selectModalWorkOrder(wo)"
            >
              <span class="role-dot" :class="wo.is_sub ? 'sub' : 'main'">{{ wo.is_sub ? '子' : '主' }}</span>
              <span class="code-mono">{{ wo.work_order_no }}</span>
              <b>{{ wo.product_name }}</b>
              <span class="code-mono">×{{ wo.planned_qty }}{{ wo.unit_name }}</span>
            </button>
          </div>

          <div class="modal-grid-2">
            <!-- 弹窗左栏：设计图纸高清预览区 -->
            <div class="modal-drawing-col">
              <div class="modal-section-head">
                <h4><i class="el-icon-picture-outline" /> 设计图纸与技术附件 ({{ modalDrawings.length }})</h4>
                <a
                  v-if="activeDrawing && safeFileUrl(activeDrawing)"
                  class="el-button el-button--mini el-button--success is-plain"
                  :href="safeFileUrl(activeDrawing)"
                  target="_blank"
                  rel="noopener noreferrer"
                >
                  新窗口打开原图
                </a>
              </div>

              <template v-if="modalDrawings.length">
                <div class="drawing-source-tabs">
                  <button
                    v-for="(d, idx) in modalDrawings"
                    :key="d.id || d.url || idx"
                    type="button"
                    class="ds-tab"
                    :class="{ active: activeDrawing && activeDrawing.url === d.url }"
                    @click="pickModalDrawing(d)"
                  >
                    [{{ d.scope_label || (d.scope_type === 'sub' ? '子工单' : '订单/主工单') }}] {{ d.name || `图纸${idx + 1}` }}
                  </button>
                </div>

                <div v-loading="drawingPreviewLoading" class="blueprint-stage">
                  <div v-if="drawingPreviewError" class="preview-error-box">
                    <i class="el-icon-warning-outline" />
                    <p>{{ drawingPreviewError }}</p>
                    <a
                      v-if="activeDrawing && safeFileUrl(activeDrawing)"
                      class="el-button el-button--small"
                      :href="safeFileUrl(activeDrawing)"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      直接打开原文件
                    </a>
                  </div>
                  <img
                    v-else-if="activeDrawing && isImageFile(activeDrawing)"
                    :src="safeFileUrl(activeDrawing)"
                    :alt="activeDrawing.name"
                    class="stage-image"
                    @load="drawingPreviewLoading = false"
                    @error="onDrawingPreviewError"
                  >
                  <iframe
                    v-else-if="activeDrawing && isPdfFile(activeDrawing) && activePdfBlobUrl"
                    :src="activePdfBlobUrl"
                    title="PDF 图纸预览"
                    class="stage-iframe"
                    @load="drawingPreviewLoading = false"
                  />
                  <div v-else-if="activeDrawing" class="preview-error-box">
                    <i class="el-icon-document" />
                    <p>{{ activeDrawing.name }}</p>
                    <a
                      v-if="safeFileUrl(activeDrawing)"
                      class="el-button el-button--small el-button--success"
                      :href="safeFileUrl(activeDrawing)"
                      target="_blank"
                      rel="noopener noreferrer"
                    >
                      下载 / 打开查看
                    </a>
                  </div>

                  <div v-if="activeDrawing" class="blueprint-caption">
                    <span>📄 {{ activeDrawing.name }} ({{ activeDrawing.scope_label || '设计图纸' }})</span>
                    <span v-if="activeDrawing.updated_at" class="code-mono">{{ activeDrawing.updated_at }}</span>
                  </div>
                </div>
              </template>

              <div v-else class="empty-drawing-stage">
                <i class="el-icon-picture-outline" />
                <p>当前订单与工单暂未上传设计图纸</p>
              </div>

              <!-- 兼容展示整单商品与附件表 -->
              <div v-if="(detail.products || []).length" class="modal-products-box">
                <div class="modal-section-head" style="margin-top:12px;">
                  <h4><i class="el-icon-s-goods" /> 订单设备清单</h4>
                </div>
                <table class="detail-products">
                  <thead>
                    <tr>
                      <th>名称</th>
                      <th>数量</th>
                      <th>备注与配置</th>
                      <th>行附件</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="(product, index) in detail.products || []" :key="product.id || index">
                      <td>{{ text(product.name) }}</td>
                      <td>{{ text(product.quantity) }}{{ product.unit }}</td>
                      <td>{{ text(product.remark) }}</td>
                      <td><AssemblyAttachments :files="product.attachments || []" /></td>
                    </tr>
                  </tbody>
                </table>
              </div>
            </div>

            <!-- 弹窗右栏：销售订单备注 + 当前选中工单工序与用料明细 -->
            <div class="modal-info-col">
              <!-- 销售订单核心备注卡片 -->
              <div class="info-block highlight-sales">
                <div class="info-block-title">
                  <span>📌 销售订单核心信息与备注 ({{ detail.display_order_no || detail.sales_order_no || detail.work_order_no }})</span>
                  <div class="title-actions">
                    <button
                      v-if="detail.latest_change_id"
                      type="button"
                      class="diff-alert-btn"
                      @click="openSpecificChange(detail.latest_change_id)"
                    >
                      最新修改对比
                    </button>
                    <button type="button" class="btn-history-link" @click="openHistory">
                      修改记录
                    </button>
                  </div>
                </div>
                <div class="kv-list">
                  <div class="kv-row">
                    <span class="kv-lbl">客户 / 销售</span>
                    <span class="kv-val">
                      {{ text(detail.customer_name) }}
                      <template v-if="detail.customer_phone"> ({{ detail.customer_phone }})</template>
                      ｜ 销售：{{ text(detail.salesperson) }}
                    </span>
                  </div>
                  <div class="kv-row">
                    <span class="kv-lbl">要求交期</span>
                    <span class="kv-val code-mono">
                      {{ text(detail.required_delivery_date) }}
                      <template v-if="detail.due_hint"> ({{ detail.due_hint }})</template>
                    </span>
                  </div>
                  <div class="kv-row">
                    <span class="kv-lbl">电压 / 水泵</span>
                    <span class="kv-val text-danger-strong">
                      {{ text(detail.electric_label) }} ｜ {{ text(detail.need_pump_label) }}
                    </span>
                  </div>
                  <div class="kv-row">
                    <span class="kv-lbl">整单备注</span>
                    <span class="kv-val remark-highlight">{{ text(detail.order_remark || detail.order_note) }}</span>
                  </div>
                  <div v-if="(detail.line_remarks || []).length" class="kv-row">
                    <span class="kv-lbl">行与定制要求</span>
                    <div class="kv-val">
                      <div v-for="(lr, i) in detail.line_remarks" :key="i">{{ lr }}</div>
                    </div>
                  </div>
                  <div class="kv-row">
                    <span class="kv-lbl">物流与地址</span>
                    <span class="kv-val">
                      {{ text(detail.logistics_requirement || (detail.production_remarks && detail.production_remarks.logistics)) }}
                      <template v-if="detail.address"> · {{ detail.address }}</template>
                    </span>
                  </div>
                </div>
              </div>

              <!-- 当前选中工单详情卡片 -->
              <div v-if="activeModalWo" class="info-block">
                <div class="info-block-title">
                  <span>🔧 当前工单执行详情 ({{ activeModalWo.work_order_no }})</span>
                  <span class="wo-role-tag" :class="activeModalWo.is_sub ? 'sub' : 'main'">
                    {{ activeModalWo.role_label }}
                  </span>
                </div>
                <div class="kv-list">
                  <div class="kv-row">
                    <span class="kv-lbl">产品与数量</span>
                    <span class="kv-val">
                      <b>{{ activeModalWo.product_name }}</b> × {{ activeModalWo.planned_qty }} {{ activeModalWo.unit_name }}
                      (已完成 {{ activeModalWo.completed_qty }} / 待做 {{ activeModalWo.todo_qty }})
                    </span>
                  </div>
                  <div class="kv-row">
                    <span class="kv-lbl">规格型号</span>
                    <span class="kv-val">
                      {{ text(activeModalWo.specification) }}
                      <template v-if="activeModalWo.item_code"> · {{ activeModalWo.item_code }}</template>
                    </span>
                  </div>
                  <div class="kv-row">
                    <span class="kv-lbl">齐套 / 交接</span>
                    <span class="kv-val">
                      <span class="kitting-badge" :class="activeModalWo.kitting_type || 'ready'">
                        {{ activeModalWo.kitting_text }}
                      </span>
                      <span v-if="activeModalWo.semi_text" style="margin-left:6px;">{{ activeModalWo.semi_text }}</span>
                    </span>
                  </div>
                  <div class="kv-row">
                    <span class="kv-lbl">工单工艺备注</span>
                    <span class="kv-val">{{ text(activeModalWo.wo_remark) }}</span>
                  </div>
                </div>

                <!-- 工序进度条 -->
                <div v-if="activeModalWo.operations && activeModalWo.operations.length" class="modal-ops-section">
                  <div class="sub-label">工序实时进度：</div>
                  <div class="ops-chain">
                    <span
                      v-for="(op, opIdx) in activeModalWo.operations"
                      :key="op.id || opIdx"
                      class="op-step"
                      :class="op.state"
                    >
                      <b>{{ op.name }}</b>
                      <span v-if="op.planned !== '-'" class="code-mono">{{ op.completed }}/{{ op.planned }}</span>
                      <span>· {{ op.worker }}</span>
                    </span>
                  </div>
                </div>

                <!-- 工单用料齐套表 -->
                <div v-if="activeModalWo.materials && activeModalWo.materials.length" class="modal-materials-section">
                  <div class="sub-label">本工单用料明细 ({{ activeModalWo.materials.length }} 项)：</div>
                  <div class="mini-table-scroll">
                    <table class="mini-mat-table">
                      <thead>
                        <tr>
                          <th>物料名称</th>
                          <th>规格 / 下料尺寸</th>
                          <th>已领 / 需求</th>
                          <th>状态</th>
                        </tr>
                      </thead>
                      <tbody>
                        <tr v-for="m in activeModalWo.materials" :key="m.id">
                          <td>{{ m.component_item_name }}</td>
                          <td>{{ m.specification || '—' }}</td>
                          <td class="code-mono">{{ m.issued_qty }} / {{ m.required_qty }} {{ m.unit_name }}</td>
                          <td>
                            <span :class="m.is_short ? 'mat-short' : 'mat-ok'">
                              {{ m.is_short ? '待领/缺料' : '已齐' }}
                            </span>
                          </td>
                        </tr>
                      </tbody>
                    </table>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>
      <span slot="footer" class="detail-footer">
        <el-button plain type="success" :disabled="!detail || !!detailError" @click="openHistory">
          查看历史修改记录
        </el-button>
        <el-button type="success" @click="detailVisible = false">关闭</el-button>
      </span>
    </el-dialog>

    <!-- 6. 历史修改记录弹窗 -->
    <el-dialog
      title="订单修改记录"
      :visible.sync="historyVisible"
      width="1100px"
      top="8vh"
      custom-class="assembly-dialog assembly-history-dialog"
      :close-on-click-modal="false"
      @closed="afterHistoryClosed"
    >
      <div v-loading="historyLoading" class="dialog-content">
        <p class="history-order">
          订单 / 工单 <span>{{ detail && (detail.display_order_no || detail.sales_order_no || detail.work_order_no) }}</span>
        </p>
        <div v-if="historyError" class="dialog-error" role="alert">
          {{ historyError }}
          <el-button type="text" @click="loadHistory">重新加载</el-button>
        </div>
        <template v-else-if="versions.length">
          <div class="version-tabs">
            <button
              v-for="(version, index) in versions"
              :key="version.id || index"
              type="button"
              :class="{ active: versionIndex === index }"
              @click="versionIndex = index"
            >
              {{ version.label || (version.is_original ? '原数据' : `第${index}次修改`) }}
            </button>
          </div>
          <div class="version-meta">
            <span>{{ currentVersion.is_original ? '创建人' : '修改人' }}</span>
            {{ text(currentVersion.actor_name) }}
            <span>{{ currentVersion.is_original ? '创建日期' : '修改日期' }}</span>
            {{ formatDateTime(currentVersion.occurred_at) }}
          </div>
          <AssemblySnapshot :snapshot="currentVersion.snapshot || {}" />
        </template>
        <div v-else-if="!historyLoading" class="display-empty">暂无修改记录</div>
      </div>
      <span slot="footer"><el-button type="success" @click="historyVisible = false">关闭</el-button></span>
    </el-dialog>

    <!-- 7. 订单修改前后对比提醒弹窗 -->
    <el-dialog
      :visible.sync="changeVisible"
      width="1020px"
      top="8vh"
      custom-class="assembly-dialog assembly-change-dialog"
      :close-on-click-modal="false"
      @closed="afterChangeClosed"
    >
      <template slot="title">
        <span class="change-title"><i class="el-icon-warning" /> 销售订单修改前后对比</span>
      </template>
      <div v-loading="changeLoading" class="dialog-content">
        <p class="change-order">
          {{ change ? `订单 ${change.sales_order_no || change.work_order_no || ''} 修改内容对比` : '订单修改提醒' }}
        </p>
        <div v-if="changeError" class="dialog-error" role="alert">
          {{ changeError }}
          <el-button type="text" @click="loadChange">重新加载</el-button>
        </div>
        <template v-else-if="change">
          <div class="version-tabs">
            <button type="button" :class="{ active: changeSide === 'before' }" @click="changeSide = 'before'">修改前</button>
            <button type="button" :class="{ active: changeSide === 'after' }" @click="changeSide = 'after'">修改后</button>
          </div>
          <div v-if="changeSummaries.length" class="change-summary">
            <div v-for="(item, index) in changeSummaries" :key="index">
              {{ item.label }}：<span>{{ item.before }}</span>
              <i class="el-icon-right" />
              <strong>{{ item.after }}</strong>
            </div>
          </div>
          <AssemblySnapshot :snapshot="change[changeSide] || {}" :previous="changeSide === 'after' ? change.before : null" show-headings />
        </template>
      </div>
      <span slot="footer"><el-button @click="changeVisible = false">关闭</el-button></span>
    </el-dialog>
  </main>
</template>

<script>
import {
  getAssemblyChange,
  getAssemblyHistory,
  getAssemblyWorkOrder,
  listAssemblyWorkOrders,
  readAssemblyPdf,
  safeAssemblyFileUrl
} from '@/api/erp/assemblyDisplay'
import { connectAssemblyDisplay } from '@/services/assemblyDisplayRealtime'
import AssemblyAttachments from './AssemblyAttachments.vue'
import AssemblySnapshot from './AssemblySnapshot.vue'

const localDate = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`

const defaultFilters = () => ({
  type: 'all',
  status: 'all',
  create_from: '',
  create_to: '',
  customer: '',
  product_name: '',
  order_no: '',
  order_type: 'all'
})

export default {
  name: 'AssemblyDisplay',
  components: { AssemblyAttachments, AssemblySnapshot },
  data() {
    return {
      filters: defaultFilters(),
      categories: [
        { value: 'all', label: '全部订单' },
        { value: 'produce', label: '生产计划(下料·焊接)' },
        { value: 'assemble', label: '总装工单' },
        { value: 'testing', label: '发货工单(试水·打包)' },
        { value: 'stock', label: '常规备货单' }
      ],
      orderTypes: [
        { value: 'all', label: '全部' },
        { value: 'urgent', label: '加急订单' },
        { value: 'customized', label: '定制订单' },
        { value: 'delayed', label: '延迟订单' },
        { value: 'ordinary', label: '普通订单' }
      ],
      statuses: [
        { value: 'all', label: '全部在制/待接' },
        { value: 'wait', label: '待接单' },
        { value: 'accept', label: '进行中' },
        { value: 'complete', label: '已完成' },
        { value: 'cancel', label: '已取消' }
      ],
      rows: [],
      stats: null,
      total: 0,
      page: 1,
      loading: false,
      listError: '',
      listRequest: 0,
      urgentOrders: [],
      urgentLoadedAt: 0,
      urgentSignature: '',
      now: Date.now(),
      connectionState: 'connecting',
      soundEnabled: false,
      soundError: '',
      pendingChanges: [],
      activeChangeEvent: null,
      newWorkOrderIds: [],
      expandedSnWoIds: {},
      failedThumbnails: {},

      // 详情与图纸居中弹窗状态
      detailVisible: false,
      detailLoading: false,
      detailError: '',
      detail: null,
      detailId: null,
      activeModalWo: null,
      activeDrawing: null,
      activePdfBlobUrl: '',
      drawingPreviewLoading: false,
      drawingPreviewError: '',
      drawingLoadSeq: 0,

      // 历史版本与变更对比弹窗状态
      historyVisible: false,
      historyLoading: false,
      historyError: '',
      versions: [],
      versionIndex: 0,
      changeVisible: false,
      changeLoading: false,
      changeError: '',
      change: null,
      changeSide: 'after'
    }
  },
  computed: {
    dateLabel() {
      const date = new Date(this.now)
      return `${localDate(date)} 星期${'日一二三四五六'[date.getDay()]}`
    },
    timeLabel() {
      const date = new Date(this.now)
      return `${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}:${String(date.getSeconds()).padStart(2, '0')}`
    },
    connectionLabel() {
      return ({
        connected: '实时同步已连接',
        connecting: '实时通道连接中',
        unavailable: '连接中断，正在重连',
        disconnected: '实时通道已断开',
        failed: '实时连接失败，自动重连中'
      })[this.connectionState] || '实时通道连接中'
    },
    currentVersion() {
      return this.versions[this.versionIndex] || {}
    },
    detailWorkOrders() {
      if (!this.detail) return []
      return this.rowWorkOrders(this.detail)
    },
    modalDrawings() {
      if (!this.detail) return []
      if (Array.isArray(this.detail.order_drawings) && this.detail.order_drawings.length) {
        return this.detail.order_drawings
      }
      const list = []
      ;(this.detail.products || []).forEach(p => {
        ;(p.attachments || []).forEach(att => list.push(att))
      })
      return list
    },
    detailDialogTitle() {
      if (!this.detail) return '订单与工单详情 · 设计图纸'
      const orderNo = this.detail.display_order_no || this.detail.sales_order_no || this.detail.work_order_no || ''
      const wo = this.activeModalWo
      return wo
        ? `订单 ${orderNo} · 工单 ${wo.work_order_no} (${wo.product_name || ''}) 详情与设计图`
        : `订单 ${orderNo} 详情与设计图`
    },
    changeSummaries() {
      if (!this.change) return []
      const before = this.change.before || {}
      const after = this.change.after || {}
      const summary = []
      const add = (label, oldValue, value) => {
        if (JSON.stringify(oldValue == null ? '' : oldValue) !== JSON.stringify(value == null ? '' : value)) {
          summary.push({ label, before: this.text(oldValue), after: this.text(value) })
        }
      }
      ;[
        ['customer_name', '客户名'],
        ['customer_phone', '手机号'],
        ['address', '客户地址'],
        ['order_note', '工单备注']
      ].forEach(([field, label]) => add(label, before[field], after[field]))
      const previousProducts = before.products || []
      ;(after.products || []).forEach((product, index) => {
        const previous = (product.id != null ? previousProducts.find(item => String(item.id) === String(product.id)) : previousProducts[index]) || {}
        if (!Object.keys(previous).length) {
          summary.push({
            label: '新增设备',
            before: '—',
            after: `${product.name || '设备'} × ${product.quantity == null ? '—' : product.quantity}${product.unit || ''}`
          })
          return
        }
        ;[
          ['name', '设备名称'],
          ['quantity', '设备数量'],
          ['unit', '设备单位'],
          ['remark', '设备备注']
        ].forEach(([field, label]) => add(label, previous[field], product[field]))
        const fileNames = files => (files || []).map(file => file.name || '附件').join('、') || '—'
        if (JSON.stringify(previous.attachments || []) !== JSON.stringify(product.attachments || [])) {
          summary.push({ label: '设备图纸', before: fileNames(previous.attachments), after: fileNames(product.attachments) })
        }
      })
      previousProducts.forEach((product, index) => {
        const found = product.id != null ? (after.products || []).some(item => String(item.id) === String(product.id)) : (after.products || [])[index]
        if (!found) {
          summary.push({
            label: '移除设备',
            before: `${product.name || '设备'} × ${product.quantity == null ? '—' : product.quantity}${product.unit || ''}`,
            after: '—'
          })
        }
      })
      return summary
    }
  },
  created() {
    this.seenEvents = new Set()
    this.speechQueue = []
    this.nextUrgentAt = 0
    this.loadList()
    this.clockTimer = window.setInterval(() => {
      this.now = Date.now()
    }, 1000)
    try {
      this.stopRealtime = connectAssemblyDisplay(this.onRealtimeEvent, this.onConnectionState)
    } catch (error) {
      this.connectionState = 'failed'
    }
  },
  beforeDestroy() {
    this.destroyed = true
    this.listRequest += 1
    this.clearPdfBlob()
    if (this.stopRealtime) this.stopRealtime()
    window.clearInterval(this.clockTimer)
    window.clearTimeout(this.urgentTimer)
    window.clearTimeout(this.refreshTimer)
    window.clearTimeout(this.speechTimer)
    if (this.speaking && window.speechSynthesis) window.speechSynthesis.cancel()
  },
  methods: {
    text(value) {
      return value === null || value === undefined || value === '' ? '—' : value
    },
    money(value, decimals = 2) {
      const number = Number(value)
      return Number.isFinite(number)
        ? `¥${number.toLocaleString('zh-CN', { minimumFractionDigits: decimals, maximumFractionDigits: decimals })}`
        : '—'
    },
    formatDateTime(value) {
      if (!value) return '—'
      const date = new Date(value)
      if (Number.isNaN(date.getTime())) return String(value)
      return `${localDate(date)} ${[date.getHours(), date.getMinutes(), date.getSeconds()].map(number => String(number).padStart(2, '0')).join(':')}`
    },
    rowWorkOrders(row) {
      if (Array.isArray(row.work_orders) && row.work_orders.length) {
        return row.work_orders
      }
      return [
        {
          id: row.id,
          work_order_no: row.work_order_no,
          is_sub: false,
          role_label: '主工单',
          product_name: row.device_name || '生产设备',
          planned_qty: row.quantity != null ? row.quantity : 1,
          completed_qty: 0,
          todo_qty: row.quantity != null ? row.quantity : 1,
          unit_name: row.unit || '台',
          status_label: row.status_label || row.stage_name || '待处理',
          kitting_text: '',
          kitting_type: 'ready',
          operations: [],
          units: [],
          materials: [],
          drawings: []
        }
      ]
    },
    firstWorkOrder(row) {
      const list = this.rowWorkOrders(row)
      return list[0] || row
    },
    isOrderNew(row) {
      const wos = this.rowWorkOrders(row)
      return wos.some(w => this.newWorkOrderIds.includes(String(w.id))) || this.newWorkOrderIds.includes(String(row.id))
    },
    hasUrgentTag(row) {
      return (row.tags || []).some(t => /urgent|加急/.test(String(t.code || '') + String(t.label || '')))
    },
    badgeClass(tag) {
      const code = String(tag.code || '') + String(tag.label || '')
      if (/urgent|加急/.test(code)) return 'badge-urgent'
      if (/custom|定制|特殊/.test(code)) return 'badge-custom'
      if (/delay|延期|延迟/.test(code)) return 'badge-delay'
      return 'badge-ordinary'
    },
    stageClass(label) {
      if (/已完成|完工|已发货/.test(label || '')) return 'state-done'
      if (/取消|关闭/.test(label || '')) return 'state-muted'
      if (/生产中|进行中/.test(label || '')) return 'state-running'
      return 'state-wait'
    },
    safeFileUrl(file) {
      return file ? safeAssemblyFileUrl(file.url) : ''
    },
    isImageFile(file) {
      if (!file) return false
      const mime = String(file.mime_type || '').toLowerCase()
      return /^image\/(png|jpeg|gif|webp|bmp)$/.test(mime) || /\.(png|jpe?g|gif|webp|bmp)$/i.test(file.name || file.url || '')
    },
    isPdfFile(file) {
      if (!file) return false
      const mime = String(file.mime_type || '').toLowerCase()
      return mime === 'application/pdf' || /\.pdf$/i.test(file.name || file.url || '')
    },
    fileExtLabel(file) {
      if (!file) return 'FILE'
      if (this.isPdfFile(file)) return 'PDF'
      const m = String(file.name || file.url || '').match(/\.([a-z0-9]{2,5})(?:$|\?)/i)
      return m ? m[1].toUpperCase() : '图纸'
    },
    toggleSnExpand(woId) {
      this.$set(this.expandedSnWoIds, woId, !this.expandedSnWoIds[woId])
    },
    changeFilter(field, value) {
      if (this.filters[field] === value) return
      this.filters[field] = value
      this.search()
    },
    resetFilters() {
      this.filters = defaultFilters()
      this.page = 1
      this.loadList()
    },
    search() {
      if (this.filters.create_from && this.filters.create_to && this.filters.create_from > this.filters.create_to) {
        this.$message.warning('开始日期不能晚于结束日期')
        return
      }
      this.page = 1
      this.loadList()
    },
    changePage(page) {
      this.page = page
      this.loadList()
    },
    async loadList(refreshUrgentTimer = true) {
      const request = ++this.listRequest
      this.loading = true
      this.listError = ''
      try {
        const params = { ...this.filters, page: this.page, per_page: 10 }
        Object.keys(params).forEach(k => {
          if (params[k] === '' || params[k] === null) delete params[k]
        })
        const response = await listAssemblyWorkOrders(params)
        if (request !== this.listRequest || this.destroyed) return false
        const data = response.data.data
        const list = data.list
        const lastPage = Math.max(1, Number(list.last_page) || 1)
        if (this.page > lastPage) {
          this.page = lastPage
          return this.loadList(refreshUrgentTimer)
        }
        this.rows = list.data || []
        this.total = Number(list.total) || 0
        this.stats = data.stats || null
        this.urgentOrders = data.urgent_orders || []
        this.urgentLoadedAt = Date.now()
        const signature = this.urgentOrders.map(order => `${order.id}:${order.work_order_no}`).join('|')
        const changed = signature !== this.urgentSignature
        this.urgentSignature = signature
        if (refreshUrgentTimer) this.scheduleUrgent(changed)
        return true
      } catch (error) {
        if (request !== this.listRequest || this.destroyed) return false
        this.listError = error.userMessage || '看板数据加载失败，请稍后重试'
        this.rows = []
        this.stats = null
        this.total = null
        this.urgentOrders = []
        this.urgentSignature = ''
        if (refreshUrgentTimer) this.scheduleUrgent()
        return false
      } finally {
        if (request === this.listRequest) this.loading = false
      }
    },
    openDetailById(woId) {
      this.openDetail({ id: woId }, { id: woId })
    },
    async openDetail(orderRow, targetWo = null, preferredDrawing = null) {
      const id = (targetWo && targetWo.id) || orderRow.id
      this.detailId = id
      this.detail = null
      this.activeModalWo = null
      this.activeDrawing = null
      this.detailVisible = true
      const clearIds = this.rowWorkOrders(orderRow).map(w => String(w.id))
      this.newWorkOrderIds = this.newWorkOrderIds.filter(item => !clearIds.includes(item) && item !== String(id))
      await this.loadDetail(preferredDrawing)
    },
    openDetailWithDrawing(orderRow, drawing) {
      const targetWo = this.rowWorkOrders(orderRow).find(w => Number(w.id) === Number(drawing.work_order_id)) || this.firstWorkOrder(orderRow)
      this.openDetail(orderRow, targetWo, drawing)
    },
    async loadDetail(preferredDrawing = null) {
      const id = this.detailId
      this.detailLoading = true
      this.detailError = ''
      try {
        const response = await getAssemblyWorkOrder(id)
        if (this.detailId !== id || this.destroyed) return
        this.detail = response.data.data
        const wos = this.detailWorkOrders
        this.activeModalWo = wos.find(w => Number(w.id) === Number(id)) || wos[0] || null
        const drawings = this.modalDrawings
        const initialDrawing =
          (preferredDrawing && drawings.find(d => d.url === preferredDrawing.url)) ||
          (this.activeModalWo && drawings.find(d => Number(d.work_order_id) === Number(this.activeModalWo.id))) ||
          drawings[0] ||
          null
        if (initialDrawing) {
          this.pickModalDrawing(initialDrawing)
        } else {
          this.clearPdfBlob()
          this.activeDrawing = null
        }
      } catch (error) {
        if (this.detailId === id) {
          this.detailError = error.userMessage || '详情加载失败'
        }
      } finally {
        if (this.detailId === id) this.detailLoading = false
      }
    },
    selectModalWorkOrder(wo) {
      this.activeModalWo = wo
      const drawings = this.modalDrawings
      const matched = drawings.find(d => Number(d.work_order_id) === Number(wo.id))
      if (matched) {
        this.pickModalDrawing(matched)
      }
    },
    async pickModalDrawing(drawing) {
      const seq = ++this.drawingLoadSeq
      this.clearPdfBlob()
      this.activeDrawing = drawing
      this.drawingPreviewError = ''
      if (!drawing) {
        this.drawingPreviewLoading = false
        return
      }
      const url = this.safeFileUrl(drawing)
      if (!url) {
        this.drawingPreviewLoading = false
        this.drawingPreviewError = '图纸地址不可用'
        return
      }
      if (this.isImageFile(drawing)) {
        this.drawingPreviewLoading = true
        return
      }
      if (this.isPdfFile(drawing)) {
        this.drawingPreviewLoading = true
        try {
          const blobUrl = await readAssemblyPdf(url)
          if (seq !== this.drawingLoadSeq || this.destroyed) {
            URL.revokeObjectURL(blobUrl)
            return
          }
          this.activePdfBlobUrl = blobUrl
        } catch (e) {
          if (seq === this.drawingLoadSeq) {
            this.drawingPreviewError = 'PDF 预览加载失败，请点击右上角打开原图'
          }
        } finally {
          if (seq === this.drawingLoadSeq) {
            this.drawingPreviewLoading = false
          }
        }
        return
      }
      this.drawingPreviewLoading = false
    },
    onDrawingPreviewError() {
      this.drawingPreviewLoading = false
      this.drawingPreviewError = '图纸加载失败，请点击右上角新窗口打开原图'
    },
    clearPdfBlob() {
      if (this.activePdfBlobUrl) {
        URL.revokeObjectURL(this.activePdfBlobUrl)
        this.activePdfBlobUrl = ''
      }
    },
    openOrderChangeCompare(row) {
      if (row.latest_change_id) {
        this.openSpecificChange(row.latest_change_id)
      } else {
        this.detailId = row.id
        this.detail = row
        this.openHistory()
      }
    },
    openSpecificChange(changeId) {
      this.activeChangeEvent = { change_id: changeId }
      this.change = null
      this.changeSide = 'after'
      this.changeVisible = true
      this.loadChange()
    },
    openHistory() {
      this.historyVisible = true
      this.versions = []
      this.versionIndex = 0
      this.loadHistory()
    },
    async loadHistory() {
      const id = this.detailId || (this.detail && this.detail.id)
      if (!id) return
      this.historyLoading = true
      this.historyError = ''
      try {
        const response = await getAssemblyHistory(id)
        if (!this.destroyed) this.versions = response.data.data.versions || []
      } catch (error) {
        this.historyError = error.userMessage || '修改记录加载失败'
      } finally {
        this.historyLoading = false
      }
    },
    afterDetailClosed() {
      this.detailId = null
      this.detail = null
      this.activeModalWo = null
      this.activeDrawing = null
      this.clearPdfBlob()
      this.openPendingChange()
    },
    afterHistoryClosed() {
      this.openPendingChange()
    },
    onConnectionState(state) {
      const reconnect = state === 'connected' && this.connectionState !== 'connected'
      this.connectionState = state
      if (reconnect) this.scheduleRefresh()
    },
    scheduleRefresh() {
      window.clearTimeout(this.refreshTimer)
      this.refreshTimer = window.setTimeout(() => {
        if (!this.destroyed) this.loadList()
      }, 250)
    },
    onRealtimeEvent(event) {
      if (!event || !event.event_id || this.seenEvents.has(String(event.event_id))) return
      this.seenEvents.add(String(event.event_id))
      if (this.seenEvents.size > 300) this.seenEvents.delete(this.seenEvents.values().next().value)
      this.scheduleRefresh()
      if (event.type === 'work_order.created') {
        this.newWorkOrderIds = [...new Set([...this.newWorkOrderIds, ...(event.work_order_ids || []).map(String)])].slice(-100)
        this.$notify({ title: '新工单已生成', message: '', type: 'success', duration: 7500 })
        this.speak('新工单已生成')
      } else if (event.type === 'order.changed' && event.change_id) {
        if (this.activeChangeEvent && String(this.activeChangeEvent.change_id) === String(event.change_id)) return
        if (this.pendingChanges.some(item => String(item.change_id) === String(event.change_id))) return
        this.pendingChanges.push(event)
        this.$notify({ title: '有订单被修改', message: '点击查看修改内容', type: 'warning', duration: 0, onClick: this.openPendingChange })
        this.speak('有工单被修改，请查看修改内容')
        this.openPendingChange()
      }
    },
    openPendingChange() {
      if (this.detailVisible || this.historyVisible || this.changeVisible || !this.pendingChanges.length) return
      this.activeChangeEvent = this.pendingChanges.shift()
      this.change = null
      this.changeSide = 'after'
      this.changeVisible = true
      this.loadChange()
    },
    async loadChange() {
      const event = this.activeChangeEvent
      if (!event) return
      this.changeLoading = true
      this.changeError = ''
      try {
        const response = await getAssemblyChange(event.change_id)
        if (this.activeChangeEvent === event && !this.destroyed) this.change = response.data.data
      } catch (error) {
        if (this.activeChangeEvent === event) this.changeError = error.userMessage || '修改内容加载失败，请重试'
      } finally {
        if (this.activeChangeEvent === event) this.changeLoading = false
      }
    },
    afterChangeClosed() {
      this.activeChangeEvent = null
      this.change = null
      this.openPendingChange()
    },
    toggleSound() {
      if (this.soundEnabled) {
        this.soundEnabled = false
        this.speechQueue = []
        this.scheduleUrgent()
        if (window.speechSynthesis) window.speechSynthesis.cancel()
        return
      }
      if (!window.speechSynthesis || !window.SpeechSynthesisUtterance) {
        this.soundError = '当前浏览器不支持声音播报'
        this.$message.warning(this.soundError)
        return
      }
      this.soundError = ''
      this.speak('声音提醒已开启', true)
    },
    speak(message, enable = false) {
      if ((!this.soundEnabled && !enable) || !window.speechSynthesis || this.destroyed) return
      if (this.speaking) {
        if (!enable && !this.speechQueue.includes(message)) this.speechQueue.push(message)
        return
      }
      const utterance = new window.SpeechSynthesisUtterance(message)
      utterance.lang = 'zh-CN'
      this.speaking = true
      let done = false
      const finish = (error, canceled = false) => {
        if (done) return
        done = true
        window.clearTimeout(this.speechTimer)
        this.speaking = false
        if (canceled) return
        if (error) {
          this.soundEnabled = false
          this.speechQueue = []
          this.soundError = '声音播放失败，请点击重新启用'
          if (!this.destroyed) this.$message.warning(this.soundError)
        } else if (enable) {
          this.soundEnabled = true
          this.soundError = ''
          this.scheduleUrgent(true)
        }
        if (this.soundEnabled && this.speechQueue.length) this.speak(this.speechQueue.shift())
      }
      utterance.onend = () => finish(false)
      utterance.onerror = event => finish(true, ['canceled', 'interrupted'].includes(event.error) && (!this.soundEnabled || this.destroyed))
      this.speechTimer = window.setTimeout(() => finish(true), 20000)
      try {
        window.speechSynthesis.speak(utterance)
      } catch (error) {
        finish(true)
      }
    },
    waitSeconds(order) {
      return Math.max(0, Number(order.wait_seconds) || 0) + Math.max(0, Math.floor((this.now - this.urgentLoadedAt) / 1000))
    },
    waitMinutes(order) {
      return Math.floor(this.waitSeconds(order) / 60)
    },
    urgentDelay() {
      const longest = Math.max(0, ...this.urgentOrders.map(this.waitSeconds))
      return (longest >= 3600 ? 30 : longest >= 1800 ? 60 : longest >= 900 ? 120 : longest >= 300 ? 180 : 300) * 1000
    },
    scheduleUrgent(immediate = false) {
      window.clearTimeout(this.urgentTimer)
      if (this.destroyed) return
      const audible = this.soundEnabled && this.urgentOrders.length
      if (!audible) this.nextUrgentAt = 0
      else if (immediate || !this.nextUrgentAt) this.nextUrgentAt = Date.now() + 1000
      const delay = audible ? Math.min(60000, Math.max(1000, this.nextUrgentAt - Date.now())) : 60000
      this.urgentTimer = window.setTimeout(async () => {
        const fresh = await this.loadList(false)
        if (this.destroyed) return
        if (fresh && this.soundEnabled && this.urgentOrders.length && Date.now() >= this.nextUrgentAt) {
          const numbers = this.urgentOrders.slice(0, 3).map(order => order.work_order_no).join('，')
          this.speak(`当天发货有${this.urgentOrders.length}张工单待接单。${numbers}${this.urgentOrders.length > 3 ? '等工单' : ''}，请及时处理`)
          this.nextUrgentAt = Date.now() + this.urgentDelay()
        }
        this.scheduleUrgent()
      }, delay)
    }
  }
}
</script>

<style scoped>
.assembly-display {
  display: flex;
  flex-direction: column;
  width: 100%;
  min-height: 100vh;
  background: #f1f5f9;
  color: #0f172a;
  font-size: 13px;
}

.code-mono {
  font-family: "JetBrains Mono", Consolas, Monaco, monospace;
}

/* 1. 顶部导航栏 */
.kanban-topbar {
  background: #0f172a;
  color: #ffffff;
  padding: 10px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 16px;
  flex-wrap: wrap;
  position: sticky;
  top: 0;
  z-index: 100;
  box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
}

.brand-box {
  display: flex;
  align-items: center;
  gap: 12px;
}

.brand-logo {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: linear-gradient(135deg, #10b981 0%, #008b4b 100%);
  display: flex;
  align-items: center;
  justify-content: center;
  font-weight: 800;
  font-size: 15px;
  color: #fff;
  flex-shrink: 0;
}

.brand-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.brand-title h1 {
  margin: 0;
  font-size: 17px;
  font-weight: 700;
  color: #fff;
}

.public-badge {
  font-size: 11px;
  font-weight: 600;
  padding: 2px 8px;
  border-radius: 999px;
  background: rgba(16, 185, 129, 0.2);
  color: #34d399;
  border: 1px solid rgba(52, 211, 153, 0.35);
}

.brand-sub {
  font-size: 11px;
  color: #94a3b8;
  margin-top: 2px;
}

.stage-tabs {
  display: flex;
  background: rgba(255, 255, 255, 0.08);
  padding: 4px;
  border-radius: 8px;
  gap: 4px;
  flex-wrap: wrap;
}

.stage-tab {
  border: none;
  background: transparent;
  color: #cbd5e1;
  padding: 6px 14px;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.16s;
}

.stage-tab:hover {
  color: #fff;
  background: rgba(255, 255, 255, 0.08);
}

.stage-tab.active {
  background: #008b4b;
  color: #ffffff;
}

.topbar-right {
  display: flex;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.stat-pill {
  background: rgba(255, 255, 255, 0.07);
  border: 1px solid rgba(255, 255, 255, 0.12);
  padding: 5px 12px;
  border-radius: 6px;
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.stat-pill span {
  font-size: 11px;
  color: #94a3b8;
}

.stat-pill strong {
  font-size: 15px;
  color: #38bdf8;
}

.pending-changes-btn {
  background: #f59e0b;
  color: #fff;
  border: none;
  padding: 5px 12px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.voice-btn {
  border: 1px solid rgba(255, 255, 255, 0.22);
  background: rgba(255, 255, 255, 0.06);
  color: #e2e8f0;
  padding: 5px 11px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.voice-btn.on {
  border-color: #34d399;
  color: #34d399;
  background: rgba(16, 185, 129, 0.16);
}

.live-clock {
  font-size: 12px;
  color: #cbd5e1;
  display: flex;
  align-items: center;
  gap: 6px;
}

.live-clock b {
  font-size: 14px;
  color: #fff;
}

.pulse-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background: #10b981;
  box-shadow: 0 0 0 3px rgba(16, 185, 129, 0.28);
}

.pulse-dot.disconnected {
  background: #f59e0b;
  box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.28);
}

/* 2. 当天交期/待接单语音催办条 */
.urgent-alert-strip {
  background: linear-gradient(90deg, #fef2f2 0%, #fff7ed 100%);
  border-bottom: 1px solid #fecaca;
  padding: 8px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.urgent-left {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.urgent-tag {
  background: #dc2626;
  color: #fff;
  font-weight: 700;
  font-size: 12px;
  padding: 3px 9px;
  border-radius: 5px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.urgent-chip {
  background: #fff;
  border: 1px solid #fca5a5;
  color: #b91c1c;
  padding: 3px 10px;
  border-radius: 999px;
  font-weight: 600;
  font-size: 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.urgent-chip:hover {
  background: #fee2e2;
}

.connection-hint {
  font-size: 12px;
  color: #64748b;
}

/* 3. 筛选与工具栏 */
.toolbar-wrap {
  background: #fff;
  border-bottom: 1px solid #e2e8f0;
  padding: 10px 20px;
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.toolbar-top-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
}

.status-pills,
.order-type-pills {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.filter-label {
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
}

.status-pill,
.type-pill {
  border: 1px solid #e2e8f0;
  background: #f8fafc;
  color: #334155;
  padding: 5px 12px;
  border-radius: 6px;
  font-size: 12.5px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}

.status-pill.active,
.type-pill.active {
  background: #e8f5e9;
  border-color: #008b4b;
  color: #008b4b;
}

.search-form-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.filter-input {
  width: 210px;
}

.date-range-box {
  display: flex;
  align-items: center;
  gap: 6px;
}

.date-sep {
  color: #64748b;
  font-size: 12px;
}

.btn-search {
  background: #008b4b;
  border-color: #008b4b;
}

/* 4. 全宽主工作区：以订单为主，内部树形下挂工单 */
.workspace-single {
  flex: 1;
  padding: 14px 20px 24px;
  max-width: 1720px;
  width: 100%;
  margin: 0 auto;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.display-error,
.dialog-error {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 18px;
  border-radius: 8px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #b45309;
}

.empty-card {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 48px 20px;
  text-align: center;
  color: #64748b;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 10px;
}

.empty-card i {
  font-size: 36px;
  color: #94a3b8;
}

.order-group-card {
  background: #ffffff;
  border: 1.5px solid #cbd5e1;
  border-radius: 10px;
  box-shadow: 0 1px 3px rgba(15, 23, 42, 0.05);
  overflow: hidden;
  transition: all 0.16s ease;
}

.order-group-card:hover {
  border-color: #008b4b;
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.08);
}

.order-group-card.is-new-order {
  border-color: #10b981;
  box-shadow: 0 0 0 2px rgba(16, 185, 129, 0.18);
}

.order-card-header {
  background: linear-gradient(90deg, #f8fafc 0%, #f1f5f9 100%);
  padding: 10px 16px;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  cursor: pointer;
}

.order-head-left,
.order-head-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.new-badge {
  background: #10b981;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 4px;
}

.order-source-badge {
  background: #dc2626;
  color: #fff;
  font-size: 11px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 4px;
}

.order-source-badge.stock {
  background: #475569;
}

.order-no-title {
  font-size: 16px;
  font-weight: 800;
  color: #0f172a;
}

.badge {
  font-size: 11.5px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 4px;
  display: inline-flex;
  align-items: center;
}

.badge-urgent {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fca5a5;
}

.badge-custom {
  background: #f5f3ff;
  color: #7c3aed;
  border: 1px solid #ddd6fe;
}

.badge-delay {
  background: #fffbeb;
  color: #d97706;
  border: 1px solid #fde68a;
}

.badge-ordinary {
  background: #eff6ff;
  color: #2563eb;
  border: 1px solid #bfdbfe;
}

.badge-sales {
  background: #fff1f2;
  color: #be123c;
  border: 1px solid #fecdd3;
}

.badge-customer {
  background: #f1f5f9;
  color: #1e293b;
  border: 1px solid #cbd5e1;
}

.badge-platform {
  background: #ecfeff;
  color: #0e7490;
  border: 1px solid #a5f3fc;
}

.delivery-pill {
  font-size: 12px;
  font-weight: 700;
  padding: 3px 9px;
  border-radius: 5px;
  background: #fff;
  border: 1px solid #cbd5e1;
  color: #334155;
}

.delivery-pill.urgent-due {
  background: #fef2f2;
  border-color: #fca5a5;
  color: #dc2626;
}

.wo-summary-pill {
  font-size: 12px;
  font-weight: 600;
  color: #0f172a;
  background: #e2e8f0;
  padding: 3px 9px;
  border-radius: 5px;
}

.btn-open-modal {
  border: 1px solid #008b4b;
  background: #e8f5e9;
  color: #008b4b;
  padding: 4px 11px;
  border-radius: 5px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
  transition: all 0.15s;
}

.btn-open-modal:hover {
  background: #008b4b;
  color: #ffffff;
}

/* 订单销售备注与图纸直出条 */
.order-brief-banner {
  padding: 10px 16px;
  background: #fffdf5;
  border-bottom: 1px solid #fde68a;
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 16px;
  align-items: center;
}

.remarks-area {
  display: flex;
  flex-direction: column;
  gap: 6px;
}

.tech-spec-chips {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.spec-chip {
  font-size: 12px;
  font-weight: 800;
  padding: 2px 9px;
  border-radius: 5px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.spec-chip.voltage {
  background: #1e3a8a;
  color: #ffffff;
}

.spec-chip.pump-yes {
  background: #b91c1c;
  color: #ffffff;
}

.spec-chip.pump-no {
  background: #e2e8f0;
  color: #475569;
  font-weight: 600;
}

.spec-chip.logistics {
  background: #0f766e;
  color: #ffffff;
}

.diff-alert-btn {
  background: #fff1f2;
  border: 1px solid #f43f5e;
  color: #e11d48;
  font-size: 11.5px;
  font-weight: 700;
  padding: 2px 9px;
  border-radius: 999px;
  cursor: pointer;
}

.diff-alert-btn:hover {
  background: #ffe4e6;
}

.remark-line {
  font-size: 13px;
  color: #1e293b;
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.remark-tag {
  font-size: 11px;
  font-weight: 700;
  padding: 1px 6px;
  border-radius: 4px;
  background: #fef3c7;
  color: #92400e;
  border: 1px solid #fcd34d;
  flex-shrink: 0;
}

.remark-tag.custom-tag {
  background: #ede9fe;
  color: #5b21b6;
  border-color: #c4b5fd;
}

.remark-text-strong {
  font-weight: 700;
  color: #9a3412;
  white-space: pre-wrap;
}

.text-muted {
  color: #94a3b8;
}

/* 内联图纸缩略图 */
.inline-drawings-strip {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.drawing-mini-card {
  width: 116px;
  background: #ffffff;
  border: 1.5px solid #cbd5e1;
  border-radius: 6px;
  padding: 4px;
  cursor: pointer;
  transition: all 0.15s;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.drawing-mini-card:hover {
  border-color: #008b4b;
  background: #f0fdf4;
  transform: translateY(-1px);
}

.drawing-mini-thumb {
  height: 54px;
  border-radius: 4px;
  background: #0f172a;
  display: flex;
  align-items: center;
  justify-content: center;
  position: relative;
  overflow: hidden;
}

.drawing-mini-thumb img {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.drawing-file-icon {
  display: flex;
  flex-direction: column;
  align-items: center;
  color: #38bdf8;
}

.drawing-file-icon i {
  font-size: 20px;
}

.drawing-file-icon small {
  font-size: 10px;
  font-weight: 700;
}

.drawing-scope-tag {
  position: absolute;
  top: 2px;
  left: 2px;
  font-size: 9px;
  font-weight: 700;
  padding: 0 4px;
  border-radius: 3px;
  background: rgba(0, 139, 75, 0.9);
  color: #fff;
  z-index: 1;
}

.drawing-scope-tag.sub {
  background: rgba(217, 119, 6, 0.92);
}

.drawing-mini-name {
  font-size: 11px;
  font-weight: 600;
  color: #1e293b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.more-drawings-btn {
  border: 1px dashed #008b4b;
  background: #f0fdf4;
  color: #008b4b;
  padding: 8px 10px;
  border-radius: 6px;
  font-size: 12px;
  font-weight: 700;
  cursor: pointer;
}

.no-drawing-pill {
  font-size: 12px;
  color: #94a3b8;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  padding: 8px 12px;
  border-radius: 6px;
}

/* 下挂生产工单树 */
.nested-workorders-section {
  padding: 10px 16px 14px;
  background: #ffffff;
}

.nested-wo-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 6px;
  margin-bottom: 8px;
  border-bottom: 1px dashed #e2e8f0;
  font-size: 12px;
  color: #64748b;
  font-weight: 600;
}

.wo-tree-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.wo-node-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 9px 12px;
  background: #f8fafc;
  transition: all 0.15s;
  cursor: pointer;
}

.wo-node-card:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}

.wo-node-card.is-child {
  margin-left: 28px;
  position: relative;
  background: #fffbeb;
  border-color: #fde68a;
}

.wo-node-card.is-child:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}

.wo-node-card.is-child::before {
  content: "";
  position: absolute;
  left: -16px;
  top: -10px;
  width: 14px;
  height: 28px;
  border-left: 2px solid #94a3b8;
  border-bottom: 2px solid #94a3b8;
  border-bottom-left-radius: 4px;
}

.wo-top-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 7px;
}

.wo-identity,
.wo-status-group {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.wo-role-tag {
  font-size: 11px;
  font-weight: 700;
  padding: 2px 7px;
  border-radius: 4px;
}

.wo-role-tag.main {
  background: #dcfce7;
  color: #15803d;
  border: 1px solid #86efac;
}

.wo-role-tag.sub {
  background: #fef3c7;
  color: #b45309;
  border: 1px solid #fcd34d;
}

.wo-no {
  font-weight: 700;
  color: #334155;
  font-size: 12.5px;
}

.wo-product-name {
  font-size: 14px;
  font-weight: 800;
  color: #0f172a;
}

.wo-qty-badge {
  font-size: 13px;
  font-weight: 800;
  color: #dc2626;
  background: #fef2f2;
  padding: 1px 7px;
  border-radius: 4px;
  border: 1px solid #fecaca;
}

.wo-spec-pill {
  font-size: 11.5px;
  color: #475569;
  background: #e2e8f0;
  padding: 1px 7px;
  border-radius: 4px;
}

.wo-state-badge {
  font-size: 11.5px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 4px;
  background: #eff6ff;
  color: #1d4ed8;
  border: 1px solid #bfdbfe;
}

.wo-state-badge.state-done {
  background: #ecfdf5;
  color: #047857;
  border-color: #a7f3d0;
}

.wo-state-badge.state-running {
  background: #eff6ff;
  color: #1d4ed8;
  border-color: #93c5fd;
}

.wo-state-badge.state-wait {
  background: #fffbeb;
  color: #b45309;
  border-color: #fde68a;
}

.kitting-badge {
  font-size: 11.5px;
  font-weight: 700;
  padding: 2px 8px;
  border-radius: 4px;
}

.kitting-badge.short {
  background: #fef2f2;
  color: #dc2626;
  border: 1px solid #fca5a5;
}

.kitting-badge.ready {
  background: #ecfdf5;
  color: #047857;
  border: 1px solid #a7f3d0;
}

.wo-ops-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}

.ops-chain {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}

.op-step {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 9px;
  border-radius: 6px;
  font-size: 12px;
  border: 1px solid #cbd5e1;
  background: #ffffff;
  color: #475569;
}

.op-step.done {
  background: #ecfdf5;
  border-color: #6ee7b7;
  color: #065f46;
}

.op-step.timing {
  background: #fff1f2;
  border-color: #f43f5e;
  color: #be123c;
  font-weight: 700;
}

.op-step.claimed {
  background: #eff6ff;
  border-color: #93c5fd;
  color: #1e40af;
  font-weight: 600;
}

.op-arrow {
  color: #94a3b8;
  font-size: 11px;
}

.wo-meta-right {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 11.5px;
  color: #64748b;
}

.semi-summary-text {
  font-weight: 600;
  color: #334155;
}

.wo-inline-remark {
  max-width: 260px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: #b45309;
  background: #fef3c7;
  padding: 1px 6px;
  border-radius: 4px;
}

.unit-sn-trigger,
.btn-wo-detail {
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #334155;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
  cursor: pointer;
}

.unit-sn-trigger:hover,
.btn-wo-detail:hover {
  border-color: #008b4b;
  color: #008b4b;
}

.sn-drawer {
  margin-top: 7px;
  padding-top: 7px;
  border-top: 1px dashed #cbd5e1;
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.sn-drawer-label {
  font-size: 11px;
  color: #64748b;
  font-weight: 600;
}

.sn-chip {
  font-size: 11.5px;
  padding: 2px 8px;
  border-radius: 4px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.sn-chip .sn-state {
  color: #008b4b;
  font-weight: 600;
}

/* 底部分页 */
.display-pagination {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  color: #475569;
}

.page-size {
  margin-left: 12px;
  padding: 3px 10px;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 12px;
}

/* 5. 详情与设计图纸居中大弹窗样式 */
.modal-wo-switcher {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  padding: 8px 12px;
  margin-bottom: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}

.switcher-label {
  font-size: 12px;
  font-weight: 700;
  color: #475569;
}

.wo-switch-pill {
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #334155;
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 5px;
}

.wo-switch-pill.active {
  border-color: #008b4b;
  background: #e8f5e9;
  color: #008b4b;
}

.role-dot {
  font-size: 10px;
  font-weight: 800;
  padding: 1px 4px;
  border-radius: 3px;
}

.role-dot.main {
  background: #dcfce7;
  color: #15803d;
}

.role-dot.sub {
  background: #fef3c7;
  color: #b45309;
}

.modal-grid-2 {
  display: grid;
  grid-template-columns: 1.25fr 1fr;
  gap: 16px;
  align-items: start;
}

.modal-section-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 8px;
}

.modal-section-head h4 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}

.drawing-source-tabs {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
  margin-bottom: 8px;
}

.ds-tab {
  border: 1px solid #cbd5e1;
  background: #fff;
  color: #475569;
  padding: 5px 10px;
  border-radius: 5px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
}

.ds-tab.active {
  background: #008b4b;
  color: #fff;
  border-color: #008b4b;
}

.blueprint-stage {
  background: #0b1324;
  height: 390px;
  border-radius: 8px;
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  overflow: auto;
  border: 1px solid #1e293b;
}

.stage-image {
  max-width: 100%;
  max-height: 100%;
  object-fit: contain;
}

.stage-iframe {
  width: 100%;
  height: 100%;
  border: 0;
  background: #fff;
}

.preview-error-box,
.empty-drawing-stage {
  padding: 36px 20px;
  text-align: center;
  color: #94a3b8;
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 8px;
}

.empty-drawing-stage {
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  height: 220px;
  justify-content: center;
}

.empty-drawing-stage i,
.preview-error-box i {
  font-size: 32px;
}

.blueprint-caption {
  position: absolute;
  bottom: 8px;
  left: 10px;
  right: 10px;
  background: rgba(15, 23, 42, 0.85);
  color: #e2e8f0;
  padding: 5px 10px;
  border-radius: 5px;
  font-size: 11.5px;
  display: flex;
  justify-content: space-between;
}

.modal-info-col {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.info-block {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
  background: #f8fafc;
}

.info-block.highlight-sales {
  background: #fffbeb;
  border-color: #fde68a;
}

.info-block-title {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
  margin-bottom: 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
}

.title-actions {
  display: flex;
  align-items: center;
  gap: 6px;
}

.btn-history-link {
  border: 1px solid #008b4b;
  background: #fff;
  color: #008b4b;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 11.5px;
  font-weight: 600;
  cursor: pointer;
}

.kv-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 12.5px;
}

.kv-row {
  display: grid;
  grid-template-columns: 88px minmax(0, 1fr);
  gap: 8px;
}

.kv-lbl {
  color: #64748b;
  font-weight: 600;
}

.kv-val {
  color: #0f172a;
  font-weight: 600;
  word-break: break-word;
  white-space: pre-wrap;
}

.text-danger-strong {
  color: #b91c1c;
  font-weight: 800;
}

.remark-highlight {
  color: #9a3412;
  font-weight: 700;
}

.modal-ops-section,
.modal-materials-section {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px dashed #cbd5e1;
}

.sub-label {
  font-size: 12px;
  font-weight: 700;
  color: #334155;
  margin-bottom: 6px;
}

.mini-table-scroll {
  max-height: 180px;
  overflow: auto;
}

.mini-mat-table,
.detail-products {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  background: #fff;
}

.mini-mat-table th,
.mini-mat-table td,
.detail-products th,
.detail-products td {
  border: 1px solid #e2e8f0;
  padding: 6px 8px;
  text-align: left;
}

.mini-mat-table th,
.detail-products th {
  background: #f1f5f9;
  font-weight: 700;
}

.mat-short {
  color: #dc2626;
  font-weight: 700;
}

.mat-ok {
  color: #047857;
  font-weight: 600;
}

.detail-footer {
  display: flex;
  justify-content: space-between;
}

.version-tabs {
  display: flex;
  gap: 8px;
  margin-bottom: 14px;
  border-bottom: 1px solid #e2e8f0;
  padding-bottom: 8px;
}

.version-tabs button {
  border: 1px solid #cbd5e1;
  background: #fff;
  padding: 6px 14px;
  border-radius: 6px;
  cursor: pointer;
  font-weight: 600;
}

.version-tabs button.active {
  background: #008b4b;
  color: #fff;
  border-color: #008b4b;
}

.version-meta {
  display: flex;
  gap: 16px;
  padding: 10px 14px;
  background: #f8fafc;
  border-radius: 6px;
  margin-bottom: 14px;
}

.change-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-weight: 700;
}

.change-title i {
  color: #f59e0b;
}

.change-summary {
  display: flex;
  flex-wrap: wrap;
  gap: 10px 20px;
  margin-bottom: 14px;
  padding: 12px 16px;
  border: 1px solid #fde68a;
  border-radius: 6px;
  background: #fffbeb;
}

@media (max-width: 1024px) {
  .order-brief-banner,
  .modal-grid-2 {
    grid-template-columns: minmax(0, 1fr);
  }
}
</style>
