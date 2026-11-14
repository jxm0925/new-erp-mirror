<template>
  <section class="return-form-page">
    <template v-if="kind === 'purchase'">
      <!-- 顶部白底页头卡片 -->
      <div class="page-head">
        <div class="head-left">
          <el-button circle size="small" icon="el-icon-arrow-left" @click="$router.push(basePath)" />
          <div class="head-titles">
            <div class="title-row">
              <h1>新建采购退货单</h1>
              <el-tag size="mini" type="success">草稿</el-tag>
              <span class="doc-no-pill mono">{{ reservation && reservation.document_no ? reservation.document_no : '单号预占中...' }}</span>
            </div>
            <p class="subtitle">基于原采购到货入库批次发起退货，严格校验同供应商、同到货单、可退库存与设备序列号</p>
          </div>
        </div>
        <div class="head-actions">
          <el-button size="small" icon="el-icon-back" @click="$router.push(basePath)">返回列表</el-button>
          <el-button size="small" class="draft-button" :loading="saving" :disabled="!reservation" @click="save(false)">保存草稿</el-button>
          <el-button size="small" type="success" icon="el-icon-check" :loading="saving" :disabled="!reservation" @click="save(true)">保存并提交</el-button>
        </div>
      </div>

      <!-- 统一规则提示条 -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>
          <strong>退货提示：</strong>采购退货单需提交并审核通过，仓库完成<strong>退货出库过账</strong>后对应仓库与批次库存才会扣减；同一退货单仅支持勾选<strong>同一张原到货单</strong>下的入库批次。
        </span>
      </div>

      <!-- 水平保存前校验状态条（释放右侧边栏为 100% 全宽） -->
      <div class="check-strip">
        <div class="check-strip-head">
          <span class="check-strip-title">
            <i :class="purchaseAllChecksOk ? 'el-icon-circle-check ok' : 'el-icon-warning-outline warn'" />
            保存前校验
          </span>
          <span :class="['check-progress-pill', purchaseAllChecksOk ? 'is-ok' : 'is-pending']">
            {{ purchasePassedCheckCount }} / {{ purchaseChecksWithReason.length }} 项通过
          </span>
        </div>
        <div class="check-chip-list">
          <span
            v-for="item in purchaseChecksWithReason"
            :key="item.text"
            :class="['check-chip', item.ok ? 'is-ok' : 'is-pending']"
          >
            <i :class="item.ok ? 'el-icon-success' : 'el-icon-warning'" />
            <span>{{ item.text }}</span>
          </span>
        </div>
      </div>

      <div class="purchase-main-full">
        <!-- 一、基础信息卡片 -->
        <div class="section-card">
          <div class="section-title-bar">
            <span class="bar-accent" />
            <h3>一、退货单基础信息</h3>
          </div>
          <el-form label-position="top" size="small" class="purchase-header-form">
            <el-form-item label="管理类型" required>
              <el-select :value="form.management_scope" :disabled="saving || scopeChanging" @change="changeManagementScope">
                <el-option v-for="scope in scopeOptions" :key="scope.value" :label="scope.label" :value="scope.value" />
              </el-select>
            </el-form-item>
            <el-form-item label="退货单号">
              <el-input :value="reservation && reservation.document_no" disabled class="erp-system-number" />
            </el-form-item>
            <el-form-item label="退货日期">
              <el-date-picker v-model="form.return_date" value-format="yyyy-MM-dd" style="width: 100%" />
            </el-form-item>
            <el-form-item label="供应商" required>
              <el-input :value="selectedSource && selectedSource.supplier_name" placeholder="选择原到货批次后自动带出" disabled />
            </el-form-item>
            <el-form-item label="退货原因" required>
              <el-select v-model="form.return_reason" placeholder="请选择退货原因" style="width: 100%">
                <el-option v-for="reason in purchaseReasons" :key="reason" :label="reason" :value="reason" />
              </el-select>
            </el-form-item>
            <el-form-item class="remark-field" label="退货备注">
              <el-input v-model.trim="form.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="请输入备注说明（非必填）" />
            </el-form-item>
          </el-form>
        </div>

        <!-- 二、选择原采购到货批次 -->
        <div class="section-card">
          <div class="section-head-row">
            <div class="section-title-bar">
              <span class="bar-accent" />
              <h3>二、选择原采购到货批次</h3>
              <span class="section-badge">共 {{ sourceTotal }} 条可选批次</span>
            </div>
            <div class="source-toolbar">
              <el-input
                v-model="sourceQuery.keyword"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="请输入到货单号 / 采购订单号 / 物料编码 / 物料名称"
                @keyup.enter.native="reloadSources"
                @clear="reloadSources"
              />
              <el-button size="small" type="success" plain icon="el-icon-search" @click="reloadSources">查询</el-button>
              <el-button size="small" icon="el-icon-refresh" @click="reloadSources">刷新</el-button>
            </div>
          </div>

          <el-table v-loading="loading" :data="sources" border stripe size="mini" highlight-current-row empty-text="当前管理类型下暂无可退到货批次">
            <el-table-column label="选择" width="58" align="center">
              <template slot-scope="{row}">
                <el-tooltip :disabled="!purchaseSourceBlocked(row)" :content="purchaseSourceBlockedReason(row)" placement="top">
                  <span>
                    <el-checkbox :value="isPurchaseSourceSelected(row)" :disabled="purchaseSourceBlocked(row)" @change="togglePurchaseSource(row, $event)" />
                  </span>
                </el-tooltip>
              </template>
            </el-table-column>
            <el-table-column label="管理类型" width="105" align="center">
              <template slot-scope="{row}">
                <el-tag size="mini" :type="row.management_scope === 'office' ? 'info' : 'success'">{{ scopeLabel(row.management_scope) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="receipt_no" label="到货单" min-width="136">
              <template slot-scope="{row}"><span class="mono">{{ row.receipt_no }}</span></template>
            </el-table-column>
            <el-table-column prop="purchase_order_no" label="采购订单" min-width="136">
              <template slot-scope="{row}"><span class="mono">{{ row.purchase_order_no }}</span></template>
            </el-table-column>
            <el-table-column label="物料编码 / 名称" min-width="210">
              <template slot-scope="{row}">
                <div class="item-cell-compact">
                  <span class="code-badge mono">{{ row.item_code }}</span>
                  <span class="item-name-bold">{{ row.item_name }}</span>
                </div>
                <div v-if="row.serial_tracking_mode === 'required'" :class="['source-serial-hint', { blocked: purchaseSourceSerialBlocked(row) }]">
                  可用设备编号 {{ row.available_serial_count }} 个
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="warehouse_name" label="仓库" min-width="96" />
            <el-table-column prop="location_name" label="库位" min-width="96" />
            <el-table-column prop="batch_no" label="批次" min-width="136">
              <template slot-scope="{row}"><span class="mono">{{ row.batch_no }}</span></template>
            </el-table-column>
            <el-table-column prop="posted_base_qty" label="已入库" width="90" align="right" />
            <el-table-column prop="reserved_return_base_qty" label="已占退货" width="94" align="right" />
            <el-table-column prop="current_available_base_qty" label="当前可用" width="96" align="right" />
            <el-table-column label="可退数量" width="102" align="right">
              <template slot-scope="{row}">
                <span :class="Number(row.available_return_base_qty) > 0 ? 'qty-ok' : 'qty-zero'">
                  {{ row.available_return_base_qty }}
                </span>
              </template>
            </el-table-column>
          </el-table>

          <div class="pager-bar">
            <span class="pager-info">共 {{ sourceTotal }} 条可选批次 · 第 {{ sourceQuery.page }} 页</span>
            <el-pagination
              background
              layout="total, prev, pager, next, sizes, jumper"
              :current-page.sync="sourceQuery.page"
              :page-size.sync="sourceQuery.per_page"
              :page-sizes="[10, 20, 50]"
              :total="sourceTotal"
              @current-change="loadSources"
              @size-change="reloadSources"
            />
          </div>
        </div>

        <!-- 三、已选择的退货明细 -->
        <div class="section-card">
          <div class="section-head-row">
            <div class="section-title-bar">
              <span class="bar-accent" />
              <h3>三、已选择的退货明细</h3>
              <span class="section-badge is-brand">已选 {{ selectedLines.length }} 条</span>
            </div>
            <div v-if="selectedSource" class="source-locked-tag">
              <i class="el-icon-lock" /> 已绑定供应商：<b>{{ selectedSource.supplier_name }}</b> · 到货单：<span class="mono">{{ selectedSource.receipt_no }}</span>
            </div>
          </div>

          <el-table :data="selectedLines" border stripe size="mini" empty-text="请在上方勾选需要退货的原采购到货批次">
            <el-table-column type="index" label="序号" width="54" align="center" />
            <el-table-column label="管理类型" width="100" align="center">
              <template slot-scope="{row}">
                <el-tag size="mini" :type="row.management_scope === 'office' ? 'info' : 'success'">{{ scopeLabel(row.management_scope) }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="receipt_no" label="到货单" min-width="125">
              <template slot-scope="{row}"><span class="mono">{{ row.receipt_no }}</span></template>
            </el-table-column>
            <el-table-column prop="purchase_order_no" label="采购订单" min-width="125">
              <template slot-scope="{row}"><span class="mono">{{ row.purchase_order_no }}</span></template>
            </el-table-column>
            <el-table-column label="物料编码 / 名称" min-width="195">
              <template slot-scope="{row}">
                <div class="item-cell-compact">
                  <span class="code-badge mono">{{ row.item_code }}</span>
                  <span class="item-name-bold">{{ row.item_name }}</span>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="仓库" min-width="88"><template slot-scope="{row}">{{ row.warehouse_name }}</template></el-table-column>
            <el-table-column label="库位" min-width="88"><template slot-scope="{row}">{{ row.location_name }}</template></el-table-column>
            <el-table-column prop="batch_no" label="批次" min-width="125">
              <template slot-scope="{row}"><span class="mono">{{ row.batch_no }}</span></template>
            </el-table-column>
            <el-table-column label="实际可退数量" width="176" align="right">
              <template slot-scope="{row}">
                <div class="qty-main">{{ formatQty(purchaseReturnMax(row), returnUnitPrecision(row)) }} {{ returnUnitName(row) }}</div>
                <div v-if="Number(row.return_conversion_factor || 1) !== 1" class="qty-conversion-hint">
                  折合 {{ formatQty(purchaseReturnMax(row) * Number(row.return_conversion_factor || 1), baseUnitPrecision(row)) }} {{ row.base_unit_name }}
                  <template v-if="purchaseReturnRemainder(row) > 0">；另余 {{ formatQty(purchaseReturnRemainder(row), baseUnitPrecision(row)) }} {{ row.base_unit_name }}需改用基本单位</template>
                </div>
              </template>
            </el-table-column>
            <el-table-column label="退货单位" width="112">
              <template slot-scope="{row}">
                <el-select v-model="row.return_unit_id" size="mini" @change="changePurchaseReturnUnit(row, $event)">
                  <el-option v-for="unit in row.return_units" :key="unit.unit_id" :label="unit.unit_name" :value="unit.unit_id" />
                </el-select>
              </template>
            </el-table-column>
            <el-table-column label="本次退货数量" width="132">
              <template slot-scope="{row}">
                <el-input :value="row.requested_qty_input" size="mini" inputmode="decimal" placeholder="请输入" @input="handlePurchaseQtyInput(row, $event)" @blur="normalizePurchaseQty(row)" />
              </template>
            </el-table-column>
            <el-table-column label="折合基本数量" width="145" align="right">
              <template slot-scope="{row}">
                <span class="qty-main is-brand">{{ formatQty(purchaseRequestedBaseQty(row), baseUnitPrecision(row)) }} {{ row.base_unit_name }}</span>
              </template>
            </el-table-column>
            <el-table-column label="设备编号" width="154" align="center">
              <template slot-scope="{row}">
                <span v-if="row.serial_tracking_mode === 'none'" class="muted-dash">-</span>
                <el-button
                  v-else
                  :type="row.serial_tracking_mode === 'required' && (row.serial_entries || []).length !== serialRequiredCount(row) ? 'danger' : 'text'"
                  size="mini"
                  class="serial-select-button"
                  @click="openSerialDialog(row)"
                >
                  {{ serialActionText(row) }}
                </el-button>
              </template>
            </el-table-column>
            <el-table-column label="备注" min-width="150">
              <template slot-scope="{row}">
                <el-input v-model.trim="row.remark" size="mini" maxlength="100" placeholder="请输入备注（非必填）" />
              </template>
            </el-table-column>
            <el-table-column label="操作" width="68" align="center" fixed="right">
              <template slot-scope="{$index}">
                <el-button type="text" size="mini" class="danger-link" icon="el-icon-delete" @click="removePurchaseLine($index)">移除</el-button>
              </template>
            </el-table-column>
          </el-table>

          <div class="selected-summary-bar">
            <span>明细行数：<b>{{ selectedLines.length }}</b> 行</span>
            <span>可退基本数量合计：<b>{{ purchaseAvailableTotal }}</b></span>
            <span>本次退货基本数量合计：<b class="brand-strong">{{ purchaseRequestedTotal }}</b></span>
          </div>
        </div>
      </div>

      <!-- 吸底操作栏 -->
      <div class="sticky-footer">
        <div class="sticky-left">
          <span>已选 <b>{{ selectedLines.length }}</b> 条明细</span>
          <span class="sep">·</span>
          <span>本次退货基本数量：<b class="brand-strong">{{ purchaseRequestedTotal }}</b></span>
          <span class="sep">·</span>
          <span :class="purchaseAllChecksOk ? 'ok-text' : 'warn-text'">
            {{ purchaseAllChecksOk ? '保存前校验已全部通过，可提交' : `尚有 ${purchaseChecksWithReason.length - purchasePassedCheckCount} 项待补全` }}
          </span>
        </div>
        <div class="sticky-right">
          <el-button size="small" @click="$router.push(basePath)">返回列表</el-button>
          <el-button size="small" class="draft-button" :loading="saving" :disabled="!reservation" @click="save(false)">保存草稿</el-button>
          <el-button size="small" type="success" icon="el-icon-check" :loading="saving" :disabled="!reservation" @click="save(true)">保存并提交</el-button>
        </div>
      </div>

      <!-- 设备编号选择弹窗 -->
      <el-dialog title="选择退货设备编号" :visible.sync="serialDialog.visible" width="760px" custom-class="master-style-dialog" append-to-body>
        <el-alert class="serial-required-alert" :closable="false" type="info" show-icon :title="serialDialogInstruction()" />
        <el-alert v-if="!serialDialog.loading && serialDialog.total === 0" class="serial-empty-alert" :closable="false" type="error" show-icon title="当前批次没有可用设备编号，请先核对入库编号状态，或取消占用该编号的未完成退货单。" />
        <div class="serial-dialog-summary">
          <span>{{ serialDialog.line && serialDialog.line.item_code }} / {{ serialDialog.line && serialDialog.line.item_name }}</span>
          <b>应选 {{ serialRequiredCount(serialDialog.line) }} 个，已选 {{ serialDialog.draftEntries.length }} 个</b>
        </div>
        <div class="serial-dialog-toolbar">
          <el-input v-model.trim="serialDialog.keyword" size="small" clearable placeholder="输入设备编号或厂家序列号" @keyup.enter.native="reloadSerials" />
          <el-button size="small" type="success" @click="reloadSerials">查询</el-button>
        </div>
        <el-table v-loading="serialDialog.loading" :data="serialDialog.rows" border size="mini">
          <el-table-column label="选择" width="58" align="center">
            <template slot-scope="{row}"><el-checkbox :value="isSerialSelected(row)" @change="toggleSerial(row, $event)" /></template>
          </el-table-column>
          <el-table-column prop="serial_no" label="设备编号 / 序列号" min-width="220" />
          <el-table-column prop="manufacturer_serial_no" label="厂家序列号" min-width="180"><template slot-scope="{row}">{{ row.manufacturer_serial_no || '-' }}</template></el-table-column>
          <el-table-column label="编号来源" width="110"><template slot-scope="{row}">{{ serialNumberSourceText(row.number_source) }}</template></el-table-column>
          <el-table-column label="状态" width="90"><template><el-tag size="mini" type="success">可用</el-tag></template></el-table-column>
        </el-table>
        <el-pagination
          background
          layout="total, prev, pager, next, sizes"
          :current-page.sync="serialDialog.page"
          :page-size.sync="serialDialog.per_page"
          :page-sizes="[10, 20, 50]"
          :total="serialDialog.total"
          @current-change="loadSerials"
          @size-change="reloadSerials"
        />
        <div slot="footer" class="dialog-footer-custom">
          <el-button size="small" @click="serialDialog.visible = false">取消</el-button>
          <el-button size="small" type="success" :disabled="serialConfirmDisabled()" @click="confirmSerialSelection">{{ serialConfirmText() }}</el-button>
        </div>
      </el-dialog>
    </template>

    <template v-else>
      <div class="page-head">
        <div class="head-left">
          <el-button circle size="small" icon="el-icon-arrow-left" @click="$router.push(basePath)" />
          <div class="head-titles">
            <div class="title-row">
              <h1>新建销售退货单</h1>
              <el-tag size="mini" type="success">草稿</el-tag>
            </div>
            <p class="subtitle">基于原销售订单履约物料快照创建退货申请</p>
          </div>
        </div>
        <div class="head-actions">
          <el-button size="small" icon="el-icon-back" @click="$router.push(basePath)">返回列表</el-button>
          <el-button size="small" class="draft-button" :loading="saving" :disabled="!reservation" @click="save(false)">保存草稿</el-button>
          <el-button size="small" type="success" :loading="saving" :disabled="!reservation" @click="save(true)">保存并提交确认</el-button>
        </div>
      </div>

      <div class="sales-layout">
        <main class="sales-main">
          <div class="card sales-header-card">
            <div class="sales-header-col">
              <span class="field-lbl">退货单号</span>
              <el-input :value="reservation && reservation.document_no" size="small" disabled class="erp-system-number" />
              <span class="field-lbl">退货日期 <b>*</b></span>
              <el-date-picker v-model="form.return_date" size="small" value-format="yyyy-MM-dd" style="width: 100%" />
            </div>
            <div class="sales-header-col">
              <span class="field-lbl">退货原因 <b>*</b></span>
              <el-select v-model="form.return_reason" size="small" placeholder="请选择退货原因" style="width: 100%">
                <el-option v-for="reason in salesReasons" :key="reason" :label="reason" :value="reason" />
              </el-select>
              <span class="field-lbl">退货类型 <b>*</b></span>
              <el-select v-model="form.return_type" size="small" style="width: 100%">
                <el-option label="退货退款（冲减应收）" value="refund" />
                <el-option label="换货（不冲减应收）" value="exchange" />
              </el-select>
              <span class="field-lbl">备注</span>
              <el-input v-model.trim="form.remark" type="textarea" :rows="2" maxlength="200" show-word-limit placeholder="请输入备注（选填）" />
            </div>
            <div class="order-snapshot">
              <h3>原销售订单信息（快照）</h3>
              <dl>
                <dt>订单号</dt><dd>{{ selectedSource && selectedSource.sales_order_no || '-' }}</dd>
                <dt>订单金额</dt><dd>{{ money(selectedSource && selectedSource.order_amount) }}</dd>
                <dt>客户名称</dt><dd>{{ selectedSource && selectedSource.customer_name || '-' }}</dd>
                <dt>已发货金额</dt><dd>{{ money(selectedSource && selectedSource.shipped_amount) }}</dd>
                <dt>下单日期</dt><dd>{{ selectedSource && selectedSource.order_date || '-' }}</dd>
                <dt>已申请退货金额</dt><dd>{{ money(selectedSource && selectedSource.returned_amount) }}</dd>
              </dl>
            </div>
          </div>

          <div class="card sales-source-card">
            <h3>选择原销售订单</h3>
            <div class="sales-source-filter">
              <el-input v-model="sourceQuery.order_no" size="small" clearable placeholder="请输入订单号" @keyup.enter.native="reloadSources"><template slot="prepend">订单号</template></el-input>
              <el-input v-model="sourceQuery.customer_keyword" size="small" clearable placeholder="请输入客户名称" @keyup.enter.native="reloadSources"><template slot="prepend">客户名称</template></el-input>
              <el-date-picker v-model="sourceDateRange" size="small" type="daterange" value-format="yyyy-MM-dd" range-separator="~" start-placeholder="开始日期" end-placeholder="结束日期" />
              <el-select v-model="sourceQuery.shipment_status" clearable size="small" placeholder="订单状态"><el-option label="已发货" value="shipped" /><el-option label="部分发货" value="partial_shipped" /></el-select>
              <el-button size="small" type="success" @click="reloadSources">查询</el-button>
              <el-button size="small" @click="resetSourceFilters">重置</el-button>
            </div>
            <el-table v-loading="loading" :data="sources" border size="mini" highlight-current-row @current-change="chooseSource">
              <el-table-column label="" width="48"><template slot-scope="{row}"><el-radio v-model="selectedSourceId" :label="row.id" @change="chooseSource(row)"><span /></el-radio></template></el-table-column>
              <el-table-column prop="sales_order_no" label="订单号" min-width="160" />
              <el-table-column prop="customer_name" label="客户名称" min-width="180" />
              <el-table-column prop="order_date" label="下单日期" width="110" />
              <el-table-column label="订单金额" width="120" align="right"><template slot-scope="{row}">{{ money(row.order_amount) }}</template></el-table-column>
              <el-table-column label="已发货金额" width="120" align="right"><template slot-scope="{row}">{{ money(row.shipped_amount) }}</template></el-table-column>
              <el-table-column label="已申请退货金额" width="130" align="right"><template slot-scope="{row}">{{ money(row.returned_amount) }}</template></el-table-column>
              <el-table-column prop="shipment_status" label="订单状态" width="100"><template><el-tag size="mini" type="success">已发货</el-tag></template></el-table-column>
            </el-table>
            <el-pagination background layout="total, sizes, prev, pager, next, jumper" :current-page.sync="sourceQuery.page" :page-size.sync="sourceQuery.per_page" :page-sizes="[10, 20, 50]" :total="sourceTotal" @current-change="loadSources" @size-change="reloadSources" />
          </div>

          <div class="card sales-lines-card">
            <h3>所选订单明细 <small>（基于原履约物料 快照）</small></h3>
            <el-table :data="selectedLines" border size="mini">
              <el-table-column label="选择" width="58" align="center"><template slot-scope="{row}"><el-checkbox v-model="row.selected" /></template></el-table-column>
              <el-table-column prop="product_name" label="Product" min-width="135" />
              <el-table-column prop="sku_name" label="SKU" min-width="130" />
              <el-table-column label="原履约物料" min-width="180"><template slot-scope="{row}">{{ row.item_code }} / {{ row.item_name }}</template></el-table-column>
              <el-table-column prop="base_unit_name" label="销售单位" width="85" />
              <el-table-column prop="shipped_qty" label="已发货" width="85" align="right" />
              <el-table-column prop="reserved_return_qty" label="已申请退货" width="95" align="right" />
              <el-table-column prop="available_return_qty" label="可退" width="80" align="right" />
              <el-table-column label="本次退货数量 *" width="130"><template slot-scope="{row}"><el-input-number v-model="row.requested_qty" size="mini" :min="0" :max="Number(row.available_return_qty)" :precision="2" :controls="false" :disabled="!row.selected" /></template></el-table-column>
              <el-table-column v-if="form.return_type === 'refund'" label="退货单价" width="120"><template slot-scope="{row}"><el-input-number v-if="row.default_refund_unit_price != null" v-model="row.refund_unit_price" size="mini" :min="0" :max="Number(row.default_refund_unit_price || 0)" :precision="4" :controls="false" :disabled="!row.selected" /></template></el-table-column>
              <el-table-column label="换算后基本数量" width="125" align="right"><template slot-scope="{row}">{{ (Number(row.requested_qty || 0) * Number(row.fulfillment_factor_snapshot || 1)).toFixed(2) }}</template></el-table-column>
              <el-table-column label="备注" min-width="150"><template slot-scope="{row}"><el-input v-model.trim="row.remark" size="mini" maxlength="100" placeholder="请输入（选填）" :disabled="!row.selected" /></template></el-table-column>
              <el-table-column label="删除" width="55" align="center"><template slot-scope="{$index}"><el-button type="text" icon="el-icon-delete" class="danger" @click="selectedLines.splice($index, 1)" /></template></el-table-column>
            </el-table>
            <el-pagination background layout="total, sizes, prev, pager, next, jumper" :current-page="1" :page-size="10" :total="selectedLines.length" />
          </div>

          <div class="erp-page-tip">
            <i class="el-icon-info" />
            <span>说明：调整本次退货数量或当前商品 SKU 默认物料，不会改变退货物料。退货物料始终以原履约物料快照为准。</span>
          </div>
        </main>
        <aside class="card check-card sales-check-card">
          <h3>退货校验结果</h3>
          <p v-for="item in checks" :key="item.text" :class="{ ok: item.ok }">
            <i :class="item.ok ? 'el-icon-success' : 'el-icon-warning'" />
            <span><b>{{ item.text }}</b><small>{{ item.hint }}</small></span>
          </p>
        </aside>
      </div>
    </template>
  </section>
</template>

<script>
import { purchaseScopes, purchaseScopeLabel, purchaseScopeMatches, validPurchaseScope } from '@/utils/purchaseManagementScope.mjs'
import { createPurchaseReturn, listPurchaseReturnSources, listPurchaseReturnSourceSerials, submitPurchaseReturn } from '@/api/erp/purchase'
import { confirmSalesReturn, createSalesReturn, listSalesReturnSources } from '@/api/erp/sales'
import { clearCreatePageReservation, reserveForCreatePage } from '@/utils/documentNumberReservation'

export default {
  props: { kind: { type: String, required: true } },
  data: () => ({
    saving: false,
    loading: false,
    reservation: null,
    sourceRevision: 0,
    scopeChanging: false,
    sources: [],
    sourceTotal: 0,
    selectedSource: null,
    selectedSourceId: null,
    selectedLines: [],
    sourceDateRange: [],
    sourceQuery: { keyword: '', order_no: '', customer_keyword: '', shipment_status: '', page: 1, per_page: 10 },
    serialDialog: { visible: false, loading: false, line: null, rows: [], total: 0, keyword: '', page: 1, per_page: 10, draftEntries: [] },
    purchaseReasons: ['质量问题', '采购错误', '规格不符', '包装破损', '供应商责任', '其他'],
    salesReasons: ['商品质量问题', '客户多发', '包装破损', '发错商品', '客户拒收', '其他'],
    form: {
      management_scope: 'factory',
      return_date: (() => {
        const date = new Date()
        const offset = date.getTimezoneOffset() * 60000
        return new Date(date.getTime() - offset).toISOString().slice(0, 10)
      })(),
      return_reason: '',
      return_type: 'refund',
      remark: ''
    }
  }),
  computed: {
    scopeOptions() { return purchaseScopes },
    title() { return this.kind === 'purchase' ? '采购退货' : '销售退货' },
    basePath() { return this.kind === 'purchase' ? '/purchase/returns' : '/sales/returns' },
    activeLines() { return this.kind === 'sales' ? this.selectedLines.filter(r => r.selected) : this.selectedLines },
    checks() {
      return [
        { text: this.kind === 'purchase' ? '已选择同一供应商的原采购批次' : '原订单已发货', hint: this.kind === 'sales' ? '订单已完成发货，可发起退货。' : '', ok: !!this.selectedSource },
        { text: this.kind === 'purchase' ? '已选择至少一条退货明细' : '使用原履约物料 快照', hint: this.kind === 'sales' ? '基于原履约物料 信息进行退货。' : '', ok: this.activeLines.length > 0 && this.activeLines.every(r => this.kind === 'purchase' || r.item_id) },
        { text: '退货数量为正数', hint: this.kind === 'sales' ? '本次退货数量均大于 0。' : '', ok: this.activeLines.length > 0 && this.activeLines.every(r => Number(r.requested_qty) > 0) },
        { text: '累计退货数量未超出已发货', hint: this.kind === 'sales' ? '所选商品累计退货数量未超出已发货数量。' : '', ok: this.activeLines.length > 0 && this.activeLines.every(r => this.kind === 'purchase' ? this.purchaseRequestedBaseQty(r) <= Number(r.available_return_base_qty) : Number(r.requested_qty) <= Number(r.available_return_qty)) },
        { text: '不存在重复明细', hint: this.kind === 'sales' ? '所选商品在退货单中未重复添加。' : '', ok: new Set(this.activeLines.map(r => this.kind === 'purchase' ? r.inventory_transaction_item_id : r.sales_order_line_id)).size === this.activeLines.length }
      ]
    },
    purchaseChecks() {
      const validQty = this.selectedLines.length > 0 && this.selectedLines.every(r => Number(r.requested_qty) > 0 && r.return_unit_id)
      const within = this.selectedLines.length > 0 && this.selectedLines.every(r => this.purchaseRequestedBaseQty(r) <= Number(r.available_return_base_qty))
      const oneReceipt = this.selectedLines.length > 0 && new Set(this.selectedLines.map(r => r.source_receipt_id)).size === 1
      const serialsOk = this.selectedLines.length > 0 && this.selectedLines.every(r => r.serial_tracking_mode !== 'required' || ((r.serial_entries || []).length === this.serialRequiredCount(r)))
      return [
        { text: '供应商一致性校验通过', ok: !!this.selectedSource && oneReceipt },
        { text: '同一到货单多明细校验通过', ok: oneReceipt },
        { text: '原始到货批次校验通过', ok: this.selectedLines.length > 0 && this.selectedLines.every(r => r.source_receipt_item_id && r.batch_no) },
        { text: '退货数量和单位完整', ok: validQty },
        { text: '本次折合基本数量不超过实际可退数量', ok: within },
        { text: '必需设备编号的明细已逐件选择', ok: serialsOk },
        { text: '未选择重复的原始到货批次', ok: new Set(this.selectedLines.map(r => r.inventory_transaction_item_id)).size === this.selectedLines.length }
      ]
    },
    purchaseChecksWithReason() {
      return [
        { text: '已选择退货原因', ok: !!this.form.return_reason },
        ...this.purchaseChecks
      ]
    },
    purchasePassedCheckCount() {
      return this.purchaseChecksWithReason.filter(item => item.ok).length
    },
    purchaseAllChecksOk() {
      return this.purchasePassedCheckCount === this.purchaseChecksWithReason.length
    },
    purchaseAvailableTotal() { return this.selectedLines.reduce((sum, row) => sum + Number(row.available_return_base_qty || 0), 0).toFixed(2) },
    purchaseRequestedTotal() { return this.selectedLines.reduce((sum, row) => sum + this.purchaseRequestedBaseQty(row), 0).toFixed(2) }
  },
  created() {
    if (this.kind === 'purchase' && validPurchaseScope(this.$route.query.management_scope)) {
      this.form.management_scope = this.$route.query.management_scope
    }
    this.reserveNumber()
    this.loadSources()
  },
  methods: {
    async reserveNumber() {
      try {
        this.reservation = await reserveForCreatePage(`${this.kind}_return`, `${this.basePath}/create`)
      } catch (error) {
        this.$message.error(error.userMessage || '退货单号预生成失败')
      }
    },
    async changeManagementScope(scope) {
      if (this.kind !== 'purchase' || scope === this.form.management_scope || this.saving || this.scopeChanging) return
      this.scopeChanging = true
      try {
        if (this.selectedLines.length) await this.$confirm('切换管理类型将清空已选择的退货批次及设备编号，是否继续？', '切换管理类型', { type: 'warning', confirmButtonText: '清空并切换' })
        this.sourceRevision++
        this.form.management_scope = scope
        this.selectedSource = null
        this.selectedLines = []
        this.serialDialog.visible = false
        this.serialDialog.line = null
        this.sources = []
        await this.reloadSources()
      } catch (error) {
        if (error !== 'cancel' && error !== 'close') this.$message.error(error.userMessage || '管理类型切换失败')
      } finally { this.scopeChanging = false }
    },
    scopeLabel(scope) { return purchaseScopeLabel(scope) },
    async loadSources() {
      const revision = ++this.sourceRevision
      this.loading = true
      try {
        const params = { ...this.sourceQuery, order_date_from: this.sourceDateRange && this.sourceDateRange[0], order_date_to: this.sourceDateRange && this.sourceDateRange[1] }
        if (this.kind === 'purchase') params.management_scope = this.form.management_scope
        const { data } = await (this.kind === 'purchase' ? listPurchaseReturnSources(params) : listSalesReturnSources(params))
        if (revision !== this.sourceRevision) return
        this.sources = (data.data || []).filter(row => this.kind !== 'purchase' || purchaseScopeMatches(row, this.form.management_scope))
        this.sourceTotal = data.total || 0
      } finally { if (revision === this.sourceRevision) this.loading = false }
    },
    reloadSources() { this.sourceQuery.page = 1; this.loadSources() },
    resetSourceFilters() { this.sourceDateRange = []; this.sourceQuery = { keyword: '', order_no: '', customer_keyword: '', shipment_status: '', page: 1, per_page: 10 }; this.loadSources() },
    chooseSource(row) {
      if (!row || this.kind === 'purchase') return
      this.selectedSource = row
      this.selectedSourceId = row.id
      this.selectedLines = (row.lines || []).filter(line => Number(line.available_return_qty) > 0).map(line => ({
        ...line,
        selected: true,
        requested_qty: Number(line.available_return_qty),
        refund_unit_price: line.refund_unit_price == null ? null : Number(line.refund_unit_price),
        default_refund_unit_price: line.refund_unit_price == null ? null : Number(line.refund_unit_price),
        remark: ''
      }))
    },
    isPurchaseSourceSelected(row) { return this.selectedLines.some(line => Number(line.inventory_transaction_item_id) === Number(row.inventory_transaction_item_id)) },
    togglePurchaseSource(row, checked) {
      const index = this.selectedLines.findIndex(line => Number(line.inventory_transaction_item_id) === Number(row.inventory_transaction_item_id))
      if (!checked) { if (index >= 0) this.removePurchaseLine(index); return }
      if (index >= 0) return
      if (this.purchaseSourceBlocked(row)) return this.$message.error(this.purchaseSourceBlockedReason(row))
      if (this.selectedLines.length && Number(this.selectedLines[0].source_receipt_id) !== Number(row.source_receipt_id)) {
        this.$message.error('一张采购退货单只能选择同一张原到货单的明细')
        return
      }
      const units = row.return_units || []
      const base = units.find(unit => unit.unit_type === 'base') || units[0]
      const line = { ...row, return_unit_id: base && base.unit_id, return_conversion_factor: Number(base && base.conversion_factor || 1), requested_qty: null, requested_qty_input: '', serial_entries: [], remark: '' }
      this.selectedLines.push(line)
      this.selectedSource = this.selectedLines[0]
    },
    purchaseSourceSerialBlocked(row) { return !!row && row.serial_tracking_mode === 'required' && Number(row.available_serial_count || 0) <= 0 },
    purchaseSourceBlocked(row) { return !purchaseScopeMatches(row, this.form.management_scope) || Number(row && row.available_return_base_qty || 0) <= 0 || this.purchaseSourceSerialBlocked(row) },
    purchaseSourceBlockedReason(row) {
      if (!purchaseScopeMatches(row, this.form.management_scope)) return '来源批次管理类型与退货单不一致或未明确'
      if (this.purchaseSourceSerialBlocked(row)) return '当前批次没有可用设备编号，不能直接退货'
      return '当前批次库存已被订单、工单或质量处理占用，没有可退数量'
    },
    removePurchaseLine(index) { this.selectedLines.splice(index, 1); this.selectedSource = this.selectedLines[0] || null },
    changePurchaseReturnUnit(row, unitId) {
      const hadQuantity = Number(row.requested_qty) > 0
      const previousBase = this.purchaseRequestedBaseQty(row)
      const unit = (row.return_units || []).find(item => Number(item.unit_id) === Number(unitId))
      const factor = Number(unit && unit.conversion_factor || 1)
      this.$set(row, 'return_conversion_factor', factor)
      if (!hadQuantity) {
        this.$set(row, 'requested_qty', null)
        this.$set(row, 'requested_qty_input', '')
        return
      }
      const precision = this.returnUnitPrecision(row)
      const converted = this.floorToPrecision(previousBase / factor, precision)
      const next = Math.min(this.purchaseReturnMax(row), converted)
      this.$set(row, 'requested_qty', next)
      this.$set(row, 'requested_qty_input', String(next))
    },
    purchaseRequestedBaseQty(row) { return this.roundToPrecision(Number(row.requested_qty || 0) * Number(row.return_conversion_factor || 1), this.baseUnitPrecision(row)) },
    purchaseReturnMax(row) { return this.floorToPrecision(Number(row.available_return_base_qty || 0) / Number(row.return_conversion_factor || 1), this.returnUnitPrecision(row)) },
    returnUnitPrecision(row) { const unit = (row.return_units || []).find(item => Number(item.unit_id) === Number(row.return_unit_id)); return Math.min(8, Math.max(0, Number(unit && unit.decimal_places != null ? unit.decimal_places : 4))) },
    baseUnitPrecision(row) { const unit = (row.return_units || []).find(item => item.unit_type === 'base'); return Math.min(8, Math.max(0, Number(unit && unit.decimal_places != null ? unit.decimal_places : 4))) },
    returnUnitName(row) { const unit = (row.return_units || []).find(item => Number(item.unit_id) === Number(row.return_unit_id)); return unit && unit.unit_name || row.base_unit_name || '' },
    purchaseReturnRemainder(row) { const represented = this.purchaseReturnMax(row) * Number(row.return_conversion_factor || 1); return Math.max(0, this.roundToPrecision(Number(row.available_return_base_qty || 0) - represented, this.baseUnitPrecision(row))) },
    floorToPrecision(value, precision) { const scale = Math.pow(10, precision); return Math.floor((Number(value || 0) + Number.EPSILON) * scale) / scale },
    roundToPrecision(value, precision) { const scale = Math.pow(10, precision); return Math.round((Number(value || 0) + Number.EPSILON) * scale) / scale },
    formatQty(value, precision) { return Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: precision }) },
    serialRequiredCount(row) { if (!row || row.serial_tracking_mode === 'none') return 0; const qty = this.purchaseRequestedBaseQty(row); return Math.max(0, Math.round(qty)) },
    serialSelectedCount(row) { return (row && row.serial_entries || []).length },
    serialActionText(row) {
      const selected = this.serialSelectedCount(row)
      const required = this.serialRequiredCount(row)
      if (!(Number(row.requested_qty) > 0)) return '请先填写退货数量'
      if (row.serial_tracking_mode !== 'required') return selected === 0 ? '选择编号（可选）' : `已选 ${selected} 个`
      return selected === 0 ? `选择编号（必选 ${required} 个）` : `已选 ${selected}/${required} 个`
    },
    serialConfirmDisabled() { const line = this.serialDialog.line; return !!line && line.serial_tracking_mode === 'required' && this.serialDialog.draftEntries.length !== this.serialRequiredCount(line) },
    serialDialogInstruction() { return this.serialDialog.line && this.serialDialog.line.serial_tracking_mode === 'required' ? '必管编号物料必须逐件选择本批次当前可用编号；确认后才能保存退货单。' : '可按实际退货实物选择本批次当前可用编号。' },
    serialConfirmText() { const line = this.serialDialog.line; return line && line.serial_tracking_mode === 'required' ? `确认选择（${this.serialDialog.draftEntries.length}/${this.serialRequiredCount(line)}）` : '确认选择' },
    serialNumberSourceText(value) { return ({ system_generated: '系统生成', supplier: '供应商编号', manufacturer: '厂家编号', manual: '手工录入' })[value] || value || '-' },
    async openSerialDialog(row) {
      if (!(Number(row.requested_qty) > 0)) return this.$message.error('请先填写本次退货数量，再选择设备编号')
      const required = this.serialRequiredCount(row)
      if (row.serial_tracking_mode === 'required' && Math.abs(this.purchaseRequestedBaseQty(row) - required) > 0.00000001) {
        return this.$message.error('必需设备编号的物料必须按整数件退货')
      }
      this.serialDialog.line = row
      this.serialDialog.keyword = ''
      this.serialDialog.page = 1
      this.serialDialog.draftEntries = (row.serial_entries || []).map(entry => ({ ...entry }))
      this.serialDialog.visible = true
      await this.loadSerials()
    },
    async loadSerials() {
      if (!this.serialDialog.line) return
      this.serialDialog.loading = true
      try {
        const { data } = await listPurchaseReturnSourceSerials(this.serialDialog.line.inventory_transaction_item_id, {
          keyword: this.serialDialog.keyword,
          page: this.serialDialog.page,
          per_page: this.serialDialog.per_page
        })
        this.serialDialog.rows = data.data || []
        this.serialDialog.total = data.total || 0
      } finally { this.serialDialog.loading = false }
    },
    reloadSerials() { this.serialDialog.page = 1; this.loadSerials() },
    isSerialSelected(row) { return this.serialDialog.draftEntries.some(entry => Number(entry.id) === Number(row.id)) },
    toggleSerial(row, checked) {
      const index = this.serialDialog.draftEntries.findIndex(entry => Number(entry.id) === Number(row.id))
      if (!checked) { if (index >= 0) this.serialDialog.draftEntries.splice(index, 1); return }
      if (index >= 0) return
      const required = this.serialRequiredCount(this.serialDialog.line)
      if (this.serialDialog.draftEntries.length >= required) {
        this.$message.warning(`本次退货只需选择 ${required} 个设备编号`)
        return
      }
      this.serialDialog.draftEntries.push({ ...row })
    },
    confirmSerialSelection() {
      const line = this.serialDialog.line
      const required = this.serialRequiredCount(line)
      if (line && line.serial_tracking_mode === 'required' && this.serialDialog.draftEntries.length !== required) {
        return this.$message.error(`本次退货 ${required} 件，必须选择 ${required} 个设备编号`)
      }
      this.$set(line, 'serial_entries', this.serialDialog.draftEntries.map(entry => ({ ...entry })))
      this.serialDialog.visible = false
    },
    purchaseReturnMin(row) { return 1 / Math.pow(10, this.returnUnitPrecision(row)) },
    handlePurchaseQtyInput(row, value) {
      const raw = String(value == null ? '' : value).replace(/[^0-9.]/g, '')
      const firstDot = raw.indexOf('.')
      const cleaned = firstDot < 0 ? raw : raw.slice(0, firstDot + 1) + raw.slice(firstDot + 1).replace(/\./g, '')
      this.$set(row, 'requested_qty_input', cleaned)
      const numeric = cleaned !== '' && cleaned !== '.' ? Number(cleaned) : NaN
      this.$set(row, 'requested_qty', Number.isFinite(numeric) ? numeric : null)
      this.handlePurchaseQtyChange(row)
    },
    normalizePurchaseQty(row) {
      const numeric = Number(row.requested_qty)
      if (!(numeric > 0)) {
        this.$set(row, 'requested_qty', null)
        this.$set(row, 'requested_qty_input', '')
        this.handlePurchaseQtyChange(row)
        return
      }
      const precision = this.returnUnitPrecision(row)
      const normalized = Math.min(this.purchaseReturnMax(row), Math.max(this.purchaseReturnMin(row), this.floorToPrecision(numeric, precision)))
      this.$set(row, 'requested_qty', normalized)
      this.$set(row, 'requested_qty_input', String(normalized))
      this.handlePurchaseQtyChange(row)
    },
    handlePurchaseQtyChange(row) {
      if (!row || row.serial_tracking_mode === 'none') return
      const required = this.serialRequiredCount(row)
      if ((row.serial_entries || []).length > required) this.$set(row, 'serial_entries', row.serial_entries.slice(0, required))
    },
    payload() {
      const header = { ...this.form, reservation_token: this.reservation.reservation_token, creation_session_id: this.reservation.creation_session_id }
      if (this.kind === 'purchase') {
        return {
          ...header,
          return_scope: 'posted_inventory',
          source_receipt_id: this.selectedSource && this.selectedSource.source_receipt_id,
          supplier_id: this.selectedSource && this.selectedSource.supplier_id,
          items: this.selectedLines.map(row => ({
            source_receipt_item_id: row.source_receipt_item_id,
            warehouse_id: row.warehouse_id,
            location_id: row.location_id,
            batch_no: row.batch_no,
            requested_return_qty: row.requested_qty,
            return_unit_id: row.return_unit_id,
            serial_ids: (row.serial_entries || []).map(entry => entry.id),
            remark: row.remark || null
          }))
        }
      }
      return {
        ...header,
        sales_order_id: this.selectedSource && this.selectedSource.id,
        items: this.activeLines.map(row => ({
          sales_order_line_id: row.sales_order_line_id,
          requested_sales_qty: row.requested_qty,
          ...(row.refund_unit_price == null ? {} : { refund_unit_price: this.form.return_type === 'refund' ? row.refund_unit_price : null }),
          remark: row.remark || null
        }))
      }
    },
    money(value) { return `¥ ${Number(value || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}` },
    async save(advance) {
      if (this.saving || this.scopeChanging || !this.reservation) return
      if (this.kind === 'purchase' && (!validPurchaseScope(this.form.management_scope) || this.selectedLines.some(row => !purchaseScopeMatches(row, this.form.management_scope)))) {
        return this.$message.error('采购退货明细必须与单据管理类型一致')
      }
      const validations = this.kind === 'purchase' ? this.purchaseChecks : this.checks
      if (!this.form.return_reason || !validations.every(item => item.ok)) return this.$message.error('请先补全退货原因、业务来源、合法数量和设备编号')
      this.saving = true
      let draftSaved = false
      try {
        const response = await (this.kind === 'purchase' ? createPurchaseReturn(this.payload()) : createSalesReturn(this.payload()))
        const row = response.data.data || response.data
        clearCreatePageReservation(this.reservation)
        draftSaved = true
        try {
          if (advance) await (this.kind === 'purchase' ? submitPurchaseReturn(row.id) : confirmSalesReturn(row.id))
        } finally { this.$router.replace(`${this.basePath}/${row.id}/detail`) }
        this.$message.success(advance ? '已保存并进入下一状态' : '草稿已保存')
      } catch (error) { this.$message.error((draftSaved ? '草稿已保存，提交失败：' : '') + (error.userMessage || '退货单保存失败')) }
      finally { this.saving = false }
    }
  }
}
</script>

<style scoped>
.return-form-page {
  padding: 16px 20px 82px;
  min-height: calc(100vh - 52px);
  background: #f5f7f9;
  color: #0f172a;
  box-sizing: border-box;
  overflow-x: clip;
}

.mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
}

