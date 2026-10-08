<template>
  <section>
    <p class="pm-muted">下料板材和长料在现场领用。只有生产目标当前责任人可以确认领料。</p>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <div v-for="job in pending" :key="job.key" class="pm-inline-field"><span>现场领料结果尚未确认</span><el-button size="small" :loading="busy" @click="recover(job)">核对上次领料</el-button></div>
    <el-table :data="rows" border size="small" v-loading="loading">
      <el-table-column prop="work_order_no" label="工单" min-width="175" />
      <el-table-column prop="item_name" label="物料" min-width="145" />
      <el-table-column prop="spec" label="规格" min-width="115" />
      <el-table-column label="待领数量" min-width="100"><template slot-scope="{row}">{{ row.remaining_qty }} {{ row.unit_name_snapshot }}</template></el-table-column>
      <el-table-column label="本次领料" width="135"><template slot-scope="{row}"><el-input v-model="row.accepted" size="small" inputmode="decimal" placeholder="填写数量" /></template></el-table-column>
      <el-table-column label="具体原料" min-width="140"><template slot-scope="{row}"><el-button v-if="row.material_management_mode === 'physical'" type="text" @click="select(row, 'physicals')">选择板材 {{ row.physicals.length }} 张</el-button><el-button v-if="row.serial_control_type !== 'none'" type="text" @click="select(row, 'serials')">选择序列号 {{ row.serials.length }} 件</el-button><span v-if="row.material_management_mode !== 'physical' && row.serial_control_type === 'none'">按数量领料</span></template></el-table-column>
      <el-table-column label="操作" width="110"><template slot-scope="{row}"><el-button type="text" :disabled="busy || !valid(row) || !$can('production.material_receipt.confirm')" @click="receive(row)">确认现场领料</el-button></template></el-table-column>
    </el-table>
    <el-pagination :pager-count="5" class="pm-pagination" :current-page.sync="page" :total="total" :page-size="20" layout="total, prev, pager, next" @current-change="load" />
    <option-picker ref="options" />
  </section>
</template>
<script>
import { onsiteCollections, receiveOnsite, materialWrite, pendingMaterialWrites } from '@/api/erp/public-materials'
import OptionPicker from './ProductionMaterialOptionPicker.vue'
export default {
  components: { OptionPicker },
  data: () => ({ rows: [], page: 1, total: 0, loading: false, busy: false, pending: [], error: '', sequence: 0 }),
  mounted() { this.load() },
  beforeDestroy() { this.sequence++ },
  methods: {
    async load() {
      const sequence = ++this.sequence; this.loading = true; this.error = ''
      this.pending = pendingMaterialWrites('onsite-')
      try {
        const { data } = await onsiteCollections({ page: this.page, per_page: 20 })
        if (sequence === this.sequence) { this.rows = data.data.map(row => ({ ...row, accepted: '', physicals: [], serials: [] })); this.total = data.total }
      } catch (e) { if (sequence === this.sequence) this.error = e.userMessage || e.message }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    valid(row) {
      return /^\d+(\.\d{1,8})?$/.test(row.accepted) && Number(row.accepted) > 0 && Number(row.accepted) <= Number(row.remaining_qty)
        && (row.material_management_mode !== 'physical' || Number(row.accepted) === row.physicals.length)
        && (row.serial_control_type === 'none' || Number(row.accepted) === row.serials.length)
    },
    select(row, kind) {
      this.$refs.options.open(`onsite-${kind}`, kind === 'physicals' ? '选择现场领用板材' : '选择领料序列号', { picking_task_line_id: row.id }, selected => { row[kind] = selected; row.accepted = String(selected.length) }, row[kind])
    },
    async receive(row) {
      if (this.busy || !this.valid(row)) return
      this.busy = true; this.error = ''
      try {
        const payload = { expected_version: row.task_version, lines: [{ picking_task_line_id: row.id, accepted_qty: row.accepted, physical_material_ids: row.physicals.map(item => item.id), accepted_serial_ids: row.serials.map(item => item.id) }] }
        const { data } = await materialWrite(`onsite-${row.task_id}-${row.id}`, payload, body => receiveOnsite(row.task_id, body))
        this.$message.success(data.message); await this.load()
      } catch (e) { this.error = e.userMessage || '领料结果尚未确认，请重试确认上次操作。' }
      finally { this.busy = false; this.pending = pendingMaterialWrites('onsite-') }
    },
    async recover(job) {
      if (this.busy) return
      const match = /^onsite-(\d+)-(\d+)$/.exec(job.key)
      if (!match) return
      this.busy = true
      try { const { data } = await materialWrite(job.key, {}, body => receiveOnsite(Number(match[1]), body)); this.$message.success(data.message); await this.load() }
      catch (e) { this.error = e.userMessage || e.message }
      finally { this.busy = false; this.pending = pendingMaterialWrites('onsite-') }
    }
  }
}
</script>
