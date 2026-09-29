<template>
  <section class="sup-page" :class="{ 'drawer-open': drawerVisible }">
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
          <el-button size="small" icon="el-icon-upload2" class="btn-import" @click="$router.push({ path: '/master/imports', query: { type: 'Supplier' } })">导入供应商</el-button>
          <el-button size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openCreate">新增供应商</el-button>
        </div>
      </header>

      <!-- 全局统一页面提示条（用户指令：无需综合指标卡片，聚焦业务列表） -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>维护企业上游供应商主档案、供货能力资质与采购价格协议。支持维护供应商关联 Item 供货目录、换算阶梯报价及采购履约对账记录。</span>
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
            <el-table-column prop="supplier_code" label="供应商编码" width="125">
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

            <el-table-column prop="short_name" label="简称" width="85" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span v-if="row.short_name" class="short-badge">{{ row.short_name }}</span>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>

            <el-table-column label="类型" width="100" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="supplierTypeTagType(row.supplier_type)" effect="plain" class="type-tag">
                  {{ typeText(row.supplier_type) }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column prop="contact_name" label="联系人" width="90">
              <template slot-scope="{ row }">
                <span>{{ row.contact_name || '-' }}</span>
              </template>
            </el-table-column>

            <el-table-column label="手机号" width="120">
              <template slot-scope="{ row }">
                <span class="font-tabular text-phone">{{ maskPhone(row.contact_phone || row.phone) }}</span>
              </template>
            </el-table-column>

            <el-table-column prop="active_item_relation_count" label="具体物料" width="80" align="right">
              <template slot-scope="{ row }">
                <span class="font-tabular stat-count" :class="{ 'has-val': Number(row.active_item_relation_count || 0) > 0 }">
                  {{ Number(row.active_item_relation_count || 0).toLocaleString() }}
                </span>
              </template>
            </el-table-column>

            <el-table-column prop="enabled_quotation_count" label="有效报价" width="80" align="right">
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

            <el-table-column label="更新时间" width="130">
              <template slot-scope="{ row }">
                <span class="font-tabular text-muted date-text">{{ formatDate(row.updated_at) }}</span>
              </template>
            </el-table-column>

            <el-table-column label="操作" width="230" :fixed="isActionFixed ? 'right' : false" align="center">
              <template slot-scope="{ row }">
                <div class="row-actions">
                  <el-button type="text" size="mini" class="btn-action-detail" icon="el-icon-view" @click.stop="openDetail(row)">详情</el-button>
                  <el-button type="text" size="mini" class="btn-action-edit" icon="el-icon-edit" @click.stop="openEdit(row)">编辑</el-button>
                  <el-button
                    type="text"
                    size="mini"
                    :class="row.status === 'enabled' ? 'btn-action-disable' : 'btn-action-enable'"
                    :icon="row.status === 'enabled' ? 'el-icon-video-pause' : 'el-icon-video-play'"
                    @click.stop="toggleStatus(row)"
                  >
                    {{ row.status === 'enabled' ? '停用' : '启用' }}
                  </el-button>
                  <el-button
                    v-if="row.status !== 'enabled'"
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

    <!-- 侧边详情看板抽屉（专用于供应商全景查看与履约洞察） -->
    <aside v-if="drawerVisible" class="sup-drawer">
      <div class="drawer-head">
        <div class="drawer-head-title">
          <h2>{{ drawerTitle }}</h2>
          <small v-if="selectedSupplier.supplier_code" class="font-tabular drawer-code-badge">{{ selectedSupplier.supplier_code }}</small>
        </div>
        <button class="drawer-close-btn" title="关闭面板" @click="closeDrawer">
          <i class="el-icon-close" />
        </button>
      </div>

      <div v-loading="detailLoading" class="drawer-body">
        <section class="drawer-card">
          <div class="card-head">
            <h3><i class="el-icon-info" /> 基础属性</h3>
            <el-button type="text" size="mini" class="btn-card-action" icon="el-icon-edit" @click="openEdit(selectedSupplier)">编辑</el-button>
          </div>
          <dl class="info-grid">
            <dt>供应商名称</dt><dd class="fw-bold">{{ selectedSupplier.supplier_name }}</dd>
            <dt>供应商类型</dt><dd><el-tag size="mini" effect="plain">{{ typeText(selectedSupplier.supplier_type) }}</el-tag></dd>
            <dt>审批状态</dt><dd>{{ approvalText(selectedSupplier.approval_status) }}</dd>
            <dt>合作状态</dt><dd>{{ cooperationText(selectedSupplier.cooperation_status) }}</dd>
            <dt>质量状态</dt><dd>{{ selectedSupplier.quality_status === 'frozen' ? '质量冻结' : '正常' }}</dd>
            <dt>采购限制</dt><dd>{{ selectedSupplier.purchase_restricted ? '限制采购' : '正常合作' }}</dd>
          </dl>
        </section>

        <section class="drawer-card">
          <div class="card-head">
            <h3><i class="el-icon-phone-outline" /> 联系方式与结算</h3>
          </div>
          <dl class="info-grid">
            <dt>联系人</dt><dd>{{ selectedSupplier.contact_name || '-' }}</dd>
            <dt>联系手机</dt><dd class="font-tabular">{{ maskPhone(selectedSupplier.contact_phone) }}</dd>
            <dt>固定电话</dt><dd class="font-tabular">{{ selectedSupplier.phone || '-' }}</dd>
            <dt>电子邮箱</dt><dd>{{ selectedSupplier.email || '-' }}</dd>
            <dt>结算方式</dt><dd>{{ selectedSupplier.settlement_method || '-' }}</dd>
            <dt>付款方式</dt><dd>{{ selectedSupplier.payment_method || '-' }}</dd>
          </dl>
        </section>

        <section class="drawer-card">
          <div class="card-head">
            <h3><i class="el-icon-folder-opened" /> 可供物料类目</h3>
            <el-button type="text" size="mini" class="btn-card-action" icon="el-icon-edit" @click="openEdit(selectedSupplier)">维护</el-button>
          </div>
          <div class="tag-list">
            <el-tag v-for="scope in categoryCapabilities" :key="scope.id" size="mini" effect="plain">{{ categoryPath(scope.item_category_id) }}</el-tag>
            <span v-if="!categoryCapabilities.length" class="text-muted text-xs">未维护品类范围；品类用于候选筛选，不能替代具体物料供货关系。</span>
          </div>
        </section>

        <section class="drawer-card">
          <div class="card-head">
            <h3><i class="el-icon-goods" /> 具体物料供货能力（{{ relationPagination.total }}）</h3>
            <el-button size="mini" type="success" plain class="btn-mini-add" icon="el-icon-plus" @click="openRelation">新增关系</el-button>
          </div>
          <el-table :data="relations" size="mini" border empty-text="暂无正式物料供货关系">
            <el-table-column prop="item.item_code" label="物料编码" min-width="110" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="font-tabular chip-mini">{{ (row.item && row.item.item_code) || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="item.item_name" label="物料名称" min-width="110" show-overflow-tooltip />
            <el-table-column label="来源" width="75" align="center">
              <template slot-scope="{ row }">{{ sourceText(row.capability_source) }}</template>
            </el-table-column>
            <el-table-column label="状态" width="70" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="row.relation_status === 'active' ? 'success' : 'info'">{{ row.relation_status === 'active' ? '有效' : '失效' }}</el-tag>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="60" align="center">
              <template slot-scope="{ row }">
                <el-button v-if="row.relation_status === 'active'" type="text" size="mini" class="danger-link" @click="disableRelation(row)">停用</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div v-if="relationPagination.total > relationPagination.perPage" class="drawer-mini-pager">
            <el-pagination
              small
              layout="prev, pager, next"
              :current-page="relationPagination.page"
              :page-size="relationPagination.perPage"
              :total="relationPagination.total"
              @current-change="loadRelations"
            />
          </div>
        </section>

        <section class="drawer-card">
          <div class="card-head">
            <h3><i class="el-icon-price-tag" /> 供应商阶梯报价（{{ quotePagination.total }}）</h3>
            <el-button size="mini" type="success" plain class="btn-mini-add" icon="el-icon-plus" @click="openQuote">新增报价</el-button>
          </div>
          <el-table :data="quotations" size="mini" border empty-text="暂无有效报价">
            <el-table-column prop="item.item_code" label="物料编码" min-width="110" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="font-tabular chip-mini">{{ (row.item && row.item.item_code) || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="报价" width="95" align="right">
              <template slot-scope="{ row }">
                <span class="font-tabular font-bold">{{ row.currency }} {{ money(row.price) }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="lead_time_days" label="交期" width="60" align="center">
              <template slot-scope="{ row }">
                <span class="font-tabular">{{ row.lead_time_days }}天</span>
              </template>
            </el-table-column>
            <el-table-column label="有效期" min-width="95">
              <template slot-scope="{ row }">
                <span class="font-tabular text-muted">{{ formatDateOnly(row.valid_until) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="60" align="center">
              <template slot-scope="{ row }">
                <el-button type="text" size="mini" class="danger-link" @click="disableQuote(row)">停用</el-button>
              </template>
            </el-table-column>
          </el-table>
          <div v-if="quotePagination.total > quotePagination.perPage" class="drawer-mini-pager">
            <el-pagination
              small
              layout="prev, pager, next"
              :current-page="quotePagination.page"
              :page-size="quotePagination.perPage"
              :total="quotePagination.total"
              @current-change="loadQuotations"
            />
          </div>
        </section>

        <section class="drawer-card">
          <div class="card-head">
            <h3><i class="el-icon-notebook-2" /> 最近采购履历（{{ purchasePagination.total }}）</h3>
          </div>
          <el-table :data="purchaseHistory" size="mini" border empty-text="暂无采购履历">
            <el-table-column prop="item.item_code" label="物料编码" min-width="110" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="font-tabular chip-mini">{{ (row.item && row.item.item_code) || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="采购单价" width="95" align="right">
              <template slot-scope="{ row }">
                <span class="font-tabular font-bold">{{ row.currency }} {{ money(row.price) }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="effective_date" label="采购日期" min-width="95">
              <template slot-scope="{ row }">
                <span class="font-tabular text-muted">{{ row.effective_date }}</span>
              </template>
            </el-table-column>
          </el-table>
          <div v-if="purchasePagination.total > purchasePagination.perPage" class="drawer-mini-pager">
            <el-pagination
              small
              layout="prev, pager, next"
              :current-page="purchasePagination.page"
              :page-size="purchasePagination.perPage"
              :total="purchasePagination.total"
              @current-change="loadPurchaseHistory"
            />
          </div>
        </section>
      </div>

      <!-- 抽屉底部快捷操作 -->
      <div class="drawer-footer">
        <el-button size="small" class="btn-drawer-close" @click="closeDrawer">关闭</el-button>
        <el-button size="small" icon="el-icon-edit" class="btn-drawer-edit" @click="openEdit(selectedSupplier)">编辑资料</el-button>
        <el-button size="small" icon="el-icon-plus" class="btn-drawer-relation" @click="openRelation">新增物料关系</el-button>
        <el-button size="small" type="success" icon="el-icon-price-tag" class="btn-theme-create" @click="openQuote">新增报价</el-button>
      </div>
    </aside>

    <!-- 弹窗 1：新增 / 编辑供应商主档案（标签页分类紧凑排版，无须滚动） -->
    <el-dialog
      :visible.sync="supplierDialogVisible"
      :title="supplierDialogTitle"
      width="880px"
      top="8vh"
      custom-class="erp-supplier-dialog"
      :close-on-click-modal="false"
      @close="closeSupplierDialog"
    >
      <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small" class="dialog-supplier-form">
        <el-tabs v-model="activeTab" class="supplier-tabs">
          <!-- 标签 1：基础与商务信息 -->
          <el-tab-pane name="basic">
            <span slot="label"><i class="el-icon-office-building" /> 基础与商务信息</span>
            <div class="form-grid-3">
              <!-- 第 1 行：编码 (1列), 全称 (2列) -->
              <el-form-item label="供应商编码" prop="supplier_code">
                <el-input v-model="form.supplier_code" disabled class="font-tabular">
                  <template slot="append">{{ supplierDialogMode === 'create' ? '系统预生成' : '锁定' }}</template>
                </el-input>
              </el-form-item>
              <el-form-item label="供应商全称" prop="supplier_name" class="col-span-2">
                <el-input v-model.trim="form.supplier_name" placeholder="请输入企业工商注册全称" maxlength="150" clearable />
              </el-form-item>

              <!-- 第 2 行：简称 (1列), 类型 (1列), 档案状态 (1列) -->
              <el-form-item label="企业简称">
                <el-input v-model.trim="form.short_name" placeholder="选填，如 阿里、华为" clearable />
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

              <!-- 第 3 行：联系人, 手机号, 电子邮箱 -->
              <el-form-item label="业务联系人">
                <el-input v-model.trim="form.contact_name" placeholder="主要业务联系人" clearable />
              </el-form-item>
              <el-form-item label="联系人手机">
                <el-input v-model.trim="form.contact_phone" placeholder="常用手机号" class="font-tabular" clearable />
              </el-form-item>
              <el-form-item label="电子邮箱">
                <el-input v-model.trim="form.email" placeholder="业务通知接收邮箱" clearable />
              </el-form-item>

              <!-- 第 4 行：结算方式, 付款方式, 固定电话 -->
              <el-form-item label="结算方式">
                <el-input v-model.trim="form.settlement_method" placeholder="如 月结30天、现结" clearable />
              </el-form-item>
              <el-form-item label="付款方式">
                <el-input v-model.trim="form.payment_method" placeholder="如 银行转账、承兑" clearable />
              </el-form-item>
              <el-form-item label="固定电话">
                <el-input v-model.trim="form.phone" placeholder="座机号码" class="font-tabular" clearable />
              </el-form-item>

              <!-- 第 5 行：开户行 (1.5列), 银行账号 (1.5列) -->
              <el-form-item label="开户银行" class="col-span-1-5">
                <el-input v-model.trim="form.bank_name" placeholder="如 中国工商银行某某支行" clearable />
              </el-form-item>
              <el-form-item label="银行账号" class="col-span-1-5">
                <el-input v-model.trim="form.bank_account" placeholder="对公银行结算账号" class="font-tabular" clearable />
              </el-form-item>
            </div>
          </el-tab-pane>

          <!-- 标签 2：资质准入与供货品类 -->
          <el-tab-pane name="qualification">
            <span slot="label"><i class="el-icon-folder-opened" /> 资质准入与供货品类</span>
            <div class="tab-qualification-grid">
              <!-- 左侧：审批状态、合作状态、风险管控与备注 -->
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

              <!-- 右侧：可供物料品类树 -->
              <div class="qual-col-right">
                <el-form-item label="可供物料品类（采购寻源候选范围）">
                  <item-category-tree-picker v-model="selectedCategoryIds" :tree="categoryTree" multiple />
                  <div class="category-scope-tip">
                    <i class="el-icon-info" />
                    <span>品类用于采购寻源候选筛选；具体供货以物料 Item 供货关系与阶梯报价为准。</span>
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
          <el-button size="small" type="success" :loading="saving" class="btn-theme-create" icon="el-icon-check" @click="saveSupplier">
            保存供应商档案
          </el-button>
        </div>
      </div>
    </el-dialog>

    <!-- 弹窗 2：新增 Item 供货关系 -->
    <el-dialog
      :visible.sync="relationDialogVisible"
      title="新增 Item 供货关系"
      width="580px"
      custom-class="erp-supplier-dialog"
      :close-on-click-modal="false"
    >
      <el-form ref="relationForm" :model="relationForm" :rules="relationRules" label-position="top" size="small">
        <el-form-item label="具体物料 Item" prop="item_id">
          <el-select v-model="relationForm.item_id" v-paged-scroll="loadMoreSupplierItems" filterable remote :remote-method="searchSupplierItems" :loading="itemPage.loading" class="full" placeholder="请选择已启用采购物料">
            <el-option v-for="item in items" :key="item.id" :label="`${item.item_code} / ${item.item_name}`" :value="item.id" />
          </el-select>
        </el-form-item>
        <el-form-item label="能力来源" prop="capability_source">
          <el-select v-model="relationForm.capability_source" class="full">
            <el-option label="人工确认" value="manual_confirmed" />
            <el-option label="采购历史" value="purchase_history" />
          </el-select>
        </el-form-item>
        <el-form-item label="是否作为该物料默认供应商">
          <div class="switch-box">
            <el-switch v-model="relationForm.is_default" active-color="#008b4b" />
            <span class="text-xs text-muted">{{ relationForm.is_default ? '采购寻源默认优选该供应商' : '非默认供应商' }}</span>
          </div>
        </el-form-item>
        <el-form-item label="生效日期">
          <el-date-picker v-model="relationForm.effective_at" value-format="yyyy-MM-dd" class="full" placeholder="请选择生效日期" />
        </el-form-item>
        <el-form-item label="变更原因" prop="change_reason">
          <el-input v-model="relationForm.change_reason" placeholder="如 人工确认供货资质" />
        </el-form-item>
        <el-form-item label="备注说明">
          <el-input v-model="relationForm.remark" type="textarea" :rows="3" placeholder="补充说明供货能力细节或前置交期要求..." />
        </el-form-item>
      </el-form>
      <div slot="footer" class="dialog-footer">
        <el-button size="small" class="btn-dialog-cancel" @click="relationDialogVisible = false">取消</el-button>
        <el-button size="small" type="success" :loading="saving" class="btn-theme-create" icon="el-icon-check" @click="saveRelation">
          保存供货关系
        </el-button>
      </div>
    </el-dialog>

    <!-- 弹窗 3：维护供应商阶梯报价 -->
    <el-dialog
      :visible.sync="quoteDialogVisible"
      title="维护供应商阶梯报价"
      width="680px"
      custom-class="erp-supplier-dialog"
      :close-on-click-modal="false"
    >
      <el-form ref="quoteForm" :model="quoteForm" :rules="quoteRules" label-position="top" size="small">
        <div class="form-grid-2">
          <el-form-item label="具体物料 Item" prop="item_id">
            <el-select v-model="quoteForm.item_id" v-paged-scroll="loadMoreSupplierItems" filterable remote :remote-method="searchSupplierItems" :loading="itemPage.loading" class="full" placeholder="请选择物料 Item" @change="loadQuoteConversions">
              <el-option v-for="item in items" :key="item.id" :label="`${item.item_code} / ${item.item_name}`" :value="item.id" />
            </el-select>
          </el-form-item>
          <el-form-item label="采购单位" prop="unit_id">
            <el-select v-model="quoteForm.unit_id" class="full" placeholder="请选择采购单位">
              <el-option
                v-for="relation in quoteConversions"
                :key="relation.id"
                :label="relation.purchase_unit && `${relation.purchase_unit.unit_name} (${relation.purchase_unit.unit_code})`"
                :value="relation.purchase_unit_id"
              />
            </el-select>
          </el-form-item>
        </div>

        <div class="conversion-box">
          <div class="conversion-head">
            <b>单位换算关系</b>
            <span class="text-xs text-muted">自动读取 Item 采购单位换算</span>
          </div>
          <p class="conversion-p">Item默认：1 {{ quotePurchaseUnit }} = {{ quoteStandardFactor }} {{ quoteBaseUnit }}</p>
          <div class="quote-formula font-tabular">{{ quoteFormulaText }}</div>
          <div class="factor-result">
            <span>基本单位：<strong>{{ quoteBaseUnit }}</strong></span>
            <span>基准单价：<strong class="font-tabular">{{ quoteBaseUnitPrice === '-' ? '-' : `${quoteBaseUnitPrice} ${quoteForm.currency}/${quoteBaseUnit}` }}</strong></span>
          </div>
        </div>

        <div class="form-grid-2">
          <el-form-item label="报价价格" prop="price">
            <el-input v-model="quoteForm.price" type="number" min="0.0001" step="0.0001" class="full font-tabular" placeholder="单价" />
          </el-form-item>
          <el-form-item label="币种">
            <el-input v-model="quoteForm.currency" class="full font-tabular" placeholder="如 CNY" />
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
          <el-form-item label="供货交期(天)">
            <el-input-number v-model="quoteForm.lead_time_days" :min="0" class="full" />
          </el-form-item>
          <el-form-item label="最小订购量 (MOQ)">
            <el-input-number v-model="quoteForm.min_order_qty" :min="0" :precision="4" class="full" />
          </el-form-item>
        </div>
        <el-form-item label="有效期" required>
          <el-date-picker v-model="quoteDates" type="daterange" value-format="yyyy-MM-dd" start-placeholder="开始日期" end-placeholder="结束日期" class="full" />
        </el-form-item>
        <el-form-item label="变更原因">
          <el-input v-model="quoteForm.change_reason" placeholder="如 供应商季度协议价格调整" />
        </el-form-item>
        <el-form-item label="备注说明">
          <el-input v-model="quoteForm.remark" type="textarea" :rows="3" placeholder="补充说明报价特殊约束或阶梯条件..." />
        </el-form-item>
      </el-form>
      <div slot="footer" class="dialog-footer">
        <el-button size="small" class="btn-dialog-cancel" @click="quoteDialogVisible = false">取消</el-button>
        <el-button size="small" type="success" :loading="saving" class="btn-theme-create" icon="el-icon-check" @click="saveQuote">
          保存报价
        </el-button>
      </div>
    </el-dialog>
  </section>
</template>

<script>
import pagedScroll from '../../../directives/pagedScroll'
import { createPageState, queryPage, includeSelected } from '../../../utils/pagedQuery'
import {
  listEntity, saveEntity, disableEntity, enableEntity, deleteEntity, getItemCategoryTree,
  getSupplierCapabilities, listSupplierItemRelations, saveSupplierItemRelation, disableSupplierItemRelation,
  listSupplierQuotations, saveSupplierQuotation, disableSupplierQuotation, listSupplierPurchaseHistory,
  listItemPurchaseConversionOptions
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
  item_id: null,
  capability_source: 'manual_confirmed',
  is_default: false,
  effective_at: '',
  change_reason: '人工确认供货能力',
  remark: ''
})

const emptyQuote = () => ({
  item_id: null,
  unit_id: null,
  price: null,
  currency: 'CNY',
  tax_mode: 'tax_included',
  tax_rate: 13,
  lead_time_days: 0,
  min_order_qty: 1,
  max_order_qty: null,
  change_reason: '供应商报价更新',
  remark: ''
})

export default {
  directives: { pagedScroll },
  name: 'SupplierList',
  components: { ItemCategoryTreePicker },
  data () {
    return {
      compact: typeof window !== 'undefined' ? window.innerWidth <= 1400 : false,
      loading: false,
      detailLoading: false,
      saving: false,
      drawerVisible: false,
      supplierDialogVisible: false,
      supplierDialogMode: 'create',
      activeTab: 'basic',
      relationDialogVisible: false,
      quoteDialogVisible: false,
      suppliers: [],
      items: [],
      itemPage: createPageState(50),
      itemKeyword: '',
      selectedItems: [],
      units: [],
      categories: [],
      selectedSupplier: {},
      categoryCapabilities: [],
      relations: [],
      quotations: [],
      purchaseHistory: [],
      reservation: null,
      quoteConversions: [],
      categoryKeyword: '',
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
      pagination: { page: 1, perPage: 20, total: 0 },
      relationPagination: { page: 1, perPage: 5, total: 0 },
      quotePagination: { page: 1, perPage: 5, total: 0 },
      purchasePagination: { page: 1, perPage: 5, total: 0 },
      rules: {
        supplier_code: [{ required: true, message: '系统未取得供应商编码，请重新打开新增窗口', trigger: 'blur' }],
        supplier_name: [{ required: true, message: '请输入供应商名称', trigger: 'blur' }]
      },
      relationRules: {
        item_id: [{ required: true, message: '请选择具体 Item', trigger: 'change' }],
        capability_source: [{ required: true, message: '请选择能力来源', trigger: 'change' }],
        change_reason: [{ required: true, message: '请输入变更原因', trigger: 'blur' }]
      },
      quoteRules: {
        item_id: [{ required: true, message: '请选择具体 Item', trigger: 'change' }],
        unit_id: [{ required: true, message: '请选择采购单位', trigger: 'change' }],
        price: [{ required: true, message: '请输入大于 0 的有效报价', trigger: 'blur' }]
      }
    }
  },
  computed: {
    supplierDialogTitle () {
      return this.supplierDialogMode === 'create'
        ? '新增供应商主档案'
        : `编辑供应商主档案 - ${this.form.supplier_name || this.form.supplier_code}`
    },
    drawerTitle () {
      return this.selectedSupplier.supplier_name || '供应商全景详情'
    },
    selectedCategoryRows () {
      const selected = new Set(this.selectedCategoryIds.map(Number))
      return this.categories.filter(category => selected.has(Number(category.id)))
    },
    quoteConversion () {
      return this.quoteConversions.find(row => Number(row.purchase_unit_id) === Number(this.quoteForm.unit_id))
    },
    quoteStandardFactor () {
      return Number((this.quoteConversion && this.quoteConversion.factor) || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 })
    },
    quoteFinalFactor () {
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
        ? (Number(this.quoteForm.price) / this.quoteFinalFactor).toLocaleString('zh-CN', { minimumFractionDigits: 4, maximumFractionDigits: 8 })
        : '-'
    },
    quoteFormulaText () {
      if (!this.quoteConversion || this.quoteFinalFactor <= 0 || Number(this.quoteForm.price || 0) <= 0) {
        return '请先选择 Item、采购单位并填写有效报价'
      }
      const price = Number(this.quoteForm.price).toLocaleString('zh-CN', { maximumFractionDigits: 4 })
      const factor = Number(this.quoteFinalFactor).toLocaleString('zh-CN', { maximumFractionDigits: 8 })
      const tax = this.quoteForm.tax_mode === 'tax_included' ? `含税 ${Number(this.quoteForm.tax_rate || 0)}%` : '未税'
      return `${price} ${this.quoteForm.currency}/${this.quotePurchaseUnit} ÷ ${factor} ${this.quoteBaseUnit}/${this.quotePurchaseUnit} = ${this.quoteBaseUnitPrice} ${this.quoteForm.currency}/${this.quoteBaseUnit}（${tax}）`
    },
    isActionFixed () {
      return !this.compact && !this.drawerVisible
    }
  },
  watch: {
    drawerVisible () {
      this.$nextTick(() => {
        if (this.$refs.table) {
          this.$refs.table.doLayout()
        }
      })
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
        if (this.$refs.table) {
          this.$refs.table.doLayout()
        }
      })
    }
    window.addEventListener('resize', this.handleResize)
  },
  beforeDestroy () {
    if (this.handleResize) {
      window.removeEventListener('resize', this.handleResize)
    }
  },
  methods: {
    closeDrawer () {
      this.drawerVisible = false
    },
    clearCategoryFilter () {
      this.filters.category_id = ''
      this.query()
    },
    async fetchOptions () {
      try {
        const [units, categories] = await Promise.all([
          listEntity('units', { status: 'enabled', per_page: 100 }),
          getItemCategoryTree()
        ])
        this.units = units.data.data || []
        this.categoryTree = categories.data.data || []
        this.categories = this.flattenCategories(this.categoryTree)
      } catch (e) {
        this.$message.error(e.userMessage || '供应商基础选项加载失败')
      }
    },
    async searchSupplierItems (keyword = '', append = false) {
      if (!append) {
        this.itemKeyword = keyword
        const ids = [this.relationForm.item_id, this.quoteForm.item_id].map(Number)
        this.selectedItems = includeSelected(this.items, this.selectedItems).filter(row => ids.includes(Number(row.id)))
        this.items = this.selectedItems
      }
      const state = this.itemPage
      try {
        const data = await queryPage(state, params => listEntity('items', params), {
          status: 'enabled', is_purchase_item: 1, keyword: this.itemKeyword
        }, append)
        if (data && state === this.itemPage) this.items = includeSelected(state.rows, this.selectedItems)
      } catch (e) { this.$message.error(e.userMessage || '采购物料加载失败') }
    },
    loadMoreSupplierItems () {
      if (!this.relationDialogVisible && !this.quoteDialogVisible) return
      return this.searchSupplierItems(this.itemKeyword, true)
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
      this.drawerVisible = true
      await this.loadSupplierDetail()
    },
    async loadSupplierDetail () {
      if (!this.selectedSupplier.id) return
      this.detailLoading = true
      try {
        const [capabilities, relations, quotations, history] = await Promise.all([
          getSupplierCapabilities(this.selectedSupplier.id),
          listSupplierItemRelations(this.selectedSupplier.id, { page: 1, per_page: this.relationPagination.perPage }),
          listSupplierQuotations(this.selectedSupplier.id, { page: 1, per_page: this.quotePagination.perPage }),
          listSupplierPurchaseHistory(this.selectedSupplier.id, { page: 1, per_page: this.purchasePagination.perPage })
        ])
        this.selectedSupplier = capabilities.data.data
        this.categoryCapabilities = this.selectedSupplier.category_capabilities || []
        this.applyPage(relations.data, this.relations, 'relations', this.relationPagination)
        this.applyPage(quotations.data, this.quotations, 'quotations', this.quotePagination)
        this.applyPage(history.data, this.purchaseHistory, 'purchaseHistory', this.purchasePagination)
      } catch (e) {
        this.$message.error(e.userMessage || '供应商能力信息加载失败')
      } finally {
        this.detailLoading = false
      }
    },
    applyPage (payload, current, property, pagination) {
      this[property] = payload.data || current
      pagination.page = payload.current_page || 1
      pagination.total = payload.total || 0
    },
    async loadRelations (page) {
      const response = await listSupplierItemRelations(this.selectedSupplier.id, { page, per_page: this.relationPagination.perPage })
      this.applyPage(response.data, this.relations, 'relations', this.relationPagination)
    },
    async loadQuotations (page) {
      const response = await listSupplierQuotations(this.selectedSupplier.id, { page, per_page: this.quotePagination.perPage })
      this.applyPage(response.data, this.quotations, 'quotations', this.quotePagination)
    },
    async loadPurchaseHistory (page) {
      const response = await listSupplierPurchaseHistory(this.selectedSupplier.id, { page, per_page: this.purchasePagination.perPage })
      this.applyPage(response.data, this.purchaseHistory, 'purchaseHistory', this.purchasePagination)
    },
    async openCreate () {
      this.form = emptyForm()
      this.selectedCategoryIds = []
      this.activeTab = 'basic'
      this.supplierDialogMode = 'create'
      this.supplierDialogVisible = true
      try {
        this.reservation = await reserveForCreatePage('supplier', '/master/suppliers/create')
        this.form.supplier_code = this.reservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || '供应商编码预生成失败')
      }
      this.$nextTick(() => { if (this.$refs.form) this.$refs.form.clearValidate() })
    },
    async openEdit (row) {
      this.selectedSupplier = { ...row }
      if (!row.category_capabilities) await this.loadSupplierDetail()
      this.form = { ...emptyForm(), ...this.selectedSupplier }
      const ids = (this.selectedSupplier.category_capabilities || []).map(scope => scope.item_category_id)
      this.selectedCategoryIds = ids.map(Number)
      this.activeTab = 'basic'
      this.supplierDialogMode = 'edit'
      this.supplierDialogVisible = true
      this.$nextTick(() => { if (this.$refs.form) this.$refs.form.clearValidate() })
    },
    closeSupplierDialog () {
      if (this.supplierDialogMode === 'create' && this.reservation) {
        clearCreatePageReservation(this.reservation)
        this.reservation = null
      }
      this.supplierDialogVisible = false
    },
    saveSupplier () {
      this.$refs.form.validate(async valid => {
        if (!valid) {
          if (!this.form.supplier_name) {
            this.activeTab = 'basic'
          }
          return
        }
        this.saving = true
        try {
          const payload = { ...this.form, category_ids: Array.from(new Set(this.selectedCategoryIds.map(Number))) }
          if (this.supplierDialogMode === 'create' && this.reservation) {
            payload.reservation_token = this.reservation.reservation_token
            payload.creation_session_id = this.reservation.creation_session_id
          }
          const response = await saveEntity('suppliers', payload)
          if (this.supplierDialogMode === 'create') clearCreatePageReservation(this.reservation)
          this.reservation = null
          this.$message.success('供应商档案已保存')
          this.supplierDialogVisible = false
          await this.fetchAll()
          if (this.drawerVisible && this.selectedSupplier.id === response.data.data.id) {
            await this.loadSupplierDetail()
          }
        } catch (e) {
          this.$message.error(e.userMessage || '供应商保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    openRelation () {
      this.itemPage = createPageState(50)
      this.items = []; this.selectedItems = []; this.itemKeyword = ''
      this.relationForm = emptyRelation()
      this.relationDialogVisible = true
      this.searchSupplierItems('')
      this.$nextTick(() => { if (this.$refs.relationForm) this.$refs.relationForm.clearValidate() })
    },
    saveRelation () {
      this.$refs.relationForm.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          await saveSupplierItemRelation(this.selectedSupplier.id, { ...this.relationForm, relation_status: 'active' })
          this.$message.success('具体 Item 供货关系已保存')
          this.relationDialogVisible = false
          await this.loadRelations(1)
        } catch (e) {
          this.$message.error(e.userMessage || 'Item 供货关系保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    openQuote () {
      this.itemPage = createPageState(50)
      this.items = []; this.selectedItems = []; this.itemKeyword = ''
      this.quoteForm = emptyQuote()
      this.quoteConversions = []
      this.quoteDates = []
      this.quoteDialogVisible = true
      this.searchSupplierItems('')
      this.$nextTick(() => { if (this.$refs.quoteForm) this.$refs.quoteForm.clearValidate() })
    },
    async loadQuoteConversions () {
      this.quoteConversions = []
      this.quoteForm.unit_id = null
      if (!this.quoteForm.item_id) return
      const { data } = await listItemPurchaseConversionOptions(this.quoteForm.item_id, { page: 1, per_page: 100 })
      this.quoteConversions = data.data || []
      const selected = this.quoteConversions.find(row => row.is_default) || this.quoteConversions[0]
      if (selected) this.quoteForm.unit_id = selected.purchase_unit_id
    },
    saveQuote () {
      this.$refs.quoteForm.validate(async valid => {
        if (!valid) return
        if (!this.quoteDates || this.quoteDates.length !== 2) return this.$message.warning('请选择报价有效期')
        this.saving = true
        try {
          await saveSupplierQuotation(this.selectedSupplier.id, {
            ...this.quoteForm,
            max_order_qty: Number(this.quoteForm.max_order_qty) > 0 ? this.quoteForm.max_order_qty : null,
            valid_from: this.quoteDates[0],
            valid_until: this.quoteDates[1]
          })
          this.$message.success('供应商报价已保存')
          this.quoteDialogVisible = false
          await this.loadQuotations(1)
        } catch (e) {
          this.$message.error(e.userMessage || '供应商报价保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    async disableRelation (row) {
      try {
        const result = await this.$prompt('停用后不会删除历史。请输入停用原因：', '停用 Item 关系', {
          inputPattern: /\S+/,
          inputErrorMessage: '必须填写原因'
        })
        await disableSupplierItemRelation(this.selectedSupplier.id, row.id, { reason: result.value })
        this.$message.success('Item 关系已停用')
        await this.loadSupplierDetail()
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '停用失败')
      }
    },
    async disableQuote (row) {
      try {
        const result = await this.$prompt('请输入报价停用原因，系统会保留报价历史：', '停用报价', {
          inputPattern: /\S+/,
          inputErrorMessage: '必须填写原因'
        })
        await disableSupplierQuotation(this.selectedSupplier.id, row.id, { reason: result.value })
        this.$message.success('报价已停用')
        await this.loadSupplierDetail()
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
          this.drawerVisible = false
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
      return ({ manual_confirmed: '人工', quotation: '报价', purchase_history: '历史' })[v] || v
    },
    maskPhone (v) {
      return v ? String(v).replace(/(\d{3})\d+(\d{4})/, '$1****$2') : '-'
    },
    formatDate (v) {
      return v ? String(v).replace('T', ' ').slice(0, 16) : '-'
    },
    formatDateOnly (v) {
      return v ? String(v).slice(0, 10) : '长期'
    },
    money (v) {
      return Number(v || 0).toFixed(2)
    },
    rowClass ({ row }) {
      return row.id === this.selectedSupplier.id ? 'selected-row' : ''
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
  transition: padding-right 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}

.sup-page.drawer-open .sup-workspace {
  padding-right: 480px;
}

/* 页面头部 */
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
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.total-tag,
.filter-category-tag {
  border-radius: 4px;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-import {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-import:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
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

/* 表格与筛选卡片容器 */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}

.filter-toolbar {
  padding: 14px 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 12px;
  background: #fafbfc;
  border-bottom: 1px solid #e2e8f0;
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
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
}

.filter-item .el-input {
  width: 200px;
}

.filter-item .el-select {
  width: 130px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  margin-left: auto;
}

.btn-theme-search {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

.btn-theme-search:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.btn-theme-reset {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 表格主体 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
}

.enterprise-table ::v-deep th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
  font-size: 12px;
  padding: 10px 0;
}

.enterprise-table ::v-deep td {
  padding: 9px 0;
  font-size: 13px;
  color: #1e293b;
}

/* 统一采用清晰自然 UI 字体，绝不用模糊等宽字体 */
.font-tabular,
.sup-code-link,
.supplier-name-text {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
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
  box-shadow: 0 1px 4px rgba(0, 118, 63, 0.12);
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
  line-height: 1.4;
  transition: color 0.15s;
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

.text-phone {
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

.type-tag,
.eligible-tag,
.status-tag {
  border-radius: 4px;
  font-size: 11px;
}

.date-text {
  font-size: 12px;
}

.text-muted {
  color: #94a3b8;
}

.text-xs {
  font-size: 11px;
}

.fw-bold {
  font-weight: 600;
}

/* 操作列排版：宽敞、无拥挤感、绝对无蓝色 */
.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
  padding: 0 4px;
}

.row-actions .el-button--text {
  padding: 4px 6px !important;
  font-size: 13px !important;
  font-weight: 500;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  margin-left: 0 !important;
}

.btn-action-detail {
  color: #008b4b !important;
}

.btn-action-detail:hover {
  color: #00763f !important;
}

.btn-action-edit {
  color: #0d9488 !important;
}

.btn-action-edit:hover {
  color: #0f766e !important;
}

.btn-action-disable {
  color: #d97706 !important;
}

.btn-action-disable:hover {
  color: #b45309 !important;
}

.btn-action-enable {
  color: #008b4b !important;
}

.btn-action-enable:hover {
  color: #00763f !important;
}

.btn-action-delete {
  color: #ef4444 !important;
}

.btn-action-delete:hover {
  color: #dc2626 !important;
}

.enterprise-table ::v-deep .selected-row td {
  background: #f0fdf4 !important;
}

.enterprise-table ::v-deep .selected-row td:first-child {
  box-shadow: inset 4px 0 0 #008b4b;
}

/* 分页 */
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

.total-info {
  font-size: 13px;
  color: #64748b;
}

.total-info strong {
  color: #0f172a;
}

/* 侧边详情抽屉 */
.sup-drawer {
  position: fixed;
  top: 52px;
  right: 0;
  bottom: 0;
  width: 460px;
  background: #ffffff;
  border-left: 1px solid #e2e8f0;
  z-index: 99;
  box-shadow: -8px 0 24px rgba(15, 23, 42, 0.08);
  display: flex;
  flex-direction: column;
}

.drawer-head {
  height: 56px;
  padding: 0 16px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #e2e8f0;
  background: #ffffff;
  flex-shrink: 0;
}

.drawer-head-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.drawer-head-title h2 {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.drawer-code-badge {
  padding: 1px 6px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  font-size: 11px;
  color: #64748b;
}

.drawer-close-btn {
  width: 32px;
  height: 32px;
  display: flex;
  align-items: center;
  justify-content: center;
  border: 0;
  background: transparent;
  color: #64748b;
  border-radius: 6px;
  cursor: pointer;
  font-size: 16px;
  transition: all 0.15s;
}

.drawer-close-btn:hover {
  background: #f1f5f9;
  color: #0f172a;
}

.drawer-body {
  flex: 1;
  padding: 14px 16px 80px;
  overflow-y: auto;
}

.drawer-card {
  margin-bottom: 12px;
  padding: 14px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}

.card-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.card-head h3 {
  margin: 0;
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}

.card-head h3 i {
  color: #008b4b;
}

.btn-card-action {
  padding: 0 !important;
  color: #008b4b !important;
}

.btn-card-action:hover {
  color: #00763f !important;
}

.btn-mini-add {
  padding: 4px 8px !important;
  font-size: 11px !important;
  border-color: #86efac !important;
  color: #166534 !important;
  background: #f0fdf4 !important;
}

.info-grid {
  display: grid;
  grid-template-columns: 85px minmax(0, 1fr);
  gap: 8px 12px;
  margin: 0;
  font-size: 13px;
}

.info-grid dt {
  color: #64748b;
}

.info-grid dd {
  margin: 0;
  color: #0f172a;
  word-break: break-all;
}

.tag-list {
  display: flex;
  gap: 6px;
  flex-wrap: wrap;
}

.chip-mini {
  display: inline-block;
  padding: 0 4px;
  background: #f1f5f9;
  border-radius: 3px;
  font-size: 11px;
}

.danger-link {
  color: #ef4444 !important;
}

.drawer-mini-pager {
  margin-top: 8px;
  display: flex;
  justify-content: flex-end;
}

.drawer-footer {
  position: absolute;
  left: 0;
  right: 0;
  bottom: 0;
  height: 60px;
  padding: 12px 16px;
  display: flex;
  gap: 8px;
  background: #ffffff;
  border-top: 1px solid #e2e8f0;
  z-index: 10;
}

.drawer-footer .el-button {
  flex: 1;
  padding: 8px 4px !important;
  font-size: 12px !important;
}

.btn-drawer-close {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
  flex: 0.7 !important;
}

.btn-drawer-edit,
.btn-drawer-relation {
  border-color: #cbd5e1 !important;
  color: #334155 !important;
}

.btn-drawer-edit:hover,
.btn-drawer-relation:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 模态弹窗系统 */
.erp-supplier-dialog ::v-deep .el-dialog {
  border-radius: 8px;
  overflow: hidden;
  box-shadow: 0 12px 32px rgba(15, 23, 42, 0.12);
}

.erp-supplier-dialog ::v-deep .el-dialog__header {
  padding: 14px 20px;
  border-bottom: 1px solid #e2e8f0;
  background: #ffffff;
}

.erp-supplier-dialog ::v-deep .el-dialog__title {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.erp-supplier-dialog ::v-deep .el-dialog__body {
  padding: 12px 20px 8px;
  background: #ffffff;
  overflow: visible;
}

.erp-supplier-dialog ::v-deep .el-dialog__footer {
  padding: 12px 20px;
  border-top: 1px solid #e2e8f0;
  background: #f8fafc;
}

.dialog-footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.footer-left {
  display: flex;
  align-items: center;
}

.btn-step-switch {
  border-color: #cbd5e1 !important;
  color: #008b4b !important;
  background: #f0fdf4 !important;
}

.btn-step-switch:hover {
  border-color: #008b4b !important;
  background: #dcfce7 !important;
}

.footer-right {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* 标签页高质感企业绿定制（绝对无蓝色） */
.supplier-tabs ::v-deep .el-tabs__header {
  margin: 0 0 14px;
}

.supplier-tabs ::v-deep .el-tabs__nav-wrap::after {
  height: 1px;
  background-color: #e2e8f0;
}

.supplier-tabs ::v-deep .el-tabs__item {
  height: 38px;
  line-height: 38px;
  font-size: 13px;
  font-weight: 500;
  color: #64748b;
  padding: 0 16px;
  transition: all 0.15s ease;
}

.supplier-tabs ::v-deep .el-tabs__item.is-active {
  color: #008b4b !important;
  font-weight: 600;
}

.supplier-tabs ::v-deep .el-tabs__item:hover {
  color: #008b4b;
}

.supplier-tabs ::v-deep .el-tabs__active-bar {
  background-color: #008b4b !important;
  height: 2px;
}

/* Tab 1: 3 列紧凑栅格 */
.form-grid-3 {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 0 16px;
}

.col-span-2 {
  grid-column: span 2;
}

.col-span-1-5 {
  grid-column: span 1;
}

.form-grid-3 .el-form-item,
.tab-qualification-grid .el-form-item {
  margin-bottom: 12px;
}

.form-grid-3 ::v-deep .el-form-item__label,
.tab-qualification-grid ::v-deep .el-form-item__label {
  padding-bottom: 2px;
  font-size: 12px;
  font-weight: 500;
  color: #475569;
  line-height: 1.4;
}

.status-radio-compact {
  height: 32px;
  display: flex;
  align-items: center;
  gap: 14px;
}

/* Tab 2: 左右两栏对齐（左资质管控，右品类树） */
.tab-qualification-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 20px;
}

.qual-col-left,
.qual-col-right {
  display: flex;
  flex-direction: column;
}

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

.switch-item {
  display: flex;
  align-items: center;
  gap: 8px;
}

.switch-label {
  font-size: 12px;
  color: #334155;
  font-weight: 500;
}

.mt-8 {
  margin-top: 4px;
}

.form-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 0 16px;
}

.full {
  width: 100%;
}

.switch-box {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 32px;
}

.danger-tip-text {
  font-size: 11px;
  color: #ef4444;
}

.warn-tip-text {
  font-size: 11px;
  color: #d97706;
}

.text-success {
  color: #008b4b !important;
}

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

.category-scope-tip i {
  color: #008b4b;
  font-size: 14px;
}

.conversion-box {
  margin: 0 0 14px;
  padding: 12px;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  background: #f0fdf4;
}

.conversion-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.conversion-p {
  margin: 6px 0;
  color: #166534;
  font-size: 12px;
}

.quote-formula {
  margin: 8px 0;
  padding: 8px 10px;
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
  padding-top: 8px;
  border-top: 1px dashed #bbf7d0;
  color: #166534;
  font-size: 12px;
}

.btn-dialog-cancel {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-dialog-cancel:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

/* 响应式断点适配 */
@media (max-width: 1200px) {
  .sup-page.drawer-open .sup-workspace {
    padding-right: 20px;
  }

  .sup-drawer {
    width: 440px;
    max-width: 90vw;
  }
}

@media (max-width: 768px) {
  .sup-workspace {
    padding: 12px;
  }

  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }

  .head-actions {
    width: 100%;
    justify-content: flex-end;
  }

  .filter-toolbar {
    flex-direction: column;
    align-items: stretch;
  }

  .filter-fields {
    flex-direction: column;
    align-items: stretch;
  }

  .filter-item {
    width: 100%;
  }

  .filter-item .el-input,
  .filter-item .el-select {
    width: 100%;
  }

  .filter-actions {
    margin-left: 0;
    justify-content: flex-end;
    width: 100%;
  }

  .sup-drawer {
    width: 100%;
    max-width: 100vw;
  }

  .form-grid-2 {
    grid-template-columns: 1fr;
  }

  .pager-row {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>
