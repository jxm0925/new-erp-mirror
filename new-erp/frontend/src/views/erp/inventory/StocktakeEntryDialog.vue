<template>
  <el-dialog :title="title" :visible="visible" :width="width" append-to-body :close-on-click-modal="false" :before-close="close">
    <div v-if="line" v-loading="loading">
      <div class="entry-meta">
        <span>账面 {{ line.book_qty == null || line.book_qty === '' ? '—' : num(line.book_qty) }}</span>
        <span v-if="stats">在账{{ isSerial ? '编号' : '实物' }} {{ stats.book }} 件，已确认 {{ stats.present + stats.missing }}，不在 {{ stats.missing }}，补录 {{ stats.new }}<template v-if="stats.locked">，业务占用 {{ stats.locked }}</template></span>
        <span v-if="isSerial">账面无编号 {{ num(line.unnumbered_book_qty || 0) }} 件</span>
      </div>
      <div class="simple-filters entry-filters">
        <el-input v-model.trim="keyword" size="small" clearable :placeholder="isSerial ? '搜索编号' : '搜索实物编号 / 尺寸'" @keyup.enter.native="reload(1)" @clear="reload(1)" />
        <el-select v-model="filter" size="small" clearable placeholder="全部" @change="reload(1)">
          <el-option label="未确认" value="undecided" /><el-option label="不在" value="missing" /><el-option label="补录" value="new" /><el-option label="业务占用" value="locked" />
        </el-select>
        <template v-if="editable">
          <el-button size="small" :disabled="loading || saving || !editableRows.length" @click="markPage(true)">本页 {{ editableRows.length }} 件在库</el-button>
          <el-button size="small" :disabled="loading || saving || !editableRows.length" @click="markPage(false)">本页 {{ editableRows.length }} 件不在</el-button>
        </template>
      </div>
      <el-table :data="rows" border size="small" max-height="360">
        <el-table-column :label="isSerial ? '编号' : '实物'" min-width="200">
          <template slot-scope="{ row }">
            <span :class="{ 'entry-locked': row.is_locked }">{{ row.serial_no || row.physical_label }}</span>
            <el-tag v-if="row.is_new" size="mini" type="success">补录</el-tag>
            <el-tag v-if="row.is_locked" size="mini" type="info">已被业务占用，不参与盘点</el-tag>
          </template>
        </el-table-column>
        <el-table-column v-if="!isSerial && $can('inventory.amount.view')" label="成本" width="110">
          <template slot-scope="{ row }">{{ formatMoney(row.total_cost) }}</template>
        </el-table-column>
        <el-table-column label="盘点结果" width="190">
          <template slot-scope="{ row }">
            <span v-if="row.is_locked">按账面</span>
            <template v-else-if="row.is_new">
              <span>在</span>
              <el-button v-if="editable" type="text" size="mini" @click="removeNew(row)">删除</el-button>
            </template>
            <el-radio-group v-else-if="editable" :value="presentOf(row)" :disabled="saving" size="mini" @input="setPresent([row], $event)">
              <el-radio-button :label="true">在</el-radio-button>
              <el-radio-button :label="false">不在</el-radio-button>
            </el-radio-group>
            <span v-else>{{ presentText(presentOf(row)) }}</span>
          </template>
        </el-table-column>
      </el-table>
      <div class="simple-pager">
        <el-pagination background small layout="total, prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="reload" />
      </div>
      <template v-if="editable">
        <div v-if="isSerial" class="entry-add">
          <el-input ref="serialInput" v-model.trim="serialNo" size="small" placeholder="补录盘到的账外编号，扫码或输入后回车" @keyup.enter.native="addSerial" />
          <el-button size="small" @click="addSerial">补录编号</el-button>
        </div>
        <div v-else-if="$can('inventory.amount.view')" class="entry-add">
          <el-input v-model.trim="physical.length_mm" size="small" placeholder="长 mm" />
          <el-input v-if="!isLength" v-model.trim="physical.width_mm" size="small" placeholder="宽 mm" />
          <el-input v-if="!isLength" v-model.trim="physical.thickness_mm" size="small" placeholder="厚 mm" />
          <el-input v-model.trim="physical.total_cost" size="small" placeholder="成本（元）" @blur="formatPhysicalCost" />
          <el-button size="small" @click="addPhysical">补录实物</el-button>
        </div>
        <div v-if="pendingNew.length" class="entry-pending">
          待保存补录：
          <el-tag v-for="(entry, index) in pendingNew" :key="index" size="small" closable @close="pendingNew.splice(index, 1)">{{ entry.serial_no || entryLabel(entry) }}</el-tag>
        </div>
        <div v-if="isSerial" class="entry-add">
          <span>无编号实盘数</span>
          <el-input-number v-model="unnumbered" :min="0" :precision="0" :controls="false" size="small" style="width: 120px" @change="unnumberedChanged = true" />
          <span class="entry-tip">{{ line.unnumbered_book_qty > 0 ? '盘到的无编号件数' : '没有无编号件可不填' }}</span>
        </div>
      </template>
    </div>
    <span slot="footer">
      <el-button size="small" :disabled="saving" @click="close()">取消</el-button>
      <el-button v-if="editable" size="small" type="success" :loading="saving" @click="save">保存逐件结果</el-button>
    </span>
  </el-dialog>
