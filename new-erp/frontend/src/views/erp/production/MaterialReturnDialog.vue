<template>
  <el-dialog
    title="生产退料"
    :visible="visible"
    width="680px"
    custom-class="master-style-dialog"
    :close-on-click-modal="false"
    @update:visible="v => $emit('update:visible', v)"
  >
    <div v-loading="loading">
      <el-alert v-if="loadError" :title="loadError" type="error" :closable="false" />
      <p v-if="dispositionId" class="dialog-hint">本次只退 {{ returnUnitText || '所选编号' }} 的材料，其余编号的材料保持原安排。</p>
      <p class="dialog-hint">{{ operationName }}：把领多、领错、不良或报废设备拆下的料退回仓库，按原收料的批次和单价冲回库存和工单材料成本。</p>
      <el-form label-position="top" size="small" :disabled="saving || pendingRequest">
        <el-form-item label="退料类型">
          <el-radio-group v-model="form.return_type">
            <el-radio-button v-for="t in types" :key="t.value" :label="t.value">{{ t.label }}</el-radio-button>
          </el-radio-group>
          <div class="text-muted hint">{{ typeHint }}</div>
        </el-form-item>
        <el-form-item v-for="m in visibleMaterials" :key="m.material_id" :label="materialLabel(m)">
          <el-select v-if="m.by_number && serialsFor(m).length" v-model="form.serials[m.material_id]" multiple filterable placeholder="选择要退的编号" class="full">
            <el-option v-for="s in serialsFor(m)" :key="s.id" :label="serialLabel(s)" :value="s.id" />
          </el-select>
          <div v-if="m.by_qty && limitOf(m) > 0" :class="{ locate: m.by_number && serialsFor(m).length }">
            <el-input-number v-model="form.qty[m.material_id]" :min="0" :max="limitOf(m)" :precision="4" controls-position="right" />
            <span class="text-muted hint-inline">{{ m.by_number ? '无编号部分' : '' }}最多可退 {{ qty(limitOf(m)) }} {{ m.unit_name || '' }}</span>
          </div>
        </el-form-item>
        <p v-if="!loading && !visibleMaterials.length" class="dialog-hint text-muted">本道按“{{ typeLabel }}”没有可退的料。</p>
        <el-form-item label="退料原因">
          <el-input v-model="form.reason" type="textarea" :rows="2" maxlength="500" placeholder="例如：多领 2 个、型号领错、来料不良、报废设备拆回" />
        </el-form-item>
        <el-form-item v-if="form.direct && selectedSerialNos.length" :label="'逐个核对序列号 · 已核对 ' + Object.keys(checkedSerials).filter(sn => selectedSerialNos.includes(sn)).length + ' / ' + selectedSerialNos.length"><el-input v-model="serialCode" placeholder="扫描或输入序列号后回车" @keyup.enter.native="checkSerial"><el-button slot="append" @click="checkSerial">核对</el-button></el-input></el-form-item>
        <template v-if="canApprove">
          <el-checkbox v-model="form.direct">提交时同时接收入库（需核对全部实物与实际库位）</el-checkbox>
          <el-form-item v-if="form.direct" label="退入库位" class="locate">
            <div class="picker-row">
              <el-button size="small" @click="openPicker('warehouses')">{{ form.warehouse_name || '选仓库' }}<i class="el-icon-search" /></el-button>
              <el-button size="small" :disabled="!form.warehouse_id" @click="openPicker('locations')">{{ form.location_name || '选库位' }}<i class="el-icon-search" /></el-button>
            </div>
          </el-form-item>
        </template>
      </el-form>
      <p class="dialog-hint text-muted">* 不良退回会冲减已领数量，退后要重新收料才能继续开工；报废设备拆回不冲减已领。</p>
    </div>
    <span slot="footer">
      <el-button size="small" @click="$emit('update:visible', false)">取消</el-button>
      <el-button size="small" type="success" class="btn-theme-create" :loading="saving" :disabled="!canSubmit" @click="submit">{{ pendingRequest ? '核对上次申请结果' : form.direct ? '确认退料入库' : '提交退料申请' }}</el-button>
    </span>
    <system-record-picker :visible.sync="picker.visible" :type="picker.type" :multiple="false" :value="[]" :warehouse-id="Number(form.warehouse_id || 0)" :location-purposes="['general']" management-scope="factory" @confirm="pick" />
  </el-dialog>
