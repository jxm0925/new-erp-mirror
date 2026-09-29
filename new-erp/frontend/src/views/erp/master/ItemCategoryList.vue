<template>
  <section class="category-page">
    <div class="category-workspace">
      <!-- 页面全局头部：图标、标题、统计标签与主要操作 -->
      <header class="page-head">
        <div class="head-left">
          <span class="head-icon"><i class="el-icon-folder-opened" /></span>
          <div class="head-title-wrap">
            <div class="title-row">
              <h1 class="page-title">物料类目档案</h1>
              <el-tag size="small" type="success" effect="plain" class="head-tag">
                共 {{ flatRows.length }} 个类目
              </el-tag>
              <el-tag v-if="selected.id" size="small" type="info" effect="plain" class="head-tag">
                当前选中：{{ selected.category_name }}
              </el-tag>
            </div>
          </div>
        </div>
        <div class="head-actions">
          <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="initialize">刷新</el-button>
          <el-button v-if="canManage" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openCreate(null)">
            新增一级类目
          </el-button>
        </div>
      </header>

      <!-- 全局统一页面提示条 -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>维护物料标准多级树形类目体系。左侧树形目录支持按编码或名称即时检索；右侧维护当前类目属性、下级子类目层级，并可直接下钻穿透查看关联物料档案与供货商。</span>
      </div>

      <!-- 顶部筛选与检索栏 -->
      <div class="filter-card">
        <div class="filter-inputs">
          <div class="filter-item">
            <span class="filter-label">类目检索</span>
            <el-input
              v-model="query.keyword"
              size="small"
              clearable
              prefix-icon="el-icon-search"
              placeholder="输入类目编码或名称..."
              @keyup.enter.native="search"
            />
          </div>
          <div class="filter-item">
            <span class="filter-label">类目状态</span>
            <el-select v-model="query.status" size="small" clearable placeholder="全部状态" class="status-select">
              <el-option label="正常启用" value="enabled" />
              <el-option label="已停用" value="disabled" />
            </el-select>
          </div>
          <div class="filter-actions">
            <el-button size="small" type="success" icon="el-icon-search" class="btn-theme-search" @click="search">查询</el-button>
            <el-button size="small" icon="el-icon-refresh-left" class="btn-theme-reset" @click="reset">重置</el-button>
          </div>
        </div>
        <div class="filter-quick-info">
          <span class="path-hint">
            <i class="el-icon-guide" />
            当前路径：<strong>{{ selected.full_path || '全部根类目' }}</strong>
          </span>
        </div>
      </div>

      <!-- 核心工作区：左侧树形目录 + 右侧详情与子类列表 -->
      <div class="content-grid">
        <!-- 左侧类目树卡片 -->
        <aside class="tree-card">
          <div class="card-header">
            <div class="header-main">
              <span class="card-icon-badge"><i class="el-icon-s-operation" /></span>
              <h2 class="card-title">类目层级树</h2>
            </div>
            <el-button type="text" size="mini" icon="el-icon-refresh" class="btn-refresh-tree" @click="loadTree">刷新树</el-button>
          </div>

          <div class="tree-search-wrap">
            <el-input
              v-model="treeKeyword"
              size="small"
              clearable
              prefix-icon="el-icon-search"
              placeholder="搜索类目名称/编码..."
              @input="filterTree"
            />
          </div>

          <div class="tree-scroll-container">
            <el-tree
              ref="categoryTree"
              :data="tree"
              node-key="id"
              default-expand-all
              highlight-current
              :filter-node-method="filterNode"
              :props="{ label: 'category_name', children: 'children' }"
              @node-click="selectCategory"
            >
              <div slot-scope="{ data, node }" class="custom-tree-node" :class="{ 'is-selected': selected.id === data.id }">
                <div class="node-main">
                  <i :class="data.is_leaf ? 'el-icon-document leaf-icon' : (node.expanded ? 'el-icon-folder-opened folder-icon' : 'el-icon-folder folder-icon')" />
                  <span class="node-name" :title="data.category_name">{{ data.category_name }}</span>
                </div>
                <div class="node-badges">
                  <span v-if="data.subtree_item_count !== undefined && data.subtree_item_count !== null" class="count-badge" title="关联物料数">
                    {{ data.subtree_item_count }}
                  </span>
                  <span v-if="data.status === 'disabled'" class="disabled-dot" title="已停用" />
                </div>
              </div>
            </el-tree>
          </div>
        </aside>

        <!-- 右侧主体内容 -->
        <main class="detail-column">
          <!-- 当前选中类目卡片 -->
          <section v-if="selected.id" class="detail-card">
            <div class="detail-head">
              <div class="head-title-box">
                <div class="title-with-badge">
                  <h2 class="detail-title">{{ selected.category_name }}</h2>
                  <el-tag size="mini" :type="selected.status === 'enabled' ? 'success' : 'info'" effect="plain">
                    {{ selected.status === 'enabled' ? '正常启用' : '已停用' }}
                  </el-tag>
                  <span class="code-chip">
                    <i class="el-icon-postcard" />
                    <strong class="code-mono">{{ selected.category_code }}</strong>
                  </span>
                </div>
                <div class="path-breadcrumb">
                  <i class="el-icon-location-outline" />
                  <span>完整路径：{{ selected.full_path || '-' }}</span>
                </div>
              </div>

              <div class="detail-actions">
                <el-button v-if="canManage" size="small" type="success" plain icon="el-icon-plus" @click="openCreate(selected)">
                  新增子类目
                </el-button>
                <el-button v-if="canManage" size="small" icon="el-icon-edit" @click="openEdit(selected)">
                  编辑
                </el-button>
                <el-button
                  v-if="canManage"
                  size="small"
                  :type="selected.status === 'enabled' ? 'danger' : 'success'"
                  plain
                  @click="toggleStatus(selected)"
                >
                  {{ selected.status === 'enabled' ? '停用' : '启用' }}
                </el-button>
                <el-button
                  v-if="canManage && selected.status !== 'enabled'"
                  size="small"
                  type="danger"
                  plain
                  icon="el-icon-delete"
                  @click="deleteCategory(selected)"
                >
                  删除
                </el-button>
              </div>
            </div>

            <!-- 属性元信息网格 -->
            <div class="meta-grid">
              <div class="meta-item">
                <span class="meta-label">类目编码</span>
                <span class="meta-val code-mono">{{ selected.category_code }}</span>
              </div>
              <div class="meta-item">
                <span class="meta-label">父级类目</span>
                <span class="meta-val">{{ selected.parent_id ? parentName(selected.parent_id) : '一级根类目' }}</span>
              </div>
              <div class="meta-item">
                <span class="meta-label">显示排序</span>
                <span class="meta-val code-mono">{{ selected.sort_order }}</span>
              </div>
              <div class="meta-item">
                <span class="meta-label">最后更新时间</span>
                <span class="meta-val code-mono text-muted">{{ formatDate(selected.updated_at) }}</span>
              </div>
              <div class="meta-item col-span-full">
                <span class="meta-label">类目备注说明</span>
                <span class="meta-val text-desc">{{ selected.remark || '无备注' }}</span>
              </div>
            </div>
          </section>

          <!-- 三项统计指标卡片 (支持点击快速穿透跳转) -->
          <div v-if="selected.id" class="stat-row">
            <div class="stat-card linkable" @click="goItems">
              <div class="stat-icon-box item-icon">
                <i class="el-icon-box" />
              </div>
              <div class="stat-info">
                <span class="stat-label">直属关联物料</span>
                <div class="stat-value-row">
                  <strong class="stat-number code-mono">{{ selected.direct_item_count || 0 }}</strong>
                  <span class="stat-unit">款物料</span>
                </div>
              </div>
              <i class="el-icon-arrow-right stat-arrow" />
            </div>

            <div class="stat-card linkable" @click="goSuppliers">
              <div class="stat-icon-box supplier-icon">
                <i class="el-icon-truck" />
              </div>
              <div class="stat-info">
                <span class="stat-label">直属关联供应商</span>
                <div class="stat-value-row">
                  <strong class="stat-number code-mono">{{ selected.direct_supplier_count || 0 }}</strong>
                  <span class="stat-unit">家供货商</span>
                </div>
              </div>
              <i class="el-icon-arrow-right stat-arrow" />
            </div>

            <div class="stat-card">
              <div class="stat-icon-box child-icon">
                <i class="el-icon-folder" />
              </div>
              <div class="stat-info">
                <span class="stat-label">直接下级子类目</span>
                <div class="stat-value-row">
                  <strong class="stat-number code-mono">{{ selected.direct_child_count || 0 }}</strong>
                  <span class="stat-unit">个分支</span>
                </div>
              </div>
            </div>
          </div>

          <!-- 下级类目列表表格 -->
          <section class="children-card">
            <div class="card-header">
              <div class="header-main">
                <span class="card-icon-badge"><i class="el-icon-s-unfold" /></span>
                <div>
                  <h3 class="card-title">{{ selected.id ? `「${selected.category_name}」下级子类目` : '根级主类目列表' }}</h3>
                  <span class="card-subtitle">共 {{ total }} 个下级分支</span>
                </div>
              </div>
              <el-button v-if="canManage" size="mini" type="success" icon="el-icon-plus" class="btn-theme-create" @click="openCreate(selected.id ? selected : null)">
                添加子类目
              </el-button>
            </div>

            <div class="table-container">
              <el-table
                v-loading="loading"
                :data="rows"
                size="small"
                stripe
                highlight-current-row
                class="category-data-table"
                @row-click="selectCategory"
              >
                <el-table-column prop="category_code" label="类目编码" min-width="130">
                  <template slot-scope="{ row }">
                    <span class="code-mono category-code-badge">
                      <i class="el-icon-postcard" />
                      {{ row.category_code }}
                    </span>
                  </template>
                </el-table-column>

                <el-table-column prop="category_name" label="类目名称" min-width="160">
                  <template slot-scope="{ row }">
                    <span class="category-name-cell">
                      <i :class="row.is_leaf ? 'el-icon-document leaf-icon' : 'el-icon-folder folder-icon'" />
                      <strong>{{ row.category_name }}</strong>
                    </span>
                  </template>
                </el-table-column>

                <el-table-column prop="direct_child_count" label="子类目数" width="100" align="center">
                  <template slot-scope="{ row }">
                    <span v-if="row.direct_child_count > 0" class="sub-count-tag">
                      {{ row.direct_child_count }} 个
                    </span>
                    <span v-else class="text-muted">0</span>
                  </template>
                </el-table-column>

                <el-table-column prop="direct_item_count" label="关联物料" width="110" align="center">
                  <template slot-scope="{ row }">
                    <el-button
                      v-if="row.direct_item_count > 0"
                      type="text"
                      size="small"
                      class="text-link-green code-mono"
                      @click.stop="goItems(row)"
                    >
                      <i class="el-icon-box" /> {{ row.direct_item_count }} 款
                    </el-button>
                    <span v-else class="text-muted">0</span>
                  </template>
                </el-table-column>

                <el-table-column prop="direct_supplier_count" label="供应商" width="110" align="center">
                  <template slot-scope="{ row }">
                    <el-button
                      v-if="row.direct_supplier_count > 0"
                      type="text"
                      size="small"
                      class="text-link-blue code-mono"
                      @click.stop="goSuppliers(row)"
                    >
                      <i class="el-icon-truck" /> {{ row.direct_supplier_count }} 家
                    </el-button>
                    <span v-else class="text-muted">0</span>
                  </template>
                </el-table-column>

                <el-table-column label="状态" width="90" align="center">
                  <template slot-scope="{ row }">
                    <el-tag size="mini" :type="row.status === 'enabled' ? 'success' : 'info'" effect="plain">
                      {{ row.status === 'enabled' ? '正常启用' : '已停用' }}
                    </el-tag>
                  </template>
                </el-table-column>

                <el-table-column label="操作" width="160" fixed="right" align="center">
                  <template slot-scope="{ row }">
                    <div class="row-actions">
                      <el-button type="text" size="small" class="text-theme-btn" @click.stop="selectCategory(row)">下钻</el-button>
                      <el-button v-if="canManage" type="text" size="small" @click.stop="openEdit(row)">编辑</el-button>
                      <el-button v-if="canManage" type="text" size="small" class="text-link-green" @click.stop="openCreate(row)">加子类</el-button>
                    </div>
                  </template>
                </el-table-column>
              </el-table>
            </div>

            <!-- 分页栏 -->
            <div class="pager-row">
              <span class="total-text">共 {{ total }} 条类目数据</span>
              <el-pagination
                small
                background
                layout="prev, pager, next, sizes"
                :current-page.sync="query.page"
                :page-size.sync="query.per_page"
                :page-sizes="[10, 20, 50]"
                :total="total"
                @current-change="loadChildren"
                @size-change="loadChildren"
              />
            </div>
          </section>
        </main>
      </div>
    </div>

    <!-- 规范居中弹窗：新增/编辑类目 (替代原侧边狭窄抽屉) -->
    <el-dialog
      :title="form.id ? '编辑物料类目' : '新增物料类目'"
      :visible.sync="drawerVisible"
      width="580px"
      append-to-body
      destroy-on-close
      class="category-dialog"
    >
      <div class="dialog-subtitle-chip">
        <i class="el-icon-folder" />
        <span>{{ form.parent_id ? `上级父类目：${parentName(form.parent_id)}` : '上级父类目：一级根类目' }}</span>
      </div>

      <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small" class="dialog-form">
        <div class="dialog-grid-2">
          <el-form-item label="类目编码" prop="category_code">
            <el-input v-model.trim="form.category_code" disabled placeholder="系统预占生成" class="code-mono">
              <template slot="append">系统预占</template>
            </el-input>
          </el-form-item>

          <el-form-item label="类目名称" prop="category_name" required>
            <el-input v-model.trim="form.category_name" clearable maxlength="80" placeholder="如：不锈钢材料 / 工业阀门" />
          </el-form-item>
        </div>

        <el-form-item label="上级父级类目">
          <el-select v-model="form.parent_id" clearable filterable class="full-width" placeholder="不选择则自动作为一级根类目">
            <el-option v-for="row in parentOptions" :key="row.id" :label="row.full_path" :value="row.id" />
          </el-select>
        </el-form-item>

        <div class="dialog-grid-2">
          <el-form-item label="显示排序">
            <el-input-number v-model="form.sort_order" :min="0" :max="9999" controls-position="right" class="full-width" />
          </el-form-item>

          <el-form-item label="启用状态">
            <div class="dialog-switch-box">
              <el-radio-group v-model="form.status" size="small" class="dialog-radio-group">
                <el-radio-button label="enabled"><i class="el-icon-check" /> 正常启用</el-radio-button>
                <el-radio-button label="disabled"><i class="el-icon-close" /> 停用</el-radio-button>
              </el-radio-group>
            </div>
          </el-form-item>
        </div>

        <el-form-item label="类目备注说明">
          <el-input
            v-model="form.remark"
            type="textarea"
            :rows="3"
            maxlength="300"
            show-word-limit
            placeholder="填写类目定义、适用材料范围或业务使用说明..."
          />
        </el-form-item>

        <div class="dialog-alert-note">
          <i class="el-icon-info" />
          <span>类目编码由系统统一分配不可修改；已有业务物料或供应商引用的类目只允许停用，不可直接删除。</span>
        </div>
      </el-form>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" @click="drawerVisible = false">取消</el-button>
        <el-button
          v-if="canManage"
          size="small"
          type="success"
          class="btn-dialog-save"
          :loading="saving || numberLoading"
          :disabled="!form.id && (!reservation || !form.category_code)"
          @click="save"
        >
          保存类目
        </el-button>
      </span>
    </el-dialog>
  </section>
