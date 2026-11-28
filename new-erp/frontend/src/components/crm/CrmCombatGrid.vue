<template>
  <el-table :data="rows" border size="mini" class="combat-table" :empty-text="emptyText">
    <el-table-column
      v-for="col in columns"
      :key="col.key"
      :label="col.label"
      :fixed="col.fixed ? 'left' : false"
      :min-width="columnWidth(col)"
      :align="col.money ? 'right' : 'left'"
    >
      <template slot-scope="{ row }">
        <span v-if="col.money && !showMoney" class="daily-muted">未提供</span>
        <el-button
          v-else-if="col.key === 'customer_name' && row.key"
          type="text"
          size="mini"
          class="grid-customer-link"
          @click="$emit('project', row.key)"
        >
          {{ row.customer_name || row.number }}
        </el-button>
        <template v-else-if="canEdit(col, row)">
          <el-select
            v-if="col.options"
            :value="row[col.key]"
            size="mini"
            clearable
            filterable
            style="width: 100%"
            :allow-create="!col.strict"
            :aria-label="col.label"
            @input="setField(row, col, $event)"
          >
            <el-option v-for="v in fieldOptions(col, row)" :key="v" :value="v" :label="col.labels && col.labels[v] || v" />
          </el-select>
          <el-input
            v-else
            :value="row[col.key]"
            size="mini"
            style="width: 100%"
            :type="col.money ? 'number' : 'text'"
            :min="col.money ? 0 : undefined"
            :aria-label="col.label"
            maxlength="500"
            @input="setField(row, col, $event)"
          />
          <crm-ai-field-hint
            v-if="suggestions && aiBlank(row[col.key])"
            :field="col.label"
            :suggestion="suggestions[row.key] && suggestions[row.key][col.key]"
            @apply="fillAiBlanks(row, { [col.key]: suggestions[row.key][col.key] })"
          />
        </template>
        <el-tag v-else-if="badgeType(col, row) !== null && hasValue(row[col.key])" size="mini" :type="badgeType(col, row)" effect="plain">
          {{ (col.labels && col.labels[row[col.key]]) || row[col.key] }}
        </el-tag>
        <span v-else :class="{ 'tab-num': col.money && hasValue(row[col.key]) }">
          {{ formatDisplay(col, row[col.key]) }}
        </span>
      </template>
    </el-table-column>
    <el-table-column v-if="$scopedSlots.advice" label="AI 建议" min-width="200">
      <template slot-scope="{ row }"><slot name="advice" :row="row" /></template>
    </el-table-column>
    <el-table-column v-if="removable && editable" label="操作" width="64" align="center" fixed="right">
      <template slot-scope="{ row }"><el-button type="text" size="mini" @click="$emit('remove', row)">移除</el-button></template>
    </el-table-column>
  </el-table>
</template>

<script>
import { poolNames, stageOptions } from '@/utils/crmProgress'
import CrmAiFieldHint from './CrmAiFieldHint.vue'
import { combatDictionaries, aiBlank, fillAiBlanks } from './combatTemplates'

const NARROW_KEYS = new Set(['priority', 'status', 'contact_time', 'deadline', 'expected_pay_time'])
const WIDE_KEYS = new Set([
  'today_action', 'today_result', 'promise', 'tmr_must_action', 'tmr_advance_to',
  'response_action', 'fact', 'goal', 'seller_action', 'support', 'acceptance', 'result', 'remark'
])

export default {
  components: { CrmAiFieldHint },
  props: {
    rows: { type: Array, required: true },
    columns: { type: Array, required: true },
    editable: Boolean,
    showMoney: Boolean,
    removable: Boolean,
    suggestions: Object,
    emptyText: { type: String, default: '暂无记录' }
  },
  methods: {
    fieldOptions(col, row) {
      let options = col.options || []
      if (col.key === 'pool') options = poolNames(combatDictionaries, row.kind, row[col.key])
      if (col.key === 'nine_step' && row.kind) options = stageOptions(combatDictionaries, row.kind).map(item => item.label)
      return [...new Set([...options, row[col.key]].filter(value => value !== null && value !== undefined && value !== ''))]
    },
    setField(row, col, value) {
      this.$set(row, col.key, value)
      if (col.key === 'nine_step' && row.kind) {
        const stage = stageOptions(combatDictionaries, row.kind).find(item => item.value === value || item.label === value)
        if (stage && stage.probability) this.$set(row, 'probability', stage.probability)
      }
    },
    aiBlank,
    fillAiBlanks,
    hasValue(val) {
      return val != null && val !== ''
    },
    columnWidth(col) {
      if (col.money) return 115
      if (NARROW_KEYS.has(col.key)) return 120
      if (WIDE_KEYS.has(col.key)) return 195
      if (col.key === 'plan_priority' || col.key === 'max_blocker' || col.key === 'anticipated_blocker' || col.key === 'reason') return 160
      return 145
    },
    badgeType(col, row) {
      const val = row[col.key]
      if (!this.hasValue(val)) return null
      if (col.key === 'priority') {
        if (val === 'primary') return 'danger'
        if (val === 'backup') return 'warning'
        return 'info'
      }
      if (col.key === 'status') {
        if (val === '已完成') return 'success'
        if (val === '有风险') return 'danger'
        if (val === '执行中') return ''
        return 'info'
      }
      if (col.key === 'plan_priority') {
        if (String(val).startsWith('P1')) return 'danger'
        if (String(val).startsWith('P2')) return 'warning'
        return 'info'
      }
      return null
    },
    formatDisplay(col, val) {
      if (val === 0) return 0
      if (!this.hasValue(val)) return '—'
      if (col.money && !Number.isNaN(Number(val))) {
        return Number(val).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
      }
      return (col.labels && col.labels[val]) || val
    },
    canEdit(col, row) {
      if (!this.editable || col.readonly) return false
      if (col.key === 'priority' && col.options?.includes('primary') && !row.key) return false
      return !(row.key && col.key === 'stage_label')
    }
  }
}
</script>

<style scoped>
.grid-customer-link {
  padding: 0;
  white-space: normal;
  text-align: left;
  font-weight: 600;
  line-height: 1.4;
}
</style>
