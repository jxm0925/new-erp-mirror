<template>
  <section class="physical-entries">
    <div class="entry-heading"><strong>{{ mode === 'length' ? '逐根管材长度' : '逐张板材尺寸' }}（mm）</strong><span>{{ measureUnit ? entries.length + ' ' + countUnit + ' · ' + measuredQuantity + ' / ' + quantity + ' ' + measureUnit : entries.length + ' / ' + quantity + ' ' + countUnit }}</span></div>
    <div class="batch-fields">
      <el-input v-model="batch.length_mm" size="mini" placeholder="实际长度" />
      <el-input v-if="mode !== 'length'" v-model="batch.width_mm" size="mini" placeholder="实际宽度" />
      <el-input v-if="mode !== 'length'" v-model="batch.thickness_mm" size="mini" placeholder="实际厚度" />
      <el-input v-if="mode !== 'length'" v-model="batch.nominal_thickness_mm" size="mini" placeholder="公称厚度（选填）" />
      <label v-if="measureUnit">本次添加{{ mode === 'length' ? '根数' : '张数' }}<el-input-number v-model="appendQty" size="mini" :min="1" :max="500" :precision="0" :step="1" step-strictly controls-position="right" /></label>
    </div>
    <el-button size="mini" @click="append">{{ measureUnit ? '按以上尺寸添加 ' + appendQty + ' ' + countUnit : '按以上尺寸补齐' + (mode === 'length' ? '根数' : '张数') }}</el-button>
    <div v-for="(entry,index) in entries" :key="index" class="physical-entry">
      <div class="entry-heading"><span>第 {{ index + 1 }} {{ countUnit }}</span><el-button type="text" size="mini" @click="remove(index)">删除</el-button></div>
      <div class="batch-fields">
        <label>实际长<el-input v-model="entry.dimensions.length_mm" size="mini" /></label>
        <label v-if="mode !== 'length'">实际宽<el-input v-model="entry.dimensions.width_mm" size="mini" /></label>
        <label v-if="mode !== 'length'">实际厚<el-input v-model="entry.dimensions.thickness_mm" size="mini" /></label>
        <label v-if="mode !== 'length'">公称厚（选填）<el-input v-model="entry.dimensions.nominal_thickness_mm" size="mini" /></label>
      </div>
    </div>
  </section>
</template>

<script>
export default {
  props: { entries: { type: Array, required: true }, quantity: { type: Number, required: true }, mode: { type: String, default: 'sheet' }, measureUnit: { type: String, default: '' } },
  data: () => ({ batch: { length_mm: '', width_mm: '', thickness_mm: '', nominal_thickness_mm: '' }, appendQty: 1 }),
  computed: {
    countUnit() { return this.mode === 'length' ? '根' : '张' },
    measuredQuantity() { return Number(this.entries.reduce((sum, entry) => sum + Number(entry.dimensions.length_mm || 0) * (this.mode === 'length' ? 1 / 1000 : Number(entry.dimensions.width_mm || 0) / 1000000), 0).toFixed(8)) }
  },
  methods: {
    append() {
      if (this.measureUnit ? !Number.isInteger(this.appendQty) || this.appendQty < 1 || this.entries.length + this.appendQty > 500 : !Number.isInteger(this.quantity) || this.quantity < 1 || this.quantity > 500) return this.$message.error('每个库位最多登记 500 张板材或 500 根管材，按件计量须为整数')
      const valid = value => /^\d+(\.\d{1,2})?$/.test(String(value)) && Number(value) > 0
      const keys = this.mode === 'length' ? ['length_mm'] : ['length_mm', 'width_mm', 'thickness_mm']
      if (!keys.every(key => valid(this.batch[key])) || (this.mode !== 'length' && this.batch.nominal_thickness_mm && !valid(this.batch.nominal_thickness_mm))) return this.$message.error('请填写正数尺寸，最多保留两位小数')
      const rows = this.entries.slice()
      const count = this.measureUnit ? rows.length + this.appendQty : this.quantity
      while (rows.length < count) rows.push({ dimensions: this.mode === 'length' ? { length_mm: this.batch.length_mm } : { ...this.batch } })
      this.$emit('change', rows)
    },
    remove(index) { this.$emit('change', this.entries.filter((entry, position) => position !== index)) }
  }
}
</script>

<style scoped>
.physical-entries{min-width:0;display:grid;gap:8px}.entry-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:12px}.batch-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.batch-fields label{min-width:0;font-size:11px;display:grid;gap:4px}.physical-entry{border-top:1px solid #edf0f2;padding-top:6px}.physical-entries>.el-button{justify-self:start;max-width:100%;white-space:normal}
</style>
