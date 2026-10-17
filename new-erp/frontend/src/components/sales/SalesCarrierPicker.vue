<template>
  <el-dialog title="选择承运商" :visible="visible" append-to-body width="min(820px, calc(100vw - 32px))" top="8vh" custom-class="sales-carrier-picker" :close-on-click-modal="false" @close="close">
    <div class="carrier-picker-content">
      <form class="carrier-search" @submit.prevent="search">
        <el-input v-model.trim="keyword" size="small" clearable placeholder="承运商名称 / 业务编码" @clear="search" />
        <el-button size="small" type="success" native-type="submit" icon="el-icon-search">查询</el-button>
      </form>
      <el-alert v-if="error" :title="error" type="error" :closable="false" />
      <el-table v-loading="loading" :data="rows" border size="small" max-height="330" highlight-current-row @row-click="selected = $event">
        <el-table-column label="选择" width="56" align="center"><template slot-scope="{ row }"><el-radio :value="selected && selected.carrier_option_id" :label="row.carrier_option_id" @change="selected = row"><span class="sr-only">{{ row.name }}</span></el-radio></template></el-table-column>
        <el-table-column prop="name" label="承运商名称" min-width="170" />
        <el-table-column label="业务编码" min-width="140"><template slot-scope="{ row }">{{ row.code || '—' }}</template></el-table-column>
      </el-table>
      <el-pagination background small :current-page="page" :page-size="10" :total="total" layout="total, prev, pager, next" @current-change="changePage" />
      <div class="carrier-selection">已选：{{ selected ? selected.name : '未选择' }}</div>
    </div>
    <span slot="footer"><el-button size="small" @click="close">取消</el-button><el-button size="small" type="success" :disabled="!selected || selected.status !== 'enabled' || loading || !!error" @click="confirm">确认选择</el-button></span>
  </el-dialog>
</template>

<script>
import { listArchives } from '@/api/erp/base-archives'

export default {
  name: 'SalesCarrierPicker',
  props: { visible: Boolean, current: { type: Object, default: null } },
  data: () => ({ rows: [], selected: null, keyword: '', page: 1, total: 0, loading: false, error: '', requestSerial: 0 }),
  watch: {
    visible(value) {
      if (!value) { this.requestSerial++; return }
      this.selected = this.current ? { ...this.current, status: null } : null
      this.rows = []; this.keyword = ''; this.page = 1; this.total = 0; this.error = ''
      this.load()
    }
  },
  beforeDestroy() { this.requestSerial++ },
  methods: {
    async load() {
      const serial = ++this.requestSerial
      this.loading = true; this.error = ''; this.rows = []
      try {
        const { data } = await listArchives('carriers', { keyword: this.keyword, status: 'enabled', page: this.page, per_page: 10 })
        if (serial !== this.requestSerial || !this.visible) return
        this.rows = data.data || []; this.total = Number(data.total || 0)
        const current = this.rows.find(row => Number(row.carrier_option_id) === Number(this.selected && this.selected.carrier_option_id))
        if (current) this.selected = current
      } catch (error) {
        if (serial === this.requestSerial) { this.error = error.userMessage || '承运商列表加载失败，请重新查询'; this.total = 0 }
      } finally { if (serial === this.requestSerial) this.loading = false }
    },
    search() { this.page = 1; this.load() },
    changePage(page) { this.page = page; this.load() },
    close() { this.$emit('update:visible', false) },
    confirm() { if (this.selected && this.selected.status === 'enabled') { this.$emit('selected', this.selected); this.close() } }
  }
}
</script>

<style scoped>
.carrier-picker-content { min-width: 0; max-height: 65vh; overflow: auto; }
.carrier-search { display: flex; gap: 8px; margin-bottom: 12px; }
.carrier-search > .el-input { flex: 1; min-width: 0; }
.carrier-search > .el-button { flex-shrink: 0; }
.carrier-selection { margin-top: 12px; overflow-wrap: anywhere; }
.el-pagination { max-width: 100%; overflow: auto; margin-top: 12px; }
.sr-only { position: absolute; width: 1px; height: 1px; overflow: hidden; clip: rect(0, 0, 0, 0); }
</style>