</template>

<script>
import {
  listItemCategories,
  getItemCategoryTree,
  getItemCategory,
  saveItemCategory,
  disableItemCategory,
  enableItemCategory,
  deleteItemCategory
} from '../../../api/erp/master'
import {
  reserveForCreatePage,
  reserveFreshDocumentNumber,
  clearCreatePageReservation
} from '../../../utils/documentNumberReservation'

const emptyForm = () => ({
  id: null,
  category_code: '',
  category_name: '',
  parent_id: null,
  sort_order: 0,
  status: 'enabled',
  remark: ''
})

export default {
  name: 'ItemCategoryList',
  data() {
    return {
      loading: false,
      saving: false,
      numberLoading: false,
      reservation: null,
      drawerVisible: false,
      tree: [],
      treeKeyword: '',
      rows: [],
      total: 0,
      selected: {},
      form: emptyForm(),
      query: {
        keyword: '',
        status: '',
        page: 1,
        per_page: 20
      },
      rules: {
        category_code: [{ required: true, message: '系统编号生成失败，请重新打开新增页', trigger: 'change' }],
        category_name: [{ required: true, message: '请输入类目名称', trigger: 'blur' }]
      }
    }
  },
  computed: {
    flatRows() {
      const rows = []
      const visit = list =>
        (list || []).forEach(row => {
          rows.push(row)
          visit(row.children)
        })
      visit(this.tree)
      return rows
    },
    parentOptions() {
      return this.flatRows.filter(row => Number(row.id) !== Number(this.form.id))
    },
    canManage() {
      const profile = JSON.parse(localStorage.getItem('erp_me') || '{}')
      const permissions = JSON.parse(localStorage.getItem('erp_permissions') || '[]')
      return !!profile.is_super_admin || permissions.includes('item_category.manage')
    }
  },
  created() {
    this.initialize()
  },
  methods: {
    async initialize() {
      await this.loadTree()
      if (this.tree.length) await this.selectCategory(this.tree[0])
      else await this.loadChildren()
    },
    async loadTree() {
      try {
        const { data } = await getItemCategoryTree()
        this.tree = data.data || []
      } catch (e) {
        this.$message.error(e.userMessage || 'Item类目树加载失败')
      }
    },
    async loadChildren() {
      this.loading = true
      try {
        const params = { ...this.query }
        if (this.selected.id) params.parent_id = this.selected.id
        else params.root_only = 1
        const { data } = await listItemCategories(params)
        this.rows = data.data || []
        this.total = data.total || 0
      } catch (e) {
        this.$message.error(e.userMessage || '类目列表加载失败')
      } finally {
        this.loading = false
      }
    },
    async selectCategory(row) {
      try {
        const { data } = await getItemCategory(row.id)
        this.selected = data.data || row
        this.query.page = 1
        await this.loadChildren()
        this.$refs.categoryTree && this.$refs.categoryTree.setCurrentKey(row.id)
      } catch (e) {
        this.$message.error(e.userMessage || '类目详情加载失败')
      }
    },
    search() {
      this.query.page = 1
      this.loadChildren()
    },
    reset() {
      this.query = { keyword: '', status: '', page: 1, per_page: 20 }
      this.loadChildren()
    },
    filterTree(value) {
      this.$refs.categoryTree && this.$refs.categoryTree.filter(value)
    },
    filterNode(value, data) {
      if (!value) return true
      const q = String(value).toLowerCase()
      return `${data.category_code}${data.category_name}${data.full_path}`.toLowerCase().includes(q)
    },
    async openCreate(parent) {
      this.form = { ...emptyForm(), parent_id: parent?.id || null }
      this.reservation = null
      this.drawerVisible = true
      this.numberLoading = true
      try {
        this.reservation = await reserveForCreatePage('item_category', '/master/categories#create')
        this.form.category_code = this.reservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || 'Item类目编号预生成失败，请重新打开新增页')
      } finally {
        this.numberLoading = false
      }
    },
    openEdit(row) {
      this.reservation = null
      this.form = { ...emptyForm(), ...row }
      this.drawerVisible = true
    },
    save() {
      this.$refs.form.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          const payload = { ...this.form }
          if (!this.form.id && this.reservation) {
            payload.reservation_token = this.reservation.reservation_token
            payload.creation_session_id = this.reservation.creation_session_id
          }
          const { data } = await saveItemCategory(payload)
          if (!this.form.id) clearCreatePageReservation(this.reservation)
          this.$message.success('Item类目保存成功')
          this.drawerVisible = false
          await this.loadTree()
          if (data?.data?.id) await this.selectCategory({ id: data.data.id })
          else if (this.form.id) await this.selectCategory({ id: this.form.id })
          else if (this.form.parent_id) await this.selectCategory({ id: this.form.parent_id })
          else await this.initialize()
        } catch (e) {
          const errors = e.response?.data?.errors || {}
          if (!this.form.id && (errors.category_code || errors.reservation_token || errors.creation_session_id)) {
            await this.refreshGeneratedNumber(e)
            return
          }
          this.$message.error(e.userMessage || '保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    async refreshGeneratedNumber(error) {
      const old = this.reservation
      clearCreatePageReservation(old)
      this.reservation = null
      this.form.category_code = ''
      this.numberLoading = true
      try {
        this.reservation = await reserveFreshDocumentNumber('item_category', '/master/categories#create')
        this.form.category_code = this.reservation.document_no
        const errors = error.response?.data?.errors || {}
        const first = Object.values(errors)[0]
        this.$message.error(
          `${Array.isArray(first) ? first[0] : first || '编号冲突'}，系统已刷新编号，请重新确认保存。`
        )
      } catch (e) {
        this.$message.error(e.userMessage || '新编号生成失败，请关闭并重新打开新增页')
      } finally {
        this.numberLoading = false
      }
    },
    async toggleStatus(row) {
      const enabling = row.status !== 'enabled'
      try {
        await this.$confirm(
          enabling
            ? '启用前系统会检查全部上级类目，确认继续？'
            : '停用前系统会检查启用的子类目；历史 Item 与供应商关系不会删除。',
          enabling ? '启用类目' : '停用类目',
          { type: 'warning' }
        )
        await (enabling ? enableItemCategory : disableItemCategory)(row.id)
        this.$message.success(enabling ? '类目已启用' : '类目已停用')
        await this.loadTree()
        await this.selectCategory({ id: row.id })
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '操作失败')
      }
    },
    async deleteCategory(row) {
      try {
        await this.$confirm(
          `确认删除 Item 类目 ${row.category_code} / ${row.category_name}？仅无子类目且未被 Item 或供应商引用的停用类目可以删除。`,
          '删除类目',
          { type: 'warning', confirmButtonText: '确认删除' }
        )
        await deleteItemCategory(row.id)
        this.$message.success('Item 类目已删除')
        this.selected = {}
        await this.loadTree()
        await this.initialize()
      } catch (e) {
        if (e !== 'cancel' && e !== 'close') this.$message.error(e.userMessage || '类目删除失败')
      }
    },
    parentName(id) {
      return (this.flatRows.find(row => Number(row.id) === Number(id)) || {}).category_name || '-'
    },
    goItems(row = this.selected) {
      this.$router.push({ path: '/master/items', query: { category_id: row.id } })
    },
    goSuppliers(row = this.selected) {
      this.$router.push({ path: '/master/suppliers', query: { category_id: row.id } })
    },
    formatDate(v) {
      return v ? String(v).replace('T', ' ').slice(0, 16) : '-'
    }
  }
}
</script>

