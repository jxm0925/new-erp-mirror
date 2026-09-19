<template>
  <el-dialog
    :visible.sync="dialogVisible"
    title="选择材料"
    width="920px"
    append-to-body
    custom-class="cutting-material-dialog"
    @open="onOpen"
  >
    <div class="selector-shell">
      <aside>
        <button
          v-for="cat in categories"
          :key="cat.key"
          type="button"
          :class="{ active: cat.key === activeCategory }"
          @click="activeCategory = cat.key; search()"
        >{{ cat.label }} <small>{{ cat.count != null ? cat.count : '' }}</small></button>
      </aside>
      <section class="results">
        <div class="toolbar">
          <el-input v-model="keyword" clearable placeholder="编码 / 名称 / 规格 / 实物号" @keyup.enter.native="search" />
          <el-button type="primary" @click="search">搜索</el-button>
        </div>
        <el-table :data="rows" border height="360" v-loading="loading" @selection-change="onSelect">
          <el-table-column type="selection" width="48" :selectable="row => !row.occupied" />
          <el-table-column prop="physical_no" label="实物号 / 批次" min-width="140">
            <template slot-scope="{ row }">{{ row.physical_no || row.batch_no || '-' }}</template>
          </el-table-column>
          <el-table-column prop="item_name" label="名称" min-width="140" />
          <el-table-column prop="spec" label="规格" min-width="160">
            <template slot-scope="{ row }">{{ row.spec || row.dimension_text || '-' }}</template>
          </el-table-column>
          <el-table-column label="状态" width="100">
            <template slot-scope="{ row }">{{ row.occupied ? '已被占用' : (row.status_label || '可用') }}</template>
          </el-table-column>
        </el-table>
        <div class="pager">
          <el-pagination
            small
            layout="total, prev, pager, next"
            :total="total"
            :current-page.sync="page"
            :page-size="perPage"
            @current-change="fetchRows"
          />
        </div>
      </section>
    </div>
    <div class="selected-bar">
      <strong>已选 {{ selected.length }}</strong>
      <div class="chips">
        <el-tag v-for="item in selected" :key="itemKey(item)" closable @close="removeSelected(item)">
          {{ item.physical_no || item.batch_no || item.item_name }}
        </el-tag>
      </div>
    </div>
    <span slot="footer">
      <el-button @click="dialogVisible = false">取消</el-button>
      <el-button type="success" @click="confirm">确认带回</el-button>
    </span>
  </el-dialog>
</template>

<script>
import { listMaterialPhysicals } from '../../../api/erp/cutting'

export default {
  name: 'CuttingMaterialSelector',
  props: {
    visible: { type: Boolean, default: false }
  },
  data: () => ({
    loading: false,
    keyword: '',
    page: 1,
    perPage: 20,
    total: 0,
    rows: [],
    selected: [],
    activeCategory: 'all',
    categories: [
      { key: 'all', label: '全部' },
      { key: 'plate', label: '钢板整板' },
      { key: 'remnant_rect', label: '矩形余料' },
      { key: 'remnant_irregular', label: '异形余料' },
      { key: 'tube', label: '方管' }
    ]
  }),
  computed: {
    dialogVisible: {
      get() { return this.visible },
      set(v) { this.$emit('update:visible', v) }
    }
  },
  methods: {
    itemKey(item) { return String(item.physical_material_id || item.id || item.physical_no || item.batch_no) },
    onOpen() { this.search() },
    search() { this.page = 1; this.fetchRows() },
    onSelect(rows) {
      const map = new Map(this.selected.map(x => [this.itemKey(x), x]))
      rows.forEach(r => map.set(this.itemKey(r), r))
      // keep previous pages
      this.selected = Array.from(map.values())
    },
    removeSelected(item) {
      this.selected = this.selected.filter(x => this.itemKey(x) !== this.itemKey(item))
    },
    confirm() {
      this.$emit('confirm', this.selected.slice())
    },
    async fetchRows() {
      this.loading = true
      try {
        const { data } = await listMaterialPhysicals({
          page: this.page,
          per_page: this.perPage,
          keyword: this.keyword || undefined,
          category: this.activeCategory === 'all' ? undefined : this.activeCategory
        })
        const payload = data.data || data
        this.rows = Array.isArray(payload) ? payload : (payload.data || [])
        this.total = data.total || payload.total || this.rows.length
        if (Array.isArray(data.categories) && data.categories.length) {
          this.categories = [{ key: 'all', label: '全部' }].concat(data.categories.map(c => ({
            key: c.key || c.code, label: c.label || c.name, count: c.count
          })))
        }
      } catch (error) {
        this.rows = []
        this.total = 0
        this.$message.error(error.userMessage || '材料列表加载失败')
      } finally {
        this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.selector-shell { display: grid; grid-template-columns: 180px 1fr; gap: 12px; min-height: 420px; }
aside { border: 1px solid #e6ebf0; border-radius: 6px; padding: 8px; overflow: auto; }
aside button { width: 100%; text-align: left; border: 0; background: transparent; padding: 10px 8px; border-radius: 4px; cursor: pointer; }
aside button.active, aside button:hover { background: #eefbf5; color: #067a55; }
aside small { float: right; color: #94a3b8; }
.results { min-width: 0; }
.toolbar { display: flex; gap: 8px; margin-bottom: 8px; }
.pager { margin-top: 8px; text-align: right; }
.selected-bar { margin-top: 12px; border-top: 1px solid #e6ebf0; padding-top: 10px; }
.chips { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
</style>
