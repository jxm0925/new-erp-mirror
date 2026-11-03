<template>
  <section v-loading="treeLoading" class="order-progress">
    <div class="progress-view-toolbar">
      <el-button
        size="mini"
        :icon="showWorkOrders ? 'el-icon-share' : 'el-icon-s-order'"
        @click="toggleWorkOrders"
        >{{ showWorkOrders ? '按产品查看' : '按工单列表' }}</el-button
      >
    </div>
    <div v-if="showWorkOrders" class="work-order-list">
      <slot name="work-orders" />
    </div>
    <div v-else class="progress-layout">
      <aside class="product-tree progress-card">
        <h3>订单产品</h3>
        <div v-if="treeError" class="progress-empty">
          <p>{{ treeError }}</p>
          <el-button size="mini" @click="loadTree">重新加载</el-button>
        </div>
        <div
          v-else-if="!flatNodes.length && !treeLoading"
          class="progress-empty"
        >
          暂无订单产品
        </div>
        <div v-else class="product-tree-list" role="tree" aria-label="订单产品">
          <button
            v-for="entry in flatNodes"
            :key="entry.node.key"
            type="button"
            role="treeitem"
            :aria-level="entry.depth + 1"
            :aria-selected="selectedNode && selectedNode.key === entry.node.key"
            class="product-node"
            :class="{
              selected: selectedNode && selectedNode.key === entry.node.key,
              'product-node--root': entry.depth === 0
            }"
            :style="{
              marginLeft: `${entry.depth * 18}px`,
              width: `calc(100% - ${entry.depth * 18}px)`
            }"
            @click="selectNode(entry.node)"
          >
            <el-image
              v-if="entry.node.image_url"
              class="node-image"
              :src="entry.node.image_url"
              fit="contain"
            />
            <span v-else class="node-type">{{ nodeType(entry.node) }}</span>
            <span class="node-copy"
              ><b>{{ entry.node.name }}</b
              ><small v-if="entry.node.type === 'line'">{{
                entry.node.subtitle ||
                `${number(entry.node.quantity)} ${entry.node.unit_name || ''}`
              }}</small
              ><small
                v-else-if="
                  entry.node.document_no && permissions.work_order_links
                "
                >{{ entry.node.document_no }}</small
              ><small
                v-if="entry.node.type !== 'line' && entry.node.subtitle"
                >{{ entry.node.subtitle }}</small
              ></span
            >
            <span class="progress-tag" :class="entry.node.status">{{
              entry.node.status_label || quantityLabel(entry.node)
            }}</span>
          </button>
        </div>
      </aside>

      <main v-loading="detailLoading" class="progress-detail progress-card">
        <div v-if="detailError" class="progress-empty">
          <p>{{ detailError }}</p>
          <el-button size="mini" @click="selectNode(selectedNode)"
            >重新加载</el-button
          >
        </div>
        <template v-else-if="selectedNode && selectedNode.type === 'material'">
          <div class="subject-header">
            <div class="subject-copy">
              <h2>{{ selectedNode.name }}</h2>
              <p>{{ selectedNode.subtitle || '库存 / 外购备料' }}</p>
            </div>
            <span class="progress-tag" :class="selectedNode.status">{{
              selectedNode.status_label
            }}</span>
          </div>
          <div class="material-quantities">
            <span
              >需求数量
              <b
                >{{ number(selectedNode.planned_qty) }}
                {{ selectedNode.unit_name }}</b
              ></span
            ><span
              >已领数量
              <b
                >{{ number(selectedNode.completed_qty) }}
                {{ selectedNode.unit_name }}</b
              ></span
            >
          </div>
        </template>
        <template v-else-if="detail">
          <div class="subject-header">
            <el-image
              v-if="subject.image_url"
              class="subject-image"
              :src="subject.image_url"
              fit="contain"
            />
            <div v-else class="subject-image subject-image--empty">
              <i class="el-icon-picture-outline" /><span>未上传产品图</span>
            </div>
            <div class="subject-copy">
              <div class="subject-title">
                <h2>{{ subject.name || selectedNode.name }}</h2>
                <span v-if="subject.line_no">第 {{ subject.line_no }} 行</span
                ><span
                  >{{ number(subject.quantity) }}
                  {{ subject.unit_name || '' }}</span
                ><span v-if="subject.routing_name"
                  >{{ subject.routing_name
                  }}{{
                    subject.routing_version
                      ? ` V${subject.routing_version}`
                      : ''
                  }}</span
                ><span v-if="subject.destination_label"
                  >去向：{{ subject.destination_label }}</span
                >
              </div>
              <div v-if="(!quantitySummary || quantitySummary.requires_delivery) && (detail.units_mode !== 'quantity' || isWorkOrder)" class="unit-selector">
                <span>{{ isWorkOrder ? '查看：' : '选择设备：' }}</span>
                <span
                  v-if="detail.units_mode === 'quantity'"
                  class="quantity-mode"
                  >工单计划 {{ number(subject.quantity) }} {{ subject.unit_name || '' }}</span
                >
                <template v-else-if="detail.unit_total <= 8">
                  <button
                    v-if="isWorkOrder"
                    type="button"
                    class="unit-chip"
                    :class="{ active: !selectedUnitKey }"
                    @click="selectUnit('')"
                  >
                    全部 {{ detail.unit_total }} 台
                  </button>
                  <button
                    v-for="unit in detail.unit_options"
                    :key="unit.key"
                    type="button"
                    class="unit-chip"
                    :class="{ active: selectedUnitKey === unit.key }"
                    @click="selectUnit(unit.key)"
                  >
                    <b>{{ unit.serial_no || unit.label || unit.key }}</b
                    ><small v-if="unit.current_node">{{
                      unit.current_node.label
                    }}</small>
                  </button>
                  <span v-if="!detail.unit_total">暂无设备记录</span>
                </template>
                <el-select
                  v-else
                  v-model="selectedUnitKey"
                  class="remote-unit-select"
                  size="small"
                  filterable
                  remote
                  reserve-keyword
                  :remote-method="searchUnits"
                  :loading="unitsLoading"
                  placeholder="搜索 SN / 设备号"
                  @change="selectUnit"
                >
                  <el-option
                    v-if="isWorkOrder"
                    :value="''"
                    :label="`全部 ${detail.unit_total} 台`"
                  />
                  <el-option
                    v-for="unit in selectOptions"
                    :key="unit.key"
                    :value="unit.key"
                    :label="`${unit.serial_no || unit.label || unit.key}${
                      unit.current_node ? ' · ' + unit.current_node.label : ''
                    }`"
                  />
                  <el-option class="unit-page-option" disabled value="__page__"
                    ><div class="unit-option-pagination">
                      <span
                        >共{{ unitsPage.total }}台 ·
                        {{ unitsPage.current_page }}/{{
                          unitsPage.last_page
                        }}页</span
                      ><el-button
                        type="text"
                        size="mini"
                        :disabled="unitsPage.current_page <= 1 || unitsLoading"
                        @click.stop="turnUnits(-1)"
                        >上一页</el-button
                      ><el-button
                        type="text"
                        size="mini"
                        :disabled="
                          unitsPage.current_page >= unitsPage.last_page ||
                          unitsLoading
                        "
                        @click.stop="turnUnits(1)"
                        >下一页</el-button
                      >
                    </div></el-option
                  >
                </el-select>
              </div>
            </div>
            <div class="subject-meta">
              <span v-if="subject.shipment_no"
                >发货单 {{ subject.shipment_no }}</span
              >
              <span v-if="subject.planned_finish_date"
                >计划完工 {{ date(subject.planned_finish_date, 10) }}</span
              ><el-button
                v-if="
                  isWorkOrder &&
                  subject.document_no &&
                  permissions.work_order_links
                "
                type="text"
                size="mini"
                @click="
                  $router.push(
                    `/production/work-orders/${
                      selectedNode.work_order_id || selectedNode.id
                    }`
                  )
                "
                >{{ subject.document_no }}</el-button
              ><span
                v-else-if="
                  !isWorkOrder &&
                  subject.document_no &&
                  permissions.work_order_links
                "
                >{{ subject.document_no }}</span
              ><span v-if="subject.parent_name"
                >上级：{{ subject.parent_name }}</span
              >
            </div>
          </div>

          <div v-if="quantitySummary" class="material-quantities">
            <span>本行汇总 · {{ quantitySummary.fulfillment_label }}</span>
            <span>{{ quantitySummary.requires_delivery ? '销售需求' : '订单数量' }}<b>{{ summaryQuantity(quantitySummary.order_qty, quantitySummary.sales_unit_name) }}</b></span>
            <template v-if="quantitySummary.requires_delivery">
              <span>订单库存需求<b>{{ summaryQuantity(quantitySummary.required_base_qty, quantitySummary.base_unit_name) }}</b></span>
              <span>库存余额<b>{{ summaryQuantity(quantitySummary.inventory_on_hand_qty, quantitySummary.sales_unit_name) }}</b></span>
              <span>可再锁库存<b>{{ summaryQuantity(quantitySummary.inventory_available_qty, quantitySummary.sales_unit_name) }}</b></span>
              <span>本行已锁<b>{{ summaryQuantity(quantitySummary.locked_inventory_qty, quantitySummary.sales_unit_name) }}</b></span>
              <span v-if="quantitySummary.fulfillment_strategy === 'production' || Number(quantitySummary.pending_production_qty) > 0">生产待补<b>{{ summaryQuantity(quantitySummary.pending_production_qty, quantitySummary.sales_unit_name) }}</b></span>
              <span v-if="quantitySummary.fulfillment_strategy === 'purchase' || Number(quantitySummary.pending_purchase_qty) > 0">外购待补<b>{{ summaryQuantity(quantitySummary.pending_purchase_qty, quantitySummary.sales_unit_name) }}</b></span>
              <span>已发<b>{{ summaryQuantity(quantitySummary.shipped_qty, quantitySummary.sales_unit_name) }}</b></span>
              <span>待发<b>{{ summaryQuantity(quantitySummary.pending_fulfillment_qty, quantitySummary.sales_unit_name) }}</b></span>
              <span v-if="quantitySummary.inventory_pool_shared">同物料行共用库存</span>
            </template>
          </div>
          <p v-if="quantitySummary && quantitySummary.quantity_notice" class="progress-notice">{{ quantitySummary.quantity_notice }}</p>

          <!-- 工单备料与领料情况（原树形物料转移至此处，集中展示） -->
          <div v-if="materials.length" class="materials-overview-bar">
            <div class="materials-bar-header" @click="materialsExpanded = !materialsExpanded">
              <div class="materials-title">
                <i class="el-icon-receiving" />
                <b>工单用料与领料明细</b>
                <span class="materials-badge count-all">共 {{ materials.length }} 项用料</span>
                <span v-if="materialsCompletedCount" class="materials-badge count-done">已领齐 {{ materialsCompletedCount }} 项</span>
                <span v-if="materialsPendingCount" class="materials-badge count-pending">待领 {{ materialsPendingCount }} 项</span>
              </div>
              <button type="button" class="materials-toggle-btn">
                {{ materialsExpanded ? '收起明细' : '展开明细' }}
                <i :class="materialsExpanded ? 'el-icon-arrow-up' : 'el-icon-arrow-down'" />
              </button>
            </div>
            <el-collapse-transition>
              <div v-show="materialsExpanded" class="materials-table-wrapper">
                <el-table :data="materials" size="mini" border class="materials-compact-table">
                  <el-table-column prop="component_item_name" label="物料名称" min-width="140" show-overflow-tooltip>
                    <template slot-scope="{row}">
                      <b class="mat-name">{{ row.component_item_name }}</b>
                      <small v-if="row.component_item_code" class="text-muted font-mono"> ({{ row.component_item_code }})</small>
                    </template>
                  </el-table-column>
                  <el-table-column prop="specification" label="规格 / 下料尺寸" min-width="160" show-overflow-tooltip>
                    <template slot-scope="{row}">
                      <span>{{ row.specification || '-' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="计划需求" width="110" align="right">
                    <template slot-scope="{row}">
                      <span class="font-mono">{{ number(row.required_qty) }} {{ row.unit_name }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="实际已领" width="110" align="right">
                    <template slot-scope="{row}">
                      <b class="font-mono" :style="{ color: row.status === 'completed' ? '#008b4b' : (row.issued_qty > 0 ? '#d97706' : '#94a3b8') }">
                        {{ number(row.issued_qty) }} {{ row.unit_name }}
                      </b>
                    </template>
                  </el-table-column>
                  <el-table-column label="领料状态" width="90" align="center">
                    <template slot-scope="{row}">
                      <el-tag size="mini" :type="row.status === 'completed' ? 'success' : (row.status === 'partial' ? 'warning' : 'info')">
                        {{ row.status_label }}
                      </el-tag>
                    </template>
                  </el-table-column>
                </el-table>
              </div>
            </el-collapse-transition>
          </div>

          <template v-if="!quantitySummary || quantitySummary.requires_delivery">
            <div v-if="overviewGroups.length" class="tl-overview">
              <template v-for="(group, gi) in overviewGroups">
                <div
                  v-if="gi"
                  :key="group.key + '-sep'"
                  class="tl-sep"
                  :class="{ 'is-gray': !overviewGroups[gi - 1].done }"
                />
                <div :key="group.key" class="tl-grp">
                  <span class="tl-grp-label" :class="'is-' + group.key">{{
                    group.label
                  }}</span>
                  <div
                    v-for="step in group.steps"
                    :key="step.key"
                    class="tl-step"
                    :class="step.state"
                  >
                    <span class="tl-dot"
                      ><i v-if="step.state === 'is-done'" class="el-icon-check"
                    /></span>
                    <b>{{ step.label }}</b>
                    <small>{{ step.text }}</small>
                    <em v-if="step.badge" class="tl-badge">{{ step.badge }}</em>
                  </div>
                </div>
              </template>
            </div>

            <div
              v-if="progress"
              class="tl-pin"
              :class="'is-' + progress.state"
            >
              <b>当前进度：{{ progress.title }}</b>
              <span v-if="progress.detail" class="tl-pin-detail">{{
                progress.detail
              }}</span>
              <template v-if="progress.upcoming && progress.upcoming.length"
                ><span class="tl-arrow">→</span
                ><span>后续：{{ progress.upcoming.join(' → ') }}</span></template
              >
            </div>

            <div class="tl-filter">
              <h3>处理过程</h3>
              <button
                v-for="option in filterOptions"
                :key="option.key"
                type="button"
                class="tl-chip"
                :class="{ active: timelineFilter === option.key }"
                @click="timelineFilter = option.key"
              >
                {{ option.label }} {{ option.count }}
              </button>
              <span class="tl-hint">最新在上</span>
            </div>

            <div v-if="filteredTimeline.length" class="tl">
              <div
                v-for="item in filteredTimeline"
                :key="item.key"
                class="tl-ev"
                :class="[eventClass(item), { 'is-cancelled': item.is_cancelled }]"
              >
                <div class="tl-time">
                  <b>{{ clock(item.time) }}</b
                  >{{ day(item.time) }}
                </div>
                <div class="tl-rail">
                  <i>{{ eventIcon(item) }}</i>
                </div>
                <div class="tl-body" :class="{ 'is-sub': item.kind === 'sub_group' }">
                  <template v-if="item.kind === 'sub_group'">
                    <div class="tl-head is-clickable" @click="toggleGroup(item.key)">
                      <span class="tl-stage is-sub">子工单</span>
                      <b>{{ item.name }}</b>
                      <span class="tl-res" :class="'is-' + item.status">{{
                        item.summary
                      }}</span>
                      <span v-if="item.fail_count" class="tl-res is-failed"
                        >不合格 {{ item.fail_count }} 次</span
                      >
                      <button
                        v-if="item.work_order_no && permissions.work_order_links"
                        type="button"
                        class="tl-sn tl-link"
                        @click.stop="openWorkOrder(item.work_order_id)"
                      >
                        {{ item.work_order_no }}
                      </button>
                      <span v-else-if="item.work_order_no" class="tl-sn">{{
                        item.work_order_no
                      }}</span>
                      <span class="tl-caret">{{
                        groupOpen(item.key)
                          ? '收起 ▴'
                          : '展开 ' + item.events.length + ' 条 ▾'
                      }}</span>
                    </div>
                    <div class="tl-sub">
                      <span v-if="item.time_from">{{ date(item.time_from) }} ~ {{ date(item.time) }}</span>
                    </div>
                    <div v-show="groupOpen(item.key)" class="tl-inner">
                      <div
                        v-for="inner in item.events"
                        :key="inner.key"
                        class="tl-row"
                        :class="'is-' + inner.status"
                      >
                        <time>{{ date(inner.time) }}</time>
                        <div class="tl-row-main">
                          <div class="tl-row-line">
                            <b>{{ inner.label }}</b>
                            <span class="tl-res" :class="'is-' + inner.status">{{
                              inner.result_label
                            }}</span>
                            <span v-if="inner.round_label" class="tl-res is-round">{{
                              inner.round_label
                            }}</span>
                            <span v-for="sn in inner.unit_labels" :key="sn" class="tl-sn">{{
                              sn
                            }}</span>
                            <span v-if="inner.person" class="tl-person">{{
                              inner.person
                            }}</span>
                          </div>
                          <div v-if="inner.reason" class="tl-callout">{{ inner.reason }}</div>
                          <div v-if="inner.return_to" class="tl-callout is-back">
                            ↩ 已退回「{{ inner.return_to }}」返修
                          </div>
                          <div v-if="inner.media && inner.media.length" class="record-media">
                            <template v-for="(media, index) in inner.media"
                              ><el-image
                                v-if="media.type === 'image'"
                                :key="`image-${index}`"
                                class="record-thumb"
                                :src="media.url"
                                :preview-src-list="imageUrls(inner.media)"
                                fit="cover"
                                ><div slot="error" class="media-load-error">图片读取失败</div></el-image
                              ><button
                                v-else-if="media.type === 'video'"
                                :key="`video-${index}`"
                                type="button"
                                class="record-thumb video-thumb"
                                aria-label="播放报工视频"
                                @click="openVideo(media)"
                              >
                                <img v-if="media.poster_url" :src="media.poster_url" alt="视频封面" /><video
                                  v-else
                                  :src="media.url"
                                  preload="metadata"
                                  muted
                                /><i class="el-icon-video-play" /><small>{{ duration(media.duration) }}</small>
                              </button></template
                            >
                          </div>
                        </div>
                      </div>
                    </div>
                  </template>
                  <template v-else>
                    <div class="tl-head">
                      <span class="tl-stage" :class="'is-' + item.stage">{{
                        stageLabel(item.stage)
                      }}</span>
                      <b>{{ item.label }}</b>
                      <span class="tl-res" :class="'is-' + item.status">{{
                        item.result_label
                      }}</span>
                      <span v-if="item.round_label" class="tl-res is-round">{{
                        item.round_label
                      }}</span>
                      <template v-if="showUnits"
                        ><span v-for="sn in item.unit_labels" :key="sn" class="tl-sn">{{
                          sn
                        }}</span></template
                      >
                      <b v-if="item.package_no" class="tl-pkg">{{ item.package_no }}</b>
                    </div>
                    <div v-if="hasMeta(item)" class="tl-sub">
                      <span v-if="item.person">{{ item.person }}</span>
                      <span v-if="item.operator_name && item.operator_name !== item.person"
                        >报工人 {{ item.operator_name }}</span
                      >
                      <span v-if="item.collaborators && item.collaborators.length"
                        >协作 {{ people(item.collaborators) }}</span
                      >
                      <span v-if="item.claimed_at">接单 {{ date(item.claimed_at) }}</span>
                      <span v-if="item.started_at">开始 {{ date(item.started_at) }}</span>
                      <span v-if="quantityText(item)">{{ quantityText(item) }}</span>
                      <span v-if="item.scope_label">{{ item.scope_label }}</span>
                      <span v-if="item.description">{{ item.description }}</span>
                    </div>
                    <div v-if="item.reason" class="tl-callout">{{ item.reason }}</div>
                    <div v-if="item.return_to" class="tl-callout is-back">
                      ↩ 已退回「{{ item.return_to }}」返修，返修完成后继续
                    </div>
                    <div
                      v-for="run in item.cutting_runs || []"
                      :key="run.id || run.run_no"
                      class="tl-cut"
                    >
                      <b>{{ run.run_no || run.document_no }}</b
                      ><span v-if="run.scope_label">{{ run.scope_label }}</span>
                      <p v-if="run.items && run.items.length">
                        切割件：{{
                          run.items
                            .map(
                              (cut) =>
                                `${cut.name || cut.item_name} × ${number(
                                  cut.pieces != null
                                    ? cut.pieces
                                    : cut.quantity != null
                                    ? cut.quantity
                                    : cut.qty
                                )}`
                            )
                            .join('、')
                        }}
                      </p>
                    </div>
                    <div v-if="item.media && item.media.length" class="record-media">
                      <template v-for="(media, index) in item.media"
                        ><el-image
                          v-if="media.type === 'image'"
                          :key="`image-${index}`"
                          class="record-thumb"
                          :src="media.url"
                          :preview-src-list="imageUrls(item.media)"
                          fit="cover"
                          ><div slot="error" class="media-load-error">图片读取失败</div></el-image
                        ><button
                          v-else-if="media.type === 'video'"
                          :key="`video-${index}`"
                          type="button"
                          class="record-thumb video-thumb"
                          aria-label="播放报工视频"
                          @click="openVideo(media)"
                        >
                          <img v-if="media.poster_url" :src="media.poster_url" alt="视频封面" /><video
                            v-else
                            :src="media.url"
                            preload="metadata"
                            muted
                          /><i class="el-icon-video-play" /><small>{{ duration(media.duration) }}</small>
                        </button></template
                      >
                    </div>
                    <span
                      v-else-if="item.stage === 'production' && item.key.indexOf('report:') === 0 && item.status === 'completed'"
                      class="missing-media"
                      >未上传完工影像</span
                    >
                  </template>
                </div>
              </div>
            </div>
            <div v-else class="progress-empty">
              {{ timelineAll.length ? '没有符合条件的记录' : '暂无处理记录，接单后这里按时间显示每一步。' }}
            </div>
            <p v-if="detail.notice" class="progress-notice">
              {{ detail.notice }}
            </p>
          </template>
        </template>
        <div v-else-if="!detailLoading" class="progress-empty">
          选择左侧产品查看进度
        </div>
      </main>
    </div>
    <el-dialog
      title="完工视频"
      :visible.sync="videoVisible"
      width="min(960px, calc(100vw - 48px))"
      top="6vh"
      append-to-body
      destroy-on-close
      @closed="videoMedia = null"
      ><div v-if="videoMedia" class="video-preview">
        <video
          :src="videoMedia.url"
          :poster="videoMedia.poster_url || undefined"
          controls
          autoplay
        /></div
    ></el-dialog>
  </section>
</template>

<script>
import {
  getSalesOrderProgressTree,
  listSalesOrderProgressUnits,
  getSalesOrderLineProgress,
  getSalesOrderWorkOrderProgress
} from '@/api/erp/sales'
import { erpDateTime } from '@/utils/dateTime'

export default {
  props: { orderId: { type: [Number, String], required: true } },
  data: () => ({
    nodes: [],
    permissions: {},
    selectedNode: null,
    detail: null,
    treeLoading: false,
    detailLoading: false,
    unitsLoading: false,
    treeError: '',
    detailError: '',
    showWorkOrders: false,
    selectedUnitKey: '',
    unitRows: [],
    unitsKeyword: '',
    unitsPage: { total: 0, current_page: 1, last_page: 1 },
    treeSequence: 0,
    detailSequence: 0,
    unitsSequence: 0,
    materialsExpanded: false,
    timelineFilter: 'all',
    expandedGroups: {},
    videoVisible: false,
    videoMedia: null
  }),
  computed: {
    materials() {
      return (this.detail && this.detail.materials) || []
    },
    materialsCompletedCount() {
      return this.materials.filter((m) => m.status === 'completed').length
    },
    materialsPendingCount() {
      return this.materials.filter((m) => m.status !== 'completed').length
    },
    flatNodes() {
      const rows = []
      const visit = (nodes, depth) =>
        nodes.forEach((node) => {
          if (node.type === 'material') return
          rows.push({ node, depth })
          visit(node.children || [], depth + 1)
        })
      visit(this.nodes, 0)
      return rows
    },
    subject() {
      return this.detail.subject || {}
    },
    quantitySummary() {
      return this.isWorkOrder ? null : this.detail?.quantity_summary || null
    },
    isWorkOrder() {
      return this.selectedNode && this.selectedNode.type === 'work_order'
    },
    selectOptions() {
      const selected = this.detail && this.detail.selected_unit
      return selected &&
        !this.unitRows.some((unit) => unit.key === selected.key)
        ? [selected, ...this.unitRows]
        : this.unitRows
    },
    progress() {
      return (this.detail && this.detail.progress) || null
    },
    timelineAll() {
      return (this.detail && this.detail.timeline) || []
    },
    showUnits() {
      return !this.detail || this.detail.units_mode !== 'unit'
    },
    filteredTimeline() {
      const filter = this.timelineFilter
      return this.timelineAll.filter((item) => {
        if (filter === 'all') return true
        if (filter === 'abnormal') return this.isAbnormal(item)
        return item.stage === filter
      })
    },
    filterOptions() {
      const all = this.timelineAll
      const options = [{ key: 'all', label: '全部', count: all.length }]
      const labels = { sub: '子工单', production: '生产', shipment: '发货' }
      Object.keys(labels).forEach((stage) => {
        const count = all.filter((item) => item.stage === stage).length
        if (count) options.push({ key: stage, label: labels[stage], count })
      })
      const abnormal = all.filter((item) => this.isAbnormal(item)).length
      if (abnormal) options.push({ key: 'abnormal', label: '只看异常', count: abnormal })
      return options
    },
    overviewGroups() {
      if (!this.detail) return []
      // 当前该做的那一步即使还没人接单，也按“进行中”高亮，和置顶的当前进度一致。
      const currentKey = this.progress && this.progress.state === 'doing' ? this.progress.node_key : null
      const stateOf = (status, key) =>
        key && key === currentKey
          ? 'is-doing'
          : status === 'completed'
          ? 'is-done'
          : status === 'in_progress'
          ? 'is-doing'
          : status === 'cancelled'
          ? 'is-cancelled'
          : ''
      const groups = []
      const subs = this.timelineAll.filter((item) => item.kind === 'sub_group')
      if (subs.length)
        groups.push({
          key: 'sub',
          label: '子工单',
          done: subs.every((item) => item.status === 'completed'),
          steps: subs.map((item) => ({
            key: item.key,
            label: item.name,
            state: stateOf(item.status, item.key),
            text: item.status === 'completed' ? item.progress_label + ' 已完成' : item.progress_label + ' 进行中',
            badge: item.fail_count ? `不合格 ${item.fail_count} 次` : ''
          }))
        })
      const purchase =
        this.quantitySummary && this.quantitySummary.fulfillment_strategy === 'purchase'
      const make = (key, label, nodes, badgeText) => {
        if (!nodes || !nodes.length) return
        groups.push({
          key,
          label,
          done: nodes.every((node) => ['completed', 'cancelled'].includes(node.status)),
          steps: nodes.map((node) => ({
            key: node.key,
            label: node.label,
            state: stateOf(node.status, node.key),
            text: this.stepText(node),
            badge: node.rework_count ? `${badgeText} ${node.rework_count} 次` : ''
          }))
        })
      }
      make('production', purchase ? '备货' : '生产', this.detail.production_nodes, '返修')
      make('shipment', '发货', this.detail.shipment_nodes, '不合格')
      return groups
    }
  },
  watch: {
    orderId: {
      immediate: true,
      handler() {
        this.loadTree()
      }
    }
  },
  beforeDestroy() {
    this.treeSequence++
    this.detailSequence++
    this.unitsSequence++
  },
  methods: {
    date(value, length = 16) {
      return erpDateTime(value, length)
    },
    number(value) {
      return value == null
        ? '—'
        : Number(value).toLocaleString('zh-CN', { maximumFractionDigits: 4 })
    },
    summaryQuantity(value, unit) {
      return value == null ? '—' : `${this.number(value)} ${unit || ''}`
    },
    nodeType(node) {
      return (
        node.type_label ||
        { line: '产品', work_order: '工单', material: '材料' }[node.type]
      )
    },
    quantityLabel(node) {
      return node.planned_qty == null
        ? ''
        : `${this.number(node.completed_qty || 0)}/${this.number(
            node.planned_qty
          )}`
    },
    statusText(status) {
      return (
        {
          completed: '已完成',
          in_progress: '进行中',
          rework: '返工中',
          failed: '不合格',
          pending: '待接单',
          cancelled: '已取消'
        }[status] || '暂无记录'
      )
    },
    stepText(node) {
      if (this.isWorkOrder || this.detail.units_mode === 'quantity')
        return `${node.progress_label || this.quantityLabel(node)}${
          node.rework_count ? ` · 返工 ${node.rework_count}` : ''
        }`
      return node.time
        ? this.date(node.time)
        : node.status_label || this.statusText(node.status)
    },
    people(rows) {
      return rows
        .map((person) =>
          typeof person === 'string'
            ? person
            : person.employee_name || person.name
        )
        .filter(Boolean)
        .join('、')
    },
    imageUrls(media) {
      return media
        .filter((item) => item.type === 'image')
        .map((item) => item.url)
    },
    duration(seconds) {
      if (seconds == null) return '视频'
      return `${Math.floor(seconds / 60)
        .toString()
        .padStart(2, '0')}:${Math.floor(seconds % 60)
        .toString()
        .padStart(2, '0')}`
    },
    stageLabel(stage) {
      return { sub: '子工单', production: '生产', shipment: '发货' }[stage] || ''
    },
    isAbnormal(item) {
      return item.status === 'failed' || (item.kind === 'sub_group' && item.fail_count > 0)
    },
    eventClass(item) {
      if (item.kind === 'sub_group')
        return item.fail_count > 0
          ? 'is-failed'
          : { completed: 'is-done', in_progress: 'is-doing' }[item.status] || 'is-todo'
      return (
        { completed: 'is-done', failed: 'is-failed', in_progress: 'is-doing', cancelled: 'is-cancelled' }[
          item.status
        ] || 'is-todo'
      )
    },
    eventIcon(item) {
      const cls = this.eventClass(item)
      return cls === 'is-done' ? '✓' : cls === 'is-failed' ? '!' : cls === 'is-doing' ? '●' : ''
    },
    clock(value) {
      return value ? erpDateTime(value, 19).slice(11, 16) : '—'
    },
    day(value) {
      return value ? erpDateTime(value, 19).slice(5, 10) : ''
    },
    hasMeta(item) {
      return !!(
        item.person ||
        (item.operator_name && item.operator_name !== item.person) ||
        (item.collaborators && item.collaborators.length) ||
        item.claimed_at ||
        item.started_at ||
        this.quantityText(item) ||
        item.scope_label ||
        item.description
      )
    },
    quantityText(item) {
      if (this.detail.units_mode === 'unit' || item.quantity == null || !(item.quantity > 0)) return ''
      if (item.unit_labels && item.unit_labels.length) return ''
      return `${this.number(item.quantity)} ${this.subject.unit_name || ''}`
    },
    groupOpen(key) {
      return this.expandedGroups[key] === true
    },
    toggleGroup(key) {
      this.$set(this.expandedGroups, key, !this.groupOpen(key))
    },
    openWorkOrder(id) {
      this.$router.push(`/production/work-orders/${id}`)
    },
    toggleWorkOrders() {
      this.showWorkOrders = !this.showWorkOrders
      if (this.showWorkOrders) this.$emit('show-work-orders')
    },
    async loadTree() {
      if (!this.orderId) return
      const sequence = ++this.treeSequence
      this.detailSequence++
      this.unitsSequence++
      this.nodes = []
      this.selectedNode = null
      this.detail = null
      this.detailLoading = false
      this.unitsLoading = false
      this.showWorkOrders = false
      this.videoVisible = false
      this.videoMedia = null
      this.treeLoading = true
      this.treeError = ''
      this.detailError = ''
      try {
        const { data } = await getSalesOrderProgressTree(this.orderId)
        if (sequence !== this.treeSequence) return
        this.nodes = data.data || []
        this.permissions = (data.meta && data.meta.permissions) || {}
        if (this.nodes[0]) this.selectNode(this.nodes[0])
      } catch (error) {
        if (sequence === this.treeSequence)
          this.treeError = error.userMessage || '订单产品读取失败'
      } finally {
        if (sequence === this.treeSequence) this.treeLoading = false
      }
    },
    async selectNode(node) {
      if (!node) return
      this.selectedNode = node
      this.detail = null
      this.detailError = ''
      this.selectedUnitKey = ''
      this.timelineFilter = 'all'
      this.expandedGroups = {}
      this.unitRows = []
      this.unitsKeyword = ''
      this.unitsPage = { total: 0, current_page: 1, last_page: 1 }
      this.detailSequence++
      this.unitsSequence++
      if (node.type === 'material') {
        this.detailLoading = false
        return
      }
      if (node.type === 'work_order') {
        await this.loadDetail('')
        return
      }
      const selectedKey = node.key
      this.detailLoading = true
      const units = await this.loadUnits(1)
      if (!this.selectedNode || selectedKey !== this.selectedNode.key) return
      if (units === null) {
        this.detailLoading = false
        return
      }
      const first =
        units.find(
          (unit) => !['cancelled', 'scrapped'].includes(unit.status)
        ) || units[0]
      this.selectedUnitKey = first ? first.key : 'quantity'
      await this.loadDetail(this.selectedUnitKey)
    },
    async loadUnits(page = 1) {
      const sequence = ++this.unitsSequence
      const node = this.selectedNode
      this.unitsLoading = true
      try {
        const { data } = await listSalesOrderProgressUnits(
          this.orderId,
          node.line_id || node.id,
          {
            page,
            per_page: 10,
            keyword: this.unitsKeyword,
            work_order_id:
              node.type === 'work_order'
                ? node.work_order_id || node.id
                : undefined
          }
        )
        if (sequence !== this.unitsSequence) return null
        this.unitRows = data.data || []
        this.unitsPage = {
          total: 0,
          current_page: 1,
          last_page: 1,
          ...data.meta
        }
        this.unitsPage.last_page = Math.max(1, this.unitsPage.last_page)
        return this.unitRows
      } catch (error) {
        if (sequence === this.unitsSequence) {
          const message = error.userMessage || '设备列表读取失败'
          if (this.detail) this.$message.error(message)
          else this.detailError = message
        }
        return null
      } finally {
        if (sequence === this.unitsSequence) this.unitsLoading = false
      }
    },
    searchUnits(keyword) {
      this.unitsKeyword = keyword
      this.loadUnits(1)
    },
    turnUnits(delta) {
      this.loadUnits(this.unitsPage.current_page + delta)
    },
    selectUnit(key) {
      this.selectedUnitKey = key
      this.loadDetail(key)
    },
    async loadDetail(key) {
      const sequence = ++this.detailSequence
      const node = this.selectedNode
      this.detailLoading = true
      this.detailError = ''
      try {
        const response =
          node.type === 'work_order'
            ? await getSalesOrderWorkOrderProgress(
                this.orderId,
                node.work_order_id || node.id,
                key ? { unit_id: key } : {}
              )
            : await getSalesOrderLineProgress(
                this.orderId,
                node.line_id || node.id,
                key
              )
        if (sequence !== this.detailSequence) return
        const data = response.data.data
        this.detail = {
          production_nodes: [],
          shipment_nodes: [],
          production_records: [],
          shipment_records: [],
          timeline: [],
          progress: null,
          unit_options: [],
          unit_total: 0,
          ...data
        }
        if (this.detail.permissions) this.permissions = this.detail.permissions
        this.selectedUnitKey = this.detail.selected_unit
          ? this.detail.selected_unit.key
          : node.type === 'work_order'
          ? ''
          : key
        if (
          node.type === 'work_order' &&
          this.detail.unit_total > 8 &&
          !this.unitRows.length
        )
          this.loadUnits(1)
      } catch (error) {
        if (sequence === this.detailSequence)
          this.detailError = error.userMessage || '进度记录读取失败'
      } finally {
        if (sequence === this.detailSequence) this.detailLoading = false
      }
    },
    openVideo(media) {
      this.videoMedia = media
      this.videoVisible = true
    }
  }
}
</script>

<style scoped>
.order-progress,
.order-progress * {
  box-sizing: border-box;
  min-width: 0;
}
.order-progress {
  color: #28384b;
  font-size: 12px;
}
.progress-view-toolbar {
  display: flex;
  justify-content: flex-end;
  margin: 0 0 10px;
}
.progress-layout {
  display: grid;
  grid-template-columns: minmax(0, 24%) minmax(0, 1fr);
  gap: 12px;
  align-items: stretch;
}
.progress-card {
  background: #fff;
  border: 1px solid #e5eaf0;
  border-radius: 6px;
}
.product-tree {
  padding: 0 0 20px;
}
.product-tree h3 {
  margin: 0;
  padding: 16px;
  border-bottom: 1px solid #edf0f4;
  font-size: 14px;
}
.product-tree-list {
  padding: 10px;
}
.product-node {
  display: flex;
  position: relative;
  width: 100%;
  gap: 8px;
  align-items: center;
  margin: 0 0 6px;
  padding: 8px 10px;
  background: #fff;
  color: inherit;
  border: 1px solid transparent;
  border-radius: 6px;
  text-align: left;
  font: inherit;
  cursor: pointer;
  transition: background 0.15s ease, border-color 0.15s ease;
}
.product-node.selected {
  background: #f0fdf4;
  border-color: #bbf7d0;
}
.product-node:hover:not(.selected) {
  background: #f8fafc;
  border-color: #e2e8f0;
}
.product-node--root:not(:first-child) {
  margin-top: 8px;
}
.node-image {
  width: 42px;
  height: 46px;
  flex: 0 0 42px;
  border-radius: 3px;
  background: #f0f3f7;
}
.node-type {
  flex: none;
  padding: 1px 6px;
  border-radius: 4px;
  color: #008b4b;
  background: #eaf7ef;
  font-size: 11px;
}
.node-copy {
  flex: 1;
  overflow-wrap: anywhere;
  line-height: 1.5;
}
.node-copy b {
  display: block;
  font-size: 13px;
}
.node-copy small {
  display: block;
  color: #7b8b9e;
  font-size: 11px;
}
.progress-tag {
  display: inline-block;
  padding: 2px 6px;
  border-radius: 3px;
  white-space: nowrap;
  color: #7e8999;
  background: #f1f3f6;
  font-size: 11px;
  line-height: 1.5;
  font-weight: normal;
}
.progress-tag.completed {
  background: #e5f8ed;
  color: #00a052;
}
.progress-tag.in_progress {
  background: #fff2e4;
  color: #ef7c00;
}
.progress-tag.rework {
  background: #f3edff;
  color: #8a59cf;
}
.progress-tag.failed {
  background: #fff0ed;
  color: #ed654f;
}
.progress-tag.cancelled {
  background: #f0f1f3;
  color: #9da6b3;
}
.progress-detail {
  min-height: 560px;
  padding: 16px 18px;
}
.subject-header {
  display: flex;
  gap: 14px;
  align-items: flex-start;
}
.subject-image {
  width: 90px;
  height: 62px;
  flex: 0 0 90px;
  border-radius: 3px;
  background: #f0f3f7;
}
.subject-image--empty {
  display: flex;
  flex-direction: column;
  gap: 5px;
  align-items: center;
  justify-content: center;
  color: #a0aabb;
  font-size: 10px;
}
.subject-image--empty i {
  font-size: 18px;
}
.subject-copy {
  flex: 1;
}
.subject-title {
  display: flex;
  flex-wrap: wrap;
  align-items: baseline;
  gap: 3px 9px;
  color: #7f8da1;
  line-height: 1.5;
}
.subject-title h2,
.subject-copy h2 {
  display: inline;
  margin: 0;
  font-size: 17px;
  color: #26364a;
}
.subject-copy p {
  margin: 6px 0;
  color: #7f8da1;
}
.subject-meta {
  display: flex;
  flex-direction: column;
  gap: 6px;
  text-align: right;
  color: #8290a3;
  font-size: 11px;
}
.subject-meta .el-button {
  padding: 0;
  color: #008b4b;
  font-size: 11px;
  text-align: inherit;
}
.unit-selector {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px;
  margin-top: 8px;
  color: #7d8c9e;
}
.unit-chip {
  border: 1px solid #dae1e9;
  background: white;
  padding: 4px 10px;
  color: #4b5b71;
  border-radius: 15px;
  font: inherit;
  font-size: 11px;
  cursor: pointer;
}
.unit-chip b,
.unit-chip small {
  display: block;
  font-size: inherit;
  font-weight: normal;
}
.unit-chip.active {
  border-color: #1aa75a;
  background: #edf9f2;
  color: #00a454;
}
.unit-chip.active b {
  font-weight: 600;
}
.remote-unit-select {
  width: 240px;
  max-width: 100%;
}
.quantity-mode {
  color: #4c6175;
}
.unit-option-pagination {
  display: flex;
  align-items: center;
  gap: 9px;
  color: #7d8c9e;
  font-size: 11px;
}
.unit-page-option {
  height: auto;
  border-top: 1px solid #edf0f4;
}
/* 工单备料与领料概览卡片 */
.materials-overview-bar {
  margin: 12px 0 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}
.materials-bar-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 9px 14px;
  cursor: pointer;
  user-select: none;
  background: #f1f5f9;
  border-bottom: 1px solid #e2e8f0;
  transition: background 0.15s ease;
}
.materials-bar-header:hover {
  background: #e2e8f0;
}
.materials-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #1e293b;
}
.materials-title i {
  color: #008b4b;
  font-size: 15px;
}
.materials-badge {
  display: inline-flex;
  align-items: center;
  padding: 1px 7px;
  border-radius: 10px;
  font-size: 11px;
  line-height: 16px;
  font-weight: 500;
}
.materials-badge.count-all {
  background: #e2e8f0;
  color: #475569;
}
.materials-badge.count-done {
  background: #dcfce7;
  color: #15803d;
}
.materials-badge.count-pending {
  background: #ffedd5;
  color: #c2410c;
}
.materials-toggle-btn {
  background: transparent;
  border: none;
  color: #0284c7;
  font-size: 12px;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}
.materials-table-wrapper {
  padding: 10px 12px;
  background: #ffffff;
}
.materials-compact-table {
  border-radius: 4px;
  overflow: hidden;
}
.materials-compact-table :deep(th) {
  background: #f8fafc !important;
  color: #475569;
  font-size: 11px;
}
.mat-name {
  color: #0f172a;
}

.tl-overview {
  display: flex;
  align-items: flex-start;
  margin-top: 12px;
  padding: 12px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow-x: auto;
}
.tl-grp {
  display: flex;
  align-items: flex-start;
  flex: none;
}
.tl-grp-label {
  align-self: stretch;
  margin-right: 10px;
  padding: 6px 3px;
  border-radius: 4px;
  color: #00763f;
  background: #eaf7ef;
  font-size: 11px;
  font-weight: 600;
  letter-spacing: 2px;
  text-align: center;
  writing-mode: vertical-rl;
}
.tl-grp-label.is-sub {
  color: #7c3aed;
  background: #f3e8ff;
}
.tl-grp-label.is-shipment {
  color: #334155;
  background: #e2e8f0;
}
.tl-sep {
  flex: none;
  width: 26px;
  height: 2px;
  margin-top: 11px;
  background: #10a65e;
}
.tl-sep.is-gray {
  background: #cbd5e1;
}
.tl-step {
  position: relative;
  flex: none;
  width: 92px;
  text-align: center;
  line-height: 1.5;
}
.tl-step:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 11px;
  left: calc(50% + 13px);
  width: calc(100% - 26px);
  height: 2px;
  background: #cbd5e1;
}
.tl-step.is-done:not(:last-child)::after {
  background: #10a65e;
}
.tl-dot {
  position: relative;
  z-index: 2;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 22px;
  height: 22px;
  margin: 0 auto 4px;
  border: 2px solid #cbd5e1;
  border-radius: 50%;
  background: #fff;
  color: #fff;
  font-size: 11px;
}
.tl-step.is-done .tl-dot {
  border-color: #10a65e;
  background: #10a65e;
}
.tl-step.is-doing .tl-dot {
  border-color: #f59e0b;
  background: #fef3c7;
  box-shadow: 0 0 0 3px rgba(245, 158, 11, 0.2);
}
.tl-step b {
  display: block;
  padding: 0 3px;
  color: #334155;
  font-size: 12px;
}
.tl-step small {
  display: block;
  padding: 0 3px;
  color: #64748b;
  font-size: 10px;
}
.tl-step.is-doing b {
  color: #d97706;
}
.tl-step.is-cancelled b,
.tl-step.is-cancelled small {
  color: #94a3b8;
}
.tl-badge {
  display: inline-block;
  margin-top: 2px;
  padding: 0 6px;
  border-radius: 8px;
  color: #c2410c;
  background: #ffedd5;
  font-size: 10px;
  font-style: normal;
}
.tl-pin {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 10px;
  margin-top: 12px;
  padding: 10px 14px;
  border: 1px solid #bbf7d0;
  border-radius: 8px;
  background: #f0fdf4;
}
.tl-pin.is-doing {
  border-color: #fde68a;
  background: #fffbeb;
}
.tl-pin.is-cancelled {
  border-color: #e2e8f0;
  background: #f8fafc;
}
.tl-pin b {
  font-size: 13px;
}
.tl-pin span {
  color: #64748b;
}
.tl-pin .tl-pin-detail {
  color: #b45309;
}
.tl-pin .tl-arrow {
  color: #94a3b8;
}
.tl-filter {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 8px;
  margin: 14px 0 8px;
}
.tl-filter h3 {
  margin: 0 12px 0 0;
  color: #475569;
  font-size: 13px;
  font-weight: 600;
}
.tl-chip {
  padding: 3px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #fff;
  color: #334155;
  font-size: 12px;
  cursor: pointer;
}
.tl-chip.active {
  border-color: #008b4b;
  background: #f0fdf4;
  color: #00763f;
}
.tl-hint {
  margin-left: auto;
  color: #94a3b8;
}
.tl {
  position: relative;
}
.tl-ev {
  display: grid;
  grid-template-columns: 76px 26px minmax(0, 1fr);
  gap: 0 8px;
  padding-bottom: 12px;
}
.tl-time {
  padding-top: 7px;
  color: #64748b;
  font-size: 11px;
  line-height: 1.35;
  text-align: right;
}
.tl-time b {
  display: block;
  color: #334155;
  font-size: 12px;
}
.tl-rail {
  position: relative;
}
.tl-rail::before {
  content: '';
  position: absolute;
  top: 0;
  bottom: -12px;
  left: 12px;
  width: 2px;
  background: #e2e8f0;
}
.tl-ev:last-child .tl-rail::before {
  display: none;
}
.tl-rail i {
  position: absolute;
  top: 7px;
  left: 3px;
  z-index: 2;
  width: 20px;
  height: 20px;
  border: 2px solid #cbd5e1;
  border-radius: 50%;
  background: #fff;
  color: #fff;
  font-size: 10px;
  font-style: normal;
  line-height: 16px;
  text-align: center;
}
.tl-ev.is-done .tl-rail i {
  border-color: #10a65e;
  background: #10a65e;
}
.tl-ev.is-failed .tl-rail i {
  border-color: #ef4444;
  background: #fee2e2;
  color: #dc2626;
}
.tl-ev.is-doing .tl-rail i {
  border-color: #f59e0b;
  background: #fef3c7;
  color: #d97706;
}
.tl-ev.is-cancelled {
  opacity: 0.58;
}
.tl-body {
  padding: 8px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #fff;
}
.tl-ev.is-failed .tl-body {
  border-color: #fecaca;
  background: #fffafa;
}
.tl-body.is-sub {
  border-color: #e9d5ff;
  background: #faf5ff;
}
.tl-head {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 6px 8px;
}
.tl-head.is-clickable {
  cursor: pointer;
}
.tl-head b {
  font-size: 13px;
}
.tl-stage {
  padding: 0 6px;
  border-radius: 4px;
  color: #00763f;
  background: #eaf7ef;
  font-size: 10px;
  font-weight: 600;
}
.tl-stage.is-sub {
  color: #7c3aed;
  background: #f3e8ff;
}
.tl-stage.is-shipment {
  color: #334155;
  background: #e2e8f0;
}
.tl-res {
  padding: 1px 8px;
  border-radius: 10px;
  color: #15803d;
  background: #dcfce7;
  font-size: 11px;
}
.tl-res.is-failed {
  color: #dc2626;
  background: #fee2e2;
}
.tl-res.is-in_progress,
.tl-res.is-pending {
  color: #b45309;
  background: #fef3c7;
}
.tl-res.is-cancelled,
.tl-res.is-round {
  color: #64748b;
  background: #f1f5f9;
}
.tl-sn {
  padding: 0 5px;
  border: 0;
  border-radius: 3px;
  color: #334155;
  background: #f1f5f9;
  font-family: Consolas, monospace;
  font-size: 11px;
}
.tl-link {
  color: #008b4b;
  cursor: pointer;
}
.tl-pkg {
  color: #304158;
  font-size: 12px;
}
.tl-caret {
  margin-left: auto;
  color: #7c3aed;
  font-size: 12px;
}
.tl-sub {
  margin-top: 4px;
  color: #64748b;
  font-size: 12px;
  line-height: 1.6;
}
.tl-sub span {
  margin-right: 12px;
}
.tl-callout {
  margin-top: 6px;
  padding: 6px 10px;
  border-radius: 4px;
  color: #b91c1c;
  background: #fef2f2;
  font-size: 12px;
  overflow-wrap: anywhere;
}
.tl-callout.is-back {
  color: #b45309;
  background: #fffbeb;
}
.tl-cut {
  margin-top: 3px;
  color: #77879d;
  font-size: 11px;
}
.tl-cut span {
  margin-left: 8px;
}
.tl-cut p {
  margin: 2px 0 0;
}
.tl-inner {
  margin-top: 8px;
  padding-top: 8px;
  border-top: 1px dashed #e9d5ff;
}
.tl-row {
  display: grid;
  grid-template-columns: 96px minmax(0, 1fr);
  gap: 6px;
  padding: 3px 0;
  color: #475569;
  font-size: 12px;
}
.tl-row > time {
  color: #94a3b8;
  text-align: right;
}
.tl-row.is-failed {
  color: #b91c1c;
}
.tl-row-line {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 4px 8px;
}
.tl-person {
  color: #64748b;
}
.record-media {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
  margin-top: 6px;
}
.record-thumb {
  width: 76px;
  height: 52px;
  border: 0;
  padding: 0;
  border-radius: 3px;
  background: #f0f3f7;
  overflow: hidden;
  flex: 0 0 76px;
}
.video-thumb {
  position: relative;
  cursor: pointer;
}
.video-thumb img,
.video-thumb video {
  width: 100%;
  height: 100%;
  object-fit: cover;
}
.video-thumb > i {
  position: absolute;
  left: 50%;
  top: 50%;
  transform: translate(-50%, -50%);
  font-size: 29px;
  color: white;
  text-shadow: 0 1px 3px #526274;
}
.video-thumb small {
  position: absolute;
  right: 2px;
  bottom: 1px;
  color: white;
  font-size: 9px;
  line-height: 1.2;
  background: rgba(0, 0, 0, 0.6);
  padding: 1px 2px;
}
.missing-media {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 120px;
  height: 52px;
  color: #99a5b5;
  border: 1px dashed #d9e0e9;
  border-radius: 3px;
  font-size: 10px;
}
.media-load-error {
  font-size: 10px;
  color: #97a4b6;
  display: flex;
  align-items: center;
  justify-content: center;
  height: 100%;
}
.progress-notice {
  background: #f0f8f4;
  border-radius: 3px;
  color: #3d805b;
  line-height: 1.6;
  margin: 12px 0 0;
  padding: 8px 10px;
}
.progress-empty {
  padding: 50px 16px;
  text-align: center;
  color: #929eaf;
  line-height: 1.8;
}
.material-quantities {
  display: flex;
  flex-wrap: wrap;
  gap: 30px;
  margin-top: 24px;
  padding: 20px;
  border-top: 1px solid #edf0f4;
  color: #7c8b9e;
}
.material-quantities b {
  color: #354b63;
  margin-left: 8px;
}
.video-preview {
  display: flex;
  align-items: center;
  justify-content: center;
}
.video-preview video {
  max-width: 100%;
  max-height: 68vh;
}
button:focus-visible {
  outline: 2px solid #008b4b;
  outline-offset: -2px;
}
@media (max-width: 1180px) {
  .progress-layout {
    grid-template-columns: minmax(0, 1fr);
  }
  .subject-header {
    flex-wrap: wrap;
  }
  .subject-meta {
    width: 100%;
    text-align: left;
    flex-direction: row;
    flex-wrap: wrap;
  }
}
@media (max-width: 780px) {
  .progress-detail {
    padding: 12px;
  }
  .tl-ev {
    grid-template-columns: 56px 26px minmax(0, 1fr);
  }
  .tl-row {
    grid-template-columns: 1fr;
  }
  .tl-row > time {
    text-align: left;
  }
  .subject-image {
    width: 64px;
    flex-basis: 64px;
  }
}
</style>
