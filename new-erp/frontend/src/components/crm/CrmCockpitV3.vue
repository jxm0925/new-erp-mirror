<template>
  <div class="cockpit-v3">
    <!-- 首屏驾驶舱头卡与工作日进度 -->
    <section class="sheet-section cockpit-hero-section">
      <div class="combat-banner cockpit-hero-banner">
        <div>
          <div class="cockpit-hero-title">简云智水 C 端 3.0 经营驾驶舱</div>
          <div class="cockpit-hero-sub">统计日期：{{ date }} · 汇报币种：{{ currency }}</div>
        </div>
        <div class="cockpit-hero-meta">
          <span class="hero-update-pill">最后更新 {{ data.updated_at || '尚未保存' }}</span>
        </div>
      </div>

      <div class="calendar-strip">
        <div v-for="(cell, idx) in calendarCells" :key="cell.label" class="cal-tile" :class="{ 'is-edit': !!cell.key }">
          <label>{{ cell.label }}</label>
          <el-input
            v-if="cell.key"
            :value="settings[cell.key]"
            size="small"
            type="number"
            min="0"
            :placeholder="String(cell.value)"
            @input="$set(settings, cell.key, $event)"
          />
          <template v-else>
            <strong class="tab-num">{{ cell.value }}</strong>
            <el-progress
              v-if="idx === calendarCells.length - 1"
              :percentage="completionPct"
              :show-text="false"
              :stroke-width="5"
              color="#008b4b"
              style="margin-top: 6px"
            />
          </template>
        </div>
      </div>
    </section>

    <!-- 一、主管经营总览（15项拆分为零豁口结构化矩阵） -->
    <section class="sheet-section">
      <div class="sheet-section-head">
        <h3><span class="section-badge">一</span> 主管经营总览</h3>
        <span class="daily-muted">金额币种：{{ currency }} · 差额与预测按实绩与剩余工作日自动演算</span>
      </div>

      <div class="subpanel-title">月度接单与目标预测</div>
      <div class="overview-month-grid">
        <div
          v-for="cell in metrics.slice(0, 8)"
          :key="cell.label"
          class="metric-card"
          :class="{ 'is-edit': !!(cell.key && showMoney), 'is-risk': cell.label === '目标风险' }"
        >
          <label>{{ cell.label }}</label>
          <el-input
            v-if="cell.key && showMoney"
            :value="settings[cell.key]"
            type="number"
            min="0"
            size="small"
            :placeholder="String(cell.value ?? '')"
            @input="$set(settings, cell.key, $event)"
          />
          <span v-else-if="cell.label === '目标风险'" class="risk-pill" :class="riskClass(cell.value)">
            {{ display(cell) }}
          </span>
          <strong v-else class="tab-num" :class="deltaClass(cell)">{{ formatMetric(cell) }}</strong>
        </div>
      </div>

      <div class="overview-split-row">
        <div class="overview-group">
          <div class="subpanel-title">今日接单与近 7 日节拍</div>
          <div class="overview-subgrid cols-3">
            <div
              v-for="cell in [metrics[8], metrics[9], metrics[14]]"
              :key="cell.label"
              class="metric-card"
              :class="{ 'is-edit': !!(cell.key && showMoney) }"
            >
              <label>{{ cell.label }}</label>
              <el-input
                v-if="cell.key && showMoney"
                :value="settings[cell.key]"
                type="number"
                min="0"
                size="small"
                :placeholder="String(cell.value ?? '')"
                @input="$set(settings, cell.key, $event)"
              />
              <strong v-else class="tab-num">{{ formatMetric(cell) }}</strong>
            </div>
          </div>
        </div>

        <div class="overview-group">
          <div class="subpanel-title">出货交付与未发积压</div>
          <div class="overview-subgrid cols-4">
            <div
              v-for="cell in metrics.slice(10, 14)"
              :key="cell.label"
              class="metric-card"
              :class="{ 'is-edit': !!(cell.key && showMoney) }"
            >
              <label>{{ cell.label }}</label>
              <el-input
                v-if="cell.key && showMoney"
                :value="settings[cell.key]"
                type="number"
                min="0"
                size="small"
                :placeholder="String(cell.value ?? '')"
                @input="$set(settings, cell.key, $event)"
              />
              <strong v-else class="tab-num">{{ formatMetric(cell) }}</strong>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- 二、今日经营结论（3×3 整齐九宫格） -->
    <section class="sheet-section">
      <div class="sheet-section-head">
        <h3><span class="section-badge">二</span> 今日经营结论</h3>
        <span class="daily-muted">共 {{ conclusionFields.length }} 项核心经营复盘判断</span>
      </div>
      <div class="conclusion-grid">
        <div v-for="f in conclusionFields" :key="f.key" class="conclusion-card">
          <label class="conclusion-label">{{ f.label }}</label>
          <div v-if="f.sensitive && !showMoney" class="conclusion-locked daily-muted">未提供</div>
          <el-input
            v-else
            type="textarea"
            :rows="2"
            maxlength="1500"
            :aria-label="f.label"
            :value="form.conclusions[f.key]"
            placeholder="请输入结论..."
            @input="$set(form.conclusions, f.key, $event)"
          />
          <crm-ai-field-hint
            v-if="aiBlank(form.conclusions[f.key])"
            :field="f.label"
            :suggestion="conclusionHints[f.key]"
            @apply="fillAiBlanks(form.conclusions, { [f.key]: conclusionHints[f.key] })"
          />
        </div>
      </div>
    </section>

    <!-- 三、C端人员经营诊断 -->
    <section class="sheet-section">
      <div class="sheet-section-head">
        <h3><span class="section-badge">三</span> C端人员经营诊断</h3>
        <div class="sheet-toolbar-group">
          <span class="daily-muted">点击姓名可直接查阅该销售当日作战表</span>
          <el-select v-model="inspectId" filterable clearable placeholder="快速查阅销售日报" size="small" style="width: 180px" @change="inspect">
            <el-option v-for="p in data.staff" :key="p.id" :value="p.id" :label="p.name" />
          </el-select>
        </div>
      </div>
      <el-table :data="people.slice((page-1)*10, page*10)" border size="mini" class="combat-table" empty-text="当日尚无已确认的销售日报">
        <el-table-column
          v-for="f in personFields"
          :key="f.key"
          :label="f.label"
          :fixed="f.key === 'name' ? 'left' : false"
          :align="f.money ? 'right' : (f.key === 'risk' || f.key === 'key_count' || f.key === 'high_count' ? 'center' : 'left')"
          :min-width="f.key === 'coord' ? 200 : (f.key === 'reason' ? 175 : (f.key === 'name' ? 105 : 118))"
        >
          <template slot-scope="{ row }">
            <el-button v-if="f.key === 'name'" size="mini" type="text" class="staff-link-btn" @click="$emit('inspect', row)">
              {{ row.name }}
            </el-button>
            <span v-else-if="f.money && !showMoney" class="daily-muted">未提供</span>
            <el-select v-else-if="f.key === 'risk'" :value="row.risk" clearable size="mini" placeholder="选择" @input="override(row.id, f.key, $event)">
              <el-option v-for="v in ['低','中','高']" :key="v" :value="v" />
            </el-select>
            <el-input
              v-else-if="f.edit && !row.fromDaily[f.key]"
              :value="row[f.key]"
              size="mini"
              :type="f.key === 'reason' ? 'text' : 'number'"
              min="0"
              @input="override(row.id, f.key, $event)"
            />
            <span v-else :class="{ 'tab-num': f.money || f.key === 'key_count' || f.key === 'high_count' }">
              {{ f.money && row[f.key] != null ? formatMoneyVal(row[f.key]) : (row[f.key] ?? '—') }}
            </span>
          </template>
        </el-table-column>
      </el-table>
      <div v-if="people.length > 10" style="margin-top: 10px; display: flex; justify-content: flex-end">
        <el-pagination small layout="prev,pager,next,total" :page-size="10" :current-page.sync="page" :total="people.length" />
      </div>
    </section>

    <!-- 四、今日三项重点推进与辅导 -->
    <section class="sheet-section">
      <div class="sheet-section-head">
        <h3><span class="section-badge">四</span> 今日三项重点推进与辅导</h3>
        <span class="daily-muted">按 P1 / P2 / P3 明确今日重点客户结果目标与主管支持动作</span>
      </div>
      <crm-combat-grid :rows="form.coaching" :columns="coachingColumns" editable :show-money="showMoney">
        <template v-if="suggestions && suggestions.coaching && suggestions.coaching.length" #advice="{ row }">
          <crm-ai-field-hint
            v-if="coachingHints[row.priority]"
            :field="row.priority"
            action-label="预填这项辅导"
            :suggestion="coachingPreview(coachingHints[row.priority])"
            @apply="applyCoaching(row)"
          />
        </template>
      </crm-combat-grid>
    </section>

    <!-- AI 经营分析面板 -->
    <section v-if="suggestions" class="sheet-section ai-panel">
      <div class="sheet-section-head">
        <h3>
          <i class="el-icon-cpu" /> AI 经营分析与辅导预检
          <el-tag size="mini" type="success" effect="plain">{{ suggestions.model }}</el-tag>
        </h3>
        <el-button size="small" type="success" @click="adopt">采纳到空白项</el-button>
      </div>
      <div v-if="suggestedConclusions.length" class="ai-conclusion-grid">
        <div v-for="f in suggestedConclusions" :key="f.key" class="ai-conclusion-card">
          <div class="ai-cc-lbl">{{ f.label }}</div>
          <div class="ai-cc-val">{{ suggestions.conclusions[f.key] }}</div>
        </div>
      </div>
      <crm-combat-grid :rows="suggestions.coaching || []" :columns="coachingColumns" :show-money="showMoney" />
    </section>
  </div>