<style scoped>
.category-page {
  position: relative;
  min-height: calc(100vh - 52px);
  background: #f5f7fa;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  color: #1f2937;
  box-sizing: border-box;
}

.category-workspace {
  padding: 16px 20px 30px;
}

/* 高清等宽数字与编码字体规范 */
.code-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif !important;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  letter-spacing: 0.5px;
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

.head-tag {
  border-radius: 4px;
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

/* 顶部筛选与检索栏 */
.filter-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 16px;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  margin-bottom: 14px;
  flex-wrap: wrap;
  gap: 12px;
}

.filter-inputs {
  display: flex;
  align-items: center;
  gap: 16px;
  flex-wrap: wrap;
}

.filter-item {
  display: flex;
  align-items: center;
  gap: 8px;
}

.filter-label {
  font-size: 13px;
  font-weight: 600;
  color: #4b5563;
  white-space: nowrap;
}

.filter-item .el-input {
  width: 220px;
}

.status-select {
  width: 130px;
}

.filter-actions {
  display: flex;
  gap: 8px;
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
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #4b5563 !important;
}

.btn-theme-reset:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.filter-quick-info {
  font-size: 12px;
  color: #6b7280;
}

.path-hint {
  display: flex;
  align-items: center;
  gap: 6px;
}

.path-hint strong {
  color: #111827;
}

