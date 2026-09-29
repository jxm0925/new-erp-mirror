<template>
  <div class="archive-dictionary">
    <el-empty v-if="!canRead" description="暂无查看权限" />
    <template v-else>
      <!-- 概览指标卡片网格 -->
      <section class="metric-overview-grid">
        <!-- 分类模式指标卡片 -->
        <template v-if="kind === 'categories'">
          <div class="metric-card metric-all" :class="{ active: !query.status }" title="查看全部商品分类" @click="filterStatus('')">
            <div class="metric-icon-box"><i class="el-icon-price-tag" /></div>
            <div class="metric-info">
              <span class="metric-label">全部商品分类</span>
              <strong class="metric-val code-mono">{{ total }}</strong>
            </div>
          </div>
          <div class="metric-card metric-enabled" :class="{ active: query.status === 'enabled' }" title="筛选正常启用分类" @click="filterStatus('enabled')">
            <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
            <div class="metric-info">
              <span class="metric-label">正常启用分类</span>
              <strong class="metric-val code-mono text-success">{{ enabledCount }}</strong>
            </div>
          </div>
          <div class="metric-card metric-root" title="一级（根）分类总数">
            <div class="metric-icon-box"><i class="el-icon-folder" /></div>
            <div class="metric-info">
              <span class="metric-label">一级大类</span>
              <strong class="metric-val code-mono">{{ rootCount }}</strong>
            </div>
          </div>
          <div class="metric-card metric-sub" title="二级与下级分类总数">
            <div class="metric-icon-box"><i class="el-icon-document-copy" /></div>
            <div class="metric-info">
              <span class="metric-label">下级子分类</span>
              <strong class="metric-val code-mono">{{ subCount }}</strong>
            </div>
          </div>
        </template>

        <!-- 付款方式模式指标卡片 -->
        <template v-else-if="kind === 'payments'">
          <div class="metric-card metric-all" :class="{ active: !query.status && !query.usage }" title="查看全部付款结算方式" @click="filterStatus('')">
            <div class="metric-icon-box"><i class="el-icon-bank-card" /></div>
            <div class="metric-info">
              <span class="metric-label">全部结算方式</span>
              <strong class="metric-val code-mono">{{ total }}</strong>
            </div>
          </div>
          <div class="metric-card metric-enabled" :class="{ active: query.status === 'enabled' }" title="筛选正常启用结算方式" @click="filterStatus('enabled')">
            <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
            <div class="metric-info">
              <span class="metric-label">正常启用方式</span>
              <strong class="metric-val code-mono text-success">{{ enabledCount }}</strong>
            </div>
          </div>
          <div class="metric-card metric-sales" :class="{ active: query.usage === 'sales' }" title="筛选支持销售订单/收款的方式" @click="filterUsage('sales')">
            <div class="metric-icon-box"><i class="el-icon-money" /></div>
            <div class="metric-info">
              <span class="metric-label">支持销售/收款</span>
              <strong class="metric-val code-mono">{{ salesCount }}</strong>
            </div>
          </div>
          <div class="metric-card metric-payment" :class="{ active: query.usage === 'payment' }" title="筛选支持采购付款的方式" @click="filterUsage('payment')">
            <div class="metric-icon-box"><i class="el-icon-wallet" /></div>
            <div class="metric-info">
              <span class="metric-label">支持采购付款</span>
              <strong class="metric-val code-mono">{{ paymentCount }}</strong>
            </div>
          </div>
        </template>

        <!-- 成交平台模式指标卡片 -->
        <template v-else-if="kind === 'platforms'">
          <div class="metric-card metric-all" :class="{ active: !query.status }" title="查看全渠道成交平台" @click="filterStatus('')">
            <div class="metric-icon-box"><i class="el-icon-shopping-cart-2" /></div>
            <div class="metric-info">
              <span class="metric-label">全部成交平台</span>
              <strong class="metric-val code-mono">{{ total }}</strong>
            </div>
          </div>
          <div class="metric-card metric-enabled" :class="{ active: query.status === 'enabled' }" title="筛选正常启用平台" @click="filterStatus('enabled')">
            <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
            <div class="metric-info">
              <span class="metric-label">正常启用平台</span>
              <strong class="metric-val code-mono text-success">{{ enabledCount }}</strong>
            </div>
          </div>
          <div class="metric-card metric-root" title="独立主渠道/电商平台">
            <div class="metric-icon-box"><i class="el-icon-s-shop" /></div>
            <div class="metric-info">
              <span class="metric-label">主流平台体系</span>
              <strong class="metric-val code-mono">{{ rootCount }}</strong>
            </div>
          </div>
          <div class="metric-card metric-sub" title="下属子店铺/授权店铺">
            <div class="metric-icon-box"><i class="el-icon-office-building" /></div>
            <div class="metric-info">
              <span class="metric-label">下属店铺/分支</span>
              <strong class="metric-val code-mono">{{ subCount }}</strong>
            </div>
          </div>
        </template>
      </section>

      <!-- 筛选工具栏与表格卡片 -->
      <section class="table-container-card">
        <div class="filter-toolbar">
          <div class="filter-fields">
            <div class="filter-item">
              <span class="filter-label">关键字</span>
              <el-input
                v-model.trim="query.keyword"
                size="small"
                clearable
                prefix-icon="el-icon-search"
                :placeholder="kind === 'platforms' ? '平台名称 / 简称' : '编码 / 名称...'"
                @keyup.enter.native="search"
              />
            </div>
            <div class="filter-item">
              <span class="filter-label">状态</span>
              <el-select v-model="query.status" size="small" clearable placeholder="全部状态" @change="search">
                <el-option label="正常启用" value="enabled" />
                <el-option label="已停用" value="disabled" />
              </el-select>
            </div>
            <div v-if="kind === 'payments'" class="filter-item">
              <span class="filter-label">适用业务</span>
              <el-select v-model="query.usage" size="small" clearable placeholder="全部业务" @change="search">
                <el-option label="销售订单" value="sales" />
                <el-option label="收款" value="receipt" />
                <el-option label="付款" value="payment" />
              </el-select>
            </div>
          </div>
          <div class="filter-actions">
            <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="search">查询</el-button>
            <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
            <el-button v-if="canManage" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openCreate()">
              新增{{ label }}
            </el-button>
          </div>
        </div>

        <div class="table-wrap">
          <el-table ref="table" v-loading="loading" :data="rows" border size="small" row-key="id" class="enterprise-table">
            <el-table-column :prop="codeField" :label="kind === 'platforms' ? '平台编号' : '编码'" min-width="120">
              <template slot-scope="{ row }">
                <span class="code-mono archive-code-chip">{{ row[codeField] }}</span>
              </template>
            </el-table-column>

            <el-table-column :prop="nameField" :label="label + '名称'" min-width="150">
              <template slot-scope="{ row }">
                <span class="archive-name-text">{{ row[nameField] }}</span>
              </template>
            </el-table-column>

            <el-table-column v-if="kind !== 'payments'" label="所属层级" min-width="130">
              <template slot-scope="{ row }">
                <span v-if="isRoot(row)" class="root-badge"><i class="el-icon-folder" /> 一级（根）</span>
                <span v-else class="sub-badge"><i class="el-icon-caret-right" /> {{ parentName(row) }}</span>
              </template>
            </el-table-column>

            <el-table-column v-if="kind === 'platforms'" prop="short_name" label="简称" min-width="110">
              <template slot-scope="{ row }">
                <span class="short-badge">{{ row.short_name || '-' }}</span>
              </template>
            </el-table-column>

            <el-table-column v-if="kind === 'platforms'" prop="trade_type" label="平台类型" min-width="110">
              <template slot-scope="{ row }">
                <el-tag v-if="row.trade_type" size="mini" effect="plain">{{ row.trade_type }}</el-tag>
                <span v-else class="text-muted">-</span>
              </template>
            </el-table-column>

            <el-table-column v-if="kind === 'payments'" label="适用业务" min-width="210">
              <template slot-scope="{ row }">
                <div class="usage-tags-row">
                  <el-tag v-if="row.available_for_sales" size="mini" effect="plain" type="primary" class="usage-tag">销售订单</el-tag>
                  <el-tag v-if="row.available_for_receipt" size="mini" effect="plain" type="success" class="usage-tag">收款</el-tag>
                  <el-tag v-if="row.available_for_payment" size="mini" effect="plain" type="warning" class="usage-tag">付款</el-tag>
                  <span v-if="!row.available_for_sales && !row.available_for_receipt && !row.available_for_payment" class="text-muted text-xs">无适用</span>
                </div>
              </template>
            </el-table-column>

            <el-table-column label="状态" width="90" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="isEnabled(row) ? 'success' : 'info'" effect="light" class="status-tag">
                  <i :class="isEnabled(row) ? 'el-icon-circle-check' : 'el-icon-circle-close'" />
                  {{ isEnabled(row) ? '启用' : '停用' }}
                </el-tag>
              </template>
            </el-table-column>

            <el-table-column :prop="sortField" label="排序" width="70" align="center">
              <template slot-scope="{ row }">
                <span class="code-mono sort-val">{{ row[sortField] }}</span>
              </template>
            </el-table-column>

            <el-table-column v-if="kind !== 'platforms'" prop="remark" label="备注说明" min-width="140" show-overflow-tooltip>
              <template slot-scope="{ row }">
                <span class="remark-text">{{ row.remark || '-' }}</span>
              </template>
            </el-table-column>

            <el-table-column label="更新时间" min-width="140">
              <template slot-scope="{ row }">
                <span class="code-mono text-muted date-text">{{ dateText(row.updated_at) }}</span>
              </template>
            </el-table-column>

            <el-table-column v-if="canManage" label="操作" :width="kind === 'payments' ? 140 : 220" :fixed="compact ? false : 'right'" align="center">
              <template slot-scope="{ row }">
                <div class="row-actions">
                  <el-button v-if="canCreateChild(row)" type="text" size="mini" class="btn-action-child" icon="el-icon-plus" @click="openCreate(row)">新增下级</el-button>
                  <el-button type="text" size="mini" class="btn-action-edit" icon="el-icon-edit" @click="openEdit(row)">编辑</el-button>
                  <el-button
                    type="text"
                    size="mini"
                    :class="isEnabled(row) ? 'btn-action-disable' : 'btn-action-enable'"
                    :icon="isEnabled(row) ? 'el-icon-video-pause' : 'el-icon-video-play'"
                    @click="toggleStatus(row)"
                  >
                    {{ isEnabled(row) ? '停用' : '启用' }}
                  </el-button>
                  <el-button
                    v-if="kind === 'categories' && !isEnabled(row) && $can('master.base_archive.delete')"
                    type="text"
                    size="mini"
                    class="btn-action-delete"
                    icon="el-icon-delete"
                    @click="remove(row)"
                  >
                    删除
                  </el-button>
                </div>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <footer class="pager-row">
          <span class="total-info">共 <strong>{{ total }}</strong> 条记录</span>
          <el-pagination
            small
            background
            layout="prev, pager, next, sizes, jumper"
            :current-page.sync="query.page"
            :page-size.sync="query.per_page"
            :page-sizes="[10, 20, 50, 100]"
            :total="total"
            @current-change="load"
            @size-change="search"
          />
        </footer>
      </section>
    </template>

    <!-- 档案新增 / 编辑通用弹窗 -->
    <el-dialog
      :title="(form.id ? '编辑' : '新增') + label"
      :visible.sync="dialogVisible"
      width="620px"
      top="7vh"
      append-to-body
      :close-on-click-modal="false"
      :close-on-press-escape="!saving"
      :show-close="!saving"
      :before-close="beforeClose"
      custom-class="base-archive-dialog"
      @closed="clearValidation"
    >
      <div class="dialog-header-banner">
        <i :class="bannerIconClass" class="banner-icon" />
        <div class="banner-text">
          <p class="banner-title">{{ form.id ? '修改' + label + '基础属性' : '录入新' + label }}</p>
          <p class="banner-sub">请确保编码在全系统内唯一且符合主数据编码规范</p>
        </div>
      </div>

      <el-form ref="form" :model="form" label-position="top" size="small" :disabled="saving" class="archive-dialog-form" @submit.native.prevent>
        <div class="form-grid-2col">
          <el-form-item
            v-if="kind !== 'platforms'"
            :label="label + '编码'"
            :prop="codeField"
            :rules="[{ required: true, message: '请输入编码', trigger: 'blur' }]"
          >
            <el-input v-model.trim="form[codeField]" :disabled="!!form.id" :maxlength="kind === 'payments' ? 60 : 80" class="code-mono" placeholder="如 CLOTH、WECHAT" />
            <div v-if="form.id" class="field-help">创建后不可修改</div>
          </el-form-item>

          <el-form-item :label="label + '名称'" :prop="nameField" :rules="[{ required: true, message: '请输入名称', trigger: 'blur' }]">
            <el-input v-model.trim="form[nameField]" :maxlength="kind === 'platforms' ? 160 : 120" placeholder="请输入名称" />
          </el-form-item>
        </div>

        <div v-if="kind !== 'payments'" class="form-grid-2col">
          <el-form-item label="所属上级">
            <el-input :value="formParentName || '无（一级/主平台）'" disabled />
          </el-form-item>

          <el-form-item v-if="kind === 'platforms'" label="平台简称">
            <el-input v-model.trim="form.short_name" maxlength="160" placeholder="选填，如 JD、TB" />
          </el-form-item>
        </div>

        <div v-if="kind === 'platforms'" class="form-grid-2col">
          <el-form-item label="平台类型">
            <el-input v-model.trim="form.trade_type" maxlength="40" placeholder="选填，如 电商、线下门店、分销" />
          </el-form-item>
        </div>

        <template v-if="kind === 'payments'">
          <el-form-item label="适用业务范围" required class="full">
            <div class="checkbox-group-box">
              <el-checkbox v-model="form.available_for_sales">销售订单结算</el-checkbox>
              <el-checkbox v-model="form.available_for_receipt">财务日常收款</el-checkbox>
              <el-checkbox v-model="form.available_for_payment">采购款日常付款</el-checkbox>
            </div>
            <div class="field-help">请至少选择一项适用业务，单据结算时将据此过滤可用方式</div>
          </el-form-item>
        </template>

        <div class="form-grid-2col">
          <el-form-item label="显示排序">
            <el-input-number v-model="form[sortField]" :min="0" :max="9999" :precision="0" controls-position="right" class="full" />
            <div class="field-help">数值越小排序越靠前</div>
          </el-form-item>

          <el-form-item label="启用状态">
            <el-radio-group v-model="form.status" class="status-radio-group">
              <el-radio label="enabled">
                <span class="radio-label-text text-success"><i class="el-icon-circle-check" /> 正常启用</span>
              </el-radio>
              <el-radio label="disabled">
                <span class="radio-label-text text-muted"><i class="el-icon-circle-close" /> 停用</span>
              </el-radio>
            </el-radio-group>
          </el-form-item>
        </div>

        <el-form-item v-if="kind !== 'platforms'" label="备注说明" class="full">
          <el-input v-model="form.remark" type="textarea" :rows="3" :maxlength="kind === 'payments' ? 1000 : 200" show-word-limit placeholder="选填，补充说明该档案业务场景或约束..." />
        </el-form-item>
      </el-form>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" :disabled="saving" @click="dialogVisible = false">取消</el-button>
        <el-button type="success" size="small" :loading="saving" class="btn-theme-create" @click="save">保存档案</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import { listArchives, saveArchive, setArchiveStatus, deleteProductCategory } from '../../../api/erp/base-archives'

