<template>
  <div v-if="fields.length" class="drawing-source-info">
    <div v-for="field in fields" :key="field[0] + field[1]" class="drawing-source-field" :class="{ 'is-person': ['客户', '负责销售'].includes(field[0]) }"><span>{{ field[0] }}：</span><el-button v-if="field[0] === '商机编号' && opportunityId" type="text" size="mini" class="drawing-source-link" :loading="openingOpportunity" @click="openOpportunity">{{ field[1] }}</el-button><el-button v-else-if="field[0] === '绘图需求编号' && requestId" type="text" size="mini" class="drawing-source-link" :loading="openingRequest" title="查看绘图需求与交图版本" @click="openRequest">{{ field[1] }}</el-button><b v-else>{{ field[1] }}</b></div>
    <component :is="opportunityComponent" v-if="opportunityComponent" ref="opportunity" @changed="$emit('changed')" />
    <component :is="requestComponent" v-if="requestComponent" ref="request" dialog-only @changed="$emit('changed')" />
  </div>
</template>

<script>
export default {
  name: 'TechnicalDrawingSourceInfo',
  props: { source: { type: Object, default: () => ({}) } },
  data: () => ({ opportunityComponent: null, openingOpportunity: false, requestComponent: null, openingRequest: false }),
  computed: {
    opportunityId() { return Number((this.source.source_context || {}).opportunity_id) || null },
    requestId() { return Number((this.source.source_context || {}).request_id) || null },
    fields() {
      const source = this.source
      const context = source.source_context || {}
      const type = context.source_type || source.source_type
      const kinds = { opportunity: '商机', drawing_request: '绘图需求', lead: '线索', bom: 'BOM', work_order: '工单' }
      const kind = kinds[type] || source.source_label
      const nameLabels = { opportunity: '商机名称', drawing_request: '绘图需求', lead: '线索名称', bom: 'BOM名称' }
      // 直接来源沿用登记时编号和名称，关联业务补充定位信息，不覆盖冻结事实。
      const number = source.source_no || context.source_no
      const name = source.source_name || context.source_name
      const opportunityNo = type === 'opportunity' ? number || context.opportunity_no : context.opportunity_no
      const opportunityName = type === 'opportunity' ? name || context.opportunity_name : context.opportunity_name
      const hasOpportunity = !!(opportunityNo || opportunityName)
      const leadNo = !hasOpportunity ? type === 'lead' ? number || context.lead_no : context.lead_no : ''
      const leadName = !hasOpportunity ? type === 'lead' ? name || context.lead_name : context.lead_name : ''
      const linked = !!(hasOpportunity || leadNo || leadName)
      const people = [['客户', context.customer_name || source.customer_name], ['负责销售', context.sales_user_name || source.sales_user_name]]
      const fields = linked ? [['商机编号', opportunityNo], ['商机名称', opportunityName], ['线索编号', leadNo], ['线索名称', leadName], ...people] : [['来源', kind]]
      if (!['opportunity', 'lead'].includes(type)) {
        fields.push([kind ? `${kind}编号` : '来源编号', number || (type === 'drawing_request' ? context.request_no : '')])
        fields.push([nameLabels[type] || '来源名称', name || (type === 'drawing_request' ? context.request_name : '')])
      }
      if (!linked) fields.push(...people)
      return fields.filter(([, value]) => value !== null && value !== undefined && String(value).trim())
    }
  },
  methods: {
    async openOpportunity() {
      if (!this.opportunityId || this.openingOpportunity) return
      const id = this.opportunityId
      this.openingOpportunity = true
      try {
        // CRM详情也会打开图纸；只在点击后挂载，避免形成递归组件树。
        this.opportunityComponent = (await import('@/components/crm/CrmOpportunityDetailDialog.vue')).default
        await this.$nextTick()
        if (!this._isDestroyed && this.$refs.opportunity) this.$refs.opportunity.open(id)
      } catch (_) { this.$message.error('商机详情加载失败，请重试') }
      finally { this.openingOpportunity = false }
    },
    async openRequest() {
      if (!this.requestId || this.openingRequest) return
      const id = this.requestId
      this.openingRequest = true
      try {
        this.requestComponent = (await import('@/components/crm/CrmDrawingRequests.vue')).default
        await this.$nextTick()
        if (!this._isDestroyed && this.$refs.request) this.$refs.request.open(id)
      } catch (_) { this.$message.error('绘图需求加载失败，请重试') }
      finally { this.openingRequest = false }
    }
  }
}
</script>

<style scoped>
.drawing-source-info {
  display: flex;
  flex-wrap: wrap;
  min-width: 0;
  font-size: 13px;
  line-height: 1.6;
  overflow-wrap: anywhere;
  word-break: break-word;
}
.drawing-source-field {
  width: 100%;
  min-width: 0;
}
.drawing-source-field.is-person {
  width: auto;
  margin-right: 12px;
}
.drawing-source-info span {
  color: #64748b;
}
.drawing-source-info b {
  color: #334155;
  font-weight: 400;
}
.drawing-source-link {
  padding: 0;
  color: #008b4b;
  font-size: 13px;
  white-space: normal;
  text-align: left;
}
</style>
