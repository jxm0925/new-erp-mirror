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
        <div v-else role="tree" aria-label="订单产品">
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
            :style="{ paddingLeft: `${14 + entry.depth * 20}px` }"
            @click="selectNode(entry.node)"
          >
            <span
              v-if="entry.depth"
              class="tree-connector"
              :style="{ left: `${24 + (entry.depth - 1) * 20}px` }"
            />
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
              <div class="unit-selector">
                <span>{{ isWorkOrder ? '查看：' : '选择设备：' }}</span>
                <span
                  v-if="detail.units_mode === 'quantity'"
                  class="quantity-mode"
                  >按数量查看</span
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

          <div
            class="progress-sections"
            :class="{
              'progress-sections--single':
                isWorkOrder || !detail.shipment_nodes.length
            }"
          >
            <div v-if="detail.production_nodes.length" class="route-progress">
              <div class="route-label">
                生产 ·
                {{
                  isWorkOrder
                    ? subject.routing_name || '工单路线'
                    : '主工单路线'
                }}
              </div>
              <div class="route-steps">
                <div
                  v-for="node in detail.production_nodes"
                  :key="node.key"
                  class="route-step"
                  :class="node.status"
                >
                  <span class="route-dot"
                    ><i
                      v-if="node.status === 'completed'"
                      class="el-icon-check" /></span
                  ><b>{{ node.label }}</b
                  ><small>{{ stepText(node) }}</small>
                </div>
              </div>
            </div>
            <div
              v-if="detail.shipment_nodes.length"
              class="route-progress route-progress--shipment"
            >
              <div class="route-label">发货 · 发货工序</div>
              <div class="route-steps">
                <div
                  v-for="node in detail.shipment_nodes"
                  :key="node.key"
                  class="route-step"
                  :class="node.status"
                >
                  <span class="route-dot"
                    ><i
                      v-if="node.status === 'completed'"
                      class="el-icon-check" /></span
                  ><b>{{ node.label }}</b
                  ><small>{{ stepText(node) }}</small>
                </div>
              </div>
            </div>
          </div>

          <div class="record-sections">
            <section
              v-for="section in recordSections"
              :key="section.key"
              class="record-section"
            >
              <h3 v-if="!isWorkOrder">
                {{ section.label
                }}<span
                  v-if="section.key === 'production' && detail.selected_unit"
                  >（{{
                    detail.selected_unit.serial_no ||
                    detail.selected_unit.label
                  }}）</span
                >
              </h3>
              <div
                v-for="node in section.nodes"
                :key="node.key"
                class="record-node"
                :class="node.status"
              >
                <div class="record-node-label">
                  <span class="record-dot"
                    ><i
                      v-if="node.status === 'completed'"
                      class="el-icon-check" /></span
                  ><b>{{ node.label }}</b
                  ><small>{{ node.status_label || stepText(node) }}</small>
                </div>
                <div class="record-node-body">
                  <article
                    v-for="record in recordsFor(section.records, node.key)"
                    :key="record.key"
                    class="record-card"
                    :class="{ 'record-card--cancelled': record.is_cancelled }"
                  >
                    <div class="record-copy">
                      <div class="record-main">
                        <span class="progress-tag" :class="record.status"
                          >{{ record.status_label || statusText(record.status)
                          }}{{ recordQuantity(record) }}</span
                        ><b v-if="isWorkOrder && unitLabels(record)">{{
                          unitLabels(record)
                        }}</b
                        ><b v-if="record.package_no">{{ record.package_no }}</b
                        ><b v-if="record.person">{{ record.person }}</b
                        ><span
                          v-if="
                            record.operator_name &&
                            record.operator_name !== record.person
                          "
                          >报工人 {{ record.operator_name }}</span
                        ><span
                          v-if="
                            record.collaborators && record.collaborators.length
                          "
                          >协作 {{ people(record.collaborators) }}</span
                        >
                      </div>
                      <div
                        v-if="
                          record.claimed_at ||
                          record.started_at ||
                          record.completed_at ||
                          record.occurred_at
                        "
                        class="record-times"
                      >
                        <span v-if="record.claimed_at"
                          >接单 {{ date(record.claimed_at) }}</span
                        ><span v-if="record.started_at"
                          >开始 {{ date(record.started_at) }}</span
                        ><span v-if="record.completed_at"
                          >完成 {{ date(record.completed_at) }}</span
                        ><span v-else-if="record.occurred_at"
                          >{{ record.status === 'failed' ? '不合格' : '记录' }}
                          {{ date(record.occurred_at) }}</span
                        >
                      </div>
                      <p v-if="record.scope_label" class="record-scope">
                        {{ record.scope_label }}
                      </p>
                      <p v-if="record.reason">{{ record.reason }}</p>
                      <p
                        v-if="
                          record.description &&
                          record.description !== record.reason
                        "
                      >
                        {{ record.description }}
                      </p>
                      <div
                        v-for="run in record.cutting_runs || []"
                        :key="run.id || run.run_no"
                        class="cutting-record"
                      >
                        <b>{{ run.run_no || run.document_no }}</b
                        ><span v-if="run.scope_label">{{
                          run.scope_label
                        }}</span>
                        <p v-if="run.items && run.items.length">
                          切割件：{{
                            run.items
                              .map(
                                (item) =>
                                  `${item.name || item.item_name} × ${number(
                                    item.pieces != null
                                      ? item.pieces
                                      : item.quantity != null
                                      ? item.quantity
                                      : item.qty
                                  )}`
                              )
                              .join('、')
                          }}
                        </p>
                      </div>
                    </div>
                    <div class="record-media">
                      <template v-if="record.media && record.media.length"
                        ><template v-for="(media, index) in record.media"
                          ><el-image
                            v-if="media.type === 'image'"
                            :key="`image-${index}`"
                            class="record-thumb"
                            :src="media.url"
                            :preview-src-list="imageUrls(record.media)"
                            fit="cover"
                            ><div slot="error" class="media-load-error">
                              图片读取失败
                            </div></el-image
                          ><button
                            v-else-if="media.type === 'video'"
                            :key="`video-${index}`"
                            type="button"
                            class="record-thumb video-thumb"
                            aria-label="播放报工视频"
                            @click="openVideo(media)"
                          >
                            <img
                              v-if="media.poster_url"
                              :src="media.poster_url"
                              alt="视频封面"
                            /><video
                              v-else
                              :src="media.url"
                              preload="metadata"
                              muted
                            /><i class="el-icon-video-play" /><small>{{
                              duration(media.duration)
                            }}</small>
                          </button></template
                        ></template
                      ><span v-else class="missing-media">未上传完工影像</span>
                    </div>
                  </article>
                  <div
                    v-if="!recordsFor(section.records, node.key).length"
                    class="record-pending"
                  >
                    <span class="progress-tag" :class="node.status">{{
                      node.status_label || statusText(node.status)
                    }}</span>
                  </div>
                </div>
              </div>
            </section>
            <div v-if="!recordSections.length" class="progress-empty">
              暂无进度记录
            </div>
            <p v-if="detail.notice" class="progress-notice">
              {{ detail.notice }}
            </p>
          </div>
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
    videoVisible: false,
    videoMedia: null
  }),
  computed: {
    flatNodes() {
      const rows = []
      const visit = (nodes, depth) =>
        nodes.forEach((node) => {
          rows.push({ node, depth })
          visit(node.children || [], depth + 1)
        })
      visit(this.nodes, 0)
      return rows
    },
    subject() {
      return this.detail.subject || {}
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
    recordSections() {
      return [
        {
          key: 'production',
          label: '生产阶段',
          nodes: this.detail.production_nodes,
          records: this.detail.production_records
        },
        {
          key: 'shipment',
          label: '发货阶段',
          nodes: this.detail.shipment_nodes,
          records: this.detail.shipment_records
        }
      ].filter((section) => section.nodes.length)
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
    unitLabels(record) {
      return record.unit_labels
        ? record.unit_labels.join('、')
        : (record.units || [])
            .map((unit) => unit.serial_no || unit.label)
            .filter(Boolean)
            .join('、')
    },
    recordQuantity(record) {
      return (this.isWorkOrder &&
        !this.selectedUnitKey &&
        record.quantity > 1) ||
        (this.detail.units_mode === 'quantity' && record.quantity > 0)
        ? ` ${this.number(record.quantity)} ${this.subject.unit_name || ''}`
        : ''
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
    recordsFor(records, key) {
      const current = (record) => /(^current:|:current$)/.test(record.key)
      const time = (record) =>
        Date.parse(
          (
            record.occurred_at ||
            record.completed_at ||
            record.started_at ||
            record.claimed_at ||
            ''
          ).replace(' ', 'T')
        ) || 0
      return records
        .filter((record) => record.node_key === key)
        .sort(
          (a, b) => Number(current(b)) - Number(current(a)) || time(b) - time(a)
        )
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
.product-node {
  display: flex;
  position: relative;
  width: 100%;
  gap: 10px;
  align-items: center;
  padding: 12px 12px 12px 14px;
  background: white;
  color: inherit;
  border: 0;
  border-left: 3px solid transparent;
  text-align: left;
  font: inherit;
  cursor: pointer;
}
.product-node.selected {
  background: #edf9f2;
  border-left-color: #13a257;
}
.product-node:hover {
  background: #f4faf6;
}
.product-node--root:not(:first-child) {
  border-top: 1px solid #edf0f4;
  margin-top: 4px;
}
.node-image,
.node-type {
  width: 42px;
  height: 46px;
  flex: 0 0 42px;
  border-radius: 3px;
  background: #f0f3f7;
}
.node-type {
  display: flex;
  align-items: center;
  justify-content: center;
  color: #9aa5b5;
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
.tree-connector {
  width: 11px;
  height: 23px;
  position: absolute;
  top: 0;
  border-bottom: 1px solid #d9e0e9;
  border-left: 1px solid #d9e0e9;
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
.progress-sections {
  display: flex;
  gap: 0;
  margin: 14px 0 0;
  padding: 0 0 16px;
  border-bottom: 1px solid #edf0f4;
}
.route-progress {
  flex: 1;
  padding: 0 10px;
}
.route-progress--shipment {
  border-left: 1px dashed #d6dee8;
}
.route-label {
  width: fit-content;
  border-radius: 10px;
  padding: 2px 8px;
  margin: 0 0 5px;
  color: #008b4b;
  background: #edf9f2;
  font-size: 11px;
}
.route-steps {
  display: flex;
  align-items: stretch;
  gap: 0;
}
.route-step {
  flex: 1;
  position: relative;
  text-align: center;
  padding-top: 0;
  line-height: 1.6;
}
.route-step:not(:last-child)::after {
  content: '';
  position: absolute;
  top: 11px;
  left: calc(50% + 12px);
  width: calc(100% - 24px);
  height: 2px;
  background: #cbd3de;
}
.route-step.completed:not(:last-child)::after {
  background: #12a459;
}
.route-dot {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  margin: 0 auto 5px;
  border: 4px solid #cbd2dc;
  border-radius: 50%;
  background: #fff;
  color: white;
}
.route-step.completed .route-dot {
  background: #12a459;
  border-color: #12a459;
}
.route-step.in_progress .route-dot,
.route-step.rework .route-dot {
  border-color: #f18700;
}
.route-step b {
  display: block;
  padding: 0 3px;
  font-size: 12px;
}
.route-step small {
  display: block;
  color: #8290a3;
  font-size: 10px;
  padding: 0 3px;
}
.route-step.in_progress b,
.route-step.in_progress small,
.route-step.rework b {
  color: #f18700;
}
.record-sections {
  padding-top: 8px;
}
.record-section h3 {
  display: flex;
  align-items: center;
  margin: 10px 0;
  color: #8190a4;
  font-size: 11px;
  font-weight: normal;
}
.record-section h3::after {
  content: '';
  height: 1px;
  background: #edf0f4;
  flex: 1;
  margin-left: 8px;
}
.record-node {
  display: grid;
  grid-template-columns: 90px minmax(0, 1fr);
  gap: 8px;
  margin-bottom: 10px;
}
.record-node-label {
  position: relative;
  padding-left: 28px;
  padding-top: 2px;
}
.record-node-label b {
  display: block;
  font-size: 13px;
  line-height: 1.5;
}
.record-node-label small {
  display: block;
  margin-top: 2px;
  color: #7d8c9f;
  font-size: 11px;
}
.record-node.completed .record-node-label small {
  color: #08a35a;
}
.record-node.in_progress .record-node-label small,
.record-node.rework .record-node-label small {
  color: #ef8500;
}
.record-dot {
  position: absolute;
  left: 0;
  top: 1px;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border: 4px solid #cbd2dc;
  border-radius: 50%;
  color: white;
}
.record-node.completed .record-dot {
  border-color: #12a459;
  background: #12a459;
  border-width: 3px;
}
.record-node.in_progress .record-dot,
.record-node.rework .record-dot {
  border-color: #f18700;
}
.record-node-body {
  border: 1px solid #e7ecf3;
  border-radius: 5px;
  overflow: hidden;
}
.record-card {
  display: flex;
  gap: 12px;
  padding: 9px 12px;
  min-height: 60px;
  align-items: center;
}
.record-card + .record-card {
  border-top: 1px solid #edf0f4;
}
.record-card--cancelled {
  opacity: 0.58;
}
.record-copy {
  flex: 1;
  line-height: 1.6;
}
.record-main {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 3px 9px;
  color: #7e8da0;
}
.record-main b {
  color: #304158;
  font-size: 12px;
}
.record-copy p {
  margin: 2px 0 0;
  color: #6c7889;
  font-size: 11px;
  overflow-wrap: anywhere;
}
.record-times {
  display: flex;
  flex-wrap: wrap;
  gap: 3px 10px;
  margin-top: 3px;
  color: #8090a6;
  font-size: 11px;
}
.record-copy .record-scope {
  color: #8b98aa;
}
.cutting-record {
  color: #77879d;
  font-size: 11px;
  margin-top: 3px;
}
.cutting-record span {
  margin-left: 8px;
}
.record-media {
  display: flex;
  flex-wrap: wrap;
  justify-content: flex-end;
  gap: 8px;
  max-width: 40%;
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
.record-pending {
  padding: 11px 12px;
  min-height: 38px;
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
  .progress-sections {
    flex-direction: column;
    gap: 16px;
  }
  .route-progress--shipment {
    border-left: 0;
  }
  .record-node {
    grid-template-columns: 72px minmax(0, 1fr);
  }
  .record-card {
    flex-wrap: wrap;
  }
  .record-media {
    max-width: 100%;
    justify-content: flex-start;
  }
  .subject-image {
    width: 64px;
    flex-basis: 64px;
  }
}
</style>