/* 核心工作区两列网格 */
.content-grid {
  display: grid;
  grid-template-columns: 290px minmax(0, 1fr);
  gap: 14px;
  align-items: start;
}

/* 通用卡片容器 */
.tree-card,
.detail-card,
.children-card {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
  box-sizing: border-box;
}

.card-header {
  padding: 12px 16px;
  background: #fafbfc;
  border-bottom: 1px solid #edf1f5;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.header-main {
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-icon-badge {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: #f0fdf4;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 15px;
  flex-shrink: 0;
}

.card-title {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #111827;
}

.card-subtitle {
  font-size: 12px;
  color: #6b7280;
}

/* 左侧类目树卡片 */
.tree-card {
  display: flex;
  flex-direction: column;
}

.btn-refresh-tree {
  color: #008b4b !important;
  font-size: 12px;
  padding: 0;
}

.tree-search-wrap {
  padding: 10px 14px;
  border-bottom: 1px solid #f3f4f6;
  background: #ffffff;
}

.tree-scroll-container {
  padding: 8px 10px 16px;
  max-height: 680px;
  overflow-y: auto;
}

/* 树节点样式定制 */
.custom-tree-node {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  padding-right: 8px;
  font-size: 13px;
  border-radius: 4px;
}

.node-main {
  display: flex;
  align-items: center;
  gap: 6px;
  overflow: hidden;
}

.folder-icon {
  color: #d97706;
  font-size: 15px;
}

.leaf-icon {
  color: #008b4b;
  font-size: 14px;
}

.node-name {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 150px;
  color: #374151;
}

.node-badges {
  display: flex;
  align-items: center;
  gap: 4px;
}

.count-badge {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
  font-size: 10px;
  font-weight: 600;
  padding: 1px 6px;
  border-radius: 10px;
  font-family: tabular-nums, sans-serif;
}

.disabled-dot {
  width: 6px;
  height: 6px;
  border-radius: 50%;
  background: #ef4444;
}

.tree-scroll-container ::v-deep .el-tree-node__content {
  height: 32px;
  border-radius: 4px;
}

.tree-scroll-container ::v-deep .el-tree-node.is-current > .el-tree-node__content {
  background-color: #f0fdf4 !important;
  color: #008b4b !important;
  font-weight: 600;
}

.tree-scroll-container ::v-deep .el-tree-node.is-current > .el-tree-node__content .node-name {
  color: #008b4b;
}

/* 右侧详情列 */
.detail-column {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
}

/* 当前选中类目卡片 */
.detail-card {
  padding: 16px 20px;
}

.detail-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  border-bottom: 1px solid #edf1f5;
  padding-bottom: 14px;
  flex-wrap: wrap;
  gap: 10px;
}

