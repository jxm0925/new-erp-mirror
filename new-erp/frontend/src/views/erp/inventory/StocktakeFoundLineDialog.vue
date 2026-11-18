<template>
  <el-dialog title="补录账外物料" :visible="true" width="min(520px, 96vw)" append-to-body
    :before-close="close" :close-on-click-modal="false">
    <el-form size="small" label-width="90px" @submit.native.prevent="save">
      <el-form-item label="盘点库位" required>
        <span v-if="stocktake.location_id">{{ stocktake.location.location_code }} {{ stocktake.location.location_name }}</span>
        <paged-record-select v-else v-model="form.location_id" :request="getWarehouseLocators"
          :params="{ mode: 'location', warehouse_id: stocktake.warehouse_id }" title="选择盘点库位" />
      </el-form-item>
      <el-form-item label="物料" required>
        <paged-record-select v-model="form.item_id" entity="items" :request="items"
          :category-type="$can('item_category.view') ? 'item' : ''" :columns="itemColumns"
          title="选择账外物料" placeholder="按物料编码、名称或规格查找" @select="selectItem" />
      </el-form-item>
      <el-form-item label="批次号" required>
        <el-input v-model.trim="form.batch_no" maxlength="80" placeholder="现场盘到的批次号" />
      </el-form-item>
      <el-form-item v-if="showCost" label="盘盈单价">
        <erp-money-input v-model.trim="form.unit_cost" inputmode="decimal" placeholder="留空使用可追溯成本" />
      </el-form-item>
      <el-form-item label="发现原因">
        <el-input v-model.trim="form.remark" type="textarea" :rows="2" maxlength="500" />
      </el-form-item>
    </el-form>
    <span slot="footer">
      <el-button size="small" :disabled="saving" @click="close">取消</el-button>
      <el-button size="small" type="success" :loading="saving" @click="save">添加盘点行</el-button>
    </span>
  </el-dialog>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { addStocktakeLine, listStocktakeItems } from '@/api/erp/inventory'
import { getWarehouseLocators } from '@/api/erp/warehouse'

export default {
  components: { PagedRecordSelect },
  props: { stocktake: { type: Object, required: true } },
  data() {
    return {
      saving: false, item: null,
      form: { location_id: this.stocktake.location_id || null, item_id: null, batch_no: '', unit_cost: '', remark: '' },
      itemColumns: [{ prop: 'item_code', label: '编码' }, { prop: 'item_name', label: '名称' }, { prop: 'spec', label: '规格' }]
    }
  },
  computed: {
    showCost() { return this.item && this.item.material_management_mode !== 'physical' && this.$can('inventory.amount.view') }
  },
  methods: {
    getWarehouseLocators,
    items(params) { return listStocktakeItems(this.stocktake.id, params) },
    selectItem(item) { this.item = item; this.form.unit_cost = '' },
    close() { if (!this.saving) this.$emit('close') },
    async save() {
      if (this.saving) return
      if (!this.form.location_id || !this.form.item_id || !this.form.batch_no) return this.$message.warning('请选择库位和物料，并填写批次号')
      if (this.showCost && this.form.unit_cost && (!/^\d{1,10}(\.\d{1,8})?$/.test(this.form.unit_cost) || Number(this.form.unit_cost) <= 0)) return this.$message.warning('盘盈单价必须大于零，或留空使用可追溯成本')
      this.saving = true
      try {
        const { data } = await addStocktakeLine(this.stocktake.id, {
          ...this.form, expected_version: this.stocktake.business_version,
          unit_cost: this.showCost && this.form.unit_cost ? this.form.unit_cost : undefined
        })
        this.$emit('added', data.data)
      } catch (error) {
        this.$message.error(error.userMessage || '补录失败，请核对后重试')
      } finally {
        this.saving = false
      }
    }
  }
}
</script>
