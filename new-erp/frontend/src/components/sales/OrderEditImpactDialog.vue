<template>
  <el-dialog
    :visible="visible"
    :close-on-click-modal="false"
    :close-on-press-escape="false"
    :show-close="!submitting && !previewing"
    class="order-edit-impact-dialog"
    append-to-body
    @update:visible="$emit('update:visible', $event)"
    @opened="resetScroll"
    @close="$emit('close')"
  >
    <template slot="title">本次修改影响</template>

    <div class="impact-dialog-shell">
      <div ref="impactScroll" :class="['impact-dialog-scroll', { 'is-long-list': changes.length > 5 || selectionGroups.length }]">
        <section class="impact-summary" aria-label="修改摘要">
          <div class="summary-item summary-total"><i class="el-icon-info" /><span>检测到</span><strong>{{ displaySummary.total }}</strong><span>项修改</span></div>
          <div class="summary-item"><span>无需审核</span><strong>{{ displaySummary.none }}</strong><span>项</span></div>
          <div class="summary-item"><span>需业务审核</span><strong>{{ displaySummary.business }}</strong><span>项</span></div>
          <div class="summary-item"><span>需财务审核</span><strong>{{ displaySummary.finance }}</strong><span>项</span></div>
          <div class="summary-item"><span>需库存交付审核</span><strong>{{ displaySummary.fulfillment }}</strong><span>项</span></div>
          <div class="summary-item summary-level"><span>本次修改等级：</span><strong :class="`level-${level}`">{{ levelText }}</strong></div>
        </section>

        <section class="impact-detail-section">
          <table class="impact-detail-table">
            <thead>
              <tr><th>序号</th><th>修改内容</th><th>修改前</th><th>修改后</th><th>业务影响</th><th>审核要求</th></tr>
            </thead>
            <tbody>
              <tr v-for="(row, index) in changes" :key="`${row.key || 'change'}-${index}`">
                <td>{{ index + 1 }}</td>
                <td>{{ row.label }}</td>
                <td>{{ row.before || '—' }}</td>
                <td>{{ row.after || '—' }}</td>
                <td>{{ row.impact || '无业务影响' }}</td>
                <td>
                  <div class="requirement-tags">
                    <el-tag v-for="(item, tagIndex) in requirementLabels(row)" :key="`${item}-${tagIndex}`" size="mini" :type="requirementTagType(item)">{{ item }}</el-tag>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </section>

        <section v-if="selectionGroups.length" class="production-unit-section">
          <h3>选择本次处理的设备 / 子件</h3>
          <p>本次已选商品编号 {{ selectedProductCount }} 个，关联子件编号 {{ selectedComponentCount }} 个。</p>
          <div v-for="group in selectionGroups" :key="`${group.line_id}-${group.work_order_id}-${group.parent_unit_id || 0}`" class="production-unit-group">
            <div class="production-unit-head"><strong>{{ group.item_name }} · {{ group.work_order_no }}</strong><el-button type="text" size="mini" :disabled="submitting || previewing" @click="$emit('choose-production-units', group)">{{ group.selection_required && (group.selected_unit_ids || []).length ? '确认所选编号' : '选择 / 调整编号' }}</el-button></div>
            <p v-if="group.selection_required && (group.selected_unit_ids || []).length">这些编号尚待确认，请点“确认所选编号”核对。</p>
            <p v-if="group.parent_unit_serial_no">所属主设备：{{ group.parent_unit_serial_no }}</p>
            <p v-if="(group.required_unit_ids || []).length">已经装在这台主机上的子件已固定选中，不能换成另一个。</p>
            <p v-if="group.allow_count_change">这张工单可选 0 至 {{ group.max_count }} 个，已选 {{ (group.selected_unit_ids || []).length }} 个；本商品编号合计{{ lineRequiredText(group) }}，当前已选 {{ lineSelectedCount(group) }} 个</p>
            <p v-else>本次应选 {{ group.required_count }} 个，已选 {{ (group.selected_unit_ids || []).length }} 个</p>
            <div class="production-unit-tags"><el-tag v-for="unit in group.selected_units || []" :key="unit.id" size="mini">{{ unit.serial_no || ('第' + unit.unit_no + '个') }}</el-tag><span v-if="!(group.selected_unit_ids || []).length">请先选择具体编号</span></div>
          </div>
        </section>
        <section v-if="quantityAdjustments.length" class="production-unit-section">
          <h3>另外减少的计划 / 库存需求</h3>
          <p v-if="!selectedProductCount">本次未选择具体商品编号，先减少下列尚未下达的计划或库存备货需求。</p>
          <p v-for="(item,index) in quantityAdjustments" :key="`${item.line_id}-${item.work_order_id || 0}-${item.fulfillment_id}-${index}`">{{ item.item_name }} · {{ item.origin === 'planned' ? '尚未下达的计划' : '库存备货需求' }}：减少 {{ item.qty }} {{ item.unit_name || '' }}</p>
        </section>
        <el-alert v-if="selectionErrors.length" :title="selectionErrors.join('；')" type="warning" :closable="false" show-icon />

        <section class="approval-section">
          <h3>本次审核要求</h3>
          <div class="approval-cards">
            <article v-for="card in approvalCards" :key="card.key" :class="['approval-card', card.key, { required: card.required }]">
              <div class="approval-card-head"><span><i :class="card.icon" />{{ card.title }}</span><el-tag size="mini" :type="card.required ? card.tagType : 'info'">{{ card.required ? '需审核' : '不需要' }}</el-tag></div>
              <p>{{ card.description }}</p>
            </article>
          </div>
        </section>

        <section :class="['effect-note', { immediate: !requiresApproval }]">
          <i :class="requiresApproval ? 'el-icon-warning-outline' : 'el-icon-success'" />
          <span v-if="effectSummary">{{ effectSummary }}</span>
          <span v-else-if="requiresApproval">本次修改将形成 Candidate {{ candidateVersion }}；审核通过前，当前正式版本 {{ effectiveVersion }} 及已生效的库存、生产和交付安排保持不变。</span>
          <span v-else>本次修改保存后立即生效，并记录修改历史。</span>
        </section>

        <section class="reason-section">
          <label :class="{ required: requiresApproval }">{{ requiresApproval ? '变更原因' : '修改说明（可选）' }}</label>
          <el-input v-model="localReason" type="textarea" :rows="2" maxlength="200" show-word-limit :placeholder="requiresApproval ? '请输入本次变更原因（必填）' : '请输入修改说明（可选）'" @input="$emit('update:reason', localReason)" />
        </section>
      </div>

      <el-alert v-if="submitError" :title="submitError" type="error" :closable="false" show-icon class="impact-submit-error" />

      <footer class="impact-dialog-footer">
        <el-button size="small" :disabled="submitting || previewing" @click="$emit('back')">返回修改</el-button>
        <el-button size="small" type="success" :loading="submitting || previewing" :disabled="!canSubmit || previewing" @click="$emit('submit')">{{ requiresApproval ? '提交审核' : '确认保存' }}</el-button>
      </footer>
    </div>
  </el-dialog>
