<template>
  <section class="base-archives-page">
    <div class="archives-workspace">
      <!-- 页面全局头部：图标、标题、当前模块与主要操作 -->
      <header class="page-head">
        <div class="head-left">
          <span class="head-icon"><i class="el-icon-files" /></span>
          <div class="head-title-wrap">
            <div class="title-row">
              <h1 class="page-title">基础档案</h1>
              <el-tag size="small" type="success" effect="plain" class="head-tag">
                当前模块：{{ currentTabName }} ({{ currentTabCount }})
              </el-tag>
            </div>
          </div>
        </div>
        <div class="head-actions">
          <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="refreshActiveTab">刷新</el-button>
          <el-button
            v-if="canCreateCurrent"
            size="small"
            type="success"
            icon="el-icon-plus"
            class="btn-theme-create"
            @click="createInActiveTab"
          >
            {{ currentCreateBtnText }}
          </el-button>
        </div>
      </header>

      <!-- 全局统一页面提示条 -->
      <div class="erp-page-tip">
        <i class="el-icon-info" />
        <span>统一维护系统底层公共基础业务字典与标准档案。涵盖计量单位、商品分类、付款结算方式及全渠道成交平台，为商品规格、物料管理、采购结算、销售履约及财务凭证流转提供标准化映射支撑。</span>
      </div>

      <!-- 模块选项卡容器 -->
      <div class="tabs-card">
        <el-tabs v-model="activeTab" class="archive-tabs" @tab-click="visit">
          <el-tab-pane name="units">
            <span slot="label" class="custom-tab-item">
              <i class="el-icon-c-scale-to-original" />
              <span>单位管理</span>
              <span class="tab-badge code-mono">{{ counts.units }}</span>
            </span>
            <div class="tab-pane-content">
              <unit-list
                v-if="visited.units"
                ref="unitList"
                embedded
                :active="activeTab === 'units'"
                @count-updated="c => updateCount('units', c)"
              />
            </div>
          </el-tab-pane>

          <el-tab-pane name="categories">
            <span slot="label" class="custom-tab-item">
              <i class="el-icon-price-tag" />
              <span>商品分类</span>
              <span class="tab-badge code-mono">{{ counts.categories }}</span>
            </span>
            <div class="tab-pane-content">
              <archive-dictionary
                v-if="visited.categories"
                ref="categoryList"
                kind="categories"
                :active="activeTab === 'categories'"
                @count-updated="c => updateCount('categories', c)"
              />
            </div>
          </el-tab-pane>

          <el-tab-pane name="payments">
            <span slot="label" class="custom-tab-item">
              <i class="el-icon-bank-card" />
              <span>付款方式</span>
              <span class="tab-badge code-mono">{{ counts.payments }}</span>
            </span>
            <div class="tab-pane-content">
              <archive-dictionary
                v-if="visited.payments"
                ref="paymentList"
                kind="payments"
                :active="activeTab === 'payments'"
                @count-updated="c => updateCount('payments', c)"
              />
            </div>
          </el-tab-pane>

          <el-tab-pane name="platforms">
            <span slot="label" class="custom-tab-item">
              <i class="el-icon-shopping-cart-2" />
              <span>成交平台</span>
              <span class="tab-badge code-mono">{{ counts.platforms }}</span>
            </span>
            <div class="tab-pane-content">
              <archive-dictionary
                v-if="visited.platforms"
                ref="platformList"
                kind="platforms"
                :active="activeTab === 'platforms'"
                @count-updated="c => updateCount('platforms', c)"
              />
            </div>
          </el-tab-pane>
        </el-tabs>
      </div>
    </div>
  </section>
</template>

<script>
import UnitList from './UnitList.vue'
import ArchiveDictionary from './ArchiveDictionary.vue'
import { listEntity } from '../../../api/erp/master'
import { listArchives } from '../../../api/erp/base-archives'

// 模块级缓存，杜绝切换页签与跨次访问时的微标闪烁与重渲染抖动
const countsCache = {
  units: 9,
  categories: 0,
  payments: 6,
  platforms: 1
}

