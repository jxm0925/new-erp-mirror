<template>
  <el-table :data="rows" border size="small" empty-text="尚未选择待核销业务，可保留为预收或预付款">
    <el-table-column label="来源类型" min-width="125"><template slot-scope="{ row }">{{ sourceLabel(row.source_business_type) }}</template></el-table-column>
    <el-table-column label="业务单号" min-width="145"><template slot-scope="{ row }">{{ row.source_document_no || row.source_document_id }}<small v-if="row.source_error" class="source-error">{{ row.source_error }}</small></template></el-table-column>
    <el-table-column label="业务金额" min-width="105" align="right"><template slot-scope="{ row }">{{ row.source_amount == null ? '—' : money(row.source_amount) }}</template></el-table-column>
    <el-table-column label="已结算" min-width="100" align="right"><template slot-scope="{ row }">{{ row.source_allocated_amount == null ? '—' : money(row.source_allocated_amount) }}</template></el-table-column>
    <el-table-column label="待结算" min-width="100" align="right"><template slot-scope="{ row }">{{ row.source_remaining_amount == null ? '—' : money(row.source_remaining_amount) }}</template></el-table-column>
    <el-table-column label="本次核销" min-width="130"><template slot-scope="{ row }"><el-input v-model.trim="row.allocated_amount" :disabled="!editable" size="small" /></template></el-table-column>
    <el-table-column v-if="editable" label="操作" width="68"><template slot-scope="{ $index }"><el-button type="text" class="danger" @click="$emit('remove', $index)">移除</el-button></template></el-table-column>
  </el-table>
</template>
<script>
import { money, sourceLabel } from '../../utils/financeCashAllocation'
export default {
  props: { rows: { type: Array, default: () => [] }, editable: Boolean },
  methods: { money, sourceLabel },
}
</script>
<style scoped>
.source-error {
  display: block;
  line-height: 1.5;
  color: #b45309;
  word-break: break-word;
}
.danger {
  color: #ef4444;
}
</style>
