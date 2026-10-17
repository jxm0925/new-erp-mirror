<template>
  <div class="number-rule-page">
    <!-- 页面全局头部卡片：白底圆角独立卡片 (100% 对齐主数据中心 / SkuList.vue 风格) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-postcard" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">编号规则</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag total-tag">
              共 {{ (stats.total || total).toLocaleString() }} 款规则
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
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="load">刷新</el-button>
        <el-button
          v-if="$can('document_number_rule.manage')"
          size="small"
          type="success"
          icon="el-icon-plus"
          class="btn-theme-create"
          @click="openCreate"
        >
          新增规则
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>统一配置各业务对象的编号前缀、日期维度与流水号规则。规则调整即刻生效并仅影响后续新生成单号，不修改历史已有单据编号。</span>
    </div>

    <!-- 顶部概览指标卡片 (100% 对齐主数据中心 / SkuList.vue 风格) -->
    <section class="metric-overview-grid">
      <div
        class="metric-card metric-all"
        :class="{ active: !query.enabled && !query.document_type }"
        title="点击查看全部编号规则"
        @click="filterAll"
      >
        <div class="metric-icon-box"><i class="el-icon-postcard" /></div>
        <div class="metric-info">
          <span class="metric-label">全部编号规则</span>
          <strong class="metric-val code-mono">{{ (stats.total || total).toLocaleString() }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-enabled"
        :class="{ active: query.enabled === '1' }"
        title="点击筛选正常启用规则"
        @click="filterStatus('1')"
      >
        <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
        <div class="metric-info">
          <span class="metric-label">正常启用规则</span>
          <strong class="metric-val code-mono">{{ enabledCount.toLocaleString() }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-disabled metric-draft"
        :class="{ active: query.enabled === '0' }"
        title="点击筛选已停用规则"
        @click="filterStatus('0')"
      >
        <div class="metric-icon-box"><i class="el-icon-circle-close" /></div>
        <div class="metric-info">
          <span class="metric-label">已停用规则</span>
          <strong class="metric-val code-mono">{{ disabledCount.toLocaleString() }}</strong>
        </div>
      </div>

      <div
        class="metric-card metric-types"
        title="系统支持的业务单据类型覆盖情况"
      >
        <div class="metric-icon-box"><i class="el-icon-collection" /></div>
        <div class="metric-info">
          <span class="metric-label">已配置业务类型</span>
          <strong class="metric-val code-mono">{{ configuredTypesCount }} <span class="metric-sub-count">/ {{ types.length }}</span></strong>
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
            placeholder="搜索规则名称、编号前缀..."
            class="filter-input-search"
            @keyup.enter.native="search"
            @clear="search"
          />
          <el-select
            v-model="query.document_type"
            size="small"
            clearable
            filterable
            placeholder="业务类型"
            class="filter-select-md"
            @change="search"
          >
            <el-option
              v-for="type in types"
              :key="type.code"
              :label="`${type.label} (${type.code})`"
              :value="type.code"
            />
          </el-select>
          <el-select
            v-model="query.enabled"
            size="small"
            clearable
            placeholder="启用状态"
            class="filter-select-xs"
            @change="search"
          >
            <el-option label="全部状态" value="" />
            <el-option label="已启用" value="1" />
            <el-option label="已停用" value="0" />
          </el-select>
        </div>

        <div class="filter-actions">
          <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="search">查询</el-button>
          <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
          <el-button size="small" icon="el-icon-refresh" circle title="刷新列表" @click="load" />
        </div>
      </div>

      <!-- 表格主体 -->
      <div class="main-table-wrap">
        <el-table
          v-loading="loading"
          :data="rows"
          border
          size="small"
          class="custom-item-table"
          empty-text="暂无编号规则数据，请点击右上角“新增规则”创建。"
        >
          <el-table-column label="业务类型" min-width="165">
            <template slot-scope="{ row }">
              <div class="type-name-cell">
                <span class="type-name-text">{{ row.business_type_label }}</span>
                <span class="type-code-sub code-mono">{{ row.document_type }}</span>
              </div>
            </template>
          </el-table-column>

          <el-table-column prop="name" label="规则名称" min-width="165" show-overflow-tooltip>
            <template slot-scope="{ row }">
              <span class="rule-name-text">{{ row.name }}</span>
            </template>
          </el-table-column>

          <el-table-column label="编号前缀" width="115" align="center">
            <template slot-scope="{ row }">
              <span class="item-code-chip code-mono">
                <i class="el-icon-postcard" /> {{ row.prefix }}
              </span>
            </template>
          </el-table-column>

          <el-table-column label="日期格式" width="115" align="center">
            <template slot-scope="{ row }">
              <span class="type-badge code-mono">{{ dateLabel(row.date_format) }}</span>
            </template>
          </el-table-column>

          <el-table-column label="流水位数" width="90" align="center">
            <template slot-scope="{ row }">
              <span class="unit-badge code-mono">{{ row.sequence_length }}位</span>
            </template>
          </el-table-column>

          <el-table-column label="重置周期" width="100" align="center">
            <template slot-scope="{ row }">
              <el-tag size="mini" :type="cycleTagType(row.reset_cycle)" effect="plain" class="cycle-tag">
                {{ cycleLabel(row.reset_cycle) }}
              </el-tag>
            </template>
          </el-table-column>

          <el-table-column label="当前流水" width="95" align="right">
            <template slot-scope="{ row }">
              <span class="font-tabular stat-count" :class="{ 'has-val': Number(row.current_sequence || 0) > 0 }">
                {{ Number(row.current_sequence || 0).toLocaleString() }}
              </span>
            </template>
          </el-table-column>

          <el-table-column label="格式示例" min-width="190">
            <template slot-scope="{ row }">
              <span
                class="example-code-pill code-mono"
                title="点击复制示例单号"
                @click="copyExample(row.format_example)"
              >
                <i class="el-icon-document-copy copy-icon" />
                {{ row.format_example }}
              </span>
            </template>
          </el-table-column>

          <el-table-column label="状态" width="80" align="center">
            <template slot-scope="{ row }">
              <span class="status-pill" :class="row.enabled ? 'enabled' : 'disabled'">
                {{ row.enabled ? '启用' : '停用' }}
              </span>
            </template>
          </el-table-column>

          <el-table-column label="操作" width="130" fixed="right" align="center">
            <template slot-scope="{ row }">
              <el-button
                v-if="$can('document_number_rule.manage')"
                type="text"
                size="small"
                icon="el-icon-edit"
                class="action-link-theme"
                @click="openEdit(row)"
              >
                编辑
              </el-button>
              <el-button
                v-if="$can('document_number_rule.manage')"
                type="text"
                size="small"
                :class="row.enabled ? 'danger-link' : 'success-link'"
                @click="row.enabled ? openDisable(row) : enable(row)"
              >
                {{ row.enabled ? '停用' : '启用' }}
              </el-button>
            </template>
          </el-table-column>
        </el-table>
      </div>

      <!-- 表格底部分页栏 -->
      <div class="table-pagination-footer">
        <span class="total-text">共 <strong>{{ (stats.total || total).toLocaleString() }}</strong> 条规则</span>
        <el-pagination
          background
          layout="total, sizes, prev, pager, next, jumper"
          :total="total"
          :current-page.sync="page"
          :page-size.sync="perPage"
          :page-sizes="[10, 20, 50, 100]"
          @current-change="load"
          @size-change="changeSize"
        />
      </div>
    </section>

    <!-- 规则新增 / 编辑居中弹窗 -->
    <el-dialog
      :title="editing ? '编辑编号规则' : '新增编号规则'"
      :visible.sync="dialogVisible"
      width="680px"
      top="6vh"
      append-to-body
      :close-on-click-modal="false"
      :close-on-press-escape="!saving"
      :show-close="!saving"
      custom-class="base-archive-dialog number-rule-dialog"
      @closed="clearForm"
    >
      <!-- 弹窗顶栏横幅 -->
      <div class="dialog-header-banner">
        <i class="el-icon-postcard banner-icon" />
        <div class="banner-text">
          <p class="banner-title">{{ editing ? '修改单据编号生成规则' : '创建业务单据编号规则' }}</p>
          <p class="banner-sub">
            {{ editing ? `当前配置业务：${form.document_type}。规则保存后对后续新开单据生效，不修改历史编号。` : '为指定业务对象配置编码前缀、时间序列与流水号规则。' }}
          </p>
        </div>
      </div>

      <div class="dialog-notice-tip">
        <i class="el-icon-warning-outline" />
        <span>规则修改只影响后续新生成编号，不修改历史已有单据编号和已预留编号。</span>
      </div>

      <el-form
        ref="ruleForm"
        :model="form"
        :rules="formRules"
        :disabled="saving"
        label-position="top"
        size="small"
        class="rule-dialog-form"
      >
        <!-- 分块一：业务与基础定义 -->
        <div class="form-section-card">
          <div class="section-title"><i class="el-icon-info" /> 业务与基础属性</div>
          <div class="form-grid-2col">
            <el-form-item label="业务类型" prop="document_type">
              <el-select
                v-model="form.document_type"
                :disabled="editing"
                filterable
                placeholder="请选择业务类型"
                class="full-width"
              >
                <el-option
                  v-for="type in types"
                  :key="type.code"
                  :label="`${type.label} (${type.code})`"
                  :value="type.code"
                  :disabled="!editing && type.configured"
                />
              </el-select>
              <div v-if="!editing && types.length && types.every(type => type.configured)" class="field-help text-warning">
                当前固定业务类型均已配置；新增业务类型需先纳入固定字典。
              </div>
              <div v-else-if="!editing" class="field-help">已被其他规则配置的业务类型将置灰不可选</div>
              <div v-else class="field-help">业务类型在创建后固定，不可修改</div>
            </el-form-item>

            <el-form-item label="编号前缀" prop="prefix">
              <el-input
                v-model.trim="form.prefix"
                maxlength="20"
                placeholder="如 PO、SO、WO、PR"
                class="code-mono"
                @input="form.prefix = form.prefix.toUpperCase()"
              />
              <div class="field-help">必须以字母开头，支持大写字母、数字和短横线</div>
            </el-form-item>
          </div>

          <el-form-item label="规则名称" prop="name">
            <el-input
              v-model.trim="form.name"
              maxlength="120"
              show-word-limit
              placeholder="请输入规则名称，如 采购订单标准编号规则"
            />
          </el-form-item>
        </div>

        <!-- 分块二：编码构成规则 -->
        <div class="form-section-card">
          <div class="section-title"><i class="el-icon-setting" /> 编号构成与重置逻辑</div>
          <div class="form-grid-3col">
            <el-form-item label="日期格式" prop="date_format">
              <el-select v-model="form.date_format" class="full-width" @change="syncCycle">
                <el-option label="无日期" value="none" />
                <el-option label="YYYY (年)" value="YYYY" />
                <el-option label="YYYYMM (年月)" value="YYYYMM" />
                <el-option label="YYYYMMDD (年月日)" value="YYYYMMDD" />
              </el-select>
              <div class="field-help">切换将自动联动重置周期</div>
            </el-form-item>

            <el-form-item label="流水号位数" prop="sequence_length">
              <el-input-number
                v-model="form.sequence_length"
                :min="1"
                :max="12"
                controls-position="right"
                class="full-width"
              />
              <div class="field-help">1–12位，不足自动前补0</div>
            </el-form-item>

            <el-form-item label="重置周期" prop="reset_cycle">
              <el-select v-model="form.reset_cycle" class="full-width">
                <el-option label="不重置" value="none" />
                <el-option label="每年重置" value="yearly" />
                <el-option label="每月重置" value="monthly" />
                <el-option label="每日重置" value="daily" />
              </el-select>
              <div class="field-help">按周期归零从1重新递增</div>
            </el-form-item>
          </div>
        </div>

        <!-- 分块三：实时仿真预览与状态 -->
        <div class="form-section-card preview-section-card">
          <div class="section-title"><i class="el-icon-view" /> 编号生成仿真预览</div>
          
          <div class="example-preview-box">
            <div class="preview-header-row">
              <span class="preview-title-tag"><i class="el-icon-postcard" /> 最终单号样式预览</span>
              <span class="preview-note">基于当前规则即时计算，不推进流水、不占用单号</span>
            </div>
            <div class="preview-display-text code-mono">
              {{ formatExample || '—' }}
            </div>
            <div class="preview-breakdown-bar">
              <div class="breakdown-item">
                <span class="bd-label">前缀</span>
                <span class="bd-val code-mono">{{ form.prefix || '空' }}</span>
              </div>
              <div class="bd-divider">+</div>
              <div class="breakdown-item">
                <span class="bd-label">日期部分</span>
                <span class="bd-val code-mono">{{ previewDatePart }}</span>
              </div>
              <div class="bd-divider">+</div>
              <div class="breakdown-item">
                <span class="bd-label">流水位数 ({{ form.sequence_length || 0 }}位)</span>
                <span class="bd-val code-mono">{{ previewSequencePart }}</span>
              </div>
            </div>
          </div>

          <div class="status-config-row">
            <span class="status-config-label">启用状态：</span>
            <el-radio-group v-model="form.enabled" class="status-radio-group">
              <el-radio :label="true">
                <span class="text-success"><i class="el-icon-circle-check" /> 正常启用</span>
              </el-radio>
              <el-radio :label="false">
                <span class="text-muted"><i class="el-icon-circle-close" /> 停用规则</span>
              </el-radio>
            </el-radio-group>
          </div>
          <div v-if="!form.enabled" class="status-warning-tip text-warning">
            <i class="el-icon-warning" /> 停用后，对应业务新增页面将无法自动生成新编号；历史编号和已有预留不受影响。
          </div>
        </div>

        <!-- 分块四：修改原因（编辑时） -->
        <div v-if="editing" class="form-section-card edit-reason-card">
          <div class="section-title"><i class="el-icon-document" /> 变更审计记录</div>
          <el-form-item
            label="修改原因"
            prop="change_reason"
            :class="{ 'is-error': reasonError }"
          >
            <el-input
              v-model.trim="form.change_reason"
              type="textarea"
              :rows="2"
              maxlength="200"
              show-word-limit
              placeholder="请输入本次规则修改的原因与业务背景（如：业务扩展调整流水号长度）"
              @input="reasonError = ''"
            />
            <div v-if="reasonError" class="reason-error-msg">{{ reasonError }}</div>
            <div class="field-help">规则实际发生变更时必填，保存后将写入企业公共操作审计日志。</div>
          </el-form-item>
        </div>
      </el-form>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" :disabled="saving" @click="dialogVisible = false">取消</el-button>
        <el-button
          size="small"
          type="success"
          :loading="saving"
          class="btn-theme-create"
          @click="save"
        >
          保存规则
        </el-button>
      </span>
    </el-dialog>

    <!-- 停用规则确认弹窗 -->
    <el-dialog
      :visible.sync="disableVisible"
      width="480px"
      top="20vh"
      append-to-body
      custom-class="disable-dialog"
      :close-on-click-modal="false"
      :show-close="false"
    >
      <div slot="title" class="disable-dialog-title">
        <i class="el-icon-warning" />
        <span>确认停用编号规则？</span>
      </div>

      <div class="disable-body">
        <dl v-if="disableTarget" class="disable-info-grid">
          <dt>业务类型</dt>
          <dd><strong>{{ disableTarget.business_type_label }}</strong> <span class="code-mono text-muted">({{ disableTarget.document_type }})</span></dd>
          <dt>规则名称</dt>
          <dd>{{ disableTarget.name }}</dd>
          <dt>编号前缀</dt>
          <dd><span class="item-code-chip code-mono"><i class="el-icon-postcard" /> {{ disableTarget.prefix }}</span></dd>
        </dl>

        <div class="disable-warn-card">
          <i class="el-icon-warning" />
          <div class="warn-text">
            <p class="warn-head">停用影响说明</p>
            <p class="warn-desc">停用后，对应业务的新增页面将无法自动生成新编号。历史编号、已使用编号和已预留编号不受任何影响。</p>
          </div>
        </div>
      </div>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" :disabled="saving" @click="disableVisible = false">取消</el-button>
        <el-button
          size="small"
          type="danger"
          :loading="saving"
          @click="confirmDisable"
        >
          确认停用
        </el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  createDocumentNumberRule,
  disableDocumentNumberRule,
  enableDocumentNumberRule,
  listDocumentNumberRules,
  listDocumentNumberRuleTypes,
  updateDocumentNumberRule
} from '../../../api/erp/master'

const emptyForm = () => ({
  document_type: '',
  name: '',
  prefix: '',
  date_format: 'YYYYMMDD',
  sequence_length: 5,
  reset_cycle: 'daily',
  enabled: true,
  change_reason: ''
})

export default {
  name: 'DocumentNumberRuleList',
  data: () => ({
    loading: false,
    saving: false,
    rows: [],
    types: [],
    total: 0,
    stats: {
      total: 0,
      enabled: 0,
      disabled: 0
    },
    page: 1,
    perPage: 10,
    query: {
      document_type: '',
      keyword: '',
      enabled: ''
    },
    dialogVisible: false,
    editing: false,
    form: emptyForm(),
    original: null,
    disableVisible: false,
    disableTarget: null,
    reasonError: '',
    formRules: {
      document_type: [{ required: true, message: '请选择业务类型', trigger: 'change' }],
      name: [{ required: true, message: '请输入规则名称', trigger: 'blur' }],
      prefix: [
        { required: true, message: '请输入编号前缀', trigger: 'blur' },
        { pattern: /^[A-Z][A-Z0-9-]*$/, message: '只能使用大写字母、数字和短横线，且必须以字母开头', trigger: 'blur' }
      ],
      date_format: [{ required: true, message: '请选择日期格式', trigger: 'change' }],
      sequence_length: [{ required: true, message: '请输入流水号长度', trigger: 'change' }],
      reset_cycle: [{ required: true, message: '请选择重置周期', trigger: 'change' }]
    }
  }),
  computed: {
    isDirty () {
      if (!this.editing || !this.original) return true
      return ['name', 'prefix', 'date_format', 'sequence_length', 'reset_cycle', 'enabled'].some(
        key => this.form[key] !== this.original[key]
      )
    },
    formatExample () {
      const dates = {
        none: '',
        YYYY: this.today('year'),
        YYYYMM: this.today('month'),
        YYYYMMDD: this.today('day')
      }
      const seq = String(1).padStart(Number(this.form.sequence_length || 1), '0')
      return `${this.form.prefix || ''}${dates[this.form.date_format] || ''}${seq}`
    },
    previewDatePart () {
      const dates = {
        none: '无日期',
        YYYY: this.today('year'),
        YYYYMM: this.today('month'),
        YYYYMMDD: this.today('day')
      }
      return dates[this.form.date_format] || '无'
    },
    previewSequencePart () {
      return String(1).padStart(Number(this.form.sequence_length || 1), '0')
    },
    enabledCount () {
      if (this.stats && typeof this.stats.enabled === 'number') {
        return this.stats.enabled
      }
      return this.rows.filter(row => row.enabled).length
    },
    disabledCount () {
      if (this.stats && typeof this.stats.disabled === 'number') {
        return this.stats.disabled
      }
      return this.rows.filter(row => !row.enabled).length
    },
    configuredTypesCount () {
      return this.types.filter(type => type.configured).length
    },
    activeFilterTag () {
      if (this.query.enabled === '1') return '状态：正常启用'
      if (this.query.enabled === '0') return '状态：已停用'
      if (this.query.document_type) {
        const item = this.types.find(t => t.code === this.query.document_type)
        return `类型：${item ? item.label : this.query.document_type}`
      }
      return ''
    }
  },
  created () {
    this.initialize()
  },
  methods: {
    async initialize () {
      try {
        const { data } = await listDocumentNumberRuleTypes()
        this.types = data.data || []
      } catch (e) {
        this.noticeError(e)
      }
      await this.load()
    },
    async load () {
      this.loading = true
      try {
        const { data } = await listDocumentNumberRules({
          ...this.query,
          page: this.page,
          per_page: this.perPage
        })
        this.rows = data.data || []
        this.total = Number(data.total || 0)
        if (data.stats) {
          this.stats = {
            total: Number(data.stats.total || this.total),
            enabled: Number(data.stats.enabled || 0),
            disabled: Number(data.stats.disabled || 0)
          }
        } else {
          if (!this.query.enabled && !this.query.document_type && !this.query.keyword) {
            this.stats = {
              total: this.total,
              enabled: this.total,
              disabled: 0
            }
          }
        }
      } catch (e) {
        this.noticeError(e)
      } finally {
        this.loading = false
      }
    },
    search () {
      this.page = 1
      this.load()
    },
    reset () {
      this.query = { document_type: '', keyword: '', enabled: '' }
      this.search()
    },
    filterAll () {
      this.query.enabled = ''
      this.query.document_type = ''
      this.search()
    },
    filterStatus (status) {
      this.query.enabled = status
      this.search()
    },
    clearFilterTag () {
      this.query.enabled = ''
      this.query.document_type = ''
      this.search()
    },
    changeSize () {
      this.page = 1
      this.load()
    },
    openCreate () {
      this.editing = false
      this.form = emptyForm()
      this.original = null
      this.reasonError = ''
      this.dialogVisible = true
    },
    openEdit (row) {
      this.editing = true
      this.form = {
        document_type: row.document_type,
        name: row.name,
        prefix: row.prefix,
        date_format: row.date_format,
        sequence_length: row.sequence_length,
        reset_cycle: row.reset_cycle,
        enabled: row.enabled,
        change_reason: ''
      }
      this.original = { ...this.form }
      this.reasonError = ''
      this.dialogVisible = true
    },
    clearForm () {
      this.form = emptyForm()
      this.original = null
      this.reasonError = ''
      this.$refs.ruleForm?.clearValidate()
    },
    syncCycle (format) {
      this.form.reset_cycle = ({
        none: 'none',
        YYYY: 'yearly',
        YYYYMM: 'monthly',
        YYYYMMDD: 'daily'
      })[format] || 'none'
    },
    async save () {
      const valid = await this.$refs.ruleForm.validate().catch(() => false)
      if (!valid) return
      if (this.editing && !this.isDirty) {
        return this.$message.warning('编号规则没有发生任何变化')
      }
      if (this.editing && !String(this.form.change_reason || '').trim()) {
        this.reasonError = '规则实际发生变更时必须填写修改原因'
        return
      }
      this.saving = true
      try {
        const targetRow = this.rows.find(row => row.document_type === this.form.document_type)
        const targetId = targetRow ? targetRow.id : undefined
        const request = this.editing
          ? updateDocumentNumberRule(targetId, this.form)
          : createDocumentNumberRule(this.form)
        const { data } = await request
        this.$message.success(data.message || '操作成功')
        this.dialogVisible = false
        await this.load()
      } catch (e) {
        this.noticeError(e)
      } finally {
        this.saving = false
      }
    },
    openDisable (row) {
      this.disableTarget = row
      this.disableVisible = true
    },
    async confirmDisable () {
      if (!this.disableTarget) return
      this.saving = true
      try {
        const { data } = await disableDocumentNumberRule(this.disableTarget.id)
        this.$message.success(data.message || '规则已停用')
        this.disableVisible = false
        await this.load()
      } catch (e) {
        this.noticeError(e)
      } finally {
        this.saving = false
      }
    },
    async enable (row) {
      this.saving = true
      try {
        const { data } = await enableDocumentNumberRule(row.id)
        this.$message.success(data.message || '规则已启用')
        await this.load()
      } catch (e) {
        this.noticeError(e)
      } finally {
        this.saving = false
      }
    },
    copyExample (text) {
      if (!text) return
      if (navigator?.clipboard?.writeText) {
        navigator.clipboard.writeText(text).then(() => {
          this.$message.success(`已复制格式示例：${text}`)
        }).catch(() => {
          this.fallbackCopy(text)
        })
      } else {
        this.fallbackCopy(text)
      }
    },
    fallbackCopy (text) {
      const input = document.createElement('input')
      input.value = text
      document.body.appendChild(input)
      input.select()
      try {
        document.execCommand('copy')
        this.$message.success(`已复制格式示例：${text}`)
      } catch (e) {
        this.$message.info(`编号格式示例：${text}`)
      } finally {
        document.body.removeChild(input)
      }
    },
    today (part) {
      const d = new Date()
      const y = d.getFullYear()
      const m = String(d.getMonth() + 1).padStart(2, '0')
      const day = String(d.getDate()).padStart(2, '0')
      return part === 'year' ? `${y}` : part === 'month' ? `${y}${m}` : `${y}${m}${day}`
    },
    dateLabel (value) {
      return value === 'none' ? '无日期' : value
    },
    cycleLabel (value) {
      return ({
        none: '不重置',
        yearly: '每年',
        monthly: '每月',
        daily: '每日'
      })[value] || value
    },
    cycleTagType (value) {
      return ({
        none: 'info',
        yearly: 'warning',
        monthly: '',
        daily: 'success'
      })[value] || ''
    },
    noticeError (error) {
      const errors = error.response?.data?.errors
      const first = errors && Object.values(errors)[0]
      this.$message.error((Array.isArray(first) ? first[0] : first) || error.userMessage || error.message || '操作失败')
    }
  }
}
</script>

<style scoped>
/* ==========================================================================
   页面容器与基准：100% 对齐 SkuList.vue 风格
   ========================================================================== */
.number-rule-page {
  padding: 16px 20px 24px;
  background: #f8fafc;
  min-height: calc(100vh - 54px);
  box-sizing: border-box;
}

/* 页面头部：100% 对齐 SkuList 白底独立卡片 */
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
  border: 1px solid #bbf7d0;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
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
  color: #0f172a;
}

