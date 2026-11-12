<template>
  <div>
    <crm-lead-detail-dialog v-if="can('crm.lead.view')" ref="lead" @changed="changed" />
    <crm-opportunity-detail-dialog v-if="can('crm.opportunity.view')" ref="opportunity" @changed="changed" />
    <customer-detail-dialog v-if="can('sales.customer')" ref="customer" @changed="changed" />
    <crm-followup-dialog ref="follow" @saved="changed" />
    <crm-lead-action-dialog ref="action" @changed="changed" />
    <crm-identity-review-dialog ref="review" @saved="changed" />
  </div>
</template>
<script>
import CrmLeadDetailDialog from '@/components/crm/CrmLeadDetailDialog.vue'
import CrmOpportunityDetailDialog from '@/components/crm/CrmOpportunityDetailDialog.vue'
import CrmFollowupDialog from '@/components/crm/CrmFollowupDialog.vue'
import CrmLeadActionDialog from '@/components/crm/CrmLeadActionDialog.vue'
import CrmIdentityReviewDialog from '@/components/crm/CrmIdentityReviewDialog.vue'
import { getCrmLead, crmError } from '@/api/erp/crm'
export default {
  name: 'DashboardActions',
  components: { CrmLeadDetailDialog, CrmOpportunityDetailDialog, CrmFollowupDialog, CrmLeadActionDialog, CrmIdentityReviewDialog, CustomerDetailDialog: () => import('@/components/sales/CustomerDetailDialog.vue') },
  data: () => ({ sequence: 0, acting: false }),
  beforeDestroy() { this.sequence += 1 },
  methods: {
    can(code) { return !this.$can || this.$can(code) },
    changed() { this.$emit('changed') },
    invalidate() { this.sequence += 1; this.acting = false },
    view(row) {
      this.invalidate()
      const id = row.target_id
      if (row.target_type === 'order' && this.can('sales_order.view')) this.$router.push(`/sales/orders/${id}/detail`)
      else if (this.$refs[row.target_type]) this.$refs[row.target_type].open(id)
    },
    async act(row) {
      const key = { followup: 'follow', receive: 'accept', verify: 'verify' }[row.action]
      if (!key || !(row.allowed || {})[key] || this.acting) return
      const sequence = ++this.sequence
      if (row.action === 'followup') this.$refs.follow.open({ subject_type: row.target_type, subject_id: row.target_id, customer_id: row.customer_id, customer_name: row.customer_name, opportunity_name: row.target_type === 'opportunity' ? row.title : '', lead_no: row.lead_no, contact_name: row.contact_name })
      else if (row.action === 'receive') this.$refs.action.open('accept', row.target_id)
      else {
        this.acting = true
        try {
          const { data } = await getCrmLead(row.target_id)
          if (sequence !== this.sequence) return
          const lead = data.data || data
          if (lead.review && lead.review.can_resolve) this.$refs.review.openReview(lead.review)
          else this.$message.warning('该线索状态或权限已变化，请刷新后重新选择')
        } catch (error) { if (sequence === this.sequence) this.$message.error(crmError(error,'线索加载失败')) }
        finally { if (sequence === this.sequence) this.acting = false }
      }
    }
  }
}
</script>