.title-with-badge {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.detail-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #111827;
}

.code-chip {
  display: inline-flex;
  align-items: center;
  gap: 5px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 8px;
  border-radius: 4px;
  color: #00763f;
  font-size: 12px;
}

.path-breadcrumb {
  display: flex;
  align-items: center;
  gap: 6px;
  margin-top: 6px;
  font-size: 12px;
  color: #6b7280;
}

.detail-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
}

/* 元属性网格 */
.meta-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px 18px;
  padding-top: 14px;
}

.meta-item {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.meta-item.col-span-full {
  grid-column: 1 / -1;
}

.meta-label {
  font-size: 12px;
  color: #6b7280;
  font-weight: 500;
}

.meta-val {
  font-size: 13px;
  font-weight: 600;
  color: #1f2937;
}

.meta-val.text-muted {
  font-weight: 500;
  color: #6b7280;
}

.meta-val.text-desc {
  font-weight: normal;
  color: #4b5563;
  line-height: 1.5;
}

/* 统计指标卡片行 */
.stat-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;
}

.stat-card {
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
}

.stat-card.linkable {
  cursor: pointer;
}

.stat-card.linkable:hover {
  border-color: #008b4b;
  box-shadow: 0 4px 12px rgba(0, 139, 75, 0.08);
  transform: translateY(-1px);
}