</template>

<script>
import { listStocktakeEntries, listStocktakeSourceEntries, saveStocktakeCounts } from '@/api/erp/inventory'
import { formatMoney, settlementAmount } from '@/utils/money'

// 序列号/实物逐件盘点：勾选在/不在、补录账外新件，保存后由后端重算该行实盘数。
export default {
  name: 'StocktakeEntryDialog',
  props: {
    visible: Boolean,
    stocktake: { type: Object, default: null },
    line: { type: Object, default: null },
    editable: Boolean
  },
  data() {
    return {
      loading: false, saving: false, rows: [], total: 0, page: 1, keyword: '', filter: '',
      changes: {}, pendingNew: [], deleteIds: [], unnumbered: undefined, unnumberedChanged: false, serialNo: '', loadSequence: 0,
      physical: { length_mm: '', width_mm: '', thickness_mm: '', total_cost: '' },
      width: window.innerWidth < 768 ? '96%' : '760px'
    }
  },
  computed: {
    isSerial() { return this.line && this.line.count_mode === 'serial' },
    isLength() { return this.line && this.line.item && this.line.item.cutting_mode === 'length' },
    editableRows() { return this.rows.filter(row => !row.is_new && !row.is_locked) },
    stats() { return this.line && this.line.entry_stats },
    title() { return this.line ? `逐件盘点 ${this.line.item ? this.line.item.item_code + ' ' + this.line.item.item_name : ''}（批次 ${this.line.batch_no}）` : '逐件盘点' },
    dirty() { return Object.keys(this.changes).length > 0 || this.pendingNew.length > 0 || this.deleteIds.length > 0 || this.unnumberedChanged }
  },
  watch: {
    visible(value) { if (value) this.reset(); else this.loadSequence++ }
  },
  methods: {
    formatMoney,
    formatPhysicalCost() {
      if (/^\d+(\.\d*)?$/.test(this.physical.total_cost)) this.physical.total_cost = settlementAmount(this.physical.total_cost)
    },
    num(value) { const n = Number(value || 0); return Number.isInteger(n) ? String(n) : String(Number(n.toFixed(4))) },
    reset() {
      Object.assign(this, { rows: [], total: 0, page: 1, keyword: '', filter: '', changes: {}, pendingNew: [], deleteIds: [], serialNo: '' })
      this.physical = { length_mm: '', width_mm: '', thickness_mm: '', total_cost: '' }
      this.unnumbered = this.line && this.line.unnumbered_counted_qty !== null && this.line.unnumbered_counted_qty !== undefined ? Number(this.line.unnumbered_counted_qty) : undefined
      this.unnumberedChanged = false
      this.reload(1)
    },
    async reload(page = 1) {
      const sequence = ++this.loadSequence
      this.loading = true
      this.rows = []
      try {
        const params = { page, per_page: 10, keyword: this.keyword || undefined, filter: this.filter || undefined }
        const { data } = await (this.line.id ? listStocktakeEntries(this.stocktake.id, this.line.id, params)
          : listStocktakeSourceEntries(this.stocktake.id, { ...params, source_key: this.line.source_key }))
        if (sequence !== this.loadSequence) return
        this.rows = data.data.filter(row => !this.deleteIds.includes(row.id))
        this.total = data.total
        this.page = data.current_page
      } catch (e) {
        if (sequence === this.loadSequence) this.$message.error((e && e.userMessage) || '逐件明细加载失败')
      } finally {
        if (sequence === this.loadSequence) this.loading = false
      }
    },
    presentOf(row) {
      if (Object.prototype.hasOwnProperty.call(this.changes, row.id)) return this.changes[row.id]
      return row.counted_present
    },
    presentText(value) { return value === true ? '在' : value === false ? '不在' : '未确认' },
    setPresent(rows, value) {
      const changes = { ...this.changes }
      rows.forEach(row => { if (value === row.counted_present) delete changes[row.id]; else changes[row.id] = value })
      if (Object.keys(changes).length > 500) return this.$message.warning('一次可保存 500 件，请先保存已录结果，再继续盘点')
      this.changes = changes
    },
    markPage(value) {
      if (this.loading || this.saving) return
      this.setPresent(this.editableRows, value)
    },
    removeNew(row) {
      this.deleteIds.push(row.id)
      this.rows = this.rows.filter(item => item.id !== row.id)
    },
    addSerial() {
      const serialNo = this.serialNo
      if (!serialNo) return
      if (this.pendingNew.some(entry => entry.serial_no === serialNo)) return this.$message.warning(`编号 ${serialNo} 已在待保存列表中`)
      this.pendingNew.push({ serial_no: serialNo })
      this.serialNo = ''
      this.$nextTick(() => this.$refs.serialInput && this.$refs.serialInput.focus())
    },
    addPhysical() {
      if (!this.$can('inventory.amount.view')) return
      const p = this.physical
      const size = /^\d{1,10}(\.\d{1,2})?$/
      const dimensions = this.isLength ? { length_mm: p.length_mm } : { length_mm: p.length_mm, width_mm: p.width_mm, thickness_mm: p.thickness_mm }
      if (!Object.values(dimensions).every(v => size.test(v) && Number(v) > 0)) return this.$message.warning(this.isLength ? '请填写大于 0 的长度（最多两位小数）' : '请填写大于 0 的长、宽、厚（最多两位小数）')
      const cost = settlementAmount(p.total_cost)
      if (!/^\d+(\.\d*)?$/.test(p.total_cost) || !/^\d{1,14}\.\d{2}$/.test(cost)) return this.$message.warning('请填写有效的非负成本金额')
      this.pendingNew.push({ dimensions, total_cost: cost })
      this.physical = { length_mm: '', width_mm: '', thickness_mm: '', total_cost: '' }
    },
    entryLabel(entry) { return `${Object.values(entry.dimensions).join('×')} mm${this.$can('inventory.amount.view') ? ` / ${formatMoney(entry.total_cost)} 元` : ''}` },
    async close(done) {
      if (this.saving) return
      if (this.dirty) {
        try { await this.$confirm('逐件结果尚未保存，关闭将放弃这些修改。确认关闭？', '未保存修改', { type: 'warning' }) } catch (e) { return }
      }
      if (typeof done === 'function') done()
      this.$emit('update:visible', false)
    },
    async save() {
      if (this.line.source_error) return
      const payload = {
        ...(this.line.id ? { id: this.line.id } : { source_key: this.line.source_key }),
        entries: Object.entries(this.changes).map(([id, present]) => ({ id: Number(id), counted_present: present })),
        new_entries: this.pendingNew,
        delete_entry_ids: this.deleteIds
      }
      if (this.isSerial) payload.unnumbered_counted_qty = this.unnumbered === undefined ? null : this.unnumbered
      this.saving = true
      try {
        const { data } = await saveStocktakeCounts(this.stocktake.id, [payload], this.stocktake.business_version)
        this.$message.success('逐件结果已保存')
        this.changes = {}
        this.pendingNew = []
        this.deleteIds = []
        this.unnumberedChanged = false
        this.$emit('saved', data.data.business_version)
        this.$emit('update:visible', false)
      } catch (e) {
        this.$message.error((e && e.userMessage) || '保存失败')
      } finally {
        this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.entry-meta {
  display: flex;
  flex-wrap: wrap;
  gap: 16px;
  margin-bottom: 8px;
  color: #606266;
  font-size: 13px;
}
.entry-filters,
.entry-add {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: 8px;
  margin: 8px 0;
}
.entry-add .el-input {
  width: 120px;
}
.entry-add .el-input:first-child {
  flex: 1;
  min-width: 160px;
}
.entry-pending {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  align-items: center;
  font-size: 13px;
}
.entry-locked {
  color: #c0c4cc;
}
.entry-tip {
  color: #909399;
  font-size: 12px;
}
</style>
