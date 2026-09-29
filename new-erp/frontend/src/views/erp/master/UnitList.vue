<template>
  <section class="unit-page" :class="{ embedded }">
    <main class="unit-workspace">
      <!-- 独立访问时的页面头部 -->
      <header v-if="!embedded" class="page-head">
        <div class="head-left">
          <span class="head-icon"><i class="el-icon-c-scale-to-original" /></span>
          <div class="head-title-wrap">
            <div class="title-row">
              <h1 class="page-title">单位管理</h1>
              <el-tag size="small" type="success" effect="plain" class="total-tag">共 {{ total }} 个单位</el-tag>
            </div>
          </div>
        </div>
        <div class="head-actions">
          <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="load">刷新</el-button>
          <el-button size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openCreate">新增单位</el-button>
        </div>
      </header>

      <!-- 概览指标卡片 -->
      <section class="metric-overview-grid">
        <div
          class="metric-card metric-all"
          :class="{ active: !query.status && !query.unit_type }"
          title="点击查看全部单位"
          @click="filterAll"
        >
          <div class="metric-icon-box"><i class="el-icon-c-scale-to-original" /></div>
          <div class="metric-info">
            <span class="metric-label">全部单位档案</span>
            <strong class="metric-val code-mono">{{ total }}</strong>
          </div>
        </div>

        <div
          class="metric-card metric-enabled"
          :class="{ active: query.status === 'enabled' }"
          title="点击筛选正常启用单位"
          @click="filterStatus('enabled')"
        >
          <div class="metric-icon-box"><i class="el-icon-circle-check" /></div>
          <div class="metric-info">
            <span class="metric-label">正常启用单位</span>
            <strong class="metric-val code-mono text-success">{{ enabledCount }}</strong>
          </div>
        </div>

        <div
          class="metric-card metric-quantity"
          :class="{ active: query.unit_type === 'quantity' }"
          title="点击筛选数量类单位"
          @click="filterType('quantity')"
        >
          <div class="metric-icon-box"><i class="el-icon-goods" /></div>
          <div class="metric-info">
            <span class="metric-label">数量类单位</span>
            <strong class="metric-val code-mono">{{ quantityCount }}</strong>
          </div>
        </div>

        <div
          class="metric-card metric-measure"
          :class="{ active: query.unit_type && query.unit_type !== 'quantity' }"
          title="度量衡单位（重/长/面/体/时间）"
        >
          <div class="metric-icon-box"><i class="el-icon-odometer" /></div>
          <div class="metric-info">
            <span class="metric-label">度量衡单位</span>
            <strong class="metric-val code-mono">{{ measureCount }}</strong>
          </div>
        </div>
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
                placeholder="编码 / 名称 / 符号..."
                @keyup.enter.native="search"
              />
            </div>
            <div class="filter-item">
              <span class="filter-label">单位类型</span>
              <el-select v-model="query.unit_type" size="small" clearable placeholder="全部类型" @change="search">
                <el-option v-for="type in unitTypes" :key="type.value" :label="type.label" :value="type.value" />
              </el-select>
            </div>
            <div class="filter-item">
              <span class="filter-label">状态</span>
              <el-select v-model="query.status" size="small" clearable placeholder="全部状态" @change="search">
                <el-option label="正常启用" value="enabled" />
                <el-option label="已停用" value="disabled" />
              </el-select>
            </div>
          </div>
          <div class="filter-actions">
            <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="search">查询</el-button>
            <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
            <el-button size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openCreate">新增单位</el-button>
          </div>
        </div>

        <div class="table-wrap">
          <el-table ref="table" v-loading="loading" :data="rows" border size="small" row-key="id" class="enterprise-table">
            <el-table-column prop="unit_code" label="单位编码" min-width="110">
              <template slot-scope="{ row }">
                <span class="code-mono unit-code-chip">{{ row.unit_code }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="unit_name" label="单位名称" min-width="110">
              <template slot-scope="{ row }">
                <span class="unit-name-text">{{ row.unit_name }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="symbol" label="单位符号" width="95" align="center">
              <template slot-scope="{ row }">
                <span class="symbol-badge code-mono">{{ row.symbol || row.unit_name || '-' }}</span>
              </template>
            </el-table-column>
            <el-table-column label="单位类型" width="100" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="unitTypeTagType(row.unit_type)" effect="plain" class="type-tag">
                  {{ unitTypeText(row.unit_type) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column label="允许小数" width="95" align="center">
              <template slot-scope="{ row }">
                <el-tag v-if="row.allow_decimal" size="mini" type="success" effect="plain" class="decimal-tag">
                  允许
                </el-tag>
                <span v-else class="text-muted text-xs">仅整数</span>
              </template>
            </el-table-column>
            <el-table-column prop="decimal_places" label="小数位" width="80" align="center">
              <template slot-scope="{ row }">
                <span class="code-mono" :class="{ 'text-muted': !row.allow_decimal }">{{ row.allow_decimal ? row.decimal_places : 0 }}</span>
              </template>
            </el-table-column>
            <el-table-column label="使用对象数" width="105" align="right">
              <template slot-scope="{ row }">
                <span class="code-mono usage-badge" :class="{ 'has-usage': usageCount(row) > 0 }">
                  {{ usageCount(row).toLocaleString() }}
                </span>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="90" align="center">
              <template slot-scope="{ row }">
                <el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'" effect="light" class="status-tag">
                  <i :class="row.status === 'enabled' ? 'el-icon-circle-check' : 'el-icon-circle-close'" />
                  {{ statusText(row.status) }}
                </el-tag>
              </template>
            </el-table-column>
            <el-table-column prop="sort_order" label="排序" width="70" align="center">
              <template slot-scope="{ row }">
                <span class="code-mono sort-val">{{ row.sort_order }}</span>
              </template>
            </el-table-column>
            <el-table-column label="更新时间" min-width="140">
              <template slot-scope="{ row }">
                <span class="code-mono text-muted date-text">{{ formatDate(row.updated_at) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="165" :fixed="compact ? false : 'right'" align="center">
              <template slot-scope="{ row }">
                <div class="row-actions">
                  <el-button type="text" size="mini" class="btn-action-edit" icon="el-icon-edit" @click="openEdit(row)">编辑</el-button>
                  <el-button
                    type="text"
                    size="mini"
                    :class="row.status === 'enabled' ? 'btn-action-disable' : 'btn-action-enable'"
                    :icon="row.status === 'enabled' ? 'el-icon-video-pause' : 'el-icon-video-play'"
                    @click="toggleStatus(row)"
                  >
                    {{ row.status === 'enabled' ? '停用' : '启用' }}
                  </el-button>
                  <el-button
                    v-if="row.status !== 'enabled'"
                    type="text"
                    size="mini"
                    class="btn-action-delete"
                    icon="el-icon-delete"
                    @click="deleteUnit(row)"
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
            background
            small
            layout="prev, pager, next, sizes, jumper"
            :current-page.sync="query.page"
            :page-size.sync="query.per_page"
            :page-sizes="[10, 20, 50, 100]"
            :total="total"
            @current-change="load"
            @size-change="sizeChange"
          />
        </footer>
      </section>
    </main>

    <!-- 单位新增 / 编辑弹窗 -->
    <el-dialog
      :title="form.id ? '编辑单位档案' : '新增计量单位'"
      :visible.sync="drawerVisible"
      width="640px"
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
        <i class="el-icon-c-scale-to-original banner-icon" />
        <div class="banner-text">
          <p class="banner-title">{{ form.id ? '修改计量单位属性' : '创建全局通用计量单位' }}</p>
          <p class="banner-sub">计量单位一经业务单据、商品规格或换算体系引用，部分关键编码将不可变更</p>
        </div>
      </div>

      <el-form ref="form" :model="form" :rules="rules" :disabled="saving" label-position="top" size="small" class="unit-dialog-form">
        <div class="form-grid-2col">
          <el-form-item label="单位编码" prop="unit_code">
            <el-input v-model.trim="form.unit_code" :disabled="!!form.id" placeholder="如 PCS、KG、SET" class="code-mono" />
            <div v-if="form.id" class="field-help">创建后不可修改</div>
          </el-form-item>

          <el-form-item label="单位名称" prop="unit_name">
            <el-input v-model.trim="form.unit_name" maxlength="50" show-word-limit placeholder="如 件、千克、套" />
          </el-form-item>
        </div>

        <div class="form-grid-2col">
          <el-form-item label="单位符号" prop="symbol">
            <el-input v-model.trim="form.symbol" maxlength="20" show-word-limit placeholder="打印与表单缩写，如 pcs、kg" class="code-mono" />
          </el-form-item>

          <el-form-item label="单位类型" prop="unit_type">
            <el-select v-model="form.unit_type" class="full">
              <el-option v-for="type in unitTypes" :key="type.value" :label="type.label" :value="type.value" />
            </el-select>
          </el-form-item>
        </div>

        <div class="form-grid-2col">
          <el-form-item label="允许小数">
            <div class="switch-box">
              <el-switch v-model="form.allow_decimal" active-color="#008b4b" @change="decimalToggle" />
              <span class="switch-tip">{{ form.allow_decimal ? '允许业务输入小数' : '仅允许录入整数' }}</span>
            </div>
          </el-form-item>

          <el-form-item label="小数保留位数" prop="decimal_places">
            <el-input-number v-model="form.decimal_places" :min="0" :max="6" :disabled="!form.allow_decimal" controls-position="right" class="full" />
            <div class="field-help">范围 0-6 位；不允许小数时固定为 0</div>
          </el-form-item>
        </div>

        <div class="form-grid-2col">
          <el-form-item label="显示排序" prop="sort_order">
            <el-input-number v-model="form.sort_order" :min="0" :max="999999" controls-position="right" class="full" />
            <div class="field-help">数值越小排在越前（默认 1）</div>
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

        <el-form-item label="备注说明">
          <el-input v-model="form.remark" type="textarea" :rows="3" maxlength="200" show-word-limit placeholder="选填，补充说明该单位适用场景或换算规则..." />
        </el-form-item>
      </el-form>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" :disabled="saving" @click="closeDrawer">取消</el-button>
        <el-button size="small" type="success" :loading="saving" class="btn-theme-create" @click="save">保存单位</el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import { deleteEntity, disableEntity, enableEntity, listEntity, saveEntity } from '../../../api/erp/master'

const emptyForm = () => ({
  id: null,
  unit_code: '',
  unit_name: '',
  symbol: '',
  unit_type: 'quantity',
  allow_decimal: false,
  decimal_places: 0,
  sort_order: 1,
  is_base: false,
  status: 'enabled',
  remark: ''
})

export default {
  name: 'UnitList',
  props: {
    embedded: { type: Boolean, default: false },
    active: { type: Boolean, default: true }
  },
  data () {
    return {
      loading: false,
      saving: false,
      drawerVisible: false,
      rows: [],
      total: 0,
      stats: {},
      compact: window.innerWidth <= 900,
      query: { keyword: '', unit_type: '', status: '', page: 1, per_page: 20 },
      form: emptyForm(),
      unitTypes: [
        { value: 'quantity', label: '数量' },
        { value: 'weight', label: '重量' },
        { value: 'length', label: '长度' },
        { value: 'area', label: '面积' },
        { value: 'volume', label: '体积' },
        { value: 'time', label: '时间' }
      ],
      rules: {
        unit_code: [{ required: true, message: '请输入单位编码', trigger: 'blur' }],
        unit_name: [{ required: true, message: '请输入单位名称', trigger: 'blur' }],
        symbol: [{ required: true, message: '请输入单位符号', trigger: 'blur' }],
        unit_type: [{ required: true, message: '请选择单位类型', trigger: 'change' }],
        decimal_places: [{ required: true, message: '请设置小数位数', trigger: 'change' }],
        sort_order: [{ required: true, message: '请设置排序', trigger: 'change' }]
      }
    }
  },
  computed: {
    enabledCount () {
      return Number(this.stats.enabled || 0)
    },
    quantityCount () {
      return Number(this.stats.quantity || 0)
    },
    measureCount () {
      return Number(this.stats.measure || 0)
    }
  },
  created () {
    this.load()
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
      this.loading = true
      try {
        const { data } = await listEntity('units', { ...this.query, include_stats: 1 })
        this.rows = data.data || []
        this.total = Number(data.total || 0)
        this.stats = data.stats || {}
        this.$emit('count-updated', this.total)
      } catch (e) {
        this.$message.error(e.userMessage || '单位列表加载失败')
      } finally {
        this.loading = false
      }
    },
    search () {
      this.query.page = 1
      this.load()
    },
    reset () {
      this.query = { keyword: '', unit_type: '', status: '', page: 1, per_page: this.query.per_page }
      this.load()
    },
    sizeChange () {
      this.query.page = 1
      this.load()
    },
    filterAll () {
      this.reset()
    },
    filterStatus (status) {
      this.query.status = status
      this.search()
    },
    filterType (type) {
      this.query.unit_type = type
      this.search()
    },
    openCreate () {
      this.form = emptyForm()
      this.drawerVisible = true
      this.clearValidation()
    },
    openEdit (row) {
      this.form = {
        ...emptyForm(),
        ...row,
        allow_decimal: !!row.allow_decimal,
        sort_order: Number(row.sort_order || 0)
      }
      this.drawerVisible = true
      this.clearValidation()
    },
    closeDrawer () {
      if (!this.saving) this.drawerVisible = false
    },
    clearValidation () {
      this.$nextTick(() => this.$refs.form && this.$refs.form.clearValidate())
    },
    beforeClose (done) {
      if (!this.saving) done()
    },
    decimalToggle (value) {
      if (!value) this.form.decimal_places = 0
    },
    save () {
      if (this.saving) return
      this.$refs.form.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          await saveEntity('units', {
            ...this.form,
            decimal_places: this.form.allow_decimal ? this.form.decimal_places : 0
          })
          this.$message.success('单位保存成功')
          this.drawerVisible = false
          await this.load()
        } catch (e) {
          this.$message.error(e.userMessage || '单位保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    async toggleStatus (row) {
      const enabling = row.status !== 'enabled'
      try {
        await this.$confirm(
          enabling ? '确认启用该单位？' : '被业务引用的单位不能停用，确认继续检查并停用？',
          enabling ? '启用单位' : '停用单位',
          { type: 'warning' }
        )
        await (enabling ? enableEntity : disableEntity)('units', row.id)
        this.$message.success(enabling ? '单位已启用' : '单位已停用')
        await this.load()
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '操作失败')
      }
    },
    async deleteUnit (row) {
      try {
        await this.$confirm(
          `确认删除单位 ${row.unit_code} / ${row.unit_name}？被商品、SKU、物料、换算或业务单据引用的单位不能删除。`,
          '删除单位',
          { type: 'warning', confirmButtonText: '确认删除' }
        )
        await deleteEntity('units', row.id)
        this.$message.success('单位已删除')
        await this.load()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '单位删除失败')
      }
    },
    usageCount (row) {
      return (
        Number(row.items_count || 0) +
        Number(row.skus_count || 0) +
        Number(row.products_count || 0) +
        Number(row.purchase_conversions_count || 0)
      )
    },
    unitTypeText (value) {
      return (this.unitTypes.find(type => type.value === value) || {}).label || value || '-'
    },
    unitTypeTagType (value) {
      const map = {
        quantity: '',
        weight: 'warning',
        length: 'info',
        area: '',
        volume: 'success',
        time: 'info'
      }
      return map[value] || ''
    },
    statusText (value) {
      return value === 'enabled' ? '启用' : '停用'
    },
    formatDate (value) {
      return value ? String(value).replace('T', ' ').slice(0, 16) : '-'
    }
  }
}
</script>

<style scoped>
.unit-page {
  min-width: 0;
  color: #1f2937;
}

.unit-workspace {
  padding: 0;
}

/* 独立头部 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: #ffffff;
  border-bottom: 1px solid #e2e8f0;
  border-radius: 8px 8px 0 0;
  margin-bottom: 16px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.head-icon {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
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

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
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

.metric-quantity .metric-icon-box {
  background: #f5f3ff;
  color: #7c3aed;
}

.metric-measure .metric-icon-box {
  background: #fefce8;
  color: #ca8a04;
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

.unit-code-chip {
  display: inline-block;
  padding: 2px 7px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  color: #0f172a;
  font-size: 12px;
  font-weight: 600;
}

.unit-name-text {
  font-weight: 600;
  color: #0f172a;
}

.symbol-badge {
  display: inline-block;
  padding: 1px 6px;
  background: #f8fafc;
  border: 1px solid #cbd5e1;
  border-radius: 4px;
  font-size: 12px;
  color: #334155;
}

.usage-badge {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
}

.usage-badge.has-usage {
  color: #008b4b;
}

.sort-val {
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

.type-tag,
.decimal-tag {
  border-radius: 4px;
  font-size: 11px;
}

/* 操作按钮 */
.row-actions {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 10px;
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

.unit-dialog-form {
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

.switch-box {
  height: 32px;
  display: flex;
  align-items: center;
  gap: 10px;
}

.switch-tip {
  font-size: 12px;
  color: #475569;
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