.head-tag,
.total-tag {
  border-radius: 4px;
  font-weight: 500;
  background: #f0fdf4 !important;
  border-color: #bbf7d0 !important;
  color: #008b4b !important;
}

.filter-status-tag {
  background: #f1f5f9 !important;
  border-color: #cbd5e1 !important;
  color: #475569 !important;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-refresh:hover {
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
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.25);
}

/* 全局统一页面提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 13px;
  line-height: 1.5;
  margin-bottom: 14px;
}

.erp-page-tip i {
  font-size: 15px;
  color: #008b4b;
  flex-shrink: 0;
}

/* 顶部概览指标卡片网格 (100% 对齐 SkuList / 用户截图) */
.metric-overview-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 14px;
  margin-bottom: 14px;
}

.metric-card {
  position: relative;
  display: flex;
  align-items: center;
  gap: 14px;
  padding: 14px 18px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  transition: all 0.2s ease;
  box-sizing: border-box;
  cursor: pointer;
  user-select: none;
}

.metric-card:hover {
  transform: translateY(-1px);
  box-shadow: 0 4px 12px rgba(15, 23, 42, 0.05);
  border-color: #cbd5e1;
}

.metric-card.active {
  border-color: #008b4b;
  background: #fafffc;
  box-shadow: 0 0 0 2px rgba(0, 139, 75, 0.12);
}

