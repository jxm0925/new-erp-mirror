<template>
  <div v-loading="viewLoading" class="crm-page dashboard-page">
    <header class="crm-head dashboard-head"><span class="crm-head-icon"><i class="el-icon-data-analysis" /></span><h1>{{ tab === 'income' ? '收入统计' : '经营看板' }}</h1><div class="dashboard-head-actions"><span v-if="currentView" class="dashboard-view-name">{{ currentView.name }}</span><el-button type="success" size="small" icon="el-icon-setting" :disabled="!metadataReady || viewLoading" @click="openConfiguration">配置看板</el-button><el-button size="small" icon="el-icon-document" :disabled="!metadataReady || viewLoading" @click="openConfiguration">保存视图</el-button></div></header>
    <el-tabs v-model="tab" class="dashboard-tabs" @tab-click="changeTab"><el-tab-pane v-for="item in tabs" :key="item.key" :label="item.label" :name="item.key" /></el-tabs>
    <el-alert v-if="metadataError" :title="metadataError" type="error" :closable="false" show-icon />
    <form class="dashboard-filters" @submit.prevent="applyFilters">
      <label>{{ tab === 'income' || tab === 'sales' ? '成交日期' : '日期' }}<el-date-picker v-model="draft.range" type="daterange" range-separator="至" start-placeholder="开始日期" end-placeholder="结束日期" value-format="yyyy-MM-dd" size="small" popper-class="crm-date-popper" :clearable="false" /></label>
      <label>部门<el-select v-model="draft.department_legacy_id" size="small" clearable placeholder="全部授权部门" popper-class="crm-select-popper" @change="departmentChanged"><el-option v-for="item in departmentOptions" :key="item.id" :label="item.name" :value="item.id" /></el-select></label>
      <label>小组<el-select v-model="draft.group_legacy_id" size="small" clearable placeholder="全部" popper-class="crm-select-popper" @change="salesScopeChanged"><el-option v-for="item in groupOptions" :key="item.id" :label="item.name" :value="item.id" /></el-select></label>
      <label class="dashboard-sales-filter">销售<paged-record-select v-if="metadataReady" v-model="draft.sales_user_legacy_id" :request="salesRequest" :params="salesPickerParams" :columns="salesColumns" :label="salesLabel" title="选择销售" placeholder="全部" :selected-rows.sync="selectedSales" @change="draft.sales_user_unassigned = false" /></label>
      <label>业务线<el-select v-model="draft.business_line" size="small" clearable placeholder="全部" popper-class="crm-select-popper"><el-option v-for="item in metadata.business_lines || []" :key="item" :value="item" :label="item" /></el-select></label>
      <div class="dashboard-filter-actions"><el-button native-type="submit" size="small" type="success" :disabled="!metadataReady" :loading="overview.loading">查询</el-button><el-button size="small" @click="resetFilters">重置</el-button></div>
    </form>
    <div class="dashboard-currency"><label>币种<el-select v-model="draft.currency" size="mini" placeholder="分币种" clearable popper-class="crm-select-popper" @change="applyFilters"><el-option v-for="item in metadata.currencies || []" :key="item" :label="item" :value="item" /></el-select></label><span>金额单位：万</span></div>
    <el-alert v-if="overview.error" :title="overview.error" type="error" :closable="false" show-icon />

    <el-alert v-if="viewError" :title="viewError" type="warning" :closable="false" show-icon />
    <el-alert v-for="(warning,index) in viewWarnings" :key="'view-warning-'+index" :title="viewWarningText(warning)" type="warning" :closable="false" show-icon />
    <div class="dashboard-configured-grid">
      <dashboard-widget v-for="definition in metricDefinitions" :key="definition.key" :configuration="widget(definition.key)" :page="tab" :metric-columns="metricColumns"><dashboard-metrics :items="[configuredMetric(definition)]" :columns="1" @drilldown="openDrill" /></dashboard-widget>
        <dashboard-widget :configuration="widget('overview_sales')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('overview_sales') }}</h3><div><el-button type="text" @click="goTab('sales')">进入销售分析<i class="el-icon-arrow-right" /></el-button><el-button v-if="can('orders') && can('amounts')" type="text" @click="openDrill({kind:'orders'},'销售经营明细')">查看明细<i class="el-icon-arrow-right" /></el-button></div></div><dashboard-chart title="销售经营对比" :rows="overviewSalesRows" :series="salesChartSeries" :empty-text="!scope.currency ? '选择币种查看金额对比' : overview.error || '暂无销售经营数据'" @select="salesChartDrill" /></dashboard-widget>
        <dashboard-widget :configuration="widget('overview_stages')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('overview_stages') }}</h3><div><el-button type="text" @click="goTab('pipeline')">进入商机进度<i class="el-icon-arrow-right" /></el-button><el-button v-if="can('opportunities')" type="text" @click="openDrill({kind:'pipeline_active'},'当前进行中商机')">查看明细<i class="el-icon-arrow-right" /></el-button></div></div><el-alert v-if="pipeline.error" :title="pipeline.error" type="error" :closable="false" /><dashboard-chart v-loading="pipeline.loading" title="当前商机阶段" :rows="overviewStageRows" :empty-text="can('opportunities') ? pipeline.error || '暂无进行中商机' : '无商机查看权限'" @select="row => openDrill(row.query,row.label)" /></dashboard-widget>
        <dashboard-widget :configuration="widget('overview_industry')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('overview_industry') }}</h3><el-button type="text" @click="goTab('dimensions')">进入行业与客户<i class="el-icon-arrow-right" /></el-button></div><dashboard-chart v-loading="industry.loading" title="客户行业分布" :rows="industryRows" :empty-text="can('customers') ? industry.error || '暂无行业数据' : '无客户查看权限'" @select="row => openDrill(row.drilldown,row.label)" /><footer v-if="can('customers')" class="crm-pager"><el-pagination small background layout="total, prev, next" :total="total(industry)" :page-size="industry.per_page" :current-page="industry.page" @current-change="industryPage" /></footer></dashboard-widget>
        <dashboard-widget :configuration="widget('overview_purchase')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('overview_purchase') }}</h3><el-button type="text" @click="goDimension('purchase_scale')">进入行业与客户<i class="el-icon-arrow-right" /></el-button></div><dashboard-chart v-loading="purchase.loading" title="客户年采购力分布" :rows="purchaseRows" :empty-text="can('customers') ? purchase.error || '暂无采购力数据' : '无客户查看权限'" @select="row => openDrill(row.drilldown,row.label)" /><footer v-if="can('customers')" class="crm-pager"><el-pagination small background layout="total, prev, next" :total="total(purchase)" :page-size="purchase.per_page" :current-page="purchase.page" @current-change="purchasePage" /></footer></dashboard-widget>
        <dashboard-widget :configuration="widget('overview_followups')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('overview_followups') }}</h3><el-button type="text" @click="goTab('sources')">查看明细<i class="el-icon-arrow-right" /></el-button></div><el-alert v-if="followups.error" :title="followups.error" type="error" :closable="false" /><div v-loading="followups.loading" class="dashboard-reminders"><button v-for="item in reminderCards" :key="item.key" type="button" :class="item.tone" :disabled="!item.drilldown" @click="openDrill(item.drilldown,item.label)"><span>{{ item.label }}</span><strong>{{ count(item.value) }}</strong><small>{{ item.caption }}</small></button></div><div class="dashboard-reminder-links"><el-button v-if="contactCan('overdue')" type="text" icon="el-icon-user" @click="openDrill(contactQuery('overdue'),'逾期跟进')">查看逾期客户</el-button><el-button v-if="contactCan('pending_accept')" type="text" icon="el-icon-tickets" @click="openDrill(contactQuery('pending_accept'),'待接收线索')">查看待接收线索</el-button></div></dashboard-widget>

      <dashboard-widget :configuration="widget('sales_table')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('sales_table') }}</h3><el-button v-if="can('customers')" type="text" @click="openDrill({kind:'customers'},'客户明细')">查看明细<i class="el-icon-arrow-right" /></el-button></div><dashboard-sales-table v-bind="tableProps(sales)" :currency="scope.currency" @page="salesPage" @size="salesSize" @person="openPersonal" @drilldown="openDrill" @orders="selectOrders" /></dashboard-widget>
      <dashboard-widget :configuration="widget('sales_funnels')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('sales_funnels') }}</h3></div><div class="dashboard-cohort-filter"><label>同批线索<el-date-picker v-model="cohort.range" type="daterange" size="mini" value-format="yyyy-MM-dd" range-separator="至" popper-class="crm-date-popper" :clearable="false" /></label><label>观察截至<el-date-picker v-model="cohort.observed_on" type="date" size="mini" value-format="yyyy-MM-dd" popper-class="crm-date-popper" :clearable="false" /></label><el-button size="mini" type="success" :disabled="!can('leads')" @click="loadSales">查询</el-button></div><div v-if="!can('leads')" class="dashboard-empty">无线索查看权限</div><div v-else class="dashboard-funnel-grid"><article v-for="row in rows(sales)" :key="row.sales_user_legacy_id || 'unassigned'" class="dashboard-funnel-card"><div class="dashboard-panel-head"><h3><el-button type="text" @click="openPersonal(row)">{{ row.sales_user_name || '未归属销售' }}</el-button></h3><span>整体转化率：<strong>{{ funnelRate(row.funnel) }}</strong></span><el-button v-if="row.funnel && row.funnel.can" type="text" @click="openDrill(personFunnelQuery(row,'recorded'),'同批线索推进')">查看推进明细<i class="el-icon-arrow-right" /></el-button></div><dashboard-chart :title="(row.sales_user_name || '未归属销售') + '同批推进漏斗'" type="funnel" :height="280" :rows="funnelRows(row.funnel)" :empty-text="row.funnel && row.funnel.can === false ? '无线索查看权限' : '暂无有据可查的同批线索'" @select="point => openDrill({...salesFilter(row),...funnelScope(row.funnel),...point.query},point.label)" /><small v-if="funnelUnknown(row.funnel)" class="crm-muted">部分阶段历史待核实，未知未计入已到达数。</small></article></div></dashboard-widget>

      <div v-if="tab === 'dimensions'" class="dashboard-dimension-tabs dashboard-config-full"><span>分析维度：</span><el-button v-for="item in dimensionOptions" :key="item.value" size="small" :type="dimension === item.value ? 'success' : 'default'" :disabled="!canDimension(item.value)" @click="changeDimension(item.value)">{{ item.label }}</el-button></div>
        <dashboard-widget :configuration="widget('dimension_distribution')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('dimension_distribution') }}</h3><el-button v-if="can('customers')" type="text" @click="openDrill({kind:'customers'},'客户')">查看明细<i class="el-icon-arrow-right" /></el-button></div><p class="dashboard-caption">客户去重计数 · 当前归属{{ dimensionTotal === null ? '' : ' · 合计 ' + count(dimensionTotal) }}</p><el-alert v-if="dimensions.error" :title="dimensions.error" type="error" :closable="false" /><dashboard-chart v-loading="dimensions.loading" :title="'客户' + dimensionLabel + '分布'" :height="260" :rows="dimensionRows" :empty-text="can('customers') ? dimensions.error || '暂无数据' : '无客户查看权限'" @select="row => openDrill(row.drilldown,row.label)" /></dashboard-widget>
        <dashboard-widget :configuration="widget('dimension_cross')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('dimension_cross') }}</h3></div><el-alert v-if="cross.error" :title="cross.error" type="error" :closable="false" /><div v-if="!can('customers')" class="dashboard-empty">无客户查看权限</div><template v-else><el-table v-loading="cross.loading" :data="crossRows" size="small" border><el-table-column label="销售" min-width="105"><template slot-scope="{row}"><el-button type="text" @click="openPersonal(row)">{{ row.sales_user_name || '未归属销售' }}</el-button></template></el-table-column><el-table-column v-for="column in crossColumns" :key="crossKey(column)" :label="column.label" min-width="100" align="right"><template slot-scope="{row}"><el-button v-if="crossCell(row,column)" type="text" @click="openDrill(crossCell(row,column).drilldown,column.label)">{{ count(crossCell(row,column).customer_count) }}</el-button><span v-else>—</span></template></el-table-column><el-table-column label="去重合计" min-width="100" align="right"><template slot-scope="{row}"><el-button type="text" @click="openDrill(row.drilldown,row.sales_user_name + '客户')">{{ count(row.customer_count) }}</el-button></template></el-table-column><template slot="empty">{{ cross.error || '当前条件下暂无交叉数据' }}</template></el-table><div class="dashboard-cross-pagers"><label>销售<el-pagination small background layout="total, prev, next" :total="total(cross)" :page-size="cross.per_page" :current-page="cross.page" @current-change="crossPage" /></label><label>{{ dimensionLabel }}<el-pagination small background layout="total, prev, next" :total="crossDimensionTotal" :page-size="10" :current-page="crossDimensionPage" @current-change="crossDimensionChange" /></label></div></template></dashboard-widget>
      <dashboard-widget :configuration="widget('dimension_table')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('dimension_table') }}</h3></div><el-table v-loading="dimensions.loading" :data="dimensionRows" size="small" border :show-summary="!!dimensions.data" :summary-method="dimensionSummaries"><el-table-column prop="label" :label="dimensionLabel" min-width="150" /><el-table-column prop="customer_count" label="客户数" min-width="110" align="right"><template slot-scope="{row}"><el-button type="text" @click="openDrill(row.drilldown,row.label)">{{ count(row.customer_count) }}</el-button></template></el-table-column><el-table-column prop="open_opportunity_count" label="进行中商机" min-width="120" align="right"><template slot-scope="{row}"><el-button v-if="row.open_opportunity_count !== null && row.open_opportunity_count !== undefined" type="text" @click="openDrill(dimensionQuery(row,'dimension_opportunities'),row.label + '商机')">{{ count(row.open_opportunity_count) }}</el-button><span v-else>—</span></template></el-table-column><el-table-column prop="expected_amounts" label="待成交金额（万）" min-width="170" align="right"><template slot-scope="{row}"><el-button v-if="row.expected_amounts" type="text" @click="openDrill(dimensionQuery(row,'dimension_opportunities'),row.label + '商机')">{{ expectedText(row.expected_amounts) }}</el-button><span v-else>—</span></template></el-table-column><el-table-column prop="deal_amount" label="本期成交金额（万）" min-width="180" align="right"><template slot-scope="{row}"><el-button v-if="row.money_summary" type="text" @click="openDrill(dimensionQuery(row,'dimension_orders'),row.label + '订单')">{{ money(row.money_summary,'deal_amount',scope.currency) }}</el-button><span v-else>—</span></template></el-table-column><el-table-column label="操作" min-width="125"><template slot-scope="{row}"><el-button type="text" @click="openDrill(row.drilldown,row.label)">查看客户<i class="el-icon-arrow-right" /></el-button></template></el-table-column><template slot="empty">{{ dimensions.error || (can('customers') ? '暂无维度数据' : '无客户查看权限') }}</template></el-table><footer class="crm-pager"><el-pagination background layout="total, sizes, prev, pager, next" :total="total(dimensions)" :page-size="dimensions.per_page" :current-page="dimensions.page" :page-sizes="[10,20,50]" @current-change="dimensionPage" @size-change="dimensionSize" /></footer></dashboard-widget>

      <p v-if="tab === 'pipeline'" class="dashboard-caption dashboard-config-full"><i class="el-icon-info" /> 当前进行中与预计金额按当前状态；本期赢单、丢单按所选期间实际结果。</p>
      <el-alert class="dashboard-config-full" v-if="tab === 'pipeline' && pipeline.error" :title="pipeline.error" type="error" :closable="false" show-icon />
        <dashboard-widget :configuration="widget('pipeline_stages')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('pipeline_stages') }}</h3><el-radio-group v-model="stageMode" size="mini"><el-radio-button label="count">数量</el-radio-button><el-radio-button label="amount">预计金额</el-radio-button></el-radio-group><el-button v-if="can('opportunities')" type="text" @click="openDrill({kind:'pipeline_active'},'当前进行中商机')">查看明细<i class="el-icon-arrow-right" /></el-button></div><p class="dashboard-caption">当前状态{{ stageMode === 'amount' ? ' · ' + (scope.currency || '请先选择币种') + ' · 万' : '' }}</p><dashboard-chart v-loading="pipeline.loading" title="当前阶段分布" :rows="stageRows" :height="260" :empty-text="can('opportunities') ? pipeline.error || '暂无进行中商机' : '无商机查看权限'" @select="row => openDrill(row.query,row.label)" /></dashboard-widget>
        <dashboard-widget :configuration="widget('pipeline_funnel')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('pipeline_funnel') }}</h3><el-button v-if="can('leads')" type="text" @click="openDrill(cohortQuery('recorded'),'同批录入线索')">查看明细<i class="el-icon-arrow-right" /></el-button></div><div class="dashboard-cohort-filter"><label>录入期间<el-date-picker v-model="cohort.range" type="daterange" size="mini" value-format="yyyy-MM-dd" range-separator="至" popper-class="crm-date-popper" :clearable="false" /></label><label>观察截至<el-date-picker v-model="cohort.observed_on" type="date" size="mini" value-format="yyyy-MM-dd" popper-class="crm-date-popper" :clearable="false" /></label><el-button size="mini" type="success" :disabled="!can('leads')" @click="loadPipeline">查询</el-button></div><div class="dashboard-funnel-with-rate"><dashboard-chart v-loading="pipeline.loading" title="同批线索推进漏斗" type="funnel" :height="250" :rows="funnelRows(pipelineData.funnel)" :empty-text="can('leads') ? pipeline.error || '暂无有据可查的同批线索' : '无线索查看权限'" @select="row => openDrill({...funnelScope(pipelineData.funnel),...row.query},row.label)" /><div class="dashboard-conversion"><span>转化率</span><strong>{{ funnelRate(pipelineData.funnel) }}</strong><small>{{ funnelFraction(pipelineData.funnel) }}</small></div></div><small v-if="funnelUnknown(pipelineData.funnel)" class="crm-muted">缺少历史证据的阶段保留待核实，不计入已到达数。</small></dashboard-widget>
      <dashboard-widget :configuration="widget('pipeline_records')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('pipeline_records') }}</h3><el-button v-if="can('opportunities')" type="text" icon="el-icon-data-analysis" @click="lostVisible = true">丢单原因分析<i class="el-icon-arrow-right" /></el-button></div><el-tabs v-model="riskKind" @tab-click="riskChanged"><el-tab-pane v-for="item in riskTabs" :key="item.key" :name="item.key" :label="item.label" /></el-tabs><div class="dashboard-table-tools"><label>停留超过<el-select v-model="stalledDays" size="mini" popper-class="crm-select-popper" @change="riskChanged"><el-option v-for="value in [7,15,30,60,90]" :key="value" :value="value" :label="value + '天'" /></el-select></label><span>筛选结果 {{ risk.data ? total(risk) : '—' }} 项</span></div><dashboard-records v-if="can('opportunities')" v-bind="tableProps(risk)" type="opportunities" @page="riskPage" @size="riskSize" @view="viewRecord" @act="actRecord" /><div v-else class="dashboard-empty">无商机查看权限</div></dashboard-widget>

        <dashboard-widget :configuration="widget('source_distribution')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('source_distribution') }}</h3><label>来源口径<el-select v-model="source" size="small" popper-class="crm-select-popper" @change="sourceChanged"><el-option v-for="item in sourceOptions" :key="item.value" :label="item.label" :value="item.value" :disabled="!canSource(item.value)" /></el-select></label></div><p class="dashboard-caption">客户去重计数{{ sources.data ? ' · 合计 ' + count((sources.data.summary || {}).customer_count) : '' }}</p><el-alert v-if="sources.error" :title="sources.error" type="error" :closable="false" /><dashboard-chart v-loading="sources.loading" :title="sourceLabel + '分布'" :height="260" :rows="sourceRows" :empty-text="can('customers') ? sources.error || '暂无来源数据' : '无客户查看权限'" @select="row => openDrill(row.drilldown,row.label)" /><footer class="crm-pager"><el-pagination small background layout="total, prev, next" :total="total(sources)" :page-size="sources.per_page" :current-page="sources.page" @current-change="sourcePage" /></footer></dashboard-widget>
        <dashboard-widget :configuration="widget('source_followups')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('source_followups') }}</h3><el-button v-if="canFollowups" type="text" @click="openDrill({kind:'contact_due'},'本期应跟进')">查看明细<i class="el-icon-arrow-right" /></el-button></div><el-alert v-if="followups.error" :title="followups.error" type="error" :closable="false" /><dashboard-chart v-loading="followups.loading" title="各销售跟进完成" :rows="followupSalesRows" :series="followupSeries" vertical :height="290" :empty-text="canFollowups ? followups.error || '暂无计划跟进数据' : '无跟进查看权限'" @select="followupSalesDrill" /><footer v-if="canFollowups" class="crm-pager"><el-pagination small background layout="total, prev, next" :total="(followupData.sales || {}).total || 0" :page-size="followups.per_page" :current-page="followups.page" @current-change="followupSalesPage" /></footer></dashboard-widget>
      <dashboard-widget :configuration="widget('source_records')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('source_records') }}</h3></div><el-tabs v-model="taskKind" @tab-click="taskChanged"><el-tab-pane v-for="item in availableTaskTabs" :key="item.key" :name="item.key" :label="item.label" /></el-tabs><dashboard-records v-if="canFollowups" v-bind="tableProps(tasks)" @page="taskPage" @size="taskSize" @view="viewRecord" @act="actRecord" /><div v-else class="dashboard-empty">无跟进查看权限</div></dashboard-widget>

      <p v-if="tab === 'income'" class="dashboard-caption dashboard-config-full">统计 {{ scope.date_from }} 至 {{ scope.date_to }} 下单的有效订单；已收与尾款为{{ amountAsOf ? '截至 ' + amountAsOf : '当前' }}的实际金额。</p>
        <dashboard-widget :configuration="widget('income_chart')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('income_chart') }}</h3></div><dashboard-chart v-loading="income.loading" title="各销售成交、已收与尾款" :rows="incomeSalesRows" :series="incomeSeries" :height="270" :empty-text="can('amounts') && can('orders') ? !scope.currency ? '选择币种查看金额对比' : income.error || '暂无订单金额数据' : '无订单金额查看权限'" @select="row => selectOrders(salesFilter(row),row.label)" /></dashboard-widget>
        <dashboard-widget :configuration="widget('income_table')" :page="tab"><div class="dashboard-panel-head"><h3>{{ widgetTitle('income_table') }}</h3></div><dashboard-sales-table v-bind="tableProps(income)" :currency="scope.currency" income @drilldown="openDrill" @page="incomePage" @size="incomeSize" @person="openPersonal" @orders="selectOrders" /></dashboard-widget>
      <dashboard-widget :configuration="widget('income_orders')" :page="tab"><div class="dashboard-panel-head dashboard-orders-head"><h3>{{ widgetTitle('income_orders') }}</h3><label>销售<paged-record-select v-if="metadataReady" v-model="orderSalesId" :request="salesRequest" :params="appliedSalesParams" :columns="salesColumns" :label="salesLabel" title="选择销售" placeholder="全部销售" :selected-rows.sync="orderSelectedSales" @change="orderSalesChanged" /></label><label>尾款<el-select v-model="outstandingStatus" size="small" clearable placeholder="全部" popper-class="crm-select-popper" @change="orderChanged"><el-option label="未结清" value="outstanding" /><el-option label="已结清" value="settled" /><el-option label="待核实" value="verification" /></el-select></label><el-tag v-if="orderUnassigned" size="small" closable @close="clearUnassigned">未归属销售</el-tag></div><dashboard-records v-if="can('orders') && can('amounts')" v-bind="tableProps(orders)" type="orders" @page="orderPage" @size="orderSize" @view="viewRecord" /><div v-else class="dashboard-empty">无订单金额查看权限</div><p class="dashboard-caption"><i class="el-icon-info" /> 线上订单按已确认付款统计，同一笔款项只计一次。</p></dashboard-widget>

    </div>
    <p class="dashboard-footnote"><i class="el-icon-info" /> 客户及商机按当前归属统计，成交按订单原销售归属；范围遵循权限。未知与未填写分别保留，金额分币种展示。</p>
    <el-dialog class="crm-dialog dashboard-drill" :title="drill.title" :visible.sync="drill.visible" width="1180px" top="5vh" append-to-body :close-on-click-modal="false" :before-close="closeDrill"><div v-if="drillCohort" class="dashboard-table-tools"><label>转化分类<el-select v-model="conversionBucket" size="small" popper-class="crm-select-popper" @change="changeConversion"><el-option label="当前推进阶段" value="" /><el-option v-for="item in conversionOptions" :key="item.value" :label="item.label" :value="item.value" /></el-select></label></div><dashboard-records v-if="drill.visible" v-bind="tableProps(drillState)" :type="drillType" @page="drillPage" @size="drillSize" @view="viewRecord" @act="actRecord" /><span slot="footer"><el-button size="small" @click="closeDrill">关闭</el-button></span></el-dialog>
    <el-dialog class="crm-dialog" title="丢单原因分析" :visible.sync="lostVisible" width="760px" append-to-body><dashboard-chart title="本期丢单原因" :rows="lostRows" :height="320" :empty-text="pipeline.error || '暂无本期丢单记录'" @select="row => openDrill(row.query,row.label)" /></el-dialog>
    <dashboard-personal-dialog ref="personal" :abilities="metadata.abilities || {}" @drilldown="openDrill" @view="viewRecord" @act="actRecord" @expand="expandPersonal" @closed="invalidateActions" @invalidate="invalidateActions" />
    <dashboard-actions ref="actions" @changed="afterChanged" />
    <dashboard-configuration-dialog ref="configuration" :dashboard-metadata="metadata" :dimensions="dimensionOptions" :sources="sourceOptions" @saved="useSavedView" @catalog="updateViewCatalog" @default="restoreDefaultView" @deleted="viewDeleted"><template slot="preview" slot-scope="{componentKey,configuration}"><dashboard-configuration-preview :component-key="componentKey" :configuration="configuration" :dashboard="previewData" /></template></dashboard-configuration-dialog>
  </div>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import DashboardMetrics from '@/components/crm/dashboard/DashboardMetrics.vue'
