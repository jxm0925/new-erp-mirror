<template>
  <div v-loading="busy" class="sales-combat-wrap">
    <el-alert v-if="error" type="error" :title="error" :closable="false" show-icon />
    <el-alert
      v-if="pendingLocalDraft"
      type="warning"
      show-icon
      :closable="false"
      class="draft-recover-alert"
    >
      <template slot="title">
        检测到 {{ pendingLocalDraft.savedAt }} 的本地未提交暂存草稿
        <el-button type="text" size="mini" style="margin-left: 10px" @click="restoreLocalDraft">恢复草稿</el-button>
        <el-button type="text" size="mini" @click="dismissLocalDraft">忽略</el-button>
      </template>
    </el-alert>

    <!-- 顶部操作栏 -->
    <div class="sheet-toolbar">
      <div class="sheet-toolbar-group">
        <el-tag size="small" :type="confirmed ? 'success' : 'info'" effect="dark">
          {{ confirmed ? '已确认' : '草稿' }}
        </el-tag>
        <el-tag v-if="ready && !record.is_self" size="small" type="warning" effect="plain">
          主管查阅/批注视角（仅可保存「主管最终判断」）
        </el-tag>
        <span class="tool-label" style="margin-left: 6px">币种</span>
        <el-select
          v-model="form.currency"
          size="small"
          style="width: 92px"
          filterable
          allow-create
          :disabled="!editable || !amountsAvailable"
        >
          <el-option v-for="c in currencies" :key="c" :value="c" :label="c" />
        </el-select>
        <span v-if="autoSavedAt" class="daily-muted">已本地暂存 {{ autoSavedAt }}</span>
      </div>

      <div class="sheet-toolbar-group">
        <el-button size="small" icon="el-icon-refresh" @click="reload">刷新</el-button>
        <el-button v-if="ready && editable" size="small" icon="el-icon-document" @click="persist(false)">保存草稿</el-button>
        <el-dropdown v-if="ready && editable" split-button type="primary" size="small" :disabled="!hasAi || busy"
          @click="analyze(true)" @command="analyze(false)">
          AI 预填空白项
          <el-dropdown-menu slot="dropdown"><el-dropdown-item command="preview">仅生成填写提示</el-dropdown-item></el-dropdown-menu>
        </el-dropdown>
        <el-button v-if="ready && editable" size="small" type="success" icon="el-icon-check" @click="confirm">
          确认日报与明日待办
        </el-button>
        <el-button v-if="ready" size="small" icon="el-icon-download" @click="download">导出 Excel</el-button>
      </div>
    </div>

    <template v-if="ready">
      <!-- 作战表标题与核心摘要 -->
      <div class="combat-banner">
        <div class="combat-banner-left">
          <div class="combat-banner-eyebrow">
            <span class="eyebrow-date">{{ date }}</span>
            <span>·</span>
            <span class="eyebrow-name">{{ salesName }}</span>
          </div>
          <el-input
            v-model="form.title"
            size="small"
            class="combat-banner-input"
            maxlength="100"
            :readonly="!editable"
            placeholder="输入作战表标题"
          />
        </div>
        <div class="banner-stat-chips">
          <div class="banner-chip">
            <span class="banner-chip-lbl">{{ v2Actuals[6][0] }}</span>
            <span class="banner-chip-val">{{ v2Actuals[6][1] }}</span>
          </div>
          <div class="banner-chip">
            <span class="banner-chip-lbl">{{ v2Actuals[7][0] }}</span>
            <span class="banner-chip-val">{{ v2Actuals[7][1] }} 客</span>
          </div>
          <div class="banner-chip">
            <span class="banner-chip-lbl">{{ v2Actuals[8][0] }}</span>
            <span class="banner-chip-val">{{ v2Actuals[8][1] }} 客</span>
          </div>
        </div>
      </div>

      <!-- 基础目标与实绩 -->
      <section class="sheet-section">
        <div class="sheet-section-head">
          <h3><span class="section-badge">基</span> 基础目标与实绩</h3>
          <span class="daily-muted">币种：{{ form.currency }} · 成交取已确认订单，发货取已发货单</span>
        </div>

        <div class="actuals-row-top">
          <div v-for="(cell, idx) in v2Actuals.slice(0, 4)" :key="cell[0]" class="actual-card">
            <span class="actual-lbl">{{ cell[0] }}</span>
            <span class="actual-val tab-num" :class="actualDeltaClass(idx)">{{ cell[1] }}</span>
          </div>
        </div>
        <div class="actuals-row-bottom">
          <div v-for="cell in v2Actuals.slice(4)" :key="cell[0]" class="actual-card is-sub">
            <span class="actual-lbl">{{ cell[0] }}</span>
            <span class="actual-val tab-num">{{ cell[1] }}</span>
          </div>
        </div>

        <div v-if="amountsAvailable" class="base-targets-grid">
          <div class="target-tile is-primary">
            <label>明日成交目标</label>
            <el-input v-model="form.close_target" size="small" type="number" min="0" :disabled="!editable" placeholder="0.00" />
          </div>
          <div class="target-tile is-primary">
            <label>明日发货目标</label>
            <el-input v-model="form.ship_target" size="small" type="number" min="0" :disabled="!editable" placeholder="0.00" />
          </div>
          <div v-for="f in v2Targets" :key="f.key" class="target-tile">
            <label>{{ f.label }}</label>
            <el-input
              :value="form.base[f.key]"
              size="small"
              type="number"
              min="0"
              :disabled="!editable"
              :placeholder="f.hint"
              @input="$set(form.base, f.key, $event)"
            />
          </div>
        </div>
      </section>

      <!-- AI 诊断与填表建议面板 -->
      <section v-if="record.analysis" class="sheet-section ai-panel">
        <div class="sheet-section-head">
          <h3>
            <i class="el-icon-cpu" /> AI 成单诊断与填表建议
            <el-tag size="mini" type="success" effect="plain">
              {{ record.analysis.provider }} · {{ record.analysis.model }}
            </el-tag>
            <el-tag v-if="record.analysis.review_changed || dirty" size="mini" type="warning">填报内容已变更</el-tag>
          </h3>
          <div class="sheet-toolbar-group">
            <span class="daily-muted">{{ record.analysis.at }} · 参考 {{ record.analysis.candidate_count || 0 }} 个项目</span>
            <el-button v-if="editable" size="mini" type="success" @click="prefillDraft">批量填充空白项</el-button>
            <el-button size="mini" type="text" @click="aiExpanded = !aiExpanded">
              {{ aiExpanded ? '收起' : '展开' }}
            </el-button>
          </div>
        </div>

        <el-tabs v-show="aiExpanded" v-model="aiTab" size="mini">
          <el-tab-pane :label="`客户池推进 (${aiPoolAdvice.length})`" name="pool">
            <div v-if="editable" style="margin-bottom: 8px; display: flex; gap: 8px">
              <el-button size="mini" type="primary" plain @click="adoptPoolAdvice()">仅填空白项到客户池表</el-button>
            </div>
            <div v-if="!aiPoolAdvice.length" class="daily-muted">暂无客户池推进建议。</div>
            <article v-for="r in aiPoolAdvice" :key="r.key" class="daily-item">
              <header>
                <el-button type="text" @click="detail(r.key)">{{ label(r.key) }}</el-button>
                <el-tag v-if="r.action_type" size="mini">{{ r.action_type }}</el-tag>
                <el-tag v-if="r.probability" size="mini" type="success">概率 {{ r.probability }}</el-tag>
                <el-button v-if="editable" size="mini" type="text" @click="applySinglePoolAdvice(r)">采纳此客户</el-button>
              </header>
              <div><b>最大卡点：</b>{{ r.max_blocker }}</div>
              <div><b>明天必须动作：</b>{{ r.tmr_must_action }}　｜　<b>推进到：</b>{{ r.tmr_advance_to || '-' }}</div>
              <details v-if="r.sources && r.sources.length">
                <summary class="daily-muted" style="cursor: pointer">查看引用跟进依据（{{ r.sources.length }} 条）</summary>
                <p v-for="s in r.sources" :key="s.id" class="daily-muted">#{{ s.id }} · {{ s.at }} · {{ s.content }} {{ s.result }}</p>
              </details>
            </article>
          </el-tab-pane>

          <el-tab-pane :label="`重点推荐 (${aiItems.length})`" name="items">
            <div v-if="!aiItems.length" class="daily-muted">本次没有足够依据推荐重点主攻/补位客户。</div>
            <article v-for="r in aiItems" :key="r.key" class="daily-item">
              <header>
                <el-tag size="mini" :type="r.priority === 'primary' ? 'danger' : 'warning'">
                  {{ labels[r.priority] || r.priority }}
                </el-tag>
                <el-button type="text" @click="detail(r.key)">{{ label(r.key) }}</el-button>
                <el-button v-if="editable" type="text" size="mini" @click="adoptRecommendedItem(r)">
                  加入客户池并标为{{ labels[r.priority] }}
                </el-button>
              </header>
              <div><b>卡点：</b>{{ r.blocker }}</div>
              <div><b>跟进理由：</b>{{ r.reason }}</div>
              <div><b>建议动作：</b>{{ r.action }}</div>
              <div v-if="r.help"><b>建议协调：</b>{{ r.help }}</div>
              <details v-if="r.sources && r.sources.length">
                <summary class="daily-muted" style="cursor: pointer">跟进依据（{{ r.sources.length }} 条）</summary>
                <p v-for="s in r.sources" :key="s.id" class="daily-muted">#{{ s.id }} · {{ s.at }} · {{ s.content }} {{ s.result }}</p>
              </details>
            </article>
          </el-tab-pane>

          <el-tab-pane label="复盘与明日计划草稿" name="reviewPlan">
            <div v-if="editable" style="margin-bottom: 8px; display: flex; gap: 8px">
              <el-button size="mini" type="primary" plain @click="adoptReview()">填充复盘空白项</el-button>
              <el-button size="mini" type="primary" plain @click="$message.info(adoptPlan() ? '已填入客户池及补充事项' : '没有可新增的计划项')">采纳计划建议</el-button>
            </div>
            <div v-if="aiReview" class="daily-fields">
              <div v-for="field in reviewFields" :key="field.key"><b>{{ field.label }}：</b>{{ aiReview[field.key] || '—' }}</div>
            </div>
            <el-table v-if="aiPlanDraft.length" :data="aiPlanDraft" border size="mini" class="combat-table" style="margin-top: 10px">
              <el-table-column prop="time" label="时间段" width="110" />
              <el-table-column prop="task" label="工作事项" width="150" />
              <el-table-column prop="customer_name" label="客户名称" width="150" />
              <el-table-column prop="action" label="具体动作" min-width="200" />
              <el-table-column prop="result" label="推进成果" min-width="170" />
              <el-table-column prop="blocker" label="卡点" min-width="140" />
            </el-table>
          </el-tab-pane>
        </el-tabs>
      </section>

      <!-- 模块一：客户池拆解（先排队，再推进） -->
      <section class="sheet-section">
        <div class="sheet-section-head">
          <h3><span class="section-badge">一</span> 重点客户推进</h3>
          <span class="daily-muted">主攻 / 补位客户将在「确认日报」后自动同步至工作台明日跟进待办，填写「请主管协调」将自动提交主管办理</span>
        </div>

        <div class="subsection-head">
          <span class="tool-label">
            客户池共 {{ form.pool_others.length }} 项，
            已标主攻 {{ priorityCount('primary') }}/3 · 补位 {{ priorityCount('backup') }}/3
          </span>
          <div v-if="editable" class="sheet-toolbar-group">
            <el-button size="mini" icon="el-icon-search" @click="togglePicker">
              {{ pickerOpen ? '收起候选项目' : '从线索或商机增补' }}
            </el-button>
            <el-button size="mini" icon="el-icon-plus" @click="addOtherPoolRow">手工新增行</el-button>
          </div>
        </div>

        <div v-if="editable && pickerOpen" class="daily-item" style="margin-bottom: 10px">
          <el-input
            v-model.trim="keyword"
            size="small"
            placeholder="搜索客户名称、项目或单号"
            clearable
            @keyup.enter.native="search(1)"
            @clear="search(1)"
          >
            <el-button slot="append" icon="el-icon-search" @click="search(1)" />
          </el-input>
          <el-table v-loading="searching" :data="list" size="mini" empty-text="没有匹配的在跟项目" style="margin-top: 6px">
            <el-table-column prop="number" label="单号" width="145" />
            <el-table-column prop="customer_name" label="客户" min-width="140" />
            <el-table-column prop="project" label="项目 / 需求" min-width="160" />
            <el-table-column prop="pool" label="客户池" width="120" />
            <el-table-column prop="expected_amount" label="预计金额" width="100" align="right" />
            <el-table-column prop="stage_label" label="阶段" width="110" />
            <el-table-column width="80" align="center">
              <template slot-scope="{ row }">
                <el-button type="text" size="mini" :disabled="hasPoolKey(row.key)" @click="chooseToPool(row)">
                  {{ hasPoolKey(row.key) ? '已加入' : '加入' }}
                </el-button>
              </template>
            </el-table-column>
          </el-table>
          <el-pagination
            small
            layout="prev,pager,next,total"
            :page-size="10"
            :current-page="page"
            :total="total"
            @current-change="search"
          />
        </div>

        <crm-combat-grid :rows="form.pool_others" :columns="progressFields" :suggestions="projectSuggestions" :editable="editable" :show-money="amountsAvailable"
          removable empty-text="从线索或商机选择今天要推进的客户" @project="detail" @remove="form.pool_others.splice(form.pool_others.indexOf($event), 1)" />
      </section>

      <section class="sheet-section">
        <div class="sheet-section-head">
          <h3><span class="section-badge">二</span> 明日作战计划</h3>
          <el-button v-if="editable" size="mini" icon="el-icon-plus" @click="addPlanRow">新增非客户事项</el-button>
        </div>
        <crm-combat-grid :rows="customerPlanRows" :columns="planFields" :suggestions="projectSuggestions" :editable="editable" :show-money="amountsAvailable"
          empty-text="在重点客户推进里填写明日动作后自动带入" @project="detail" />
        <div v-if="form.plan.length" class="subsection-head">
          <span class="tool-label">补充事项（非客户专项任务，共 {{ form.plan.length }} 项）</span>
        </div>
        <el-table v-if="form.plan.length" :data="form.plan" border size="mini" class="combat-table" empty-text="在客户池填写明日动作后，计划会显示在这里">
          <el-table-column label="序号" width="58" align="center">
            <template slot-scope="{ $index }">{{ $index + 1 }}</template>
          </el-table-column>
          <el-table-column label="工作事项" min-width="140">
            <template slot-scope="{ row }">
              <el-input v-if="editable" v-model="row.task" size="mini" placeholder="如：准备培训资料" />
              <span v-else>{{ row.task }}</span>
            </template>
          </el-table-column>
          <el-table-column label="客户名称" min-width="140">
            <template slot-scope="{ row }">
              <span>{{ row.customer_name }}</span>
            </template>
          </el-table-column>
          <el-table-column label="时间段" width="120">
            <template slot-scope="{ row }">
              <el-input v-if="editable" v-model="row.time" size="mini" placeholder="09:00-10:30" />
              <span v-else>{{ row.time }}</span>
            </template>
          </el-table-column>
          <el-table-column label="具体动作" min-width="180">
            <template slot-scope="{ row }">
              <el-input v-if="editable" v-model="row.action" size="mini" type="textarea" :autosize="{ minRows: 1, maxRows: 3 }" />
              <span v-else>{{ row.action }}</span>
            </template>
          </el-table-column>
          <el-table-column label="推进成果" min-width="160">
            <template slot-scope="{ row }">
              <el-input v-if="editable" v-model="row.result" size="mini" type="textarea" :autosize="{ minRows: 1, maxRows: 3 }" />
              <span v-else>{{ row.result }}</span>
            </template>
          </el-table-column>
          <el-table-column label="卡点" min-width="140">
            <template slot-scope="{ row }">
              <el-input v-if="editable" v-model="row.blocker" size="mini" />
              <span v-else>{{ row.blocker }}</span>
            </template>
          </el-table-column>
          <el-table-column label="原因分析" min-width="140">
            <template slot-scope="{ row }">
              <el-input v-if="editable" v-model="row.reason" size="mini" />
              <span v-else>{{ row.reason }}</span>
            </template>
          </el-table-column>
          <el-table-column label="备注" min-width="110">
            <template slot-scope="{ row }">
              <el-input v-if="editable" v-model="row.remark" size="mini" />
              <span v-else>{{ row.remark }}</span>
            </template>
          </el-table-column>
          <el-table-column v-if="editable" label="操作" width="56" align="center">
            <template slot-scope="{ row }">
              <el-button type="text" size="mini" @click="form.plan.splice(form.plan.indexOf(row), 1)">删</el-button>
            </template>
          </el-table-column>
        </el-table>
      </section>
      <!-- 模块三：今日问题复盘（结构化紧凑九宫格） -->
      <section class="sheet-section">
        <div class="sheet-section-head">
          <h3><span class="section-badge">三</span> 今日问题复盘（晚上复盘就看这几项）</h3>
          <span v-if="form.supervisor_annotator" class="daily-muted">
            主管批注人：{{ form.supervisor_annotator }}（{{ form.supervisor_annotated_at }}）
          </span>
        </div>

        <div class="focus-pair-bar">
          <div v-for="group in focusGroups" :key="group.priority" class="focus-pair-card">
            <div class="focus-pair-lbl">
              <el-tag size="mini" :type="group.priority === 'primary' ? 'danger' : 'warning'" effect="dark">
                {{ group.priority === 'primary' ? '主攻' : '补位' }}
              </el-tag>
              <span>{{ group.label }}</span>
            </div>
            <div class="focus-pair-tags">
              <template v-if="group.names.length">
                <el-tag v-for="(name, i) in group.names" :key="name + '-' + i" size="small" effect="plain" type="success">
                  {{ name }}
                </el-tag>
              </template>
              <span v-else class="daily-muted">客户池尚未选择</span>
            </div>
          </div>
        </div>

        <div class="review-cards-grid">
          <div v-for="f in reviewFields" :key="f.key" class="review-card">
            <label class="review-card-lbl">{{ f.label }}</label>
            <el-select
              v-if="editable && f.options"
              :value="form.review_detail[f.key]"
              size="small"
              filterable
              clearable
              allow-create
              style="width: 100%"
              placeholder="请选择或输入"
              @input="$set(form.review_detail, f.key, $event)"
            >
              <el-option v-for="v in f.options" :key="v" :value="v" />
            </el-select>
            <el-input
              v-else
              :value="form.review_detail[f.key]"
              type="textarea"
              :autosize="{ minRows: 2, maxRows: 4 }"
              :readonly="!editable"
              maxlength="1000"
              placeholder="请输入..."
              @input="$set(form.review_detail, f.key, $event)"
            />
            <crm-ai-field-hint
              v-if="editable && aiBlank(form.review_detail[f.key])"
              :field="f.label"
              :suggestion="reviewHints[f.key]"
              @apply="fillAiBlanks(form.review_detail, { [f.key]: reviewHints[f.key] })"
            />
          </div>
        </div>

        <div class="review-bottom-stack">
          <div class="help-panel-card">
            <div class="help-panel-head">
              <span class="review-card-lbl" style="margin: 0">主管协调</span>
              <div v-if="editable" class="sheet-toolbar-group">
                <el-select v-model="helpTarget" filterable size="small" style="min-width: 220px" placeholder="选择项目或部门事项">
                  <el-option label="部门事项" value="department" />
                  <el-option v-for="row in form.pool_others.filter(r => r.key)" :key="row.key" :value="row.key" :label="label(row.key)" />
                </el-select>
                <el-button size="small" type="primary" plain icon="el-icon-edit" @click="editHelp()">填写协调事项</el-button>
              </div>
            </div>
            <div v-if="helpRows.length" class="help-entry-list">
              <div v-for="h in helpRows" :key="h.key" class="help-entry-card">
                <div class="help-entry-main">
                  <el-tag size="mini" :type="h.action && h.action.help_status === 'done' ? 'success' : (h.action && h.action.help_status === 'returned' ? 'warning' : 'info')">
                    {{ h.action ? states[h.action.help_status] : '待确认' }}
                  </el-tag>
                  <b>{{ h.label }}</b>
                  <span>{{ h.note }}</span>
                  <span v-if="h.action && h.action.manager_note" class="daily-muted">
                    · {{ h.action.manager_name }}：{{ h.action.manager_note }}
                  </span>
                  <span v-if="h.action && h.action.help_due" class="daily-muted">
                    · 期限 {{ h.action.help_due }}
                  </span>
                </div>
                <div class="help-entry-ops">
                  <el-button v-if="editable" type="text" size="mini" @click="editHelp(h)">编辑</el-button>
                  <el-button v-if="editable" type="text" size="mini" @click="removeHelp(h)">移除</el-button>
                  <el-button v-if="record.is_self && h.action && h.action.help_status === 'returned'" type="text" size="mini" @click="resubmit(h.action)">补充重提</el-button>
                </div>
              </div>
            </div>
            <div v-else class="daily-muted" style="margin-top: 6px">暂无协调事项</div>
          </div>

          <div class="supervisor-final-box">
            <div class="supervisor-final-lbl">
              <span>主管最终判断</span>
              <span v-if="form.supervisor_annotator" class="daily-muted">（{{ form.supervisor_annotator }}）</span>
            </div>
            <div class="supervisor-final-ctrl">
              <el-input
                v-model="form.review_detail.final"
                size="small"
                :readonly="!record.can_annotate"
                placeholder="由主管查阅作战表后填写最终批注"
              />
              <el-button
                v-if="record.can_annotate"
                size="small"
                type="success"
                @click="saveFinalNote"
              >
                保存主管批注
              </el-button>
            </div>
          </div>
        </div>
      </section>

    </template>
  </div>