.metric-icon-box {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}

.metric-all .metric-icon-box {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}

.metric-enabled .metric-icon-box {
  background: #ecfdf5;
  color: #10b981;
  border: 1px solid #a7f3d0;
}

.metric-draft .metric-icon-box {
  background: #fffbeb;
  color: #f59e0b;
  border: 1px solid #fde68a;
}

.metric-types .metric-icon-box {
  background: #eff6ff;
  color: #2563eb;
  border: 1px solid #bfdbfe;
}

.metric-info {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
}

.metric-label {
  font-size: 12px;
  color: #6b7280;
  font-weight: 500;
}

.metric-val {
  font-size: 22px;
  font-weight: 700;
  color: #111827;
}

.metric-sub-count {
  font-size: 13px;
  color: #94a3b8;
  font-weight: 400;
}

.code-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
  letter-spacing: 0.3px;
}

.text-success { color: #008b4b !important; }
.text-muted { color: #94a3b8 !important; }
.text-warning { color: #d97706 !important; }

/* ==========================================================================
   表格主数据卡片与筛选工具栏 (对齐 SkuList / ItemList 风格)
   ========================================================================== */
.table-container-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  overflow: hidden;
}

.filter-toolbar {
  padding: 14px 18px;
  background: #ffffff;
  border-bottom: 1px solid #f1f5f9;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.filter-fields {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  flex: 1;
  min-width: 0;
}

.filter-input-search {
  width: 220px;
}

.filter-select-md {
  width: 180px;
}

.filter-select-xs {
  width: 110px;
}

.filter-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

.btn-theme-search {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  transition: all 0.2s ease;
}

.btn-theme-search:hover,
.btn-theme-search:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
  color: #ffffff !important;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.25);
}

.btn-theme-reset {
  border-color: #cbd5e1;
  color: #475569;
}

.btn-theme-reset:hover {
  border-color: #008b4b;
  color: #008b4b;
}

/* 主表格区域 */
.main-table-wrap {
  width: 100%;
  overflow-x: auto;
}

.custom-item-table {
  width: 100%;
}

.main-table-wrap ::v-deep .el-table th {
  background-color: #f8fafc !important;
  color: #475569;
  font-weight: 600;
  font-size: 12px;
  height: 40px;
  padding: 4px 0;
}

.main-table-wrap ::v-deep .el-table td {
  padding: 8px 0;
  color: #1e293b;
  font-size: 13px;
}

.type-name-cell {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.type-name-text {
  font-weight: 600;
  color: #0f172a;
}

.type-code-sub {
  font-size: 11px;
  color: #64748b;
}

.rule-name-text {
  font-weight: 600;
  color: #1e293b;
}

/* 编号前缀胶囊：对齐 ItemList.vue 的 item-code-link 样式 */
.item-code-chip {
  font-size: 13px;
  font-weight: 600;
  letter-spacing: 0.4px;
  font-variant-numeric: tabular-nums;
  color: #00763f;
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border-radius: 4px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  transition: all 0.15s ease;
  line-height: 1.4;
  white-space: nowrap;
}

.item-code-chip i {
  font-size: 13px;
  color: #008b4b;
  flex-shrink: 0;
}

.type-badge {
  font-size: 11px;
  color: #475569;
  background: #f1f5f9;
  padding: 2px 6px;
  border-radius: 4px;
  border: 1px solid #e2e8f0;
}

.unit-badge {
  font-size: 12px;
  color: #475569;
  background: #f8fafc;
  padding: 2px 6px;
  border-radius: 4px;
  border: 1px solid #e2e8f0;
}

.font-tabular {
  font-variant-numeric: tabular-nums;
}

.stat-count {
  font-size: 13px;
  color: #64748b;
  font-weight: 500;
}

.stat-count.has-val {
  color: #008b4b;
  font-weight: 700;
}

/* 格式示例仿真单号胶囊 */
.example-code-pill {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  padding: 3px 8px;
  border-radius: 4px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  color: #1e293b;
  font-size: 12px;
  font-weight: 600;
  cursor: pointer;
  transition: all 0.15s ease;
  user-select: none;
}

.example-code-pill:hover {
  background: #eaf7ef;
  border-color: #008b4b;
  color: #00763f;
}

.copy-icon {
  font-size: 13px;
  color: #64748b;
}

.example-code-pill:hover .copy-icon {
  color: #008b4b;
}

/* 状态胶囊：对齐 ItemList.vue 的 status-pill */
.status-pill {
  display: inline-block;
  padding: 2px 8px;
  border-radius: 10px;
  font-size: 11px;
  font-weight: 500;
}

.status-pill.enabled {
  background: #eaf7ef;
  color: #00763f;
  border: 1px solid #bbf7d0;
}

.status-pill.disabled {
  background: #f1f5f9;
  color: #94a3b8;
  border: 1px solid #e2e8f0;
}

/* 操作列链接 */
.action-link-theme {
  color: #008b4b !important;
  font-weight: 500;
}

.action-link-theme:hover {
  color: #00763f !important;
}

.danger-link {
  color: #ef4444 !important;
  font-weight: 500;
}

.danger-link:hover {
  color: #dc2626 !important;
}

.success-link {
  color: #008b4b !important;
  font-weight: 500;
}

.success-link:hover {
  color: #00763f !important;
}

/* 表格底部分页栏 */
.table-pagination-footer {
  padding: 12px 18px;
  background: #ffffff;
  border-top: 1px solid #f1f5f9;
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
}

.total-text {
  font-size: 13px;
  color: #64748b;
}

.total-text strong {
  color: #0f172a;
}

/* ==========================================================================
   居中弹窗样式 (Dialog 规范)
   ========================================================================== */
.dialog-header-banner {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 16px;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 12px;
}

.banner-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #eaf7ef;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
  border: 1px solid #ccebd7;
}

.banner-text {
  min-width: 0;
}

.banner-title {
  margin: 0 0 3px;
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}

.banner-sub {
  margin: 0;
  font-size: 12px;
  color: #64748b;
  line-height: 1.4;
}

.dialog-notice-tip {
  padding: 8px 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 6px;
  color: #92400e;
  font-size: 12px;
  display: flex;
  align-items: center;
  gap: 8px;
  margin-bottom: 14px;
  line-height: 1.45;
}

.dialog-notice-tip i {
  color: #d97706;
  font-size: 14px;
  flex-shrink: 0;
}

.rule-dialog-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.form-section-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 14px 16px;
  box-sizing: border-box;
}