/* 顶部白底页头卡片 */
.page-head {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 20px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.head-titles {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.title-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.title-row h1 {
  margin: 0;
  font-size: 19px;
  font-weight: 700;
  color: #0f172a;
}

.doc-no-pill {
  padding: 2px 9px;
  border-radius: 999px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  font-size: 12px;
  font-weight: 600;
}

.subtitle {
  margin: 0;
  font-size: 12px;
  color: #64748b;
}

.head-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

.draft-button {
  color: #008b4b;
  border-color: #008b4b;
  background: #ffffff;
}

.draft-button:hover,
.draft-button:focus {
  background: #f0fdf4;
  color: #00763f;
  border-color: #00763f;
}

/* 水平保存前校验条 */
.check-strip {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 16px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.check-strip-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.check-strip-title {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.check-strip-title .ok {
  color: #008b4b;
}

.check-strip-title .warn {
  color: #d97706;
}

.check-progress-pill {
  padding: 2px 10px;
  border-radius: 999px;
  font-size: 11px;
  font-weight: 600;
}

.check-progress-pill.is-ok {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
}

.check-progress-pill.is-pending {
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #b45309;
}

.check-chip-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.check-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 4px 10px;
  border-radius: 6px;
  font-size: 12px;
  border: 1px solid transparent;
}

.check-chip.is-ok {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #166534;
}

.check-chip.is-ok i {
  color: #008b4b;
}

.check-chip.is-pending {
  background: #fffbeb;
  border-color: #fde68a;
  color: #92400e;
}

.check-chip.is-pending i {
  color: #d97706;
}

/* 全宽分块卡片 */
.purchase-main-full {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.section-card,
.card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 18px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  box-sizing: border-box;
}

.section-head-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 12px;
}

.section-title-bar {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 12px;
}

.section-head-row .section-title-bar {
  margin-bottom: 0;
}

.bar-accent {
  width: 4px;
  height: 16px;
  border-radius: 2px;
  background: #008b4b;
  flex-shrink: 0;
}

.section-title-bar h3 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.section-badge {
  padding: 1px 8px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #475569;
  font-size: 11px;
  font-weight: 600;
}

.section-badge.is-brand {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}

.source-locked-tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  border-radius: 6px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #166534;
  font-size: 12px;
}

.purchase-header-form {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 0 20px;
}

.purchase-header-form .remark-field {
  grid-column: 1 / -1;
}

.purchase-header-form ::v-deep .el-form-item {
  margin-bottom: 12px;
}

.purchase-header-form ::v-deep .el-form-item__label {
  line-height: 20px;
  padding: 0 0 6px;
  font-weight: 600;
  color: #334155;
  font-size: 12px;
}

.purchase-header-form ::v-deep .el-select {
  width: 100%;
}

.purchase-header-form ::v-deep .el-textarea__inner {
  min-height: 58px !important;
}

.source-toolbar {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.source-toolbar .el-input {
  width: 360px;
  max-width: 100%;
}

.item-cell-compact {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.code-badge {
  padding: 1px 6px;
  border-radius: 4px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  color: #334155;
  font-size: 11px;
  font-weight: 600;
}

.item-name-bold {
  font-weight: 600;
  color: #0f172a;
}

.qty-ok {
  color: #008b4b;
  font-weight: 700;
  font-variant-numeric: tabular-nums;
}

.qty-zero {
  color: #94a3b8;
  font-variant-numeric: tabular-nums;
}

.qty-main {
  font-variant-numeric: tabular-nums;
  white-space: nowrap;
  font-weight: 600;
}

.qty-main.is-brand {
  color: #008b4b;
}

.qty-conversion-hint {
  margin-top: 3px;
  color: #b45309;
  font-size: 11px;
  line-height: 1.35;
  white-space: normal;
}

.muted-dash {
  color: #94a3b8;
}

.danger-link {
  color: #ef4444 !important;
  font-weight: 500;
}

.danger-link:hover {
  color: #dc2626 !important;
}

.selected-summary-bar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  flex-wrap: wrap;
  gap: 24px;
  padding-top: 12px;
  margin-top: 10px;
  border-top: 1px solid #f1f5f9;
  font-size: 13px;
  color: #475569;
}

.selected-summary-bar b {
  color: #0f172a;
}

.brand-strong {
  color: #008b4b !important;
}

.pager-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-top: 14px;
  padding-top: 12px;
  border-top: 1px solid #f1f5f9;
}

.pager-info {
  font-size: 12px;
  color: #64748b;
}

/* 吸底操作栏 */
.sticky-footer {
  position: sticky;
  bottom: 0;
  z-index: 25;
  margin-top: 16px;
  padding: 12px 20px;
  background: rgba(255, 255, 255, 0.96);
  backdrop-filter: blur(6px);
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 -4px 16px rgba(15, 23, 42, 0.06);
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.sticky-left {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  font-size: 12px;
  color: #475569;
}

.sticky-left .sep {
  color: #cbd5e1;
}

.sticky-left .ok-text {
  color: #008b4b;
  font-weight: 600;
}

.sticky-left .warn-text {
  color: #b45309;
  font-weight: 600;
}

.sticky-right {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
}

/* 弹窗内部样式 */
.serial-dialog-summary {
  display: flex;
  justify-content: space-between;
  gap: 16px;
  padding: 10px 12px;
  margin: 10px 0;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
}

.serial-dialog-summary b {
  color: #008b4b;
}

.serial-dialog-toolbar {
  display: flex;
  gap: 10px;
  margin-bottom: 10px;
}

.serial-dialog-toolbar .el-input {
  flex: 1;
}

.serial-dialog-summary + .serial-dialog-toolbar + .el-table + .el-pagination {
  padding-top: 14px;
  text-align: right;
}

.source-serial-hint {
  margin-top: 3px;
  color: #008b4b;
  font-size: 11px;
}

.source-serial-hint.blocked {
  color: #dc2626;
}

.serial-empty-alert {
  margin: 10px 0;
}

.dialog-footer-custom {
  display: flex;
  justify-content: flex-end;
  gap: 8px;
}

/* 销售退货分支保留布局与升级 */
.sales-layout {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 270px;
  gap: 14px;
  align-items: stretch;
}

.sales-main {
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.sales-header-card {
  display: grid;
  grid-template-columns: 0.9fr 1fr 1.55fr;
  gap: 20px;
  padding: 18px;
}

.sales-header-col {
  display: grid;
  grid-template-columns: 1fr;
  gap: 8px;
}

.field-lbl {
  font-size: 12px;
  font-weight: 600;
  color: #334155;
}

.field-lbl:not(:first-child) {
  margin-top: 6px;
}

.field-lbl b {
  color: #ef4444;
}

.order-snapshot {
  padding-left: 20px;
  border-left: 1px solid #e2e8f0;
}

.order-snapshot h3 {
  font-size: 14px;
  margin: 0 0 12px;
}

.order-snapshot dl {
  display: grid;
  grid-template-columns: 80px 1fr 100px 1fr;
  gap: 12px 10px;
  margin: 0;
  font-size: 12px;
}

.order-snapshot dt {
  color: #64748b;
}

.order-snapshot dd {
  margin: 0;
  font-weight: 500;
}

.sales-source-filter {
  display: grid;
  grid-template-columns: 1.15fr 1.15fr 1.05fr 0.8fr auto auto;
  gap: 10px;
  margin-bottom: 14px;
}

.sales-source-card > .el-pagination,
.sales-lines-card > .el-pagination {
  padding: 14px 0 0;
  text-align: center;
}

.sales-lines-card h3 small {
  color: #64748b;
  font-weight: 400;
}

.check-card h3 {
  font-size: 16px;
  margin: 0 0 14px;
}

.sales-check-card p {
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 10px 0;
  margin: 0;
  color: #b45309;
  font-size: 13px;
}

.sales-check-card p.ok {
  color: #0f172a;
}

.sales-check-card p i {
  margin: 2px 0 0;
  color: #d97706;
}

.sales-check-card p.ok i {
  color: #008b4b;
}

.sales-check-card p span {
  display: flex;
  flex-direction: column;
}

.sales-check-card p b {
  font-size: 13px;
}

.sales-check-card p small {
  margin-top: 4px;
  color: #64748b;
  line-height: 1.5;
}

.danger {
  color: #ef4444;
}

/* 主题绿控件统一覆盖 */
.return-form-page ::v-deep .el-button--success {
  background-color: #008b4b;
  border-color: #008b4b;
}

.return-form-page ::v-deep .el-button--success:hover,
.return-form-page ::v-deep .el-button--success:focus {
  background-color: #00763f;
  border-color: #00763f;
}

.return-form-page ::v-deep .el-button--success.is-plain {
  background-color: #f0fdf4;
  border-color: #bbf7d0;
  color: #008b4b;
}

.return-form-page ::v-deep .el-button--success.is-plain:hover,
.return-form-page ::v-deep .el-button--success.is-plain:focus {
  background-color: #008b4b;
  border-color: #008b4b;
  color: #ffffff;
}

.return-form-page ::v-deep .el-table th {
  background: #f8fafc !important;
  color: #475569;
  font-weight: 600;
  font-size: 12px;
}

.return-form-page ::v-deep .el-table td,
.return-form-page ::v-deep .el-table th {
  padding: 7px 0;
}

.return-form-page ::v-deep .el-input__inner:focus,
.return-form-page ::v-deep .el-textarea__inner:focus {
  border-color: #008b4b;
}

.return-form-page ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner,
.return-form-page ::v-deep .el-checkbox__input.is-indeterminate .el-checkbox__inner,
.return-form-page ::v-deep .el-radio__input.is-checked .el-radio__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}

.return-form-page ::v-deep .el-checkbox__inner:hover,
.return-form-page ::v-deep .el-radio__inner:hover {
  border-color: #008b4b;
}

.return-form-page ::v-deep .el-pagination.is-background .el-pager li:not(.disabled).active {
  background-color: #008b4b !important;
  color: #ffffff !important;
  border-color: #008b4b !important;
  border-radius: 4px;
}

.return-form-page ::v-deep .el-input-group__prepend {
  padding: 0 10px;
  background: #fff;
  color: #475569;
}

.return-form-page ::v-deep .el-textarea__inner {
  min-height: 58px !important;
}

@media (max-width: 1350px) {
  .sales-layout {
    grid-template-columns: minmax(0, 1fr) 240px;
  }
  .sales-header-card {
    gap: 14px;
  }
  .order-snapshot {
    padding-left: 14px;
  }
  .sales-source-filter {
    grid-template-columns: 1fr 1fr 1fr;
  }
}

@media (max-width: 1100px) {
  .purchase-header-form {
    grid-template-columns: 1fr 1fr;
  }
  .sales-layout {
    grid-template-columns: 1fr;
  }
  .sales-header-card {
    grid-template-columns: 1fr 1fr;
  }
  .order-snapshot {
    grid-column: span 2;
    border-left: 0;
    border-top: 1px solid #e2e8f0;
    padding-left: 0;
    padding-top: 14px;
  }
  .sales-source-filter {
    grid-template-columns: 1fr 1fr;
  }
}

@media (max-width: 780px) {
  .return-form-page {
    padding: 12px 10px 84px;
  }
  .purchase-header-form,
  .sales-header-card,
  .sales-source-filter {
    grid-template-columns: minmax(0, 1fr);
  }
  .order-snapshot {
    grid-column: auto;
  }
  .source-toolbar {
    width: 100%;
  }
  .source-toolbar .el-input {
    width: auto;
    flex: 1;
    min-width: 0;
  }
  .selected-summary-bar {
    justify-content: flex-start;
    gap: 12px;
  }
}
</style>
