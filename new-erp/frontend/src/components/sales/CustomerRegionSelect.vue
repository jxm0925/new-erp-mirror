<template>
  <div>
    <el-row :gutter="12">
      <el-col v-for="(label, level) in labels" :key="level" :xs="12" :sm="6">
        <el-select
          :value="selected[level] || null"
          :placeholder="label"
          :aria-label="label"
          :disabled="disabled || loading || (level > 0 && !selected[level - 1]) || (level > 0 && !options[level].length)"
          :loading="loading"
          size="small"
          filterable
          clearable
          @change="selectRegion(level, $event)"
        >
          <el-option v-for="region in options[level]" :key="region.id" :value="Number(region.id)" :label="region.name" />
        </el-select>
      </el-col>
    </el-row>
    <div v-if="!value && legacyLabel" class="legacy-region">{{ legacyLabel }}</div>
    <el-button v-if="failed" type="text" size="small" @click="initialize(value)">地区加载失败，点击重试</el-button>
  </div>
</template>

<script>
import { getCustomerRegionPath, listCustomerRegions } from '@/api/erp/sales'

export default {
  name: 'CustomerRegionSelect',
  props: {
    value: { type: [Number, String], default: null },
    legacyLabel: { type: String, default: '' },
    disabled: { type: Boolean, default: false }
  },
  data: () => ({
    labels: ['国家/地区', '省/州', '城市', '区/县'],
    selected: [],
    options: [[], [], [], []],
    loading: false,
    failed: false,
    sequence: 0,
    currentId: null
  }),
  watch: {
    value: {
      immediate: true,
      handler(value) {
        if (Number(value) !== Number(this.currentId) || !this.options[0].length) this.initialize(value)
      }
    }
  },
  beforeDestroy() { this.sequence += 1 },
  methods: {
    async initialize(value) {
      const sequence = ++this.sequence
      this.loading = true
      this.failed = false
      try {
        const path = value ? (await getCustomerRegionPath(value)).data : []
        const parentIds = [0, ...path.slice(0, 3).map(row => Number(row.id))]
        const lists = await Promise.all(parentIds.map(id => listCustomerRegions(id)))
        if (sequence !== this.sequence) return
        this.selected = path.map(row => Number(row.id))
        this.options = [[], [], [], []].map((_, index) => lists[index] ? lists[index].data : [])
        this.currentId = value ? Number(value) : null
      } catch (error) {
        if (sequence !== this.sequence) return
        this.failed = true
      } finally {
        if (sequence === this.sequence) this.loading = false
      }
    },
    async selectRegion(level, value) {
      const sequence = ++this.sequence
      this.selected = this.selected.slice(0, level)
      if (value) this.selected.push(Number(value))
      for (let index = level + 1; index < 4; index += 1) this.$set(this.options, index, [])
      this.currentId = this.selected.length ? this.selected[this.selected.length - 1] : null
      this.$emit('input', this.currentId)
      this.failed = false
      if (!value || level === 3) return
      this.loading = true
      try {
        const { data } = await listCustomerRegions(value)
        if (sequence === this.sequence) this.$set(this.options, level + 1, data)
      } catch (error) {
        if (sequence === this.sequence) this.failed = true
      } finally {
        if (sequence === this.sequence) this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.el-select {
  width: 100%;
}
.legacy-region {
  color: #64748b;
  font-size: 12px;
  margin-top: 6px;
}
</style>