.stat-icon-box {
  width: 44px;
  height: 44px;
  border-radius: 8px;
  display: grid;
  place-items: center;
  font-size: 22px;
  flex-shrink: 0;
}

.item-icon {
  background: #f0fdf4;
  color: #008b4b;
  border: 1px solid #bbf7d0;
}

.supplier-icon {
  background: #eff6ff;
  color: #2563eb;
  border: 1px solid #bfdbfe;
}

.child-icon {
  background: #fefce8;
  color: #d97706;
  border: 1px solid #fef08a;
}

.stat-info {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
}

.stat-label {
  font-size: 12px;
  color: #6b7280;
  font-weight: 500;
}

.stat-value-row {
  display: flex;
  align-items: baseline;
  gap: 6px;
}

.stat-number {
  font-size: 22px;
  font-weight: 700;
  color: #111827;
}

.stat-unit {
  font-size: 12px;
  color: #9ca3af;
}

.stat-arrow {
  color: #9ca3af;
  font-size: 14px;
  transition: transform 0.2s ease;
}

.stat-card.linkable:hover .stat-arrow {
  color: #008b4b;
  transform: translateX(3px);
}

/* 下级类目表格卡片 */
.children-card {
  display: flex;
  flex-direction: column;
}

.table-container {
  overflow-x: auto;
}