import DashboardChart from '@/components/crm/dashboard/DashboardChart.vue'
import DashboardRecords from '@/components/crm/dashboard/DashboardRecords.vue'
import DashboardSalesTable from '@/components/crm/dashboard/DashboardSalesTable.vue'
import DashboardPersonalDialog from '@/components/crm/dashboard/DashboardPersonalDialog.vue'
import DashboardActions from '@/components/crm/dashboard/DashboardActions.vue'
import DashboardWidget from '@/components/crm/dashboard/DashboardWidget.vue'
import DashboardConfigurationDialog from '@/components/crm/dashboard/DashboardConfigurationDialog.vue'
import DashboardConfigurationPreview from '@/components/crm/dashboard/DashboardConfigurationPreview.vue'
import { dashboardComponentCatalog, defaultDashboardComponents, normalizeDashboardComponents } from '@/components/crm/dashboard/dashboardConfiguration'
import { getCrmViewMetadata, getCrmView, rememberedCrmView, rememberCrmView } from '@/api/erp/crmViews'
import { getDashboardMetadata, listDashboardSalesOptions, getDashboardOverview, listDashboardSales, listDashboardDimensions, listDashboardSources, getDashboardPipeline, getDashboardFollowups, getDashboardIncome, listDashboardOrders, listDashboardDrilldown } from '@/api/erp/crmDashboard'
import { crmError } from '@/api/erp/crm'
import { erpDateTime } from '@/utils/dateTime'
import { countText, amountText, moneyText, currencyAmount, pageState, pageRows, pageTotal } from '@/components/crm/dashboard/dashboardFormat'
import '@/styles/crm.css'
import '@/styles/crm-dashboard.css'
import '@/components/crm/dashboard/dashboardConfiguration.css'

