<template>
  <el-alert v-if="freezes.total" class="stocktake-freeze-alert" :title="title" :type="freezes.overdue ? 'error' : 'warning'" show-icon :closable="false">
    <div v-for="freeze in freezes.data" :key="freeze.id" class="stocktake-freeze-row">
      <span>{{ freeze.warehouse && freeze.warehouse.warehouse_name }}（{{ freeze.location ? freeze.location.location_name : '整仓' }}）正在盘点</span>
      <el-button v-if="canOpen" type="text" size="mini" @click="open(freeze)">{{ freeze.stocktake_no }}</el-button>
      <span v-else>{{ freeze.stocktake_no }}</span>
      <span :class="{ overdue: freeze.overdue }">，已持续 {{ freeze.days }} 天<template v-if="freeze.created_by_name">，发起人 {{ freeze.created_by_name }}</template>，期间不能出入库</span>
    </div>
    <el-pagination v-if="freezes.total > 10" small layout="prev, pager, next" :page-size="10" :total="freezes.total" :current-page="freezes.current_page" @current-change="load" />
  </el-alert>
</template>

<script>
import { listActiveStocktakes } from '@/api/erp/inventory'

// 盘点冻结提示：盘点中的仓库/库位暂停一切出入库，超过 3 天未完成改红色提醒。
export default {
  name: 'StocktakeFreezeAlert',
  props: {
    warehouseId: { type: [Number, String], default: null },
    // 由父页面提供数据时（如库存余额接口已带回），不再单独请求。
    value: { type: Object, default: null }
  },
  data() {
    return { own: { data: [], total: 0, current_page: 1, overdue: false } }
  },
  computed: {
    freezes() { return this.value || this.own },
    canOpen() { return this.$can('inventory.stocktake.view') },
    title() { return this.freezes.overdue ? '有盘点已持续超过 3 天仍未完成，以下范围一直暂停出入库，请尽快完成或取消盘点' : '库存正在盘点，以下范围暂停出入库，完成或取消盘点后解除冻结' }
  },
  watch: {
    warehouseId() { if (!this.value) this.load(1) }
  },
  created() {
    if (!this.value) this.load(1)
  },
  methods: {
    async load(page = 1) {
      if (this.value) return this.$emit('page-change', page)
      try {
        const { data } = await listActiveStocktakes({ page, warehouse_id: this.warehouseId || undefined })
        this.own = data
      } catch (e) {
        // 提示条加载失败不影响主业务页面。
        this.own = { data: [], total: 0, current_page: 1, overdue: false }
      }
    },
    open(freeze) {
      this.$router.push({ path: '/inventory/stocktakes', query: { id: freeze.id } })
    }
  }
}
</script>

<style scoped>
.stocktake-freeze-alert {
  margin-bottom: 12px;
}
.stocktake-freeze-row {
  line-height: 24px;
}
.stocktake-freeze-row .overdue {
  color: #f56c6c;
  font-weight: 600;
}
</style>