</template>

<script>
import CrmCombatGrid from './CrmCombatGrid.vue'
import CrmAiFieldHint from './CrmAiFieldHint.vue'
import { conclusions, coachingFields, tableValues, aiBlank, fillAiBlanks } from './combatTemplates'
import { pickCurrencyAmount } from '@/api/erp/crmDaily'

const has = v => v != null && v !== ''
const number = (v, fallback = null) => has(v) ? Number(v) : fallback
const rounded = v => v == null ? null : Math.round(v * 100) / 100

export default {
  components: { CrmCombatGrid, CrmAiFieldHint },
  props: { data: Object, form: Object, currency: String, date: String },
  data: () => ({ inspectId: null, page: 1, conclusionFields: conclusions,
    personFields: [{ key: 'name', label: '姓名' }, { key: 'today_target', label: '今日目标', edit: true, money: true },
      { key: 'today_actual', label: '今日实绩', money: true }, { key: 'key_count', label: '今日重点客户数', edit: true },
      { key: 'high_count', label: '高概率客户数（>50%）', edit: true }, { key: 'reason', label: '原因分析', edit: true },
      { key: 'coord', label: '需要主管协调' }, { key: 'risk', label: '风险评估', edit: true }]
  }),
  computed: {
    settings() { return this.form.settings },
    showMoney() { return this.data.amounts != null },
    completionPct() {
      const r = this.days.rate
      if (r == null) return 0
      return Math.min(100, Math.max(0, Math.round(r * 100)))
    },
    people() {
      return this.data.staff.filter(p => p.submitted).map(p => {
        const previous = p.targets?.currency === this.currency ? p.targets.close_target : null
        const row = { id: p.id, name: p.name,
          today_target: number(p.currency === this.currency ? p.today_target : null, previous),
          today_actual: pickCurrencyAmount(p.actuals?.closed, this.currency), key_count: p.key_count, high_count: p.high_count,
          reason: [p.review_detail.reason_category, p.review_detail.q1].filter(Boolean).join(' · '),
          risk: '', coord: (this.data.help || []).filter(h => Number(h.owner_id) === p.id && h.report_date === this.date)
            .map(h => h.help_request).join('；') }
        const edits = this.form.personnel.find(e => e.sales_id === p.id) || {}
        row.fromDaily = { key_count: has(p.key_count), high_count: has(p.high_count), reason: has(row.reason) }
        for (const key of ['today_target', 'key_count', 'high_count', 'reason', 'risk']) {
          if (!row.fromDaily[key] && has(edits[key])) row[key] = edits[key]
        }
        return row
      })
    },
    days() {
      const total = number(this.settings.total_days, this.data.calendar.total_days)
      const passed = number(this.settings.passed_days, this.data.calendar.passed_days)
      const due = number(this.settings.due_people, this.data.due_people)
      const submitted = this.data.submitted_people
      return { total, passed, left: Math.max(0, total - passed), due, submitted, rate: due > 0 ? submitted / due : null }
    },
    calendarCells() {
      const d = this.days
      return [{ key: 'total_days', label: '本月总工作日', value: d.total }, { key: 'passed_days', label: '已过工作日', value: d.passed },
        { label: '剩余工作日', value: d.left }, { key: 'due_people', label: '应交人数', value: d.due },
        { label: '完整人数（已确认）', value: d.submitted }, { label: '数据完整率', value: d.rate == null ? '—' : (d.rate * 100).toFixed(0) + '%' }]
    },
    metrics() {
      const d = this.days
      const targets = this.data.month_targets?.[this.currency] || {}
      const month = number(this.settings.month_target, number(targets.orders))
      const shipTarget = number(this.settings.month_ship_target, number(targets.shipments))
      const staffTargets = this.people.map(p => p.today_target).filter(has)
      const today = number(this.settings.today_target, staffTargets.length ? staffTargets.reduce((a,b) => a + Number(b), 0) : null)
      const actual = pickCurrencyAmount(this.data.monthly_amounts?.closed, this.currency)
      const avg = pickCurrencyAmount(this.data.cockpit_amounts?.seven_day_average, this.currency)
      const should = month != null && d.total > 0 ? month * d.passed / d.total : null
      const forecast = actual + d.left * avg
      let risk = '数据不足'
      if (this.showMoney && month > 0 && d.total > 0 && d.rate >= 1) {
        if (actual >= month) risk = '无风险'
        else if (d.left < 5) risk = actual < should ? '中风险' : '低风险'
        else if (forecast < month * 0.9) risk = '高风险'
        else risk = forecast < month ? '中风险' : '低风险'
      }
      const metric = (label, value, key) => ({ label, value: rounded(value), key, money: true })
      return [metric('月接单目标', month, 'month_target'), metric('截至今日应完成', should), metric('月接单实绩', actual),
        metric('进度差额', should == null ? null : actual - should), metric('月底预测', forecast),
        metric('预计差额', month == null ? null : forecast - month), metric('剩余日均要求', month == null ? null : d.left ? Math.max(0, month - actual) / d.left : 0),
        { label: '目标风险', value: risk }, metric('今日接单目标', today, 'today_target'),
        metric('今日接单实绩', pickCurrencyAmount(this.data.amounts?.closed, this.currency)), metric('月出货目标', shipTarget, 'month_ship_target'),
        metric('月出货实绩', pickCurrencyAmount(this.data.monthly_amounts?.shipped, this.currency)),
        metric('今日出货', pickCurrencyAmount(this.data.amounts?.shipped, this.currency)),
        metric('已接单未发货', pickCurrencyAmount(this.data.cockpit_amounts?.unshipped, this.currency)), metric('最近7日接单日均', avg)]
    },
    coachingColumns() {
      const ids = this.data.staff.map(p => p.id)
      const labels = Object.fromEntries(this.data.staff.map(p => [p.id, p.name]))
      return coachingFields.map(c => c.key === 'sales_id' ? { ...c, options: ids, labels, strict: true } : { ...c, readonly: c.key === 'priority' })
    },
    suggestions() { return this.data.analysis?.template_version === 3 ? this.data.analysis : null },
    conclusionHints() {
      return Object.fromEntries(this.suggestedConclusions.map(f => [f.key, this.suggestions.conclusions[f.key]]))
    },
    coachingHints() {
      const hints = {}
      for (const advice of this.suggestions?.coaching || []) {
        const slot = this.form.coaching.find(r => r.priority === advice.priority)
        if (!slot || (slot.sales_id && slot.sales_id !== advice.sales_id) || (slot.customer && slot.customer !== advice.customer)) continue
        if (!this.data.staff.some(p => p.id === advice.sales_id)) continue
        if (advice.customer && this.form.coaching.some(r => r !== slot && r.customer?.trim() === advice.customer.trim())) continue
        const fields = ['sales_id', 'customer', 'fact', 'goal', 'seller_action', 'support', 'deadline', 'acceptance']
        if (fields.some(f => aiBlank(slot[f]) && !aiBlank(advice[f]))) {
          hints[slot.priority] = Object.fromEntries(fields.map(f => [f, advice[f]]))
        }
      }
      return hints
    },
    suggestedConclusions() { return conclusions.filter(c => (!c.sensitive || this.showMoney) && this.suggestions?.conclusions?.[c.key]) }
  },
  methods: {
    aiBlank,
    fillAiBlanks,
    formatMoneyVal(val) {
      if (val == null || val === '') return '—'
      const num = Number(val)
      if (Number.isNaN(num)) return val
      return num.toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    formatMetric(cell) {
      if (cell.money && !this.showMoney) return '未提供'
      if (cell.value == null) return '—'
      if (cell.money) return this.formatMoneyVal(cell.value)
      return cell.value
    },
    deltaClass(cell) {
      if (!this.showMoney || cell.value == null) return ''
      if (cell.label !== '进度差额' && cell.label !== '预计差额') return ''
      if (cell.value > 0) return 'num-pos'
      if (cell.value < 0) return 'num-neg'
      return ''
    },
    riskClass(val) {
      if (val === '高风险') return 'is-high'
      if (val === '中风险') return 'is-mid'
      if (val === '低风险' || val === '无风险') return 'is-ok'
      return 'is-muted'
    },
    coachingPreview(advice) {
      const name = this.data.staff.find(p => p.id === advice.sales_id)?.name
      return [name, advice.customer, advice.goal || advice.seller_action || advice.support || advice.fact].filter(Boolean).join(' · ')
    },
    applyCoaching(row) {
      return fillAiBlanks(row, this.coachingHints[row.priority])
    },
    display(cell) { return cell.money && !this.showMoney ? '未提供' : cell.value ?? '—' },
    inspect(id) {
      const person = this.data.staff.find(p => p.id === id)
      if (person) this.$emit('inspect', person)
      this.inspectId = null
    },
    override(id, key, value) {
      let entry = this.form.personnel.find(p => p.sales_id === id)
      if (!entry) {
        entry = { sales_id: id }
        this.form.personnel.push(entry)
      }
      this.$set(entry, key, value)
    },
    adopt() {
      let count = fillAiBlanks(this.form.conclusions, this.conclusionHints)
      for (const row of this.form.coaching) count += this.applyCoaching(row)
      this.$message.success(count ? '已预填 ' + count + ' 个空白字段，可修改后保存' : '本次没有可预填的空白字段')
    },
    exportSheets() {
      return [['主管经营总览', [['日期',this.date],['币种',this.currency], ...this.calendarCells.map(c => [c.label,c.value]),
        ...this.metrics.map(c => [c.label,this.display(c)])]], ['今日经营结论', conclusions.map(c => [c.label,c.sensitive && !this.showMoney ? '未提供' : this.form.conclusions[c.key]])],
        ['C端人员经营诊断', tableValues(this.personFields, this.people, this.showMoney)],
        ['三项重点推进与辅导', tableValues(this.coachingColumns, this.form.coaching, this.showMoney)],
        ['协调事项', [['销售','事项','状态','办理结果'], ...this.data.help.map(h => [h.author_name,h.help_request,
          ({pending:'待协调',accepted:'处理中',returned:'已退回',done:'已完成'})[h.help_status],h.manager_note])]]]
    }
  }
}
</script>

<style scoped>
.cockpit-hero-section {
  padding-top: 16px;
}
.cockpit-hero-banner {
  margin-bottom: 12px;
  padding: 13px 18px;
}
.cockpit-hero-title {
  font-size: 16px;
  font-weight: 700;
  color: #ffffff;
  letter-spacing: 0.3px;
}
.cockpit-hero-sub {
  font-size: 12px;
  color: #bce3cf;
  margin-top: 3px;
}
.hero-update-pill {
  display: inline-flex;
  align-items: center;
  padding: 4px 12px;
  border-radius: 999px;
  background: rgba(255, 255, 255, 0.14);
  border: 1px solid rgba(255, 255, 255, 0.2);
  color: #ecfdf5;
  font-size: 12px;
  font-weight: 500;
}
.calendar-strip {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
}
.cal-tile {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 9px 12px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
  min-height: 66px;
}
.cal-tile.is-edit {
  background: #f4fbf7;
  border-color: #c6ebd6;
}
.cal-tile label,
.metric-card label {
  display: block;
  font-size: 11.5px;
  color: #475569;
  font-weight: 600;
  margin-bottom: 5px;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}
.cal-tile strong {
  font-size: 16px;
  color: #0f172a;
}
.overview-month-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 14px;
}
.overview-split-row {
  display: grid;
  grid-template-columns: minmax(0, 3fr) minmax(0, 4fr);
  gap: 14px;
}
.overview-group {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 12px;
}
.overview-subgrid {
  display: grid;
  gap: 8px;
}
.overview-subgrid.cols-3 {
  grid-template-columns: repeat(3, minmax(0, 1fr));
}
.overview-subgrid.cols-4 {
  grid-template-columns: repeat(4, minmax(0, 1fr));
}
.metric-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 9px 12px;
  min-height: 64px;
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}
.overview-group .metric-card {
  background: #ffffff;
}
.metric-card.is-edit {
  background: #f2fbf6;
  border-color: #bce3cf;
}
.metric-card.is-edit label {
  color: #00763f;
}
.metric-card strong {
  font-size: 15px;
  line-height: 1.3;
}
.conclusion-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
}
.conclusion-card {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 10px 12px;
  display: flex;
  flex-direction: column;
}
.conclusion-label {
  display: block;
  font-size: 12.5px;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 6px;
}
.conclusion-card ::v-deep .el-textarea__inner {
  background: #ffffff;
  border-color: #dbe3ec;
  border-radius: 6px;
  font-size: 12.5px;
}
.conclusion-card ::v-deep .el-textarea__inner:focus {
  border-color: #008b4b;
}
.conclusion-locked {
  padding: 10px 0;
}
.staff-link-btn {
  padding: 0;
  font-weight: 700;
}
.ai-conclusion-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 12px;
}
.ai-conclusion-card {
  background: #ffffff;
  border: 1px solid #d1fae5;
  border-radius: 6px;
  padding: 8px 10px;
}
.ai-cc-lbl {
  font-size: 11.5px;
  font-weight: 700;
  color: #00763f;
  margin-bottom: 3px;
}
.ai-cc-val {
  font-size: 12.5px;
  color: #1e293b;
  line-height: 1.5;
}
@media (max-width: 1180px) {
  .calendar-strip {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .overview-split-row {
    grid-template-columns: 1fr;
  }
  .conclusion-grid,
  .ai-conclusion-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 760px) {
  .calendar-strip,
  .overview-month-grid,
  .overview-subgrid.cols-3,
  .overview-subgrid.cols-4,
  .conclusion-grid,
  .ai-conclusion-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