.section-title {
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 12px;
  display: flex;
  align-items: center;
  gap: 6px;
}

.section-title i {
  color: #008b4b;
  font-size: 14px;
}

.form-grid-2col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 16px;
}

.form-grid-3col {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr;
  gap: 12px;
}

.full-width {
  width: 100%;
}

.field-help {
  font-size: 11px;
  color: #64748b;
  margin-top: 4px;
  line-height: 1.4;
}

/* 仿真预览卡片 */
.preview-section-card {
  background: #fcfdfd;
  border-color: #cbd5e1;
}

.example-preview-box {
  background: #ffffff;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 12px 14px;
  margin-bottom: 12px;
}

.preview-header-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
  margin-bottom: 8px;
}

.preview-title-tag {
  font-size: 12px;
  font-weight: 600;
  color: #00763f;
  display: flex;
  align-items: center;
  gap: 5px;
}

.preview-note {
  font-size: 11px;
  color: #94a3b8;
}

.preview-display-text {
  font-size: 18px;
  font-weight: 700;
  color: #00763f;
  background: #f0fdf4;
  padding: 8px 12px;
  border-radius: 6px;
  border: 1px solid #86efac;
  letter-spacing: 1px;
  text-align: center;
  margin-bottom: 10px;
}

.preview-breakdown-bar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 6px 10px;
  background: #f8fafc;
  border-radius: 4px;
  border: 1px solid #e2e8f0;
}

