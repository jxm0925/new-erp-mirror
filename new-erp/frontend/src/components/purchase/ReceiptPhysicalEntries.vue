<template>
  <section class="physical-entries">
    <div class="entry-heading"><strong>逐张板材尺寸（mm）</strong><span>{{ entries.length }} / {{ quantity }} 张</span></div>
    <div class="batch-fields">
      <el-input v-model="batch.length_mm" size="mini" placeholder="实际长度" />
      <el-input v-model="batch.width_mm" size="mini" placeholder="实际宽度" />
      <el-input v-model="batch.thickness_mm" size="mini" placeholder="实际厚度" />
      <el-input v-model="batch.nominal_thickness_mm" size="mini" placeholder="公称厚度（选填）" />
    </div>
    <el-button size="mini" @click="append">按以上尺寸补齐张数</el-button>
    <div v-for="(entry,index) in entries" :key="index" class="physical-entry">
      <div class="entry-heading"><span>第 {{ index + 1 }} 张</span><el-button type="text" size="mini" @click="remove(index)">删除</el-button></div>
      <div class="batch-fields">
        <label>实际长<el-input v-model="entry.dimensions.length_mm" size="mini" /></label>
        <label>实际宽<el-input v-model="entry.dimensions.width_mm" size="mini" /></label>
        <label>实际厚<el-input v-model="entry.dimensions.thickness_mm" size="mini" /></label>
        <label>公称厚（选填）<el-input v-model="entry.dimensions.nominal_thickness_mm" size="mini" /></label>
      </div>
    </div>
  </section>
</template>

<script>
export default {
  props: { entries: { type: Array, required: true }, quantity: { type: Number, required: true } },
  data: () => ({ batch: { length_mm: '', width_mm: '', thickness_mm: '', nominal_thickness_mm: '' } }),
  methods: {
    append() {
      if (!Number.isInteger(this.quantity) || this.quantity < 1 || this.quantity > 500) return this.$message.error('每个库位的板材张数须为 1 至 500 的整数')
      const valid = value => /^\d+(\.\d{1,2})?$/.test(String(value)) && Number(value) > 0
      if (!['length_mm', 'width_mm', 'thickness_mm'].every(key => valid(this.batch[key])) || (this.batch.nominal_thickness_mm && !valid(this.batch.nominal_thickness_mm))) return this.$message.error('请填写正数尺寸，最多保留两位小数')
      const rows = this.entries.slice()
      while (rows.length < this.quantity) rows.push({ dimensions: { ...this.batch } })
      this.$emit('change', rows)
    },
    remove(index) { this.$emit('change', this.entries.filter((entry, position) => position !== index)) }
  }
}
</script>

<style scoped>
.physical-entries{min-width:0;display:grid;gap:8px}.entry-heading{display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:12px}.batch-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.batch-fields label{min-width:0;font-size:11px;display:grid;gap:4px}.physical-entry{border-top:1px solid #edf0f2;padding-top:6px}.physical-entries>.el-button{justify-self:start;max-width:100%;white-space:normal}
</style>