const labels = { categories: '商品分类', payments: '付款方式', platforms: '成交平台' }

export default {
  name: 'ArchiveDictionary',
  props: {
    kind: { type: String, required: true },
    active: { type: Boolean, default: true }
  },
  data () {
    return {
      rows: [],
      total: 0,
      stats: {},
      loading: false,
      saving: false,
      dialogVisible: false,
      form: {},
      formParentName: '',
      query: { keyword: '', status: '', usage: '', page: 1, per_page: 20 },
      loadSequence: 0,
      compact: window.innerWidth <= 900
    }
  },
  computed: {
    label () { return labels[this.kind] },
    codeField () { return this.kind === 'payments' ? 'method_code' : this.kind === 'categories' ? 'category_code' : 'legacy_id' },
    nameField () { return this.kind === 'payments' ? 'method_name' : this.kind === 'categories' ? 'category_name' : 'name' },
    sortField () { return this.kind === 'categories' ? 'sort_order' : 'sort' },
    canManage () { return this.$can(this.kind === 'payments' ? 'finance.payment_method.manage' : 'master.base_archive') },
    canRead () { return this.canManage || (this.kind === 'payments' && this.$can('finance.payment_method.view')) },
    bannerIconClass () {
      if (this.kind === 'categories') return 'el-icon-price-tag'
      if (this.kind === 'payments') return 'el-icon-bank-card'
      return 'el-icon-shopping-cart-2'
    },
    enabledCount () {
      return Number(this.stats.enabled || 0)
    },
    rootCount () {
      return Number(this.stats.root || 0)
    },
    subCount () {
      return Number(this.stats.sub || 0)
    },
    salesCount () {
      return Number(this.stats.sales || 0)
    },
    paymentCount () {
      return Number(this.stats.payment || 0)
    }
  },
  created () {
    if (this.canRead) this.load()
  },
  watch: {
    active (value) {
      if (value) this.layoutTable()
    }
  },
  mounted () {
    window.addEventListener('resize', this.resizeTable)
  },
  beforeDestroy () {
    window.removeEventListener('resize', this.resizeTable)
  },
  methods: {
    resizeTable () {
      this.compact = window.innerWidth <= 900
      if (this.active) this.layoutTable()
    },
    layoutTable () {
      this.$nextTick(() => {
        if (this.$refs.table) this.$refs.table.doLayout()
      })
    },
    async load () {
      const sequence = ++this.loadSequence
      this.loading = true
      try {
        const { data } = await listArchives(this.kind, { ...this.query, include_stats: 1 })
        if (sequence !== this.loadSequence) return
        this.rows = data.data || []
        this.total = Number(data.total || 0)
        this.stats = data.stats || {}
        this.$emit('count-updated', this.total)
        if (!this.rows.length && this.query.page > 1 && this.total <= (this.query.page - 1) * this.query.per_page) {
          this.query.page = Math.max(1, Math.ceil(this.total / this.query.per_page))
          return this.load()
        }
      } catch (e) {
        if (sequence === this.loadSequence) this.$message.error(e.userMessage || this.label + '加载失败')
      } finally {
        if (sequence === this.loadSequence) this.loading = false
      }
    },
    search () {
      this.query.page = 1
      this.load()
    },
    reset () {
      this.query = { keyword: '', status: '', usage: '', page: 1, per_page: this.query.per_page }
      this.load()
    },
    filterStatus (status) {
      this.query.status = status
      this.search()
    },
    filterUsage (usage) {
      this.query.usage = usage
      this.search()
    },
    isEnabled (row) {
      return this.kind === 'platforms' ? !!row.enabled : row.status === 'enabled'
    },
    isRoot (row) {
      if (this.kind === 'categories') return !row.parent_id
      return !Number(row.parent_legacy_id)
    },
    parentName (row) {
      return this.kind === 'categories'
        ? (row.parent && row.parent.category_name) || '无（一级）'
        : row.parent_name || '无（主平台）'
    },
    canCreateChild (row) {
      return this.kind !== 'payments' && this.isEnabled(row) && (this.kind === 'categories' || !Number(row.parent_legacy_id))
    },
    dateText (value) {
      return value ? String(value).replace('T', ' ').slice(0, 16) : '—'
    },
    openCreate (parent) {
      this.form = {
        [this.codeField]: '',
        [this.nameField]: '',
        [this.sortField]: 0,
        status: 'enabled',
        remark: '',
        parent_id: parent ? parent.id : null,
        parent_legacy_id: parent ? parent.legacy_id : 0,
        short_name: '',
        trade_type: '',
        available_for_sales: true,
        available_for_receipt: true,
        available_for_payment: true
      }
      if (this.kind === 'platforms') this.form.client_request_id = window.crypto.randomUUID()
      this.formParentName = parent ? parent[this.nameField] : ''
      this.dialogVisible = true
      this.clearValidation()
    },
    openEdit (row) {
      this.form = {
        ...row,
        status: this.isEnabled(row) ? 'enabled' : 'disabled',
        available_for_sales: !!row.available_for_sales,
        available_for_receipt: !!row.available_for_receipt,
        available_for_payment: !!row.available_for_payment
      }
      this.formParentName = this.parentName(row)
      this.dialogVisible = true
      this.clearValidation()
    },
    clearValidation () {
      this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate())
    },
    beforeClose (done) {
      if (!this.saving) done()
    },
    save () {
      if (this.saving || !this.canManage) return
      this.$refs.form.validate(async valid => {
        if (!valid) return
        if (this.kind === 'payments' && !this.form.available_for_sales && !this.form.available_for_receipt && !this.form.available_for_payment) {
          return this.$message.error('请至少选择一个适用业务')
        }
        const f = this.form
        let payload
        if (this.kind === 'categories') {
          payload = {
            id: f.id,
            category_code: f.category_code,
            category_name: f.category_name,
            category_type: 'product',
            parent_id: f.parent_id || null,
            sort_order: f.sort_order,
            status: f.status,
            remark: f.remark
          }
        } else if (this.kind === 'payments') {
          payload = {
            id: f.id,
            method_name: f.method_name,
            available_for_sales: f.available_for_sales,
            available_for_receipt: f.available_for_receipt,
            available_for_payment: f.available_for_payment,
            status: f.status,
            sort: f.sort,
            remark: f.remark
          }
          if (f.id) payload.expected_version = f.business_version
          else payload.method_code = f.method_code
        } else {
          payload = {
            id: f.id,
            name: f.name,
            short_name: f.short_name,
            trade_type: f.trade_type,
            parent_legacy_id: Number(f.parent_legacy_id || 0),
            sort: f.sort,
            status: f.status
          }
          if (f.id) payload.expected_version = f.business_version
          else payload.client_request_id = f.client_request_id
        }
        this.saving = true
        try {
          await saveArchive(this.kind, payload)
          this.dialogVisible = false
          this.$message.success(this.label + '已保存')
          await this.load()
        } catch (e) {
          this.$message.error(e.userMessage || '保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    async toggleStatus (row) {
      const enabled = !this.isEnabled(row)
      try {
        await this.$confirm(`确认${enabled ? '启用' : '停用'}“${row[this.nameField]}”？`, this.label, { type: 'warning' })
        await setArchiveStatus(this.kind, row, enabled)
        this.$message.success('状态已更新')
        await this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '状态更新失败')
      }
    },
    async remove (row) {
      try {
        await this.$confirm(`确认删除商品分类“${row.category_name}”？已被引用或仍有下级的分类不能删除。`, '删除商品分类', { type: 'warning' })
        await deleteProductCategory(row.id)
        this.$message.success('商品分类已删除')
        await this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '删除失败')
      }
    }
  }
}
</script>

<style scoped>
.archive-dictionary {
  min-width: 0;
  color: #1f2937;
}

/* 指标卡片网格 */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}