</template>

<script>
import CrmCombatGrid from './CrmCombatGrid.vue'
import CrmAiFieldHint from './CrmAiFieldHint.vue'
import { progressFields, planFields, reviewFields, tableValues, aiBlank, fillAiBlanks } from './combatTemplates'
import {
  dailyCandidates,
  readDaily,
  saveDaily,
  saveSupervisorNote,
  analyzeDaily,
  exportDaily,
  pickCurrencyAmount,
  helpStatus,
  resubmitDailyHelp
} from '@/api/erp/crmDaily'

const emptyForm = () => ({
  template_version: 2,
  title: '',
  currency: 'CNY',
  close_target: null,
  ship_target: null,
  base: { today_close_target: null, today_ship_target: null, month_close_target: null, month_ship_target: null },
  department_help: [],
  pool_others: [],
  review_detail: { ...Object.fromEntries(reviewFields.map(f => [f.key, ''])), final: '' },
  plan: [],
  review: ''
})

export default {
  components: { CrmCombatGrid, CrmAiFieldHint },
  props: {
    date: { type: String, required: true },
    salesId: { type: Number, default: 0 },
    aiServiceCode: { type: String, default: '' },
    aiModel: { type: String, default: '' },
    hasAi: { type: Boolean, default: true }
  },
  data: () => ({
    busy: false,
    ready: false,
    error: '',
    record: {},
    form: emptyForm(),
    baseline: '',
    subjects: {},
    helpTarget: 'department',
    pickerOpen: false,
    keyword: '',
    list: [],
    total: 0,
    page: 1,
    searching: false,
    request: 0,
    progressFields: progressFields.map(c => c.key === 'priority' ? { ...c, strict: true } : c),
    planFields: planFields.map(c => ({ ...c, readonly: !c.edit })),
    reviewFields,
    states: helpStatus,
    labels: { primary: '主攻', backup: '补位', review: '仅复盘' },
    currencies: ['CNY', 'USD', 'EUR', 'GBP', 'HKD', 'JPY'],
    aiExpanded: true,
    aiTab: 'pool',
    autoSaveTimer: null,
    autoSavedAt: '',
    pendingLocalDraft: null
  }),
  computed: {
    customerPlanRows() {
      return this.form.pool_others.filter(r => r.tmr_must_action || ['primary', 'backup'].includes(r.priority))
    },
    v2Targets() {
      return [
        { key: 'today_close_target', label: '今日接单目标', hint: String(this.priorCloseTarget ?? '') },
        { key: 'today_ship_target', label: '今日出货目标', hint: String(this.priorShipTarget ?? '') },
        { key: 'month_close_target', label: '当月接单目标' }, { key: 'month_ship_target', label: '当月出货目标' }
      ]
    },
    v2Actuals() {
      const monthTarget = Number(this.form.base.month_close_target)
      const amount = v => this.amountsAvailable && v != null ? this.formatNum(v) : '未提供'
      const customers = this.form.pool_others.filter(r => r.customer_name || r.key)
      return [['今日接单实绩', amount(this.todayClosed)], ['今日接单差额', amount(this.closeGap)],
        ['今日出货实绩', amount(this.todayShipped)], ['今日出货差额', amount(this.shipGap)],
        ['当月接单实绩', amount(this.monthClosed)], ['当月出货总额', amount(this.monthShipped)],
        ['当月接单达成比', this.amountsAvailable && monthTarget > 0 ? (this.monthClosed / monthTarget * 100).toFixed(1) + '%' : '—'],
        ['今日重点客户数', customers.length], ['高概率客户数（>50%）', customers.filter(r => parseFloat(r.probability) > 50).length]]
    },
    dirty() {
      return this.ready && this.baseline !== JSON.stringify(this.form)
    },
    confirmed() {
      return !!this.record.confirmed
    },
    editable() {
      return this.ready && !!this.record.is_self && !this.confirmed
    },
    salesName() {
      return this.record.sales_user?.name || ''
    },
    amountsAvailable() {
      return this.record.amounts != null
    },
    todayClosed() {
      return pickCurrencyAmount(this.record.amounts?.closed, this.form.currency)
    },
    todayShipped() {
      return pickCurrencyAmount(this.record.amounts?.shipped, this.form.currency)
    },
    monthClosed() {
      return pickCurrencyAmount(this.record.monthly_amounts?.closed, this.form.currency)
    },
    monthShipped() {
      return pickCurrencyAmount(this.record.monthly_amounts?.shipped, this.form.currency)
    },
    priorCloseTarget() {
      const current = this.form.base.today_close_target
      if (current != null && current !== '') return Number(current)
      if (this.record.previous_target?.currency !== this.form.currency) return null
      const value = this.record.previous_target.close_target
      return value == null || value === '' ? null : Number(value)
    },
    priorShipTarget() {
      const current = this.form.base.today_ship_target
      if (current != null && current !== '') return Number(current)
      if (this.record.previous_target?.currency !== this.form.currency) return null
      const value = this.record.previous_target.ship_target
      return value == null || value === '' ? null : Number(value)
    },
    closeGap() {
      if (!this.amountsAvailable || this.priorCloseTarget === null) return null
      return Math.round((this.todayClosed - this.priorCloseTarget) * 100) / 100
    },
    shipGap() {
      if (!this.amountsAvailable || this.priorShipTarget === null) return null
      return Math.round((this.todayShipped - this.priorShipTarget) * 100) / 100
    },
    focusGroups() {
      return [['primary', '明日主攻客户'], ['backup', '明日补位客户']].map(([priority, label]) => {
        const names = this.form.pool_others.filter(r => r.priority === priority).map(r => r.customer_name || r.number)
        return { priority, label, names }
      })
    },
    helpRows() {
      if (this.confirmed) return (this.record.coordination || []).map(action => ({
        key: action.id, label: action.subject_type === 'department_issue' ? '部门事项' : this.label(action.subject_type + ':' + action.subject_id),
        note: action.help_request, action
      }))
      const projects = this.form.pool_others.filter(r => r.key && r.help_request).map(row => ({
        key: row.key, label: this.label(row.key), note: row.help_request, row
      }))
      return projects.concat(this.form.department_help.map((note, index) => ({ key: 'department:' + index, label: '部门事项', note, index })))
    },
    reviewHints() {
      if (!this.editable || this.record.analysis?.template_version !== 2) return {}
      return Object.fromEntries(this.reviewFields.filter(f => this.aiReview?.[f.key]).map(f => [f.key, this.aiReview[f.key]]))
    },
    projectSuggestions() {
      if (!this.editable || this.record.analysis?.template_version !== 2) return {}
      const hints = {}
      const add = (key, values) => {
        if (!key) return
        const row = hints[key] || (hints[key] = {})
        for (const [field, value] of Object.entries(values)) {
          if (typeof value === 'string' && value.trim() && value.trim() !== '待核实' && !row[field]) row[field] = value.trim()
        }
      }
      for (const a of this.aiPoolAdvice) {
        add(a.key, { max_blocker: a.max_blocker, reason: a.reason, tmr_must_action: a.tmr_must_action,
          action_type: a.action_type, tmr_advance_to: a.tmr_advance_to, anticipated_blocker: a.anticipated_blocker,
          response_action: a.response_action, materials: a.materials })
      }
      for (const a of this.aiPlanDraft) {
        add(a.subject_key, { tmr_must_action: a.action, tmr_advance_to: a.result, anticipated_blocker: a.blocker })
      }
      for (const a of this.aiItems) add(a.key, { max_blocker: a.blocker, tmr_must_action: a.action })
      return hints
    },
    aiPoolAdvice() {
      return Array.isArray(this.record.analysis?.pool_advice) ? this.record.analysis.pool_advice : []
    },
    aiItems() {
      return Array.isArray(this.record.analysis?.items) ? this.record.analysis.items : []
    },
    aiReview() {
      return this.record.analysis?.review_suggestions || null
    },
    aiPlanDraft() {
      return Array.isArray(this.record.analysis?.plan_draft) ? this.record.analysis.plan_draft : []
    }
  },
  created() {
    this.reload()
    this.autoSaveTimer = setInterval(() => this.autoSaveDraft(), 60000)
  },
  beforeDestroy() {
    if (this.autoSaveTimer) clearInterval(this.autoSaveTimer)
  },
  methods: {
    aiBlank,
    fillAiBlanks,
    formatNum(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    actualDeltaClass(idx) {
      if (!this.amountsAvailable) return ''
      if (idx === 1) return this.closeGap > 0 ? 'num-pos' : (this.closeGap < 0 ? 'num-neg' : '')
      if (idx === 3) return this.shipGap > 0 ? 'num-pos' : (this.shipGap < 0 ? 'num-neg' : '')
      return ''
    },
    draftStorageKey(data = this.record) {
      const sid = data?.sales_user?.id || this.salesId || 0
      return `crm_combat_draft_${sid}_${this.date}`
    },
    apply(data, checkLocalDraft = true) {
      this.record = data
      const b = data.body || {}
      const itemMap = Object.fromEntries((b.items || []).map(x => [x.key, x]))
      const poolOthers = (b.pool_others || []).map(r => {
        const it = r.key ? itemMap[r.key] : null
        return {
          ...r,
          priority: it ? it.priority : (r.priority || ''),
          help_request: it ? (it.help_request || '') : (r.help_request || ''),
          tmr_must_action: r.tmr_must_action || (it?.action || ''),
          contact_time: r.contact_time || it?.time || '',
          max_blocker: r.max_blocker || (it?.blocker || ''),
          tmr_advance_to: r.tmr_advance_to || (it?.expected_result || '')
        }
      })
      const subjectsMap = Object.fromEntries((data.subjects || []).map(s => [s.key, s]))
      this.form = {
        ...emptyForm(),
        ...b,
        base: { ...emptyForm().base, ...(b.base || {}) },
        review_detail: { ...emptyForm().review_detail, ...(b.review_detail || {}) },
        pool_others: poolOthers,
        department_help: [...(b.department_help || [])],
        plan: (b.plan || []).map(x => ({ ...x }))
      }
      delete this.form.items
      this.aiTab = 'pool'
      this.subjects = subjectsMap
      this.baseline = JSON.stringify(this.form)
      this.ready = true

      if (checkLocalDraft && data.is_self && !data.confirmed) {
        this.inspectLocalDraft(data)
      } else {
        this.pendingLocalDraft = null
      }
    },
    inspectLocalDraft(data) {
      try {
        const raw = localStorage.getItem(this.draftStorageKey(data))
        if (!raw) {
          this.pendingLocalDraft = null
          return
        }
        const parsed = JSON.parse(raw)
        if (parsed?.form?.template_version === 2 && JSON.stringify(parsed.form) !== this.baseline) {
          this.pendingLocalDraft = parsed
        } else {
          this.pendingLocalDraft = null
        }
      } catch (_) {
        this.pendingLocalDraft = null
      }
    },
    restoreLocalDraft() {
      if (!this.pendingLocalDraft?.form) return
      const restored = this.pendingLocalDraft.form
      this.form = {
        ...emptyForm(),
        ...restored,
        template_version: 2,
        base: { ...emptyForm().base, ...(restored.base || {}) },
        review_detail: { ...emptyForm().review_detail, ...(restored.review_detail || {}) }
      }
      if (!this.amountsAvailable) this.maskLocalAmounts()
      this.pendingLocalDraft = null
      this.$message.success('已恢复本地草稿')
    },
    maskLocalAmounts() {
      this.form.close_target = null
      this.form.ship_target = null
      Object.keys(this.form.base).forEach(field => { this.form.base[field] = null })
      this.form.pool_others.forEach(row => { row.expected_amount = null })
    },
    dismissLocalDraft() {
      try { localStorage.removeItem(this.draftStorageKey()) } catch (_) {}
      this.pendingLocalDraft = null
    },
    autoSaveDraft() {
      if (!this.editable || !this.dirty) return
      try {
        const now = new Date()
        const t = now.toTimeString().slice(0, 8)
        localStorage.setItem(this.draftStorageKey(), JSON.stringify({ savedAt: t, form: this.form }))
        this.autoSavedAt = t
      } catch (_) {}
    },
    async editHelp(entry) {
      const row = entry ? entry.row : this.form.pool_others.find(r => r.key === this.helpTarget)
      const value = entry?.note || row?.help_request || this.aiItems.find(r => row && r.key === row.key)?.help || ''
      try {
        const { value: note } = await this.$prompt(row ? this.label(row.key) : '部门事项', '填写主管协调事项', {
          inputValue: value, inputType: 'textarea', inputValidator: v => !!v?.trim() && v.trim().length <= 500 || '请填写 1 至 500 字的事项'
        })
        const text = note.trim()
        if (row) this.$set(row, 'help_request', text)
        else if (this.form.department_help.some((n, i) => n === text && i !== entry?.index)) this.$message.info('该协调事项已在列表中')
        else if (entry) this.form.department_help.splice(entry.index, 1, text)
        else this.form.department_help.push(text)
      } catch (_) {}
    },
    removeHelp(entry) {
      if (entry.row) this.$set(entry.row, 'help_request', '')
      else this.form.department_help.splice(entry.index, 1)
    },
    async run(work) {
      if (this.busy) return
      this.busy = true
      this.$emit('working', true)
      this.error = ''
      try {
        await work()
      } catch (e) {
        this.error = e.userMessage || e.response?.data?.message || e.message || '操作失败'
      } finally {
        this.busy = false
        this.$emit('working', false)
      }
    },
    async reload() {
      if (this.dirty) {
        try {
          await this.$confirm('重新加载将放弃未保存的修改。', '重新加载')
        } catch (_) {
          return
        }
      }
      return this.run(async () => {
        const params = { date: this.date }
        if (this.salesId) params.sales_id = this.salesId
        this.apply((await readDaily('sales', params)).data)
      })
    },
    async write(confirm) {
      const payload = {
        ...this.form,
        version: this.record.version,
        date: this.date,
        confirm
      }
      const { data } = await saveDaily('sales', payload)
      this.dismissLocalDraft()
      this.autoSavedAt = ''
      this.apply(data, false)
      this.$emit('changed')
      if (confirm) this.$emit('confirmed')
    },
    persist(confirm) {
      return this.run(async () => {
        await this.write(confirm)
        this.$message.success(confirm ? '作战表已确认并同步明日待办' : '草稿已保存')
      })
    },
    async saveFinalNote() {
      return this.run(async () => {
        const { data } = await saveSupervisorNote({
          date: this.date,
          sales_id: this.record.sales_user.id,
          final: this.form.review_detail.final || ''
        })
        this.apply(data, false)
        this.$emit('changed')
        this.$message.success('主管批注已保存')
      })
    },
    async resubmit(item) {
      let note
      try {
        note = (
          await this.$prompt('请补充需要主管协调的事项。', '重新提交', {
            inputValue: item.help_request,
            inputPattern: /\S/,
            inputErrorMessage: '请填写具体事项'
          })
        ).value
      } catch (_) {
        return
      }
      return this.run(async () => {
        await resubmitDailyHelp(item, note)
        const params = { date: this.date }
        if (this.salesId) params.sales_id = this.salesId
        this.apply((await readDaily('sales', params)).data, false)
        this.$emit('changed')
      })
    },
    async confirm() {
      const n = this.form.pool_others.filter(x => x.key && ['primary', 'backup'].includes(x.priority)).length
      const planned = new Date(this.date + 'T12:00:00')
      planned.setDate(planned.getDate() + 1)
      try {
        await this.$confirm(
          `确认 ${this.date} 的作战表后将锁定填报，并生成 ${n} 项 ${planned.toLocaleDateString('zh-CN')} 的工作台跟进待办及协调事项。`,
          '确认销售作战表'
        )
      } catch (_) {
        return
      }
      return this.persist(true)
    },
    analyze(prefill = false) {
      return this.run(async () => {
        if (!this.record.version || this.dirty) await this.write(false)
        const extra = {}
        if (this.aiServiceCode) extra.service_code = this.aiServiceCode
        if (this.aiModel) extra.model = this.aiModel
        this.apply((await analyzeDaily('sales', this.date, this.record.version, extra)).data, false)
        this.aiExpanded = true
        if (prefill && this.editable) {
          this.prefillDraft()
        } else {
          this.$message.success('填写提示已生成，可在对应字段旁采用')
        }
      })
    },
    // AI 采纳方法（默认仅填充空白，不覆盖手工填写内容）
    prefillDraft() {
      let count = fillAiBlanks(this.form.review_detail, this.reviewHints)
      for (const row of this.form.pool_others) count += fillAiBlanks(row, this.projectSuggestions[row.key])
      this.$message.success(count ? '已预填 ' + count + ' 个空白字段，可修改后保存' : '本次没有可预填的空白字段')
    },
    adoptPoolAdvice() {
      for (const advice of this.aiPoolAdvice) {
        this.applySinglePoolAdvice(advice, false)
      }
      this.$message.success('客户池推进建议已填充')
    },
    applySinglePoolAdvice(advice, toast = true) {
      let row = this.form.pool_others.find(r => r.key === advice.key)
      if (!row && this.subjects[advice.key]) {
        this.chooseToPool(this.subjects[advice.key])
        row = this.form.pool_others.find(r => r.key === advice.key)
      }
      if (!row) return
      const fields = ['max_blocker', 'tmr_must_action', 'action_type', 'tmr_advance_to', 'probability',
        'reason', 'anticipated_blocker', 'response_action', 'materials']
      for (const f of fields) {
        if (advice[f] && !String(row[f] || '').trim()) {
          this.$set(row, f, advice[f])
        }
      }
      if (toast) this.$message.success('已采纳该客户推进建议')
    },
    adoptRecommendedItem(r) {
      let row = this.form.pool_others.find(p => p.key === r.key)
      if (!row && this.subjects[r.key]) {
        this.chooseToPool(this.subjects[r.key])
        row = this.form.pool_others.find(p => p.key === r.key)
      }
      if (!row) return
      if (!row.priority || row.priority === 'review') this.$set(row, 'priority', r.priority || 'primary')
      if (!row.max_blocker && r.blocker) this.$set(row, 'max_blocker', r.blocker)
      if (!row.tmr_must_action && r.action) this.$set(row, 'tmr_must_action', r.action)
      this.$message.success(`已标为${this.labels[row.priority]}`)
    },
    adoptReview() {
      fillAiBlanks(this.form.review_detail, this.reviewHints)
      this.$message.success('今日复盘建议已填充')
    },
    adoptPlan() {
      let changed = false
      for (const suggestion of this.aiPlanDraft) {
        if (suggestion.subject_key || suggestion.customer_name) {
          let row = this.form.pool_others.find(r => r.key && r.key === suggestion.subject_key)
          if (!row && suggestion.subject_key && this.subjects[suggestion.subject_key]) {
            this.chooseToPool(this.subjects[suggestion.subject_key])
            row = this.form.pool_others.find(r => r.key === suggestion.subject_key)
          } else if (!row && !suggestion.subject_key) {
            const matches = this.form.pool_others.filter(r => r.customer_name === suggestion.customer_name)
            if (matches.length === 1) row = matches[0]
          }
          if (!row) continue
          for (const [field, value] of Object.entries({
            tmr_must_action: suggestion.action, contact_time: suggestion.time, tmr_advance_to: suggestion.result,
            max_blocker: suggestion.blocker, action_type: suggestion.task
          })) {
            if (value && !row[field]) {
              this.$set(row, field, value)
              changed = true
            }
          }
        } else if (suggestion.task || suggestion.action) {
          const fields = ['task', 'time', 'action']
          const ref = suggestion.ai_ref || ''
          const exists = this.form.plan.some(r => (ref && r.ai_ref === ref) || fields.every(f => (r[f] || '').trim() === (suggestion[f] || '').trim()))
          if (!exists) {
            this.form.plan.push({ ...suggestion, ai_ref: ref })
            changed = true
          }
        }
      }
      return changed
    },
    togglePicker() {
      this.pickerOpen = !this.pickerOpen
      if (this.pickerOpen && !this.list.length) this.search(1)
    },
    async search(page) {
      const seq = ++this.request
      this.searching = true
      try {
        const { data } = await dailyCandidates({ date: this.date, keyword: this.keyword, page })
        if (seq === this.request) {
          this.list = data.data
          this.total = data.total
          this.page = page
        }
      } catch (e) {
        if (seq === this.request) this.$message.error(e.userMessage || '查询失败')
      } finally {
        if (seq === this.request) this.searching = false
      }
    },
    hasPoolKey(key) {
      return this.form.pool_others.some(r => r.key === key)
    },
    priorityCount(prio) {
      return this.form.pool_others.filter(r => r.key && r.priority === prio).length
    },
    chooseToPool(s) {
      this.$set(this.subjects, s.key, s)
      if (this.hasPoolKey(s.key)) return
      this.form.pool_others.push({
        key: s.key,
        kind: s.kind,
        id: s.id,
        number: s.number,
        pool: s.pool || (s.kind === 'opportunity' ? '商机推进池' : '跟进池'),
        customer_name: s.customer_name || '',
        project: s.project || '',
        expected_amount: s.expected_amount ?? null,
        currency: s.currency || this.form.currency,
        stage: s.stage_label || '',
        stage_label: s.stage_label || '',
        probability: '',
        priority: '',
        tmr_must_action: '',
        action_type: '',
        contact_time: '',
        tmr_advance_to: '',
        expected_pay_time: s.close_date || '',
        max_blocker: '',
        help_request: '',
        owner: this.salesName,
        status: '待推进'
      })
    },
    newRowRef() {
      return 'manual-' + Date.now().toString(36) + '-' + Math.random().toString(36).slice(2)
    },
    addOtherPoolRow() {
      this.form.pool_others.push({
        row_ref: this.newRowRef(),
        key: '',
        kind: '',
        id: null,
        number: '',
        pool: '补位池',
        customer_name: '',
        project: '',
        expected_amount: null,
        currency: this.form.currency,
        stage: '',
        stage_label: '',
        probability: '',
        priority: '',
        tmr_must_action: '',
        action_type: '',
        contact_time: '',
        tmr_advance_to: '',
        expected_pay_time: '',
        max_blocker: '',
        help_request: '',
        owner: this.salesName,
        status: '待推进'
      })
    },
    addPlanRow() {
      this.form.plan.push({
        seq: this.form.plan.length + 1,
        task: '',
        customer_name: '',
        subject_key: '',
        time: '',
        action: '',
        result: '',
        blocker: '',
        reason: '',
        remark: ''
      })
    },
    label(key) {
      const s = this.subjects[key]
      return s ? [s.customer_name, s.project, s.number].filter(Boolean).join(' · ') : key
    },
    detail(key) {
      if (this.subjects[key]) this.$emit('project', this.subjects[key])
    },
    download() {
      return this.run(async () => {
        const params = { date: this.date, sales_id: this.record.sales_user.id }
        if (this.dirty) params.project_keys = this.form.pool_others.map(r => r.key).filter(Boolean)
        const { data } = await readDaily('sales', params)
        if (!this.dirty || data.confirmed) this.apply(data, false)
        else {
          this.record = { ...data, version: this.record.version }
          if (!this.amountsAvailable) this.maskLocalAmounts()
        }
        const money = value => this.amountsAvailable ? value : '未提供'
        const allowedProjects = new Set(data.subjects.map(r => r.key))
        const rd = this.form.review_detail
        const pool = this.form.pool_others.filter(r => !r.key || allowedProjects.has(r.key))
        const plans = this.customerPlanRows.filter(r => !r.key || allowedProjects.has(r.key))
        const base = [['日期', this.date], ['销售姓名', this.salesName], ['币种', this.form.currency], ...this.v2Actuals,
          ['明日接单目标', money(this.form.close_target)], ['明日出货目标', money(this.form.ship_target)],
          ...this.v2Targets.map(f => [f.label, money(this.form.base[f.key] ?? (f.hint || ''))])]
        const review = [...this.focusGroups.map(g => [g.label, g.names.join('、')]),
          ...this.reviewFields.map(f => [f.label, rd[f.key]]), ['需要主管协调', this.helpRows.map(h => h.label + '：' + h.note).join('；')],
          ['主管最终判断', rd.final]]
        return exportDaily('销售日报V2-' + this.salesName + '-' + this.date, [
          ['基础目标与实绩', base], ['重点客户推进', tableValues(this.progressFields, pool, this.amountsAvailable)],
          ['明日作战计划', tableValues(this.planFields, plans, this.amountsAvailable)], ['今日问题复盘', review],
          ['补充事项', [['事项','客户','时间','动作','结果','卡点','原因','备注'], ...this.form.plan.map(p => [p.task,p.customer_name,p.time,p.action,p.result,p.blocker,p.reason,p.remark])]]])
      })
    }
  }
}
</script>

<style scoped>
.combat-banner-left {
  flex: 1;
  min-width: 280px;
  max-width: 520px;
}
.combat-banner-eyebrow {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
  font-size: 12px;
  color: #bce3cf;
  margin-bottom: 6px;
}
.actuals-row-top {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 10px;
}
.actuals-row-bottom {
  display: grid;
  grid-template-columns: repeat(5, minmax(0, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}
.actual-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 9px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}
.actual-card.is-sub {
  background: #ffffff;
  border-color: #e6edf4;
}
.actual-lbl {
  font-size: 11.5px;
  color: #64748b;
  font-weight: 600;
}
.actual-val {
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}
.base-targets-grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(0, 1fr));
  gap: 10px;
}
.target-tile {
  padding: 8px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #ffffff;
  transition: border-color 0.15s ease;
}
.target-tile:hover {
  border-color: #cbd5e1;
}
.target-tile.is-primary {
  background: #f2fbf6;
  border-color: #bce3cf;
}
.target-tile label {
  display: block;
  font-size: 11.5px;
  color: #334155;
  font-weight: 600;
  margin-bottom: 5px;
  white-space: nowrap;
}
.target-tile.is-primary label {
  color: #00763f;
}
.focus-pair-bar {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}
.focus-pair-card {
  padding: 10px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}
