<template>
  <div class="bom-list-page" v-loading="loading">
    <!-- 页面全局头部卡片：白底圆角独立卡片 (100% 对齐主数据中心 / SkuList.vue 风格) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-connection" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">BOM 管理</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">
              共 {{ (stats.total || total).toLocaleString() }} 款 BOM 方案
            </el-tag>
            <el-tag
              v-if="activeFilterTag"
              size="small"
              type="info"
              effect="plain"
              closable
              class="head-tag filter-status-tag"
              @close="clearFilterTag"
            >
              {{ activeFilterTag }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" :loading="loading" @click="load">刷新</el-button>
        <el-button v-if="$can('bom.manage.view')" size="small" icon="el-icon-delete" class="btn-default" @click="openDeletedDialog">回收站</el-button>
        <el-button size="small" icon="el-icon-share" class="btn-default" @click="$router.push('/bom/expand')">
          BOM 展开算量
        </el-button>
        <el-button
          v-if="$can('bom.manage.create')"
          size="small"
          type="success"
          icon="el-icon-plus"
          class="btn-theme-create"
          @click="$router.push('/bom/create')"
        >
          新增 BOM
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面业务提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>维护 SKU / 产出物料的生产用料多级结构、版本变更、审核控制与有效期规则。默认启用方案作为生产工单发料与物料领用的唯一样本依据。</span>
    </div>

    <!-- 顶部概览指标卡片 (100% 对齐主数据中心风格，支持即时点击联动筛选) -->
    <section class="metric-overview-grid">
      <div
        class="metric-card metric-all"
        :class="{ active: !query.status && !query.audit_status && !query.is_default }"
        title="点击查看全部 BOM 方案"
        @click="filterAll"
      >
        <div class="metric-icon-box"><i class="el-icon-connection" /></div>
        <div class="metric-info">
          <span class="metric-label">全部 BOM 方案</span>
          <strong class="metric-val code-mono">{{ (stats.total || total).toLocaleString() }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-active"
        :class="{ active: query.status === 'active' }"
        title="点击筛选正常启用方案"
        @click="filterStatus('active')"
      >
        <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
        <div class="metric-info">
          <span class="metric-label">正常启用方案</span>
          <strong class="metric-val code-mono">{{ (stats.active || 0).toLocaleString() }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-pending"
        :class="{ active: query.audit_status === 'pending' }"
        title="点击筛选待审核方案"
        @click="filterAudit('pending')"
      >
        <div class="metric-icon-box"><i class="el-icon-time" /></div>
        <div class="metric-info">
          <span class="metric-label">待审核方案</span>
          <strong class="metric-val code-mono">{{ (stats.pending || 0).toLocaleString() }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-default"
        :class="{ active: query.is_default === 'true' }"
        title="点击筛选默认启用方案"
        @click="filterDefault('true')"
      >
        <div class="metric-icon-box"><i class="el-icon-star-on" /></div>
        <div class="metric-info">
          <span class="metric-label">默认生效方案</span>
          <strong class="metric-val code-mono">{{ (stats.default || 0).toLocaleString() }}</strong>
        </div>
      </div>
    </section>

    <!-- 筛选工具栏与主表格卡片 -->
    <section class="table-container-card">
      <div class="filter-toolbar">
        <div class="filter-fields">
          <el-input
            v-model.trim="query.keyword"
            size="small"
            clearable
            prefix-icon="el-icon-search"
            placeholder="搜索 BOM 编号、名称、物料、SKU..."
            class="filter-input-search"
            @keyup.enter.native="search"
            @clear="search"
          />

          <el-select v-model="query.product_id" size="small" clearable filterable placeholder="归属商品" class="filter-select-md" @change="search">
            <el-option v-for="p in products" :key="p.id" :label="`${p.product_code} / ${p.product_name}`" :value="p.id" />
          </el-select>

          <el-select v-model="query.sku_id" size="small" clearable filterable placeholder="关联SKU" class="filter-select-md" @change="search">
            <el-option v-for="s in skus" :key="s.id" :label="`${s.sku_code} / ${s.sku_name}`" :value="s.id" />
          </el-select>

          <el-select v-model="query.output_item_id" size="small" clearable filterable placeholder="产出物料" class="filter-select-md" @change="search">
            <el-option v-for="i in items" :key="i.id" :label="`${i.item_code} / ${i.item_name}`" :value="i.id" />
          </el-select>

          <el-select v-model="query.status" size="small" clearable placeholder="启用状态" class="filter-select-xs" @change="search">
            <el-option label="全部状态" value="" />
            <el-option label="草稿" value="draft" />
            <el-option label="启用" value="active" />
            <el-option label="已停用" value="inactive" />
            <el-option label="已归档" value="archived" />
          </el-select>

          <el-select v-model="query.audit_status" size="small" clearable placeholder="审核状态" class="filter-select-xs" @change="search">
            <el-option label="全部审核" value="" />
            <el-option label="待审核" value="pending" />
            <el-option label="已审核" value="approved" />
            <el-option label="已驳回" value="rejected" />
          </el-select>

          <el-select v-model="query.bom_type" size="small" clearable placeholder="BOM类型" class="filter-select-xs" @change="search">
            <el-option label="全部类型" value="" />
            <el-option label="标准" value="standard" />
            <el-option label="定制" value="custom" />
            <el-option label="试制" value="trial" />
          </el-select>

          <el-select v-model="query.is_default" size="small" clearable placeholder="是否默认" class="filter-select-xs" @change="search">
            <el-option label="全部" value="" />
            <el-option label="默认" value="true" />
            <el-option label="非默认" value="false" />
          </el-select>

          <el-date-picker
            v-model="query.dateRange"
            size="small"
            type="daterange"
            range-separator="至"
            start-placeholder="更新时间起"
            end-placeholder="更新时间止"
            value-format="yyyy-MM-dd"
            class="filter-date-picker"
            @change="search"
          />
        </div>

        <div class="filter-actions">
          <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-submit" @click="search">查询</el-button>
          <el-button size="small" icon="el-icon-refresh" class="btn-default" @click="reset">重置</el-button>
        </div>
      </div>

      <!-- BOM 数据列表表格 -->
      <el-table :data="rows" border size="small" class="bom-table erp-page-table" v-loading="loading">
        <el-table-column prop="bom_no" label="BOM编号" min-width="150">
          <template slot-scope="{ row }">
            <span class="item-code-chip" :title="row.bom_no">{{ row.bom_no }}</span>
          </template>
        </el-table-column>

        <el-table-column prop="bom_name" label="BOM名称" min-width="160" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <strong class="bom-name-text">{{ row.bom_name }}</strong>
          </template>
        </el-table-column>

        <el-table-column label="归属商品" min-width="130" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <span>{{ row.product ? row.product.product_name : '-' }}</span>
          </template>
        </el-table-column>

        <el-table-column label="关联SKU" min-width="160" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <span v-if="row.sku" class="sku-cell-text">
              <code class="code-mono">{{ row.sku.sku_code }}</code>
              <small>{{ row.sku.sku_name }}</small>
            </span>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>

        <el-table-column label="产出物料" min-width="170" show-overflow-tooltip>
          <template slot-scope="{ row }">
            <span v-if="row.output_item" class="item-cell-text">
              <code class="code-mono">{{ row.output_item.item_code }}</code>
              <small>{{ row.output_item.item_name }}</small>
            </span>
            <span v-else class="text-muted">-</span>
          </template>
        </el-table-column>

        <el-table-column prop="version" label="版本" width="75" align="center">
          <template slot-scope="{ row }">
            <span class="version-tag">{{ row.version }}</span>
          </template>
        </el-table-column>

        <el-table-column label="默认" width="80" align="center">
          <template slot-scope="{ row }">
            <span class="default-badge" :class="{ is_default: row.is_default }">
              {{ row.is_default ? '★ 默认' : '否' }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="有效期" width="180" align="center">
          <template slot-scope="{ row }">
            <span class="validity-text">{{ dateText(row.effective_date) }} 至 {{ row.expire_date ? dateText(row.expire_date) : '长期有效' }}</span>
          </template>
        </el-table-column>

        <el-table-column label="状态" width="90" align="center">
          <template slot-scope="{ row }">
            <span class="status-pill" :class="displayStatusClass(row)">
              {{ displayStatusText(row) }}
            </span>
          </template>
        </el-table-column>

        <el-table-column label="审核" width="85" align="center">
          <template slot-scope="{ row }">
            <span class="audit-pill" :class="displayAuditClass(row)">
              {{ displayAuditText(row) }}
            </span>
          </template>
        </el-table-column>

        <el-table-column prop="updated_at" label="更新时间" width="135" align="center" show-overflow-tooltip />

        <el-table-column label="操作" width="280" fixed="right" align="center">
          <template slot-scope="{ row }">
            <div class="row-action-links">
              <el-button type="text" size="small" class="theme-btn-link" icon="el-icon-view" @click="openDetail(row)">查看</el-button>
              <el-button v-if="canEditBom(row)" type="text" size="small" class="theme-btn-link" icon="el-icon-edit" @click="$router.push(`/bom/${row.id}/edit`)">编辑</el-button>
              <el-button v-if="isDraftEditable(row) && canAction('submit')" type="text" size="small" class="theme-btn-link" icon="el-icon-check" @click="action(row, 'submit')">提交</el-button>
              <el-button v-if="isPendingAudit(row) && canAction('approve')" type="text" size="small" class="theme-btn-link" icon="el-icon-circle-check" @click="action(row, 'approve')">通过</el-button>
              <el-button v-if="isPendingAudit(row) && canAction('reject')" type="text" size="small" class="danger-btn-link" icon="el-icon-circle-close" @click="action(row, 'reject')">驳回</el-button>
              <el-button v-if="isApprovedInactive(row) && canAction('activate')" type="text" size="small" class="theme-btn-link" icon="el-icon-video-play" @click="action(row, 'activate')">启用</el-button>
              <el-button v-if="isActiveApproved(row) && canAction('deactivate')" type="text" size="small" class="danger-btn-link" icon="el-icon-video-pause" @click="action(row, 'deactivate')">停用</el-button>
              <el-button v-if="canSetDefault(row) && canAction('setDefault')" type="text" size="small" class="warning-btn-link" icon="el-icon-star-off" @click="action(row, 'setDefault')">设默认</el-button>
              <el-button v-if="row.audit_status === 'approved' && $can('bom.manage.create')" type="text" size="small" class="theme-btn-link" icon="el-icon-document-copy" @click="copyVersion(row)">复制新版</el-button>
              <el-button v-if="isActiveApproved(row) && isEffective(row)" type="text" size="small" class="theme-btn-link highlight-action" icon="el-icon-share" @click="$router.push({ path: '/bom/expand', query: { bom_id: row.id } })">展开算量</el-button>
              <el-button v-if="canDeleteBom(row)" type="text" size="small" class="danger-btn-link" icon="el-icon-delete" @click="action(row, 'delete')">移入回收站</el-button>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <!-- 底部分页器 (默认 10 条每页，主题绿高亮) -->
      <div class="pager-row">
        <span class="pager-total">共 {{ total }} 款 BOM 方案</span>
        <el-pagination
          background
          layout="sizes, prev, pager, next, jumper"
          :current-page.sync="page"
          :page-size.sync="perPage"
          :page-sizes="[10, 20, 50, 100]"
          :total="total"
          @current-change="load"
          @size-change="load"
        />
      </div>
    </section>

    <el-dialog
      :visible.sync="deletedDialog.visible"
      title="BOM 回收站"
      width="1100px"
      top="5vh"
      custom-class="erp-themed-dialog bom-recycle-dialog"
      append-to-body
      :close-on-click-modal="false"
    >
      <div slot="title" class="recycle-dialog-title">
        <i class="el-icon-delete" />
        <strong>BOM 回收站</strong>
        <el-tag size="mini" type="info">共 {{ deletedDialog.total }} 款</el-tag>
      </div>
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>已删除方案可查看和恢复。恢复后保持删除前状态，不会自动启用。</span>
      </div>
      <div class="recycle-filter-bar">
        <el-input
          v-model.trim="deletedDialog.keyword"
          size="small"
          clearable
          prefix-icon="el-icon-search"
          placeholder="搜索 BOM 编号、名称、产出物料"
          @keyup.enter.native="searchDeleted"
          @clear="searchDeleted"
        />
        <el-button size="small" type="success" icon="el-icon-search" @click="searchDeleted">查询</el-button>
        <el-button size="small" icon="el-icon-refresh" @click="resetDeletedSearch">重置</el-button>
      </div>
      <el-table v-loading="deletedDialog.loading" :data="deletedDialog.rows" border size="small" class="bom-table erp-page-table">
        <el-table-column prop="bom_no" label="BOM编号" min-width="150">
          <template slot-scope="{ row }"><span class="item-code-chip">{{ row.bom_no }}</span></template>
        </el-table-column>
        <el-table-column prop="bom_name" label="BOM名称" min-width="180" />
        <el-table-column label="产出物料" min-width="200">
          <template slot-scope="{ row }">{{ outputItemText(row) }}</template>
        </el-table-column>
        <el-table-column prop="version" label="版本" width="75" align="center" />
        <el-table-column label="删除前状态" width="110" align="center">
          <template slot-scope="{ row }"><span class="status-pill disabled">{{ statusText(row.status) }}</span></template>
        </el-table-column>
        <el-table-column prop="deleted_at" label="删除时间" min-width="160" />
        <el-table-column label="操作" width="150" fixed="right" align="center">
          <template slot-scope="{ row }">
            <el-button type="text" size="small" class="theme-btn-link" icon="el-icon-view" @click="openDetail(row, true)">查看</el-button>
            <el-button v-if="$can('bom.manage.delete')" type="text" size="small" class="theme-btn-link" icon="el-icon-refresh-left" @click="action(row, 'restore')">恢复</el-button>
          </template>
        </el-table-column>
      </el-table>
      <div class="pager-row">
        <span class="pager-total">共 {{ deletedDialog.total }} 款 BOM 方案</span>
        <el-pagination
          background
          layout="sizes, prev, pager, next, jumper"
          :current-page="deletedDialog.page"
          :page-size="deletedDialog.perPage"
          :page-sizes="[10, 20, 50, 100]"
          :total="deletedDialog.total"
          @current-change="changeDeletedPage"
          @size-change="changeDeletedSize"
        />
      </div>
      <div slot="footer" class="dialog-footer">
        <el-button size="small" @click="deletedDialog.visible = false">关闭</el-button>
      </div>
    </el-dialog>

    <!-- BOM 操作确认居中弹窗 (严格遵循 2026-09-24 居中遮罩规范) -->
    <el-dialog
      :visible.sync="opDialog.visible"
      :title="`BOM 操作确认 - ${opDialog.text}`"
      width="480px"
      top="15vh"
      custom-class="erp-themed-dialog"
      append-to-body
      :close-on-click-modal="false"
    >
      <div class="dialog-header-banner">
        <div class="dialog-banner-icon warning"><i class="el-icon-warning-outline" /></div>
        <div class="dialog-banner-text">
          <span class="dialog-banner-title">请确认执行【{{ opDialog.text }}】操作</span>
          <span class="dialog-banner-desc">{{ opDialog.type === 'delete' ? '移入回收站后可恢复，原方案信息和物料明细会保留。' : opDialog.type === 'restore' ? '恢复后保持删除前状态，不会自动启用。' : '该操作会变更 BOM 的流转状态或默认生效配置，请核对方案信息。' }}</span>
        </div>
      </div>
      <div v-if="opDialog.row" class="dialog-target-card">
        <div class="target-row"><span class="target-label">BOM 编号：</span><span class="target-val code-mono">{{ opDialog.row.bom_no }}</span></div>
        <div class="target-row"><span class="target-label">BOM 名称：</span><span class="target-val">{{ opDialog.row.bom_name }}</span></div>
        <div class="target-row"><span class="target-label">当前版本：</span><span class="target-val code-mono">{{ opDialog.row.version }}</span></div>
      </div>
      <span slot="footer" class="dialog-footer">
        <el-button size="small" class="btn-default" @click="opDialog.visible = false">取消</el-button>
        <el-button v-if="canAction(opDialog.type)" size="small" type="success" class="btn-theme-submit" :loading="opDialog.loading" @click="confirmAction">确定{{ opDialog.text }}</el-button>
      </span>
    </el-dialog>

    <!-- 复制为新版本居中弹窗 (严格遵循 2026-09-24 居中遮罩规范) -->
    <el-dialog
      :visible.sync="versionDialog.visible"
      title="复制为新版本 BOM"
      width="480px"
      top="15vh"
      custom-class="erp-themed-dialog"
      append-to-body
      :close-on-click-modal="false"
    >
      <div class="dialog-header-banner">
        <div class="dialog-banner-icon"><i class="el-icon-document-copy" /></div>
        <div class="dialog-banner-text">
          <span class="dialog-banner-title">基于已有方案创建新版本草稿</span>
          <span class="dialog-banner-desc">将完整继承原用料结构与工艺参数，新版本将初始化为草稿状态。</span>
        </div>
      </div>
      <el-form label-width="90px" size="small" class="dialog-form">
        <el-form-item label="原 BOM 编号">
          <span class="code-mono fw-bold">{{ versionDialog.row ? versionDialog.row.bom_no : '-' }}</span>
        </el-form-item>
        <el-form-item label="新版本号" required>
          <el-input v-model.trim="versionDialog.version" placeholder="例如：V1.1 / V2.0" />
        </el-form-item>
      </el-form>
      <span slot="footer" class="dialog-footer">
        <el-button size="small" class="btn-default" @click="versionDialog.visible = false">取消</el-button>
        <el-button v-if="$can('bom.manage.create')" size="small" type="success" class="btn-theme-submit" :loading="versionDialog.loading" @click="confirmCopyVersion">{{ $can('bom.manage.edit') ? '确认创建并编辑' : '确认创建' }}</el-button>
      </span>
    </el-dialog>

    <!-- BOM 详情居中查看弹窗 (2026-10-15 用户要求彻底弹窗化，废除独立详情页) -->
    <el-dialog
      :visible.sync="detailDialog.visible"
      width="1040px"
      top="5vh"
      custom-class="erp-themed-dialog bom-detail-dialog"
      append-to-body
      :close-on-click-modal="false"
    >
      <div slot="title" class="dialog-header-banner">
        <div class="banner-title-row">
          <div class="banner-title-left">
            <i class="el-icon-connection"></i>
            <span class="dialog-main-title">{{ detailDialog.deleted ? '已删除 BOM 详情' : 'BOM 方案详情' }}</span>
            <span v-if="detailDialog.data" class="dialog-bom-no code-mono">{{ detailDialog.data.bom_no }}</span>
            <el-tag v-if="detailDialog.data && detailDialog.data.is_default" size="mini" type="success" effect="dark" class="tag-bold">★ 默认方案</el-tag>
            <el-tag v-if="detailDialog.data" size="mini" :type="displayStatusType(detailDialog.data)" effect="plain">
              {{ displayStatusText(detailDialog.data) }}
            </el-tag>
            <el-tag v-if="detailDialog.data" size="mini" :type="displayAuditType(detailDialog.data)" effect="plain">
              {{ displayAuditText(detailDialog.data) }}
            </el-tag>
          </div>
        </div>
        <p v-if="detailDialog.data" class="dialog-subtitle">
          方案名称：{{ detailDialog.data.bom_name }} · 版本：{{ detailDialog.data.version }} · 产出物料：{{ outputItemText(detailDialog.data) }}
        </p>
      </div>

      <div class="dialog-body-wrap" v-loading="detailDialog.loading">
        <template v-if="detailDialog.data">
          <!-- 快捷操作栏（顶部直达按钮） -->
          <div v-if="!detailDialog.deleted" class="detail-actions-bar">
            <div class="actions-left">
              <span class="actions-hint">
                <i class="el-icon-info"></i>
                当前方案{{ detailDialog.data.status === 'inactive' ? '已停用，编辑保存后需重新审核' : isDraftEditable(detailDialog.data) ? '为草稿状态，可直接编辑或提交审核' : isActiveApproved(detailDialog.data) ? '为已启用方案，可直接用于展开算量与生产工单' : '状态受控' }}
              </span>
            </div>
            <div class="actions-right">
              <el-button
                v-if="canEditBom(detailDialog.data)"
                size="mini"
                type="primary"
                icon="el-icon-edit"
                @click="editFromDetail(detailDialog.data)"
              >
                编辑BOM
              </el-button>
              <el-button
                v-if="isDraftEditable(detailDialog.data) && canAction('submit')"
                size="mini"
                type="success"
                icon="el-icon-check"
                @click="action(detailDialog.data, 'submit')"
              >
                提交审核
              </el-button>
              <el-button
                v-if="isPendingAudit(detailDialog.data) && canAction('approve')"
                size="mini"
                type="success"
                icon="el-icon-circle-check"
                @click="action(detailDialog.data, 'approve')"
              >
                审核通过
              </el-button>
              <el-button
                v-if="isPendingAudit(detailDialog.data) && canAction('reject')"
                size="mini"
                type="danger"
                plain
                icon="el-icon-circle-close"
                @click="action(detailDialog.data, 'reject')"
              >
                驳回
              </el-button>
              <el-button
                v-if="isApprovedInactive(detailDialog.data) && canAction('activate')"
                size="mini"
                type="success"
                icon="el-icon-video-play"
                @click="action(detailDialog.data, 'activate')"
              >
                启用
              </el-button>
              <el-button
                v-if="isActiveApproved(detailDialog.data) && canAction('deactivate')"
                size="mini"
                type="danger"
                plain
                icon="el-icon-video-pause"
                @click="action(detailDialog.data, 'deactivate')"
              >
                停用
              </el-button>
              <el-button
                v-if="canSetDefault(detailDialog.data) && canAction('setDefault')"
                size="mini"
                type="warning"
                plain
                icon="el-icon-star-off"
                @click="action(detailDialog.data, 'setDefault')"
              >
                设为默认
              </el-button>
              <el-button
                v-if="detailDialog.data.audit_status === 'approved' && $can('bom.manage.create')"
                size="mini"
                icon="el-icon-document-copy"
                @click="copyVersion(detailDialog.data)"
              >
                复制新版
              </el-button>
              <el-button
                v-if="isActiveApproved(detailDialog.data) && isEffective(detailDialog.data)"
                size="mini"
                type="success"
                icon="el-icon-share"
                @click="expandFromDetail(detailDialog.data)"
              >
                展开算量
              </el-button>
              <el-button
                v-if="canDeleteBom(detailDialog.data)"
                size="mini"
                type="danger"
                plain
                icon="el-icon-delete"
                @click="action(detailDialog.data, 'delete')"
              >
                移入回收站
              </el-button>
            </div>
          </div>

          <el-tabs v-model="detailDialog.activeTab" class="detail-tabs">
            <el-tab-pane label="基本属性与产出" name="basic">
              <div class="detail-pane-content">
                <div class="detail-grid-section">
                  <h4 class="section-badge-title"><i class="el-icon-tickets"></i> 基础信息</h4>
                  <div class="info-grid-4">
                    <div class="info-item"><span class="info-k">BOM 编号</span><span class="info-v code-mono">{{ detailDialog.data.bom_no }}</span></div>
                    <div class="info-item"><span class="info-k">BOM 名称</span><span class="info-v font-bold">{{ detailDialog.data.bom_name }}</span></div>
                    <div class="info-item"><span class="info-k">BOM 类型</span><span class="info-v"><el-tag size="mini" type="info">{{ bomTypeText(detailDialog.data.bom_type) }}</el-tag></span></div>
                    <div class="info-item"><span class="info-k">版本号</span><span class="info-v code-mono font-bold">{{ detailDialog.data.version }}</span></div>
                    <div class="info-item"><span class="info-k">{{ detailDialog.deleted ? '删除前状态' : '方案状态' }}</span><span class="info-v"><span class="status-dot" :class="displayStatusClass(detailDialog.data)"></span>{{ detailDialog.deleted ? statusText(detailDialog.data.status) : displayStatusText(detailDialog.data) }}</span></div>
                    <div class="info-item"><span class="info-k">审核状态</span><span class="info-v"><span class="audit-badge" :class="displayAuditClass(detailDialog.data)">{{ displayAuditText(detailDialog.data) }}</span></span></div>
                    <div class="info-item"><span class="info-k">生效日期</span><span class="info-v">{{ dateText(detailDialog.data.effective_date) }}</span></div>
                    <div class="info-item"><span class="info-k">失效日期</span><span class="info-v">{{ detailDialog.data.expire_date ? dateText(detailDialog.data.expire_date) : '长期有效' }}</span></div>
                  </div>
                </div>

                <div class="detail-grid-section">
                  <h4 class="section-badge-title"><i class="el-icon-goods"></i> 产出目标与单位</h4>
                  <div class="info-grid-4">
                    <div class="info-item"><span class="info-k">产出物料编码</span><span class="info-v code-mono">{{ detailDialog.data.output_item ? detailDialog.data.output_item.item_code : '-' }}</span></div>
                    <div class="info-item"><span class="info-k">产出物料名称</span><span class="info-v font-bold">{{ detailDialog.data.output_item ? detailDialog.data.output_item.item_name : '-' }}</span></div>
                    <div class="info-item"><span class="info-k">规格型号</span><span class="info-v">{{ detailDialog.data.output_item && detailDialog.data.output_item.spec ? detailDialog.data.output_item.spec : '—' }}</span></div>
                    <div class="info-item"><span class="info-k">标准计量单位</span><span class="info-v unit-tag">{{ outputUnitText(detailDialog.data) }}</span></div>
                    <div class="info-item"><span class="info-k">归属商品</span><span class="info-v">{{ detailDialog.data.product ? `${detailDialog.data.product.product_code} / ${detailDialog.data.product.product_name}` : '-' }}</span></div>
                    <div class="info-item"><span class="info-k">关联 SKU</span><span class="info-v">{{ detailDialog.data.sku ? `${detailDialog.data.sku.sku_code} / ${detailDialog.data.sku.sku_name}` : '-' }}</span></div>
                  </div>
                </div>

                <div v-if="detailDialog.data.bom_type === 'custom'" class="detail-grid-section custom-section">
                  <h4 class="section-badge-title"><i class="el-icon-link"></i> 定制来源追溯</h4>
                  <div class="info-grid-4">
                    <div class="info-item"><span class="info-k">来源商品</span><span class="info-v">{{ detailDialog.data.source_product ? detailDialog.data.source_product.product_name : '-' }}</span></div>
                    <div class="info-item"><span class="info-k">来源 SKU</span><span class="info-v">{{ detailDialog.data.source_sku ? detailDialog.data.source_sku.sku_name : '-' }}</span></div>
                    <div class="info-item span-2"><span class="info-k">来源标准 BOM</span><span class="info-v">{{ detailDialog.data.source_standard_bom ? `${detailDialog.data.source_standard_bom.bom_no} (${detailDialog.data.source_standard_bom.bom_name} · ${detailDialog.data.source_standard_bom.version})` : '-' }}</span></div>
                  </div>
                </div>

                <div class="detail-grid-section">
                  <h4 class="section-badge-title"><i class="el-icon-document"></i> 方案描述与备注说明</h4>
                  <div class="desc-box">
                    <p><strong>描述：</strong>{{ detailDialog.data.custom_description || '无补充描述' }}</p>
                    <p><strong>备注：</strong>{{ detailDialog.data.remark || '无备注' }}</p>
                  </div>
                </div>
              </div>
            </el-tab-pane>

            <el-tab-pane label="物料组成清单" name="items">
              <div class="detail-pane-content">
                <div class="items-head-bar">
                  <span class="items-count">共 <strong>{{ (detailDialog.data.items || []).length }}</strong> 行组成物料</span>
                </div>
                <el-table :data="detailDialog.data.items || []" border size="small" class="detail-items-table">
                  <el-table-column prop="line_no" label="行号" width="60" align="center">
                    <template slot-scope="{ row }">
                      <span class="line-badge code-mono">{{ row.line_no }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column prop="component_item_code" label="物料编码" width="130" show-overflow-tooltip>
                    <template slot-scope="{ row }">
                      <span class="code-mono font-bold">{{ row.component_item_code }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column prop="component_item_name" label="物料名称" min-width="150" show-overflow-tooltip />
                  <el-table-column label="规格" min-width="130" show-overflow-tooltip>
                    <template slot-scope="{ row }">
                      {{ (row.component_item && row.component_item.spec) || row.component_item_spec || '—' }}
                    </template>
                  </el-table-column>
                  <el-table-column label="下料要求" width="180" align="center">
                    <template slot-scope="{ row }">
                      <div class="cut-info-cell">
                        <span class="cut-main">{{ cutRequirement(row) }}</span>
                        <small v-if="stockRequirement(row)" class="cut-stock">{{ stockRequirement(row) }}</small>
                      </div>
                    </template>
                  </el-table-column>
                  <el-table-column label="定额用量" width="105" align="right">
                    <template slot-scope="{ row }">
                      <strong class="qty-highlight code-mono">{{ lineQuantity(row) }}</strong>
                    </template>
                  </el-table-column>
                  <el-table-column label="单位" width="75" align="center">
                    <template slot-scope="{ row }">
                      <span class="unit-tag">{{ (row.unit && row.unit.unit_name) || '-' }}</span>
                    </template>
                  </el-table-column>
                  <el-table-column prop="loss_rate" label="损耗率(%)" width="90" align="right">
                    <template slot-scope="{ row }">
                      {{ Number(row.loss_rate || 0).toFixed(2) }}%
                    </template>
                  </el-table-column>
                  <el-table-column prop="fixed_qty" label="固定用量" width="85" align="right">
                    <template slot-scope="{ row }">
                      {{ Number(row.fixed_qty || 0).toFixed(4) }}
                    </template>
                  </el-table-column>
                  <el-table-column label="可替代" width="75" align="center">
                    <template slot-scope="{ row }">
                      <el-tag size="mini" :type="row.replaceable ? 'success' : 'info'">{{ row.replaceable ? '是' : '否' }}</el-tag>
                    </template>
                  </el-table-column>
                  <el-table-column prop="remark" label="备注" min-width="120" show-overflow-tooltip>
                    <template slot-scope="{ row }">
                      {{ row.remark || '—' }}
                    </template>
                  </el-table-column>
                </el-table>
              </div>
            </el-tab-pane>

            <el-tab-pane label="版本与审计日志" name="history">
              <div class="detail-pane-content version-log-layout">
                <div class="version-section">
                  <h4 class="section-badge-title"><i class="el-icon-folder-opened"></i> 版本历史演进</h4>
                  <div class="version-list">
                    <div
                      v-for="v in detailDialog.data.version_history || []"
                      :key="v.id"
                      class="version-card-item"
                      :class="{ current: v.id === detailDialog.data.id, readonly: detailDialog.deleted }"
                      @click="!detailDialog.deleted && switchVersionInDialog(v.id)"
                    >
                      <div class="version-head">
                        <strong class="code-mono">{{ v.version }}</strong>
                        <el-tag v-if="v.is_default" size="mini" type="success">默认</el-tag>
                        <el-tag v-if="v.id === detailDialog.data.id" size="mini" type="primary">当前</el-tag>
                      </div>
                      <div class="version-no code-mono">{{ v.bom_no }}</div>
                      <div class="version-sub">
                        <span>{{ statusText(v.status) }}</span> · <span>{{ auditText(v.audit_status) }}</span>
                      </div>
                    </div>
                  </div>
                </div>

                <div class="log-section">
                  <h4 class="section-badge-title"><i class="el-icon-time"></i> 操作审计日志</h4>
                  <ul class="dialog-log-list">
                    <li v-for="log in detailDialog.data.logs || []" :key="log.id" class="log-item">
                      <div class="log-top">
                        <el-tag size="mini" type="info">{{ actionText(log.action) }}</el-tag>
                        <span class="log-time">{{ log.created_at }}</span>
                      </div>
                      <div class="log-msg">{{ log.message }}</div>
                    </li>
                    <li v-if="!(detailDialog.data.logs || []).length" class="empty-log">
                      暂无操作审计日志
                    </li>
                  </ul>
                </div>
              </div>
            </el-tab-pane>
          </el-tabs>
        </template>
      </div>

      <div slot="footer" class="dialog-footer">
        <el-button size="small" @click="detailDialog.visible = false">关 闭</el-button>
        <el-button v-if="detailDialog.deleted && detailDialog.data && $can('bom.manage.delete')" size="small" type="success" icon="el-icon-refresh-left" @click="action(detailDialog.data, 'restore')">恢复</el-button>
      </div>
    </el-dialog>
  </div>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { listBoms, getBom, deleteBom, restoreBom, submitBom, approveBom, rejectBom, activateBom, deactivateBom, setDefaultBom, copyBomVersion } from '@/api/erp/bom'
import { cuttingQuantityBasis, totalLengthMetres } from '@/utils/cutting-quantity.mjs'

export default {
  name: 'BomList',
  data: () => ({
    loading: false,
    rows: [],
    total: 0,
    page: 1,
    perPage: 10,
    stats: { total: 0, active: 0, pending: 0, default: 0 },
    products: [],
    skus: [],
    items: [],
    query: {
      keyword: '',
      product_id: '',
      sku_id: '',
      output_item_id: '',
      status: '',
      audit_status: '',
      bom_type: '',
      is_default: '',
      dateRange: []
    },
    opDialog: { visible: false, loading: false, row: null, type: '', text: '' },
    versionDialog: { visible: false, loading: false, row: null, version: '' },
    deletedDialog: { visible: false, loading: false, keyword: '', rows: [], total: 0, page: 1, perPage: 10 },
    detailDialog: { visible: false, loading: false, activeTab: 'basic', data: null, deleted: false }
  }),
  computed: {
    activeFilterTag() {
      if (this.query.status === 'active') return '筛选: 正常启用'
      if (this.query.status === 'inactive') return '筛选: 已停用'
      if (this.query.audit_status === 'pending') return '筛选: 待审核'
      if (this.query.is_default === 'true') return '筛选: 默认方案'
      if (this.query.keyword) return `搜索: ${this.query.keyword}`
      return ''
    }
  },
  created() {
    this.loadRefs()
    this.load()
    const viewId = this.$route.query.view_id || this.$route.query.bom_id
    const deleted = this.$route.query.deleted === 'only'
    if (deleted) this.openDeletedDialog()
    if (viewId) {
      this.openDetailById(viewId, deleted)
    }
  },
  methods: {
    async loadRefs() {
      const [p, s, i] = await Promise.all([
        listEntity('products', { per_page: 100 }),
        listEntity('skus', { per_page: 100 }),
        listEntity('items', { per_page: 100, management_scope: 'factory' })
      ])
      this.products = p.data.data || []
      this.skus = s.data.data || []
      this.items = i.data.data || []
    },
    params() {
      const [date_from, date_to] = this.query.dateRange || []
      return {
        ...this.query,
        date_from,
        date_to,
        dateRange: undefined,
        page: this.page,
        per_page: this.perPage
      }
    },
    async load() {
      this.loading = true
      try {
        const { data } = await listBoms(this.params())
        this.rows = data.data || []
        this.total = data.total || 0
        if (data.last_page && this.page > data.last_page) {
          this.page = data.last_page
          return await this.load()
        }
        if (data.stats) {
          this.stats = data.stats
        } else {
          this.stats = {
            total: this.total,
            active: this.rows.filter(x => x.status === 'active').length,
            pending: this.rows.filter(x => x.audit_status === 'pending').length,
            default: this.rows.filter(x => x.is_default).length
          }
        }
      } catch (error) {
        this.$message.error('加载 BOM 列表失败')
      } finally {
        this.loading = false
      }
    },
    search() {
      this.page = 1
      this.load()
    },
    openDeletedDialog() {
      if (!this.$can('bom.manage.view')) return
      Object.assign(this.deletedDialog, { visible: true, keyword: '', page: 1, perPage: 10 })
      this.loadDeleted()
    },
    async loadDeleted() {
      this.deletedDialog.loading = true
      try {
        const { data } = await listBoms({ deleted: 'only', keyword: this.deletedDialog.keyword, page: this.deletedDialog.page, per_page: this.deletedDialog.perPage })
        this.deletedDialog.rows = data.data || []
        this.deletedDialog.total = data.total || 0
        if (data.last_page && this.deletedDialog.page > data.last_page) {
          this.deletedDialog.page = data.last_page
          return await this.loadDeleted()
        }
      } catch (error) {
        this.$message.error(error.userMessage || '加载 BOM 回收站失败')
      } finally {
        this.deletedDialog.loading = false
      }
    },
    searchDeleted() {
      this.deletedDialog.page = 1
      this.loadDeleted()
    },
    resetDeletedSearch() {
      this.deletedDialog.keyword = ''
      this.searchDeleted()
    },
    changeDeletedPage(page) {
      this.deletedDialog.page = page
      this.loadDeleted()
    },
    changeDeletedSize(size) {
      this.deletedDialog.perPage = size
      this.searchDeleted()
    },
    reset() {
      this.query = {
        keyword: '',
        product_id: '',
        sku_id: '',
        output_item_id: '',
        status: '',
        audit_status: '',
        bom_type: '',
        is_default: '',
        dateRange: []
      }
      this.search()
    },
    filterAll() {
      this.query.status = ''
      this.query.audit_status = ''
      this.query.is_default = ''
      this.search()
    },
    filterStatus(st) {
      this.query.status = st
      this.query.audit_status = ''
      this.query.is_default = ''
      this.search()
    },
    filterAudit(aud) {
      this.query.audit_status = aud
      this.query.status = ''
      this.query.is_default = ''
      this.search()
    },
    filterDefault(def) {
      this.query.is_default = def
      this.query.status = ''
      this.query.audit_status = ''
      this.search()
    },
    clearFilterTag() {
      this.filterAll()
    },
    statusText(v) {
      return ({ draft: '草稿', active: '启用', inactive: '已停用', archived: '已归档' })[v] || v
    },
    auditText(v) {
      return ({ pending: '待审核', approved: '已审核', rejected: '已驳回' })[v] || v
    },
    displayStatusText(row) {
      if (row.status === 'inactive') return this.statusText(row.status)
      if (this.isExpired(row)) return '已失效'
      if (this.isNotYetEffective(row)) return '未生效'
      if (this.isPendingAudit(row)) return '已提交'
      if (row.audit_status === 'approved' && row.status !== 'active') return '已审核'
      return this.statusText(row.status)
    },
    displayAuditText(row) {
      if (this.isDraftEditable(row)) return '未提交'
      return this.auditText(row.audit_status)
    },
    displayStatusClass(row) {
      if (row.status === 'inactive') return 'disabled'
      if (this.isExpired(row)) return 'danger'
      if (this.isNotYetEffective(row)) return 'warning'
      if (this.isPendingAudit(row)) return 'warning'
      if (row.status === 'active') return 'enabled'
      return 'disabled'
    },
    displayAuditClass(row) {
      if (row.audit_status === 'approved') return 'approved'
      if (row.audit_status === 'rejected') return 'rejected'
      if (row.audit_status === 'pending') return 'pending'
      return 'draft'
    },
    isDraftEditable(row) { return row.status === 'draft' && !row.submitted_at },
    isPendingAudit(row) { return row.audit_status === 'pending' && Boolean(row.submitted_at) },
    canEditBom(row) { return this.$can('bom.manage.edit') && !row.deleted_at && !this.isPendingAudit(row) && (this.isDraftEditable(row) || row.status === 'inactive') },
    canDeleteBom(row) { return this.$can('bom.manage.delete') && !row.deleted_at && !row.is_default && !this.isPendingAudit(row) && (row.status === 'inactive' || (this.isDraftEditable(row) && row.audit_status === 'pending')) },
    isApprovedInactive(row) { return row.audit_status === 'approved' && row.status !== 'active' && row.status !== 'archived' },
    isActiveApproved(row) { return row.status === 'active' && row.audit_status === 'approved' },
    canSetDefault(row) { return this.isActiveApproved(row) && !row.is_default && this.isEffective(row) },
    isEffective(row) { return !this.isExpired(row) && !this.isNotYetEffective(row) },
    isExpired(row) {
      if (!row.expire_date) return false
      return this.toDateOnly(row.expire_date) < this.today()
    },
    isNotYetEffective(row) {
      if (!row.effective_date) return false
      return this.toDateOnly(row.effective_date) > this.today()
    },
    today() {
      const d = new Date()
      d.setHours(0, 0, 0, 0)
      return d
    },
    toDateOnly(value) {
      const d = new Date(value)
      d.setHours(0, 0, 0, 0)
      return d
    },
    dateText(value) {
      if (!value) return '-'
      return String(value).slice(0, 10)
    },
    displayStatusType(row) {
      if (!row) return 'info'
      if (row.status === 'inactive') return 'info'
      if (this.isExpired(row)) return 'danger'
      if (this.isNotYetEffective(row)) return 'warning'
      if (this.isPendingAudit(row)) return 'warning'
      if (row.status === 'active') return 'success'
      return 'info'
    },
    displayAuditType(row) {
      if (!row) return 'info'
      if (row.audit_status === 'approved') return 'success'
      if (row.audit_status === 'rejected') return 'danger'
      if (row.audit_status === 'pending') return 'warning'
      return 'info'
    },
    openDetail(row, deleted = false) {
      if (!row || !row.id) return
      return this.openDetailById(row.id, deleted)
    },
    async openDetailById(id, deleted = false) {
      if (!id || (deleted && !this.$can('bom.manage.view'))) return
      this.detailDialog.visible = true
      this.detailDialog.loading = true
      this.detailDialog.activeTab = 'basic'
      this.detailDialog.data = null
      this.detailDialog.deleted = deleted
      try {
        const { data } = await getBom(id, deleted ? { deleted: 'only' } : undefined)
        this.detailDialog.data = data
      } catch (e) {
        this.$message.error(e.userMessage || '加载 BOM 详情失败')
        this.detailDialog.visible = false
      } finally {
        this.detailDialog.loading = false
      }
    },
    outputItemText(bom) {
      if (!bom || !bom.output_item) return '-'
      return [bom.output_item.item_code, bom.output_item.item_name, bom.output_item.spec].filter(Boolean).join(' ')
    },
    outputUnitText(bom) {
      if (!bom || !bom.output_item || !bom.output_item.unit) return '-'
      const u = bom.output_item.unit.standard_unit || bom.output_item.unit
      return `${u.unit_code || ''} ${u.unit_name || ''}`.trim() || '-'
    },
    cutRequirement(row) {
      if (cuttingQuantityBasis(row) === 'total_length') {
        const length = totalLengthMetres(row.total_length_mm ?? row.cutting_quantity_snapshot?.total_length_mm)
        return length ? `每份总用量 ${length} m` : '总用量未填写'
      }
      if (!row || row.cut_length_mm == null) return '无需下料'
      const dims = [row.cut_length_mm, row.cut_width_mm, row.cut_thickness_mm].filter(v => v != null).map(v => Number(v).toLocaleString('zh-CN')).join(' × ')
      return `${dims} mm × ${Number(row.piece_qty || 1).toLocaleString('zh-CN')}${row.cut_width_mm != null ? '件' : '段'}`
    },
    stockRequirement(row) {
      if (!row) return ''
      const stock = row.cutting_quantity_snapshot && row.cutting_quantity_snapshot.stock_dimensions
      if (!stock) return ''
      const dims = [stock.length_mm, stock.width_mm].filter(v => v != null).map(v => Number(v).toLocaleString('zh-CN')).join(' × ')
      return dims ? `原料标准 ${dims} mm` : ''
    },
    lineQuantity(row) {
      if (!row) return '-'
      const qty = row.cutting_quantity_snapshot ? row.cutting_quantity_snapshot.theoretical_qty : row.qty
      return qty == null ? '-' : Number(qty).toLocaleString('zh-CN', { maximumFractionDigits: 8 })
    },
    bomTypeText(v) {
      return ({ standard: '标准BOM', custom: '定制BOM', trial: '试制BOM' })[v] || v || '-'
    },
    actionText(v) {
      return ({ create: '新增', update: '编辑', submit: '提交审核', approve: '审核通过', reject: '驳回', activate: '启用', deactivate: '停用', set_default: '设默认', copy_version: '复制新版本', delete: '移入回收站', soft_delete: '移入回收站', restore: '恢复' })[v] || v
    },
    editFromDetail(bom) {
      if (this.detailDialog.deleted || !this.canEditBom(bom)) return
      this.detailDialog.visible = false
      this.$router.push(`/bom/${bom.id}/edit`)
    },
    expandFromDetail(bom) {
      if (this.detailDialog.deleted) return
      this.detailDialog.visible = false
      this.$router.push({ path: '/bom/expand', query: { bom_id: bom.id } })
    },
    async switchVersionInDialog(id) {
      if (this.detailDialog.deleted) return
      this.detailDialog.loading = true
      try {
        const { data } = await getBom(id)
        this.detailDialog.data = data
      } catch (e) {
        this.$message.error('切换版本失败')
      } finally {
        this.detailDialog.loading = false
      }
    },
    canAction(type) {
      const permission = {
        submit: 'bom.manage.edit',
        approve: 'bom.manage.edit',
        reject: 'bom.manage.edit',
        activate: 'bom.manage.toggle_status',
        deactivate: 'bom.manage.toggle_status',
        setDefault: 'bom.manage.toggle_status',
        delete: 'bom.manage.delete',
        restore: 'bom.manage.delete'
      }[type]
      return Boolean(permission) && this.$can(permission)
    },
    action(row, type) {
      if (!row || !this.canAction(type) || (row.deleted_at && type !== 'restore')) return
      if (type === 'delete' && !this.canDeleteBom(row)) return
      if (type === 'restore' && (!row.deleted_at || !this.$can('bom.manage.delete'))) return
      const text = {
        submit: '提交审核',
        approve: '审核通过',
        reject: '驳回',
        activate: '启用',
        deactivate: '停用',
        setDefault: '设为默认',
        delete: '移入回收站',
        restore: '恢复'
      }[type]
      this.opDialog = { visible: true, loading: false, row, type, text }
    },
    async confirmAction() {
      const { row, type, text } = this.opDialog
      if (!row || !this.canAction(type) || this.opDialog.loading) return
      const api = {
        submit: submitBom,
        approve: approveBom,
        reject: rejectBom,
        activate: activateBom,
        deactivate: deactivateBom,
        setDefault: setDefaultBom,
        delete: deleteBom,
        restore: restoreBom
      }[type]
      this.opDialog.loading = true
      try {
        await api(row.id)
        this.$message.success(`${text}成功`)
        this.opDialog.visible = false
        if (type === 'delete' || type === 'restore') {
          if (this.detailDialog.data && this.detailDialog.data.id === row.id) {
            this.detailDialog.visible = false
            this.detailDialog.data = null
          }
          if (this.deletedDialog.visible) await this.loadDeleted()
        }
        await this.load()
        if (this.detailDialog.visible && this.detailDialog.data && this.detailDialog.data.id === row.id) {
          const { data } = await getBom(row.id)
          this.detailDialog.data = data
        }
      } catch (error) {
        this.$message.error(error.userMessage || `${text}失败`)
      } finally {
        this.opDialog.loading = false
      }
    },
    copyVersion(row) {
      if (!row || row.deleted_at || !this.$can('bom.manage.create')) return
      this.versionDialog = { visible: true, loading: false, row, version: this.nextVersion(row.version) }
    },
    async confirmCopyVersion() {
      const { row, version } = this.versionDialog
      if (!row || !this.$can('bom.manage.create') || this.versionDialog.loading) return
      if (!version) return this.$message.error('请输入新版本号')
      this.versionDialog.loading = true
      try {
        const { data } = await copyBomVersion(row.id, { version })
        this.$message.success('已复制为新版本草稿')
        this.versionDialog.visible = false
        if (this.detailDialog.visible) {
          this.detailDialog.visible = false
        }
        this.$router.push(this.$can('bom.manage.edit') ? `/bom/${data.data.id}/edit` : { path: '/bom/boms', query: { view_id: data.data.id } })
      } catch (error) {
        this.$message.error(error.userMessage || '复制新版本失败')
      } finally {
        this.versionDialog.loading = false
      }
    },
    nextVersion(v) {
      const m = String(v || 'V1.0').match(/^(.*?)(\d+)$/)
      return m ? `${m[1]}${Number(m[2]) + 1}` : `${v}-2`
    }
  }
}
</script>

<style scoped>
.bom-list-page {
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
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  margin-bottom: 12px;
}
.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
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
  color: #1e293b;
  line-height: 1.2;
}
.head-tag {
  font-size: 12px;
  border-radius: 4px;
  height: 24px;
  line-height: 22px;
  padding: 0 8px;
}
.total-tag {
  background: #f0fdf4 !important;
  border-color: #bbf7d0 !important;
  color: #008b4b !important;
  font-weight: 600;
}
.filter-status-tag {
  background: #f1f5f9 !important;
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
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
.btn-default {
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #374151 !important;
}
.btn-default:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
  background: #f0fdf4 !important;
}
.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 全局统一提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  color: #008b4b;
  padding: 10px 14px;
  border-radius: 6px;
  font-size: 13px;
  margin-bottom: 14px;
}
.erp-page-tip i {
  font-size: 15px;
  flex-shrink: 0;
}

/* 顶部概览指标卡片 (100% 对齐主数据中心 / SkuList.vue 风格) */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 14px;
}
.metric-card {
  background: #ffffff;
  border-radius: 8px;
  padding: 14px 18px;
  display: flex;
  align-items: center;
  gap: 14px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  cursor: pointer;
  transition: all 0.2s ease;
  user-select: none;
}
.metric-card:hover {
  transform: translateY(-2px);
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.08);
}
.metric-card.active {
  border-color: #008b4b;
  background: #f0fdf4;
}
.metric-icon-box {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 22px;
  flex-shrink: 0;
}
.metric-all .metric-icon-box { background: #f1f5f9; color: #475569; }
.metric-active .metric-icon-box { background: #e8f5e9; color: #008b4b; }
.metric-pending .metric-icon-box { background: #fef3c7; color: #d97706; }
.metric-default .metric-icon-box { background: #e0f2fe; color: #0284c7; }

.metric-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.metric-label {
  font-size: 13px;
  color: #64748b;
}
.metric-val {
  font-size: 22px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.2;
}
.code-mono {
  font-family: Consolas, Monaco, "Courier New", monospace;
}
.fw-bold {
  font-weight: 700;
}

/* 表格与筛选卡片容器 */
.table-container-card {
  background: #ffffff;
  border-radius: 8px;
  padding: 16px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.05);
  border: 1px solid #e2e8f0;
}
.filter-toolbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 12px;
  padding-bottom: 16px;
  margin-bottom: 14px;
  border-bottom: 1px solid #f1f5f9;
}
.filter-fields {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.filter-input-search {
  width: 250px;
}
.filter-select-md {
  width: 170px;
}
.filter-select-xs {
  width: 120px;
}
.filter-date-picker {
  width: 260px;
}
.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
}
.btn-theme-submit {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.btn-theme-submit:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 表格样式 */
.bom-table ::v-deep th {
  background: #f8fafc !important;
  color: #334155 !important;
  font-weight: 600;
  font-size: 13px;
}
.item-code-chip {
  display: inline-block;
  padding: 2px 8px;
  background: #f1f5f9;
  color: #0f172a;
  border-radius: 4px;
  font-family: Consolas, Monaco, monospace;
  font-size: 12px;
  font-weight: 600;
  border: 1px solid #e2e8f0;
}
.bom-name-text {
  color: #0f172a;
  font-weight: 600;
}
.sku-cell-text,
.item-cell-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.sku-cell-text small,
.item-cell-text small {
  color: #64748b;
  font-size: 12px;
}
.version-tag {
  display: inline-block;
  padding: 1px 6px;
  background: #f1f5f9;
  color: #334155;
  border-radius: 3px;
  font-family: Consolas, monospace;
  font-size: 12px;
  font-weight: 600;
}
.default-badge {
  display: inline-block;
  padding: 2px 6px;
  border-radius: 4px;
  font-size: 12px;
  color: #64748b;
  background: #f1f5f9;
}
.default-badge.is_default {
  color: #008b4b;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  font-weight: 600;
}
.validity-text {
  font-size: 12px;
  color: #475569;
}
.text-muted {
  color: #94a3b8;
}

/* 状态与审核胶囊标签 */
.status-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
}
.status-pill.enabled {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}
.status-pill.disabled {
  background: #f1f5f9;
  color: #94a3b8;
  border: 1px solid #e2e8f0;
}
.status-pill.warning {
  background: #fffbeb;
  color: #d97706;
  border: 1px solid #fde68a;
}
.status-pill.danger {
  background: #fef2f2;
  color: #ef4444;
  border: 1px solid #fecaca;
}

.audit-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
}
.audit-pill.approved {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}
.audit-pill.pending {
  background: #fffbeb;
  color: #d97706;
  border: 1px solid #fde68a;
}
.audit-pill.rejected {
  background: #fef2f2;
  color: #ef4444;
  border: 1px solid #fecaca;
}
.audit-pill.draft {
  background: #f1f5f9;
  color: #64748b;
  border: 1px solid #e2e8f0;
}

/* 操作列链接 */
.row-action-links {
  display: flex;
  align-items: center;
  justify-content: center;
  flex-wrap: wrap;
  gap: 8px;
}
.theme-btn-link {
  color: #008b4b !important;
}
.theme-btn-link:hover {
  color: #00763f !important;
}
.danger-btn-link {
  color: #ef4444 !important;
}
.warning-btn-link {
  color: #d97706 !important;
}
.highlight-action {
  font-weight: 600;
}

/* 底部分页行 */
.pager-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: 14px;
  flex-wrap: wrap;
  gap: 12px;
}
.pager-total {
  font-size: 13px;
  color: #64748b;
}
.recycle-dialog-title {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  padding-right: 40px;
  color: #1e293b;
}
.recycle-dialog-title i {
  color: #64748b;
}
.recycle-filter-bar {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
  margin-bottom: 12px;
}
.recycle-filter-bar .el-input {
  flex: 0 1 320px;
  max-width: 100%;
  min-width: 0;
}
.recycle-filter-bar .el-button + .el-button {
  margin-left: 0;
}

/* 居中弹窗样式 (完全对齐 ERP 规范) */
.dialog-header-banner {
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 12px 16px;
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 18px;
}
.dialog-banner-icon {
  width: 36px;
  height: 36px;
  background: #dcfce7;
  color: #008b4b;
  border-radius: 6px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}
.dialog-banner-icon.warning {
  background: #fef3c7;
  color: #d97706;
}
.dialog-banner-text {
  display: flex;
  flex-direction: column;
  gap: 2px;
}
.dialog-banner-title {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}
.dialog-banner-desc {
  font-size: 12px;
  color: #008b4b;
}
.dialog-banner-icon.warning + .dialog-banner-text .dialog-banner-desc {
  color: #b45309;
}
.dialog-target-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 16px;
  margin-bottom: 14px;
}
.target-row {
  display: flex;
  align-items: center;
  padding: 4px 0;
  font-size: 13px;
}
.target-label {
  color: #64748b;
  width: 90px;
}
.target-val {
  color: #0f172a;
  font-weight: 600;
}

/* 响应式断点 */
@media (max-width: 1200px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}
@media (max-width: 768px) {
  .bom-list-page {
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
  .metric-overview-grid {
    grid-template-columns: 1fr;
  }
  .filter-input-search,
  .filter-select-md,
  .filter-select-xs,
  .filter-date-picker {
    width: 100%;
  }
}

/* BOM 详情弹窗定制 (2026-10-15 用户要求彻底弹窗化) */
.bom-detail-dialog :deep(.el-dialog__header) {
  padding: 0;
  border-bottom: 0;
}
.bom-detail-dialog :deep(.el-dialog__body) {
  padding: 0;
  background: #f8fafc;
}
.bom-detail-dialog :deep(.el-dialog__footer) {
  padding: 12px 20px;
  background: #fff;
  border-top: 1px solid #e2e8f0;
}
.bom-detail-dialog :deep(.dialog-header-banner) {
  background: #ffffff !important;
  padding: 16px 20px 14px;
  color: #0f172a;
  border-radius: 8px 8px 0 0;
  border-bottom: 1px solid #e2e8f0;
  margin-bottom: 0;
  display: flex;
  flex-direction: column;
  align-items: stretch;
  gap: 4px;
}
.bom-detail-dialog :deep(.dialog-main-title) {
  color: #0f172a;
  font-size: 16px;
  font-weight: 700;
  letter-spacing: -0.2px;
}
.bom-detail-dialog :deep(.dialog-subtitle) {
  color: #64748b;
  font-size: 12px;
  margin: 4px 0 0;
}
.bom-detail-dialog :deep(.banner-title-left) {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.bom-detail-dialog :deep(.banner-title-left i) {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: #ecfdf5;
  color: #008b4b;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  font-size: 15px;
  border: 1px solid #bbf7d0;
}
.bom-detail-dialog :deep(.dialog-bom-no) {
  background: #f1f5f9;
  color: #1e293b;
  border: 1px solid #cbd5e1;
  padding: 1px 7px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
}
.tag-bold {
  font-weight: 700;
}
.detail-actions-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  background: #fff;
  padding: 10px 20px;
  border-bottom: 1px solid #e2e8f0;
  flex-wrap: wrap;
}
.actions-hint {
  font-size: 12px;
  color: #64748b;
  display: flex;
  align-items: center;
  gap: 5px;
}
.actions-right {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}
.detail-tabs :deep(.el-tabs__header) {
  margin: 0;
  background: #fff;
  padding: 0 20px;
  border-bottom: 1px solid #e2e8f0;
}
.detail-tabs :deep(.el-tabs__item) {
  font-size: 13px;
  height: 40px;
  line-height: 40px;
}
.detail-tabs :deep(.el-tabs__item.is-active) {
  color: #008b4b;
  font-weight: 700;
}
.detail-tabs :deep(.el-tabs__active-bar) {
  background-color: #008b4b;
}
.detail-pane-content {
  padding: 16px 20px;
  max-height: 60vh;
  overflow-y: auto;
}
.detail-grid-section {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  margin-bottom: 14px;
}
.custom-section {
  background: #f0fdf4;
  border-color: #bbf7d0;
}
.section-badge-title {
  margin: 0 0 12px;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  display: flex;
  align-items: center;
  gap: 6px;
}
.section-badge-title i {
  color: #008b4b;
  font-size: 15px;
}
.info-grid-4 {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px 16px;
}
.info-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.info-k {
  font-size: 12px;
  color: #64748b;
}
.info-v {
  font-size: 13px;
  color: #1e293b;
  word-break: break-all;
}
.info-item.span-2 {
  grid-column: span 2;
}
.font-bold {
  font-weight: 600;
}
.unit-tag {
  color: #008b4b;
  font-weight: 600;
  background: #f0fdf4;
  border: 1px solid #dcfce7;
  padding: 1px 6px;
  border-radius: 4px;
  display: inline-block;
  width: fit-content;
}
.desc-box {
  background: #f8fafc;
  border-radius: 6px;
  padding: 10px 14px;
  font-size: 13px;
  color: #334155;
  line-height: 1.6;
}
.desc-box p {
  margin: 4px 0;
}
.items-head-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}
.items-count {
  font-size: 13px;
  color: #475569;
}
.items-count strong {
  color: #008b4b;
  font-size: 15px;
}
.detail-items-table {
  border-radius: 6px;
  overflow: hidden;
}
.detail-items-table :deep(th) {
  background: #f8fafc !important;
  color: #1e293b;
  font-weight: 700;
}
.line-badge {
  background: #e2e8f0;
  color: #334155;
  padding: 2px 6px;
  border-radius: 3px;
  font-size: 12px;
}
.cut-info-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}
.cut-main {
  font-size: 12px;
  color: #1e293b;
}
.cut-stock {
  font-size: 11px;
  color: #64748b;
}
.qty-highlight {
  color: #008b4b;
  font-size: 13px;
}
.version-log-layout {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 16px;
}
.version-section, .log-section {
  background: #fff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
}
.version-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}
.version-card-item {
  border: 1px solid #e2e8f0;
  border-left: 4px solid #94a3b8;
  border-radius: 6px;
  padding: 8px 12px;
  cursor: pointer;
  background: #f8fafc;
  transition: all 0.2s;
}
.version-card-item:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}
.version-card-item.current {
  border-left-color: #008b4b;
  background: #f0fdf4;
}
.version-card-item.readonly {
  cursor: default;
  pointer-events: none;
}
.version-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
}
.version-no {
  font-size: 12px;
  color: #475569;
}
.version-sub {
  font-size: 11px;
  color: #64748b;
  margin-top: 2px;
}
.dialog-log-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.log-item {
  border-left: 3px solid #008b4b;
  background: #f8fafc;
  border-radius: 0 6px 6px 0;
  padding: 8px 12px;
}
.log-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 4px;
}
.log-time {
  font-size: 11px;
  color: #94a3b8;
}
.log-msg {
  font-size: 12px;
  color: #334155;
  line-height: 1.5;
}
.empty-log {
  color: #94a3b8;
  font-size: 13px;
  text-align: center;
  padding: 24px 0;
}
@media (max-width: 900px) {
  .info-grid-4 {
    grid-template-columns: repeat(2, 1fr);
  }
  .version-log-layout {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 600px) {
  .info-grid-4 {
    grid-template-columns: 1fr;
  }
  .detail-actions-bar {
    flex-direction: column;
    align-items: flex-start;
  }
}
</style>

<style>
.bom-recycle-dialog {
  display: flex;
  flex-direction: column;
  max-width: calc(100vw - 32px);
  max-height: 85vh;
  box-sizing: border-box;
  overflow: hidden;
}
.bom-recycle-dialog .el-dialog__header,
.bom-recycle-dialog .el-dialog__footer {
  flex-shrink: 0;
}
.bom-recycle-dialog .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
}
.bom-recycle-dialog .el-table__empty-block {
  width: 100% !important;
  min-width: 0;
}
.bom-recycle-dialog .el-pagination {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  max-width: 100%;
  white-space: normal;
}
/* 针对 append-to-body 的 BOM 详情弹窗全局层样式 */
.bom-detail-dialog .el-dialog__header {
  padding: 0 !important;
  border-bottom: 0 !important;
}
.bom-detail-dialog .el-dialog__body {
  padding: 0 !important;
  background: #f8fafc !important;
}
.bom-detail-dialog .el-dialog__footer {
  padding: 12px 20px !important;
  background: #fff !important;
  border-top: 1px solid #e2e8f0 !important;
}
.bom-detail-dialog .dialog-header-banner {
  background: #ffffff !important;
  padding: 16px 20px 14px !important;
  color: #0f172a !important;
  border-radius: 8px 8px 0 0 !important;
  border-bottom: 1px solid #e2e8f0 !important;
  margin-bottom: 0 !important;
  display: flex !important;
  flex-direction: column !important;
  align-items: stretch !important;
  gap: 4px !important;
}
.bom-detail-dialog .banner-title-row {
  display: flex !important;
  align-items: center !important;
  justify-content: space-between !important;
}
.bom-detail-dialog .banner-title-left {
  display: flex !important;
  align-items: center !important;
  gap: 8px !important;
  flex-wrap: wrap !important;
}
.bom-detail-dialog .banner-title-left i {
  width: 28px !important;
  height: 28px !important;
  border-radius: 6px !important;
  background: #ecfdf5 !important;
  color: #008b4b !important;
  display: inline-flex !important;
  align-items: center !important;
  justify-content: center !important;
  font-size: 15px !important;
  border: 1px solid #bbf7d0 !important;
}
.bom-detail-dialog .dialog-main-title {
  color: #0f172a !important;
  font-size: 16px !important;
  font-weight: 700 !important;
  letter-spacing: -0.2px !important;
}
.bom-detail-dialog .dialog-bom-no {
  background: #f1f5f9 !important;
  color: #1e293b !important;
  border: 1px solid #cbd5e1 !important;
  padding: 1px 7px !important;
  border-radius: 4px !important;
  font-size: 12px !important;
  font-weight: 600 !important;
}
.bom-detail-dialog .dialog-subtitle {
  color: #64748b !important;
  font-size: 12px !important;
  margin: 4px 0 0 !important;
}

/* 详情弹窗与确认弹窗高对比度显眼关闭按钮 */
.bom-detail-dialog .el-dialog__headerbtn,
.erp-themed-dialog .el-dialog__headerbtn {
  top: 16px !important;
  right: 18px !important;
  width: 32px !important;
  height: 32px !important;
  border-radius: 6px !important;
  background: #f1f5f9 !important;
  border: 1px solid #cbd5e1 !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
  cursor: pointer !important;
  z-index: 10 !important;
}

.bom-detail-dialog .el-dialog__headerbtn .el-dialog__close,
.erp-themed-dialog .el-dialog__headerbtn .el-dialog__close {
  color: #334155 !important;
  font-size: 16px !important;
  font-weight: 700 !important;
  transition: color 0.2s !important;
}

.bom-detail-dialog .el-dialog__headerbtn:hover,
.erp-themed-dialog .el-dialog__headerbtn:hover {
  background: #fee2e2 !important;
  border-color: #fca5a5 !important;
}

.bom-detail-dialog .el-dialog__headerbtn:hover .el-dialog__close,
.erp-themed-dialog .el-dialog__headerbtn:hover .el-dialog__close {
  color: #dc2626 !important;
}
</style>
