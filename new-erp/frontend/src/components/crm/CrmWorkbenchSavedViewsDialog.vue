<template>
  <el-dialog class="crm-dialog" title="已保存视图" :visible.sync="visible" width="780px" top="8vh" append-to-body :close-on-click-modal="false" :before-close="close">
    <div class="saved-view-filters"><el-select v-model="resource" :disabled="selecting" size="small" popper-class="crm-select-popper" aria-label="业务中心" @change="search"><el-option v-for="item in resources" :key="item.key" :value="item.key" :label="item.label" /></el-select><el-input v-model.trim="keyword" :disabled="selecting" size="small" clearable placeholder="视图名称" @keyup.enter.native="search" /><el-button size="small" type="success" :disabled="selecting" @click="search">查询</el-button></div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-table v-loading="loading" :data="rows" size="small"><el-table-column prop="name" label="视图名称" min-width="180" /><el-table-column label="范围" min-width="120"><template slot-scope="{row}">{{ row.scope === 'personal' ? '仅自己' : row.scope_name }}</template></el-table-column><el-table-column prop="owner_name" label="创建人" min-width="110" /><el-table-column label="操作" width="90"><template slot-scope="{row}"><el-button type="text" size="mini" :disabled="selecting" @click="select(row)">打开视图</el-button></template></el-table-column></el-table>
    <div class="crm-pager"><el-pagination background layout="total, prev, pager, next" :disabled="selecting" :current-page.sync="page" :page-size="10" :total="total" @current-change="load" /></div><span slot="footer"><el-button size="small" :disabled="selecting" @click="close()">关闭</el-button></span>
  </el-dialog>
</template>
<script>
import { listCrmViews, getCrmView, rememberCrmView } from '@/api/erp/crmViews'
import { crmError } from '@/api/erp/crm'
export default {
  name: 'CrmWorkbenchSavedViewsDialog',
  props: { resources: { type: Array, required: true } },
  data: () => ({ visible: false, resource: '', keyword: '', page: 1, rows: [], total: 0, loading: false, selecting: false, error: '', sequence: 0 }),
  beforeDestroy() { this.sequence += 1 },
  methods: {
    open() { if (!this.resources.length) return; this.visible = true; if (!this.resources.some(item => item.key === this.resource)) this.resource = this.resources[0].key; this.search() },
    close(done) { if (this.selecting) return; this.sequence += 1; this.loading = false; this.visible = false; if (typeof done === 'function') done() },
    search() { if (this.selecting) return; this.page = 1; this.load() },
    async load() {
      if (this.selecting) return
      const sequence = ++this.sequence
      this.loading = true; this.error = ''
      try { const { data } = await listCrmViews({ resource: this.resource, keyword: this.keyword, page: this.page, per_page: 10 }); if (sequence !== this.sequence || !this.visible) return; this.rows = data.data || []; this.total = data.total || 0 }
      catch (error) { if (sequence === this.sequence && this.visible) { this.rows = []; this.total = 0; this.error = crmError(error, '视图列表加载失败') } }
      finally { if (sequence === this.sequence) this.loading = false }
    },
    async select(row) {
      if (this.selecting || this.loading) return
      const sequence = ++this.sequence; const resource = this.resource
      this.selecting = true; this.error = ''
      try {
        const { data } = await getCrmView(row.id)
        if (sequence !== this.sequence || !this.visible) return
        const view = data.data || data; const target = this.resources.find(item => item.key === view.resource)
        if (!target || view.resource !== resource) { this.error = '视图所属业务已变化，请刷新后重试'; return }
        rememberCrmView(view.resource, view.id)
        this.visible = false
        this.$router.push(target.path)
      } catch (error) { if (sequence === this.sequence && this.visible) this.error = crmError(error, '视图不可用，请刷新后重试') }
      finally { if (sequence === this.sequence) this.selecting = false }
    }
  }
}
</script>
<style scoped>
.saved-view-filters {
  display: flex;
  align-items: center;
  gap: 10px;
  margin-bottom: 14px;
  min-width: 0;
}
.saved-view-filters .el-select {
  width: 150px;
  flex-shrink: 0;
}
.saved-view-filters .el-input {
  flex: 1;
  min-width: 0;
}
@media (max-width: 600px) {
  .saved-view-filters {
    flex-wrap: wrap;
  }
  .saved-view-filters .el-select {
    width: 100%;
  }
}
</style>
