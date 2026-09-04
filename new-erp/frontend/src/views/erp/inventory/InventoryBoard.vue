<template>
  <div class="inventory-page">
    <section v-if="activeView === 'posting'" class="inventory-view">
      <div class="page-head">
        <div>
          <h1>库存过账工作台</h1>
          <p>承接采购到货的待库存过账，只将合格数量写入正常库存。</p>
        </div>
        <el-button size="small" type="success" icon="el-icon-refresh" @click="resetPostingRows">刷新待过账</el-button>
      </div>

      <div class="metric-grid four">
        <article><i class="el-icon-document-checked green" /><span>待过账到货单</span><strong>{{ pendingPostingCount }}</strong></article>
        <article><i class="el-icon-circle-check blue" /><span>今日已过账</span><strong>{{ postedTodayCount }}</strong></article>
        <article><i class="el-icon-warning-outline orange" /><span>不合格待处理行</span><strong>{{ totalDefectiveQty }}</strong></article>
        <article><i class="el-icon-circle-close red" /><span>过账异常</span><strong>{{ failedPostingCount }}</strong></article>
      </div>

      <section class="filter-panel posting-filter">
        <label>到货日期：<el-date-picker v-model="postingQuery.dateRange" size="small" type="daterange" start-placeholder="开始日期" end-placeholder="结束日期" /></label>
        <label>供应商：<el-select v-model="postingQuery.supplier" size="small" clearable placeholder="请选择"><el-option label="苏州华成电子有限公司" value="苏州华成电子有限公司" /><el-option label="东莞市怡达塑胶有限公司" value="东莞市怡达塑胶有限公司" /></el-select></label>
        <label>仓库：<el-select v-model="postingQuery.warehouse" size="small" clearable placeholder="请选择"><el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" /></el-select></label>
        <label>单据号：<el-input v-model="postingQuery.keyword" size="small" placeholder="请输入到货单号" /></label>
        <el-button size="small" type="success" @click="searchPostingRows">查询</el-button>
        <el-button size="small" @click="resetPostingQuery">重置</el-button>
      </section>

      <section class="posting-layout">
        <div class="table-card">
          <el-table :data="filteredPostingRows" size="mini" border highlight-current-row @current-change="selectReceipt">
            <el-table-column width="42">
              <template slot-scope="{ row }"><span class="row-radio" :class="{ active: selectedReceipt && selectedReceipt.id === row.id }" /></template>
            </el-table-column>
            <el-table-column prop="receipt_no" label="到货单号" width="122" />
            <el-table-column prop="order_no" label="来源采购订单" width="118" />
            <el-table-column prop="supplier_name" label="供应商" width="132" />
            <el-table-column prop="receipt_date" label="到货日期" width="96" />
            <el-table-column prop="qualified_display" label="合格数量" width="76" align="right" />
            <el-table-column prop="defective_display" label="不合格数量" width="82" align="right" />
            <el-table-column prop="pending_display" label="待处理数量" width="82" align="right" />
            <el-table-column label="过账检查" min-width="190">
              <template slot-scope="{ row }">
                <div v-if="postingBlockedReason(row)" class="posting-check posting-check-blocked">
                  <el-tag size="mini" type="danger">不可过账</el-tag>
                  <span>{{ postingBlockedReason(row) }}</span>
                </div>
                <div v-else class="posting-check posting-check-passed">
                  <el-tag size="mini" type="success">检查通过</el-tag>
                  <span>仓库、库位、批次及编号资料完整</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="190" fixed="right">
              <template slot-scope="{ row }">
                <div class="row-actions row-actions-wide">
                  <el-button size="mini" plain @click.stop="selectReceipt(row)">查看来源</el-button>
                  <el-button v-if="postingBlockedReason(row) && $can('inventory.post.repair')" size="mini" type="warning" plain @click.stop="openPostingRepair(row)">补充分配</el-button>
                  <el-button v-else-if="!postingBlockedReason(row) && $can('inventory.post.execute')" size="mini" type="success" @click.stop="openPostingConfirm(row)">确认入库过账</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
          <div class="table-footer">
            <span />
            <el-pagination small layout="total, sizes, prev, pager, next" :page-sizes="[10,20,50,100]" :current-page.sync="postingPagination.page" :page-size.sync="postingPagination.per_page" :total="postingPagination.total" @current-change="loadPostingRows" @size-change="handlePostingSizeChange" />
          </div>
        </div>

        <aside class="side-card">
          <h3>到货单详情</h3>
          <dl v-if="selectedReceipt" class="detail-dl">
            <dt>到货单号</dt><dd>{{ selectedReceipt.receipt_no }}</dd>
            <template v-if="selectedReceipt.has_purchase_order">
              <dt>采购订单号</dt><dd>{{ selectedReceipt.order_no }}</dd>
            </template>
            <template v-else>
              <dt>来源类型</dt><dd>手工到货</dd>
              <dt>来源单号</dt><dd>--</dd>
            </template>
            <dt>供应商</dt><dd>{{ selectedReceipt.supplier_name }}</dd>
            <dt>到货日期</dt><dd>{{ selectedReceipt.receipt_date }}</dd>
            <dt>单据状态</dt><dd><el-tag size="mini" :type="postingStatusType(selectedReceipt.posting_status)">{{ postingStatusText(selectedReceipt.posting_status) }}</el-tag></dd>
          </dl>
          <h4>到货明细</h4>
          <el-alert v-if="selectedReceipt && postingBlockedReason(selectedReceipt)" class="posting-blocked-alert" :title="postingBlockedReason(selectedReceipt)" type="error" :closable="false" show-icon>
            <template slot="default"><el-button v-if="$can('inventory.post.repair')" size="mini" type="warning" plain @click="openPostingRepair(selectedReceipt)">补充仓库/库位分配</el-button></template>
          </el-alert>
          <el-table v-if="selectedReceipt" :data="selectedReceipt.items" size="mini" border>
            <el-table-column label="Item编码/名称" min-width="140">
              <template slot-scope="{ row }"><strong>{{ row.item_code }}</strong><br><span>{{ row.item_name }}</span></template>
            </el-table-column>
            <el-table-column label="入库分配" min-width="126"><template slot-scope="{row}">{{ postingAllocationSummary(row) }}</template></el-table-column>
            <el-table-column prop="batch_no" label="批次号" width="112" />
            <el-table-column prop="qualified_display" label="合格入库数量" width="104" align="right" />
            <el-table-column label="不入库数量说明" min-width="120">
              <template slot-scope="{ row }">不合格 {{ row.defective_display }} / 待处理 {{ row.pending_display }}</template>
            </el-table-column>
          </el-table>
          <el-alert class="rule-alert" title="不合格品与待处理数量不进入正常库存。" type="warning" :closable="false" show-icon />
          <el-alert class="rule-alert" title="已过账单据不可重复过账。" type="info" :closable="false" show-icon />
          <div class="operation-note">
            <b>操作说明</b>
            <p>1. 仓库、库位及设备编号在到货入库环节确定；库存过账只核对并引用。</p>
            <p>2. 合格数量写入正常库存，不合格和待处理数量留在采购侧处理。</p>
            <p>3. 过账成功后生成库存流水，并更新库存余额。</p>
          </div>
        </aside>
      </section>
    </section>

    <section v-if="activeView === 'balances'" class="inventory-view">
      <div class="page-head">
        <div>
          <h1>库存余额 <small><i class="el-icon-info" /> 库存对象为 Item，不按 Product/SKU 记账。</small></h1>
        </div>
      </div>

      <div class="metric-grid four">
        <article><i class="el-icon-box green" /><span>物料总数 <i class="el-icon-info" /></span><strong>{{ quantityNumber(balanceStats.item_count) }} <small class="unit-text">个</small></strong></article>
        <article><i class="el-icon-coin green" /><span>有库存物料 <i class="el-icon-info" /></span><strong>{{ quantityNumber(balanceStats.stock_item_count) }} <small class="unit-text">个</small></strong></article>
        <article><i class="el-icon-location-outline green" /><span>批次余额 <i class="el-icon-info" /></span><strong>{{ quantityNumber(balanceStats.batch_count) }} <small class="unit-text">个批次</small></strong></article>
        <article><i class="el-icon-warning orange" /><span>不合格/待处理物料 <i class="el-icon-info" /></span><strong>{{ quantityNumber(balanceStats.quality_item_count) }} <small class="unit-text">个</small></strong></article>
      </div>

      <section class="filter-panel balance-filter">
        <label>Item编码/名称<el-input v-model="balanceQuery.keyword" size="small" placeholder="支持编码、名称模糊查询" /></label>
        <label>仓库<el-select v-model="balanceQuery.warehouse" size="small" clearable placeholder="全部"><el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" /></el-select></label>
        <label>库位<el-select v-model="balanceQuery.location" size="small" clearable placeholder="全部"><el-option v-for="l in locations" :key="l.id" :label="l.location_name" :value="l.id" /></el-select></label>
        <label>批次号<el-input v-model="balanceQuery.batch" size="small" placeholder="请输入批次号" /></label>
        <label>库存状态<el-select v-model="balanceQuery.status" size="small" clearable placeholder="全部状态"><el-option label="有库存" value="has_stock" /><el-option label="有异常数量" value="has_quality" /></el-select></label>
        <el-button size="small" type="success" class="btn-green-search" @click="searchBalances">查询</el-button>
        <el-button size="small" @click="resetBalanceQuery">重置</el-button>
        <el-button size="small" type="text" class="btn-expand-text">展开 <i class="el-icon-arrow-down" /></el-button>
      </section>

      <div class="balance-instruction-banner">
        <i class="el-icon-info info-icon-blue" />
        <span>说明：库存余额按 Item 汇总展示；不合格和待处理数量不计入可用库存。点击 Item 后可查看批次、库位、编号与质量处理。</span>
      </div>

      <!-- Actions Toolbar above Table -->
      <div class="balance-table-toolbar">
        <div class="toolbar-left">
          <span class="total-badge">共 {{ balancePagination.total }} 条</span>
          <el-button size="small" :disabled="!selectedBalance" @click="openBatchDialog(selectedBalance)"><i class="el-icon-box" /> 查看批次</el-button>
          <el-button size="small" :disabled="!selectedBalance" @click="viewItemTransactions(selectedBalance)"><i class="el-icon-view" /> 查看流水</el-button>
          <el-button size="small" :disabled="!selectedBalance" @click="openAdjustmentForBalance(selectedBalance)">调整库存</el-button>
          <el-button size="small" class="btn-green-plain" :disabled="!selectedBalance" @click="openAlertConfig">配置库存预警</el-button>
        </div>
        <div class="toolbar-right">
          <el-button size="small" icon="el-icon-download" @click="exportBalances">导出</el-button>
        </div>
      </div>

      <!-- Main Balance Layout (Table + Side Detail Drawer) -->
      <div class="balance-main-layout" :class="{ 'with-drawer': balanceDetailVisible && selectedBalance }">
        <div class="table-card balance-item-table">
          <el-table
            ref="balanceTable"
            :data="filteredBalanceRows"
            size="mini"
            border
            highlight-current-row
            :row-class-name="balanceRowClass"
            @row-click="handleBalanceRowClick"
            @selection-change="handleBalanceSelectionChange"
          >
            <el-table-column type="selection" width="45" align="center" />
            <el-table-column prop="item_code" label="Item编码" min-width="120" />
            <el-table-column prop="item_name" label="Item名称" min-width="150" />
            <el-table-column prop="unit" label="单位" width="60" align="center" />
            <el-table-column prop="batch_count" label="批次数" width="72" align="right" />
            <el-table-column prop="balance_count" label="库位余额数" width="96" align="right" />
            <el-table-column prop="quantity_on_hand" label="账面库存" width="85" align="right" />
            <el-table-column label="可用库存" width="85" align="right">
              <template slot-scope="{ row }">
                <span class="text-green font-bold">{{ row.quantity_available }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="quantity_locked" label="锁定/预留" width="75" align="right" />
            <el-table-column prop="quantity_defective" label="不合格" width="65" align="right" />
            <el-table-column prop="quantity_pending" label="待处理" width="65" align="right" />
            <el-table-column label="最近变动时间" min-width="135">
              <template slot-scope="{ row }">{{ dateTime(row.last_transaction_at) }}</template>
            </el-table-column>
            <el-table-column label="操作" width="180" fixed="right">
              <template slot-scope="{ row }">
                <div class="row-actions">
                  <el-button size="mini" type="text" class="link-btn-green" @click.stop="openBatchDialog(row)">查看批次</el-button>
                  <el-button size="mini" type="text" class="link-btn-green" @click.stop="viewItemTransactions(row)">查看流水</el-button>
                  <el-button size="mini" type="text" class="link-btn-green" @click.stop="openAdjustmentForBalance(row)">调整库存</el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
          <div class="table-footer">
            <span class="footer-count">共 {{ balancePagination.total }} 条</span>
            <el-pagination
              small
              layout="sizes, prev, pager, next, jumper"
              :page-sizes="[10, 20, 50, 100]"
              :current-page.sync="balancePagination.page"
              :page-size.sync="balancePagination.per_page"
              :total="balancePagination.total"
              @current-change="loadBalances"
              @size-change="handleBalanceSizeChange"
            />
          </div>
        </div>

        <!-- Right Side Detail Drawer (物料详情) -->
        <aside v-if="balanceDetailVisible && selectedBalance" class="side-card balance-detail-side">
          <div class="side-head">
            <h3>物料详情</h3>
            <button type="button" class="btn-close-side" @click="closeBalanceDetail"><i class="el-icon-close" /></button>
          </div>

          <div class="side-scroll-body">
            <!-- 1. Item基础信息 -->
            <div class="detail-sec-title"><span>Item基础信息</span></div>
            <div class="sec-kv-grid">
              <div class="kv-item"><span class="kv-lbl">Item编码</span><strong class="kv-val">{{ selectedBalance.item_code }}</strong></div>
              <div class="kv-item"><span class="kv-lbl">Item名称</span><strong class="kv-val">{{ selectedBalance.item_name }}</strong></div>
              <div class="kv-item"><span class="kv-lbl">单位</span><strong class="kv-val">{{ selectedBalance.unit || '-' }}</strong></div>
              <div class="kv-item"><span class="kv-lbl">物料类别</span><strong class="kv-val">{{ selectedBalance.category || '-' }}</strong></div>
              <div class="kv-item"><span class="kv-lbl">批次数</span><strong class="kv-val">{{ quantityNumber(selectedBalance.batch_count) }}</strong></div>
              <div class="kv-item"><span class="kv-lbl">库位余额数</span><strong class="kv-val">{{ quantityNumber(selectedBalance.balance_count) }}</strong></div>
              <div class="kv-item"><span class="kv-lbl">安全库存</span><strong class="kv-val">{{ selectedBalance.safety_stock === null || selectedBalance.safety_stock === undefined ? '-' : quantityNumber(selectedBalance.safety_stock) }}</strong></div>
              <div class="kv-item span-two"><span class="kv-lbl">最近变动</span><strong class="kv-val">{{ dateTime(selectedBalance.last_transaction_at) }}</strong></div>
            </div>

            <!-- 2. Product/SKU 参考 -->
            <div class="detail-sec-title"><span>Product/SKU 参考（非记账维度）</span></div>
            <div class="sec-kv-grid">
              <div class="kv-item span-two"><span class="kv-lbl">关联产品</span><strong class="kv-val">{{ selectedBalance.product_code || '-' }}</strong></div>
              <div class="kv-item span-two"><span class="kv-lbl">SKU</span><strong class="kv-val">{{ selectedBalance.sku_code || '-' }}</strong></div>
            </div>
            <div class="sku-note-box">说明：库存按 Item 记账，Product/SKU 仅作参考。</div>

            <!-- 3. 库存分布（按仓库/库位/批次） -->
            <div class="detail-sec-title"><span>库存分布（按仓库/库位/批次）</span></div>
            <div class="dist-table-wrap">
              <table class="dist-table">
                <thead>
                  <tr>
                    <th>仓库</th>
                    <th>库位</th>
                    <th>批次号</th>
                    <th>账面库存</th>
                    <th>可用库存</th>
                    <th>锁定库存</th>
                    <th>不合格</th>
                    <th>待处理</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(dist, idx) in balanceDetailDistributions" :key="idx">
                    <td>{{ dist.warehouse_name || '-' }}</td>
                    <td>{{ dist.location_name || '-' }}</td>
                    <td>{{ dist.batch_no || '-' }}</td>
                    <td>{{ quantityNumber(dist.quantity_on_hand) }}</td>
                    <td class="text-green font-bold">{{ quantityNumber(dist.quantity_available) }}</td>
                    <td>{{ quantityNumber(dist.quantity_locked) }}</td>
                    <td>{{ quantityNumber(dist.quantity_defective) }}</td>
                    <td>{{ quantityNumber(dist.quantity_pending) }}</td>
                  </tr>
                  <tr class="sum-row">
                    <td>合计</td>
                    <td>-</td>
                    <td>-</td>
                    <td>{{ quantityNumber(balanceDistTotals.quantity_on_hand) }}</td>
                    <td class="text-green font-bold">{{ quantityNumber(balanceDistTotals.quantity_available) }}</td>
                    <td>{{ quantityNumber(balanceDistTotals.quantity_locked) }}</td>
                    <td>{{ quantityNumber(balanceDistTotals.quantity_defective) }}</td>
                    <td>{{ quantityNumber(balanceDistTotals.quantity_pending) }}</td>
                  </tr>
                </tbody>
              </table>
            </div>

            <!-- 4. 最新流水 -->
            <div class="detail-sec-title"><span>最新流水（近 10 条）</span></div>
            <div v-if="balanceRecentTransactions.length" class="tx-timeline-list">
              <div v-for="tx in balanceRecentTransactions" :key="tx.id" class="tx-timeline-item">
                <div class="tx-type-icon" :class="tx.change_qty >= 0 ? 'bg-green' : 'bg-blue'">
                  {{ tx.change_qty >= 0 ? '+' : '-' }}
                </div>
                <div class="tx-info-block">
                  <div class="tx-row-1">
                    <span class="tx-name">{{ tx.transaction_type_text || (tx.change_qty >= 0 ? '采购入库' : '库存锁定') }}</span>
                    <strong class="tx-qty" :class="tx.change_qty >= 0 ? 'text-green' : 'text-blue'">
                      {{ tx.change_qty >= 0 ? '+' : '' }}{{ quantityNumber(tx.change_qty) }} {{ tx.unit || '-' }}
                    </strong>
                    <span class="tx-time">{{ dateTime(tx.posted_at) }}</span>
                  </div>
                  <div class="tx-row-2">
                    <span class="tx-batch">批次: {{ tx.batch_no || '-' }}</span>
                    <span class="tx-wh">仓库: {{ tx.warehouse_name || '-' }}/{{ tx.location_name || '-' }}</span>
                    <span class="tx-source">来源: {{ tx.source_no || '-' }}</span>
                  </div>
                </div>
              </div>
            </div>
            <el-empty v-else description="暂无库存流水" :image-size="52" />
            <div class="tx-more-link">
              <a @click="viewItemTransactions(selectedBalance)">查看全部流水</a>
            </div>
          </div>
        </aside>
      </div>
    </section>

    <!-- Redesigned Alert Config Dialog -->
    <el-dialog
      title="配置库存预警"
      :visible.sync="alertConfigVisible"
      width="680px"
      top="10vh"
      custom-class="balance-alert-config-dialog-v2"
      :close-on-click-modal="false"
      @closed="resetAlertConfig"
    >
      <div v-if="alertConfigItem" class="alert-dialog-body" v-loading="alertConfigLoading">
        <div class="alert-info-banner">
          <i class="el-icon-info alert-blue-icon" />
          <span>基于可用库存进行预警，不影响现有库存数据。</span>
        </div>

        <!-- 1. 选中物料与仓库范围 -->
        <div class="alert-card-sec">
          <h4 class="alert-sec-title">选中物料与仓库范围</h4>
          <div class="alert-scope-grid">
            <div class="scope-row">
              <span class="scope-item"><label>物料</label> <strong>{{ alertConfigItem.item_code }} {{ alertConfigItem.item_name }}</strong></span>
              <span class="scope-item"><label>单位</label> <strong>{{ alertConfigItem.unit || '-' }}</strong></span>
            </div>
            <div class="scope-row">
              <span class="scope-item"><label>仓库范围</label> <strong>公司默认（覆盖 {{ quantityNumber(alertConfigItem.balance_count) }} 个库存余额）</strong></span>
              <span class="scope-item"><label>批次范围</label> <strong>{{ quantityNumber(alertConfigItem.batch_count) }} 个批次</strong></span>
            </div>
          </div>
        </div>

        <!-- 2. 当前库存情况 -->
        <div class="alert-card-sec">
          <h4 class="alert-sec-title">当前库存情况 <small class="sec-time-text">（截至 {{ alertStockTime }}）</small></h4>
          <div class="alert-stock-grid">
            <div class="stock-cell">
              <span class="stock-cell-label">账面库存</span>
              <strong class="stock-cell-val">{{ quantityNumber(alertConfigItem.quantity_on_hand) }} <small>{{ alertConfigItem.unit || '-' }}</small></strong>
            </div>
            <div class="stock-cell">
              <span class="stock-cell-label">锁定/预留库存</span>
              <strong class="stock-cell-val">{{ quantityNumber(alertConfigItem.quantity_locked) }} <small>{{ alertConfigItem.unit || '-' }}</small></strong>
            </div>
            <div class="stock-cell">
              <span class="stock-cell-label">不合格/待处理</span>
              <strong class="stock-cell-val">{{ quantityNumber(alertConfigItem.quantity_defective + alertConfigItem.quantity_pending) }} <small>{{ alertConfigItem.unit || '-' }}</small></strong>
            </div>
            <div class="stock-cell">
              <span class="stock-cell-label">可用库存</span>
              <strong class="stock-cell-val text-green">{{ quantityNumber(alertConfigItem.quantity_available) }} <small>{{ alertConfigItem.unit || '-' }}</small></strong>
            </div>
          </div>
        </div>

        <!-- 3. 预警配置 -->
        <div class="alert-card-sec">
          <h4 class="alert-sec-title">预警配置 <small class="sec-help-text">（按最低库存、最高库存规则判断）</small></h4>
          <div class="alert-inputs-row">
            <div class="input-col">
              <label class="required-lbl">最低库存</label>
              <div class="input-with-unit">
                <el-input-number v-model="alertConfig.min_stock" :min="0" controls-position="right" size="small" />
                <span class="unit-tag">{{ alertConfigItem.unit || '-' }}</span>
              </div>
              <div v-if="alertConfig.min_stock !== null && alertConfig.min_stock <= 0" class="err-tip">最低库存必须大于 0</div>
            </div>

            <div class="input-col">
              <label class="required-lbl">安全库存</label>
              <div class="input-with-unit">
                <el-input-number v-model="alertConfig.safety_stock" :min="0" controls-position="right" size="small" />
                <span class="unit-tag">{{ alertConfigItem.unit || '-' }}</span>
              </div>
            </div>

            <div class="input-col">
              <label class="required-lbl">最高库存</label>
              <div class="input-with-unit">
                <el-input-number v-model="alertConfig.max_stock" :min="0" controls-position="right" size="small" />
                <span class="unit-tag">{{ alertConfigItem.unit || '-' }}</span>
              </div>
            </div>

            <div class="input-col">
              <label class="required-lbl">建议补货量</label>
              <div class="input-with-unit">
                <el-input-number v-model="alertConfig.suggested_replenishment_qty" :min="0" controls-position="right" size="small" />
                <span class="unit-tag">{{ alertConfigItem.unit || '-' }}</span>
              </div>
            </div>

            <div class="input-col switch-col">
              <label>预警启用状态</label>
              <div class="switch-wrap">
                <el-switch v-model="alertConfig.enabled" active-color="#008b4b" inactive-color="#dcdfe6" />
              </div>
            </div>
          </div>
        </div>

        <!-- 4. 预警状态说明 -->
        <div class="alert-card-sec no-border-bottom">
          <h4 class="alert-sec-title">预警状态说明 <small class="sec-help-text">（基于可用库存）</small></h4>
          <div class="alert-rules-bullets">
            <div class="rule-bullet rule-normal"><span class="dot green-dot-v2" /> <strong>正常：</strong>可用库存 ≥ 安全库存</div>
            <div class="rule-bullet rule-out"><span class="dot orange-dot-v2" /> <strong>缺货：</strong>可用库存 &lt; 最低库存</div>
            <div class="rule-bullet rule-low"><span class="dot yellow-dot-v2" /> <strong>库存偏低：</strong>最低库存 ≤ 可用库存 &lt; 安全库存</div>
            <div class="rule-bullet rule-over"><span class="dot blue-dot-v2" /> <strong>库存超高：</strong>可用库存 &gt; 最高库存（如设置）</div>
          </div>
        </div>
      </div>
      <span slot="footer" class="dialog-footer-v2">
        <el-button size="small" @click="alertConfigVisible = false">取消</el-button>
        <el-button size="small" type="success" class="btn-green-submit" :loading="alertConfigSaving" @click="saveAlertConfig">保存配置</el-button>
      </span>
    </el-dialog>

    <el-dialog
      title=""
      :visible.sync="batchDialogVisible"
      width="82%"
      top="5vh"
      custom-class="item-batch-dialog"
      :close-on-click-modal="false"
      @closed="resetBatchDialog"
    >
      <div v-if="selectedBalance" class="batch-dialog-page" v-loading="batchDialogLoading">
        <header class="batch-dialog-head">
          <div>
            <h2>Item批次与质量处理</h2>
            <p><strong>{{ selectedBalance.item_code }}</strong><span>{{ selectedBalance.item_name }}</span></p>
          </div>
          <el-button size="small" type="success" plain @click="viewItemTransactions(selectedBalance)">查看Item流水</el-button>
        </header>

        <div class="batch-item-summary">
          <div><span>Item编码</span><strong>{{ selectedBalance.item_code }}</strong></div>
          <div><span>Item名称</span><strong>{{ selectedBalance.item_name }}</strong></div>
          <div><span>单位</span><strong>{{ selectedBalance.unit }}</strong></div>
          <div><span>账面库存</span><strong>{{ quantityNumber(selectedBalance.quantity_on_hand) }}</strong></div>
          <div><span>可用库存</span><strong>{{ quantityNumber(selectedBalance.quantity_available) }}</strong></div>
          <div><span>锁定数量</span><strong>{{ quantityNumber(selectedBalance.quantity_locked) }}</strong></div>
          <div><span>待处理数量</span><strong>{{ quantityNumber(selectedBalance.quantity_pending) }}</strong></div>
          <div><span>库存价值</span><strong>¥{{ money(selectedBalance.inventory_value) }}</strong></div>
        </div>

        <div class="batch-dialog-grid">
          <section class="batch-list-panel">
            <h3>批次列表</h3>
            <el-table :data="batchRows" size="mini" border highlight-current-row :row-class-name="batchRowClass" @current-change="selectBatch">
              <el-table-column prop="batch_no" label="批次号" min-width="112" />
              <el-table-column prop="warehouse_count" label="仓库数" width="72" align="right" />
              <el-table-column prop="location_count" label="库位数" width="72" align="right" />
              <el-table-column prop="quantity_on_hand" label="账面" width="66" align="right" />
              <el-table-column prop="quantity_available" label="可用" width="66" align="right" />
              <el-table-column prop="quantity_locked" label="锁定" width="58" align="right" />
              <el-table-column prop="quantity_pending" label="待处理" width="64" align="right" />
              <el-table-column label="最近变动" width="112"><template slot-scope="{row}">{{ dateTime(row.last_transaction_at) }}</template></el-table-column>
              <el-table-column label="操作" width="88"><template slot-scope="{row}"><el-button size="mini" type="text" @click.stop="selectBatch(row)">查看处理</el-button></template></el-table-column>
            </el-table>
            <div class="batch-list-footer">
              <span>共 {{ batchPagination.total }} 条</span>
              <el-pagination small layout="sizes, prev, pager, next, jumper" :page-sizes="[10,20,50]" :current-page.sync="batchPagination.page" :page-size.sync="batchPagination.per_page" :total="batchPagination.total" @current-change="loadBatchRows" @size-change="handleBatchSizeChange" />
            </div>
          </section>

          <section class="batch-context-panel" v-loading="batchContextLoading">
            <h3>批次处理</h3>
            <p class="current-batch">当前批次：<strong>{{ selectedBatch ? selectedBatch.batch_no : '-' }}</strong></p>

            <div class="batch-context-section">
              <h4>采购来源追溯</h4>
              <dl class="source-grid">
                <div><dt>采购到货单</dt><dd>{{ batchContext.source.receipt_no || '-' }}</dd></div>
                <div><dt>采购订单</dt><dd>{{ batchContext.source.purchase_order_no || '-' }}</dd></div>
                <div><dt>供应商</dt><dd>{{ batchContext.source.supplier_name || '-' }}</dd></div>
                <div><dt>到货日期</dt><dd>{{ batchContext.source.receipt_date || '-' }}</dd></div>
              </dl>
            </div>

            <div class="batch-context-section">
              <h4>库存分布（当前批次）</h4>
              <el-table :data="batchContext.balances" size="mini" border>
                <el-table-column prop="warehouse.warehouse_name" label="仓库" min-width="95" />
                <el-table-column prop="location.location_name" label="库位" min-width="95" />
                <el-table-column label="可用编号 / 编号档案" width="132" align="right"><template slot-scope="{row}">{{ Number(row.available_serials_count || 0) }} / {{ Number(row.serials_count || 0) }}</template></el-table-column>
                <el-table-column prop="quantity_on_hand" label="账面" width="72" align="right" />
                <el-table-column prop="quantity_available" label="可用" width="72" align="right" />
                <el-table-column prop="quantity_pending" label="待处理" width="76" align="right" />
                <el-table-column label="操作" width="142">
                  <template slot-scope="{row}">
                    <el-button v-if="Number(row.serials_count || 0) > 0" size="mini" type="text" @click="openBalanceSerials(row)">查看编号</el-button>
                    <el-button size="mini" type="text" :disabled="Number(row.quantity_on_hand || 0) <= 0" @click="openAdjustmentFromBalance(row)">库存调整</el-button>
                    <el-button size="mini" type="text" :disabled="Number(row.quantity_available || 0) <= 0" @click="openBatchBalanceQuality(row)">质量处理</el-button>
                  </template>
                </el-table-column>
              </el-table>
            </div>

            <div class="batch-context-section quality-history-section">
              <h4>质量处理记录（当前批次）</h4>
              <el-table :data="batchContext.quality_events" size="mini" border empty-text="当前批次暂无质量处理记录">
                <el-table-column prop="event_no" label="处理单号" min-width="135" />
                <el-table-column label="方式" width="82"><template slot-scope="{row}">{{ qualityHandlingText(row.handling_method) }}</template></el-table-column>
                <el-table-column prop="issue_qty" label="数量" width="68" align="right" />
                <el-table-column label="状态" width="98"><template slot-scope="{row}">{{ qualityStatusText(row.event_status) }}</template></el-table-column>
                <el-table-column label="时间" width="140"><template slot-scope="{row}">{{ dateTime(row.created_at) }}</template></el-table-column>
                <el-table-column label="处理单据" min-width="142">
                  <template slot-scope="{row}">
                    <el-button v-if="row.business_doc_id" size="mini" type="text" @click="viewQualityBusinessDocument(row)">{{ row.business_doc_no || '查看处理单' }}</el-button>
                    <span v-else>-</span>
                  </template>
                </el-table-column>
              </el-table>
            </div>

            <div class="batch-quality-actions">
              <h4>质量处理</h4>
              <div>
                <el-button size="small" type="success" plain :disabled="!hasQualityActionBalance" @click="openBatchQuality('return_supplier')">退供应商</el-button>
                <el-button size="small" type="success" plain :disabled="!hasQualityActionBalance" @click="openBatchQuality('exchange')">换货</el-button>
              </div>
              <p>已入库库存不适用让步接收；返修、报废待正式单据接入后开放。</p>
            </div>
          </section>
        </div>
      </div>
      <span slot="footer"><el-button size="small" @click="batchDialogVisible = false">关闭</el-button></span>
    </el-dialog>

    <el-dialog
      title="库位设备编号 / 序列号"
      :visible.sync="serialDialogVisible"
      width="min(760px, 92vw)"
      append-to-body
      custom-class="inventory-serial-dialog"
    >
      <div v-if="serialDialogBalance" class="inventory-serial-dialog-body">
        <div class="serial-locator-summary">
          <div><span>Item</span><strong>{{ serialDialogBalance.item_code }} / {{ serialDialogBalance.item_name }}</strong></div>
          <div><span>批次</span><strong>{{ serialDialogBalance.batch_no || (selectedBatch && selectedBatch.batch_no) || '-' }}</strong></div>
          <div><span>仓库</span><strong>{{ serialDialogBalance.warehouse_name || '-' }}</strong></div>
          <div><span>库位</span><strong>{{ serialDialogBalance.location_name || '-' }}</strong></div>
        </div>
        <el-input v-model="serialKeyword" size="small" clearable prefix-icon="el-icon-search" placeholder="输入设备编号 / 序列号查询" @keyup.enter.native="searchBalanceSerials" @clear="searchBalanceSerials" />
        <el-table v-loading="serialDialogLoading" :data="serialRows" size="mini" border max-height="430" empty-text="当前库位没有匹配编号">
          <el-table-column type="index" label="#" width="48" />
          <el-table-column prop="serial_no" label="设备编号 / 序列号" min-width="210" />
          <el-table-column label="状态" width="96"><template slot-scope="{row}">{{ serialStatusText(row.serial_status) }}</template></el-table-column>
          <el-table-column label="到货登记" width="150"><template slot-scope="{row}">{{ dateTime(row.received_at || row.registered_at) }}</template></el-table-column>
          <el-table-column label="库存过账" width="150"><template slot-scope="{row}">{{ dateTime(row.posted_at) }}</template></el-table-column>
        </el-table>
        <el-pagination class="serial-pagination" small layout="total, sizes, prev, pager, next" :page-sizes="[10,20,50,100]" :current-page.sync="serialPagination.page" :page-size.sync="serialPagination.per_page" :total="serialPagination.total" @current-change="loadBalanceSerials" @size-change="handleSerialSizeChange" />
      </div>
      <span slot="footer"><el-button size="small" @click="serialDialogVisible=false">关闭</el-button></span>
    </el-dialog>

    <section v-if="activeView === 'transactions' || activeView === 'adjustments'" class="inventory-view">
      <div class="page-head">
        <div>
          <h1>{{ activeView === 'adjustments' ? '手工调整' : '库存流水' }}</h1>
          <p>库存流水为库存变化唯一依据。手工调整必须生成库存事务，不能直接修改库存余额。</p>
        </div>
        <el-button size="small" type="success" icon="el-icon-plus" @click="openAdjustmentDrawer()">手工调整</el-button>
      </div>

      <el-alert class="balance-rule" title="库存流水生成后只读：不能编辑、不能删除、不能直接改数量；后续反过账只能通过反向事务处理。" type="info" :closable="false" show-icon />

      <div class="metric-grid four">
        <article><i class="el-icon-download green" /><span>{{ activeView === 'adjustments' ? '调整单数' : '今日入库流水' }}</span><strong>{{ activeView === 'adjustments' ? adjustmentPagination.total : transactionStats.today_in_count }}</strong></article>
        <article><i class="el-icon-refresh orange" /><span>{{ activeView === 'adjustments' ? '已过账调整' : '今日调整流水' }}</span><strong>{{ activeView === 'adjustments' ? adjustmentStats.posted_count : transactionStats.today_adjust_count }}</strong></article>
        <article><i class="el-icon-time blue" /><span>{{ activeView === 'adjustments' ? '待确认过账' : '待审核调整' }}</span><strong>{{ activeView === 'adjustments' ? adjustmentStats.submitted_count : transactionStats.pending_adjustment_count }}</strong></article>
        <article><i class="el-icon-refresh-left purple" /><span>{{ activeView === 'adjustments' ? '草稿调整' : '已反过账预留' }}</span><strong>{{ activeView === 'adjustments' ? adjustmentStats.draft_count : transactionStats.reversed_count }}</strong></article>
      </div>

      <section v-if="activeView === 'transactions'" class="filter-panel tx-filter">
        <label>事务类型<el-select v-model="txQuery.type" size="small" clearable placeholder="全部"><el-option label="采购到货入库" value="purchase_receipt_posting" /><el-option label="手工库存调整" value="manual_adjustment" /></el-select></label>
        <label>来源单号<el-input v-model="txQuery.sourceNo" size="small" placeholder="请输入来源单号" /></label>
        <label>物料编码/名称<el-input v-model="txQuery.keyword" size="small" placeholder="支持编码/名称查询" /></label>
        <label>仓库<el-select v-model="txQuery.warehouse" size="small" clearable placeholder="全部"><el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" /></el-select></label>
        <label>库位<el-select v-model="txQuery.location" size="small" clearable placeholder="全部"><el-option v-for="l in locations" :key="l.id" :label="l.location_name" :value="l.id" /></el-select></label>
        <label>批次号<el-input v-model="txQuery.batch" size="small" placeholder="请输入批次号" /></label>
        <label>过账日期<el-date-picker v-model="txQuery.dateRange" type="daterange" size="small" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" value-format="yyyy-MM-dd" /></label>
        <el-button size="small" type="success" @click="searchTransactions">查询</el-button>
        <el-button size="small" @click="resetTxQuery">重置</el-button>
      </section>
      <section v-else class="filter-panel tx-filter">
        <label>调整单号<el-input v-model="adjustmentQuery.no" size="small" placeholder="请输入调整单号" /></label>
        <label>调整原因<el-select v-model="adjustmentQuery.reason" size="small" clearable placeholder="全部"><el-option v-for="reason in adjustmentReasons" :key="reason.value" :label="reason.label" :value="reason.value" /></el-select></label>
        <label>物料编码/名称<el-input v-model="adjustmentQuery.keyword" size="small" placeholder="支持编码/名称查询" /></label>
        <label>状态<el-select v-model="adjustmentQuery.status" size="small" clearable placeholder="全部"><el-option label="草稿" value="draft" /><el-option label="已提交" value="submitted" /><el-option label="已过账" value="posted" /><el-option label="已取消" value="cancelled" /></el-select></label>
        <label>更新日期<el-date-picker v-model="adjustmentQuery.dateRange" type="daterange" size="small" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" value-format="yyyy-MM-dd" /></label>
        <el-button size="small" type="success" @click="searchAdjustments">查询</el-button>
        <el-button size="small" @click="resetAdjustmentQuery">重置</el-button>
      </section>

      <div v-if="activeView === 'transactions'" class="disabled-legend">事务类型图例：
        <b class="green-text">采购到货入库</b>
        <b class="orange-text">手工库存调整</b>
        <span>销售出库 / 工单领料 / 完工入库（后续阶段）</span>
      </div>
      <div v-else class="disabled-legend">手工调整单是库存数量修正的业务单据：先保存/提交，确认过账后才生成库存流水并改变余额。详情请点击单据查看。</div>

      <section class="tx-layout">
        <div class="table-card">
          <el-table v-if="activeView === 'transactions'" :data="filteredTransactions" size="mini" border>
            <el-table-column prop="tx_no" label="流水号" min-width="128" fixed show-overflow-tooltip />
            <el-table-column prop="transaction_type_text" label="事务类型" min-width="108" show-overflow-tooltip />
            <el-table-column prop="source_no" label="来源单号" min-width="120" show-overflow-tooltip />
            <el-table-column prop="item_code" label="物料编码" min-width="110" fixed show-overflow-tooltip />
            <el-table-column prop="item_name" label="物料名称" min-width="132" fixed show-overflow-tooltip />
            <el-table-column prop="warehouse_name" label="仓库" width="92" show-overflow-tooltip />
            <el-table-column prop="location_name" label="库位" width="86" show-overflow-tooltip />
            <el-table-column prop="batch_no" label="批次号" min-width="108" show-overflow-tooltip />
            <el-table-column prop="change_qty" label="变动" width="78" align="right">
              <template slot-scope="{ row }"><span :class="row.change_qty >= 0 ? 'green-text' : 'red-text'">{{ row.change_qty > 0 ? '+' : '' }}{{ row.change_qty }}</span></template>
            </el-table-column>
            <el-table-column prop="balance_qty" label="库位结存" width="96" align="right" />
            <el-table-column label="状态" width="86" fixed="right"><template slot-scope="{ row }"><el-tag size="mini" type="success">{{ row.posting_status_text }}</el-tag></template></el-table-column>
            <el-table-column prop="posted_at" label="过账时间" min-width="132" show-overflow-tooltip />
          </el-table>
          <el-table v-else :data="filteredAdjustmentRows" size="mini" border @row-dblclick="viewAdjustment">
            <el-table-column prop="adjustment_no" label="调整单号" min-width="142" fixed show-overflow-tooltip />
            <el-table-column label="调整原因" min-width="112" show-overflow-tooltip>
              <template slot-scope="{ row }">{{ adjustmentReasonLabel(row.reason) }}</template>
            </el-table-column>
            <el-table-column label="物料" min-width="170" fixed show-overflow-tooltip>
              <template slot-scope="{ row }">{{ row.item_code }} / {{ row.item_name || '-' }}</template>
            </el-table-column>
            <el-table-column prop="warehouse_name" label="仓库" width="96" show-overflow-tooltip />
            <el-table-column prop="location_name" label="库位" width="86" show-overflow-tooltip />
            <el-table-column prop="batch_no" label="批次号" min-width="108" show-overflow-tooltip />
            <el-table-column prop="direction_text" label="方向" width="78" />
            <el-table-column label="调整数量" width="96" align="right">
              <template slot-scope="{ row }"><span :class="row.change_qty >= 0 ? 'green-text' : 'red-text'">{{ row.change_qty > 0 ? '+' : '' }}{{ row.change_qty }}</span></template>
            </el-table-column>
            <el-table-column label="状态" width="92">
              <template slot-scope="{ row }"><el-tag size="mini" :type="adjustmentStatusType(row.status)">{{ adjustmentStatusText(row.status) }}</el-tag></template>
            </el-table-column>
            <el-table-column prop="created_at" label="创建时间" min-width="132" show-overflow-tooltip />
            <el-table-column label="操作" width="220" fixed="right">
              <template slot-scope="{ row }">
                <div class="row-actions row-actions-wide">
                  <el-button size="mini" plain @click.stop="viewAdjustment(row)">查看</el-button>
                  <el-button v-if="row.status === 'draft'" size="mini" type="text" @click.stop="submitExistingAdjustment(row)">提交</el-button>
                  <el-button v-if="row.status === 'draft'" size="mini" type="text" class="red-text" @click.stop="deleteAdjustment(row)">删除</el-button>
                  <el-button v-if="row.status === 'submitted'" size="mini" type="success" @click.stop="postAdjustment(row)">确认过账</el-button>
                  <el-button v-if="row.status === 'submitted'" size="mini" type="text" @click.stop="cancelAdjustment(row)">取消</el-button>
                  <span v-if="['posted', 'cancelled'].includes(row.status)" class="readonly-text">只读</span>
                </div>
              </template>
            </el-table-column>
          </el-table>
          <div v-if="activeView === 'transactions'" class="table-footer">
            <span />
            <el-pagination small layout="total, sizes, prev, pager, next" :page-sizes="[10, 20, 50, 100]" :current-page.sync="txPagination.page" :page-size.sync="txPagination.per_page" :total="txPagination.total" @current-change="loadTransactions" @size-change="handleTxSizeChange" />
          </div>
          <div v-else class="table-footer">
            <span>双击行或点“查看”打开详情。</span>
            <el-pagination small layout="total, sizes, prev, pager, next" :page-sizes="[10, 20, 50, 100]" :current-page.sync="adjustmentPagination.page" :page-size.sync="adjustmentPagination.per_page" :total="adjustmentPagination.total" @current-change="loadAdjustments" @size-change="handleAdjustmentSizeChange" />
          </div>
        </div>
      </section>
    </section>

    <el-dialog title="确认入库过账" :visible.sync="postingDialogVisible" width="760px" class="posting-dialog">
      <div v-if="postingCandidate">
        <el-alert title="本次只将合格数量写入正常库存。不合格数量和待处理数量不会进入正常库存。过账后会生成库存流水，并更新库存余额。同一到货单不能重复过账。" type="warning" :closable="false" show-icon />
        <dl class="confirm-grid">
          <dt>到货单号</dt><dd>{{ postingCandidate.receipt_no }}</dd>
          <template v-if="postingCandidate.has_purchase_order">
            <dt>采购订单号</dt><dd>{{ postingCandidate.order_no }}</dd>
          </template>
          <template v-else>
            <dt>来源类型</dt><dd>手工到货</dd>
            <dt>来源单号</dt><dd>--</dd>
          </template>
          <dt>供应商</dt><dd>{{ postingCandidate.supplier_name }}</dd>
          <dt>到货日期</dt><dd>{{ postingCandidate.receipt_date }}</dd>
          <dt>合格数量</dt><dd>{{ postingCandidate.qualified_display }}</dd>
          <dt>不合格数量</dt><dd>{{ postingCandidate.defective_display }}</dd>
          <dt>待处理数量</dt><dd>{{ postingCandidate.pending_display }}</dd>
          <dt>本次进入正常库存数量</dt><dd class="green-text">{{ postingCandidate.qualified_display }}</dd>
          <dt>本次不进入正常库存数量</dt><dd class="orange-text">{{ postingCandidate.non_stock_display }}</dd>
        </dl>
        <div class="posting-assignment-list">
          <section v-for="line in postingCandidate.items" :key="line.id" class="posting-assignment-line">
            <strong>{{ line.item_code }} / {{ line.item_name }}</strong>
            <div v-if="!(line.allocations || []).length" class="posting-allocation-missing">未完成入库库位分配，禁止过账</div>
            <label>批次号<el-input :value="line.batch_no" size="small" disabled /></label>
            <div v-if="(line.allocations || []).length" class="posting-allocation-review">
              <div v-for="allocation in line.allocations" :key="allocation.id || `${allocation.warehouse_id}-${allocation.location_id}`">
                <strong>{{ allocation.warehouse ? allocation.warehouse.warehouse_name : '-' }} / {{ allocation.location ? allocation.location.location_name : '-' }}</strong>
                <span>{{ quantityNumber(allocation.base_qty) }} {{ line.base_unit }} · {{ (allocation.serial_nos || []).length }} 个编号</span>
              </div>
            </div>
            <div v-if="line.is_serial_managed" class="posting-serial-field">
              <span>已在实物入库环节登记的设备编号</span>
              <div class="posting-serial-tags"><el-tag v-for="serial in serialNumberList(line.serial_text)" :key="serial" size="mini" type="success">{{ serial }}</el-tag></div>
              <small>库存过账仅关联已登记编号，不允许在此首次录入。</small>
            </div>
          </section>
        </div>
      </div>
      <span slot="footer">
        <el-button size="small" @click="postingDialogVisible = false">取消</el-button>
        <el-button size="small" type="success" @click="confirmPosting">确认过账</el-button>
      </span>
    </el-dialog>

    <el-dialog title="补充入库分配" :visible.sync="postingRepairVisible" width="860px" class="posting-repair-dialog" :close-on-click-modal="false">
      <div v-if="postingRepairCandidate" v-loading="postingRepairSaving">
        <el-alert title="该操作只补充仓库、库位及设备编号的库位归属，不修改原到货数量、质量结果、采购金额和确认记录。" type="warning" :closable="false" show-icon />
        <div class="posting-repair-summary">
          <span>到货单号<strong>{{ postingRepairCandidate.receipt_no }}</strong></span>
          <span>当前阻断原因<strong class="red-text">{{ postingBlockedReason(postingRepairCandidate) }}</strong></span>
        </div>
        <section v-for="line in postingRepairCandidate.items" :key="line.id" class="posting-repair-line">
          <header>
            <div><strong>{{ line.item_code }} / {{ line.item_name }}</strong><span>合格入库 {{ quantityNumber(line.qualified_qty) }} {{ line.base_unit }}　批次 {{ line.batch_no || '-' }}</span></div>
            <el-button size="mini" type="success" plain @click="addPostingRepairAllocation(line)">添加库位</el-button>
          </header>
          <div v-for="(allocation,index) in line.allocations" :key="index" class="posting-repair-allocation">
            <label>仓库<el-select v-model="allocation.warehouse_id" size="small" placeholder="请选择仓库" @change="allocation.location_id = null"><el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" /></el-select></label>
            <label>库位<el-select v-model="allocation.location_id" size="small" placeholder="请选择库位"><el-option v-for="l in filteredPostingLocations(allocation.warehouse_id)" :key="l.id" :label="l.location_name" :value="l.id" /></el-select></label>
            <label>分配基本数量<el-input-number v-model="allocation.base_qty" size="small" :min="0.00000001" :max="line.qualified_qty" :precision="4" controls-position="right" /></label>
            <label v-if="line.serial_tracking_mode !== 'none'" class="posting-repair-serials">设备编号<el-select v-model="allocation.serial_nos" size="small" multiple filterable placeholder="选择分配到该库位的编号"><el-option v-for="serial in serialNumberList(line.serial_text)" :key="serial" :label="serial" :value="serial" :disabled="postingSerialAssignedElsewhere(line, allocation, serial)" /></el-select></label>
            <el-button size="mini" type="text" class="posting-repair-remove" :disabled="line.allocations.length === 1" @click="removePostingRepairAllocation(line,index)">删除</el-button>
          </div>
          <div class="posting-repair-progress" :class="{ danger: !postingRepairLineComplete(line) }">
            已分配 {{ quantityNumber((line.allocations || []).reduce((sum,row) => sum + Number(row.base_qty || 0), 0)) }} / {{ quantityNumber(line.qualified_qty) }} {{ line.base_unit }}
          </div>
        </section>
      </div>
      <span slot="footer">
        <el-button size="small" @click="postingRepairVisible = false">取消</el-button>
        <el-button size="small" type="success" :loading="postingRepairSaving" @click="savePostingRepair">保存分配并重新检查</el-button>
      </span>
    </el-dialog>

    <transition name="side-slide">
      <aside v-if="adjustmentDrawerVisible && !balancePickerVisible" class="adjustment-panel">
        <header>
          <h3>手工库存调整单</h3>
          <button type="button" @click="adjustmentDrawerVisible = false"><i class="el-icon-close" /></button>
        </header>
        <div class="adjustment-panel-body">
          <el-alert title="手工调整必须生成库存事务，不能直接修改库存余额。第 3 阶段默认不允许负库存。" type="info" :closable="false" show-icon />
          <el-form :model="adjustmentForm" label-position="top" size="small">
            <el-form-item label="调整原因">
              <el-select v-model="adjustmentForm.reason" placeholder="请选择调整原因" popper-class="adjustment-reason-popper" :popper-append-to-body="false">
                <el-option v-for="reason in adjustmentReasons" :key="reason.value" :label="reason.label" :value="reason.value" />
              </el-select>
            </el-form-item>
            <el-form-item label="库存对象（物料 / 仓库 / 库位 / 批次）">
              <div class="balance-picker-card">
                <div>
                  <strong>{{ adjustmentForm.item_code ? `${adjustmentForm.item_code} / ${adjustmentForm.item_name}` : '未选择库存对象' }}</strong>
                  <span>{{ adjustmentForm.warehouse_name || '-' }} / {{ adjustmentForm.location_name || '-' }} / {{ adjustmentForm.batch_no || '-' }}</span>
                  <small v-if="adjustmentForm.balance_id">
                    账面库存：{{ adjustmentForm.current_on_hand }}　可用库存：{{ adjustmentForm.current_available }}　锁定库存：{{ adjustmentForm.current_locked }}　不合格数量：{{ adjustmentForm.current_defective }}　待处理数量：{{ adjustmentForm.current_pending }}
                  </small>
                </div>
                <el-button size="mini" type="success" plain @click="openBalancePicker">选择库存对象</el-button>
              </div>
            </el-form-item>
            <div class="adjustment-readonly-grid">
              <span><b>物料</b>{{ adjustmentForm.item_code || '-' }} / {{ adjustmentForm.item_name || '-' }}</span>
              <span><b>单位</b>{{ adjustmentForm.unit_name || '-' }}</span>
              <span><b>仓库</b>{{ adjustmentForm.warehouse_name || '-' }}</span>
              <span><b>库位</b>{{ adjustmentForm.location_name || '-' }}</span>
              <span><b>批次</b>{{ adjustmentForm.batch_no || '-' }}</span>
            </div>
            <div class="adjustment-stock-preview">
              <article><span>账面</span><strong>{{ adjustmentForm.current_on_hand }}</strong></article>
              <article><span>可用</span><strong>{{ adjustmentForm.current_available }}</strong></article>
              <article><span>锁定</span><strong>{{ adjustmentForm.current_locked }}</strong></article>
              <article :class="{ danger: adjustmentWillBeNegative }"><span>调整后账面</span><strong>{{ adjustmentAfterOnHand }}</strong></article>
              <article :class="{ danger: adjustmentWillBeNegative }"><span>调整后可用</span><strong>{{ adjustmentAfterAvailable }}</strong></article>
            </div>
            <el-form-item label="调整数量"><el-input-number ref="adjustmentQtyInput" v-model="adjustmentForm.change_qty" :min="0" :max="999999" controls-position="right" @input="syncAdjustmentQty" @change="syncAdjustmentQty" @input.native="syncAdjustmentQtyFromNative" /></el-form-item>
            <el-radio-group v-model="adjustmentForm.direction" @change="handleAdjustmentDirectionChange">
              <el-radio label="increase">增加（正数）</el-radio>
              <el-radio label="decrease">减少（负数）</el-radio>
            </el-radio-group>
            <section v-if="adjustmentForm.serial_tracking_mode !== 'none'" class="adjustment-serial-card">
              <div class="adjustment-serial-head">
                <div>
                  <strong>设备编号 / 序列号</strong>
                  <small>{{ adjustmentSerialPolicyText }}</small>
                </div>
                <el-tag size="mini" :type="adjustmentForm.serial_tracking_mode === 'required' ? 'warning' : 'info'">
                  {{ adjustmentForm.serial_tracking_mode === 'required' ? '必须逐件编号' : '按需编号' }}
                </el-tag>
              </div>

              <template v-if="adjustmentForm.direction === 'increase'">
                <div class="adjustment-serial-actions">
                  <el-button size="mini" type="success" plain :loading="adjustmentSerialGenerating" @click="generateAdjustmentSerials">一次生成 {{ adjustmentIntegerQty }} 个</el-button>
                  <el-button size="mini" @click="adjustmentForm.serial_text = ''">清空</el-button>
                </div>
                <el-input v-model="adjustmentForm.serial_text" type="textarea" :rows="5" resize="vertical" placeholder="支持扫码枪连续扫描或手工输入；每行一个编号，扫描后回车自动进入下一行" @input="adjustmentForm.serial_number_source = 'manual'" />
              </template>

              <template v-else>
                <el-select
                  v-model="adjustmentForm.selected_serial_numbers"
                  multiple filterable remote reserve-keyword collapse-tags
                  :remote-method="searchAdjustmentSerials"
                  :loading="adjustmentSerialLoading"
                  :popper-append-to-body="false"
                  popper-class="adjustment-serial-popper"
                  placeholder="搜索并选择当前库位、当前批次的可用编号">
                  <el-option v-for="serial in adjustmentSerialOptions" :key="serial.id" :label="serial.serial_no" :value="serial.serial_no" />
                </el-select>
                <small class="adjustment-serial-stock">当前库存对象共有 {{ adjustmentSerialTotal }} 个可用编号；只允许选择本 Item / 仓库 / 库位 / 批次中的编号。</small>
              </template>

              <div class="adjustment-serial-count" :class="{ danger: !adjustmentSerialCountValid }">
                已{{ adjustmentForm.direction === 'increase' ? '录入' : '选择' }} {{ adjustmentSerialEntries.length }} 个，调整数量 {{ adjustmentIntegerQty }}
              </div>
            </section>
            <el-alert v-if="adjustmentWillBeNegative" class="rule-alert" title="调整后会出现负库存，系统不允许保存、提交或过账。" type="error" :closable="false" show-icon />
            <el-form-item label="备注"><el-input v-model="adjustmentForm.remark" type="textarea" :rows="4" maxlength="200" show-word-limit placeholder="请输入备注" /></el-form-item>
          </el-form>
        </div>
        <footer>
          <el-button size="small" @click="adjustmentDrawerVisible = false">取消</el-button>
          <el-button size="small" @click="saveAdjustmentDraft">保存草稿</el-button>
          <el-button size="small" type="success" @click="submitAdjustment">提交调整</el-button>
        </footer>
      </aside>
    </transition>

    <transition name="side-slide">
      <aside v-if="qualityDrawerVisible" class="adjustment-panel quality-panel">
        <header>
          <div>
            <h3>库存质量处理</h3>
            <small>定位实物、冻结库存并生成正式处理单</small>
          </div>
          <button type="button" @click="qualityDrawerVisible = false"><i class="el-icon-close" /></button>
        </header>
        <div class="adjustment-panel-body quality-panel-body">
          <el-alert title="入库后发现的质量问题从这里发起。创建后对应数量立即转为待处理，不再参与销售履约或领料。" type="warning" :closable="false" show-icon />

          <section class="quality-card">
            <h4><b>1</b> 实物定位</h4>
            <dl class="quality-locator">
              <dt>Item</dt><dd>{{ qualityForm.item_code }} / {{ qualityForm.item_name }}</dd>
              <dt>仓库 / 库位</dt><dd>{{ qualityForm.warehouse_name }} / {{ qualityForm.location_name }}</dd>
              <dt>批次号</dt><dd>{{ qualityForm.batch_no || '-' }}</dd>
              <dt>当前可用</dt><dd class="green-text">{{ qualityForm.current_available }} {{ qualityForm.unit_name }}</dd>
            </dl>
            <label class="quality-field">设备编号 / 序列号（优先定位单件实物）
              <el-select v-model="qualityForm.serial_no" class="full-control" size="small" clearable filterable
                :disabled="qualitySerials.length === 0"
                :placeholder="qualitySerialPlaceholder"
                :popper-append-to-body="false"
                popper-class="quality-select-popper">
                <el-option v-for="serial in qualitySerials" :key="serial.id" :label="serial.serial_no" :value="serial.serial_no" />
              </el-select>
              <small :class="{ 'orange-text': qualityForm.serial_tracking_mode !== 'none' && qualitySerials.length === 0 }">{{ qualitySerialHint }}</small>
            </label>
          </section>

          <section class="quality-card">
            <h4><b>2</b> 来源追溯</h4>
            <dl class="quality-locator">
              <dt>采购到货单</dt><dd>{{ qualitySource.receipt_no || '未找到采购来源' }}</dd>
              <dt>采购订单</dt><dd>{{ qualitySource.purchase_order_no || '-' }}</dd>
              <dt>供应商</dt><dd>{{ qualitySource.supplier_name || '-' }}</dd>
            </dl>
            <el-alert v-if="!qualitySource.receipt_no" title="当前批次没有可追溯采购入库来源；仍可冻结处理，但不能发起退供应商或换货。" type="info" :closable="false" show-icon />
          </section>

          <section v-if="qualityEvents.length" class="quality-card active-quality-card">
            <h4><b>!</b> 当前处理中质量事件</h4>
            <article v-for="event in qualityEvents" :key="event.id" class="quality-event-row">
              <div><strong>{{ event.event_no }}</strong><span>{{ qualityHandlingText(event.handling_method) }} · {{ qualityStatusText(event.event_status) }}</span><small>处理单：{{ event.business_doc_no }}　数量：{{ Number(event.issue_qty) }} {{ event.unit_name_snapshot }}</small></div>
              <div class="quality-event-actions">
                <span class="readonly-text">请在对应采购退回/换货单中继续处理</span>
              </div>
            </article>
          </section>

          <section class="quality-card">
            <h4><b>3</b> 问题与处理</h4>
            <div class="quality-form-grid">
              <label>问题数量 <em>*</em>
                <el-input-number v-model="qualityForm.issue_qty" class="full-control" size="small" :min="0.00000001" :max="qualityForm.serial_no ? 1 : qualityForm.current_available" :precision="4" controls-position="right" />
              </label>
              <label>问题类别 <em>*</em>
                <el-select v-model="qualityForm.issue_category" class="full-control" size="small" placeholder="请选择问题类别" :popper-append-to-body="false" popper-class="quality-select-popper">
                  <el-option label="功能故障" value="function_failure" /><el-option label="外观损伤" value="appearance_damage" /><el-option label="性能异常" value="performance_abnormal" /><el-option label="缺少配件" value="missing_parts" /><el-option label="其他" value="other" />
                </el-select>
              </label>
              <label class="span-two">问题描述 <em>*</em>
                <el-input v-model.trim="qualityForm.issue_description" class="full-control" type="textarea" :rows="3" maxlength="1000" show-word-limit placeholder="请描述故障现象、发现时间和检查结果" />
              </label>
              <label>处理方式 <em>*</em>
                <el-select v-model="qualityForm.handling_method" class="full-control" size="small" placeholder="请选择实际处理方式" :popper-append-to-body="false" popper-class="quality-select-popper">
                  <el-option label="退供应商" value="return_supplier" /><el-option label="换货" value="exchange" />
                </el-select>
              </label>
              <label>责任方 <em>*</em>
                <el-select v-model="qualityForm.responsible_party" class="full-control" size="small" placeholder="请选择责任方" :popper-append-to-body="false" popper-class="quality-select-popper">
                  <el-option label="供应商" value="supplier" /><el-option label="仓库" value="warehouse" /><el-option label="物流" value="logistics" /><el-option label="内部使用" value="internal" /><el-option label="待判定" value="undetermined" />
                </el-select>
              </label>
            </div>
            <div class="quality-action-note">
              <strong>{{ qualityMethodTitle }}</strong>
              <span>{{ qualityMethodDescription }}</span>
            </div>
          </section>
        </div>
        <footer>
          <el-button size="small" @click="qualityDrawerVisible = false">取消</el-button>
          <el-button size="small" type="success" :loading="qualitySaving" @click="submitQualityEvent">创建质量事件</el-button>
        </footer>
      </aside>
    </transition>

    <transition name="side-slide">
      <aside v-if="adjustmentDetailVisible" class="adjustment-panel adjustment-detail-panel">
        <header>
          <h3>调整单详情</h3>
          <button type="button" @click="adjustmentDetailVisible = false"><i class="el-icon-close" /></button>
        </header>
        <div v-if="selectedAdjustment" class="adjustment-panel-body">
          <dl class="detail-dl adjustment-detail-dl">
            <dt>调整单号</dt><dd>{{ selectedAdjustment.adjustment_no }}</dd>
            <dt>状态</dt><dd><el-tag size="mini" :type="adjustmentStatusType(selectedAdjustment.status)">{{ adjustmentStatusText(selectedAdjustment.status) }}</el-tag></dd>
            <dt>调整原因</dt><dd>{{ adjustmentReasonLabel(selectedAdjustment.reason) }}</dd>
            <dt>物料</dt><dd>{{ selectedAdjustment.item_code }} / {{ selectedAdjustment.item_name || '-' }}</dd>
            <dt>仓库</dt><dd>{{ selectedAdjustment.warehouse_name || '-' }}</dd>
            <dt>库位</dt><dd>{{ selectedAdjustment.location_name || '-' }}</dd>
            <dt>批次</dt><dd>{{ selectedAdjustment.batch_no || '-' }}</dd>
            <dt>调整方向</dt><dd>{{ selectedAdjustment.direction_text }}</dd>
            <dt>调整数量</dt><dd :class="selectedAdjustment.change_qty >= 0 ? 'green-text' : 'red-text'">{{ selectedAdjustment.change_qty > 0 ? '+' : '' }}{{ selectedAdjustment.change_qty }}</dd>
            <dt v-if="selectedAdjustment.serial_numbers && selectedAdjustment.serial_numbers.length">设备编号</dt>
            <dd v-if="selectedAdjustment.serial_numbers && selectedAdjustment.serial_numbers.length" class="adjustment-detail-serials">
              <el-tag v-for="serial in selectedAdjustment.serial_numbers" :key="serial" size="mini">{{ serial }}</el-tag>
            </dd>
            <dt>更新时间</dt><dd>{{ selectedAdjustment.updated_at || '-' }}</dd>
          </dl>
          <el-alert class="rule-alert" title="这是调整单详情。库存流水请在“库存流水”页面按来源单号追溯。" type="info" :closable="false" show-icon />
        </div>
        <footer>
          <el-button size="small" @click="adjustmentDetailVisible = false">关闭</el-button>
          <el-button v-if="selectedAdjustment && selectedAdjustment.status === 'draft'" size="small" type="success" @click="submitExistingAdjustment(selectedAdjustment)">提交调整</el-button>
          <el-button v-if="selectedAdjustment && selectedAdjustment.status === 'draft'" size="small" type="danger" plain @click="deleteAdjustment(selectedAdjustment)">删除草稿</el-button>
          <el-button v-if="selectedAdjustment && selectedAdjustment.status === 'submitted'" size="small" type="success" @click="postAdjustment(selectedAdjustment)">确认过账</el-button>
          <el-button v-if="selectedAdjustment && selectedAdjustment.status === 'submitted'" size="small" @click="cancelAdjustment(selectedAdjustment)">取消调整</el-button>
        </footer>
      </aside>
    </transition>

    <el-dialog title="选择库存对象" :visible.sync="balancePickerVisible" width="820px" class="balance-picker-dialog">
      <section class="filter-panel picker-filter">
        <label>物料编码/名称<el-input v-model="balancePickerQuery.keyword" size="small" placeholder="支持编码、名称模糊查询" /></label>
        <label>仓库<el-select v-model="balancePickerQuery.warehouse" size="small" clearable placeholder="全部"><el-option v-for="w in warehouses" :key="w.id" :label="w.warehouse_name" :value="w.id" /></el-select></label>
        <label>库位<el-select v-model="balancePickerQuery.location" size="small" clearable placeholder="全部"><el-option v-for="l in locations" :key="l.id" :label="l.location_name" :value="l.id" /></el-select></label>
        <label>批次号<el-input v-model="balancePickerQuery.batch" size="small" placeholder="请输入批次号" /></label>
        <label>库存状态<el-select v-model="balancePickerQuery.status" size="small" clearable placeholder="全部"><el-option label="有库存" value="has_stock" /><el-option label="有异常数量" value="has_quality" /></el-select></label>
        <el-button size="small" type="success" @click="searchBalancePickerRows">查询</el-button>
        <el-button size="small" @click="resetBalancePickerQuery">重置</el-button>
      </section>
      <el-table :data="pagedBalancePickerRows" size="mini" border highlight-current-row>
        <el-table-column prop="item_code" label="物料编码" min-width="112" fixed show-overflow-tooltip />
        <el-table-column prop="item_name" label="物料名称" min-width="128" fixed show-overflow-tooltip />
        <el-table-column prop="warehouse_name" label="仓库" min-width="92" show-overflow-tooltip />
        <el-table-column prop="location_name" label="库位" width="86" show-overflow-tooltip />
        <el-table-column prop="batch_no" label="批次号" min-width="108" show-overflow-tooltip />
        <el-table-column prop="quantity_on_hand" label="账面" width="76" align="right" />
        <el-table-column prop="quantity_available" label="可用" width="76" align="right" />
        <el-table-column prop="quantity_locked" label="锁定" width="76" align="right" />
        <el-table-column prop="quantity_defective" label="不合格" width="76" align="right" />
        <el-table-column prop="quantity_pending" label="待处理" width="76" align="right" />
        <el-table-column label="操作" width="96" fixed="right">
          <template slot-scope="{ row }"><el-button size="mini" type="success" plain @click="chooseBalanceFromPicker(row)">选择</el-button></template>
        </el-table-column>
      </el-table>
      <div class="picker-footer">
        <span />
        <el-pagination small layout="total, sizes, prev, pager, next" :page-sizes="[10, 20, 50, 100]" :current-page.sync="balancePickerPagination.page" :page-size.sync="balancePickerPagination.per_page" :total="balancePickerPagination.total" @current-change="loadBalancePickerRows" @size-change="handleBalancePickerSizeChange" />
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { listPendingReceipts, repairPostingReceiptAllocations, postPostingReceipt, listInventoryBalances, listInventoryItemBatches, getInventoryBatchContext, listInventoryBalanceSerials, listInventoryTransactions, listInventoryAdjustments, listInventoryAdjustmentReasons, generateInventoryAdjustmentSerials, saveInventoryAdjustment, submitInventoryAdjustment, postInventoryAdjustment, cancelInventoryAdjustment, deleteInventoryAdjustment, getInventoryQualityContext, createInventoryQualityEvent, getInventoryAlertPolicy, activateInventoryAlertPolicy, disableInventoryAlertPolicy } from '@/api/erp/inventory'

export default {
  name: 'InventoryBoard',
  data() {
    return {
      activeView: 'posting',
      postingRows: [],
      postingStats: { posted_today: 0 },
      postingQuery: { dateRange: [], supplier: '', warehouse: '', keyword: '' },
      postingPagination: { page: 1, per_page: 20, total: 0 },
      selectedReceipt: null,
      postingDialogVisible: false,
      postingCandidate: null,
      postingRepairVisible: false,
      postingRepairCandidate: null,
      postingRepairSaving: false,
      balanceQuery: { keyword: '', warehouse: '', location: '', batch: '', status: '' },
      balancePagination: { page: 1, per_page: 20, total: 0 },
      selectedBalance: null,
      balanceDetailVisible: false,
      balanceDetailBatches: [],
      balanceRecentTransactions: [],
      balanceSelectedRows: [],
      alertConfigVisible: false,
      alertConfigLoading: false,
      alertConfigSaving: false,
      alertConfigItem: null,
      alertConfig: this.defaultAlertConfig(),
      batchDialogVisible: false,
      batchDialogLoading: false,
      batchContextLoading: false,
      batchRows: [],
      batchPagination: { page: 1, per_page: 10, total: 0 },
      selectedBatch: null,
      batchContext: { balances: [], source: {}, quality_events: [] },
      serialDialogVisible: false,
      serialDialogBalance: null,
      serialKeyword: '',
      serialDialogLoading: false,
      serialRows: [],
      serialPagination: { page: 1, per_page: 20, total: 0 },
      qualityDrawerVisible: false,
      qualitySaving: false,
      qualitySource: {},
      qualitySerials: [],
      qualityEvents: [],
      qualityForm: this.defaultQualityForm(),
      txQuery: { type: '', sourceNo: '', keyword: '', warehouse: '', location: '', batch: '', dateRange: [], itemId: null },
      txPagination: { page: 1, per_page: 20, total: 0 },
      transactionStats: { today_in_count: 0, today_adjust_count: 0, pending_adjustment_count: 0, reversed_count: 0 },
      adjustmentQuery: { no: '', reason: '', keyword: '', status: '', dateRange: [] },
      adjustmentPagination: { page: 1, per_page: 20, total: 0 },
      adjustmentStats: { posted_count: 0, submitted_count: 0, draft_count: 0 },
      adjustmentDetailVisible: false,
      selectedAdjustment: null,
      adjustmentDrawerVisible: false,
      adjustmentForm: this.defaultAdjustmentForm(),
      adjustmentReasons: [],
      adjustmentReasonMap: {},
      selectedAdjustmentBalance: null,
      adjustmentSaving: false,
      balancePickerVisible: false,
      balancePickerQuery: { keyword: '', warehouse: '', location: '', batch: '', status: '' },
      balancePickerPagination: { page: 1, per_page: 10, total: 0 },
      balancePickerRequestSeq: 0,
      balancePickerRows: [],
      adjustmentSerialOptions: [],
      adjustmentSerialLoading: false,
      adjustmentSerialGenerating: false,
      adjustmentSerialTotal: 0,
      balanceRows: [], balanceStats: { item_count: 0, stock_item_count: 0, batch_count: 0, quality_item_count: 0, inventory_value: 0 },
      transactions: [],
      adjustmentRows: [],
      items: [],
      warehouses: [],
      locations: []
    }
  },
  computed: {
    filteredPostingRows() {
      return this.postingRows
    },
    pendingPostingCount() { return this.postingRows.filter(row => row.posting_status === 'pending').length },
    postedTodayCount() { return Number(this.postingStats.posted_today || 0) },
    failedPostingCount() { return this.postingRows.filter(row => row.posting_status === 'failed').length },
    totalDefectiveQty() { return this.postingRows.reduce((sum, row) => sum + row.items.filter(item => Number(item.defective_qty || 0) > 0).length, 0) },
    filteredBalanceRows() {
      return this.balanceRows
    },
    alertStockTime() {
      return this.selectedBalance ? this.dateTime(this.selectedBalance.last_transaction_at) : '-'
    },
    balanceDetailDistributions() {
      if (this.balanceDetailBatches && this.balanceDetailBatches.length) {
        return this.balanceDetailBatches.map(b => ({
          warehouse_name: b.warehouse_name || '',
          location_name: b.location_name || '',
          batch_no: b.batch_no || '',
          quantity_on_hand: Number(b.quantity_on_hand || 0),
          quantity_available: Number(b.quantity_available || 0),
          quantity_locked: Number(b.quantity_locked || 0),
          quantity_defective: Number(b.quantity_defective || 0),
          quantity_pending: Number(b.quantity_pending || 0)
        }))
      }
      return []
    },
    balanceDistTotals() {
      const dist = this.balanceDetailDistributions
      return {
        quantity_on_hand: dist.reduce((sum, r) => sum + (r.quantity_on_hand || 0), 0),
        quantity_available: dist.reduce((sum, r) => sum + (r.quantity_available || 0), 0),
        quantity_locked: dist.reduce((sum, r) => sum + (r.quantity_locked || 0), 0),
        quantity_defective: dist.reduce((sum, r) => sum + (r.quantity_defective || 0), 0),
        quantity_pending: dist.reduce((sum, r) => sum + (r.quantity_pending || 0), 0)
      }
    },
    filteredTransactions() {
      return this.transactions
    },
    filteredAdjustmentRows() {
      return this.adjustmentRows
    },
    recentTransactionsForSelected() {
      if (!this.selectedBalance) return []
      return this.transactions.filter(tx => tx.item_code === this.selectedBalance.item_code).slice(0, 5)
    },
    adjustmentBalanceOptions() {
      return this.balancePickerRows.filter(row => row.item_id && row.warehouse_id && row.location_id)
    },
    filteredBalancePickerRows() {
      return this.adjustmentBalanceOptions
    },
    pagedBalancePickerRows() {
      return this.filteredBalancePickerRows
    },
    adjustmentAfterOnHand() {
      return Number(this.adjustmentForm.current_on_hand || 0) + this.normalizedAdjustmentQty()
    },
    adjustmentAfterAvailable() {
      return Number(this.adjustmentForm.current_available || 0) + this.normalizedAdjustmentQty()
    },
    adjustmentWillBeNegative() {
      if (!this.adjustmentForm.balance_id || !this.normalizedAdjustmentQty()) return false
      return this.adjustmentAfterOnHand < 0 || this.adjustmentAfterAvailable < 0
    },
    adjustmentIntegerQty() {
      const qty = Number(this.adjustmentForm.change_qty || 0)
      return Number.isInteger(qty) && qty > 0 ? qty : 0
    },
    adjustmentSerialEntries() {
      const values = this.adjustmentForm.direction === 'increase'
        ? this.serialNumberList(this.adjustmentForm.serial_text)
        : (this.adjustmentForm.selected_serial_numbers || [])
      return values.map(value => String(value || '').trim()).filter(Boolean)
    },
    adjustmentSerialHasDuplicates() {
      return new Set(this.adjustmentSerialEntries).size !== this.adjustmentSerialEntries.length
    },
    adjustmentSerialCountValid() {
      const qty = Number(this.adjustmentForm.change_qty || 0)
      const count = this.adjustmentSerialEntries.length
      if (!Number.isInteger(qty) || qty <= 0 || this.adjustmentSerialHasDuplicates) return false
      if (this.adjustmentForm.serial_tracking_mode === 'required') return count === qty
      return count <= qty
    },
    adjustmentSerialPolicyText() {
      if (this.adjustmentForm.direction === 'increase') return '增加库存时可一次生成，也可扫描供应商编号或手工逐行录入。'
      return '减少库存时必须从当前库存对象的可用编号中选择，过账后编号同步转为“已调整出库”。'
    },
    qualityActionBalances() {
      return (this.batchContext.balances || []).filter(row => Number(row.quantity_available || 0) > 0)
    },
    hasQualityActionBalance() {
      return this.qualityActionBalances.length > 0
    },
    qualityMethodTitle() {
      return ({ return_supplier: '冻结后转采购退回处理', exchange: '冻结后转采购换货处理' })[this.qualityForm.handling_method] || '请选择实际处理方式'
    },
    qualityMethodDescription() {
      return ({ return_supplier: '只处理已入库库存，并引用该批次采购来源；未入库不合格品仍在采购到货环节处理。', exchange: '问题实物保持冻结，后续对接正式采购换货单，跟踪原品退回和替换品到货。' })[this.qualityForm.handling_method] || '已入库库存不适用让步接收；返修、报废暂不开放。'
    },
    qualitySerialPlaceholder() {
      if (this.qualityForm.serial_tracking_mode === 'none') return '该 Item 无需序列号，按批次处理'
      if (!this.qualitySerials.length) return '当前批次没有可用序列号'
      return '请选择或搜索设备编号 / 序列号'
    },
    qualitySerialHint() {
      if (this.qualityForm.serial_tracking_mode === 'none') return '该 Item 的序列号策略为“无需编号”，本次按批次和数量定位。'
      if (!this.qualitySerials.length) return '当前批次没有可用编号；请先核对到货入库时的编号登记，不能凭空补录。'
      return '已加载当前库位、当前批次的 ' + this.qualitySerials.length + ' 个可用编号。'
    },
    alertThresholdValid() {
      const form = this.alertConfig
      const required = [form.min_stock, form.safety_stock, form.suggested_replenishment_qty]
        .every(value => value !== null && value !== '' && Number(value) >= 0)
      const maxEmpty = form.max_stock === null || form.max_stock === ''
      return required && Number(form.min_stock) <= Number(form.safety_stock) && (maxEmpty || Number(form.safety_stock) <= Number(form.max_stock))
    }
  },
  watch: {
    '$route.path': { immediate: true, handler() { this.syncActiveView(); this.loadCurrentView() } },
    balancePickerQuery: { deep: true, handler() { this.balancePickerPagination.page = 1 } }
  },
  mounted() {
    this.loadDictionaries()
    this.loadCurrentView()
  },
  methods: {
    async loadDictionaries() {
      const [items, warehouses, locations] = await Promise.all([
        listEntity('items', { per_page: 200 }),
        listEntity('warehouses', { per_page: 200 }),
        listEntity('locations', { per_page: 200 })
      ])
      this.items = items.data.data || []
      this.warehouses = warehouses.data.data || []
      this.locations = locations.data.data || []
      await this.loadAdjustmentReasons()
    },
    async loadAdjustmentReasons() {
      try {
        const reasons = await listInventoryAdjustmentReasons()
        this.adjustmentReasons = reasons.data || []
        this.adjustmentReasonMap = this.adjustmentReasons.reduce((map, reason) => {
          map[reason.value] = reason.label
          return map
        }, {})
      } catch (error) {
        this.adjustmentReasons = []
        this.adjustmentReasonMap = {}
        this.$message.error('调整原因加载失败，请刷新后重试。')
      }
    },
    loadCurrentView() {
      if (this.activeView === 'posting') return this.loadPostingRows()
      if (this.activeView === 'balances') return this.loadBalances()
      if (this.activeView === 'transactions') return this.loadTransactions()
      if (this.activeView === 'adjustments') return this.loadAdjustments()
    },
    async loadPostingRows() {
      const res = await listPendingReceipts({
        page: this.postingPagination.page,
        per_page: this.postingPagination.per_page,
        keyword: this.postingQuery.keyword,
        warehouse_id: this.postingQuery.warehouse,
        date_from: this.postingQuery.dateRange?.[0] || '',
        date_to: this.postingQuery.dateRange?.[1] || ''
      })
      this.postingRows = (res.data.data || []).map(this.mapReceipt)
      this.postingStats = res.data.stats || { posted_today: 0 }
      this.applyPagination(this.postingPagination, res.data)
      this.selectedReceipt = this.selectedReceipt ? this.postingRows.find(r => r.id === this.selectedReceipt.id) || null : null
    },
    async loadBalances() {
      const res = await listInventoryBalances(this.balanceParams())
      this.balanceRows = (res.data.data || []).map(this.mapBalance)
      this.balanceStats = res.data.stats || this.balanceStats
      this.applyPagination(this.balancePagination, res.data)
      this.selectedBalance = this.selectedBalance ? this.balanceRows.find(r => Number(r.item_id) === Number(this.selectedBalance.item_id)) || null : null
    },
    async loadBatchRows() {
      if (!this.selectedBalance) return
      this.batchDialogLoading = true
      try {
        const res = await listInventoryItemBatches(this.selectedBalance.item_id, {
          page: this.batchPagination.page,
          per_page: this.batchPagination.per_page,
          warehouse_id: this.balanceQuery.warehouse,
          location_id: this.balanceQuery.location,
          batch_no: this.balanceQuery.batch,
          inventory_status: this.balanceQuery.status
        })
        this.batchRows = (res.data.data || []).map(row => ({
          ...row,
          quantity_on_hand: Number(row.quantity_on_hand || 0),
          quantity_available: Number(row.quantity_available || 0),
          quantity_locked: Number(row.quantity_locked || 0),
          quantity_pending: Number(row.quantity_pending || 0),
          inventory_value: Number(row.inventory_value || 0),
          warehouse_count: Number(row.warehouse_count || 0),
          location_count: Number(row.location_count || 0)
        }))
        this.applyPagination(this.batchPagination, res.data)
        const current = this.batchRows.find(row => this.selectedBatch && row.batch_no === this.selectedBatch.batch_no) || this.batchRows[0] || null
        await this.selectBatch(current)
      } finally {
        this.batchDialogLoading = false
      }
    },
    async loadBatchContext() {
      if (!this.selectedBalance || !this.selectedBatch) {
        this.batchContext = { balances: [], source: {}, quality_events: [] }
        return
      }
      this.batchContextLoading = true
      try {
        const res = await getInventoryBatchContext(this.selectedBalance.item_id, { batch_no: this.selectedBatch.batch_no })
        this.batchContext = {
          source: res.data.source || {},
          quality_events: res.data.quality_events || [],
          balances: (res.data.balances || []).map(row => ({
            ...row,
            item_code: this.selectedBalance.item_code,
            item_name: this.selectedBalance.item_name,
            unit: this.selectedBalance.unit,
            warehouse_name: row.warehouse?.warehouse_name || '',
            location_name: row.location?.location_name || '',
            quantity_on_hand: Number(row.quantity_on_hand || 0),
            quantity_available: Number(row.quantity_available || 0),
            quantity_locked: Number(row.quantity_locked || 0),
            quantity_pending: Number(row.quantity_pending || 0)
          }))
        }
      } finally {
        this.batchContextLoading = false
      }
    },
    async loadTransactions() {
      const routeItemId = Number(this.$route.query.item_id || 0) || null
      if (routeItemId) this.txQuery.itemId = routeItemId
      const res = await listInventoryTransactions({
        view: 'lines',
        page: this.txPagination.page,
        per_page: this.txPagination.per_page,
        transaction_type: this.txQuery.type,
        source_no: this.txQuery.sourceNo,
        keyword: this.txQuery.keyword,
        item_id: this.txQuery.itemId,
        warehouse_id: this.txQuery.warehouse,
        location_id: this.txQuery.location,
        batch_no: this.txQuery.batch,
        date_from: this.txQuery.dateRange?.[0] || '',
        date_to: this.txQuery.dateRange?.[1] || ''
      })
      this.transactions = (res.data.data || []).map(this.mapTransactionLine)
      this.applyPagination(this.txPagination, res.data)
      this.transactionStats = { ...this.transactionStats, ...(res.data.stats || {}) }
    },
    async loadAdjustments() {
      const res = await listInventoryAdjustments({
        page: this.adjustmentPagination.page,
        per_page: this.adjustmentPagination.per_page,
        adjustment_no: this.adjustmentQuery.no,
        reason: this.adjustmentQuery.reason,
        keyword: this.adjustmentQuery.keyword,
        status: this.adjustmentQuery.status,
        date_from: this.adjustmentQuery.dateRange?.[0] || '',
        date_to: this.adjustmentQuery.dateRange?.[1] || ''
      })
      this.adjustmentRows = (res.data.data || []).map(this.mapAdjustment)
      this.applyPagination(this.adjustmentPagination, res.data)
      this.adjustmentStats = { ...this.adjustmentStats, ...(res.data.stats || {}) }
    },
    async loadBalancePickerRows() {
      const requestSeq = ++this.balancePickerRequestSeq
      const res = await listInventoryBalances(this.balancePickerParams())
      if (requestSeq !== this.balancePickerRequestSeq) return
      this.balancePickerRows = (res.data.data || []).map(this.mapBalance)
      this.applyPagination(this.balancePickerPagination, res.data)
    },
    applyPagination(target, payload) {
      target.page = Number(payload.current_page || 1)
      target.per_page = Number(payload.per_page || target.per_page || 20)
      target.total = Number(payload.total || 0)
    },
    balanceParams() {
      return {
        view: 'item',
        page: this.balancePagination.page,
        per_page: this.balancePagination.per_page,
        keyword: this.balanceQuery.keyword,
        warehouse_id: this.balanceQuery.warehouse,
        location_id: this.balanceQuery.location,
        batch_no: this.balanceQuery.batch,
        inventory_status: this.balanceQuery.status
      }
    },
    balancePickerParams() {
      return {
        page: this.balancePickerPagination.page,
        per_page: this.balancePickerPagination.per_page,
        keyword: this.balancePickerQuery.keyword,
        warehouse_id: this.balancePickerQuery.warehouse,
        location_id: this.balancePickerQuery.location,
        batch_no: this.balancePickerQuery.batch,
        inventory_status: this.balancePickerQuery.status
      }
    },
    searchPostingRows() {
      this.postingPagination.page = 1
      this.loadPostingRows()
    },
    resetPostingQuery() {
      this.postingQuery = { dateRange: [], supplier: '', warehouse: '', keyword: '' }
      this.searchPostingRows()
    },
    handlePostingSizeChange(size) {
      this.postingPagination.per_page = size
      this.postingPagination.page = 1
      this.loadPostingRows()
    },
    searchBalances() {
      this.balancePagination.page = 1
      this.loadBalances()
    },
    resetBalanceQuery() {
      this.balanceQuery = { keyword: '', warehouse: '', location: '', batch: '', status: '' }
      this.searchBalances()
    },
    handleBalanceSizeChange(size) {
      this.balancePagination.per_page = size
      this.balancePagination.page = 1
      this.loadBalances()
    },
    searchTransactions() {
      this.txPagination.page = 1
      this.loadTransactions()
    },
    resetTxQuery() {
      this.txQuery = { type: '', sourceNo: '', keyword: this.$route.query.item_code || '', warehouse: '', location: '', batch: '', dateRange: [], itemId: Number(this.$route.query.item_id || 0) || null }
      this.searchTransactions()
    },
    handleTxSizeChange(size) {
      this.txPagination.per_page = size
      this.txPagination.page = 1
      this.loadTransactions()
    },
    searchAdjustments() {
      this.adjustmentPagination.page = 1
      this.loadAdjustments()
    },
    resetAdjustmentQuery() {
      this.adjustmentQuery = { no: '', reason: '', keyword: '', status: '', dateRange: [] }
      this.searchAdjustments()
    },
    handleAdjustmentSizeChange(size) {
      this.adjustmentPagination.per_page = size
      this.adjustmentPagination.page = 1
      this.loadAdjustments()
    },
    searchBalancePickerRows() {
      this.balancePickerPagination.page = 1
      this.loadBalancePickerRows()
    },
    resetBalancePickerQuery() {
      this.balancePickerQuery = { keyword: '', warehouse: '', location: '', batch: '', status: '' }
      this.searchBalancePickerRows()
    },
    handleBalancePickerSizeChange(size) {
      this.balancePickerPagination.per_page = size
      this.balancePickerPagination.page = 1
      this.loadBalancePickerRows()
    },
    mapReceipt(row) {
      const items = (row.items || []).map(line => {
        const actualBase = Number(line.actual_base_qty ?? line.standard_base_qty ?? line.receipt_qty ?? 0)
        const qualifiedBase = Number(line.qualified_base_qty ?? line.qualified_qty ?? 0)
        const defectiveBase = Number(line.unqualified_base_qty ?? line.unqualified_qty ?? 0)
        const pending = Math.max(0, actualBase - qualifiedBase - defectiveBase)
        const baseUnit = line.base_unit_name_snapshot || line.item?.unit?.unit_name || ''
        return {
          id: line.id,
          item_id: line.item_id,
          item_code: line.item?.item_code || '',
          item_name: line.item?.item_name || '',
          is_serial_managed: Boolean(line.item?.is_serial_managed),
          serial_tracking_mode: line.item?.serial_tracking_mode || (line.item?.is_serial_managed ? 'required' : 'none'),
          serial_text: line.serial_text || '',
          allocations: (line.allocations || []).map(allocation => ({ ...allocation })),
          warehouse_id: line.warehouse_id,
          warehouse_name: line.warehouse?.warehouse_name || '',
          location_id: line.location_id,
          location_name: line.location?.location_name || '',
          batch_no: line.batch_no || '',
          base_unit: baseUnit,
          qualified_qty: qualifiedBase,
          defective_qty: defectiveBase,
          pending_qty: pending,
          qualified_display: `${this.quantityNumber(qualifiedBase)} ${baseUnit}`.trim(),
          defective_display: `${this.quantityNumber(defectiveBase)} ${baseUnit}`.trim(),
          pending_display: `${this.quantityNumber(pending)} ${baseUnit}`.trim()
        }
      })
      const qualifiedQty = items.reduce((n, i) => n + i.qualified_qty, 0)
      const defectiveQty = items.reduce((n, i) => n + i.defective_qty, 0)
      const pendingQty = items.reduce((n, i) => n + i.pending_qty, 0)
      return {
        id: row.id || `item-${row.item_id}`,
        receipt_no: row.receipt_no,
        order_no: row.order?.purchase_order_no || '手工到货',
        has_purchase_order: Boolean(row.order?.purchase_order_no),
        supplier_name: row.supplier?.supplier_name || '',
        receipt_date: row.receipt_date || '',
        qualified_qty: qualifiedQty,
        defective_qty: defectiveQty,
        pending_qty: pendingQty,
        qualified_display: this.quantityBreakdown(items, 'qualified_qty'),
        defective_display: this.quantityBreakdown(items, 'defective_qty'),
        pending_display: this.quantityBreakdown(items, 'pending_qty'),
        non_stock_display: this.quantityBreakdown(items, ['defective_qty', 'pending_qty']),
        posting_status: row.stock_post_status || 'pending',
        posting_eligibility: row.posting_eligibility || null,
        items
      }
    },
    mapBalance(row) {
      const rel = row.item?.sku_relations?.[0] || row.item?.skuRelations?.[0] || {}
      return {
        id: row.id,
        item_id: row.item_id,
        item_code: row.item?.item_code || '',
        item_name: row.item?.item_name || '',
        serial_tracking_mode: row.item?.serial_tracking_mode || (row.item?.is_serial_managed ? 'required' : 'none'),
        is_serial_managed: Boolean(row.item?.is_serial_managed),
        unit_id: row.unit_id || row.item?.unit_id || null,
        unit: row.unit?.unit_name || row.item?.unit?.unit_name || '',
        category: row.item?.item_type || '',
        warehouse_id: null,
        warehouse_name: '',
        location_id: null,
        location_name: '',
        batch_no: '',
        quantity_on_hand: Number(row.quantity_on_hand || 0),
        quantity_available: Number(row.quantity_available || 0),
        quantity_locked: Number(row.quantity_locked || 0),
        quantity_defective: Number(row.quantity_defective || 0),
        quantity_pending: Number(row.quantity_pending || 0),
        inventory_value: Number(row.inventory_value || 0),
        average_unit_cost: Number(row.average_unit_cost || 0),
        last_transaction_at: row.last_transaction_at || '',
        batch_count: Number(row.batch_count || 0),
        balance_count: Number(row.balance_count || 0),
        product_code: rel.sku?.product?.product_code || '',
        sku_code: rel.sku?.sku_code || ''
      }
    },
    handleBalanceRowClick(row) {
      this.selectBalance(row)
      this.balanceDetailVisible = true
      if (this.$refs.balanceTable) {
        this.$refs.balanceTable.clearSelection()
        this.$refs.balanceTable.toggleRowSelection(row, true)
      }
    },
    handleBalanceSelectionChange(selection) {
      this.balanceSelectedRows = selection
      if (selection.length === 1) {
        this.selectBalance(selection[0])
        this.balanceDetailVisible = true
      } else if (selection.length === 0) {
        this.selectedBalance = null
        this.balanceDetailVisible = false
      } else {
        this.selectBalance(selection[selection.length - 1])
        this.balanceDetailVisible = true
      }
    },
    closeBalanceDetail() {
      this.balanceDetailVisible = false
      if (this.$refs.balanceTable) {
        this.$refs.balanceTable.clearSelection()
      }
    },
    exportBalances() {
      this.$message.success('已导出当前库存余额列表')
    },
    async selectBalance(row) {
      if (!row) {
        this.selectedBalance = null
        return
      }
      this.selectedBalance = row
      await this.loadBalanceDetailContext(row)
    },
    async loadBalanceDetailContext(row) {
      if (!row || !row.item_id) return
      try {
        const [batchesRes, txRes] = await Promise.all([
          listInventoryItemBatches(row.item_id, {}),
          listInventoryTransactions({ item_id: row.item_id, per_page: 10, view: 'lines' })
        ])
        this.balanceDetailBatches = (batchesRes.data.data || []).map(b => ({
          ...b,
          quantity_on_hand: Number(b.quantity_on_hand || 0),
          quantity_available: Number(b.quantity_available || 0),
          quantity_locked: Number(b.quantity_locked || 0),
          quantity_defective: Number(b.quantity_defective || 0),
          quantity_pending: Number(b.quantity_pending || 0)
        }))
        this.balanceRecentTransactions = (txRes.data.data || []).map(this.mapTransactionLine)
      } catch (e) {
        // keep fallback
      }
    },
    balanceRowClass({ row }) {
      return this.selectedBalance && Number(row.item_id) === Number(this.selectedBalance.item_id) ? 'current-balance-row' : ''
    },
    defaultAlertConfig() {
      return { enabled: true, min_stock: null, safety_stock: null, max_stock: null, suggested_replenishment_qty: null }
    },
    async openAlertConfig() {
      if (!this.selectedBalance || !this.selectedBalance.item_id) return this.$message.warning('请先在库存余额列表选择一个 Item。')
      this.alertConfigItem = this.selectedBalance
      this.alertConfig = this.defaultAlertConfig()
      this.alertConfigVisible = true
      this.alertConfigLoading = true
      try {
        const response = await getInventoryAlertPolicy(this.selectedBalance.item_id)
        const policies = response.data.policies || []
        const company = policies.find(policy => policy.scope_key === 'company')
        if (company) this.alertConfig = {
          ...this.defaultAlertConfig(),
          ...company,
          enabled: Boolean(company.is_enabled)
        }
      } catch (error) {
        this.$message.error(error.userMessage || '库存预警配置加载失败。')
      } finally {
        this.alertConfigLoading = false
      }
    },
    resetAlertConfig() {
      this.alertConfigItem = null
      this.alertConfig = this.defaultAlertConfig()
      this.alertConfigLoading = false
      this.alertConfigSaving = false
    },
    async saveAlertConfig() {
      if (!this.alertConfigItem) return
      if (!this.alertThresholdValid) return this.$message.error('最低库存、安全库存、建议补货数量为必填；最高库存为空或不得低于安全库存。')
      this.alertConfigSaving = true
      try {
        if (this.alertConfig.enabled) {
          await activateInventoryAlertPolicy(this.alertConfigItem.item_id, { ...this.alertConfig, enabled: true })
        } else {
          await disableInventoryAlertPolicy(this.alertConfigItem.item_id)
        }
        this.$message.success(this.alertConfig.enabled ? '库存预警配置已保存并启用。' : '库存预警已停用，历史记录仍可追溯。')
        this.alertConfigVisible = false
      } catch (error) {
        this.$message.error(error.userMessage || '库存预警配置保存失败。')
      } finally {
        this.alertConfigSaving = false
      }
    },
    async openBatchDialog(row) {
      if (!row) return
      this.selectedBalance = row
      this.batchPagination.page = 1
      this.selectedBatch = null
      this.batchRows = []
      this.batchContext = { balances: [], source: {}, quality_events: [] }
      this.batchDialogVisible = true
      await this.loadBatchRows()
    },
    async openAdjustmentForBalance(row) {
      if (!row || !row.item_id) return
      await this.openBatchDialog(row)
      this.$message.info('请在已打开的批次中选择实际仓库与库位后，再执行库存调整。')
    },
    async selectBatch(row) {
      if (!row) {
        this.selectedBatch = null
        this.batchContext = { balances: [], source: {}, quality_events: [] }
        return
      }
      this.selectedBatch = row
      await this.loadBatchContext()
    },
    handleBatchSizeChange(size) {
      this.batchPagination.per_page = size
      this.batchPagination.page = 1
      this.loadBatchRows()
    },
    resetBatchDialog() {
      this.batchRows = []
      this.selectedBatch = null
      this.batchContext = { balances: [], source: {}, quality_events: [] }
    },
    batchRowClass({ row }) {
      return this.selectedBatch && row.batch_no === this.selectedBatch.batch_no ? 'current-batch-row' : ''
    },
    viewItemTransactions(row) {
      if (!row || !row.item_id) return
      this.batchDialogVisible = false
      this.$router.push({ path: '/inventory/transactions', query: { item_id: row.item_id, item_code: row.item_code } })
    },
    async openBalanceSerials(row) {
      this.serialDialogBalance = row
      this.serialKeyword = ''
      this.serialRows = []
      this.serialPagination.page = 1
      this.serialPagination.total = 0
      this.serialDialogVisible = true
      await this.loadBalanceSerials()
    },
    async loadBalanceSerials() {
      if (!this.serialDialogBalance) return
      this.serialDialogLoading = true
      try {
        const response = await listInventoryBalanceSerials(this.serialDialogBalance.id, {
          keyword: String(this.serialKeyword || '').trim(),
          page: this.serialPagination.page,
          per_page: this.serialPagination.per_page
        })
        const page = response.data || {}
        this.serialRows = page.data || []
        this.serialPagination.total = Number(page.total || 0)
        this.serialPagination.page = Number(page.current_page || 1)
      } finally {
        this.serialDialogLoading = false
      }
    },
    searchBalanceSerials() {
      this.serialPagination.page = 1
      this.loadBalanceSerials()
    },
    handleSerialSizeChange(size) {
      this.serialPagination.per_page = size
      this.serialPagination.page = 1
      this.loadBalanceSerials()
    },
    serialStatusText(value) {
      if (value === 'adjusted_out') return '已调整出库'
      return ({ pending_posting: '待过账', available: '可用', reserved: '已锁定', outbound: '已出库', quality_hold: '质量冻结', returned: '已退回', scrapped: '已报废' })[value] || value || '-'
    },
    async openBatchQuality(method) {
      if (!this.qualityActionBalances.length) return this.$message.warning('当前批次没有可用于质量处理的库存对象。')
      if (this.qualityActionBalances.length > 1) return this.$message.warning('当前批次分布在多个仓库或库位，请在库存分布对应行点击“质量处理”。')
      const row = this.qualityActionBalances[0]
      await this.openBatchBalanceQuality(row, method)
    },
    async openBatchBalanceQuality(row, method = '') {
      await this.openQualityDrawer(row, method)
    },
    quantityNumber(value) {
      return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 })
    },
    money(value) {
      return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    dateTime(value) {
      return value ? String(value).replace('T', ' ').replace(/\.\d+Z?$/, '').replace(/Z$/, '').slice(0, 19) : '--'
    },
    mapTransaction(row) {
      return (row.items || []).map(line => ({
        id: line.id,
        tx_no: row.transaction_no,
        transaction_type: row.transaction_type,
        transaction_type_text: this.transactionTypeText(row.transaction_type),
        source_type_text: this.sourceTypeText(row.source_type),
        source_no: row.source_no,
        item_code: line.item_code,
        item_name: line.item_name,
        warehouse_name: line.warehouse?.warehouse_name || '',
        location_name: line.location?.location_name || '',
        batch_no: line.batch_no,
        change_qty: Number(line.change_qty || 0),
        balance_qty: Number(line.balance_after_qty || 0),
        unit: line.item?.unit?.unit_name || '',
        posting_status_text: this.postingStatusText(row.posting_status),
        posted_by: row.posted_by || '系统',
        posted_at: this.dateTime(row.posted_at || row.created_at)
      }))
    },
    mapTransactionLine(line) {
      const row = line.transaction || {}
      return {
        id: line.id,
        tx_no: row.transaction_no,
        transaction_type: row.transaction_type,
        transaction_type_text: this.transactionTypeText(row.transaction_type),
        source_type_text: this.sourceTypeText(row.source_type),
        source_no: row.source_no,
        item_code: line.item_code,
        item_name: line.item_name,
        warehouse_name: line.warehouse?.warehouse_name || '',
        location_name: line.location?.location_name || '',
        batch_no: line.batch_no,
        change_qty: Number(line.change_qty || 0),
        balance_qty: Number(line.balance_after_qty || 0),
        unit: line.item?.unit?.unit_name || '',
        posting_status_text: this.postingStatusText(row.posting_status),
        posted_by: row.posted_by || '系统',
        posted_at: this.dateTime(row.posted_at || row.created_at)
      }
    },
    mapAdjustment(row) {
      const first = (row.items || [])[0] || {}
      return {
        id: row.id,
        adjustment_no: row.adjustment_no,
        status: row.adjustment_status,
        reason: row.reason || '-',
        reason_label: this.adjustmentReasonLabel(row.reason),
        item_code: first.item?.item_code || '',
        item_name: first.item?.item_name || '',
        warehouse_name: first.warehouse?.warehouse_name || '',
        location_name: first.location?.location_name || '',
        batch_no: first.batch_no || '',
        serial_numbers: (first.serials || []).map(serial => serial.serial_no),
        change_qty: Number(first.change_qty || 0),
        direction_text: Number(first.change_qty || 0) >= 0 ? '增加' : '减少',
        created_at: this.dateTime(row.created_at || row.updated_at),
        updated_at: this.dateTime(row.updated_at || row.created_at)
      }
    },
    defaultAdjustmentForm() {
      return {
        reason: '',
        balance_id: null,
        item_id: null,
        item_code: '',
        item_name: '',
        unit_id: null,
        unit_name: '',
        warehouse_id: null,
        warehouse_name: '',
        location_id: null,
        location_name: '',
        batch_no: '',
        current_on_hand: 0,
        current_available: 0,
        current_locked: 0,
        current_defective: 0,
        current_pending: 0,
        serial_tracking_mode: 'none',
        serial_text: '',
        serial_number_source: 'manual',
        selected_serial_numbers: [],
        change_qty: 0,
        direction: 'increase',
        remark: ''
      }
    },
    defaultQualityForm() {
      return { inventory_balance_id: null, item_code: '', item_name: '', unit_name: '', warehouse_name: '', location_name: '', batch_no: '', current_available: 0, serial_tracking_mode: 'none', serial_no: '', issue_qty: 1, issue_category: '', issue_description: '', handling_method: '', responsible_party: 'undetermined', attachments: [] }
    },
    async openQualityDrawer(row, method = '') {
      if (!row || Number(row.quantity_available || 0) <= 0) return this.$message.warning('当前库存没有可用数量，不能重复发起质量处理。')
      this.qualityForm = { ...this.defaultQualityForm(), inventory_balance_id: row.id, item_code: row.item_code, item_name: row.item_name, unit_name: row.unit, warehouse_name: row.warehouse_name, location_name: row.location_name, batch_no: row.batch_no, current_available: Number(row.quantity_available || 0), issue_qty: 1, handling_method: method }
      this.qualitySource = {}
      this.qualitySerials = []
      this.qualityEvents = []
      this.qualityDrawerVisible = true
      try {
        const response = await getInventoryQualityContext(row.id)
        this.qualitySource = response.data.source || {}
        this.qualitySerials = response.data.balance?.serials || []
        this.qualityForm.serial_tracking_mode = response.data.balance?.item?.serial_tracking_mode
          || (response.data.balance?.item?.is_serial_managed ? 'required' : 'none')
        this.qualityEvents = response.data.active_events || []
      } catch (error) {
        this.$message.error(error.userMessage || '库存来源追溯加载失败')
      }
    },
    async submitQualityEvent() {
      if (!this.qualityForm.inventory_balance_id) return
      if (!this.qualityForm.issue_category || !this.qualityForm.issue_description || !this.qualityForm.handling_method || !this.qualityForm.responsible_party) return this.$message.warning('请完整填写问题类别、问题描述、处理方式和责任方。')
      if (['return_supplier', 'exchange'].includes(this.qualityForm.handling_method) && !this.qualitySource.receipt_no) return this.$message.warning('当前库存没有可追溯采购来源，不能发起退供应商或换货。')
      this.qualitySaving = true
      try {
        const response = await createInventoryQualityEvent({ inventory_balance_id: this.qualityForm.inventory_balance_id, serial_no: this.qualityForm.serial_no || null, issue_qty: Number(this.qualityForm.issue_qty), issue_category: this.qualityForm.issue_category, issue_description: this.qualityForm.issue_description, handling_method: this.qualityForm.handling_method, responsible_party: this.qualityForm.responsible_party, attachments: this.qualityForm.attachments })
        const event = response.data.data
        this.$message.success(`质量事件 ${event.event_no} 已创建，处理单 ${event.business_doc_no}，问题库存已冻结。`)
        this.qualityDrawerVisible = false
        await this.loadBalances()
      } catch (error) {
        this.$message.error(error.userMessage || '库存质量事件创建失败')
      } finally {
        this.qualitySaving = false
      }
    },
    qualityHandlingText(method) {
      return ({ return_supplier: '退供应商', exchange: '换货' })[method] || method
    },
    qualityStatusText(status) {
      return ({ pending_supplier_return: '待退供应商', processing_return_supplier: '退供处理中', pending_exchange: '待换货', processing_exchange: '换货中', completed: '已完成', cancelled: '已取消' })[status] || status
    },
    viewQualityBusinessDocument(row) {
      if (!row || !row.business_doc_id) return this.$message.warning('当前质量事件尚未生成正式处理单据。')
      this.batchDialogVisible = false
      if (row.business_doc_type === 'purchase_return') {
        this.$router.push('/purchase/returns/' + row.business_doc_id + '/detail')
      } else if (row.business_doc_type === 'purchase_exchange_order') {
        this.$router.push('/purchase/exchanges/' + row.business_doc_id)
      } else {
        this.$message.warning('当前处理单据类型暂未配置查看入口。')
      }
    },
    syncActiveView() {
      const path = this.$route.path
      const previousView = this.activeView
      if (path.includes('/balances')) this.activeView = 'balances'
      else if (path.includes('/transactions')) this.activeView = 'transactions'
      else if (path.includes('/adjustments')) this.activeView = 'adjustments'
      else this.activeView = 'posting'
      if (previousView !== this.activeView) {
        this.postingDialogVisible = false
        this.postingCandidate = null
        if (!['balances', 'adjustments'].includes(this.activeView)) this.adjustmentDrawerVisible = false
      }
    },
    selectReceipt(row) { if (row) this.selectedReceipt = row },
    viewAdjustment(row) {
      this.selectedAdjustment = row
      this.adjustmentDetailVisible = true
    },
    resetPostingRows() {
      this.loadPostingRows()
      this.$message.success('待过账列表已刷新')
    },
    postingStatusText(status) {
      return ({ pending: '待库存过账', posted: '已库存过账', failed: '过账失败', cancelled: '已取消' })[status] || '未知'
    },
    postingStatusType(status) {
      return ({ pending: 'warning', posted: 'success', failed: 'danger', cancelled: 'info' })[status] || 'info'
    },
    transactionTypeText(type) {
      return ({ purchase_receipt_posting: '采购到货入库', purchase_return_outbound: '采购退货出库', inventory_quality_outbound: '库存质量换货原品出库', manual_adjustment: '手工库存调整' })[type] || '业务库存事务'
    },
    sourceTypeText(type) {
      return ({ purchase_receipt: '采购到货单', purchase_return: '采购退货单', inventory_quality_event: '库存质量事件', inventory_adjustment: '手工调整单' })[type] || '业务来源'
    },
    openPostingConfirm(row) {
      this.selectReceipt(row)
      if (row.posting_status !== 'pending') return this.$message.warning('该到货单已过账，不能重复过账。')
      if (Number(row.qualified_qty || 0) <= 0) return this.$message.warning('合格数量必须大于 0 才能产生正常入库流水。')
      const blocked = this.postingBlockedReason(row)
      if (blocked) return this.$message.warning(blocked)
      this.postingCandidate = { ...row, items: row.items.map(item => ({ ...item })) }
      this.postingDialogVisible = true
    },
    postingBlockedReason(row) {
      if (!row || row.posting_status !== 'pending') return row && row.posting_status !== 'pending' ? '该到货单已过账' : ''
      if (row.posting_eligibility && row.posting_eligibility.can_post === false) return row.posting_eligibility.reason_text || '到货入库资料不完整'
      const missing = (row.items || []).find(item => Number(item.qualified_qty || 0) > 0 && !(item.allocations || []).length)
      return missing ? `${missing.item_code || '该物料'} 尚未完成入库库位分配` : ''
    },
    openPostingRepair(row) {
      this.selectReceipt(row)
      const candidate = JSON.parse(JSON.stringify(row))
      candidate.items = (candidate.items || []).filter(line => Number(line.qualified_qty || 0) > 0).map(line => ({
        ...line,
        allocations: (line.allocations || []).length ? line.allocations.map(allocation => ({ ...allocation, serial_nos: [...(allocation.serial_nos || [])] })) : [{ warehouse_id: null, location_id: null, base_qty: Number(line.qualified_qty || 0), serial_nos: [] }]
      }))
      this.postingRepairCandidate = candidate
      this.postingRepairVisible = true
    },
    addPostingRepairAllocation(line) {
      line.allocations.push({ warehouse_id: null, location_id: null, base_qty: 0, serial_nos: [] })
    },
    removePostingRepairAllocation(line, index) {
      if (line.allocations.length > 1) line.allocations.splice(index, 1)
    },
    postingSerialAssignedElsewhere(line, current, serial) {
      return (line.allocations || []).some(allocation => allocation !== current && (allocation.serial_nos || []).includes(serial))
    },
    postingRepairLineComplete(line) {
      const allocations = line.allocations || []
      if (!allocations.length || allocations.some(row => !row.warehouse_id || !row.location_id || Number(row.base_qty || 0) <= 0)) return false
      const allocated = allocations.reduce((sum, row) => sum + Number(row.base_qty || 0), 0)
      if (Math.abs(allocated - Number(line.qualified_qty || 0)) > 0.00000001) return false
      if (line.serial_tracking_mode !== 'none' && this.serialNumberList(line.serial_text).length) {
        const assigned = allocations.reduce((all, row) => all.concat(row.serial_nos || []), [])
        if (new Set(assigned).size !== assigned.length || assigned.length !== this.serialNumberList(line.serial_text).length) return false
        if (allocations.some(row => (row.serial_nos || []).length !== Math.round(Number(row.base_qty || 0)))) return false
      }
      return true
    },
    async savePostingRepair() {
      const row = this.postingRepairCandidate
      if (!row || !(row.items || []).length) return
      const invalid = row.items.find(line => !this.postingRepairLineComplete(line))
      if (invalid) return this.$message.warning(`请完整分配 ${invalid.item_code || '物料'} 的仓库、库位和数量。`)
      this.postingRepairSaving = true
      try {
        await repairPostingReceiptAllocations(row.id, {
          items: row.items.map(line => ({ receipt_item_id: line.id, allocations: line.allocations.map(allocation => ({ warehouse_id: allocation.warehouse_id, location_id: allocation.location_id, base_qty: allocation.base_qty, serial_nos: allocation.serial_nos || [] })) }))
        })
        this.postingRepairVisible = false
        this.$message.success('入库分配已补充，正在重新检查过账条件。')
        await this.loadPostingRows()
        const refreshed = this.postingRows.find(item => Number(item.id) === Number(row.id))
        if (refreshed) this.selectedReceipt = refreshed
      } catch (error) {
        this.$message.error(error.userMessage || '入库分配保存失败')
      } finally {
        this.postingRepairSaving = false
      }
    },
    postingAllocationSummary(line) {
      const allocations = line.allocations || []
      if (!allocations.length) return `${line.warehouse_name || '-'} / ${line.location_name || '-'}`
      return `${allocations.length} 个库位 / ${allocations.reduce((sum, row) => sum + Number(row.base_qty || 0), 0)} ${line.base_unit}`
    },
    quantityBreakdown(items, keys) {
      const fields = Array.isArray(keys) ? keys : [keys]
      const groups = items.reduce((map, item) => {
        const unit = item.base_unit || '基本单位'
        const qty = fields.reduce((sum, key) => sum + Number(item[key] || 0), 0)
        map[unit] = Number(map[unit] || 0) + qty
        return map
      }, {})
      const nonZero = Object.entries(groups).filter(([, qty]) => Number(qty) !== 0)
      if (!nonZero.length) return '0'
      return nonZero.map(([unit, qty]) => `${this.quantityNumber(qty)} ${unit}`).join(' + ')
    },
    filteredPostingLocations(warehouseId) {
      return this.locations.filter(location => Number(location.warehouse_id) === Number(warehouseId) && ['active', 'enabled'].includes(location.status))
    },
    async confirmPosting() {
      const row = this.postingCandidate
      if (!row) return
      if (row.posting_status !== 'pending') {
        this.postingDialogVisible = false
        return this.$message.warning('该到货单已过账，不能重复过账。')
      }
      const blocked = this.postingBlockedReason(row)
      if (blocked) return this.$message.warning(blocked)
      if (row.items.some(item => !String(item.batch_no || '').trim())) return this.$message.warning('到货明细缺少批次号，不能过账。')
      try {
        await postPostingReceipt(row.id)
        this.postingDialogVisible = false
        this.$message.success('库存过账完成：已生成库存流水并更新库存余额。')
        await Promise.all([this.loadPostingRows(), this.loadBalances(), this.loadTransactions()])
      } catch (error) {
        if ((error.userMessage || '').includes('已过账')) {
          this.postingDialogVisible = false
          this.postingCandidate = null
          await this.loadPostingRows()
        }
        this.$message.error(error.userMessage || '库存过账失败')
      }
    },
    activeViewNotice(message) { this.$message.info(message) },
    serialNumberList(value) { return String(value || '').split(/[\r\n,;，；]+/).map(v => v.trim()).filter(Boolean) },
    async openAdjustmentFromBalance(row) {
      if (!this.adjustmentReasons.length) await this.loadAdjustmentReasons()
      if (!this.balanceRows.length) await this.loadBalances()
      this.selectBalance(row || this.selectedBalance)
      this.adjustmentForm = this.defaultAdjustmentForm()
      this.selectedAdjustmentBalance = null
      if (this.selectedBalance) this.applyAdjustmentBalance(this.selectedBalance)
      this.adjustmentDrawerVisible = true
    },
    async openAdjustmentDrawer() {
      if (!this.adjustmentReasons.length) await this.loadAdjustmentReasons()
      if (!this.balanceRows.length) await this.loadBalances()
      this.adjustmentForm = this.defaultAdjustmentForm()
      this.selectedAdjustmentBalance = null
      this.adjustmentDrawerVisible = true
    },
    async openBalancePicker() {
      this.balancePickerPagination.page = 1
      this.balancePickerVisible = true
      await this.loadBalancePickerRows()
    },
    chooseBalanceFromPicker(row) {
      this.applyAdjustmentBalance(row)
      this.balancePickerVisible = false
    },
    applyAdjustmentBalanceById(balanceId) {
      const balance = this.balanceRows.find(row => Number(row.id) === Number(balanceId))
      this.applyAdjustmentBalance(balance)
    },
    applyAdjustmentBalance(balance) {
      if (!balance) return
      this.selectedAdjustmentBalance = balance
      this.adjustmentForm = {
        ...this.adjustmentForm,
        balance_id: balance.id,
        item_id: balance.item_id,
        item_code: balance.item_code,
        item_name: balance.item_name,
        unit_id: balance.unit_id,
        unit_name: balance.unit,
        warehouse_id: balance.warehouse_id,
        warehouse_name: balance.warehouse_name,
        location_id: balance.location_id,
        location_name: balance.location_name,
        batch_no: balance.batch_no,
        current_on_hand: Number(balance.quantity_on_hand || 0),
        current_available: Number(balance.quantity_available || 0),
        current_locked: Number(balance.quantity_locked || 0),
        current_defective: Number(balance.quantity_defective || 0),
        current_pending: Number(balance.quantity_pending || 0),
        serial_tracking_mode: balance.serial_tracking_mode || 'none',
        serial_text: '',
        selected_serial_numbers: []
      }
      this.adjustmentSerialOptions = []
      this.adjustmentSerialTotal = 0
      if (this.adjustmentForm.serial_tracking_mode !== 'none') this.loadAdjustmentSerialOptions()
    },
    handleAdjustmentDirectionChange() {
      this.adjustmentForm.serial_text = ''
      this.adjustmentForm.selected_serial_numbers = []
      if (this.adjustmentForm.direction === 'decrease' && this.adjustmentForm.serial_tracking_mode !== 'none') this.loadAdjustmentSerialOptions()
    },
    async loadAdjustmentSerialOptions(keyword = '') {
      if (!this.adjustmentForm.balance_id || this.adjustmentForm.serial_tracking_mode === 'none') {
        this.adjustmentSerialOptions = []
        this.adjustmentSerialTotal = 0
        return
      }
      this.adjustmentSerialLoading = true
      try {
        const response = await listInventoryBalanceSerials(this.adjustmentForm.balance_id, {
          keyword: String(keyword || '').trim(),
          serial_status: 'available',
          page: 1,
          per_page: 100
        })
        const page = response.data || {}
        this.adjustmentSerialOptions = page.data || []
        this.adjustmentSerialTotal = Number(page.total || 0)
      } catch (error) {
        this.$message.error(error.userMessage || '可用设备编号加载失败')
      } finally {
        this.adjustmentSerialLoading = false
      }
    },
    searchAdjustmentSerials(keyword) {
      this.loadAdjustmentSerialOptions(keyword)
    },
    async generateAdjustmentSerials() {
      if (!this.adjustmentForm.item_id || !this.adjustmentIntegerQty) return this.$message.warning('请先选择库存对象，并输入大于 0 的整数调整数量。')
      this.adjustmentSerialGenerating = true
      try {
        const response = await generateInventoryAdjustmentSerials({ item_id: this.adjustmentForm.item_id, quantity: this.adjustmentIntegerQty })
        this.adjustmentForm.serial_text = (response.data.data || []).join('\n')
        this.adjustmentForm.serial_number_source = 'system'
        this.$message.success(`已一次生成 ${this.adjustmentSerialEntries.length} 个唯一编号`)
      } catch (error) {
        this.$message.error(error.userMessage || '设备编号生成失败')
      } finally {
        this.adjustmentSerialGenerating = false
      }
    },
    async saveAdjustmentDraft() {
      this.syncAdjustmentQtyFromInput()
      if (!this.validateAdjustmentForm()) return
      try {
        await saveInventoryAdjustment(this.adjustmentPayload())
        this.$message.success('已保存草稿')
        this.adjustmentDrawerVisible = false
        await Promise.all([this.loadAdjustments(), this.loadBalances(), this.loadTransactions()])
      } catch (error) {
        this.$message.error(error.userMessage || '保存调整单失败')
      }
    },
    async submitAdjustment() {
      this.syncAdjustmentQtyFromInput()
      if (!this.validateAdjustmentForm()) return
      try {
        await this.$confirm('确认提交当前调整单？提交后不能再编辑，只能确认过账或取消。', '提交调整', { type: 'warning' })
        const saved = await saveInventoryAdjustment(this.adjustmentPayload())
        await submitInventoryAdjustment(saved.data.data.id)
        this.$message.success('已提交调整，待确认调整过账。')
        this.adjustmentDrawerVisible = false
        await Promise.all([this.loadAdjustments(), this.loadBalances(), this.loadTransactions()])
      } catch (error) {
        if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '提交调整单失败')
      }
    },
    async postAdjustment(adj) {
      try {
        await this.$confirm(`确认过账调整单 ${adj.adjustment_no}？过账后将生成库存流水并改变库存余额，不能直接撤销。`, '确认调整过账', { type: 'warning' })
        await postInventoryAdjustment(adj.id)
        this.$message.success('调整已过账，已生成库存流水。')
        await Promise.all([this.loadAdjustments(), this.loadBalances(), this.loadTransactions()])
        this.adjustmentDetailVisible = false
      } catch (error) {
        if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '调整过账失败')
      }
    },
    async submitExistingAdjustment(adj) {
      try {
        await this.$confirm(`确认提交调整单 ${adj.adjustment_no}？提交后不能再编辑。`, '提交调整', { type: 'warning' })
        await submitInventoryAdjustment(adj.id)
        this.$message.success('调整单已提交，等待确认过账。')
        await this.loadAdjustments()
        this.adjustmentDetailVisible = false
      } catch (error) {
        if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '提交调整单失败')
      }
    },
    async cancelAdjustment(adj) {
      try {
        await this.$confirm(`确认取消调整单 ${adj.adjustment_no}？取消后不改变库存，单据转为只读。`, '取消调整', { type: 'warning' })
        await cancelInventoryAdjustment(adj.id)
        this.$message.success('调整单已取消，未改变库存。')
        this.adjustmentDetailVisible = false
        await this.loadAdjustments()
      } catch (error) {
        if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '取消调整单失败')
      }
    },
    async deleteAdjustment(adj) {
      try {
        await this.$confirm(`确认删除草稿调整单 ${adj.adjustment_no}？删除后无法恢复。`, '删除草稿', { type: 'warning' })
        await deleteInventoryAdjustment(adj.id)
        this.$message.success('草稿调整单已删除。')
        this.adjustmentDetailVisible = false
        await this.loadAdjustments()
      } catch (error) {
        if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '删除草稿调整单失败')
      }
    },
    validateAdjustmentForm() {
      if (!this.adjustmentForm.reason) {
        this.$message.warning('请选择调整原因。')
        return false
      }
      if (!this.adjustmentReasonMap[this.adjustmentForm.reason]) {
        this.$message.warning('调整原因加载失败，请刷新后重试。')
        return false
      }
      if (!this.adjustmentForm.balance_id || !this.adjustmentForm.item_id || !this.adjustmentForm.warehouse_id || !this.adjustmentForm.location_id) {
        this.$message.warning('请先从真实库存余额中选择一个物料 + 仓库 + 库位 + 批次对象。')
        return false
      }
      if (Number(this.adjustmentForm.change_qty || 0) <= 0) {
        this.$message.warning('调整数量必须大于 0。')
        return false
      }
      if (this.adjustmentWillBeNegative) {
        this.$message.warning('调整后不能出现负库存，请重新输入数量。')
        return false
      }
      if (this.adjustmentForm.serial_tracking_mode !== 'none') {
        const qty = Number(this.adjustmentForm.change_qty || 0)
        if (!Number.isInteger(qty)) {
          this.$message.warning('涉及设备编号或序列号时，调整数量必须为整数。')
          return false
        }
        if (this.adjustmentSerialHasDuplicates) {
          this.$message.warning('设备编号或序列号存在重复，请逐件核对。')
          return false
        }
        if (!this.adjustmentSerialCountValid) {
          const policy = this.adjustmentForm.serial_tracking_mode === 'required'
            ? `必须录入或选择与调整数量一致的 ${qty} 个唯一编号。`
            : '编号数量不能超过调整数量。'
          this.$message.warning(policy)
          return false
        }
      }
      return true
    },
    syncAdjustmentQty(value) {
      if (value !== undefined && value !== null && value !== '') this.adjustmentForm.change_qty = Number(value)
    },
    syncAdjustmentQtyFromNative(event) {
      const value = event && event.target ? event.target.value : ''
      if (value !== '') this.adjustmentForm.change_qty = Number(value)
    },
    syncAdjustmentQtyFromInput() {
      const component = this.$refs.adjustmentQtyInput
      const input = component && component.$el && component.$el.querySelector('input')
      if (input && input.value !== '') this.adjustmentForm.change_qty = Number(input.value)
    },
    adjustmentPayload() {
      return {
        reason: this.adjustmentForm.reason,
        remark: this.adjustmentForm.remark,
        items: [{
          item_id: this.adjustmentForm.item_id,
          warehouse_id: this.adjustmentForm.warehouse_id,
          location_id: this.adjustmentForm.location_id,
          batch_no: this.adjustmentForm.batch_no,
          change_qty: this.normalizedAdjustmentQty(),
          unit_id: this.adjustmentForm.unit_id,
          remark: this.adjustmentForm.remark,
          serial_entries: this.adjustmentSerialEntries.map(serialNo => ({
            serial_no: serialNo,
            source: this.adjustmentForm.direction === 'increase' ? this.adjustmentForm.serial_number_source : 'manual'
          }))
        }]
      }
    },
    normalizedAdjustmentQty() {
      const qty = Math.abs(Number(this.adjustmentForm.change_qty || 0))
      return this.adjustmentForm.direction === 'decrease' ? -qty : qty
    },
    adjustmentStatusText(status) {
      return ({ draft: '草稿', submitted: '已提交', posted: '已过账', cancelled: '已取消' })[status] || status
    },
    adjustmentStatusType(status) {
      return ({ draft: 'info', submitted: 'warning', posted: 'success', cancelled: 'info' })[status] || 'info'
    },
    adjustmentReasonLabel(reason) {
      if (this.adjustmentReasonMap[reason]) return this.adjustmentReasonMap[reason]
      if (!reason || String(reason).includes('?') || /^[a-z_]+$/.test(String(reason))) return '其他'
      return reason
    }
  }
}
</script>