.breakdown-item {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}

.bd-label {
  font-size: 10px;
  color: #64748b;
}

.bd-val {
  font-size: 12px;
  font-weight: 600;
  color: #0f172a;
}

.bd-divider {
  font-size: 13px;
  font-weight: 700;
  color: #94a3b8;
}

.status-config-row {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-top: 8px;
}

.status-config-label {
  font-size: 13px;
  font-weight: 600;
  color: #334155;
}

.status-warning-tip {
  margin-top: 8px;
  font-size: 12px;
  line-height: 1.45;
  padding: 6px 10px;
  background: #fffbeb;
  border-radius: 4px;
  border: 1px solid #fef3c7;
}

.status-warning-tip i {
  margin-right: 4px;
}

.edit-reason-card {
  border-color: #fde68a;
  background: #fffdf5;
}

.reason-error-msg {
  color: #ef4444;
  font-size: 12px;
  margin-top: 3px;
}

/* 停用确认弹窗 */
.disable-dialog-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 16px;
  font-weight: 700;
  color: #1e293b;
}

.disable-dialog-title i {
  color: #f59e0b;
  font-size: 20px;
}

.disable-body {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.disable-info-grid {
  display: grid;
  grid-template-columns: 80px 1fr;
  margin: 0;
  padding: 8px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  font-size: 13px;
}

.disable-info-grid dt,
.disable-info-grid dd {
  margin: 0;
  padding: 8px 0;
  border-bottom: 1px solid #edf2f7;
}

.disable-info-grid dt:last-of-type,
.disable-info-grid dd:last-of-type {
  border-bottom: none;
}

.disable-info-grid dt {
  color: #64748b;
}

.disable-info-grid dd {
  color: #1e293b;
}

.disable-warn-card {
  display: flex;
  gap: 10px;
  padding: 10px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
  color: #991b1b;
}

.disable-warn-card i {
  font-size: 18px;
  color: #ef4444;
  flex-shrink: 0;
  margin-top: 1px;
}

.warn-text {
  min-width: 0;
}

.warn-head {
  margin: 0 0 3px;
  font-size: 12px;
  font-weight: 700;
}

.warn-desc {
  margin: 0;
  font-size: 12px;
  line-height: 1.45;
  color: #b91c1c;
}

/* ==========================================================================
   响应式断点适配 (强制规则：移动端 320px~430px 到大屏 1920px+ 弹性自适应)
   ========================================================================== */
@media (max-width: 1024px) {
  .metric-overview-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .number-rule-page {
    padding: 10px 12px;
  }

  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
    padding: 12px 14px;
  }

  .head-actions {
    width: 100%;
    justify-content: flex-end;
  }

  .metric-overview-grid {
    grid-template-columns: 1fr;
  }

  .filter-fields {
    width: 100%;
  }

  .filter-input-search,
  .filter-select-md,
  .filter-select-xs {
    width: 100%;
  }

  .filter-actions {
    width: 100%;
    justify-content: flex-end;
  }

  .form-grid-2col,
  .form-grid-3col {
    grid-template-columns: 1fr;
  }

  .table-pagination-footer {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }

  .table-pagination-footer ::v-deep .el-pagination {
    display: flex;
    flex-wrap: wrap;
    gap: 4px;
    padding: 0;
  }
}