const tabs = [{key:'overview',label:'经营总览'},{key:'sales',label:'销售分析'},{key:'dimensions',label:'行业与客户'},{key:'pipeline',label:'商机进度'},{key:'sources',label:'来源与跟进'},{key:'income',label:'收入统计'}]
const dimensions = [{value:'industry',label:'行业'},{value:'purchase_scale',label:'年采购力'},{value:'customer_level',label:'客户等级'},{value:'region',label:'地区'},{value:'business_line',label:'业务线'},{value:'customer_pool',label:'客户池'},{value:'customer_tag',label:'客户标签'},{value:'custom_tag',label:'个人标签'}]
const sourceOptions = [{value:'first_source',label:'客户首次来源'},{value:'inquiry_source',label:'本次咨询来源'},{value:'entry_method',label:'录入方式'}]
const riskTabs = [{key:'pipeline_overdue_followup',label:'超期跟进'},{key:'pipeline_stalled',label:'阶段停留'},{key:'pipeline_overdue_close',label:'预计成交日已过'},{key:'pipeline_active',label:'全部进行中'}]
const taskTabs = [{key:'contact_overdue',label:'逾期跟进'},{key:'contact_due_soon',label:'即将到期'},{key:'contact_pending_accept',label:'待接收'},{key:'contact_uncontacted',label:'未建立沟通'},{key:'contact_unverified',label:'待核实'}]
const today = () => erpDateTime(new Date().toISOString(),10)
const defaultDraft = () => ({range:[today().slice(0,7) + '-01',today()],department_legacy_id:null,group_legacy_id:null,sales_user_legacy_id:null,sales_user_unassigned:false,business_line:'',currency:'CNY'})

