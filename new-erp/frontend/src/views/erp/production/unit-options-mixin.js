import { listItemUnitOptions } from '../../../api/erp/master'

// 物料单位换算：库存单位 + 物料档案“采购换算”（如 1 卷 = 300 米）。工单用料按库存单位记账，这里只做录入换算和折合显示。
export default {
  data: () => ({ itemUnitOptions: {} }),
  methods: {
    async ensureUnitOptions(ids) {
      const missing = [...new Set((ids || []).filter(Boolean).map(Number))].filter(id => !this.itemUnitOptions[id])
      if (!missing.length) return
      try {
        const { data } = await listItemUnitOptions(missing.slice(0, 500))
        Object.entries(data.data || {}).forEach(([id, list]) => this.$set(this.itemUnitOptions, Number(id), list || []))
      } catch (e) { /* 拿不到换算时只显示库存单位 */ }
    },
    unitOptionsOf(itemId) {
      return this.itemUnitOptions[Number(itemId)] || []
    },
    // 折合成默认采购单位，如“≈ 0.01 卷”；没有采购换算时不显示。
    altQtyText(itemId, value) {
      const option = this.unitOptionsOf(itemId).find(u => !u.is_base && u.is_default) || this.unitOptionsOf(itemId).find(u => !u.is_base)
      if (!option || !(Number(value) > 0)) return ''
      return `≈ ${Math.round(Number(value) / Number(option.factor) * 10000) / 10000} ${option.unit_name}`
    }
  }
}