.category-code-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  padding: 2px 7px;
  border-radius: 4px;
  color: #334155;
  font-size: 12px;
}

.category-name-cell {
  display: flex;
  align-items: center;
  gap: 8px;
  color: #1e293b;
}

.sub-count-tag {
  display: inline-block;
  padding: 2px 8px;
  background: #f1f5f9;
  border-radius: 10px;
  color: #475569;
  font-size: 11px;
  font-weight: 600;
}

.text-link-green {
  color: #008b4b !important;
  font-weight: 600;
  padding: 0 !important;
}

.text-link-blue {
  color: #2563eb !important;
  font-weight: 600;
  padding: 0 !important;
}

.text-theme-btn {
  color: #008b4b !important;
  font-weight: 600;
}

.row-actions {
  display: flex;
  justify-content: center;
  gap: 10px;
}

.text-muted {
  color: #9ca3af;
}

.pager-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 12px 18px;
  background: #ffffff;
  border-top: 1px solid #edf1f5;
}

.total-text {
  font-size: 13px;
  color: #6b7280;
}

/* 居中规范弹窗样式 */
.category-dialog ::v-deep .el-dialog {
  border-radius: 8px;
  overflow: hidden;
}

.category-dialog ::v-deep .el-dialog__header {
  padding: 16px 20px;
  background: #fafbfc;
  border-bottom: 1px solid #edf1f5;
}