export default {
  name: 'CrmDashboard',
  components: { PagedRecordSelect, DashboardMetrics, DashboardChart, DashboardRecords, DashboardSalesTable, DashboardPersonalDialog, DashboardActions, DashboardWidget, DashboardConfigurationDialog, DashboardConfigurationPreview },
  data: () => ({ viewCatalog:[], viewComponents:[], currentView:null, viewLoading:false, viewError:'', viewWarnings:[], viewSequence:0, tabs, dimensionOptions:dimensions, sourceOptions, riskTabs, taskTabs, tab:'overview', metadata:{}, metadataReady:false, metadataError:'', draft:defaultDraft(), scope:{}, selectedSales:[], salesColumns:[{prop:'name',label:'姓名'}], session:0, overview:pageState(), sales:pageState(), dimensions:pageState(), industry:pageState(), purchase:pageState(), cross:pageState(), pipeline:pageState(), followups:pageState(), sources:pageState(), income:pageState(), orders:pageState(), risk:pageState(), tasks:pageState(), drillState:pageState(), drill:{visible:false,title:'',params:{},baseParams:{},baseTitle:'',session:0}, conversionBucket:'', conversionOptions:[{value:'total',label:'累计成交'},{value:'within_30',label:'30天内成交'},{value:'over_30',label:'超过30天成交'},{value:'new',label:'新咨询成交'},{value:'old',label:'老咨询成交'},{value:'source_unknown',label:'咨询分类未填写'}], dimension:'industry', source:'first_source', crossDimensionPage:1, stageMode:'count', riskKind:'pipeline_overdue_followup', taskKind:'contact_overdue', stalledDays:30, cohort:{range:[today().slice(0,7) + '-01',today()],observed_on:today()}, orderSalesId:null, orderUnassigned:false, orderSelectedSales:[], outstandingStatus:'', lostVisible:false, salesChartSeries:[{key:'deal',label:'成交金额（万）'},{key:'expected',label:'待成交金额（万）'}], followupSeries:[{key:'due_count',label:'应跟进'},{key:'completed_count',label:'已完成'}], incomeSeries:[{key:'deal',label:'成交金额（万）'},{key:'received',label:'已收金额（万）'},{key:'outstanding',label:'尾款金额（万）'}] }),
  computed: {
    metricDefinitions() { return dashboardComponentCatalog.filter(item => item.metric) },
    metricColumns() { return {overview:6,sales:8,pipeline:4,sources:4,income:3}[this.tab] || 4 },
    previewData() { return {crossRows:this.crossRows,crossColumns:this.crossColumns,dimensionRows:this.dimensionRows,industryRows:this.industryRows,purchaseRows:this.purchaseRows,stageRows:this.stageRows,overviewStageRows:this.overviewStageRows,overviewSalesRows:this.overviewSalesRows,incomeSalesRows:this.incomeSalesRows,sourceRows:this.sourceRows,followupSalesRows:this.followupSalesRows,funnelRows:this.funnelRows(this.pipelineData.funnel),salesFunnelRows:this.funnelRows((pageRows(this.sales)[0] || {}).funnel),reminderCards:this.reminderCards,metricCards:this.metricDefinitions.map(item => ({...this.configuredMetric(item),key:item.key})),salesRows:pageRows(this.sales),incomeRows:pageRows(this.income),orderRows:pageRows(this.orders),riskRows:pageRows(this.risk),taskRows:pageRows(this.tasks),dimension:this.dimension,source:this.source,settings:this.configurationSnapshot().settings,filters:this.scope} },
    overviewData() { return this.overview.data || {} },
    pipelineData() { return this.pipeline.data || {} },
    followupData() { return this.followups.data || {} },
    canFollowups() { return this.can('customers') || this.can('leads') || this.can('opportunities') },
    hasAnyData() { return Object.values(this.metadata.abilities || {}).some(Boolean) },
    availableTaskTabs() { return taskTabs.filter(item => this.canTask(item.key)) },
    departmentOptions() { return (this.metadata.departments || []).filter(item => !item.parent_id || !(this.metadata.departments || []).some(parent => parent.id === item.parent_id)) },
    groupOptions() { return (this.metadata.departments || []).filter(item => this.draft.department_legacy_id ? String(item.parent_id) === String(this.draft.department_legacy_id) : !!item.parent_id) },
    salesPickerParams() { return {department_legacy_id:this.draft.department_legacy_id,group_legacy_id:this.draft.group_legacy_id} },
    appliedSalesParams() { return {department_legacy_id:this.scope.department_legacy_id,group_legacy_id:this.scope.group_legacy_id} },
    amountAsOf() { const metadata = this.overviewData.metadata || {}; return metadata.amount_observed_on || (metadata.amount_as_of ? erpDateTime(metadata.amount_as_of,10) : '') },
    dimensionLabel() { return (this.dimensionOptions.find(item => item.value === this.dimension) || {}).label || '行业' },
    sourceLabel() { return (sourceOptions.find(item => item.value === this.source) || {}).label || '来源' },
    dimensionRows() { return pageRows(this.dimensions).map(row => ({...row,count:row.customer_count})) },
    industryRows() { return pageRows(this.industry).map(row => ({...row,count:row.customer_count})) },
    overviewStageRows() { return (this.pipelineData.current_stages || []).map(row => ({...row,label:row.name,count:row.count})) },
    purchaseRows() { return pageRows(this.purchase).map(row => ({...row,count:row.customer_count})) },
    sourceRows() { return pageRows(this.sources).map(row => ({...row,count:row.customer_count})) },
    dimensionTotal() { return this.dimensions.data ? (this.dimensions.data.summary || {}).customer_count : null },
    crossRows() { return pageRows(this.cross) },
    crossColumns() { return (this.cross.data || {}).columns || [] },
    crossDimensionTotal() { return ((this.cross.data || {}).dimension_pagination || {}).total || 0 },
    stageRows() { return (this.pipelineData.current_stages || []).map(row => ({...row,label:row.name,count:this.stageMode === 'amount' ? this.stageAmount(row) : row.count})) },
    overviewSalesRows() { return (this.overviewData.sales_ranking || []).map(row => ({...row,label:row.sales_user_name || '未归属销售',deal:this.chartMoney(row.money_summary,'deal_amount'),expected:this.chartExpected(row.expected_amounts)})) },
    incomeSalesRows() { return pageRows(this.income).map(row => ({...row,label:row.sales_user_name || '未归属销售',deal:this.chartMoney(row.money_summary,'deal_amount'),received:this.chartMoney(row.money_summary,'received_amount'),outstanding:this.chartMoney(row.money_summary,'outstanding_amount')})) },
    followupSalesRows() { return ((this.followupData.sales || {}).data || []).map(row => ({...row,label:row.sales_user_name || '未归属销售'})) },
    lostRows() { return (this.pipelineData.lost_reasons || []).map(row => ({...row,label:row.name})) },
    reminderCards() { return ['due','completed','overdue'].map(key => ({key,label:{due:'本期应跟进',completed:'已完成',overdue:'逾期'}[key],value:this.contactCount(key),caption:key === 'overdue' ? '当前未完成' : this.periodText(),tone:key === 'overdue' ? 'danger' : '',drilldown:this.contactCan(key) ? this.contactQuery(key) : null})) },
    drillCohort() { return (this.drill.baseParams || {}).kind === 'cohort_stage' },
    drillType() { const kind = this.drill.params.kind || ''; if (kind.includes('order')) return 'orders'; if (kind === 'followup_records' || kind === 'contact_records') return 'followups'; if (kind.includes('opportunit') || kind.startsWith('pipeline_')) return 'opportunities'; if (kind.includes('customer') || kind === 'first_contacts') return 'customers'; return 'tasks' }
  },
  async created() {
    const requestedTab = this.$route.query.tab
    if (tabs.some(item => item.key === requestedTab)) this.tab = requestedTab
    await this.loadMetadata()
    if (this.metadataReady) { await this.loadViewCatalog(); await this.restoreRememberedView(); this.applyFilters() }
  },
  beforeDestroy() { this.viewSequence += 1; this.session += 1; this.drill.session += 1 },
  methods: {
    widget(key) { return this.viewComponents.find(item => item.key === key) },
    updateViewCatalog(catalog) {this.viewCatalog=catalog;this.viewComponents=this.viewComponents.map(item=>({...item,enabled:item.enabled && catalog.some(definition=>definition.key===item.key && definition.available!==false)}))},
    availableDefaultComponents() {return defaultDashboardComponents().map(item=>({...item,enabled:this.viewCatalog.some(definition=>definition.key===item.key && definition.available!==false)}))},
    async loadViewCatalog() {
      const sequence=++this.viewSequence;this.viewLoading=true
      try {const {data}=await getCrmViewMetadata({resource:'dashboard'});if(sequence!==this.viewSequence)return;this.viewCatalog=(data.data || data).dashboard_components || [];this.viewComponents=this.availableDefaultComponents()}
      catch(error) {if(sequence===this.viewSequence){this.viewComponents=this.availableDefaultComponents();this.viewError=crmError(error,'看板组件目录加载失败，请重新进入页面后重试')}}
      finally {if(sequence===this.viewSequence)this.viewLoading=false}
    },
    widgetTitle(key) { return (this.widget(key) || {}).title || '' },
    configuredMetric(definition) {
      const item = this.metricCardsFor(definition.category).find(metric => metric.key === definition.metric) || {}
      const configuration = this.widget(definition.key) || {}
      return {...item,label:configuration.title || definition.title,drilldown:configuration.drilldown ? item.drilldown : null}
    },
    configurationSnapshot() {
      return {page:this.tab,filters:{...this.scope},settings:{dimension:this.dimension,source:this.source,stage_mode:this.stageMode,cohort_from:(this.cohort.range || [])[0],cohort_to:(this.cohort.range || [])[1],observed_on:this.cohort.observed_on,stalled_days:this.stalledDays,risk_kind:this.riskKind,task_kind:this.taskKind,outstanding_status:this.outstandingStatus},components:this.viewComponents.map(item => ({...item}))}
    },
    openConfiguration() { this.$refs.configuration.open(this.configurationSnapshot(),this.currentView,this.viewWarnings) },
    viewWarningText(warning) { return typeof warning === 'string' ? warning : warning.message || '部分配置已失效，请重新选择后保存' },
    applyView(view) {
      const configuration=view.configuration || {}; const filters=configuration.filters || {}; const settings=configuration.settings || {}; const defaults=this.metadata.defaults || {}
      this.currentView=view;this.viewComponents=normalizeDashboardComponents(configuration.components);this.viewWarnings=view.warnings || [];this.viewError=''
      this.tab=tabs.some(item=>item.key===configuration.page) ? configuration.page : 'overview'
      this.draft={...defaultDraft(),...filters,range:[filters.date_from || defaults.date_from || today().slice(0,7)+'-01',filters.date_to || defaults.date_to || today()]};this.selectedSales=[]
      this.dimension=settings.dimension || 'industry';this.source=settings.source || 'first_source';this.stageMode=settings.stage_mode || 'count';this.stalledDays=settings.stalled_days || 30;this.riskKind=settings.risk_kind || 'pipeline_overdue_followup';this.taskKind=settings.task_kind || 'contact_overdue';this.outstandingStatus=settings.outstanding_status || ''
      if(!this.canTask(this.taskKind)){this.taskKind=(this.availableTaskTabs[0] || {}).key || 'contact_overdue';this.viewWarnings=[...this.viewWarnings,'原跟进任务分类当前无权查看，已切换至可用分类。']}
      this.cohort={range:[settings.cohort_from || this.draft.range[0],settings.cohort_to || this.draft.range[1]],observed_on:settings.observed_on || today()}
    },
    async restoreRememberedView() {
      const id=rememberedCrmView('dashboard');if(!id)return
      const sequence=++this.viewSequence;this.viewLoading=true
      try {const {data}=await getCrmView(id);if(sequence!==this.viewSequence)return;const view=data.data || data;if(view.resource!=='dashboard')throw new Error('看板类型已失效');this.applyView(view)}
      catch(error) {if(sequence===this.viewSequence){this.viewError=crmError(error,'已保存看板暂时无法恢复，当前显示系统默认布局');if(error.response && [403,404].includes(error.response.status))rememberCrmView('dashboard',null)}}
      finally {if(sequence===this.viewSequence)this.viewLoading=false}
    },
    useSavedView(payload) {this.viewSequence+=1;const view=payload.data || payload;if(view.resource!=='dashboard')return;this.applyView(view);rememberCrmView('dashboard',view.id);this.applyFilters()},
    restoreDefaultView() {this.viewSequence+=1;this.viewLoading=false;this.currentView=null;this.viewComponents=this.availableDefaultComponents();this.viewWarnings=[];this.viewError='';rememberCrmView('dashboard',null);this.tab='overview';this.dimension='industry';this.source='first_source';this.stageMode='count';this.stalledDays=30;this.riskKind='pipeline_overdue_followup';this.taskKind='contact_overdue';this.outstandingStatus='';this.cohort={range:[today().slice(0,7)+'-01',today()],observed_on:today()};this.resetFilters()},
    viewDeleted(id) {if(this.currentView && this.currentView.id===id){this.currentView=null;rememberCrmView('dashboard',null)}},
    metricCardsFor(page) {
      const data = this.overviewData; const metrics = data.metrics || {}; const current = '当前状态'; const period = this.periodText(); const drilldowns = data.metric_drilldowns || {}
      const customer = [{key:'customers',label:'客户总数',value:metrics.customers,icon:'el-icon-user',caption:'当前归属',drilldown:drilldowns.customers},{key:'new_customers',label:'本期新增客户',value:metrics.new_customers,icon:'el-icon-circle-plus-outline',caption:period,drilldown:drilldowns.new_customers},{key:'first_contacts',label:'近7天首次联系',value:metrics.first_contacts,icon:'el-icon-phone-outline',caption:'最近7天',drilldown:drilldowns.first_contacts},{key:'open_opportunities',label:'进行中商机',value:metrics.open_opportunities,icon:'el-icon-connection',caption:current,drilldown:drilldowns.open_opportunities},{key:'expected',label:'待成交金额',value:this.overview.data && this.can('opportunities') ? this.expectedText(data.expected_amounts) : null,icon:'el-icon-copy-document',caption:current,drilldown:this.can('opportunities') ? {kind:'open_opportunities'} : null}]
      const summary = page === 'income' ? ((this.income.data || {}).summary || {}).money_summary : data.money_summary
      const amounts = [{key:'deal_amount',label:page === 'overview' ? '本期成交金额' : '成交金额',icon:'el-icon-document'},{key:'received_amount',label:'已收金额',icon:'el-icon-coin'},{key:'outstanding_amount',label:'尾款金额',icon:'el-icon-wallet'}].map(item => ({...item,value:summary ? moneyText(summary,item.key,this.scope.currency) : null,caption:`同批订单 · ${this.amountAsOf ? '截至 ' + this.amountAsOf : '当前金额'}`,drilldown:this.can('orders') && this.can('amounts') ? {kind:'orders'} : null}))
      if (page === 'income') return amounts
      if (page === 'pipeline') return [{key:'active',label:'当前进行中',value:this.pipelineCount('active'),caption:current,icon:'el-icon-copy-document',drilldown:this.pipelineQuery('active')},{...customer[4]},{key:'won',label:'本期赢单',value:this.pipelineCount('won'),caption:period,icon:'el-icon-connection',drilldown:this.pipelineQuery('won')},{key:'lost',label:'本期丢单',value:this.pipelineCount('lost'),caption:period,icon:'el-icon-circle-close',tone:'danger',drilldown:this.pipelineQuery('lost')}]
      if (page === 'sources') return [...this.reminderCards.map(item => ({...item,icon:{due:'el-icon-date',completed:'el-icon-circle-check',overdue:'el-icon-warning-outline'}[item.key]})),customer[2]]
      if (page === 'sales') return [...customer,...amounts]
      return [...customer,amounts[0]]
    },
    count: countText,
    money: moneyText,
    rows: pageRows,
    total: pageTotal,
    salesRequest: listDashboardSalesOptions,
    salesLabel(row) { return row.name },
    can(key) { return !!(this.metadata.abilities || {})[key] },
    canDimension(value) { return this.can('customers') && (!['customer_pool','customer_tag','custom_tag'].includes(value) || this.can('leads')) },
    canSource(value) { return this.can('customers') && (value === 'first_source' || this.can('leads')) },
    canTask(kind) { return ['contact_pending_accept','contact_uncontacted','contact_unverified'].includes(kind) ? this.can('leads') : this.canFollowups },
    periodText() { return this.scope.date_from && this.scope.date_to ? `${this.scope.date_from} 至 ${this.scope.date_to}` : '' },
    tableProps(state) { return {rows:pageRows(state),total:pageTotal(state),loading:state.loading,error:state.error,page:state.page,perPage:state.per_page,summary:(state.data || {}).summary} },
    async loadMetadata() {
      const session = this.session
      try { const {data} = await getDashboardMetadata(); if (session !== this.session) return; this.metadata = data; this.metadataReady = true; this.metadataError = ''; if ((data.defaults || {}).date_from) this.draft.range = [data.defaults.date_from,data.defaults.date_to]; if (!(data.currencies || []).includes(this.draft.currency)) this.draft.currency = (data.currencies || [])[0] || '' }
      catch (error) { if (session === this.session) { this.metadataError = crmError(error,'经营看板权限与筛选加载失败'); this.metadataReady = false } }
    },
    departmentChanged() { this.draft.group_legacy_id = null; this.salesScopeChanged() },
    salesScopeChanged() { this.draft.sales_user_legacy_id = null; this.draft.sales_user_unassigned = false; this.selectedSales = [] },
    applyFilters() {
      if (!this.metadataReady || !(this.draft.range || []).length) return
      this.session += 1; this.invalidateActions(); this.closeDrill(); if (this.$refs.personal) this.$refs.personal.close()
      this.scope = {date_from:this.draft.range[0],date_to:this.draft.range[1],department_legacy_id:this.draft.department_legacy_id || undefined,group_legacy_id:this.draft.group_legacy_id || undefined,sales_user_legacy_id:this.draft.sales_user_legacy_id || undefined,sales_user_unassigned:this.draft.sales_user_unassigned || undefined,business_line:this.draft.business_line || undefined,currency:this.draft.currency || undefined}
      this.resetStates(); this.loadOverview(); this.loadTab()
    },
    resetFilters() { this.draft = defaultDraft(); if ((this.metadata.defaults || {}).date_from) this.draft.range = [this.metadata.defaults.date_from,this.metadata.defaults.date_to]; if (!(this.metadata.currencies || []).includes(this.draft.currency)) this.draft.currency = (this.metadata.currencies || [])[0] || ''; this.selectedSales = []; this.applyFilters() },
    resetStates() { ['overview','sales','dimensions','industry','purchase','cross','pipeline','followups','sources','income','orders','risk','tasks'].forEach(key => { this[key].sequence += 1; this[key] = pageState() }); this.crossDimensionPage = 1; this.orderSalesId = null; this.orderUnassigned = false; this.orderSelectedSales = [] },
    async fetch(key,request,extra = {}) {
      const state = this[key]; const session = this.session; const sequence = ++state.sequence
      state.loading = true; state.error = ''; state.data = null
      try { const {data} = await request({...this.scope,page:state.page,per_page:state.per_page,...extra}); if (session === this.session && sequence === state.sequence) state.data = data }
      catch (error) { if (session === this.session && sequence === state.sequence) state.error = crmError(error,'经营看板数据加载失败') }
      finally { if (session === this.session && sequence === state.sequence) state.loading = false }
    },
    loadOverview() { return this.fetch('overview',getDashboardOverview) },
    changeTab() { this.invalidateActions(); this.loadTab() },
    goTab(tab) { this.invalidateActions(); this.tab = tab; this.loadTab() },
    loadTab() {
      if (!this.metadataReady) return
      const visible = key => this.viewComponents.some(item => item.enabled && item.position === this.tab && item.key === key)
      const metrics = this.metricDefinitions.filter(item => visible(item.key))
      if (visible('sales_table') || visible('sales_funnels')) this.loadSales()
      if (['dimension_distribution','dimension_table'].some(visible)) this.loadDimensions()
      if (visible('dimension_cross')) this.loadCross()
      if (visible('overview_industry')) this.loadIndustry()
      if (visible('overview_purchase')) this.loadPurchase()
      if (['overview_stages','pipeline_stages','pipeline_funnel'].some(visible) || metrics.some(item => item.category === 'pipeline')) this.loadPipeline()
      if (visible('pipeline_records')) this.loadRisk()
      if (['overview_followups','source_followups'].some(visible) || metrics.some(item => item.category === 'sources')) this.loadFollowups()
      if (visible('source_distribution')) this.loadSources()
      if (visible('source_records')) this.loadTasks()
      if (['income_chart','income_table'].some(visible) || metrics.some(item => item.category === 'income')) this.loadIncome()
      if (visible('income_orders')) this.loadOrders()
    },
    cohortParams() { return {cohort_from:(this.cohort.range || [])[0],cohort_to:(this.cohort.range || [])[1],observed_on:this.cohort.observed_on} },
    loadSales() { if (this.hasAnyData) return this.fetch('sales',listDashboardSales,this.cohortParams()); this.sales.error = '无业务查看权限' },
    loadDimensions() { if (this.canDimension(this.dimension)) return this.fetch('dimensions',listDashboardDimensions,{dimension:this.dimension}) },
    loadIndustry() { if (this.can('customers')) return this.fetch('industry',listDashboardDimensions,{dimension:'industry'}) },
    loadPurchase() { if (this.can('customers')) return this.fetch('purchase',listDashboardDimensions,{dimension:'purchase_scale'}) },
    loadCross() { if (this.canDimension(this.dimension)) return this.fetch('cross',listDashboardDimensions,{dimension:this.dimension,cross_sales:1,dimension_page:this.crossDimensionPage,dimension_per_page:10}) },
    loadPipeline() { if (this.can('opportunities') || this.can('leads')) return this.fetch('pipeline',getDashboardPipeline,{...this.cohortParams(),stalled_days:this.stalledDays}) },
    loadFollowups() { if (this.canFollowups) return this.fetch('followups',getDashboardFollowups) },
    loadSources() { if (this.canSource(this.source)) return this.fetch('sources',listDashboardSources,{dimension:this.source}) },
    loadIncome() { if (this.can('orders') && this.can('amounts')) return this.fetch('income',getDashboardIncome) },
    loadOrders() { if (this.can('orders') && this.can('amounts')) return this.fetch('orders',listDashboardOrders,{sales_user_legacy_id:this.orderUnassigned ? undefined : this.orderSalesId || this.scope.sales_user_legacy_id,sales_user_unassigned:this.orderSalesId ? undefined : this.orderUnassigned || this.scope.sales_user_unassigned,outstanding_status:this.outstandingStatus || undefined}) },
    loadRisk() { if (this.can('opportunities')) return this.fetch('risk',listDashboardDrilldown,{kind:this.riskKind,stalled_days:this.stalledDays}) },
    loadTasks() { if (this.canTask(this.taskKind)) return this.fetch('tasks',listDashboardDrilldown,{kind:this.taskKind}) },
    salesPage(page) { this.sales.page = page; this.loadSales() },
    salesSize(size) { this.sales.per_page = size; this.salesPage(1) },
    dimensionPage(page) { this.dimensions.page = page; this.loadDimensions() },
    dimensionSize(size) { this.dimensions.per_page = size; this.dimensionPage(1) },
    industryPage(page) { this.industry.page = page; this.loadIndustry() },
    purchasePage(page) { this.purchase.page = page; this.loadPurchase() },
    crossPage(page) { this.cross.page = page; this.loadCross() },
    crossDimensionChange(page) { this.crossDimensionPage = page; this.loadCross() },
    followupSalesPage(page) { this.followups.page = page; this.loadFollowups() },
    sourcePage(page) { this.sources.page = page; this.loadSources() },
    incomePage(page) { this.income.page = page; this.loadIncome() },
    incomeSize(size) { this.income.per_page = size; this.incomePage(1) },
    riskChanged() { this.risk.page = 1; this.loadRisk() },
    riskPage(page) { this.risk.page = page; this.loadRisk() },
    riskSize(size) { this.risk.per_page = size; this.riskPage(1) },
    taskChanged() { this.tasks.page = 1; this.loadTasks() },
    taskPage(page) { this.tasks.page = page; this.loadTasks() },
    taskSize(size) { this.tasks.per_page = size; this.taskPage(1) },
    orderChanged() { this.orders.page = 1; this.loadOrders() },
    orderSalesChanged() { this.orderUnassigned = false; this.orderChanged() },
    clearUnassigned() { this.orderUnassigned = false; this.orderChanged() },
    orderPage(page) { this.orders.page = page; this.loadOrders() },
    orderSize(size) { this.orders.per_page = size; this.orderPage(1) },
    changeDimension(value) { this.dimension = value; this.dimensions.page = 1; this.cross.page = 1; this.crossDimensionPage = 1; this.loadDimensions(); this.loadCross() },
    goDimension(value) { this.tab = 'dimensions'; this.changeDimension(value) },
    sourceChanged() { this.sources.page = 1; this.loadSources() },
    crossKey(column) { return column.value === null || column.value === undefined ? '__empty' : String(column.value) },
    crossCell(row,column) { return (row.cells || []).find(cell => this.crossKey(cell) === this.crossKey(column)) },
    dimensionQuery(row,kind) { return {...row.drilldown,kind} },
    salesFilter(row) { return row.sales_user_legacy_id ? {sales_user_legacy_id:row.sales_user_legacy_id} : {sales_user_unassigned:1} },
    chartMoney(summary,key) { if (!this.scope.currency) return null; if (summary && summary.order_count === 0) return 0; const value = currencyAmount(summary,key,this.scope.currency); return value === null || value === undefined ? null : Number(value) / 10000 },
    chartExpected(amounts) { if (!this.scope.currency || !amounts) return null; if (!amounts.length) return 0; const row = amounts.find(item => item.currency === this.scope.currency); return row && !row.unknown_count && row.amount !== null ? Number(row.amount) / 10000 : null },
    expectedText(amounts) { return amounts === null || amounts === undefined ? '—' : !amounts.length ? '0' : amounts.filter(item => !this.scope.currency || item.currency === this.scope.currency).map(item => item.unknown_count ? `${item.currency} 待核实` : amountText(item.amount,item.currency)).join(' / ') || '—' },
    stageAmount(row) { if (!this.scope.currency || !row.can) return null; if (row.count === 0) return 0; const item = (row.amounts || []).find(item => item.currency === this.scope.currency); return item && !item.unknown_count && item.known_amount !== null ? Number(item.known_amount) / 10000 : null },
    dimensionSummaries({columns}) {
      const summary = (this.dimensions.data || {}).summary || {}; const queries = summary.drilldowns || {}
      const link = (text,query,title) => query ? this.$createElement('el-button',{props:{type:'text'},on:{click:() => this.openDrill(query,title)}},[text]) : text
      return columns.map((column,index) => {
        if (index === 0) return '合计'
        const key = column.property
        if (key === 'customer_count') return link(countText(summary.customer_count),queries.customers,this.dimensionLabel + '客户')
        if (key === 'open_opportunity_count') return summary.open_opportunity_count === null || summary.open_opportunity_count === undefined ? '—' : link(countText(summary.open_opportunity_count),queries.opportunities,this.dimensionLabel + '进行中商机')
        if (key === 'expected_amounts') return summary.expected_amounts ? link(this.expectedText(summary.expected_amounts),queries.opportunities,this.dimensionLabel + '进行中商机') : '—'
        if (key === 'deal_amount') return summary.money_summary ? link(moneyText(summary.money_summary,'deal_amount',this.scope.currency),queries.orders,this.dimensionLabel + '订单') : '—'
        return ''
      })
    },
    salesChartDrill(row,key) { this.openDrill({...this.salesFilter(row),kind:key === 'expected' ? 'open_opportunities' : 'orders'},`${row.label} · ${key === 'expected' ? '进行中商机' : '成交订单'}`) },
    selectOrders(query,name) { this.tab = 'income'; this.orderSalesId = query.sales_user_legacy_id || null; this.orderUnassigned = !!query.sales_user_unassigned; this.orderSelectedSales = this.orderSalesId ? [{id:this.orderSalesId,name}] : []; this.orders.page = 1; this.loadIncome(); this.loadOrders() },
    followupSalesDrill(row,key) { this.openDrill({...this.salesFilter(row),kind:key === 'completed_count' ? 'contact_completed' : 'contact_due'},row.label + ' · ' + (key === 'completed_count' ? '已完成跟进' : '应跟进')) },
    contactItem(key) { return (this.followupData.summary || {})[key] || {} },
    contactCan(key) { return !!this.contactItem(key).can },
    contactCount(key) { const item = this.contactItem(key); return item.can ? item.count : null },
    contactQuery(key) { return this.contactItem(key).query },
    pipelineCount(key) { const item = (this.pipelineData.summary || {})[key] || {}; return item.can ? item.count : null },
    pipelineQuery(key) { const item = (this.pipelineData.summary || {})[key] || {}; return item.can ? item.query : null },
    funnelRows(funnel) { return (funnel && funnel.can ? funnel.stages || [] : []).map(row => ({...row,label:row.name})) },
    funnelUnknown(funnel) { return !!(funnel && (funnel.stages || []).some(row => row.unknown_count > 0)) },
    funnelFraction(funnel) { if (!funnel || !funnel.can) return '—'; const won = (funnel.stages || []).find(row => row.key === 'won'); return won && !won.unknown_count && funnel.cohort_count ? `(${won.count} / ${funnel.cohort_count})` : '' },
    funnelRate(funnel) { if (!funnel || !funnel.can || !funnel.cohort_count) return '—'; const won = (funnel.stages || []).find(row => row.key === 'won'); return won && won.unknown_count > 0 ? '待核实' : won && won.count !== null ? `${(won.count / funnel.cohort_count * 100).toFixed(1)}%` : '—' },
    funnelScope(funnel) { return funnel ? {cohort_from:funnel.cohort_from,cohort_to:funnel.cohort_to,observed_on:funnel.observed_on} : {} },
    cohortQuery(stage) { return {kind:'cohort_stage',stage,...this.funnelScope(this.pipelineData.funnel)} },
    personFunnelQuery(row,stage) { return {kind:'cohort_stage',stage,...this.funnelScope(row.funnel),...this.salesFilter(row)} },
    openPersonal(row) { this.$refs.personal.open(row,{...this.scope,...this.cohortParams()}) },
    expandPersonal(scope) { this.draft = {...this.draft,range:[scope.date_from,scope.date_to],sales_user_legacy_id:scope.sales_user_legacy_id || null,sales_user_unassigned:!!scope.sales_user_unassigned}; this.selectedSales = []; this.tab = 'overview'; this.applyFilters() },
    canDrill(kind) { if (kind.includes('order')) return this.can('orders') && this.can('amounts'); if (kind.includes('opportunit') || kind.startsWith('pipeline_')) return this.can('opportunities'); if (kind === 'cohort_stage' || kind === 'cohort_conversion') return this.can('leads'); if (kind.startsWith('contact_') || kind === 'followup_records') return this.canFollowups; return this.can('customers') },
    openDrill(query,title) { if (!query || !query.kind || !this.canDrill(query.kind)) return; this.drill.session += 1; const params = {...this.scope,...query}; if (query.sales_user_legacy_id) delete params.sales_user_unassigned; if (query.sales_user_unassigned) delete params.sales_user_legacy_id; this.conversionBucket = ''; this.drill = {visible:true,title:title || '查看明细',baseTitle:title || '查看明细',baseParams:{...params},params,session:this.drill.session}; this.drillState = pageState(); this.loadDrill() },
    closeDrill() { this.drill.session += 1; this.drillState.sequence += 1; this.drill.visible = false; this.invalidateActions() },
    changeConversion(value) {
      this.drill.session += 1
      this.drill.params = value ? {...this.drill.baseParams,kind:'cohort_conversion',conversion_bucket:value} : {...this.drill.baseParams}
      this.drill.title = value ? `同批线索 · ${(this.conversionOptions.find(item => item.value === value) || {}).label || ''}` : this.drill.baseTitle
      this.drillState.page = 1
      this.loadDrill()
    },
    async loadDrill() {
      if (!this.drill.visible) return
      const session = this.drill.session; const state = this.drillState; const sequence = ++state.sequence
      state.loading = true; state.error = ''; state.data = null
      try { const {data} = await (this.drill.params.kind === 'orders' ? listDashboardOrders : listDashboardDrilldown)({...this.drill.params,page:state.page,per_page:state.per_page}); if (session === this.drill.session && sequence === state.sequence && this.drill.visible) state.data = data }
      catch (error) { if (session === this.drill.session && sequence === state.sequence && this.drill.visible) state.error = crmError(error,'明细加载失败') }
      finally { if (session === this.drill.session && sequence === state.sequence) state.loading = false }
    },
    drillPage(page) { this.drillState.page = page; this.loadDrill() },
    drillSize(size) { this.drillState.per_page = size; this.drillPage(1) },
    invalidateActions() { if (this.$refs.actions) this.$refs.actions.invalidate() },
    viewRecord(row) { this.$refs.actions.view(row) },
    actRecord(row) { this.$refs.actions.act(row) },
    afterChanged() { this.loadOverview(); this.loadTab(); if (this.drill.visible) this.loadDrill(); if (this.$refs.personal) this.$refs.personal.refresh() }
  }
}
</script>