</template>

<script>
const approvalMeta = [
  { key: 'business', title: '业务审核', icon: 'el-icon-user-solid', tagType: '' },
  { key: 'finance', title: '财务审核', icon: 'el-icon-coin', tagType: 'warning' },
  { key: 'fulfillment', title: '库存交付审核', icon: 'el-icon-s-check', tagType: 'primary' }
]

export default {
  name: 'OrderEditImpactDialog',
  props: {
    visible: { type: Boolean, default: false },
    changes: { type: Array, default: () => [] },
    approvals: { type: Object, default: () => ({}) },
    approvalReasons: { type: Object, default: () => ({}) },
    level: { type: String, default: 'low' },
    candidateVersion: { type: String, default: 'V4' },
    effectiveVersion: { type: String, default: 'V3' },
    effectSummary: { type: String, default: '' },
    reason: { type: String, default: '' },
    submitting: { type: Boolean, default: false },
    submitError: { type: String, default: '' },
    selectionGroups: { type: Array, default: () => [] },
    selectionErrors: { type: Array, default: () => [] },
    quantityAdjustments: { type: Array, default: () => [] },
    canSubmit: { type: Boolean, default: true },
    previewing: { type: Boolean, default: false },
    summary: { type: Object, default: null }
  },
  data() { return { localReason: this.reason } },
  computed: {
    requiresApproval() { return Object.values(this.approvals || {}).some(Boolean) },
    selectedProductCount() { return this.selectionGroups.filter(group => !group.parent_unit_id).reduce((sum, group) => sum + (group.selected_unit_ids || []).length, 0) },
    selectedComponentCount() { return this.selectionGroups.filter(group => group.parent_unit_id).reduce((sum, group) => sum + (group.selected_unit_ids || []).length, 0) },
    levelText() { return ({ low: '低', medium: '中', high: '高' })[this.level] || '低' },
    displaySummary() {
      if (this.$props.summary) return this.$props.summary
      const rows = this.changes || []
      const count = text => rows.filter(row => String(row.requirement || '直接保存').includes(text)).length
      return { total: rows.length, none: count('直接保存'), business: count('业务审核'), finance: count('财务审核'), fulfillment: count('库存交付审核') }
    },
    approvalCards() {
      return approvalMeta.map(card => ({
        ...card,
        required: Boolean(this.approvals && this.approvals[card.key]),
        description: String((this.approvalReasons[card.key] || {}).description || '')
      }))
    }
  },
  watch: { reason(value) { this.localReason = value } },
  methods: {
    resetScroll() {
      this.$nextTick(() => {
        if (this.$refs.impactScroll) this.$refs.impactScroll.scrollTop = 0
      })
    },
    requirementLabels(row) {
      return String(row.requirement || '直接保存').split(/\s*\+\s*/).filter(Boolean)
    },
    lineSelectedCount(group) { return this.selectionGroups.filter(item => item.line_id === group.line_id && !item.parent_unit_id).reduce((sum, item) => sum + (item.selected_unit_ids || []).length, 0) },
    lineRequiredText(group) { return group.line_min_numbered_count !== undefined && group.line_max_numbered_count !== undefined ? `可选 ${group.line_min_numbered_count} 至 ${group.line_max_numbered_count} 个` : `应选 ${group.line_required_count} 个` },
    requirementTagType(requirement) {
      if (String(requirement).includes('财务')) return 'warning'
      if (String(requirement).includes('库存与交付')) return 'primary'
      if (String(requirement).includes('业务')) return ''
      return 'success'
    }
  }
}
</script>