export default {
  name: 'BaseArchives',
  components: { UnitList, ArchiveDictionary },
  data () {
    return {
      activeTab: 'units',
      visited: { units: true },
      counts: { ...countsCache }
    }
  },
  computed: {
    currentTabName () {
      const map = {
        units: '单位管理',
        categories: '商品分类',
        payments: '付款方式',
        platforms: '成交平台'
      }
      return map[this.activeTab] || '基础档案'
    },
    currentTabCount () {
      const c = this.counts[this.activeTab]
      return `${c !== null && c !== undefined ? c : 0} 项`
    },
    currentCreateBtnText () {
      const map = {
        units: '新增单位',
        categories: '新增分类',
        payments: '新增付款方式',
        platforms: '新增平台'
      }
      return map[this.activeTab] || '新增档案'
    },
    canCreateCurrent () {
      if (this.activeTab === 'units') return true
      if (this.activeTab === 'categories') return this.$can('master.base_archive')
      if (this.activeTab === 'payments') return this.$can('finance.payment_method.manage')
      if (this.activeTab === 'platforms') return this.$can('master.base_archive')
      return true
    }
  },
  created () {
    const queryTab = this.$route.query.tab
    if (queryTab && ['units', 'categories', 'payments', 'platforms'].includes(queryTab)) {
      this.activeTab = queryTab
      this.$set(this.visited, queryTab, true)
    }
    this.prefetchCounts()
  },
  methods: {
    // 纯组件内状态切换，不触发外部路由变更，避免 App.vue 中 router-view key 重建整个页面组件
    visit (tab) {
      this.$set(this.visited, tab.name, true)
    },
    updateCount (tab, count) {
      const num = Number(count || 0)
      this.$set(this.counts, tab, num)
      countsCache[tab] = num
    },
    async prefetchCounts () {
      try {
        const [uRes, cRes, pRes, plRes] = await Promise.allSettled([
          listEntity('units', { per_page: 1 }),
          listArchives('categories', { per_page: 1 }),
          this.$can('finance.payment_method.view') || this.$can('finance.payment_method.manage')
            ? listArchives('payments', { per_page: 1 })
            : Promise.resolve({ data: { total: 0 } }),
          listArchives('platforms', { per_page: 1 })
        ])
        if (uRes.status === 'fulfilled' && uRes.value && uRes.value.data) {
          this.updateCount('units', Number(uRes.value.data.total || 0))
        }
        if (cRes.status === 'fulfilled' && cRes.value && cRes.value.data) {
          this.updateCount('categories', Number(cRes.value.data.total || 0))
        }
        if (pRes.status === 'fulfilled' && pRes.value && pRes.value.data) {
          this.updateCount('payments', Number(pRes.value.data.total || 0))
        }
        if (plRes.status === 'fulfilled' && plRes.value && plRes.value.data) {
          this.updateCount('platforms', Number(plRes.value.data.total || 0))
        }
      } catch (_) {}
    },
    getRefComp (ref) {
      return Array.isArray(ref) ? ref[0] : ref
    },
    refreshActiveTab () {
      if (this.activeTab === 'units') {
        const comp = this.getRefComp(this.$refs.unitList)
        if (comp && comp.load) comp.load()
      } else if (this.activeTab === 'categories') {
        const comp = this.getRefComp(this.$refs.categoryList)
        if (comp && comp.load) comp.load()
      } else if (this.activeTab === 'payments') {
        const comp = this.getRefComp(this.$refs.paymentList)
        if (comp && comp.load) comp.load()
      } else if (this.activeTab === 'platforms') {
        const comp = this.getRefComp(this.$refs.platformList)
        if (comp && comp.load) comp.load()
      }
    },
    createInActiveTab () {
      if (this.activeTab === 'units') {
        const comp = this.getRefComp(this.$refs.unitList)
        if (comp && comp.openCreate) comp.openCreate()
      } else if (this.activeTab === 'categories') {
        const comp = this.getRefComp(this.$refs.categoryList)
        if (comp && comp.openCreate) comp.openCreate()
      } else if (this.activeTab === 'payments') {
        const comp = this.getRefComp(this.$refs.paymentList)
        if (comp && comp.openCreate) comp.openCreate()
      } else if (this.activeTab === 'platforms') {
        const comp = this.getRefComp(this.$refs.platformList)
        if (comp && comp.openCreate) comp.openCreate()
      }
    }
  }
}
</script>

<style scoped>
.base-archives-page {
  min-width: 0;
  width: 100%;
  color: #1f2937;
  padding: 16px 20px 24px;
  box-sizing: border-box;
}

.archives-workspace {
  width: 100%;
  min-width: 0;
}

/* 页面头部 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 16px 20px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  margin-bottom: 14px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
}

.head-tag {
  border-radius: 4px;
  font-weight: 500;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-refresh:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 选项卡卡片容器 */
.tabs-card {
  min-width: 0;
}

.archive-tabs {
  min-width: 0;
}

.archive-tabs ::v-deep .el-tabs__header {
  margin: 0 0 14px;
  background: #ffffff;
  padding: 6px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
}

.archive-tabs ::v-deep .el-tabs__nav-wrap::after {
  display: none;
}

.archive-tabs ::v-deep .el-tabs__item {
  height: 40px;
  line-height: 40px;
  padding: 0 18px !important;
  font-size: 14px;
  font-weight: 500;
  color: #475569;
  border-radius: 6px;
  transition: color 0.2s cubic-bezier(0.4, 0, 0.2, 1);
}

.archive-tabs ::v-deep .el-tabs__item:hover {
  color: #008b4b;
  background: #f0fdf4;
}

.archive-tabs ::v-deep .el-tabs__item.is-active {
  color: #008b4b;
  font-weight: 600;
}

.archive-tabs ::v-deep .el-tabs__active-bar {
  background: #008b4b;
  height: 3px;
  border-radius: 2px;
  transition: transform 0.22s cubic-bezier(0.4, 0, 0.2, 1), width 0.22s cubic-bezier(0.4, 0, 0.2, 1);
}

.archive-tabs ::v-deep .el-tabs__content {
  overflow: visible;
}

.custom-tab-item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  user-select: none;
}

.custom-tab-item i {
  font-size: 15px;
}

.tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 22px;
  height: 18px;
  line-height: 18px;
  padding: 0 5px;
  box-sizing: border-box;
  background: #f1f5f9;
  border-radius: 9px;
  font-size: 11px;
  font-weight: 600;
  color: #64748b;
  margin-left: 2px;
  transition: background-color 0.2s, color 0.2s;
}

.archive-tabs ::v-deep .el-tabs__item.is-active .tab-badge {
  background: #dcfce7;
  color: #15803d;
}

.code-mono {
  font-family: SFMono-Regular, Consolas, "Liberation Mono", Menlo, Courier, monospace;
  font-variant-numeric: tabular-nums;
}

.tab-pane-content {
  min-width: 0;
}

/* 响应式断点适配 */
@media (max-width: 900px) {
  .base-archives-page {
    padding: 12px;
  }

  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }

  .head-actions {
    width: 100%;
    justify-content: flex-end;
  }
}

@media (max-width: 600px) {
  .base-archives-page {
    padding: 8px;
  }

  .archive-tabs ::v-deep .el-tabs__item {
    padding: 0 10px !important;
    font-size: 13px;
  }

  .title-row {
    flex-wrap: wrap;
  }
}
</style>