</template>

<script>
import { getMaterialReturnOptions, createMaterialReturn } from '../../../api/erp/production'
import SystemRecordPicker from '../../../components/system/SystemRecordPicker.vue'
import { qty, errorText } from './mfg-helpers'
import { pendingWarehouseCommand } from '@/api/erp/warehouse'

const HINTS = {
  surplus: '多领或领错的料，最多退到“已领 − 已到本道的设备应用量”，冲减已领数量。',
  defective: '装上后发现不良的料，冲减已领数量，退后重新收料补上。',
  scrapped: '报废设备上还能用的料拆回仓库，不冲减已领数量。',
  cancellation: '订单变更或取消后拆回可用料，按原领用来源申请退回，仓库接收入库后形成库存。'
}

export default {
  name: 'MaterialReturnDialog',
  components: { SystemRecordPicker },
  props: {
    visible: Boolean,
    workOrderId: { type: Number, default: 0 },
    operationId: { type: Number, default: 0 },
    operationName: { type: String, default: '' },
    defaultReturnType: { type: String, default: 'surplus' },
    dispositionId: { type: Number, default: 0 }
  },
  data: () => ({
    loading: false, saving: false, types: [], materials: [], canApprove: false, pendingRequest: false, checkedSerials: {}, serialCode: '', loadError: '', returnUnitText: '',
    form: { return_type: 'surplus', qty: {}, serials: {}, reason: '', direct: false, original: true, warehouse_id: null, warehouse_name: '', location_id: null, location_name: '' },
    picker: { visible: false, type: 'warehouses' }
  }),
  computed: {
    selectedSerialNos() { return this.lines.flatMap(line => { const material = this.materials.find(m => m.material_id === line.material_id); return (material?.serials || []).filter(sn => line.serial_ids.includes(sn.id)).map(sn => sn.serial_no) }) },
    typeHint() { return HINTS[this.form.return_type] || '' },
    typeLabel() { const t = this.types.find(x => x.value === this.form.return_type); return t ? t.label : '' },
    visibleMaterials() { return this.materials.filter(m => (m.by_number && this.serialsFor(m).length) || (m.by_qty && this.limitOf(m) > 0)) },
    lines() {
      return this.visibleMaterials.map(m => ({
        material_id: m.material_id,
        serial_ids: m.by_number ? (this.form.serials[m.material_id] || []).filter(id => this.serialsFor(m).some(s => s.id === id)) : [],
        qty: m.by_qty && this.limitOf(m) > 0 ? Math.min(Number(this.form.qty[m.material_id] || 0), this.limitOf(m)) : 0
      })).filter(l => l.serial_ids.length || l.qty > 0)
    },
    canSubmit() {
      return this.pendingRequest || (!this.loading && !this.loadError && this.lines.length && this.form.reason.trim() && (!this.form.direct || (this.form.location_id && this.selectedSerialNos.every(sn => this.checkedSerials[sn]))))
    }
  },
  watch: {
    visible(v) { if (v) this.load() }
  },
  methods: {
    qty,
    checkSerial() { const code = this.serialCode.trim().replace(/^ERP:SN:/i, ''); if (!this.selectedSerialNos.includes(code)) return this.$message.error('该序列号不属于本次退料'); if (this.checkedSerials[code]) return this.$message.warning('该序列号已核对'); this.$set(this.checkedSerials, code, true); this.serialCode = '' },
    async load() {
      this.loading = true
      this.materials = []; this.types = []; this.loadError = ''; this.pendingRequest = false; this.returnUnitText = ''
      this.checkedSerials = {}; this.serialCode = ''
      this.form = { return_type: 'surplus', qty: {}, serials: {}, reason: '', direct: false, original: true, warehouse_id: null, warehouse_name: '', location_id: null, location_name: '' }
      try {
        const pending = pendingWarehouseCommand('material_return.request', this.operationId)
        if (pending && Number(pending.payload.disposition_id || 0) !== this.dispositionId) throw new Error('本道工序另有其他编号的退料结果待核对，请先回到原申请核对，再办理本次退料')
        this.pendingRequest = Boolean(pending)
        if (this.defaultReturnType === 'cancellation' && !this.dispositionId) throw new Error('请从本次设备处置进入退料，先确定要退哪台的材料')
        const { data } = await getMaterialReturnOptions(this.workOrderId, this.operationId, this.dispositionId ? { disposition_id: this.dispositionId } : undefined)
        const d = data.data || {}
        this.returnUnitText = d.unit_serial_no || d.target_unit?.serial_no || (d.unit_no ? `第${d.unit_no}个` : '')
        this.types = this.dispositionId ? (d.types || []).filter(t => t.value === 'cancellation') : d.types || []
        if (this.dispositionId && !this.types.length) throw new Error('这台设备当前不能办理处置退料，请返回核对处理进度')
        this.form.return_type = this.types.some(t => t.value === this.defaultReturnType) ? this.defaultReturnType : (this.types[0]?.value || 'surplus')
        this.canApprove = !!d.can_approve
        this.materials = d.materials || []
        this.materials.forEach(m => {
          this.$set(this.form.qty, m.material_id, 0)
          this.$set(this.form.serials, m.material_id, [])
        })
      } catch (e) {
        this.loadError = errorText(e, e?.message || '读取可退物料失败')
        this.$message.error(this.loadError)
      } finally {
        this.loading = false
      }
    },
    limitOf(m) { return Number((m.limits || {})[this.form.return_type] || 0) },
    serialsFor(m) {
      if (this.form.return_type === 'cancellation') return m.serials || []
      const scrapped = this.form.return_type === 'scrapped'
      return (m.serials || []).filter(s => (s.kind === 'scrapped') === scrapped)
    },
    materialLabel(m) {
      const spec = m.specification || m.component_item_spec ? ` [${m.specification || m.component_item_spec}]` : ''
      return `${m.component_item_name}${spec}（已领 ${qty(m.issued_qty)} / 应领 ${qty(m.required_qty)} ${m.unit_name || ''}${m.serial_mode === 'required' ? '，按编号退' : (m.by_number ? '，有编号的选编号' : '')}）`
    },
    serialLabel(s) {
      const where = s.unit_serial_no ? `装在 ${s.unit_serial_no}${s.kind === 'scrapped' ? '（报废）' : ''}` : '未装到设备'
      return `${s.serial_no} · ${where}`
    },
    openPicker(type) { this.picker = { visible: true, type } },
    pick(rows) {
      const row = rows[0]
      if (!row) return
      if (this.picker.type === 'warehouses') {
        if (Number(this.form.warehouse_id) !== Number(row.id)) { this.form.location_id = null; this.form.location_name = '' }
        this.form.warehouse_id = row.id
        this.form.warehouse_name = row.warehouse_name
      } else {
        this.form.location_id = row.id
        this.form.location_name = [row.location_code, row.location_name].filter(Boolean).join(' ')
      }
    },
    async submit() {
      if (!this.canSubmit || this.saving) return
      const f = this.form
      const payload = { return_type: f.return_type, reason: f.reason.trim(), lines: this.lines, with_detail: true }
      if (this.dispositionId) payload.disposition_id = this.dispositionId
      if (f.direct) {
        payload.direct = true
        payload.location_id = f.location_id
        payload.serial_nos = this.selectedSerialNos.filter(sn => this.checkedSerials[sn])
      }
      this.saving = true
      try {
        const { data } = await createMaterialReturn(this.workOrderId, this.operationId, payload)
        this.$message.success(data.message || '已提交')
        this.$emit('update:visible', false)
        this.$emit('done')
      } catch (e) {
        this.pendingRequest = Boolean(e.pendingCommand)
        this.$message.error(errorText(e, '退料失败'))
      } finally {
        this.saving = false
      }
    }
  }
}
</script>

<style scoped>
.full { width: 100%; }
.hint { font-size: 12px; line-height: 1.6; margin-top: 4px; }
.hint-inline { font-size: 12px; margin-left: 8px; }
.picker-row { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 6px; }
.locate { margin-top: 8px; }
@media (max-width: 767px) {
  .hint-inline { display: block; margin: 4px 0 0; }
}
</style>