.metric-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  display: flex;
  align-items: center;
  gap: 14px;
  cursor: pointer;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1);
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}

.metric-card:hover {
  transform: translateY(-1px);
  border-color: #cbd5e1;
  box-shadow: 0 4px 8px rgba(0, 0, 0, 0.05);
}

.metric-card.active {
  border-color: #86efac;
  background: #f0fdf4;
}

.metric-icon-box {
  width: 40px;
  height: 40px;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 19px;
  flex-shrink: 0;
}

.metric-all .metric-icon-box {
  background: #eff6ff;
  color: #2563eb;
}

.metric-enabled .metric-icon-box {
  background: #f0fdf4;
  color: #008b4b;
}

.metric-root .metric-icon-box {
  background: #fefce8;
  color: #ca8a04;
}

.metric-sub .metric-icon-box {
  background: #f5f3ff;
  color: #7c3aed;
}

.metric-sales .metric-icon-box {
  background: #ecfeff;
  color: #0891b2;
}

.metric-payment .metric-icon-box {
  background: #fff7ed;
  color: #ea580c;
}

.metric-info {
  display: flex;
  flex-direction: column;
  gap: 3px;
  min-width: 0;
}

.metric-label {
  font-size: 12px;
  color: #64748b;
}

.metric-val {
  font-size: 20px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.text-success {
  color: #008b4b !important;
}

/* 筛选与表格卡片 */
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

/* 主题按钮 */
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
  padding: 9px 0;
}