.category-dialog ::v-deep .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #111827;
}

.category-dialog ::v-deep .el-dialog__body {
  padding: 18px 22px 10px;
}

.category-dialog ::v-deep .el-dialog__footer {
  padding: 12px 20px;
  border-top: 1px solid #edf1f5;
  background: #fafbfc;
}

.dialog-subtitle-chip {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 13px;
  font-weight: 500;
  margin-bottom: 16px;
}

.dialog-form {
  display: flex;
  flex-direction: column;
}

.dialog-grid-2 {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 14px;
}

.full-width {
  width: 100%;
}

.dialog-radio-group {
  width: 100%;
  display: flex;
}

.dialog-radio-group ::v-deep .el-radio-button {
  flex: 1;
}

.dialog-radio-group ::v-deep .el-radio-button__inner {
  width: 100%;
  padding: 9px 12px;
  text-align: center;
}

.dialog-radio-group ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background-color: #008b4b;
  border-color: #008b4b;
  box-shadow: -1px 0 0 0 #008b4b;
}

.dialog-alert-note {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  padding: 9px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  color: #64748b;
  font-size: 12px;
  line-height: 1.45;
  margin-top: 8px;
}

.dialog-alert-note i {
  color: #008b4b;
  margin-top: 2px;
  flex-shrink: 0;
}

.btn-dialog-save {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
}

.btn-dialog-save:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 响应式断点适配规则 */
@media (max-width: 1366px) {
  .meta-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 1100px) {
  .content-grid {
    grid-template-columns: 260px minmax(0, 1fr);
  }
  .stat-row {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .category-workspace {
    padding: 12px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .head-actions {
    width: 100%;
    display: flex;
    gap: 8px;
  }
  .head-actions .el-button {
    flex: 1;
  }
  .content-grid {
    grid-template-columns: 1fr;
  }
  .filter-card {
    flex-direction: column;
    align-items: flex-start;
  }
  .filter-inputs {
    width: 100%;
    flex-direction: column;
    align-items: stretch;
  }
  .filter-item {
    width: 100%;
  }
  .filter-item .el-input,
  .status-select {
    width: 100%;
  }
  .filter-actions {
    width: 100%;
    justify-content: flex-end;
  }
  .detail-head {
    flex-direction: column;
  }
  .detail-actions {
    width: 100%;
    justify-content: flex-start;
  }
  .dialog-grid-2 {
    grid-template-columns: 1fr;
  }
}
</style>