<style>
.production-unit-section {
  margin-top:16px;
}
.production-unit-section h3 {
  margin:0 0 9px;
  font-size:13px;
  color:#172033;
}
.production-unit-group {
  padding:10px;
  border:1px solid #e2e8f0;
  border-radius:4px;
  margin-top:8px;
  font-size:12px;
}
.production-unit-head {
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:12px;
}
.production-unit-group p {
  margin:6px 0;
  color:#64748b;
}
.production-unit-tags {
  display:flex;
  flex-wrap:wrap;
  gap:6px;
}
.order-edit-impact-dialog .el-dialog{width:820px;max-width:calc(100vw - 32px);margin-top:calc((100vh - 731px)/2)!important;border-radius:7px;overflow:hidden}
.order-edit-impact-dialog .el-dialog__header{height:59px;padding:0 18px;display:flex;align-items:center;border-bottom:1px solid #eef1f5}
.order-edit-impact-dialog .el-dialog__title{font-size:18px;font-weight:700;color:#172033;line-height:1}
.requirement-tags{display:flex;flex-wrap:wrap;gap:3px}.requirement-tags .el-tag{height:21px;padding:0 4px;line-height:19px;font-size:11px;white-space:nowrap}
.order-edit-impact-dialog .el-dialog__headerbtn{top:20px;right:19px}.order-edit-impact-dialog .el-dialog__headerbtn .el-dialog__close{font-size:17px;color:#64748b}
.order-edit-impact-dialog .el-dialog__body{padding:0}.impact-dialog-shell{display:flex;flex-direction:column;max-height:654px;background:#fff}.impact-dialog-scroll{padding:3px 18px 12px;overflow:hidden}.impact-dialog-scroll.is-long-list{overflow:auto}.impact-summary{height:58px;display:grid;grid-template-columns:1.24fr repeat(4,1fr) 1.12fr;border:1px solid #a8cffd;border-radius:4px;background:#eff7ff;overflow:hidden}.summary-item{display:flex;align-items:center;justify-content:center;gap:4px;min-width:0;color:#275b9e;font-size:13px;border-left:1px solid #cfe3fb;white-space:nowrap}.summary-item:first-child{border-left:0}.summary-item strong{font-size:16px;color:#1e67c6}.summary-total i{font-size:18px;color:#2387f1}.summary-level strong{font-weight:700}.summary-level .level-low{color:#16a34a}.summary-level .level-medium{color:#e18a00}.summary-level .level-high{color:#f04438}.impact-detail-section{margin-top:16px;border:1px solid #e6ebf2;border-radius:4px;overflow:hidden}.impact-detail-table{width:100%;border-collapse:collapse;table-layout:fixed;font-size:12px;color:#334155}.impact-detail-table th{height:38px;background:#f8fafc;color:#334155;font-weight:600}.impact-detail-table td{height:42px;background:#fff}.impact-detail-table th,.impact-detail-table td{padding:0 8px;border-right:1px solid #e6ebf2;border-bottom:1px solid #e6ebf2;text-align:left;word-break:break-word}.impact-detail-table tr:last-child td{border-bottom:0}.impact-detail-table th:last-child,.impact-detail-table td:last-child{border-right:0}.impact-detail-table th:nth-child(1),.impact-detail-table td:nth-child(1){width:38px;text-align:center}.impact-detail-table th:nth-child(2),.impact-detail-table td:nth-child(2){width:96px}.impact-detail-table th:nth-child(3),.impact-detail-table td:nth-child(3){width:76px}.impact-detail-table th:nth-child(4),.impact-detail-table td:nth-child(4){width:120px}.impact-detail-table th:nth-child(6),.impact-detail-table td:nth-child(6){width:110px}.approval-section{margin-top:15px}.approval-section h3{margin:0 0 9px;font-size:13px;color:#172033}.approval-cards{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.approval-card{min-height:61px;padding:10px 11px;border:1px solid #e5e7eb;border-radius:4px;background:#fafafa}.approval-card.business{border-color:#cfe4ff;background:#f4f9ff}.approval-card.finance{border-color:#e7e9ef;background:#fafafa}.approval-card.fulfillment{border-color:#e3d7ff;background:#fbf9ff}.approval-card-head{display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:700;color:#334155}.approval-card.business .approval-card-head i{color:#2f80ed}.approval-card.finance .approval-card-head i{color:#f59e0b}.approval-card.fulfillment .approval-card-head i{color:#805ad5}.approval-card-head i{margin-right:5px}.approval-card p{margin:7px 0 0;color:#64748b;font-size:11px;line-height:1.45}.effect-note{display:flex;align-items:center;gap:7px;margin-top:15px;padding:9px 10px;border:1px solid #f8cd8a;border-radius:4px;background:#fff8ed;color:#e77900;font-size:12px}.effect-note i{font-size:17px}.effect-note.immediate{border-color:#bae8c8;background:#f0fff4;color:#159447}.reason-section{margin-top:16px}.reason-section label{display:block;margin:0 0 7px;color:#334155;font-size:13px;font-weight:600}.reason-section label.required::before{content:'*';margin-right:4px;color:#f04438}.reason-section .el-textarea__inner{min-height:58px!important;border-color:#dfe5ec;resize:none;font-size:12px}.impact-dialog-footer{display:flex;justify-content:flex-end;gap:12px;padding:14px 18px;border-top:1px solid #eef1f5;background:#fff}.impact-dialog-footer .el-button{min-width:91px;height:34px}.impact-dialog-footer .el-button--success{background:#00984f;border-color:#00984f}
.order-edit-impact-dialog .impact-submit-error{width:auto;margin:0 18px 12px;flex-shrink:0}
@media(max-width:900px){.order-edit-impact-dialog .el-dialog{width:calc(100vw - 20px);margin-top:10px!important}.impact-dialog-shell{max-height:calc(100vh - 20px)}.impact-summary{height:auto;grid-template-columns:repeat(3,1fr)}.summary-item{min-height:42px;border-top:1px solid #cfe3fb}.summary-item:nth-child(-n+3){border-top:0}.summary-item:nth-child(4){border-left:0}.approval-cards{grid-template-columns:1fr}.impact-detail-section{overflow-x:auto}.impact-detail-table{min-width:760px}.impact-dialog-footer{position:sticky;bottom:0}}
</style>