.enterprise-table ::v-deep td {
  padding: 8px 0;
  font-size: 13px;
  color: #1e293b;
}

.code-mono {
  font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, Courier, monospace;
  font-variant-numeric: tabular-nums;
}

.archive-code-chip {
  display: inline-block;
  padding: 2px 7px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  color: #0f172a;
  font-size: 12px;
  font-weight: 600;
}

.archive-name-text {
  font-weight: 600;
  color: #0f172a;
}

.root-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 1px 7px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  color: #64748b;
  font-size: 12px;
}

.sub-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  color: #334155;
  font-size: 12px;
}

.sub-badge i {
  color: #94a3b8;
  font-size: 11px;
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

.usage-tags-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
}

.usage-tag {
  border-radius: 4px;
  font-size: 11px;
}

.sort-val {
  color: #64748b;
  font-size: 12px;
}

.remark-text {
  color: #64748b;
  font-size: 12px;
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

.status-tag {
  border-radius: 4px;
  font-size: 12px;
  display: inline-flex;
  align-items: center;
  gap: 3px;
}

/* 操作按钮 */
.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
}

.btn-action-child {
  color: #008b4b !important;
  font-weight: 500;
  padding: 0 !important;
}

.btn-action-child:hover {
  color: #00763f !important;
}

.btn-action-edit {
  color: #2563eb !important;
  font-weight: 500;
  padding: 0 !important;
}

