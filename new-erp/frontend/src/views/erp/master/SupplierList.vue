<template>
  <section class="sup-page">
    <div class="sup-workspace">
      <!-- 页面全局头部：图标、标题、统计标签与主要操作 -->
      <header class="page-head">
        <div class="head-left">
          <span class="head-icon"><i class="el-icon-office-building" /></span>
          <div class="head-title-wrap">
            <div class="title-row">
              <h1 class="page-title">供应商管理</h1>
              <el-tag size="small" type="success" effect="plain" class="total-tag">共 {{ pagination.total }} 家供应商</el-tag>
              <el-tag v-if="filters.category_id" size="small" type="info" effect="plain" closable class="filter-category-tag" @close="clearCategoryFilter">
                类目筛选生效中
              </el-tag>
            </div>
          </div>
        </div>
        <div class="head-actions">
          <el-button v-if="$can('master.import') || $can('master.import.upload')" size="small" icon="el-icon-upload2" class="btn-import" @click="$router.push({ path: '/master/imports', query: { type: 'Supplier' } })">导入供应商</el-button>
          <el-button v-if="$can('master.supplier.create')" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openCreate">新增供应商</el-button>
        </div>
      </header>

      <!-- 全局统一页面提示条 -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>维护企业上游供应商主档案、供货能力资质与采购价格协议。支持维护供应商关联物料供货目录、多级阶梯报价、变更版本追溯及采购履约记录。</span>
      </div>

      <!-- 筛选工具栏与主表格卡片 -->
      <section class="table-container-card">
        <div class="filter-toolbar">
          <div class="filter-fields">
            <div class="filter-item">
              <span class="filter-label">供应商</span>
              <el-input
                v-model.trim="filters.keyword"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="供应商编码 / 名称..."
                @keyup.enter.native="query"
              />
            </div>
            <div class="filter-item">
              <span class="filter-label">联系人</span>
              <el-input
                v-model.trim="filters.contact"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                placeholder="联系人 / 手机号..."
                @keyup.enter.native="query"
              />
            </div>
            <div class="filter-item">
              <span class="filter-label">类型</span>
              <el-select v-model="filters.supplier_type" size="small" clearable placeholder="全部类型" @change="query">
                <el-option label="生产制造商" value="manufacturer" />
                <el-option label="贸易商" value="trader" />
                <el-option label="服务商" value="service" />
              </el-select>
            </div>
            <div class="filter-item">
              <span class="filter-label">状态</span>
              <el-select v-model="filters.status" size="small" clearable placeholder="全部状态" @change="query">
                <el-option label="正常启用" value="enabled" />
                <el-option label="已停用" value="disabled" />
              </el-select>
            </div>
          </div>
          <div class="filter-actions">
            <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="query">查询</el-button>
            <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
          </div>
        </div>

        <div class="table-wrap">
          <el-table
            ref="table"
            v-loading="loading"
            :data="suppliers"
            size="small"
            border
            :row-class-name="rowClass"
            empty-text="暂无供应商数据，请先新增供应商或批量导入。"
            class="enterprise-table"
            @row-click="openDetail"
          >
            <el-table-column prop="supplier_code" label="供应商编码" width="130">
              <template slot-scope="{ row }">
                <span class="sup-code-link" @click.stop="openDetail(row)">
                  <i class="el-icon-office-building" />
                  {{ row.supplier_code }}
                </span>
              </template>
            </el-table-column>

            <el-table-column prop="supplier_name" label="供应商名称" min-width="190" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <div class="supplier-name-cell" @click.stop="openDetail(row)">
                  <span class="supplier-name-text" title="点击查看详情">{{ row.supplier_name }}</span>
                </div>
              </template>
            </el-table-column>

            <el-table-column prop="short_name" label="简称" width="90" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span v-if="row.short_name" class="short-badge">{{ row.short_name }}</span>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>

            <el-table-column label="类型" width="105" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="supplierTypeTagType(row.supplier_type)" effect="plain" class="type-tag">
                  {{ typeText(row.supplier_type) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column prop="contact_name" label="联系人" width="95">
              <template slot-scope="{ row }">
                <span>{{ row.contact_name || '-' }}</span>
              </template>
            </el-table-column>

            <el-table-column label="手机号" width="120">
              <template slot-scope="{ row }">
                <span class="font-tabular text-phone">{{ maskPhone(row.contact_phone || row.phone) }}</span>
              </template>
            </el-table-column>

            <el-table-column prop="active_item_relation_count" label="具体物料" width="85" align="right">
              <template slot-scope="{ row }">
                <span class="font-tabular stat-count" :class="{ 'has-val': Number(row.active_item_relation_count || 0) > 0 }">
                  {{ Number(row.active_item_relation_count || 0).toLocaleString() }}
                </span>
              </template>
            </el-table-column>

            <el-table-column prop="enabled_quotation_count" label="有效报价" width="85" align="right">
              <template slot-scope="{ row }">
                <span class="font-tabular stat-count" :class="{ 'has-val': Number(row.enabled_quotation_count || 0) > 0 }">
                  {{ Number(row.enabled_quotation_count || 0).toLocaleString() }}
                </span>
              </template>
            </el-table-column>

            <el-table-column label="采购资格" width="95" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="eligible(row) ? 'success' : 'danger'" effect="light" class="eligible-tag">
                  <i :class="eligible(row) ? 'el-icon-circle-check' : 'el-icon-warning-outline'" />
                  {{ eligible(row) ? '正常采购' : '采购受限' }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="状态" width="80" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'" effect="light" class="status-tag">
                  <i :class="row.status === 'enabled' ? 'el-icon-circle-check' : 'el-icon-circle-close'" />
                  {{ statusText(row.status) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column label="更新时间" width="135">
              <template slot-scope="{ row }">
                <span class="font-tabular text-muted date-text">{{ formatDate(row.updated_at) }}</span>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="230" :fixed="isActionFixed ? 'right' : false" align="center">
              <template slot-scope="{ row }">
                <div class="row-actions">
                  <el-button type="text" size="mini" class="btn-action-detail" icon="el-icon-view" @click.stop="openDetail(row)">详情</el-button>
                  <el-button v-if="$can('master.supplier.edit')" type="text" size="mini" class="btn-action-edit" icon="el-icon-edit" @click.stop="openEdit(row)">编辑</el-button>
                  <el-button
                    v-if="$can('master.supplier.edit')"
                    type="text"
                    size="mini"
                    :class="row.status === 'enabled' ? 'btn-action-disable' : 'btn-action-enable'"
                    :icon="row.status === 'enabled' ? 'el-icon-video-pause' : 'el-icon-video-play'"
                    @click.stop="toggleStatus(row)"
                  >
                    {{ row.status === 'enabled' ? '停用' : '启用' }}
                  </el-button>
                  <el-button
                    v-if="row.status !== 'enabled' && $can('master.supplier.delete')"
                    type="text"
                    size="mini"
                    class="btn-action-delete"
                    icon="el-icon-delete"
                    @click.stop="deleteSupplier(row)"
                  >
                    删除
                  </el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <footer class="pager-row">
          <span class="total-info">共 <strong>{{ pagination.total }}</strong> 家供应商</span>
          <el-pagination
            small
            background
            layout="prev, pager, next, sizes, jumper"
            :current-page="pagination.page"
            :page-size="pagination.perPage"
            :page-sizes="[10, 20, 50, 100]"
            :total="pagination.total"
            @current-change="changePage"
            @size-change="changeSize"
          />
        </footer>
      </section>
    </div>

    <!-- 供应商全景详情居中双栏弹窗（严格遵循 UI_OPTIMIZATION_RULES.md 3.3 节双栏规范，按子权限独立按需加载） -->
    <el-dialog
      :visible.sync="detailDialogVisible"
      width="1080px"
      top="5vh"
      append-to-body
      custom-class="sup-detail-dialog"
      :show-close="false"
      :close-on-click-modal="true"
    >
      <div v-if="selectedSupplier.id" class="sup-detail-shell">
        <!-- 左侧固定档案栏 (~276px, #f8fafc) -->
        <aside class="sup-profile-aside">
          <div class="profile-badges">
            <span class="sup-type-pill">{{ typeText(selectedSupplier.supplier_type) }}</span>
            <span class="sup-status-dot" :class="selectedSupplier.status === 'enabled' ? 'is-on' : 'is-off'">
              {{ statusText(selectedSupplier.status) }}
            </span>
          </div>
          <h2 class="profile-sup-title">{{ selectedSupplier.supplier_name }}</h2>
          <div class="profile-sup-code font-tabular">
            <span>{{ selectedSupplier.supplier_code }}</span>
            <small v-if="selectedSupplier.short_name">· {{ selectedSupplier.short_name }}</small>
          </div>

          <div class="profile-hero-card">
            <div class="hero-card-head">
              <span>采购准入状态</span>
              <b :class="eligible(selectedSupplier) ? 'is-ok' : 'is-warn'">
                {{ eligible(selectedSupplier) ? '正常可采购' : '采购受限' }}
              </b>
            </div>
            <div class="hero-metrics-grid">
              <div>
                <span>具体供货物料</span>
                <strong class="font-tabular">{{ Number(selectedSupplier.active_item_relation_count ?? relationPagination.total ?? 0) }}</strong>
              </div>
              <div>
                <span>有效报价协议</span>
                <strong class="font-tabular">{{ Number(selectedSupplier.enabled_quotation_count ?? quotePagination.total ?? 0) }}</strong>
              </div>
              <div>
                <span>审批状态</span>
                <b>{{ approvalText(selectedSupplier.approval_status) }}</b>
              </div>
              <div>
                <span>合作状态</span>
                <b>{{ cooperationText(selectedSupplier.cooperation_status) }}</b>
              </div>
            </div>
          </div>

          <dl class="profile-meta-dl">
            <div class="meta-row"><dt>联系人</dt><dd>{{ selectedSupplier.contact_name || '—' }}</dd></div>
            <div class="meta-row"><dt>联系手机</dt><dd class="font-tabular">{{ maskPhone(selectedSupplier.contact_phone) }}</dd></div>
            <div class="meta-row"><dt>固定电话</dt><dd class="font-tabular">{{ selectedSupplier.phone || '—' }}</dd></div>
            <div class="meta-row"><dt>电子邮箱</dt><dd>{{ selectedSupplier.email || '—' }}</dd></div>
            <div class="meta-row"><dt>结算方式</dt><dd>{{ selectedSupplier.settlement_method || '—' }}</dd></div>
            <div class="meta-row"><dt>付款方式</dt><dd>{{ selectedSupplier.payment_method || '—' }}</dd></div>
            <div v-if="selectedSupplier.bank_name" class="meta-block">
              <dt>开户银行与账号</dt>
              <dd>{{ selectedSupplier.bank_name }} {{ selectedSupplier.bank_account || '' }}</dd>
            </div>
          </dl>

          <button
            v-if="$can('master.supplier.edit')"
            type="button"
            class="profile-edit-btn"
            @click="openEdit(selectedSupplier)"
          >
            <i class="el-icon-edit" /> 编辑供应商主档案
          </button>
        </aside>

        <!-- 右侧纯白宽屏工作区 -->
        <main class="sup-workspace-main">
          <header class="sup-workspace-head">
            <nav class="sup-tabs-nav">
              <button
                type="button"
                class="sup-tab-btn"
                :class="{ active: detailTab === 'relations' }"
                @click="switchDetailTab('relations')"
              >
                <span>具体供货物料</span>
                <span class="tab-count-pill font-tabular">{{ relationPagination.total }}</span>
              </button>
              <button
                type="button"
                class="sup-tab-btn"
                :class="{ active: detailTab === 'quotations' }"
                @click="switchDetailTab('quotations')"
              >
                <span>阶梯报价协议</span>
                <span class="tab-count-pill font-tabular">{{ quotePagination.total }}</span>
              </button>
              <button
                type="button"
                class="sup-tab-btn"
                :class="{ active: detailTab === 'audit_history' }"
                @click="switchDetailTab('audit_history')"
              >
                <span>报价与能力履历</span>
              </button>
              <button
                type="button"
                class="sup-tab-btn"
                :class="{ active: detailTab === 'purchase_history' }"
                @click="switchDetailTab('purchase_history')"
              >
                <span>采购成交记录</span>
                <span class="tab-count-pill font-tabular">{{ purchasePagination.total }}</span>
              </button>
            </nav>
            <button type="button" class="sup-close-btn" title="关闭弹窗" @click="closeDetailDialog">
              <i class="el-icon-close" />
            </button>
          </header>

          <div class="sup-workspace-body">
            <!-- Tab 1: 具体供货物料 + 可供类目 -->
            <template v-if="detailTab === 'relations'">
              <div class="capability-category-strip">
                <div class="strip-left">
                  <span class="strip-label"><i class="el-icon-folder-opened" /> 可供物料类目：</span>
                  <template v-if="categoryCapabilities.length">
                    <span v-for="scope in categoryCapabilities" :key="scope.id" class="cat-chip">
                      {{ categoryPath(scope.item_category_id) }}
                    </span>
                  </template>
                  <span v-else class="text-muted text-xs">未维护候选类目范围</span>
                </div>
                <el-button v-if="$can('master.supplier.edit')" type="text" size="mini" class="detail-link" @click="openEdit(selectedSupplier, 'qualification')">
                  维护品类
                </el-button>
              </div>

              <div class="tab-toolbar">
                <el-input
                  v-model.trim="relationKeyword"
                  size="small"
                  clearable
                  prefix-icon="el-icon-search"
                  placeholder="按物料编码或名称筛选供货目录..."
                  class="tab-search-input"
                  @keyup.enter.native="loadRelations(1)"
                  @clear="loadRelations(1)"
                />
                <div class="tab-toolbar-right">
                  <el-button size="small" icon="el-icon-search" @click="loadRelations(1)">查询</el-button>
                  <el-button v-if="canEditCapability" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openRelation()">
                    新增供货关系
                  </el-button>
                </div>
              </div>

              <el-empty v-if="relationPermissionDenied" description="当前账号暂无查看物料供货能力权限" />
              <template v-else>
                <el-table v-loading="relationLoading" :data="relations" size="small" class="detail-clean-table" empty-text="暂无正式物料供货关系">
                  <el-table-column label="物料编码 / 名称" min-width="210" show-overflow-tooltip>
                    <template slot-scope="{ row }">
                      <div class="cell-primary font-tabular">{{ (row.item && row.item.item_code) || '—' }}</div>
                      <div class="cell-secondary">{{ (row.item && row.item.item_name) || '—' }}</div>
                    </template>
                  </el-table-column>
                  <el-table-column label="来源方式" width="95" align="center">
                    <template slot-scope="{ row }">
                      <span class="soft-pill">{{ sourceText(row.capability_source) }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="默认供应商" width="95" align="center">
                    <template slot-scope="{ row }">
                      <span v-if="row.is_default" class="ok-pill">首选默认</span>
                      <span v-else class="text-muted">备选</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="生效日期" width="110" align="center">
                    <template slot-scope="{ row }">
                      <span class="font-tabular text-muted">{{ row.effective_at ? String(row.effective_at).slice(0, 10) : '立即生效' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="状态" width="80" align="center">
                    <template slot-scope="{ row }">
                      <span :class="row.relation_status === 'active' ? 'ok-pill' : 'off-pill'">
                        {{ row.relation_status === 'active' ? '有效' : '失效' }}
                      </span>
                    </template>
                  </el-table-column>
                  <el-table-column v-if="canEditCapability" label="操作" width="130" align="center">
                    <template slot-scope="{ row }">
                      <div class="detail-row-actions">
                        <el-button v-if="row.relation_status === 'active'" type="text" size="mini" class="detail-link" @click="openEditRelation(row)">编辑</el-button>
                        <el-button v-if="row.relation_status === 'active'" type="text" size="mini" class="danger-link" @click="disableRelation(row)">停用</el-button>
                      </div>
                    </template>
                  </el-table-column>
                </el-table>
                <div v-if="relationPagination.total > relationPagination.perPage" class="detail-table-footer">
                  <span>共 {{ relationPagination.total }} 条供货关系</span>
                  <el-pagination
                    small
                    background
                    layout="prev, pager, next"
                    :current-page="relationPagination.page"
                    :page-size="relationPagination.perPage"
                    :total="relationPagination.total"
                    @current-change="loadRelations"
                  />
                </div>
              </template>
            </template>

            <!-- Tab 2: 阶梯报价协议 -->
            <template v-else-if="detailTab === 'quotations'">
              <div class="tab-toolbar">
                <el-input
                  v-model.trim="quoteKeyword"
                  size="small"
                  clearable
                  prefix-icon="el-icon-search"
                  placeholder="按物料编码或名称筛选报价协议..."
                  class="tab-search-input"
                  @keyup.enter.native="loadQuotations(1)"
                  @clear="loadQuotations(1)"
                />
                <div class="tab-toolbar-right">
                  <el-button size="small" icon="el-icon-search" @click="loadQuotations(1)">查询</el-button>
                  <el-button v-if="canEditQuotation" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openQuote()">
                    新增报价协议
                  </el-button>
                </div>
              </div>

              <el-empty v-if="quotePermissionDenied" description="当前账号暂无查看供应商报价权限" />
              <template v-else>
                <el-table v-loading="quoteLoading" :data="quotations" size="small" class="detail-clean-table" empty-text="暂无报价协议">
                  <el-table-column label="物料编码 / 名称" min-width="195" show-overflow-tooltip>
                    <template slot-scope="{ row }">
                      <div class="cell-primary font-tabular">{{ (row.item && row.item.item_code) || '—' }}</div>
                      <div class="cell-secondary">{{ (row.item && row.item.item_name) || '—' }}</div>
                    </template>
                  </el-table-column>
                  <el-table-column label="采购报价" min-width="135" align="right">
                    <template slot-scope="{ row }">
                      <div class="cell-primary font-tabular">{{ row.currency }} {{ purchasePrice(row.price) }} / {{ (row.unit && row.unit.unit_name) || '采购单位' }}</div>
                      <div class="cell-secondary font-tabular">基准：{{ purchasePrice(row.base_unit_price) }} / {{ (row.item && row.item.unit && row.item.unit.unit_name) || '基本单位' }}</div>
                    </template>
                  </el-table-column>
                  <el-table-column label="MOQ / 阶梯价" min-width="125" align="center">
                    <template slot-scope="{ row }">
                      <div class="font-tabular">MOQ {{ Number(row.min_order_qty || 0) }}</div>
                      <el-tooltip v-if="Array.isArray(row.tier_prices) && row.tier_prices.length" placement="top" :content="formatTierTooltip(row)">
                        <span class="ok-pill tier-badge">含 {{ row.tier_prices.length }} 档阶梯</span>
                      </el-tooltip>
                    </template>
                  </el-table-column>
                  <el-table-column label="税率 / 交期" width="105" align="center">
                    <template slot-scope="{ row }">
                      <div>{{ row.tax_mode === 'tax_included' ? `含税 ${Number(row.tax_rate || 0)}%` : '未税' }}</div>
                      <div class="cell-secondary font-tabular">{{ Number(row.lead_time_days || 0) }} 天交期</div>
                    </template>
                  </el-table-column>
                  <el-table-column label="有效期至" width="100" align="center">
                    <template slot-scope="{ row }">
                      <span class="font-tabular text-muted">{{ formatDateOnly(row.valid_until) }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="状态" width="75" align="center">
                    <template slot-scope="{ row }">
                      <span :class="row.status === 'enabled' ? 'ok-pill' : 'off-pill'">
                        {{ row.status === 'enabled' ? '生效' : '停用' }}
                      </span>
                    </template>
                  </el-table-column>
                  <el-table-column label="操作" width="150" align="center">
                    <template slot-scope="{ row }">
                      <div class="detail-row-actions">
                        <el-button v-if="canEditQuotation" type="text" size="mini" class="detail-link" @click="openEditQuote(row)">编辑</el-button>
                        <el-button type="text" size="mini" class="detail-link" @click="openQuoteHistoryForItem(row)">历史</el-button>
                        <el-button v-if="canEditQuotation && row.status === 'enabled'" type="text" size="mini" class="danger-link" @click="disableQuote(row)">停用</el-button>
                      </div>
                    </template>
                  </el-table-column>
                </el-table>
                <div v-if="quotePagination.total > quotePagination.perPage" class="detail-table-footer">
                  <span>共 {{ quotePagination.total }} 条报价记录</span>
                  <el-pagination
                    small
                    background
                    layout="prev, pager, next"
                    :current-page="quotePagination.page"
                    :page-size="quotePagination.perPage"
                    :total="quotePagination.total"
                    @current-change="loadQuotations"
                  />
                </div>
              </template>
            </template>

            <!-- Tab 3: 报价变更历史与供货关系日志 -->
            <template v-else-if="detailTab === 'audit_history'">
              <div class="tab-toolbar">
                <div class="history-mode-wrap">
                  <el-radio-group v-model="auditMode" size="small" @change="loadAuditHistory(1)">
                    <el-radio-button label="quotation">报价版本变更记录</el-radio-button>
                    <el-radio-button label="relation">供货关系变更日志</el-radio-button>
                  </el-radio-group>
                  <el-tag v-if="auditItemFilter.id" size="small" type="success" effect="plain" closable @close="clearAuditItemFilter">
                    筛选物料：{{ auditItemFilter.label }}
                  </el-tag>
                </div>
                <el-button size="small" icon="el-icon-refresh" @click="loadAuditHistory(auditPagination.page)">刷新</el-button>
              </div>

              <el-table v-if="auditMode === 'quotation'" v-loading="auditLoading" :data="quotationHistories" size="small" class="detail-clean-table" empty-text="暂无报价变更历史">
                <el-table-column label="变更时间" width="145">
                  <template slot-scope="{ row }">
                    <span class="font-tabular text-muted">{{ formatDate(row.created_at) }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="物料" min-width="180" show-overflow-tooltip>
                  <template slot-scope="{ row }">
                    <div class="cell-primary font-tabular">{{ (row.item && row.item.item_code) || '—' }}</div>
                    <div class="cell-secondary">{{ (row.item && row.item.item_name) || '—' }}</div>
                  </template>
                </el-table-column>
                <el-table-column label="动作" width="85" align="center">
                  <template slot-scope="{ row }">
                    <span :class="row.action === 'disable' ? 'off-pill' : 'ok-pill'">{{ historyActionText(row.action) }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="快照价格 / 阶梯" min-width="165">
                  <template slot-scope="{ row }">
                    <span v-if="row.quotation_snapshot" class="font-tabular">
                      {{ row.quotation_snapshot.currency || 'CNY' }} {{ purchasePrice(row.quotation_snapshot.price || 0) }}
                      <small v-if="Array.isArray(row.quotation_snapshot.tier_prices) && row.quotation_snapshot.tier_prices.length" class="text-success">
                        ({{ row.quotation_snapshot.tier_prices.length }}档阶梯)
                      </small>
                    </span>
                    <span v-else class="text-muted">—</span>
                  </template>
                </el-table-column>
                <el-table-column prop="change_reason" label="变更原因" min-width="160" show-overflow-tooltip />
              </el-table>

              <el-table v-else v-loading="auditLoading" :data="relationHistories" size="small" class="detail-clean-table" empty-text="暂无供货关系变更日志">
                <el-table-column label="变更时间" width="145">
                  <template slot-scope="{ row }">
                    <span class="font-tabular text-muted">{{ formatDate(row.created_at) }}</span>
                  </template>
                </el-table-column>
                <el-table-column label="物料" min-width="180" show-overflow-tooltip>
                  <template slot-scope="{ row }">
                    <div class="cell-primary font-tabular">{{ (row.item && row.item.item_code) || '—' }}</div>
                    <div class="cell-secondary">{{ (row.item && row.item.item_name) || '—' }}</div>
                  </template>
                </el-table-column>
                <el-table-column label="动作" width="95" align="center">
                  <template slot-scope="{ row }">
                    <span :class="row.action === 'disable' ? 'off-pill' : 'ok-pill'">{{ historyActionText(row.action) }}</span>
                  </template>
                </el-table-column>
                <el-table-column prop="change_reason" label="变更原因" min-width="160" show-overflow-tooltip />
                <el-table-column prop="remark" label="备注说明" min-width="140" show-overflow-tooltip />
              </el-table>

              <div v-if="auditPagination.total > auditPagination.perPage" class="detail-table-footer">
                <span>共 {{ auditPagination.total }} 条变更记录</span>
                <el-pagination
                  small
                  background
                  layout="prev, pager, next"
                  :current-page="auditPagination.page"
                  :page-size="auditPagination.perPage"
                  :total="auditPagination.total"
                  @current-change="loadAuditHistory"
                />
              </div>
            </template>

            <!-- Tab 4: 采购成交记录 -->
            <template v-else-if="detailTab === 'purchase_history'">
              <el-empty v-if="purchasePermissionDenied" description="当前账号暂无查看采购历史权限" />
              <template v-else>
                <el-table v-loading="purchaseLoading" :data="purchaseHistory" size="small" class="detail-clean-table" empty-text="暂无采购成交记录">
                  <el-table-column label="物料编码 / 名称" min-width="210" show-overflow-tooltip>
                    <template slot-scope="{ row }">
                      <div class="cell-primary font-tabular">{{ (row.item && row.item.item_code) || '—' }}</div>
                      <div class="cell-secondary">{{ (row.item && row.item.item_name) || '—' }}</div>
                    </template>
                  </el-table-column>
                  <el-table-column label="成交单价" width="140" align="right">
                    <template slot-scope="{ row }">
                      <span class="font-tabular fw-bold">{{ row.currency }} {{ purchasePrice(row.price) }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column label="采购单位" width="100" align="center">
                    <template slot-scope="{ row }">
                      <span>{{ (row.unit && row.unit.unit_name) || (row.item && row.item.unit && row.item.unit.unit_name) || '—' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column prop="effective_date" label="成交日期" width="130" align="center">
                    <template slot-scope="{ row }">
                      <span class="font-tabular text-muted">{{ row.effective_date || '—' }}</span>
                    </template>
                  </el-table-column>
                </el-table>
                <div v-if="purchasePagination.total > purchasePagination.perPage" class="detail-table-footer">
                  <span>共 {{ purchasePagination.total }} 条采购记录</span>
                  <el-pagination
                    small
                    background
                    layout="prev, pager, next"
                    :current-page="purchasePagination.page"
                    :page-size="purchasePagination.perPage"
                    :total="purchasePagination.total"
                    @current-change="loadPurchaseHistory"
                  />
                </div>
              </template>
            </template>
          </div>

          <footer class="sup-workspace-footer">
            <span>供应商编码：{{ selectedSupplier.supplier_code }} · 最近更新：{{ formatDate(selectedSupplier.updated_at) }}</span>
            <el-button size="small" @click="closeDetailDialog">关闭</el-button>
          </footer>
        </main>
      </div>
    </el-dialog>

    <!-- 弹窗 1：新增 / 编辑供应商主档案 -->
    <el-dialog
      :visible.sync="supplierDialogVisible"
      :title="supplierDialogTitle"
      width="880px"
      top="8vh"
      append-to-body
      custom-class="erp-supplier-dialog"
      :close-on-click-modal="false"
      @close="closeSupplierDialog"
    >
      <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small" class="dialog-supplier-form">
        <el-tabs v-model="activeTab" class="supplier-tabs">
          <el-tab-pane name="basic">
            <span slot="label"><i class="el-icon-office-building" /> 基础与商务信息</span>
            <div class="form-grid-3">
              <el-form-item label="供应商编码" prop="supplier_code">
                <el-input v-model="form.supplier_code" disabled class="font-tabular erp-system-number">
                  <template slot="append">{{ supplierDialogMode === 'create' ? '系统预生成' : '锁定' }}</template>
                </el-input>
              </el-form-item>
              <el-form-item label="供应商全称" prop="supplier_name" class="col-span-2">
                <el-input v-model.trim="form.supplier_name" placeholder="请输入企业工商注册全称" maxlength="150" clearable />
              </el-form-item>

              <el-form-item label="企业简称">
                <el-input v-model.trim="form.short_name" placeholder="选填，如 中龙包装" clearable />
              </el-form-item>
              <el-form-item label="供应商类型">
                <el-select v-model="form.supplier_type" class="full">
                  <el-option label="生产制造商" value="manufacturer" />
                  <el-option label="贸易商" value="trader" />
                  <el-option label="服务商" value="service" />
                </el-select>
              </el-form-item>
              <el-form-item label="档案启用状态">
                <el-radio-group v-model="form.status" class="status-radio-compact">
                  <el-radio label="enabled"><span class="text-success"><i class="el-icon-circle-check" /> 正常启用</span></el-radio>
                  <el-radio label="disabled"><span class="text-muted"><i class="el-icon-circle-close" /> 停用档案</span></el-radio>
                </el-radio-group>
              </el-form-item>

              <el-form-item label="业务联系人">
                <el-input v-model.trim="form.contact_name" placeholder="主要业务联系人" clearable />
              </el-form-item>
              <el-form-item label="联系人手机">
                <el-input v-model.trim="form.contact_phone" placeholder="常用手机号" class="font-tabular" clearable />
              </el-form-item>
              <el-form-item label="电子邮箱">
                <el-input v-model.trim="form.email" placeholder="业务通知接收邮箱" clearable />
              </el-form-item>

              <el-form-item label="结算方式">
                <el-input v-model.trim="form.settlement_method" placeholder="如 月结30天、现结" clearable />
              </el-form-item>
              <el-form-item label="付款方式">
                <el-input v-model.trim="form.payment_method" placeholder="如 银行转账、承兑" clearable />
              </el-form-item>
              <el-form-item label="固定电话">
                <el-input v-model.trim="form.phone" placeholder="座机号码" class="font-tabular" clearable />
              </el-form-item>

              <el-form-item label="开户银行" class="col-span-1-5">
                <el-input v-model.trim="form.bank_name" placeholder="如 中国工商银行某某支行" clearable />
              </el-form-item>
              <el-form-item label="银行账号" class="col-span-1-5">
                <el-input v-model.trim="form.bank_account" placeholder="对公银行结算账号" class="font-tabular" clearable />
              </el-form-item>
            </div>
          </el-tab-pane>

          <el-tab-pane name="qualification">
            <span slot="label"><i class="el-icon-folder-opened" /> 资质准入与供货品类</span>
            <div class="tab-qualification-grid">
              <div class="qual-col-left">
                <div class="form-grid-2">
                  <el-form-item label="审批状态">
                    <el-select v-model="form.approval_status" class="full">
                      <el-option label="已审批合格" value="approved" />
                      <el-option label="待审批" value="pending" />
                      <el-option label="已驳回" value="rejected" />
                    </el-select>
                  </el-form-item>
                  <el-form-item label="合作状态">
                    <el-select v-model="form.cooperation_status" class="full">
                      <el-option label="正常合作" value="normal" />
                      <el-option label="合作异常" value="abnormal" />
                      <el-option label="终止合作" value="terminated" />
                    </el-select>
                  </el-form-item>
                </div>

                <div class="switch-row-compact">
                  <div class="switch-item">
                    <span class="switch-label">黑名单管制</span>
                    <el-switch v-model="form.is_blacklisted" active-color="#ef4444" />
                    <span v-if="form.is_blacklisted" class="danger-tip-text">禁止采购</span>
                  </div>
                  <div class="switch-item">
                    <span class="switch-label">限制采购</span>
                    <el-switch v-model="form.purchase_restricted" active-color="#f59e0b" />
                    <span v-if="form.purchase_restricted" class="warn-tip-text">受限合作</span>
                  </div>
                </div>

                <el-form-item label="补充说明 / 协议备注" class="mt-8">
                  <el-input v-model="form.remark" type="textarea" :rows="3" placeholder="选填，补充说明该供应商经营范围、战略协议、合作约定等..." />
                </el-form-item>
              </div>

              <div class="qual-col-right">
                <el-form-item label="可供物料品类（采购寻源候选范围）">
                  <item-category-tree-picker v-model="selectedCategoryIds" :tree="categoryTree" multiple />
                  <div class="category-scope-tip">
                    <i class="el-icon-info" />
                    <span>品类用于采购寻源候选筛选；具体供货以物料供货关系与阶梯报价为准。</span>
                  </div>
                </el-form-item>
              </div>
            </div>
          </el-tab-pane>
        </el-tabs>
      </el-form>

      <div slot="footer" class="dialog-footer">
        <div class="footer-left">
          <el-button
            v-if="activeTab === 'basic'"
            size="small"
            icon="el-icon-arrow-right"
            class="btn-step-switch"
            @click="activeTab = 'qualification'"
          >
            前往：资质准入与供货品类
          </el-button>
          <el-button
            v-else
            size="small"
            icon="el-icon-arrow-left"
            class="btn-step-switch"
            @click="activeTab = 'basic'"
          >
            返回：基础与商务信息
          </el-button>
        </div>
        <div class="footer-right">
          <el-button size="small" class="btn-dialog-cancel" @click="closeSupplierDialog">取消</el-button>
          <el-button size="small" type="success" :loading="saving" :disabled="saving || (supplierDialogMode === 'create' && !reservation)" class="btn-theme-create" icon="el-icon-check" @click="saveSupplier">
            保存供应商档案
          </el-button>
        </div>
      </div>
    </el-dialog>

    <!-- 弹窗 2：新增 / 编辑物料供货关系 -->
    <el-dialog
      :visible.sync="relationDialogVisible"
      :title="relationForm.id ? '编辑物料供货关系' : '新增物料供货关系'"
      width="580px"
      append-to-body
      custom-class="erp-supplier-dialog"
      :close-on-click-modal="false"
    >
      <el-form ref="relationForm" :model="relationForm" :rules="relationRules" label-position="top" size="small">
        <el-form-item label="具体物料" prop="item_id">
          <paged-record-select
            v-model="relationForm.item_id"
            entity="items"
            category-type="item"
            :params="{ status: 'enabled', is_purchase_item: 1 }"
            :selected-rows.sync="selectedItems"
            :disabled="!!relationForm.id"
            :clearable="false"
            class="full"
            title="选择供货物料"
            placeholder="点击弹窗按分类或编码名称选择物料"
          />
        </el-form-item>
        <el-form-item label="能力来源" prop="capability_source">
          <el-select v-model="relationForm.capability_source" class="full">
            <el-option label="人工确认" value="manual_confirmed" />
            <el-option label="报价转入" value="quotation" />
            <el-option label="采购历史" value="purchase_history" />
          </el-select>
        </el-form-item>
        <el-form-item label="是否作为该物料默认供应商">
          <div class="switch-box">
            <el-switch v-model="relationForm.is_default" active-color="#008b4b" />
            <span class="text-xs text-muted">{{ relationForm.is_default ? '采购寻源默认优选该供应商' : '非默认供应商' }}</span>
          </div>
        </el-form-item>
        <div class="form-grid-2">
          <el-form-item label="生效日期">
            <el-date-picker v-model="relationForm.effective_at" value-format="yyyy-MM-dd" class="full" placeholder="默认立即生效" />
          </el-form-item>
          <el-form-item label="失效日期">
            <el-date-picker v-model="relationForm.expired_at" value-format="yyyy-MM-dd" class="full" placeholder="选填，长期有效留空" />
          </el-form-item>
        </div>
        <el-form-item label="变更原因" prop="change_reason">
          <el-input v-model="relationForm.change_reason" placeholder="如 人工确认供货资质 / 调整默认首选供应商" />
        </el-form-item>
        <el-form-item label="备注说明">
          <el-input v-model="relationForm.remark" type="textarea" :rows="2" placeholder="补充说明供货能力细节或前置交期要求..." />
        </el-form-item>
      </el-form>
      <div slot="footer" class="dialog-footer">
        <span />
        <div class="footer-right">
          <el-button size="small" class="btn-dialog-cancel" @click="relationDialogVisible = false">取消</el-button>
          <el-button size="small" type="success" :loading="saving" class="btn-theme-create" icon="el-icon-check" @click="saveRelation">
            保存供货关系
          </el-button>
        </div>
      </div>
    </el-dialog>

    <!-- 弹窗 3：新增 / 编辑供应商阶梯报价 -->
    <el-dialog
      :visible.sync="quoteDialogVisible"
      :title="quoteForm.id ? '编辑供应商阶梯报价' : '新增供应商阶梯报价'"
      width="760px"
      top="5vh"
      append-to-body
      custom-class="erp-supplier-dialog"
      :close-on-click-modal="false"
    >
      <el-form ref="quoteForm" :model="quoteForm" :rules="quoteRules" label-position="top" size="small">
        <div class="form-grid-2">
          <el-form-item label="具体物料" prop="item_id">
            <paged-record-select
              v-model="quoteForm.item_id"
              entity="items"
              category-type="item"
              :params="{ status: 'enabled', is_purchase_item: 1 }"
              :selected-rows.sync="selectedItems"
              :disabled="!!quoteForm.id"
              :clearable="false"
              class="full"
              title="选择报价物料"
              placeholder="点击弹窗按分类或编码名称选择物料"
              @change="() => loadQuoteConversions()"
            />
          </el-form-item>
          <el-form-item label="采购单位" prop="unit_id">
            <paged-record-select v-model="quoteForm.unit_id" :request="requestQuoteConversions" :params="{ item_id: quoteForm.item_id }" value-key="purchase_unit_id" :selected-rows.sync="quoteConversions" :label="quoteUnitLabel" :columns="[{ prop: 'purchase_unit.unit_code', label: '单位编码' }, { prop: 'purchase_unit.unit_name', label: '采购单位' }, { prop: 'factor', label: '换算系数' }]" :disabled="!quoteForm.item_id" :clearable="false" class="full" title="选择采购单位" placeholder="请选择采购单位" />
          </el-form-item>
        </div>

        <div class="conversion-box">
          <div class="conversion-head">
            <b>单位换算与供应商专用系数</b>
            <el-checkbox v-model="quoteForm.use_supplier_factor">启用供应商专用换算系数</el-checkbox>
          </div>
          <p class="conversion-p">物料标准换算：1 {{ quotePurchaseUnit }} = {{ quoteStandardFactor }} {{ quoteBaseUnit }}</p>
          <div v-if="quoteForm.use_supplier_factor" class="form-grid-2" style="margin-top:8px;">
            <el-form-item label="供应商专用系数" style="margin-bottom:6px;">
              <el-input-number v-model="quoteForm.supplier_conversion_factor" :min="0.00000001" :precision="8" :controls="false" class="full" />
            </el-form-item>
            <el-form-item label="专用系数原因" style="margin-bottom:6px;">
              <el-input v-model.trim="quoteForm.supplier_factor_reason" placeholder="必填，如：该厂包装每箱固定24个" />
            </el-form-item>
          </div>
          <div class="quote-formula font-tabular">{{ quoteFormulaText }}</div>
          <div class="factor-result">
            <span>基本单位：<strong>{{ quoteBaseUnit }}</strong></span>
            <span>基准单价：<strong class="font-tabular">{{ quoteBaseUnitPrice === '-' ? '-' : `${quoteBaseUnitPrice} ${quoteForm.currency}/${quoteBaseUnit}` }}</strong></span>
          </div>
        </div>

        <div class="form-grid-3">
          <el-form-item label="基准报价单价" prop="price">
            <erp-money-input unit-price v-model="quoteForm.price" type="number" min="0.00000001" step="0.00000001" class="full font-tabular" placeholder="采购单价" />
          </el-form-item>
          <el-form-item label="币种">
            <el-input v-model.trim="quoteForm.currency" class="full font-tabular" placeholder="如 CNY" />
          </el-form-item>
          <el-form-item label="供货交期(天)">
            <el-input-number v-model="quoteForm.lead_time_days" :min="0" class="full" />
          </el-form-item>
          <el-form-item label="计税方式">
            <el-select v-model="quoteForm.tax_mode" class="full">
              <el-option label="含税" value="tax_included" />
              <el-option label="未税" value="tax_excluded" />
            </el-select>
          </el-form-item>
          <el-form-item label="税率(%)">
            <el-input-number v-model="quoteForm.tax_rate" :min="0" :max="100" class="full" />
          </el-form-item>
          <el-form-item label="最小起订量 (MOQ)">
            <el-input-number v-model="quoteForm.min_order_qty" :min="0" :precision="4" class="full" />
          </el-form-item>
        </div>

        <!-- 多级阶梯价格配置区 -->
        <div class="tier-editor-card">
          <div class="tier-editor-head">
            <span><i class="el-icon-s-data" /> 多级数量阶梯优惠价（选填，按起订量区间递增）</span>
            <el-button type="text" size="mini" icon="el-icon-plus" class="detail-link" @click="addQuoteTier">添加阶梯档位</el-button>
          </div>
          <div v-if="quoteForm.tier_prices && quoteForm.tier_prices.length" class="tier-rows">
            <div v-for="(tier, idx) in quoteForm.tier_prices" :key="idx" class="tier-row">
              <span class="tier-idx">第 {{ idx + 1 }} 档</span>
              <el-input-number v-model="tier.min_qty" size="mini" :min="0.0001" :precision="4" :controls="false" placeholder="起始量" class="tier-num" />
              <span class="tier-sep">~</span>
              <el-input-number v-model="tier.max_qty" size="mini" :min="0" :precision="4" :controls="false" placeholder="上限(空为不限)" class="tier-num" />
              <span class="tier-unit">{{ quotePurchaseUnit === '-' ? '单位' : quotePurchaseUnit }}</span>
              <el-input-number v-model="tier.price" size="mini" :min="0.00000001" :precision="6" :controls="false" placeholder="阶梯单价" class="tier-price" />
              <span class="tier-unit">{{ quoteForm.currency }}</span>
              <el-button type="text" size="mini" icon="el-icon-delete" class="danger-link" @click="quoteForm.tier_prices.splice(idx, 1)" />
            </div>
          </div>
          <p v-else class="tier-empty-hint">未设置多级阶梯时，统一按上方基准报价单价执行。</p>
        </div>

        <div class="form-grid-2">
          <el-form-item label="协议有效期" required>
            <el-date-picker v-model="quoteDates" type="daterange" value-format="yyyy-MM-dd" start-placeholder="开始日期" end-placeholder="结束日期" class="full" />
          </el-form-item>
          <el-form-item label="变更原因">
            <el-input v-model="quoteForm.change_reason" placeholder="如 季度协议调价 / 新增阶梯优惠" />
          </el-form-item>
        </div>
        <el-form-item label="备注说明">
          <el-input v-model="quoteForm.remark" type="textarea" :rows="2" placeholder="补充说明报价特殊约束或结算条件..." />
        </el-form-item>
      </el-form>
      <div slot="footer" class="dialog-footer">
        <span />
        <div class="footer-right">
          <el-button size="small" class="btn-dialog-cancel" @click="quoteDialogVisible = false">取消</el-button>
          <el-button size="small" type="success" :loading="saving" class="btn-theme-create" icon="el-icon-check" @click="saveQuote">
            保存报价协议
          </el-button>
        </div>
      </div>
    </el-dialog>
  </section>
</template>

<script>
import { formatPurchasePrice, purchaseDecimal, purchasePriceRatio } from '@/utils/purchaseLineAmount'
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { erpDateTime } from '../../../utils/dateTime'
import {
  listEntity, saveEntity, disableEntity, enableEntity, deleteEntity, getItemCategoryTree,
  getSupplierCapabilities, listSupplierItemRelations, saveSupplierItemRelation, disableSupplierItemRelation,
  listSupplierQuotations, saveSupplierQuotation, disableSupplierQuotation, listSupplierPurchaseHistory,
  listSupplierRelationHistory, listSupplierQuotationHistory, listItemPurchaseConversionOptions
} from '../../../api/erp/master'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'
import ItemCategoryTreePicker from '../../../components/master/ItemCategoryTreePicker.vue'

const emptyForm = () => ({
  id: null,
  supplier_code: '',
  supplier_name: '',
  short_name: '',
  supplier_type: 'manufacturer',
  contact_name: '',
  contact_phone: '',
  phone: '',
  email: '',
  address: '',
  default_tax_rate: 0,
  settlement_method: '月结30天',
  payment_method: '银行转账',
  bank_name: '',
  bank_account: '',
  level: '',
  approval_status: 'approved',
  is_blacklisted: false,
  cooperation_status: 'normal',
  purchase_restricted: false,
  quality_status: 'normal',
  quality_frozen_until: null,
  status: 'enabled',
  remark: '',
  category_ids: []
})

const emptyRelation = () => ({
  id: null,
  item_id: null,
  capability_source: 'manual_confirmed',
  is_default: false,
  effective_at: '',
  expired_at: '',
  change_reason: '人工确认供货能力',
  remark: ''
})

const emptyQuote = () => ({
  id: null,
  item_id: null,
  unit_id: null,
  price: null,
  currency: 'CNY',
  tax_mode: 'tax_included',
  tax_rate: 13,
  lead_time_days: 0,
  min_order_qty: 1,
  max_order_qty: null,
  use_supplier_factor: false,
  supplier_conversion_factor: undefined,
  supplier_factor_reason: '',
  tier_prices: [],
  change_reason: '供应商报价更新',
  remark: ''
})

export default {
  name: 'SupplierList',
  components: { PagedRecordSelect, ItemCategoryTreePicker },
  data () {
    return {
      compact: typeof window !== 'undefined' ? window.innerWidth <= 1400 : false,
      loading: false,
      saving: false,
      detailDialogVisible: false,
      detailTab: 'relations',
      relationLoading: false,
      quoteLoading: false,
      purchaseLoading: false,
      auditLoading: false,
      relationPermissionDenied: false,
      quotePermissionDenied: false,
      purchasePermissionDenied: false,
      relationKeyword: '',
      quoteKeyword: '',
      auditMode: 'quotation',
      auditItemFilter: { id: null, label: '' },
      supplierDialogVisible: false,
      supplierDialogMode: 'create',
      activeTab: 'basic',
      relationDialogVisible: false,
      quoteDialogVisible: false,
      suppliers: [],
      selectedItems: [],
      categories: [],
      selectedSupplier: {},
      categoryCapabilities: [],
      relations: [],
      quotations: [],
      purchaseHistory: [],
      quotationHistories: [],
      relationHistories: [],
      reservation: null,
      quoteConversions: [],
      selectedCategoryIds: [],
      categoryTree: [],
      form: emptyForm(),
      relationForm: emptyRelation(),
      quoteForm: emptyQuote(),
      quoteDates: [],
      filters: {
        keyword: '',
        contact: '',
        supplier_type: '',
        status: '',
        category_id: this.$route.query.category_id ? Number(this.$route.query.category_id) : ''
      },
      pagination: { page: 1, perPage: 10, total: 0 },
      relationPagination: { page: 1, perPage: 10, total: 0 },
      quotePagination: { page: 1, perPage: 10, total: 0 },
      purchasePagination: { page: 1, perPage: 10, total: 0 },
      auditPagination: { page: 1, perPage: 10, total: 0 },
      rules: {
        supplier_code: [{ required: true, message: '系统未取得供应商编码，请重新打开新增窗口', trigger: 'blur' }],
        supplier_name: [{ required: true, message: '请输入供应商名称', trigger: 'blur' }]
      },
      relationRules: {
        item_id: [{ required: true, message: '请选择具体物料', trigger: 'change' }],
        capability_source: [{ required: true, message: '请选择能力来源', trigger: 'change' }],
        change_reason: [{ required: true, message: '请输入变更原因', trigger: 'blur' }]
      },
      quoteRules: {
        item_id: [{ required: true, message: '请选择具体物料', trigger: 'change' }],
        unit_id: [{ required: true, message: '请选择采购单位', trigger: 'change' }],
        price: [{ required: true, message: '请输入大于 0 的有效报价', trigger: 'blur' }]
      }
    }
  },
  computed: {
    canEditCapability () {
      return this.$can('supplier_capability.edit') || this.$can('master.supplier.edit')
    },
    canEditQuotation () {
      return this.$can('supplier_quotation.edit')
    },
    supplierDialogTitle () {
      return this.supplierDialogMode === 'create'
        ? '新增供应商主档案'
        : `编辑供应商主档案 - ${this.form.supplier_name || this.form.supplier_code}`
    },
    quoteConversion () {
      return this.quoteConversions.find(row => Number(row.purchase_unit_id) === Number(this.quoteForm.unit_id))
    },
    quoteStandardFactor () {
      const f = (this.quoteConversion && this.quoteConversion.factor) || 0
      return formatPurchasePrice(f)
    },
    quoteFinalFactor () {
      if (this.quoteForm.use_supplier_factor && Number(this.quoteForm.supplier_conversion_factor) > 0) {
        return Number(this.quoteForm.supplier_conversion_factor)
      }
      return Number((this.quoteConversion && this.quoteConversion.factor) || 0)
    },
    quotePurchaseUnit () {
      return this.quoteConversion && this.quoteConversion.purchase_unit ? this.quoteConversion.purchase_unit.unit_name : '-'
    },
    quoteBaseUnit () {
      return this.quoteConversion && this.quoteConversion.base_unit ? this.quoteConversion.base_unit.unit_name : '-'
    },
    quoteBaseUnitPrice () {
      return this.quoteFinalFactor > 0 && Number(this.quoteForm.price || 0) > 0
        ? formatPurchasePrice(purchasePriceRatio(this.quoteForm.price, this.quoteFinalFactor))
        : '-'
    },
    quoteFormulaText () {
      if (!this.quoteConversion || this.quoteFinalFactor <= 0 || Number(this.quoteForm.price || 0) <= 0) {
        return '请先选择物料、采购单位并填写有效报价'
      }
      const price = formatPurchasePrice(this.quoteForm.price)
      const factor = formatPurchasePrice(this.quoteFinalFactor)
      const tax = this.quoteForm.tax_mode === 'tax_included' ? `含税 ${Number(this.quoteForm.tax_rate || 0)}%` : '未税'
      return `${price} ${this.quoteForm.currency}/${this.quotePurchaseUnit} ÷ ${factor} ${this.quoteBaseUnit}/${this.quotePurchaseUnit} = ${this.quoteBaseUnitPrice} ${this.quoteForm.currency}/${this.quoteBaseUnit}（${tax}）`
    },
    isActionFixed () {
      return !this.compact
    }
  },
  created () {
    this.fetchOptions()
    this.fetchAll()
  },
  mounted () {
    this.handleResize = () => {
      this.compact = window.innerWidth <= 1400
      this.$nextTick(() => {
        if (this.$refs.table) this.$refs.table.doLayout()
      })
    }
    window.addEventListener('resize', this.handleResize)
  },
  beforeDestroy () {
    if (this.handleResize) window.removeEventListener('resize', this.handleResize)
  },
  methods: {
    closeDetailDialog () {
      this.detailDialogVisible = false
    },
    clearCategoryFilter () {
      this.filters.category_id = ''
      this.query()
    },
    async fetchOptions () {
      if (!this.$can('item_category.view') && !this.$can('master.supplier.create') && !this.$can('master.supplier.edit')) return
      try {
        const categories = await getItemCategoryTree()
        this.categoryTree = categories.data.data || []
        this.categories = this.flattenCategories(this.categoryTree)
      } catch (_) {}
    },
    async fetchAll () {
      this.loading = true
      try {
        const response = await listEntity('suppliers', {
          page: this.pagination.page,
          per_page: this.pagination.perPage,
          keyword: this.filters.keyword,
          contact_keyword: this.filters.contact,
          supplier_type: this.filters.supplier_type,
          status: this.filters.status,
          category_id: this.filters.category_id
        })
        this.suppliers = response.data.data || []
        this.pagination.total = response.data.total || 0
      } catch (e) {
        this.$message.error(e.userMessage || '供应商数据加载失败')
      } finally {
        this.loading = false
      }
    },
    query () {
      this.pagination.page = 1
      this.fetchAll()
    },
    reset () {
      this.filters = { keyword: '', contact: '', supplier_type: '', status: '', category_id: '' }
      this.query()
    },
    changePage (page) {
      this.pagination.page = page
      this.fetchAll()
    },
    changeSize (size) {
      this.pagination.perPage = size
      this.pagination.page = 1
      this.fetchAll()
    },
    flattenCategories (tree) {
      const rows = []
      const visit = list => (list || []).forEach(row => {
        rows.push(row)
        visit(row.children)
      })
      visit(tree)
      return rows
    },
    categoryPath (id) {
      return (this.categories.find(row => Number(row.id) === Number(id)) || {}).full_path || '-'
    },
    async openDetail (row) {
      this.selectedSupplier = { ...row }
      this.detailTab = 'relations'
      this.relationKeyword = ''
      this.quoteKeyword = ''
      this.auditItemFilter = { id: null, label: '' }
      this.detailDialogVisible = true
      await this.loadSupplierOverview()
    },
    async loadSupplierOverview () {
      if (!this.selectedSupplier.id) return
      const id = this.selectedSupplier.id
      const [capRes] = await Promise.allSettled([
        getSupplierCapabilities(id),
        this.loadRelations(1),
        this.loadQuotations(1),
        this.loadPurchaseHistory(1)
      ])
      if (capRes.status === 'fulfilled' && capRes.value && capRes.value.data && capRes.value.data.data) {
        this.selectedSupplier = capRes.value.data.data
        this.categoryCapabilities = this.selectedSupplier.category_capabilities || []
      }
    },
    switchDetailTab (tab) {
      this.detailTab = tab
      if (tab === 'audit_history') this.loadAuditHistory(1)
    },
    applyPage (payload, current, property, pagination) {
      this[property] = payload.data || current
      pagination.page = payload.current_page || 1
      pagination.total = payload.total || 0
    },
    async loadRelations (page = 1) {
      if (!this.selectedSupplier.id) return
      this.relationLoading = true
      this.relationPermissionDenied = false
      try {
        const response = await listSupplierItemRelations(this.selectedSupplier.id, {
          page,
          per_page: this.relationPagination.perPage,
          keyword: this.relationKeyword || undefined
        })
        this.applyPage(response.data, [], 'relations', this.relationPagination)
      } catch (e) {
        if (e.response && e.response.status === 403) this.relationPermissionDenied = true
        else this.$message.error(e.userMessage || '供货关系加载失败')
      } finally {
        this.relationLoading = false
      }
    },
    async loadQuotations (page = 1) {
      if (!this.selectedSupplier.id) return
      this.quoteLoading = true
      this.quotePermissionDenied = false
      try {
        const response = await listSupplierQuotations(this.selectedSupplier.id, {
          page,
          per_page: this.quotePagination.perPage,
          keyword: this.quoteKeyword || undefined
        })
        this.applyPage(response.data, [], 'quotations', this.quotePagination)
      } catch (e) {
        if (e.response && e.response.status === 403) this.quotePermissionDenied = true
        else this.$message.error(e.userMessage || '供应商报价加载失败')
      } finally {
        this.quoteLoading = false
      }
    },
    async loadPurchaseHistory (page = 1) {
      if (!this.selectedSupplier.id) return
      this.purchaseLoading = true
      this.purchasePermissionDenied = false
      try {
        const response = await listSupplierPurchaseHistory(this.selectedSupplier.id, {
          page,
          per_page: this.purchasePagination.perPage
        })
        this.applyPage(response.data, [], 'purchaseHistory', this.purchasePagination)
      } catch (e) {
        if (e.response && e.response.status === 403) this.purchasePermissionDenied = true
      } finally {
        this.purchaseLoading = false
      }
    },
    async loadAuditHistory (page = 1) {
      if (!this.selectedSupplier.id) return
      this.auditLoading = true
      try {
        const params = { page, per_page: this.auditPagination.perPage }
        if (this.auditItemFilter.id) params.item_id = this.auditItemFilter.id
        if (this.auditMode === 'quotation') {
          const { data } = await listSupplierQuotationHistory(this.selectedSupplier.id, params)
          this.applyPage(data, [], 'quotationHistories', this.auditPagination)
        } else {
          const { data } = await listSupplierRelationHistory(this.selectedSupplier.id, params)
          this.applyPage(data, [], 'relationHistories', this.auditPagination)
        }
      } catch (e) {
        this.$message.error(e.userMessage || '变更履历加载失败')
      } finally {
        this.auditLoading = false
      }
    },
    openQuoteHistoryForItem (row) {
      this.auditMode = 'quotation'
      this.auditItemFilter = {
        id: row.item_id,
        label: row.item ? `${row.item.item_code} ${row.item.item_name}` : `#${row.item_id}`
      }
      this.detailTab = 'audit_history'
      this.loadAuditHistory(1)
    },
    clearAuditItemFilter () {
      this.auditItemFilter = { id: null, label: '' }
      this.loadAuditHistory(1)
    },
    async openCreate () {
      const form = emptyForm()
      this.form = form
      this.reservation = null
      this.selectedCategoryIds = []
      this.activeTab = 'basic'
      this.supplierDialogMode = 'create'
      this.supplierDialogVisible = true
      try {
        const reservation = await reserveForCreatePage('supplier', '/master/suppliers/create')
        if (!this.supplierDialogVisible || this.supplierDialogMode !== 'create' || this.form !== form) return
        this.reservation = reservation
        form.supplier_code = reservation.document_no
      } catch (e) {
        if (this.supplierDialogVisible && this.supplierDialogMode === 'create' && this.form === form) {
          this.$message.error(e.userMessage || '供应商编码预生成失败')
        }
      }
      this.$nextTick(() => { if (this.$refs.form) this.$refs.form.clearValidate() })
    },
    async openEdit (row, initialTab = 'basic') {
      this.selectedSupplier = { ...row }
      if (!row.category_capabilities) {
        try {
          const cap = await getSupplierCapabilities(row.id)
          if (cap.data && cap.data.data) {
            this.selectedSupplier = cap.data.data
            this.categoryCapabilities = this.selectedSupplier.category_capabilities || []
          }
        } catch (_) {}
      }
      this.form = { ...emptyForm(), ...this.selectedSupplier }
      const ids = (this.selectedSupplier.category_capabilities || []).map(scope => scope.item_category_id)
      this.selectedCategoryIds = ids.map(Number)
      this.activeTab = initialTab
      this.supplierDialogMode = 'edit'
      this.supplierDialogVisible = true
      this.$nextTick(() => { if (this.$refs.form) this.$refs.form.clearValidate() })
    },
    closeSupplierDialog () {
      if (this.supplierDialogMode === 'create' && this.reservation) {
        this.reservation = null
      }
      this.supplierDialogVisible = false
    },
    saveSupplier () {
      if (this.saving || (this.supplierDialogMode === 'create' && !this.reservation)) return
      this.$refs.form.validate(async valid => {
        if (!valid) {
          if (!this.form.supplier_name) this.activeTab = 'basic'
          return
        }
        this.saving = true
        try {
          const reservation = this.reservation
          const payload = { ...this.form, category_ids: Array.from(new Set(this.selectedCategoryIds.map(Number))) }
          if (!payload.id && reservation) {
            payload.reservation_token = reservation.reservation_token
            payload.creation_session_id = reservation.creation_session_id
          }
          const response = await saveEntity('suppliers', payload)
          if (!payload.id) clearCreatePageReservation(reservation)
          this.reservation = null
          this.$message.success('供应商档案已保存')
          this.supplierDialogVisible = false
          await this.fetchAll()
          if (this.detailDialogVisible && this.selectedSupplier.id === response.data.data.id) {
            await this.loadSupplierOverview()
          }
        } catch (e) {
          this.$message.error(e.userMessage || '供应商保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    openRelation () {
      this.selectedItems = []
      this.relationForm = emptyRelation()
      this.relationDialogVisible = true
      this.$nextTick(() => { if (this.$refs.relationForm) this.$refs.relationForm.clearValidate() })
    },
    openEditRelation (row) {
      this.selectedItems = row.item ? [row.item] : []
      this.relationForm = {
        id: row.id,
        item_id: row.item_id,
        capability_source: row.capability_source || 'manual_confirmed',
        is_default: !!row.is_default,
        effective_at: row.effective_at ? String(row.effective_at).slice(0, 10) : '',
        expired_at: row.expired_at ? String(row.expired_at).slice(0, 10) : '',
        change_reason: '更新供货能力配置',
        remark: row.remark || ''
      }
      this.relationDialogVisible = true
      this.$nextTick(() => { if (this.$refs.relationForm) this.$refs.relationForm.clearValidate() })
    },
    saveRelation () {
      this.$refs.relationForm.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          const payload = { ...this.relationForm, relation_status: 'active' }
          if (!payload.effective_at) delete payload.effective_at
          if (!payload.expired_at) payload.expired_at = null
          await saveSupplierItemRelation(this.selectedSupplier.id, payload)
          this.$message.success('物料供货关系已保存')
          this.relationDialogVisible = false
          await Promise.all([this.loadSupplierOverview(), this.fetchAll()])
        } catch (e) {
          this.$message.error(e.userMessage || '物料供货关系保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    openQuote () {
      this.selectedItems = []
      this.quoteForm = emptyQuote()
      this.quoteConversions = []
      this.quoteDates = []
      this.quoteDialogVisible = true
      this.$nextTick(() => { if (this.$refs.quoteForm) this.$refs.quoteForm.clearValidate() })
    },
    async openEditQuote (row) {
      this.selectedItems = row.item ? [row.item] : []
      const tiers = Array.isArray(row.tier_prices)
        ? row.tier_prices.map(t => ({
            min_qty: Number(t.min_qty || 1),
            max_qty: t.max_qty !== null && t.max_qty !== undefined ? Number(t.max_qty) : undefined,
            price: Number(t.price || row.price || 0)
          }))
        : []
      this.quoteForm = {
        id: row.id,
        item_id: row.item_id,
        unit_id: row.unit_id,
        price: Number(row.price || 0),
        currency: row.currency || 'CNY',
        tax_mode: row.tax_mode || 'tax_included',
        tax_rate: Number(row.tax_rate || 0),
        lead_time_days: Number(row.lead_time_days || 0),
        min_order_qty: Number(row.min_order_qty || 0),
        max_order_qty: row.max_order_qty ? Number(row.max_order_qty) : null,
        use_supplier_factor: row.factor_source === 'supplier_specific',
        supplier_conversion_factor: row.factor_source === 'supplier_specific' ? Number(row.final_conversion_factor || 1) : undefined,
        supplier_factor_reason: row.supplier_factor_reason || '',
        tier_prices: tiers,
        change_reason: '更新供应商报价协议',
        remark: row.remark || ''
      }
      const today = new Date().toISOString().slice(0, 10)
      const from = row.valid_from ? String(row.valid_from).slice(0, 10) : today
      const until = row.valid_until ? String(row.valid_until).slice(0, 10) : '2099-12-31'
      this.quoteDates = [from, until]
      this.quoteDialogVisible = true
      await this.loadQuoteConversions(row.unit_id)
      this.$nextTick(() => { if (this.$refs.quoteForm) this.$refs.quoteForm.clearValidate() })
    },
    addQuoteTier () {
      const list = this.quoteForm.tier_prices || []
      const prev = list[list.length - 1]
      const nextMin = prev && Number(prev.max_qty) > 0 ? Number(prev.max_qty) + 1 : Number(this.quoteForm.min_order_qty || 10)
      list.push({ min_qty: nextMin, max_qty: undefined, price: Number(this.quoteForm.price || 0) || undefined })
      this.$set(this.quoteForm, 'tier_prices', list)
    },
    formatTierTooltip (row) {
      return (row.tier_prices || [])
        .map(t => `${t.min_qty}~${t.max_qty || '∞'}: ${row.currency} ${this.purchasePrice(t.price)}`)
        .join(' ｜ ')
    },
    requestQuoteConversions (params) { return listItemPurchaseConversionOptions(this.quoteForm.item_id, params) },
    quoteUnitLabel (row) { return row.purchase_unit ? `${row.purchase_unit.unit_name} (${row.purchase_unit.unit_code})` : '' },
    async loadQuoteConversions (preserveUnitId = null) {
      const form = this.quoteForm
      const itemId = form.item_id
      this.quoteConversions = []
      if (!preserveUnitId) form.unit_id = null
      if (!itemId) return
      try {
        const { data } = await listItemPurchaseConversionOptions(itemId, preserveUnitId
          ? { ids: [preserveUnitId] }
          : { page: 1, per_page: 10 })
        if (form !== this.quoteForm || itemId !== form.item_id) return
        const currentConversion = this.quoteConversion
        this.quoteConversions = data.data || []
        if (currentConversion && !this.quoteConversion) this.quoteConversions.push(currentConversion)
        if (!preserveUnitId && !form.unit_id) {
          const selected = this.quoteConversions.find(row => row.is_default) || this.quoteConversions[0]
          if (selected) form.unit_id = selected.purchase_unit_id
        }
      } catch (_) {}
    },
    saveQuote () {
      this.$refs.quoteForm.validate(async valid => {
        if (!valid) return
        if (!this.quoteDates || this.quoteDates.length !== 2) return this.$message.warning('请选择报价有效期')
        if (this.quoteForm.use_supplier_factor && (!(Number(this.quoteForm.supplier_conversion_factor) > 0) || !this.quoteForm.supplier_factor_reason)) {
          return this.$message.warning('启用供应商专用系数时，请填写大于 0 的系数及专用原因')
        }
        const cleanTiers = (this.quoteForm.tier_prices || [])
          .filter(t => Number(t.min_qty) > 0 && Number(t.price) > 0)
          .map(t => ({
            min_qty: Number(t.min_qty),
            max_qty: Number(t.max_qty) > 0 ? Number(t.max_qty) : null,
            price: purchaseDecimal(t.price)
          }))
        this.saving = true
        try {
          await saveSupplierQuotation(this.selectedSupplier.id, {
            ...this.quoteForm,
            price: purchaseDecimal(this.quoteForm.price),
            max_order_qty: Number(this.quoteForm.max_order_qty) > 0 ? this.quoteForm.max_order_qty : null,
            tier_prices: cleanTiers.length ? cleanTiers : null,
            valid_from: this.quoteDates[0],
            valid_until: this.quoteDates[1]
          })
          this.$message.success('供应商报价协议已保存')
          this.quoteDialogVisible = false
          await Promise.all([this.loadSupplierOverview(), this.fetchAll()])
        } catch (e) {
          this.$message.error(e.userMessage || '供应商报价保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    async disableRelation (row) {
      try {
        const result = await this.$prompt('停用后不会删除历史。请输入停用原因：', '停用物料供货关系', {
          inputPattern: /\S+/,
          inputErrorMessage: '必须填写原因'
        })
        await disableSupplierItemRelation(this.selectedSupplier.id, row.id, { reason: result.value })
        this.$message.success('物料关系已停用')
        await Promise.all([this.loadSupplierOverview(), this.fetchAll()])
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '停用失败')
      }
    },
    async disableQuote (row) {
      try {
        const result = await this.$prompt('请输入报价停用原因，系统会保留报价历史：', '停用报价协议', {
          inputPattern: /\S+/,
          inputErrorMessage: '必须填写原因'
        })
        await disableSupplierQuotation(this.selectedSupplier.id, row.id, { reason: result.value })
        this.$message.success('报价已停用')
        await Promise.all([this.loadSupplierOverview(), this.fetchAll()])
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '报价停用失败')
      }
    },
    async toggleStatus (row) {
      try {
        await this.$confirm(`确认${row.status === 'enabled' ? '停用' : '启用'}供应商 ${row.supplier_code} / ${row.supplier_name}？`, '状态确认', { type: 'warning' })
        if (row.status === 'enabled') await disableEntity('suppliers', row.id)
        else await enableEntity('suppliers', row.id)
        this.$message.success('供应商状态已更新')
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '状态更新失败')
      }
    },
    async deleteSupplier (row) {
      try {
        await this.$confirm(`确认删除供应商 ${row.supplier_code} / ${row.supplier_name}？仅从未进入采购业务的停用供应商可以删除。`, '删除供应商', {
          type: 'warning',
          confirmButtonText: '确认删除'
        })
        await deleteEntity('suppliers', row.id)
        this.$message.success('供应商已删除')
        if (this.selectedSupplier.id === row.id) {
          this.detailDialogVisible = false
          this.selectedSupplier = {}
        }
        await this.fetchAll()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '供应商删除失败')
      }
    },
    eligible (row) {
      return (
        row.status === 'enabled' &&
        (row.approval_status || 'approved') === 'approved' &&
        !row.is_blacklisted &&
        (row.cooperation_status || 'normal') === 'normal' &&
        !row.purchase_restricted &&
        (row.quality_status || 'normal') !== 'frozen'
      )
    },
    typeText (v) {
      return ({ manufacturer: '生产制造商', trader: '贸易商', service: '服务商' })[v] || v || '-'
    },
    supplierTypeTagType (v) {
      const map = { manufacturer: 'success', trader: 'warning', service: 'info' }
      return map[v] || 'info'
    },
    statusText (v) {
      return v === 'disabled' ? '停用' : '启用'
    },
    approvalText (v) {
      return ({ approved: '已审批', pending: '待审批', rejected: '已驳回' })[v || 'approved']
    },
    cooperationText (v) {
      return ({ normal: '正常合作', abnormal: '合作异常', terminated: '终止合作' })[v || 'normal']
    },
    sourceText (v) {
      return ({ manual_confirmed: '人工确认', quotation: '报价联动', purchase_history: '采购历史' })[v] || v
    },
    historyActionText (v) {
      return ({ create: '新增', update: '更新', disable: '停用', upgrade: '升级' })[v] || v || '变更'
    },
    maskPhone (v) {
      return v ? String(v).replace(/(\d{3})\d+(\d{4})/, '$1****$2') : '-'
    },
    formatDate (v) {
      return v ? erpDateTime(v, 16) : '-'
    },
    formatDateOnly (v) {
      return v ? String(v).slice(0, 10) : '长期'
    },
    purchasePrice: formatPurchasePrice,
    rowClass ({ row }) {
      return row.id === this.selectedSupplier.id && this.detailDialogVisible ? 'selected-row' : ''
    }
  }
}
</script>

<style scoped>
.sup-page {
  position: relative;
  min-height: calc(100vh - 52px);
  background: #f8fafc;
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
}

.sup-workspace {
  padding: 16px 20px 24px;
  width: 100%;
  min-width: 0;
  box-sizing: border-box;
}

.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
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
}

.total-tag {
  font-weight: 600;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
}

.filter-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 14px 16px;
  border-bottom: 1px solid #f1f5f9;
}

.filter-fields {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-label {
  font-size: 13px;
  color: #475569;
  white-space: nowrap;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}

.font-tabular {
  font-variant-numeric: tabular-nums;
}

.sup-code-link {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  color: #00763f;
  font-size: 13px;
  font-weight: 600;
  cursor: pointer;
  line-height: 1.4;
  white-space: nowrap;
  transition: all 0.15s ease;
}

.sup-code-link:hover {
  background: #dcfce7;
  border-color: #86efac;
  color: #00562e;
}

.sup-code-link i {
  font-size: 12px;
  color: #008b4b;
}

.supplier-name-cell {
  cursor: pointer;
  display: inline-block;
}

.supplier-name-text {
  font-size: 13px;
  font-weight: 600;
  color: #0f172a;
}

.supplier-name-text:hover {
  color: #008b4b;
}

.short-badge {
  display: inline-block;
  padding: 1px 6px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 12px;
  color: #334155;
}

.text-phone,
.date-text {
  font-size: 12px;
  color: #334155;
}

.stat-count {
  font-size: 12px;
  color: #94a3b8;
}

.stat-count.has-val {
  color: #008b4b;
  font-weight: 600;
}

.text-muted { color: #94a3b8; }
.text-xs { font-size: 11px; }
.fw-bold { font-weight: 600; }
.text-success { color: #008b4b !important; }
.danger-link { color: #ef4444 !important; }

.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
}

.row-actions .el-button--text {
  padding: 4px 6px !important;
  font-size: 13px !important;
  margin-left: 0 !important;
}

.btn-action-detail,
.btn-action-edit,
.btn-action-enable { color: #008b4b !important; }
.btn-action-disable { color: #d97706 !important; }
.btn-action-delete { color: #ef4444 !important; }

.pager-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  padding: 12px 16px;
  background: #ffffff;
  border-top: 1px solid #f1f5f9;
}

.total-info { font-size: 13px; color: #64748b; }
.total-info strong { color: #0f172a; }

/* 编辑表单弹窗 */
.form-grid-3 { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 0 16px; }
.form-grid-2 { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0 16px; }
.col-span-2 { grid-column: span 2; }
.col-span-1-5 { grid-column: span 1; }
.full { width: 100%; }
.tab-qualification-grid { display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr); gap: 20px; }
.switch-row-compact {
  display: flex;
  gap: 20px;
  align-items: center;
  padding: 8px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  margin-bottom: 12px;
}
.switch-item { display: flex; align-items: center; gap: 8px; }
.switch-label { font-size: 12px; color: #334155; font-weight: 500; }
.switch-box { display: flex; align-items: center; gap: 8px; height: 32px; }
.danger-tip-text { font-size: 11px; color: #ef4444; }
.warn-tip-text { font-size: 11px; color: #d97706; }

.category-scope-tip {
  margin-top: 8px;
  padding: 8px 12px;
  border: 1px solid #bbf7d0;
  background: #f0fdf4;
  color: #166534;
  border-radius: 6px;
  font-size: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.conversion-box {
  margin: 0 0 12px;
  padding: 12px;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  background: #f0fdf4;
}
.conversion-head { display: flex; justify-content: space-between; align-items: center; }
.conversion-p { margin: 6px 0; color: #166534; font-size: 12px; }
.quote-formula {
  margin: 8px 0;
  padding: 6px 10px;
  border: 1px solid #86efac;
  border-radius: 4px;
  background: #ffffff;
  color: #15803d;
  font-weight: 600;
  font-size: 12px;
}
.factor-result {
  display: flex;
  justify-content: space-between;
  padding-top: 6px;
  border-top: 1px dashed #bbf7d0;
  color: #166534;
  font-size: 12px;
}

.tier-editor-card {
  margin-bottom: 14px;
  padding: 10px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #f8fafc;
}
.tier-editor-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  font-weight: 600;
  color: #334155;
  margin-bottom: 8px;
}
.tier-rows { display: flex; flex-direction: column; gap: 8px; }
.tier-row { display: flex; align-items: center; gap: 8px; flex-wrap: wrap; }
.tier-idx { font-size: 12px; color: #64748b; width: 48px; }
.tier-num { width: 125px; }
.tier-price { width: 135px; }
.tier-sep, .tier-unit { font-size: 12px; color: #64748b; }
.tier-empty-hint { margin: 0; font-size: 12px; color: #94a3b8; }

.dialog-footer { display: flex; align-items: center; justify-content: space-between; }
.footer-right { display: flex; align-items: center; gap: 10px; }

.supplier-tabs ::v-deep .el-tabs__item.is-active { color: #008b4b !important; font-weight: 600; }
.supplier-tabs ::v-deep .el-tabs__active-bar { background-color: #008b4b !important; }

@media (max-width: 900px) {
  .form-grid-3, .form-grid-2, .tab-qualification-grid { grid-template-columns: 1fr; }
  .col-span-2 { grid-column: span 1; }
}
</style>

<style>
/* Section 3.3 双栏供应商全景详情弹窗样式 */
.sup-detail-dialog {
  border-radius: 12px !important;
  overflow: hidden !important;
  max-width: calc(100vw - 32px);
}
.sup-detail-dialog .el-dialog__header { display: none; }
.sup-detail-dialog .el-dialog__body { padding: 0 !important; }
.sup-detail-dialog .sup-detail-shell {
  display: grid;
  grid-template-columns: 276px minmax(0, 1fr);
  height: min(680px, calc(100vh - 72px));
  background: #ffffff;
  box-sizing: border-box;
}
.sup-detail-dialog .sup-profile-aside {
  background: #f8fafc;
  border-right: 1px solid #edf1f5;
  padding: 20px 18px;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
  box-sizing: border-box;
}
.sup-detail-dialog .profile-badges {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  margin-bottom: 10px;
}
.sup-detail-dialog .sup-type-pill {
  padding: 2px 8px;
  border-radius: 999px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #00763f;
  font-size: 11px;
  font-weight: 600;
}
.sup-detail-dialog .sup-status-dot { font-size: 12px; font-weight: 600; }
.sup-detail-dialog .sup-status-dot.is-on { color: #008b4b; }
.sup-detail-dialog .sup-status-dot.is-off { color: #94a3b8; }
.sup-detail-dialog .profile-sup-title {
  margin: 0 0 4px;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.35;
}
.sup-detail-dialog .profile-sup-code {
  font-size: 12px;
  color: #64748b;
  margin-bottom: 14px;
}
.sup-detail-dialog .profile-hero-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 10px;
  padding: 12px;
  margin-bottom: 14px;
}
.sup-detail-dialog .hero-card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
  color: #64748b;
  margin-bottom: 10px;
}
.sup-detail-dialog .hero-card-head b.is-ok { color: #008b4b; }
.sup-detail-dialog .hero-card-head b.is-warn { color: #ef4444; }
.sup-detail-dialog .hero-metrics-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 8px;
  padding-top: 8px;
  border-top: 1px solid #f1f5f9;
}
.sup-detail-dialog .hero-metrics-grid span { display: block; font-size: 11px; color: #94a3b8; }
.sup-detail-dialog .hero-metrics-grid strong { font-size: 17px; color: #008b4b; font-weight: 700; }
.sup-detail-dialog .hero-metrics-grid b { font-size: 12px; color: #1e293b; font-weight: 600; }
.sup-detail-dialog .profile-meta-dl {
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 12px;
  flex: 1;
}
.sup-detail-dialog .profile-meta-dl .meta-row {
  display: flex;
  justify-content: space-between;
  gap: 8px;
}
.sup-detail-dialog .profile-meta-dl dt { color: #64748b; flex-shrink: 0; }
.sup-detail-dialog .profile-meta-dl dd { margin: 0; color: #1e293b; font-weight: 500; text-align: right; word-break: break-all; }
.sup-detail-dialog .profile-meta-dl .meta-block { display: flex; flex-direction: column; gap: 3px; }
.sup-detail-dialog .profile-meta-dl .meta-block dd { text-align: left; }
.sup-detail-dialog .profile-edit-btn {
  margin-top: 14px;
  width: 100%;
  height: 34px;
  border: 1px solid #bbf7d0;
  background: #f0fdf4;
  color: #00763f;
  border-radius: 8px;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s;
}
.sup-detail-dialog .profile-edit-btn:hover { background: #dcfce7; border-color: #86efac; }

.sup-detail-dialog .sup-workspace-main {
  display: flex;
  flex-direction: column;
  min-width: 0;
  min-height: 0;
  background: #ffffff;
}
.sup-detail-dialog .sup-workspace-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 20px;
  height: 52px;
  border-bottom: 1px solid #edf1f5;
  flex-shrink: 0;
}
.sup-detail-dialog .sup-tabs-nav { display: flex; align-items: center; gap: 22px; height: 100%; }
.sup-detail-dialog .sup-tab-btn {
  position: relative;
  height: 100%;
  border: 0;
  background: transparent;
  padding: 0 2px;
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}
.sup-detail-dialog .sup-tab-btn.active { color: #008b4b; font-weight: 700; }
.sup-detail-dialog .sup-tab-btn.active::after {
  content: '';
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 2px;
  background: #008b4b;
  border-radius: 2px;
}
.sup-detail-dialog .tab-count-pill {
  padding: 1px 6px;
  border-radius: 999px;
  background: #f1f5f9;
  color: #64748b;
  font-size: 11px;
}
.sup-detail-dialog .sup-tab-btn.active .tab-count-pill { background: #f0fdf4; color: #008b4b; }
.sup-detail-dialog .sup-close-btn {
  width: 30px;
  height: 30px;
  border: 0;
  background: transparent;
  border-radius: 6px;
  color: #64748b;
  cursor: pointer;
  font-size: 16px;
}
.sup-detail-dialog .sup-close-btn:hover { background: #f1f5f9; color: #0f172a; }

.sup-detail-dialog .sup-workspace-body {
  flex: 1;
  padding: 16px 20px;
  overflow-y: auto;
  min-height: 0;
}
.sup-detail-dialog .capability-category-strip {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  padding: 8px 12px;
  background: #f8fafc;
  border-radius: 8px;
  margin-bottom: 12px;
}
.sup-detail-dialog .strip-left { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; min-width: 0; }
.sup-detail-dialog .strip-label { font-size: 12px; font-weight: 600; color: #475569; }
.sup-detail-dialog .cat-chip {
  padding: 2px 8px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 11px;
  color: #334155;
}
.sup-detail-dialog .tab-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 12px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}
.sup-detail-dialog .tab-search-input { width: 280px; max-width: 100%; }
.sup-detail-dialog .tab-toolbar-right,
.sup-detail-dialog .history-mode-wrap { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.sup-detail-dialog .cell-primary { font-size: 13px; font-weight: 600; color: #0f172a; }
.sup-detail-dialog .cell-secondary { font-size: 11px; color: #64748b; margin-top: 2px; }
.sup-detail-dialog .soft-pill { padding: 2px 7px; border-radius: 4px; background: #f1f5f9; color: #475569; font-size: 11px; }
.sup-detail-dialog .ok-pill { display: inline-block; padding: 2px 7px; border-radius: 4px; background: #f0fdf4; color: #00763f; border: 1px solid #bbf7d0; font-size: 11px; font-weight: 600; }
.sup-detail-dialog .off-pill { display: inline-block; padding: 2px 7px; border-radius: 4px; background: #f8fafc; color: #94a3b8; border: 1px solid #e2e8f0; font-size: 11px; }
.sup-detail-dialog .tier-badge { margin-top: 2px; cursor: pointer; }
.sup-detail-dialog .detail-link { color: #008b4b !important; }
.sup-detail-dialog .detail-row-actions { display: flex; align-items: center; justify-content: center; gap: 10px; }
.sup-detail-dialog .detail-table-footer {
  margin-top: 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  color: #64748b;
}
.sup-detail-dialog .sup-workspace-footer {
  height: 48px;
  padding: 0 20px;
  border-top: 1px solid #edf1f5;
  display: flex;
  align-items: center;
  justify-content: space-between;
  font-size: 12px;
  color: #94a3b8;
  flex-shrink: 0;
}

@media (max-width: 960px) {
  .sup-detail-dialog .sup-detail-shell {
    grid-template-columns: 1fr;
    height: auto;
    max-height: calc(100vh - 64px);
    overflow-y: auto;
  }
  .sup-detail-dialog .sup-profile-aside {
    border-right: 0;
    border-bottom: 1px solid #edf1f5;
  }
}
</style>