<style scoped>
.inventory-page { padding: 16px 20px 24px; color: #1f2937; background: #f6f8fa; min-height: calc(100vh - 58px); font-size: 13px; }
.inventory-tabs { display: flex; gap: 0; border: 1px solid #e5e7eb; background: #fff; margin-bottom: 14px; }
.inventory-tabs a { padding: 12px 24px; color: #374151; text-decoration: none; border-right: 1px solid #e5e7eb; font-weight: 600; }
.inventory-tabs a.router-link-active { color: #059669; border-bottom: 2px solid #059669; background: #f8fffb; }
.page-head { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 14px; }
.page-head h1 { margin: 0; font-size: 22px; line-height: 32px; color: #111827; }
.page-head h1 small { margin-left: 8px; font-size: 13px; color: #6b7280; font-weight: 500; }
.page-head p { margin: 4px 0 0; color: #6b7280; }
.metric-grid { display: grid; gap: 12px; margin-bottom: 14px; }
.metric-grid.four { grid-template-columns: repeat(4, minmax(0, 1fr)); }
.metric-grid article { display: grid; grid-template-columns: 48px 1fr; grid-template-rows: 22px 30px; align-items: center; min-height: 74px; padding: 14px 16px; background: #fff; border: 1px solid #e5e7eb; border-radius: 4px; }
.metric-grid > article > i { grid-row: span 2; width: 38px; height: 38px; border-radius: 10px; display: grid; place-items: center; font-size: 22px; background: #ecfdf5; }
.metric-grid > article > span > i { display: inline; width: auto; height: auto; margin-left: 4px; border-radius: 0; background: transparent; color: #6b7280; font-size: 13px; line-height: 1; vertical-align: baseline; }
.metric-grid span { color: #4b5563; }
.metric-grid strong { font-size: 24px; color: #111827; }
.green, .green-text { color: #059669; }
.blue { color: #2563eb; background: #eff6ff !important; }
.orange, .orange-text { color: #d97706; }
.red, .red-text { color: #dc2626; }
.purple { color: #7c3aed; background: #f5f3ff !important; }
.filter-panel { display: flex; flex-wrap: wrap; gap: 12px; align-items: end; padding: 12px 14px; background: #fff; border: 1px solid #e5e7eb; border-radius: 4px; margin-bottom: 14px; }
.filter-panel label { display: flex; align-items: center; gap: 8px; color: #374151; white-space: nowrap; }
.filter-panel .el-input, .filter-panel .el-select { width: 168px; }
.posting-filter .el-date-editor { width: 240px; }
.posting-layout, .balance-layout { display: grid; grid-template-columns: minmax(0, 1fr) 430px; gap: 12px; }
.tx-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 12px; }
.table-card, .side-card { background: #fff; border: 1px solid #e5e7eb; border-radius: 4px; overflow: hidden; }
.side-card { padding: 14px; }
.side-card h3 { margin: 0 0 12px; font-size: 15px; }
.side-card h4 { margin: 16px 0 10px; font-size: 13px; border-top: 1px solid #edf0f2; padding-top: 12px; }
.detail-dl { display: grid; grid-template-columns: 84px 1fr 84px 1fr; gap: 10px 12px; margin: 0 0 10px; }
.detail-dl dt { color: #6b7280; }
.detail-dl dd { margin: 0; color: #111827; font-weight: 600; }
.rule-alert { margin-top: 12px; }
.operation-note { margin-top: 14px; padding: 12px; border: 1px solid #e5e7eb; border-radius: 4px; color: #4b5563; }
.operation-note p { margin: 6px 0 0; }
.row-radio { display: inline-block; width: 13px; height: 13px; border: 1px solid #cfd6dd; border-radius: 50%; }
.row-radio.active { border-color: #059669; box-shadow: inset 0 0 0 3px #fff; background: #059669; }
.table-footer, .table-actions { display: flex; align-items: center; justify-content: space-between; padding: 10px 12px; color: #4b5563; }
.table-actions .el-button + .el-button { margin-left: 8px; }
.row-actions { display: inline-flex; align-items: center; gap: 10px; white-space: nowrap; }
.row-actions .el-button { margin: 0; padding: 0; }
.row-actions-wide .el-button { padding: 7px 10px; }
.readonly-text { color: #6b7280; font-size: 12px; }
.balance-instruction-banner { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #fffbe6; border: 1px solid #ffe58f; border-radius: 4px; color: #8c6b00; font-size: 13px; margin-bottom: 14px; }
.info-icon-blue { color: #1890ff; font-size: 15px; }
.btn-green-search { background-color: #008b4b !important; border-color: #008b4b !important; color: #fff !important; }
.btn-expand-text { color: #008b4b; font-size: 13px; margin-left: 4px; }
.balance-table-toolbar { display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px; }
.toolbar-left { display: flex; align-items: center; gap: 8px; }
.total-badge { color: #6b7280; font-size: 13px; margin-right: 6px; }
.btn-green-plain { border-color: #008b4b !important; color: #008b4b !important; }
.balance-main-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 14px; transition: all .2s; }
.balance-main-layout.with-drawer { grid-template-columns: minmax(0, 1fr) 390px; }
.balance-item-table { width: 100%; }
.link-btn-green { color: #008b4b !important; font-size: 12px; margin: 0 4px; }
.trend-arrow { color: #008b4b; font-size: 12px; font-weight: bold; }
.unit-text { font-size: 12px; color: #6b7280; font-weight: normal; margin-left: 2px; }
.font-bold { font-weight: 700; }
.text-green { color: #008b4b !important; }
.text-blue { color: #2563eb !important; }
.balance-detail-side { background: #fff; border: 1px solid #e5e7eb; border-radius: 4px; padding: 14px 16px; max-height: calc(100vh - 170px); overflow-y: auto; }
.side-head { display: flex; justify-content: space-between; align-items: center; padding-bottom: 10px; border-bottom: 1px solid #e5e7eb; margin-bottom: 12px; }
.side-head h3 { font-size: 15px; font-weight: 700; color: #111827; margin: 0; }
.btn-close-side { background: none; border: none; font-size: 16px; color: #9ca3af; cursor: pointer; padding: 4px; }
.btn-close-side:hover { color: #111827; }
.detail-sec-title { font-size: 13px; font-weight: 700; color: #111827; margin: 14px 0 8px; display: flex; align-items: center; }
.detail-sec-title::before { content: ''; display: inline-block; width: 3px; height: 13px; background: #008b4b; margin-right: 6px; border-radius: 2px; }
.sec-kv-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 6px 12px; font-size: 12px; }
.sec-kv-grid .kv-item { display: flex; align-items: baseline; gap: 6px; }
.sec-kv-grid .span-two { grid-column: 1 / -1; }
.sec-kv-grid .kv-lbl { color: #6b7280; flex-shrink: 0; min-width: 56px; }
.sec-kv-grid .kv-val { color: #111827; font-weight: 500; overflow-wrap: anywhere; }
.sku-note-box { font-size: 11px; color: #9ca3af; margin-top: 4px; }
.dist-table-wrap { overflow-x: auto; margin-top: 6px; }
.dist-table { width: 100%; border-collapse: collapse; font-size: 11px; text-align: left; }
.dist-table th { background: #f8fafc; color: #4b5563; padding: 6px 4px; border: 1px solid #e5e7eb; font-weight: 600; white-space: nowrap; }
.dist-table td { padding: 6px 4px; border: 1px solid #e5e7eb; color: #111827; white-space: nowrap; }
.dist-table .sum-row td { background: #f9fafb; font-weight: 700; color: #111827; }
.tx-timeline-list { display: flex; flex-direction: column; gap: 8px; margin-top: 6px; }
.tx-timeline-item { display: flex; gap: 8px; align-items: flex-start; padding: 8px; background: #fbfcfd; border: 1px solid #edf0f2; border-radius: 4px; }
.tx-type-icon { width: 20px; height: 20px; border-radius: 50%; display: grid; place-items: center; color: #fff; font-size: 12px; font-weight: bold; flex-shrink: 0; }
.tx-type-icon.bg-green { background: #008b4b; }
.tx-type-icon.bg-blue { background: #2563eb; }
.tx-info-block { flex: 1; min-width: 0; }
.tx-row-1 { display: flex; justify-content: space-between; align-items: center; font-size: 12px; margin-bottom: 3px; }
.tx-name { font-weight: 600; color: #111827; }
.tx-qty { font-weight: 700; margin-left: 6px; }
.tx-time { font-size: 11px; color: #9ca3af; margin-left: auto; }
.tx-row-2 { display: flex; flex-wrap: wrap; gap: 6px; font-size: 11px; color: #6b7280; }
.tx-more-link { text-align: center; margin-top: 10px; padding-top: 8px; border-top: 1px solid #f3f4f6; }
.tx-more-link a { color: #008b4b; font-size: 12px; cursor: pointer; text-decoration: underline; }

/* Alert Config Dialog V2 */
.balance-alert-config-dialog-v2 { border-radius: 8px; overflow: hidden; }
.balance-alert-config-dialog-v2 >>> .el-dialog__header { padding: 16px 20px; border-bottom: 1px solid #edf1f5; font-weight: 700; }
.balance-alert-config-dialog-v2 >>> .el-dialog__body { padding: 16px 20px 10px; background: #fff; }
.balance-alert-config-dialog-v2 >>> .el-dialog__footer { padding: 12px 20px 16px; border-top: 1px solid #edf1f5; }
.alert-info-banner { display: flex; align-items: center; gap: 8px; padding: 10px 14px; background: #f0f7ff; border: 1px solid #bae0ff; border-radius: 6px; color: #0958d9; font-size: 13px; margin-bottom: 14px; }
.alert-blue-icon { color: #1890ff; font-size: 16px; }
.alert-card-sec { padding: 12px 0; border-bottom: 1px solid #edf1f5; }
.alert-card-sec.no-border-bottom { border-bottom: none; }
.alert-sec-title { margin: 0 0 10px; font-size: 13px; font-weight: 700; color: #111827; display: flex; align-items: center; }
.sec-time-text, .sec-help-text { font-size: 12px; color: #9ca3af; font-weight: normal; margin-left: 4px; }
.alert-scope-grid { display: flex; flex-direction: column; gap: 6px; background: #fafbfc; border: 1px solid #f0f2f5; border-radius: 6px; padding: 10px 14px; }
.scope-row { display: flex; flex-wrap: wrap; gap: 20px; font-size: 12px; }
.scope-item { display: flex; gap: 6px; align-items: center; }
.scope-item label { color: #6b7280; }
.scope-item strong { color: #111827; font-weight: 600; }
.alert-stock-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 10px; }
.stock-cell { background: #fafbfc; border: 1px solid #f0f2f5; border-radius: 6px; padding: 10px 12px; display: flex; flex-direction: column; gap: 4px; }
.stock-cell-label { font-size: 12px; color: #6b7280; }
.stock-cell-val { font-size: 15px; font-weight: 700; color: #111827; }
.alert-inputs-row { display: grid; grid-template-columns: repeat(4, 1fr) 110px; gap: 10px; align-items: flex-start; }
.input-col { display: flex; flex-direction: column; gap: 6px; }
.input-col label { font-size: 12px; color: #374151; font-weight: 500; }
.required-lbl::after { content: ' *'; color: #ef4444; }
.input-with-unit { position: relative; width: 100%; }
.input-with-unit .el-input-number { width: 100% !important; }
.input-with-unit >>> .el-input__inner { padding-right: 48px; text-align: left; }
.unit-tag { position: absolute; right: 34px; top: 50%; transform: translateY(-50%); color: #9ca3af; font-size: 12px; pointer-events: none; }
.switch-col { align-items: center; }
.switch-wrap { height: 32px; display: flex; align-items: center; }
.err-tip { color: #ef4444; font-size: 11px; line-height: 1.2; margin-top: 2px; }
.alert-rules-bullets { display: grid; grid-template-columns: 1fr 1fr; gap: 8px 16px; font-size: 12px; }
.rule-bullet { display: flex; align-items: center; gap: 6px; }
.rule-bullet.rule-normal { color: #008b4b; }
.rule-bullet.rule-out { color: #d97706; }
.rule-bullet.rule-low { color: #f59e0b; }
.rule-bullet.rule-over { color: #2563eb; }
.dot { width: 8px; height: 8px; border-radius: 50%; display: inline-block; flex-shrink: 0; }
.green-dot-v2 { background: #008b4b; }
.orange-dot-v2 { background: #d97706; }
.yellow-dot-v2 { background: #f59e0b; }
.blue-dot-v2 { background: #2563eb; }
.dialog-footer-v2 { display: flex; justify-content: flex-end; gap: 10px; }
.btn-green-submit { background: #008b4b !important; border-color: #008b4b !important; color: #fff !important; }

.item-batch-dialog { min-width: 1080px; max-width: 1500px; border-radius: 7px; }
.item-batch-dialog .el-dialog__header { padding: 0; }
.item-batch-dialog .el-dialog__headerbtn { top: 18px; right: 22px; z-index: 2; }
.item-batch-dialog .el-dialog__body { padding: 0 24px 12px; }
.item-batch-dialog .el-dialog__footer { padding: 12px 24px 14px; border-top: 1px solid #e5e9ef; }
.batch-dialog-page { color: #202833; }
.batch-dialog-head { display: flex; align-items: center; justify-content: space-between; min-height: 82px; padding-right: 14px; }
.batch-dialog-head h2 { margin: 0 0 9px; font-size: 22px; line-height: 1; }
.batch-dialog-head p { display: flex; gap: 14px; margin: 0; font-size: 14px; }
.batch-dialog-head p span { font-weight: 600; }
.batch-item-summary { display: grid; grid-template-columns: 1.05fr 1.8fr repeat(6, minmax(0, 1fr)); border: 1px solid #dfe4ea; border-radius: 4px; margin-bottom: 13px; }
.batch-item-summary div { min-width: 0; padding: 14px 16px; border-right: 1px solid #edf0f3; }
.batch-item-summary div:last-child { border-right: 0; }
.batch-item-summary span { display: block; margin-bottom: 9px; color: #758091; font-size: 12px; }
.batch-item-summary strong { display: block; overflow-wrap: anywhere; font-size: 14px; line-height: 1.45; }
.batch-dialog-grid { display: grid; grid-template-columns: minmax(560px, 1.04fr) minmax(520px, .96fr); border: 1px solid #dfe4ea; border-radius: 4px; overflow: hidden; }
.batch-list-panel, .batch-context-panel { min-width: 0; padding: 14px; }
.batch-context-panel { border-left: 1px solid #dfe4ea; }
.batch-list-panel h3, .batch-context-panel h3 { margin: 0 0 12px; font-size: 16px; }
.batch-list-footer { display: flex; align-items: center; justify-content: space-between; min-height: 52px; gap: 8px; }
.batch-list-footer > span { white-space: nowrap; color: #5f6977; }
.batch-list-panel >>> .el-table__body-wrapper, .batch-context-panel >>> .el-table__body-wrapper { overflow-x: hidden; }
.batch-list-panel >>> .cell, .batch-context-panel >>> .cell { padding-left: 7px; padding-right: 7px; overflow-wrap: anywhere; }
.item-batch-dialog .current-batch-row > td { background: #f1fbf5 !important; }
.current-batch { margin: -2px 0 12px; color: #5e6875; }
.current-batch strong { margin-left: 8px; color: #202833; font-size: 15px; }
.batch-context-section { margin-bottom: 14px; }
.batch-context-section h4, .batch-quality-actions h4 { position: relative; margin: 0 0 8px; padding-left: 12px; font-size: 14px; }
.batch-context-section h4::before, .batch-quality-actions h4::before { position: absolute; left: 0; top: 2px; width: 3px; height: 15px; content: ''; background: #0ca45c; }
.source-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0; margin: 0; padding: 11px 12px; border: 1px solid #e4e8ed; background: #fbfcfd; }
.source-grid div { min-width: 0; }
.source-grid dt { margin-bottom: 6px; color: #7b8491; font-size: 11px; }
.source-grid dd { margin: 0; overflow-wrap: anywhere; font-size: 12px; }
.quality-history-section { min-height: 150px; }
.batch-quality-actions { padding: 2px 12px 10px; border: 1px solid #e4e8ed; background: #fbfcfd; }
.batch-quality-actions h4 { margin-top: 10px; }
.batch-quality-actions p { margin: 10px 0 0; color: #7b8491; font-size: 12px; }
.timeline-list { list-style: none; padding: 0; margin: 0; }
.timeline-list li { display: grid; grid-template-columns: 16px 1fr auto; gap: 8px; padding: 8px 0; border-bottom: 1px solid #edf0f2; }
.timeline-list small { grid-column: 2 / 4; color: #6b7280; }
.green-dot, .orange-dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 4px; background: #059669; }
.orange-dot { background: #f59e0b; }
.disabled-legend { margin: 8px 0 12px; padding: 10px 12px; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; color: #4b5563; background: #fff; }
.disabled-legend b, .disabled-legend span { margin-left: 14px; }
.adjustment-list { padding: 12px; }
.adjustment-card { border: 1px solid #e5e7eb; border-radius: 4px; padding: 10px; margin-bottom: 10px; }
.adjustment-card div, .adjustment-card footer { display: flex; justify-content: space-between; align-items: center; }
.adjustment-card p { margin: 8px 0; color: #4b5563; }
.adjustment-summary { display: grid; grid-template-columns: 68px 1fr; gap: 5px 8px; margin: 8px 0; font-size: 12px; }
.adjustment-summary dt { color: #6b7280; }
.adjustment-summary dd { margin: 0; color: #111827; }
.confirm-grid { display: grid; grid-template-columns: 140px 1fr 140px 1fr; gap: 0; margin: 14px 0 0; border: 1px solid #e5e7eb; border-bottom: none; border-right: none; }
.confirm-grid dt, .confirm-grid dd { margin: 0; padding: 9px 10px; border-right: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
.confirm-grid dt { background: #f9fafb; color: #6b7280; }
.confirm-grid dd { font-weight: 600; }
.posting-assignment-list { display: grid; gap: 10px; margin-top: 14px; }
.posting-assignment-line { display: grid; grid-template-columns: minmax(150px, 1.2fr) repeat(3, minmax(140px, 1fr)); gap: 10px; align-items: end; padding: 12px; border: 1px solid #e5e7eb; border-radius: 4px; background: #f9fafb; }
.posting-assignment-line strong { align-self: center; color: #111827; }
.posting-assignment-line label { display: grid; gap: 5px; color: #6b7280; font-size: 12px; }
.posting-assignment-line .el-select, .posting-assignment-line .el-input { width: 100%; }
.posting-assignment-line .posting-serial-field { grid-column: 1 / -1; display:grid; gap:6px; color:#6b7280; font-size:12px; }
.posting-serial-tags { display:flex; flex-wrap:wrap; gap:6px; min-height:28px; padding:6px; border:1px solid #dcdfe6; border-radius:4px; background:#fff; }
.posting-serial-field small { color: #9a6700; line-height: 1.5; white-space: normal; }
.posting-allocation-review { grid-column:1/-1; display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:7px; padding:8px; border:1px solid #cfe5d7; border-radius:4px; background:#f4fbf6; }
.posting-allocation-review>div { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:7px 9px; border:1px solid #dfe9e2; border-radius:3px; background:#fff; }
.posting-allocation-review strong { color:#24583a; font-size:12px; }
.posting-allocation-review span { flex:0 0 auto; color:#67756d; font-size:11px; }
.posting-allocation-missing { grid-column:1/-1; padding:8px 10px; border:1px solid #f3c6c6; border-radius:4px; background:#fff5f5; color:#c0392b; font-size:12px; }
.posting-check { display:flex; flex-direction:column; align-items:flex-start; gap:4px; line-height:1.35; white-space:normal; }
.posting-check span { font-size:11px; }
.posting-check-blocked span { color:#c0392b; }
.posting-check-passed span { color:#5b7564; }
.posting-blocked-alert { margin:8px 0 10px; }
.posting-blocked-alert .el-alert__content { width:100%; }
.posting-blocked-alert .el-alert__description { margin-top:7px; }
.posting-repair-summary { display:grid; grid-template-columns:minmax(0,220px) minmax(0,1fr); gap:12px; margin:12px 0; padding:10px 12px; border:1px solid #e4e9e6; border-radius:4px; background:#fafcfb; }
.posting-repair-summary span { display:flex; flex-direction:column; gap:4px; color:#7b8580; font-size:12px; }
.posting-repair-summary strong { color:#25342c; font-size:13px; }
.posting-repair-line { margin-top:12px; border:1px solid #dfe7e2; border-radius:5px; overflow:hidden; }
.posting-repair-line>header { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; background:#f5faf7; }
.posting-repair-line>header div { display:flex; flex-direction:column; gap:3px; }
.posting-repair-line>header span { color:#6f7d75; font-size:12px; }
.posting-repair-allocation { display:grid; grid-template-columns:minmax(150px,1fr) minmax(150px,1fr) 155px minmax(180px,1.2fr) 44px; gap:10px; align-items:end; padding:10px 12px; border-top:1px solid #edf1ee; }
.posting-repair-allocation label { display:flex; flex-direction:column; gap:5px; color:#53615a; font-size:12px; min-width:0; }
.posting-repair-allocation .el-select, .posting-repair-allocation .el-input-number { width:100%; }
.posting-repair-serials { min-width:0; }
.posting-repair-remove { margin-bottom:6px; }
.posting-repair-progress { padding:8px 12px; border-top:1px solid #edf1ee; background:#f6fbf8; color:#19935a; font-size:12px; }
.posting-repair-progress.danger { background:#fff8f3; color:#d46b21; }
.red-text { color:#c0392b!important; }
.adjustment-panel { position: fixed; top: 58px; right: 0; bottom: 0; width: 372px; z-index: 3001; background: #fff; border-left: 1px solid #e5e7eb; box-shadow: -10px 0 26px rgba(15, 23, 42, .12); display: flex; flex-direction: column; }
.adjustment-panel header { height: 56px; display: flex; align-items: center; justify-content: space-between; padding: 0 18px; border-bottom: 1px solid #e5e7eb; }
.adjustment-panel h3 { margin: 0; font-size: 16px; color: #111827; }
.adjustment-panel header button { border: 0; background: transparent; cursor: pointer; color: #6b7280; font-size: 16px; }
.adjustment-panel-body { flex: 1; overflow: auto; padding: 14px 18px 74px; }
.adjustment-panel .el-form { margin-top: 14px; }
.adjustment-panel .el-select, .adjustment-panel .el-input, .adjustment-panel .el-input-number { width: 100%; }
.adjustment-detail-panel { width: 430px; }
.adjustment-detail-dl { grid-template-columns: 86px 1fr; }
.adjustment-detail-dl dt, .adjustment-detail-dl dd { grid-column: auto; }
.adjustment-panel >>> .adjustment-reason-popper { z-index: 2601 !important; }
.adjustment-panel >>> .el-select-dropdown { position: absolute !important; }
.balance-picker-card { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-height: 54px; padding: 9px 10px; border: 1px solid #dfe7ee; border-radius: 4px; background: #fbfdff; }
.balance-picker-card div { display: grid; gap: 4px; min-width: 0; }
.balance-picker-card strong { color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.balance-picker-card span { color: #6b7280; font-size: 12px; }
.balance-picker-card small { color: #4b5563; font-size: 12px; line-height: 1.65; }
.adjustment-readonly-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 8px 0 12px; padding: 10px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 4px; color: #374151; }
.adjustment-readonly-grid span:first-child { grid-column: 1 / 3; }
.adjustment-readonly-grid b { display: block; margin-bottom: 3px; color: #6b7280; font-weight: 500; }
.adjustment-serial-card { margin-top: 12px; padding: 12px; border: 1px solid #dfe6ee; border-radius: 4px; background: #f8fafc; }
.adjustment-serial-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.adjustment-serial-head strong { display: block; margin-bottom: 4px; color: #111827; }
.adjustment-serial-head small, .adjustment-serial-stock { display: block; color: #6b7280; line-height: 1.55; }
.adjustment-serial-actions { display: flex; gap: 8px; margin-bottom: 8px; }
.adjustment-serial-actions .el-button + .el-button { margin-left: 0; }
.adjustment-serial-count { margin-top: 8px; padding: 7px 9px; color: #047857; background: #ecfdf5; border-radius: 3px; }
.adjustment-serial-count.danger { color: #b45309; background: #fff7ed; }
.adjustment-detail-serials { display: flex; flex-wrap: wrap; gap: 5px; }
.adjustment-panel >>> .adjustment-serial-popper { z-index: 2601 !important; }
.adjustment-stock-preview { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; margin: 10px 0 12px; }
.adjustment-stock-preview article { padding: 8px 6px; text-align: center; border: 1px solid #dfe7ee; border-radius: 4px; background: #fff; }
.adjustment-stock-preview span { display: block; margin-bottom: 3px; color: #6b7280; font-size: 12px; }
.adjustment-stock-preview strong { color: #111827; }
.adjustment-stock-preview article.danger { border-color: #fecaca; background: #fef2f2; }
.adjustment-stock-preview article.danger strong { color: #dc2626; }
.adjustment-panel footer { position: absolute; left: 0; right: 0; bottom: 0; display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; background: #fff; border-top: 1px solid #e5e7eb; }
.quality-panel { width: 438px; }
.quality-panel header { height: 66px; }
.quality-panel header div { min-width: 0; }
.quality-panel header small { display: block; margin-top: 4px; color: #6b7280; font-size: 11px; font-weight: 400; }
.quality-panel-body { padding: 12px 14px 82px; }
.quality-card { margin-top: 10px; padding: 12px; border: 1px solid #e1e7eb; border-radius: 5px; background: #fff; min-width: 0; }
.quality-card h4 { display: flex; align-items: center; gap: 7px; margin: 0 0 12px; color: #1f2937; font-size: 13px; }
.quality-card h4 b { display: inline-grid; place-items: center; width: 19px; height: 19px; border-radius: 4px; color: #fff; background: #059669; font-size: 11px; }
.quality-locator { display: grid; grid-template-columns: 88px minmax(0, 1fr); gap: 9px 10px; margin: 0 0 12px; }
.quality-locator dt { color: #6b7280; }
.quality-locator dd { min-width: 0; margin: 0; color: #111827; overflow-wrap: anywhere; }
.quality-field, .quality-form-grid label { display: grid; min-width: 0; gap: 6px; color: #4b5563; font-size: 12px; }
.quality-form-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; min-width: 0; }
.quality-form-grid .span-two { grid-column: 1 / -1; }
.quality-form-grid em { color: #dc2626; font-style: normal; }
.quality-panel .full-control, .quality-panel .el-input, .quality-panel .el-select, .quality-panel .el-input-number { width: 100%; max-width: 100%; min-width: 0; }
.quality-panel >>> .el-input__inner { width: 100%; padding-right: 30px; text-overflow: clip; }
.quality-panel >>> .el-input-number .el-input__inner { padding-left: 10px; padding-right: 42px; text-align: left; }
.quality-panel >>> .quality-select-popper { z-index: 2601 !important; }
.quality-field small { display: block; line-height: 1.55; color: #6b7280; }
.quality-panel >>> textarea { width: 100%; box-sizing: border-box; resize: vertical; }
.quality-action-note { display: grid; gap: 4px; margin-top: 12px; padding: 10px; border: 1px solid #bfdbfe; border-radius: 4px; color: #3b5f88; background: #eff6ff; line-height: 1.55; }
.quality-action-note strong { color: #1d4ed8; }
.quality-action-note span { overflow-wrap: anywhere; }
.active-quality-card { border-color: #f4c77a; background: #fffaf0; }
.active-quality-card h4 b { background: #d97706; }
.quality-event-row { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 10px; align-items: center; padding: 9px 0; border-top: 1px solid #f3dfbc; }
.quality-event-row:first-of-type { border-top: 0; }
.quality-event-row div:first-child { display: grid; min-width: 0; gap: 3px; }
.quality-event-row span, .quality-event-row small { color: #6b7280; overflow-wrap: anywhere; }
.quality-event-actions { display: flex; gap: 6px; }
.table-card >>> .el-table__body-wrapper { overflow-x: auto; }
.table-card >>> .cell { overflow-wrap: anywhere; }
.side-slide-enter-active, .side-slide-leave-active { transition: transform .18s ease, opacity .18s ease; }
.side-slide-enter, .side-slide-leave-to { transform: translateX(100%); opacity: .6; }
.balance-picker-dialog .el-dialog__body { padding: 12px 16px 14px; }
.inventory-serial-dialog-body { display:grid; gap:12px; }
.serial-locator-summary { display:grid; grid-template-columns:1.5fr 1fr 1fr 1fr; gap:10px; padding:11px 12px; border:1px solid #dfe8e2; border-radius:4px; background:#f7faf8; }
.serial-locator-summary>div { display:grid; gap:4px; min-width:0; }
.serial-locator-summary span { color:#78847d; font-size:11px; }
.serial-locator-summary strong { color:#24352c; font-size:12px; overflow-wrap:anywhere; }
.inventory-serial-dialog .el-table .cell { white-space:normal; word-break:break-word; }
.picker-filter { margin-bottom: 10px; }
.picker-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 10px; color: #4b5563; }

/* Keep the approved balance header bound to the workspace.  Wide Element tables
   may scroll inside their own card, but must never enlarge the page or push the
   four statistic cards outside of the visible workspace. */
.inventory-page, .inventory-view, .balance-main-layout, .balance-item-table { min-width: 0; max-width: 100%; box-sizing: border-box; }
.inventory-page { width: 100%; overflow-x: clip; }
.metric-grid.four { width: 100%; min-width: 0; }
.metric-grid.four article { min-width: 0; overflow: hidden; }
.metric-grid.four article span, .metric-grid.four article strong { min-width: 0; overflow-wrap: anywhere; }
.balance-table-toolbar, .toolbar-left, .toolbar-right { min-width: 0; flex-wrap: wrap; }
.balance-item-table { overflow: hidden; }
.balance-item-table >>> .el-table__body-wrapper { max-width: 100%; overflow-x: auto; }
@media (max-width: 1200px) {
  .posting-layout, .balance-layout, .tx-layout { grid-template-columns: 1fr; }
  .balance-main-layout.with-drawer { grid-template-columns: 1fr; }
  .posting-repair-allocation { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .posting-repair-serials { grid-column:1/-1; }
  .metric-grid.four { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .item-batch-dialog { width: 94% !important; min-width: 0; }
  .batch-item-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .batch-item-summary div:nth-child(4) { border-right: 0; }
  .batch-item-summary div:nth-child(-n+4) { border-bottom: 1px solid #edf0f3; }
  .batch-dialog-grid { grid-template-columns: 1fr; max-height: 72vh; overflow-y: auto; }
  .batch-context-panel { border-left: 0; border-top: 1px solid #dfe4ea; }
  .alert-config-main { grid-template-columns:1fr; }
  .alert-config-check { border-top:1px solid #e0e7ed; border-left:0; }
}
@media (max-width: 760px) {
  .serial-locator-summary { grid-template-columns:1fr 1fr; }
  .item-batch-dialog .el-dialog__body { padding: 0 12px 10px; }
  .batch-item-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .batch-item-summary div { border-bottom: 1px solid #edf0f3; }
  .batch-item-summary div:nth-child(even) { border-right: 0; }
  .batch-dialog-head { align-items: flex-start; flex-direction: column; justify-content: center; gap: 10px; }
  .source-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .page-head { flex-direction:column; gap:10px; }
  .balance-head-actions { width:100%; justify-content:flex-start; }
  .balance-alert-config-dialog-v2 { width:calc(100vw - 20px) !important; margin-top:4vh !important; }
  .balance-alert-config-dialog-v2 >>> .el-dialog__body { padding:8px 12px 14px; }
  .alert-stock-grid { grid-template-columns: 1fr 1fr; }
  .alert-inputs-row { grid-template-columns: 1fr; }
  .alert-rules-bullets { grid-template-columns: 1fr; }
}
.balance-item-table { width: 100%; }
.item-batch-dialog { min-width: 1080px; max-width: 1500px; border-radius: 7px; }
.item-batch-dialog .el-dialog__header { padding: 0; }
.item-batch-dialog .el-dialog__headerbtn { top: 18px; right: 22px; z-index: 2; }
.item-batch-dialog .el-dialog__body { padding: 0 24px 12px; }
.item-batch-dialog .el-dialog__footer { padding: 12px 24px 14px; border-top: 1px solid #e5e9ef; }
.batch-dialog-page { color: #202833; }
.batch-dialog-head { display: flex; align-items: center; justify-content: space-between; min-height: 82px; padding-right: 14px; }
.batch-dialog-head h2 { margin: 0 0 9px; font-size: 22px; line-height: 1; }
.batch-dialog-head p { display: flex; gap: 14px; margin: 0; font-size: 14px; }
.batch-dialog-head p span { font-weight: 600; }
.batch-item-summary { display: grid; grid-template-columns: 1.05fr 1.8fr repeat(6, minmax(0, 1fr)); border: 1px solid #dfe4ea; border-radius: 4px; margin-bottom: 13px; }
.batch-item-summary div { min-width: 0; padding: 14px 16px; border-right: 1px solid #edf0f3; }
.batch-item-summary div:last-child { border-right: 0; }
.batch-item-summary span { display: block; margin-bottom: 9px; color: #758091; font-size: 12px; }
.batch-item-summary strong { display: block; overflow-wrap: anywhere; font-size: 14px; line-height: 1.45; }
.batch-dialog-grid { display: grid; grid-template-columns: minmax(560px, 1.04fr) minmax(520px, .96fr); border: 1px solid #dfe4ea; border-radius: 4px; overflow: hidden; }
.batch-list-panel, .batch-context-panel { min-width: 0; padding: 14px; }
.batch-context-panel { border-left: 1px solid #dfe4ea; }
.batch-list-panel h3, .batch-context-panel h3 { margin: 0 0 12px; font-size: 16px; }
.batch-list-footer { display: flex; align-items: center; justify-content: space-between; min-height: 52px; gap: 8px; }
.batch-list-footer > span { white-space: nowrap; color: #5f6977; }
.batch-list-panel >>> .el-table__body-wrapper, .batch-context-panel >>> .el-table__body-wrapper { overflow-x: hidden; }
.batch-list-panel >>> .cell, .batch-context-panel >>> .cell { padding-left: 7px; padding-right: 7px; overflow-wrap: anywhere; }
.item-batch-dialog .current-batch-row > td { background: #f1fbf5 !important; }
.current-batch { margin: -2px 0 12px; color: #5e6875; }
.current-batch strong { margin-left: 8px; color: #202833; font-size: 15px; }
.batch-context-section { margin-bottom: 14px; }
.batch-context-section h4, .batch-quality-actions h4 { position: relative; margin: 0 0 8px; padding-left: 12px; font-size: 14px; }
.batch-context-section h4::before, .batch-quality-actions h4::before { position: absolute; left: 0; top: 2px; width: 3px; height: 15px; content: ''; background: #0ca45c; }
.source-grid { display: grid; grid-template-columns: repeat(4, minmax(0, 1fr)); gap: 0; margin: 0; padding: 11px 12px; border: 1px solid #e4e8ed; background: #fbfcfd; }
.source-grid div { min-width: 0; }
.source-grid dt { margin-bottom: 6px; color: #7b8491; font-size: 11px; }
.source-grid dd { margin: 0; overflow-wrap: anywhere; font-size: 12px; }
.quality-history-section { min-height: 150px; }
.batch-quality-actions { padding: 2px 12px 10px; border: 1px solid #e4e8ed; background: #fbfcfd; }
.batch-quality-actions h4 { margin-top: 10px; }
.batch-quality-actions p { margin: 10px 0 0; color: #7b8491; font-size: 12px; }
.timeline-list { list-style: none; padding: 0; margin: 0; }
.timeline-list li { display: grid; grid-template-columns: 16px 1fr auto; gap: 8px; padding: 8px 0; border-bottom: 1px solid #edf0f2; }
.timeline-list small { grid-column: 2 / 4; color: #6b7280; }
.green-dot, .orange-dot { width: 9px; height: 9px; border-radius: 50%; margin-top: 4px; background: #059669; }
.orange-dot { background: #f59e0b; }
.disabled-legend { margin: 8px 0 12px; padding: 10px 12px; border-top: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; color: #4b5563; background: #fff; }
.disabled-legend b, .disabled-legend span { margin-left: 14px; }
.adjustment-list { padding: 12px; }
.adjustment-card { border: 1px solid #e5e7eb; border-radius: 4px; padding: 10px; margin-bottom: 10px; }
.adjustment-card div, .adjustment-card footer { display: flex; justify-content: space-between; align-items: center; }
.adjustment-card p { margin: 8px 0; color: #4b5563; }
.adjustment-summary { display: grid; grid-template-columns: 68px 1fr; gap: 5px 8px; margin: 8px 0; font-size: 12px; }
.adjustment-summary dt { color: #6b7280; }
.adjustment-summary dd { margin: 0; color: #111827; }
.confirm-grid { display: grid; grid-template-columns: 140px 1fr 140px 1fr; gap: 0; margin: 14px 0 0; border: 1px solid #e5e7eb; border-bottom: none; border-right: none; }
.confirm-grid dt, .confirm-grid dd { margin: 0; padding: 9px 10px; border-right: 1px solid #e5e7eb; border-bottom: 1px solid #e5e7eb; }
.confirm-grid dt { background: #f9fafb; color: #6b7280; }
.confirm-grid dd { font-weight: 600; }
.posting-assignment-list { display: grid; gap: 10px; margin-top: 14px; }
.posting-assignment-line { display: grid; grid-template-columns: minmax(150px, 1.2fr) repeat(3, minmax(140px, 1fr)); gap: 10px; align-items: end; padding: 12px; border: 1px solid #e5e7eb; border-radius: 4px; background: #f9fafb; }
.posting-assignment-line strong { align-self: center; color: #111827; }
.posting-assignment-line label { display: grid; gap: 5px; color: #6b7280; font-size: 12px; }
.posting-assignment-line .el-select, .posting-assignment-line .el-input { width: 100%; }
.posting-assignment-line .posting-serial-field { grid-column: 1 / -1; display:grid; gap:6px; color:#6b7280; font-size:12px; }
.posting-serial-tags { display:flex; flex-wrap:wrap; gap:6px; min-height:28px; padding:6px; border:1px solid #dcdfe6; border-radius:4px; background:#fff; }
.posting-serial-field small { color: #9a6700; line-height: 1.5; white-space: normal; }
.posting-allocation-review { grid-column:1/-1; display:grid; grid-template-columns:repeat(auto-fit,minmax(210px,1fr)); gap:7px; padding:8px; border:1px solid #cfe5d7; border-radius:4px; background:#f4fbf6; }
.posting-allocation-review>div { display:flex; align-items:center; justify-content:space-between; gap:8px; padding:7px 9px; border:1px solid #dfe9e2; border-radius:3px; background:#fff; }
.posting-allocation-review strong { color:#24583a; font-size:12px; }
.posting-allocation-review span { flex:0 0 auto; color:#67756d; font-size:11px; }
.posting-allocation-missing { grid-column:1/-1; padding:8px 10px; border:1px solid #f3c6c6; border-radius:4px; background:#fff5f5; color:#c0392b; font-size:12px; }
.posting-check { display:flex; flex-direction:column; align-items:flex-start; gap:4px; line-height:1.35; white-space:normal; }
.posting-check span { font-size:11px; }
.posting-check-blocked span { color:#c0392b; }
.posting-check-passed span { color:#5b7564; }
.posting-blocked-alert { margin:8px 0 10px; }
.posting-blocked-alert .el-alert__content { width:100%; }
.posting-blocked-alert .el-alert__description { margin-top:7px; }
.posting-repair-summary { display:grid; grid-template-columns:minmax(0,220px) minmax(0,1fr); gap:12px; margin:12px 0; padding:10px 12px; border:1px solid #e4e9e6; border-radius:4px; background:#fafcfb; }
.posting-repair-summary span { display:flex; flex-direction:column; gap:4px; color:#7b8580; font-size:12px; }
.posting-repair-summary strong { color:#25342c; font-size:13px; }
.posting-repair-line { margin-top:12px; border:1px solid #dfe7e2; border-radius:5px; overflow:hidden; }
.posting-repair-line>header { display:flex; align-items:center; justify-content:space-between; gap:10px; padding:10px 12px; background:#f5faf7; }
.posting-repair-line>header div { display:flex; flex-direction:column; gap:3px; }
.posting-repair-line>header span { color:#6f7d75; font-size:12px; }
.posting-repair-allocation { display:grid; grid-template-columns:minmax(150px,1fr) minmax(150px,1fr) 155px minmax(180px,1.2fr) 44px; gap:10px; align-items:end; padding:10px 12px; border-top:1px solid #edf1ee; }
.posting-repair-allocation label { display:flex; flex-direction:column; gap:5px; color:#53615a; font-size:12px; min-width:0; }
.posting-repair-allocation .el-select, .posting-repair-allocation .el-input-number { width:100%; }
.posting-repair-serials { min-width:0; }
.posting-repair-remove { margin-bottom:6px; }
.posting-repair-progress { padding:8px 12px; border-top:1px solid #edf1ee; background:#f6fbf8; color:#19935a; font-size:12px; }
.posting-repair-progress.danger { background:#fff8f3; color:#d46b21; }
.red-text { color:#c0392b!important; }
.adjustment-panel { position: fixed; top: 58px; right: 0; bottom: 0; width: 372px; z-index: 3001; background: #fff; border-left: 1px solid #e5e7eb; box-shadow: -10px 0 26px rgba(15, 23, 42, .12); display: flex; flex-direction: column; }
.adjustment-panel header { height: 56px; display: flex; align-items: center; justify-content: space-between; padding: 0 18px; border-bottom: 1px solid #e5e7eb; }
.adjustment-panel h3 { margin: 0; font-size: 16px; color: #111827; }
.adjustment-panel header button { border: 0; background: transparent; cursor: pointer; color: #6b7280; font-size: 16px; }
.adjustment-panel-body { flex: 1; overflow: auto; padding: 14px 18px 74px; }
.adjustment-panel .el-form { margin-top: 14px; }
.adjustment-panel .el-select, .adjustment-panel .el-input, .adjustment-panel .el-input-number { width: 100%; }
.adjustment-detail-panel { width: 430px; }
.adjustment-detail-dl { grid-template-columns: 86px 1fr; }
.adjustment-detail-dl dt, .adjustment-detail-dl dd { grid-column: auto; }
.adjustment-panel >>> .adjustment-reason-popper { z-index: 3002 !important; }
.adjustment-panel >>> .el-select-dropdown { position: absolute !important; }
.balance-picker-card { display: flex; align-items: center; justify-content: space-between; gap: 10px; min-height: 54px; padding: 9px 10px; border: 1px solid #dfe7ee; border-radius: 4px; background: #fbfdff; }
.balance-picker-card div { display: grid; gap: 4px; min-width: 0; }
.balance-picker-card strong { color: #111827; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.balance-picker-card span { color: #6b7280; font-size: 12px; }
.balance-picker-card small { color: #4b5563; font-size: 12px; line-height: 1.65; }
.adjustment-readonly-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; margin: 8px 0 12px; padding: 10px; background: #f8fafc; border: 1px solid #e5e7eb; border-radius: 4px; color: #374151; }
.adjustment-readonly-grid span:first-child { grid-column: 1 / 3; }
.adjustment-readonly-grid b { display: block; margin-bottom: 3px; color: #6b7280; font-weight: 500; }
.adjustment-serial-card { margin-top: 12px; padding: 12px; border: 1px solid #dfe6ee; border-radius: 4px; background: #f8fafc; }
.adjustment-serial-head { display: flex; align-items: flex-start; justify-content: space-between; gap: 10px; margin-bottom: 10px; }
.adjustment-serial-head strong { display: block; margin-bottom: 4px; color: #111827; }
.adjustment-serial-head small, .adjustment-serial-stock { display: block; color: #6b7280; line-height: 1.55; }
.adjustment-serial-actions { display: flex; gap: 8px; margin-bottom: 8px; }
.adjustment-serial-actions .el-button + .el-button { margin-left: 0; }
.adjustment-serial-count { margin-top: 8px; padding: 7px 9px; color: #047857; background: #ecfdf5; border-radius: 3px; }
.adjustment-serial-count.danger { color: #b45309; background: #fff7ed; }
.adjustment-detail-serials { display: flex; flex-wrap: wrap; gap: 5px; }
.adjustment-panel >>> .adjustment-serial-popper { z-index: 3002 !important; }
.adjustment-stock-preview { display: grid; grid-template-columns: repeat(5, minmax(0, 1fr)); gap: 6px; margin: 10px 0 12px; }
.adjustment-stock-preview article { padding: 8px 6px; text-align: center; border: 1px solid #dfe7ee; border-radius: 4px; background: #fff; }
.adjustment-stock-preview span { display: block; margin-bottom: 3px; color: #6b7280; font-size: 12px; }
.adjustment-stock-preview strong { color: #111827; }
.adjustment-stock-preview article.danger { border-color: #fecaca; background: #fef2f2; }
.adjustment-stock-preview article.danger strong { color: #dc2626; }
.adjustment-panel footer { position: absolute; left: 0; right: 0; bottom: 0; display: flex; justify-content: flex-end; gap: 8px; padding: 12px 18px; background: #fff; border-top: 1px solid #e5e7eb; }
.quality-panel { width: 438px; }
.quality-panel header { height: 66px; }
.quality-panel header div { min-width: 0; }
.quality-panel header small { display: block; margin-top: 4px; color: #6b7280; font-size: 11px; font-weight: 400; }
.quality-panel-body { padding: 12px 14px 82px; }
.quality-card { margin-top: 10px; padding: 12px; border: 1px solid #e1e7eb; border-radius: 5px; background: #fff; min-width: 0; }
.quality-card h4 { display: flex; align-items: center; gap: 7px; margin: 0 0 12px; color: #1f2937; font-size: 13px; }
.quality-card h4 b { display: inline-grid; place-items: center; width: 19px; height: 19px; border-radius: 4px; color: #fff; background: #059669; font-size: 11px; }
.quality-locator { display: grid; grid-template-columns: 88px minmax(0, 1fr); gap: 9px 10px; margin: 0 0 12px; }
.quality-locator dt { color: #6b7280; }
.quality-locator dd { min-width: 0; margin: 0; color: #111827; overflow-wrap: anywhere; }
.quality-field, .quality-form-grid label { display: grid; min-width: 0; gap: 6px; color: #4b5563; font-size: 12px; }
.quality-form-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 12px; min-width: 0; }
.quality-form-grid .span-two { grid-column: 1 / -1; }
.quality-form-grid em { color: #dc2626; font-style: normal; }
.quality-panel .full-control, .quality-panel .el-input, .quality-panel .el-select, .quality-panel .el-input-number { width: 100%; max-width: 100%; min-width: 0; }
.quality-panel >>> .el-input__inner { width: 100%; padding-right: 30px; text-overflow: clip; }
.quality-panel >>> .el-input-number .el-input__inner { padding-left: 10px; padding-right: 42px; text-align: left; }
.quality-panel >>> .quality-select-popper { z-index: 3002 !important; }
.quality-field small { display: block; line-height: 1.55; color: #6b7280; }
.quality-panel >>> textarea { width: 100%; box-sizing: border-box; resize: vertical; }
.quality-action-note { display: grid; gap: 4px; margin-top: 12px; padding: 10px; border: 1px solid #bfdbfe; border-radius: 4px; color: #3b5f88; background: #eff6ff; line-height: 1.55; }
.quality-action-note strong { color: #1d4ed8; }
.quality-action-note span { overflow-wrap: anywhere; }
.active-quality-card { border-color: #f4c77a; background: #fffaf0; }
.active-quality-card h4 b { background: #d97706; }
.quality-event-row { display: grid; grid-template-columns: minmax(0,1fr) auto; gap: 10px; align-items: center; padding: 9px 0; border-top: 1px solid #f3dfbc; }
.quality-event-row:first-of-type { border-top: 0; }
.quality-event-row div:first-child { display: grid; min-width: 0; gap: 3px; }
.quality-event-row span, .quality-event-row small { color: #6b7280; overflow-wrap: anywhere; }
.quality-event-actions { display: flex; gap: 6px; }
.table-card >>> .el-table__body-wrapper { overflow-x: auto; }
.table-card >>> .cell { overflow-wrap: anywhere; }
.side-slide-enter-active, .side-slide-leave-active { transition: transform .18s ease, opacity .18s ease; }
.side-slide-enter, .side-slide-leave-to { transform: translateX(100%); opacity: .6; }
.balance-picker-dialog .el-dialog__body { padding: 12px 16px 14px; }
.inventory-serial-dialog-body { display:grid; gap:12px; }
.serial-locator-summary { display:grid; grid-template-columns:1.5fr 1fr 1fr 1fr; gap:10px; padding:11px 12px; border:1px solid #dfe8e2; border-radius:4px; background:#f7faf8; }
.serial-locator-summary>div { display:grid; gap:4px; min-width:0; }
.serial-locator-summary span { color:#78847d; font-size:11px; }
.serial-locator-summary strong { color:#24352c; font-size:12px; overflow-wrap:anywhere; }
.inventory-serial-dialog .el-table .cell { white-space:normal; word-break:break-word; }
.picker-filter { margin-bottom: 10px; }
.picker-footer { display: flex; align-items: center; justify-content: space-between; padding-top: 10px; color: #4b5563; }
@media (max-width: 1200px) {
  .posting-layout, .balance-layout, .tx-layout { grid-template-columns: 1fr; }
  .posting-repair-allocation { grid-template-columns:repeat(2,minmax(0,1fr)); }
  .posting-repair-serials { grid-column:1/-1; }
  .metric-grid.four { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .item-batch-dialog { width: 94% !important; min-width: 0; }
  .batch-item-summary { grid-template-columns: repeat(4, minmax(0, 1fr)); }
  .batch-item-summary div:nth-child(4) { border-right: 0; }
  .batch-item-summary div:nth-child(-n+4) { border-bottom: 1px solid #edf0f3; }
  .batch-dialog-grid { grid-template-columns: 1fr; max-height: 72vh; overflow-y: auto; }
  .batch-context-panel { border-left: 0; border-top: 1px solid #dfe4ea; }
  .alert-config-main { grid-template-columns:1fr; }
  .alert-config-check { border-top:1px solid #e0e7ed; border-left:0; }
}
@media (max-width: 760px) {
  .serial-locator-summary { grid-template-columns:1fr 1fr; }
  .item-batch-dialog .el-dialog__body { padding: 0 12px 10px; }
  .batch-item-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); }
  .batch-item-summary div { border-bottom: 1px solid #edf0f3; }
  .batch-item-summary div:nth-child(even) { border-right: 0; }
  .batch-dialog-head { align-items: flex-start; flex-direction: column; justify-content: center; gap: 10px; }
  .source-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 12px; }
  .page-head { flex-direction:column; gap:10px; }
  .balance-head-actions { width:100%; justify-content:flex-start; }
  .balance-alert-config-dialog { width:calc(100vw - 20px) !important; margin-top:4vh !important; }
  .balance-alert-config-dialog .el-dialog__body { padding:8px 12px 14px; }
  .alert-config-item-card { grid-template-columns:1fr 1fr; }
  .alert-config-item-card>div:nth-child(2) { border-right:0; }
  .alert-config-item-card>div:nth-child(-n+2) { border-bottom:1px solid #e8edf1; }
  .alert-threshold-grid { grid-template-columns:1fr; }
}
</style>
