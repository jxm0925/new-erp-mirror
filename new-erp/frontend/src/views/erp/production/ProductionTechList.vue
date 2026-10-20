<template>
  <section class="mfg-page" v-loading="loading">
    <div class="mfg-hero">
      <div class="mfg-crumb">生产管理 / 定制技术</div>
      <div class="mfg-hrow">
        <div>
          <div class="mfg-title">定制技术</div>
          <div class="mfg-hero-sub">定制产品的工单先停在“计划”；技术上传设计图、确认定制 BOM 后下发车间，不用审核</div>
        </div>
      </div>
    </div>
    <div class="mfg-body">
      <div class="card">
        <div class="tabs">
          <span v-for="tab in tabs" :key="tab.value" :class="{ on: filters.status === tab.value }" @click="pickTab(tab.value)">{{ tab.label }}<em>{{ tabCount(tab.value) }}</em></span>
        </div>
        <div class="flt">
          <el-input v-model="filters.keyword" class="w" size="small" clearable placeholder="搜工单号 / 订单号 / 客户 / 产品" prefix-icon="el-icon-search" @keyup.enter.native="search" @clear="search" />
        </div>

        <div class="desktop-only">
          <el-table :data="rows" class="tech-table" empty-text="暂无定制工单" @row-click="open">
            <el-table-column label="工单" min-width="200">
              <template slot-scope="s">
                <div class="mono no">{{ s.row.work_order_no }}</div>
                <div class="pn">{{ productName(s.row) }} <b>× {{ qty(s.row.planned_qty) }} {{ s.row.unit_name || '台' }}</b></div>
              </template>
            </el-table-column>
            <el-table-column label="订单 / 客户" min-width="140">
              <template slot-scope="s"><div>{{ s.row.sales_order_no || '-' }}</div><div class="sub">{{ s.row.customer_name }}</div></template>
            </el-table-column>
            <el-table-column label="客户要什么" min-width="240">
              <template slot-scope="s"><div class="desc">{{ s.row.custom_description || '销售没填定制说明' }}</div></template>
            </el-table-column>
            <el-table-column label="进度" min-width="150">
              <template slot-scope="s">
                <div :class="s.row.drawing_count ? 'ok' : 'warn'">{{ s.row.drawing_count ? '设计图 ' + s.row.drawing_count + ' 份' : '还没传图' }}</div>
                <div :class="s.row.bom_confirmed ? 'ok' : 'amb'">{{ s.row.bom_confirmed ? '定制 BOM 已确认' : 'BOM 没确认' }}</div>
              </template>
            </el-table-column>
            <el-table-column label="状态" width="130">
              <template slot-scope="s">
                <span :class="['chip', s.row.status === 'pending' ? 'c-o' : 'c-g']">{{ s.row.status_label }}</span>
                <span v-if="s.row.is_urgent" class="chip c-r">加急</span>
                <div v-if="s.row.status === 'released'" class="sub">工单{{ s.row.work_order_status_label }}</div>
              </template>
            </el-table-column>
            <el-table-column label="交期" width="90"><template slot-scope="s">{{ shortDate(s.row.required_date) }}</template></el-table-column>
            <el-table-column label="技术负责人" width="100"><template slot-scope="s">{{ s.row.tech_owner_name || '-' }}</template></el-table-column>
            <el-table-column label="" width="60"><template slot-scope="s"><span class="lk" @click.stop="open(s.row)">{{ s.row.status === 'pending' ? '处理' : '查看' }}</span></template></el-table-column>
          </el-table>
        </div>

        <div class="mobile-only">
          <div v-if="!rows.length" class="empty">暂无定制工单</div>
          <div v-for="row in rows" :key="row.work_order_id" class="m-row" @click="open(row)">
            <div class="m-head"><span class="mono no">{{ row.work_order_no }}</span><span><span :class="['chip', row.status === 'pending' ? 'c-o' : 'c-g']">{{ row.status_label }}</span> <span v-if="row.is_urgent" class="chip c-r">加急</span></span></div>
            <div class="pn">{{ productName(row) }} <b>× {{ qty(row.planned_qty) }} {{ row.unit_name || '台' }}</b></div>
            <div class="sub">{{ row.sales_order_no || '-' }} · {{ row.customer_name || '' }}</div>
            <div class="desc">{{ row.custom_description || '销售没填定制说明' }}</div>
            <div class="m-foot">
              <span>交期 {{ shortDate(row.required_date) }} · {{ row.tech_owner_name || '未定技术' }}</span>
              <span><span :class="row.drawing_count ? 'ok' : 'warn'">{{ row.drawing_count ? '图 ' + row.drawing_count : '没图' }}</span> · <span :class="row.bom_confirmed ? 'ok' : 'amb'">{{ row.bom_confirmed ? 'BOM 已确认' : 'BOM 没确认' }}</span></span>
            </div>
          </div>
        </div>

        <div class="pg">
          <span>共 {{ total }} 条</span>
          <el-pagination background small layout="prev, pager, next" :current-page="page" :page-size="10" :total="total" @current-change="v => { page = v; load() }" />
        </div>
      </div>
    </div>
  </section>
