<template>
  <el-dialog title="合并下料明细" :visible.sync="visible" width="960px" custom-class="production-material-dialog public-material-dialog" append-to-body :close-on-click-modal="false" @closed="sequence++">
    <template v-if="owner">
      <div class="pm-meta"><span>工单：{{ owner.work_order_no }}</span><span>原材料：{{ owner.item_name }} · {{ owner.spec || '—' }}</span><span>接收工序：{{ owner.target_operation_name }}</span></div>
      <el-alert v-if="error" :title="error" type="error" :closable="false" />
      <el-table :data="rows" border size="small" v-loading="loading" empty-text="暂无其他可合并下料明细">
        <el-table-column width="48"><template slot-scope="{row}"><el-checkbox :value="!!selected[row.id]" :disabled="Number(row.id) === Number(owner.id) || !compatible(row)" :aria-label="'合并下料明细 ' + row.id" @change="toggle(row,$event)" /></template></el-table-column>
        <el-table-column label="加工尺寸" min-width="180"><template slot-scope="{row}">{{ cuttingDimensions(row.cutting_requirement_dimensions) }}</template></el-table-column>
        <el-table-column label="待加工件数" min-width="110"><template slot-scope="{row}">{{ row.remaining_cut_piece_qty }} {{ row.cutting_unit_kind === 'root' ? '段' : '块' }}</template></el-table-column>
        <el-table-column label="理论用量" min-width="120"><template slot-scope="{row}">{{ row.remaining_to_prepare }} {{ row.unit_name }}</template></el-table-column>
      </el-table>
      <el-pagination class="pm-pagination" :pager-count="5" :current-page.sync="page" :page-size="10" :total="total" layout="total, prev, pager, next" @current-change="load" />
      <div class="pm-selected">已选 {{ chosen.length }} 条下料明细（翻页保留）<el-tag v-for="row in chosen" :key="row.id" :closable="Number(row.id) !== Number(owner.id)" @close="toggle(row,false)">{{ cuttingDimensions(row.cutting_requirement_dimensions) }} · {{ row.remaining_cut_piece_qty }} {{ row.cutting_unit_kind === 'root' ? '段' : '块' }}</el-tag></div>
    </template>
    <span slot="footer"><el-button size="small" @click="visible=false">取消</el-button><el-button size="small" type="success" :disabled="chosen.length < 2 || chosen.length > 100 || chosen.some(row => !compatible(row))" @click="confirm">合并明细</el-button></span>
  </el-dialog>
</template>

<script>
import { materialDemands } from '@/api/erp/production-materials'
import { cuttingDimensions, cuttingWholeStock, cuttingTotalLength } from '@/utils/production-cutting-allocation.mjs'

export default {
  data: () => ({ visible: false, owner: null, selected: {}, rows: [], page: 1, total: 0, loading: false, error: '', sequence: 0 }),
  computed: { chosen() { return Object.values(this.selected) } },
  methods: {
    cuttingDimensions,
    compatible(row) {
      return !!this.owner && !cuttingTotalLength(this.owner) && !cuttingTotalLength(row) && cuttingWholeStock(row) && Number(row.remaining_cut_piece_qty) > 0
        && ['work_order_id','production_target_type','production_target_id','component_item_id','base_unit_id','configuration_id'].every(key => String(row[key] ?? '') === String(this.owner[key] ?? ''))
    },
    open(owner, members = []) {
      const originalOwner=members.find(row=>Number(row.id)===Number(owner.id)) || owner
      this.sequence++; this.owner={...originalOwner}; this.selected={}; this.rows=[]; this.page=1; this.total=0; this.error=''; this.visible=true
      ;(members.length ? members : [owner]).forEach(row => this.$set(this.selected,row.id,{...row}))
      this.$set(this.selected,owner.id,{...originalOwner}); this.load()
    },
    toggle(row, selected) { if(Number(row.id)===Number(this.owner.id)) return; if(selected && this.compatible(row)) this.$set(this.selected,row.id,{...row}); else this.$delete(this.selected,row.id) },
    async load() {
      const sequence=++this.sequence; const owner=this.owner; this.loading=true; this.error=''
      try {
        const {data}=await materialDemands({work_order_id:owner.work_order_id,production_target_type:owner.production_target_type,production_target_id:owner.production_target_id,component_item_id:owner.component_item_id,page:this.page,per_page:10})
        if(sequence!==this.sequence || owner!==this.owner) return
        this.rows=data.data || []; this.total=data.total || 0
        this.rows.forEach(row => { if(this.selected[row.id]) this.$set(this.selected,row.id,{...row}) })
      } catch(e) { if(sequence===this.sequence) this.error=e.userMessage || '下料明细读取失败' }
      finally { if(sequence===this.sequence) this.loading=false }
    },
    confirm() { if(this.chosen.length<2 || this.chosen.length>100 || this.chosen.some(row=>!this.compatible(row))) return; this.$emit('confirm',{owner:this.owner,members:this.chosen.map(row=>({...row}))}); this.visible=false }
  }
}
</script>
