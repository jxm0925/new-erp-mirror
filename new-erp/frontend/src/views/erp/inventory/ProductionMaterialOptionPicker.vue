<template>
  <el-dialog :title="title" :visible.sync="visible" width="760px" custom-class="production-material-dialog public-material-dialog" append-to-body :close-on-click-modal="false" @closed="sequence++">
    <div class="pm-filters"><el-input v-model="keyword" size="small" clearable placeholder="输入编码或名称搜索" @keyup.enter.native="search" /><el-button type="success" size="small" @click="search">查询</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" />
    <el-table v-loading="loading" :data="rows" border size="small" empty-text="暂无可选记录" @row-click="choose">
      <el-table-column width="48"><template slot-scope="{row}"><span @click.stop><el-checkbox v-if="multiple" :value="!!selected[row.id]" :aria-label="label(row)" @change="choose(row)" /><el-radio v-else :value="current && current.id" :label="row.id" @change="choose(row)"><span class="pm-sr">{{ label(row) }}</span></el-radio></span></template></el-table-column>
      <el-table-column :label="kind === 'people' ? '账号' : kind.endsWith('serials') ? '序列号' : kind.endsWith('physicals') ? '实物编号' : '编码'"><template slot-scope="{row}">{{ row.username || row.serial_no || row.physical_no || row.warehouse_code }}</template></el-table-column>
      <el-table-column :label="kind.endsWith('physicals') ? '实际尺寸' : '名称'"><template slot-scope="{row}">{{ kind.endsWith('physicals') ? dimensions(row.dimensions) : row.nickname || row.warehouse_name || row.serial_no }}</template></el-table-column>
    </el-table>
    <el-pagination class="pm-pagination" :current-page.sync="page" :page-size="20" :total="total" layout="total, prev, pager, next" @current-change="load" />
    <div v-if="multiple" class="pm-selected">已选 {{ Object.keys(selected).length }} 项 <el-tag v-for="row in Object.values(selected)" :key="row.id" closable @close="$delete(selected, row.id)">{{ label(row) }}</el-tag></div>
    <span slot="footer"><el-button size="small" @click="visible = false">取消</el-button><el-button size="small" type="success" :disabled="multiple ? !Object.keys(selected).length : !current" @click="confirm">确认选择</el-button></span>
  </el-dialog>
</template>
<script>
import { materialWorkspace } from '@/api/erp/production-materials'
import { onsiteCollectionSources } from '@/api/erp/public-materials'
export default {
  data: () => ({ visible: false, title: '', kind: '', multiple: false, rows: [], current: null, selected: {}, keyword: '', page: 1, total: 0, loading: false, error: '', sequence: 0, context: {}, callback: null }),
  methods: {
    label(row) { return row.nickname || row.serial_no || row.physical_no || row.warehouse_name || row.username },
    dimensions(value) { const size = value || {}; return [size.length_mm, size.width_mm, size.thickness_mm].filter(value => value !== undefined && value !== null).join(' × ') + ' mm' },
    open(kind, title, context, callback, selected = []) { this.sequence++; Object.assign(this, { kind, title, context, callback, rows: [], current: null, selected: {}, keyword: '', page: 1, total: 0, error: '', visible: true, multiple: ['serials', 'physicals', 'onsite-serials', 'onsite-physicals'].includes(kind) }); selected.forEach(row => this.$set(this.selected, row.id, row)); this.load() },
    choose(row) { if (this.multiple) this.selected[row.id] ? this.$delete(this.selected, row.id) : this.$set(this.selected, row.id, row); else this.current = row },
    search() { this.page = 1; this.load() },
    async load() { const sequence = ++this.sequence; this.loading = true; try { const tracked = ['serials', 'physicals', 'onsite-serials', 'onsite-physicals'].includes(this.kind); const { data } = await (this.kind.startsWith('onsite-') ? onsiteCollectionSources(this.kind.slice(7), { ...this.context, keyword: this.keyword, page: this.page, per_page: 20 }) : materialWorkspace(tracked ? this.kind : 'options', { ...this.context, kind: tracked ? undefined : this.kind, keyword: this.keyword, page: this.page, per_page: 20 })); if (sequence === this.sequence) { this.rows = data.data; this.total = data.total } } catch (e) { if (sequence === this.sequence) this.error = e.userMessage } finally { if (sequence === this.sequence) this.loading = false } },
    confirm() { this.callback(this.multiple ? Object.values(this.selected) : this.current); this.visible = false }
  }
}
</script>