</template>

<script>
import { listProductionTech } from '../../../api/erp/production'
import { qty, shortDate, errorText } from './mfg-helpers'

export default {
  name: 'ProductionTechList',
  data: () => ({
    loading: false, rows: [], total: 0, page: 1, summary: {},
    filters: { status: 'pending', keyword: '' },
    tabs: [{ value: 'pending', label: '待技术处理' }, { value: 'released', label: '已下发车间' }, { value: '', label: '全部' }]
  }),
  created() {
    if (this.$route.query.status !== undefined) this.filters.status = String(this.$route.query.status)
    this.load()
  },
  methods: {
    qty, shortDate,
    async load() {
      this.loading = true
      try {
        const params = { page: this.page, per_page: 10, status: this.filters.status || undefined, keyword: this.filters.keyword || undefined }
        const { data } = await listProductionTech(params)
        this.rows = data.data || []
        this.total = data.total || 0
        this.summary = data.summary || {}
      } catch (e) {
        this.$message.error(errorText(e, '定制工单加载失败'))
      } finally {
        this.loading = false
      }
    },
    search() { this.page = 1; this.load() },
    pickTab(status) { this.filters.status = status; this.search() },
    tabCount(status) {
      const s = this.summary || {}
      return status ? (s[status] || 0) : (s.pending || 0) + (s.released || 0)
    },
    open(row) { this.$router.push(`/production/tech/${row.work_order_id}`) },
    productName(row) { return [row.product_name, row.sku_code].filter(Boolean).join(' ') }
  }
}
</script>

<style scoped src="./mfg-v2.css"></style>
<style scoped>
.tabs { display: flex; gap: 4px; border-bottom: 1px solid #e3e8f0; margin-bottom: 12px; overflow-x: auto; }
.tabs span { padding: 8px 14px; color: #5b6780; border-bottom: 2px solid transparent; cursor: pointer; white-space: nowrap; }
.tabs span.on { color: #1f6feb; border-color: #1f6feb; font-weight: 600; }
.tabs em { font-style: normal; font-size: 11px; background: #eef1f5; border-radius: 8px; padding: 0 6px; margin-left: 4px; }
.flt { display: flex; gap: 8px; margin-bottom: 12px; flex-wrap: wrap; align-items: center; }
.flt .w { width: 300px; }
.no { font-size: 11px; color: #8a95a8; }
.pn { font-weight: 700; font-size: 13px; margin-top: 2px; }
.desc { color: #5b6780; font-size: 12px; line-height: 1.6; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.tech-table ::v-deep th { color: #8a95a8; font-weight: 500; font-size: 12px; background: #fff; }
.tech-table ::v-deep td { font-size: 12px; cursor: pointer; }
.pg { display: flex; justify-content: space-between; align-items: center; margin-top: 12px; color: #8a95a8; font-size: 12px; flex-wrap: wrap; gap: 8px; }
.mobile-only { display: none; }
.m-row { border: 1px solid #e3e8f0; border-radius: 10px; padding: 12px; margin-bottom: 10px; display: grid; gap: 6px; }
.m-head, .m-foot { display: flex; justify-content: space-between; gap: 8px; font-size: 12px; flex-wrap: wrap; }
.m-foot span:first-child { color: #5b6780; }
@media (max-width: 767px) {
  .desktop-only { display: none; }
  .mobile-only { display: block; }
  .flt .w { width: 100%; }
}
</style>
