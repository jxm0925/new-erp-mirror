<template>
  <el-dialog :title="title" :visible.sync="visible" width="760px" custom-class="production-material-dialog" append-to-body :close-on-click-modal="false" @closed="sequence++">
    <div class="pm-filters"><el-input v-model="keyword" size="small" clearable placeholder="输入编码或名称搜索" @keyup.enter.native="search" /><el-button type="success" size="small" @click="search">查询</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table v-loading="loading" :data="rows" border size="small" empty-text="暂无可选记录" @row-click="choose">
      <el-table-column width="48"><template slot-scope="{row}"><span @click.stop><el-checkbox v-if="multiple" :value="!!selected[row.id]" :aria-label="label(row)" @change="choose(row)" /><el-radio v-else :value="current && current.id" :label="row.id" @change="choose(row)"><span class="pm-sr">{{ label(row) }}</span></el-radio></span></template></el-table-column>
      <el-table-column :label="kind === 'people' ? '账号' : kind === 'serials' ? '序列号' : '编码'"><template slot-scope="{row}">{{ row.username || row.serial_no || row.warehouse_code }}</template></el-table-column>
      <el-table-column label="名称"><template slot-scope="{row}">{{ row.nickname || row.warehouse_name || row.serial_no }}</template></el-table-column>
    </el-table>
    <el-pagination class="pm-pagination" :current-page.sync="page" :page-size="20" :total="total" layout="total, prev, pager, next" @current-change="load" />
    <div v-if="multiple" class="pm-selected">已选 {{ Object.keys(selected).length }} 项 <el-tag v-for="row in Object.values(selected)" :key="row.id" closable @close="$delete(selected, row.id)">{{ label(row) }}</el-tag></div>
    <span slot="footer"><el-button size="small" @click="visible = false">取消</el-button><el-button size="small" type="success" :disabled="multiple ? !Object.keys(selected).length : !current" @click="confirm">确认选择</el-button></span>
  </el-dialog>
</template>
<script>
import { materialWorkspace } from '@/api/erp/production-materials'
export default {
  data: () => ({ visible: false, title: '', kind: '', multiple: false, rows: [], current: null, selected: {}, keyword: '', page: 1, total: 0, loading: false, error: '', sequence: 0, context: {}, callback: null }),
  methods: {
    label(row) { return row.nickname || row.serial_no || row.warehouse_name || row.username },
    open(kind, title, context, callback, selected = []) { this.sequence++; Object.assign(this, { kind, title, context, callback, rows: [], current: null, selected: {}, keyword: '', page: 1, total: 0, error: '', visible: true, multiple: kind === 'serials' }); selected.forEach(row => this.$set(this.selected, row.id, row)); this.load() },
    choose(row) { if (this.multiple) this.selected[row.id] ? this.$delete(this.selected, row.id) : this.$set(this.selected, row.id, row); else this.current = row },
    search() { this.page = 1; this.load() },
    async load() { const sequence = ++this.sequence; this.loading = true; try { const { data } = await materialWorkspace(this.kind === 'serials' ? 'serials' : 'options', { ...this.context, kind: this.kind === 'serials' ? undefined : this.kind, keyword: this.keyword, page: this.page, per_page: 20 }); if (sequence === this.sequence) { this.rows = data.data; this.total = data.total } } catch (e) { if (sequence === this.sequence) this.error = e.userMessage } finally { if (sequence === this.sequence) this.loading = false } },
    confirm() { this.callback(this.multiple ? Object.values(this.selected) : this.current); this.visible = false }
  }
}
</script>