@media (max-width: 480px) {
  .preview-breakdown-bar {
    flex-direction: column;
    gap: 4px;
  }

  .bd-divider {
    display: none;
  }
}
</style>

<style>
/* 全局弹窗居中与自适应定制 */
.number-rule-dialog.el-dialog {
  border-radius: 8px;
  overflow: hidden;
  max-width: 680px;
  margin-bottom: 5vh;
}

@media (max-width: 720px) {
  .number-rule-dialog.el-dialog {
    width: calc(100vw - 24px) !important;
    margin: 5vh auto !important;
  }
}

.number-rule-dialog .el-dialog__header {
  padding: 16px 20px 14px;
  border-bottom: 1px solid #f1f5f9;
}

.number-rule-dialog .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.number-rule-dialog .el-dialog__body {
  padding: 16px 20px;
  max-height: 72vh;
  overflow-y: auto;
}

.number-rule-dialog .el-dialog__footer {
  padding: 12px 20px;
  border-top: 1px solid #f1f5f9;
}

.disable-dialog.el-dialog {
  border-radius: 8px;
  overflow: hidden;
}

@media (max-width: 520px) {
  .disable-dialog.el-dialog {
    width: calc(100vw - 24px) !important;
  }
}

.disable-dialog .el-dialog__header {
  padding: 16px 20px 12px;
  border-bottom: 1px solid #f1f5f9;
}

.disable-dialog .el-dialog__body {
  padding: 16px 20px;
}

.disable-dialog .el-dialog__footer {
  padding: 12px 20px;
  border-top: 1px solid #f1f5f9;
}
</style>
