<template>
  <!-- 采购价格对比：本次单价与历史采购价（按库存基本单位），超过预警比例标红。 -->
  <div v-if="result && result.lines && result.lines.length" class="price-check-panel" :class="{ 'has-warning': warnings.length }">
    <div class="price-check-head">
      <i :class="warnings.length ? 'el-icon-warning' : 'el-icon-data-line'" />
      <strong>{{ warnings.length ? `${warnings.length} 项单价明显高于历史价（超过 ${result.threshold_percent}%）` : '单价与历史采购价对比' }}</strong>
    </div>
    <ul>
      <li v-for="line in result.lines" :key="line.line_key" :class="`level-${line.level}`">
        <el-tag size="mini" :type="tagType(line.level)" effect="plain">{{ levelText(line) }}</el-tag>
        <span>{{ text(line) }}</span>
      </li>
    </ul>
  </div>
</template>

<script>
import { priceCheckText, priceWarnings } from '@/utils/purchasePriceCheck'

export default {
  name: 'PurchasePriceCheckPanel',
  props: { result: { type: Object, default: null } },
  computed: {
    warnings() { return priceWarnings(this.result) }
  },
  methods: {
    text(line) { return priceCheckText(line) },
    tagType(level) { return ({ warning: 'danger', up: 'warning', down: 'success', same: 'info', new: 'info' })[level] || 'info' },
    levelText(line) { return ({ warning: '明显偏高', up: '略高', down: '更低', same: '持平', new: '首次' })[line.level] || '-' }
  }
}
</script>

<style scoped>
.price-check-panel { margin: 10px 0; padding: 10px 12px; border: 1px solid #e3e8ec; border-radius: 4px; background: #fafcfb; }
.price-check-panel.has-warning { border-color: #f3c2c8; background: #fff6f7; }
.price-check-head { display: flex; align-items: center; gap: 6px; margin-bottom: 6px; color: #25313b; }
.has-warning .price-check-head { color: #c0392b; }
ul { margin: 0; padding: 0; list-style: none; }
li { display: flex; align-items: flex-start; gap: 8px; padding: 3px 0; font-size: 12px; color: #4b5763; line-height: 1.5; }
li.level-warning { color: #c0392b; }
</style>