.focus-pair-lbl {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12.5px;
  font-weight: 700;
  color: #1e293b;
}
.focus-pair-tags {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-wrap: wrap;
}
.review-cards-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin-bottom: 12px;
}
.review-card {
  padding: 10px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  display: flex;
  flex-direction: column;
}
.review-card-lbl {
  display: block;
  font-size: 12.5px;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 6px;
}
.review-card ::v-deep .el-textarea__inner {
  background: #ffffff;
  border-color: #dbe3ec;
  border-radius: 6px;
  font-size: 12.5px;
}
.review-card ::v-deep .el-textarea__inner:focus {
  border-color: #008b4b;
}
.review-bottom-stack {
  display: flex;
  flex-direction: column;
  gap: 10px;
}
.help-panel-card {
  padding: 12px 14px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}
.help-panel-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  flex-wrap: wrap;
}
.help-entry-list {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-top: 8px;
}
.help-entry-card {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
  padding: 8px 12px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  font-size: 12.5px;
}
.help-entry-main {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.help-entry-ops {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}
.supervisor-final-box {
  padding: 12px 14px;
  background: #effaf4;
  border: 1px solid #bce3cf;
  border-radius: 8px;
}
.supervisor-final-lbl {
  font-size: 13px;
  font-weight: 700;
  color: #00763f;
  margin-bottom: 8px;
}
.supervisor-final-ctrl {
  display: flex;
  align-items: center;
  gap: 8px;
}
@media (max-width: 1120px) {
  .actuals-row-top {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .actuals-row-bottom {
    grid-template-columns: repeat(3, minmax(0, 1fr));
  }
  .base-targets-grid,
  .review-cards-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 760px) {
  .actuals-row-top,
  .actuals-row-bottom,
  .base-targets-grid,
  .focus-pair-bar,
  .review-cards-grid {
    grid-template-columns: 1fr;
  }
}
</style>