.btn-action-edit:hover {
  color: #1d4ed8 !important;
}

.btn-action-disable {
  color: #ca8a04 !important;
  font-weight: 500;
  padding: 0 !important;
}

.btn-action-disable:hover {
  color: #a16207 !important;
}

.btn-action-enable {
  color: #008b4b !important;
  font-weight: 500;
  padding: 0 !important;
}

.btn-action-enable:hover {
  color: #00763f !important;
}

.btn-action-delete {
  color: #ef4444 !important;
  font-weight: 500;
  padding: 0 !important;
}

.btn-action-delete:hover {
  color: #dc2626 !important;
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

/* 弹窗设计 */
.dialog-header-banner {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 12px 14px;
  margin-bottom: 16px;
}

.banner-icon {
  font-size: 20px;
  color: #008b4b;
  margin-top: 2px;
  flex-shrink: 0;
}

.banner-title {
  margin: 0 0 3px;
  font-size: 13px;
  font-weight: 600;
  color: #166534;
}

.banner-sub {
  margin: 0;
  font-size: 12px;
  color: #15803d;
  line-height: 1.4;
}

.archive-dialog-form {
  padding: 4px 2px;
}

.form-grid-2col {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 16px;
}

.full {
  width: 100%;
}

.field-help {
  margin-top: 3px;
  color: #94a3b8;
  font-size: 11px;
  line-height: 1.3;
}

.checkbox-group-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 14px;
  display: flex;
  flex-wrap: wrap;
  gap: 18px;
}

.status-radio-group {
  height: 32px;
  display: flex;
  align-items: center;
  gap: 16px;
}

.radio-label-text {
  font-size: 13px;
  display: inline-flex;
  align-items: center;
  gap: 4px;
}

.dialog-footer {
  display: flex;
  justify-content: flex-end;
  gap: 10px;
}

/* 响应式断点适配 */
@media (max-width: 900px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 600px) {
  .metric-overview-grid {
    grid-template-columns: 1fr;
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
    flex: 1;
  }

  .filter-actions {
    margin-left: 0;
    justify-content: flex-end;
    width: 100%;
  }

  .form-grid-2col {
    grid-template-columns: 1fr;
    gap: 0;
  }

  .pager-row {
    flex-direction: column;
    align-items: stretch;
  }
}
</style>

<style>
.base-archive-dialog {
  max-width: calc(100vw - 32px);
  border-radius: 8px;
}

.base-archive-dialog .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #e2e8f0;
}

.base-archive-dialog .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.base-archive-dialog .el-dialog__body {
  max-height: calc(88vh - 132px);
  overflow-y: auto;
  padding: 16px 20px;
}

.base-archive-dialog .el-dialog__footer {
  border-top: 1px solid #e2e8f0;
  padding: 12px 20px;
}

.base-archive-dialog .el-form-item__label {
  font-weight: 600;
  color: #334155;
  padding-bottom: 4px;
}

.base-archive-dialog .el-form-item {
  margin-bottom: 16px;
}
</style>
