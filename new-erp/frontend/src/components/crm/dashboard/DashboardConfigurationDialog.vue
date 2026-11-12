<template>
  <el-dialog class="crm-dialog dashboard-configuration" title="配置经营看板" :visible.sync="visible" width="1100px" top="5vh" append-to-body :close-on-click-modal="false" :close-on-press-escape="!saving" :show-close="!saving" :before-close="close">
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <el-alert v-for="(warning,index) in warnings" :key="index" :title="warningText(warning)" type="warning" :closable="false" show-icon />
    <div class="dashboard-config-identity">
      <label>看板名称<el-input v-model="name" size="small" maxlength="40" :disabled="saving" /></label>
      <label>共享范围<el-select v-model="shareKey" size="small" :disabled="saving" popper-class="crm-select-popper"><el-option v-for="item in shareOptions" :key="item.key" :label="item.label" :value="item.key" /></el-select></label>
      <el-button size="small" icon="el-icon-folder-opened" :disabled="saving" @click="openPicker">恢复视图</el-button>
    </div>
    <div v-loading="saving" class="dashboard-config-columns">
      <nav class="dashboard-config-categories" aria-label="组件分类"><h3>组件分类</h3><button v-for="item in pages" :key="item.key" type="button" :class="{active:category === item.key}" @click="category = item.key; selectCategory(item.key)"><i :class="item.icon" />{{ item.label }}</button></nav>
      <section class="dashboard-config-library"><h3>组件库</h3><div class="dashboard-config-library-scroll"><section v-for="page in pages" :key="page.key"><h4>{{ page.label }}</h4><div v-for="definition in categoryComponents(page.key)" :key="definition.key" class="dashboard-config-library-item" :class="{selected:selectedKey === definition.key}" :draggable="!saving" @dragstart="dragKey = definition.key" @dragover.prevent @drop.prevent="dropBefore(definition.key)"><el-checkbox v-model="component(definition.key).enabled" :disabled="saving || !componentAvailable(definition.key)" :aria-label="definition.title" /><button type="button" @click="selectedKey = definition.key; category = definition.category">{{ component(definition.key).title }}</button></div></section></div></section>
      <section class="dashboard-config-settings"><h3>当前组件设置</h3><template v-if="selected && definition">
        <div class="dashboard-config-fields">
          <label>组件名称<el-input v-model="selected.title" size="mini" maxlength="40" :disabled="saving" /></label>
          <label>图表形式<el-input :value="definition.chart" size="mini" readonly /></label>
          <label>统计对象<el-input :value="statisticObject" size="mini" readonly /></label>
          <label>指标<el-select v-if="selectedKey === 'pipeline_stages'" v-model="configuration.settings.stage_mode" size="mini" :disabled="saving" popper-class="crm-select-popper"><el-option label="进行中商机数" value="count" /><el-option label="同币种预计金额" value="amount" /></el-select><el-input v-else :value="definition.basis" size="mini" readonly /></label>
          <label v-if="selectedKey === 'dimension_cross'">行维度<el-input value="销售人员" size="mini" readonly /></label>
          <label>{{ selectedKey === 'dimension_cross' ? '列维度' : '分析维度' }}<el-select v-if="isDimension" v-model="configuration.settings.dimension" size="mini" :disabled="saving" popper-class="crm-select-popper"><el-option v-for="item in dimensions" :key="item.value" :label="item.label" :value="item.value" :disabled="!dimensionAllowed(item.value)" /></el-select><el-select v-else-if="selectedKey === 'source_distribution'" v-model="configuration.settings.source" size="mini" :disabled="saving" popper-class="crm-select-popper"><el-option v-for="item in sources" :key="item.value" :label="item.label" :value="item.value" :disabled="item.value !== 'first_source' && !ability('leads')" /></el-select><el-input v-else :value="definition.dimension" size="mini" readonly /></label>
          <label>销售归属<el-input :value="definition.category === 'income' ? '订单原销售归属' : '客户 / 商机当前归属；订单原归属'" size="mini" readonly /></label>
          <label>时间口径<el-input :value="definition.date" size="mini" readonly /></label>
          <template v-if="isFunnel"><label>同批期间<el-date-picker v-model="cohortRange" type="daterange" :disabled="saving" size="mini" range-separator="至" value-format="yyyy-MM-dd" :clearable="false" popper-class="crm-date-popper" /></label><label>观察截至<el-date-picker v-model="configuration.settings.observed_on" type="date" :disabled="saving" size="mini" value-format="yyyy-MM-dd" :clearable="false" popper-class="crm-date-popper" /></label></template>
          <label v-if="selectedKey === 'pipeline_records'">阶段停留<el-select v-model="configuration.settings.stalled_days" size="mini" :disabled="saving" popper-class="crm-select-popper"><el-option v-for="day in [7,15,30,60,90]" :key="day" :label="day + '天'" :value="day" /></el-select></label>
          <label>筛选条件<el-popover placement="bottom-end" width="470" trigger="click" popper-class="dashboard-global-filter-popover"><div class="dashboard-config-global-fields"><label>日期<el-date-picker v-model="filterRange" type="daterange" :disabled="saving" size="small" value-format="yyyy-MM-dd" range-separator="至" :clearable="false" popper-class="crm-date-popper" /></label><label>部门<el-select v-model="configuration.filters.department_legacy_id" size="small" :disabled="saving" clearable placeholder="全部授权部门" popper-class="crm-select-popper" @change="departmentChanged"><el-option v-for="item in departments" :key="item.id" :label="item.name" :value="item.id" /></el-select></label><label>小组<el-select v-model="configuration.filters.group_legacy_id" size="small" :disabled="saving" clearable placeholder="全部" popper-class="crm-select-popper" @change="clearSales"><el-option v-for="item in groups" :key="item.id" :label="item.name" :value="item.id" /></el-select></label><label>销售<paged-record-select :disabled="saving" v-model="configuration.filters.sales_user_legacy_id" :request="salesRequest" :params="salesParams" :columns="[{prop:'name',label:'姓名'}]" :label="salesLabel" title="选择销售" placeholder="全部" @change="configuration.filters.sales_user_unassigned = false" /></label><label>业务线<el-select v-model="configuration.filters.business_line" size="small" :disabled="saving" clearable placeholder="全部" popper-class="crm-select-popper"><el-option v-for="item in dashboardMetadata.business_lines || []" :key="item" :label="item" :value="item" /></el-select></label><label>币种<el-select v-model="configuration.filters.currency" size="small" :disabled="saving" clearable placeholder="分币种" popper-class="crm-select-popper"><el-option v-for="item in dashboardMetadata.currencies || []" :key="item" :label="item" :value="item" /></el-select></label></div><el-button slot="reference" size="mini" class="dashboard-config-filter-button">继承全局筛选<i class="el-icon-arrow-down" /></el-button></el-popover></label>
        </div>
        <p v-if="isDimension" class="dashboard-config-setting-note">客户维度分布、交叉表和经营对比共用此分析维度。</p>
        <el-checkbox v-model="selected.drilldown" :disabled="saving">支持点击查看明细</el-checkbox>
        <div class="dashboard-config-preview"><strong>预览</strong><slot name="preview" :component-key="selectedKey" :configuration="configuration"><div class="dashboard-empty">当前条件下暂无可预览数据</div></slot></div>
        <div class="dashboard-config-fields dashboard-config-placement"><label>显示位置<el-select v-model="selected.position" size="small" :disabled="saving" popper-class="crm-select-popper"><el-option v-for="item in pages" :key="item.key" :label="item.label" :value="item.key" /></el-select></label><label v-if="!definition.metric">布局<el-select v-model="selected.width" size="small" :disabled="saving" popper-class="crm-select-popper"><el-option label="整行" value="full" /><el-option label="二分之一" value="half" /><el-option label="三分之一" value="third" /></el-select></label><label>排序<div class="dashboard-config-order"><i class="el-icon-rank" /><span>可拖拽调整组件顺序</span><el-button size="mini" icon="el-icon-top" aria-label="上移组件" :disabled="saving || !canMove(-1)" @click="move(-1)" /><el-button size="mini" icon="el-icon-bottom" aria-label="下移组件" :disabled="saving || !canMove(1)" @click="move(1)" /></div></label></div>
      </template></section>
    </div>
    <div slot="footer" class="dashboard-config-footer"><span><i class="el-icon-info" /> 共享仅共享布局，数据按查看人的权限重新计算。</span><div><el-button size="small" :disabled="saving" @click="restoreDefault">恢复默认</el-button><el-button size="small" :disabled="saving" @click="close">取消</el-button><el-button v-if="current && current.can_update" size="small" :disabled="saving" @click="save(true)">另存为</el-button><el-button type="success" size="small" :loading="saving" :disabled="!metadataReady" @click="save(false)">{{ current && current.can_update ? '保存看板' : current ? '另存为' : '保存看板' }}</el-button></div></div>
    <el-dialog class="crm-dialog dashboard-view-picker" title="选择已保存看板" :visible.sync="picker.visible" width="900px" append-to-body :close-on-click-modal="false"><form class="dashboard-table-tools" @submit.prevent="loadViews(1)"><el-input v-model="picker.keyword" size="small" :disabled="saving" clearable placeholder="搜索看板名称" /><el-button size="small" type="success" native-type="submit" :loading="picker.loading">查询</el-button></form><el-alert v-if="picker.error" :title="picker.error" type="error" :closable="false" /><el-table v-loading="picker.loading" :data="picker.rows" size="small" border><el-table-column prop="name" label="看板名称" min-width="180" /><el-table-column label="共享范围" min-width="130"><template slot-scope="{row}">{{ scopeLabel(row) }}</template></el-table-column><el-table-column prop="owner_name" label="创建人" min-width="100" /><el-table-column label="操作" width="145"><template slot-scope="{row}"><el-button type="text" :disabled="picker.restoring || saving" @click="restore(row)">使用</el-button><el-button v-if="row.can_delete" type="text" class="danger-link" :disabled="picker.restoring || saving" @click="remove(row)">删除</el-button></template></el-table-column></el-table><footer class="crm-pager"><el-pagination background layout="total, prev, pager, next" :total="picker.total" :current-page="picker.page" :page-size="10" @current-change="loadViews" /></footer><span slot="footer"><el-button size="small" @click="picker.visible = false">关闭</el-button></span></el-dialog>
  </el-dialog>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import { dashboardPages, dashboardComponentCatalog, normalizeDashboardComponents, defaultDashboardComponents } from './dashboardConfiguration'
import { listDashboardSalesOptions } from '@/api/erp/crmDashboard'
import { getCrmViewMetadata, listCrmViews, getCrmView, createCrmView, updateCrmView, deleteCrmView } from '@/api/erp/crmViews'
import { crmError } from '@/api/erp/crm'

const copy = value => JSON.parse(JSON.stringify(value))
export default {
  name: 'DashboardConfigurationDialog',
  components: {PagedRecordSelect},
  props: {dashboardMetadata:Object, dimensions:Array, sources:Array},
  data: () => ({visible:false, saving:false, error:'', warnings:[], metadata:{}, metadataReady:false, sequence:0, current:null, configuration:{filters:{},settings:{},components:[]}, name:'', shareKey:'personal:', category:'overview', selectedKey:'dimension_cross', dragKey:'', pages:dashboardPages, catalog:dashboardComponentCatalog, picker:{visible:false,keyword:'',rows:[],total:0,page:1,loading:false,restoring:false,error:'',sequence:0}}),
  computed: {
    selected() { return this.configuration.components.find(item => item.key === this.selectedKey) },
    definition() { return this.catalog.find(item => item.key === this.selectedKey) },
    isDimension() { return ['dimension_distribution','dimension_cross','dimension_table'].includes(this.selectedKey) },
    isFunnel() { return ['sales_funnels','pipeline_funnel'].includes(this.selectedKey) },
    statisticObject() { if (!this.definition) return ''; const key = this.definition.key; return key.includes('funnel') ? '同批线索' : key.includes('income') || /amount/.test(key) ? '有效订单' : /pipeline|stages|opportunit|expected/.test(key) ? '商机' : /followup|records|due|completed/.test(key) ? '跟进任务' : '客户' },
    shareOptions() { return [{key:'personal:',label:'仅自己',scope:'personal',scope_id:null},...(this.metadata.share_scopes || []).filter(item => item.scope !== 'personal').map(item => ({...item,key:`${item.scope}:${item.scope_id}`,label:item.label || item.name || (item.scope === 'group' ? '本小组' : '本部门')}))] },
    departments() { return (this.dashboardMetadata.departments || []).filter(item => !item.parent_id || !(this.dashboardMetadata.departments || []).some(parent => parent.id === item.parent_id)) },
    groups() { return (this.dashboardMetadata.departments || []).filter(item => this.configuration.filters.department_legacy_id ? String(item.parent_id) === String(this.configuration.filters.department_legacy_id) : !!item.parent_id) },
    salesParams() { return {department_legacy_id:this.configuration.filters.department_legacy_id,group_legacy_id:this.configuration.filters.group_legacy_id} },
    filterRange: {get() {return [this.configuration.filters.date_from,this.configuration.filters.date_to]}, set(value) {this.$set(this.configuration.filters,'date_from',(value || [])[0]);this.$set(this.configuration.filters,'date_to',(value || [])[1])}},
    cohortRange: {get() {return [this.configuration.settings.cohort_from,this.configuration.settings.cohort_to]}, set(value) {this.configuration.settings.cohort_from=(value || [])[0];this.configuration.settings.cohort_to=(value || [])[1]}}
  },
  beforeDestroy() { this.sequence += 1; this.picker.sequence += 1 },
  methods: {
    salesRequest:listDashboardSalesOptions,
    salesLabel(row) {return row.name},
    warningText(warning) {return typeof warning === 'string' ? warning : warning.message || '部分配置已失效，请重新选择后保存'},
    ability(key) {return !!(this.dashboardMetadata.abilities || {})[key]},
    dimensionAllowed(value) {return this.ability('customers') && (!['customer_pool','customer_tag','custom_tag'].includes(value) || this.ability('leads')) && (value !== 'custom_tag' || this.shareKey === 'personal:')},
    component(key) {return this.configuration.components.find(item => item.key === key)},
    categoryComponents(category) {return this.catalog.filter(item => item.category === category).sort((a,b) => this.component(a.key).order - this.component(b.key).order)},
    selectCategory(category) {const definition=this.categoryComponents(category)[0];if(definition)this.selectedKey=definition.key},
    async open(configuration, current, warnings = []) {
      const sequence=++this.sequence; this.configuration=copy(configuration);this.configuration.components=normalizeDashboardComponents(configuration.components);this.current=current ? copy(current) : null;this.name=current ? current.name : '我的经营看板';this.shareKey=current ? `${current.scope}:${current.scope_id || ''}` : 'personal:';this.warnings=warnings.slice();this.error='';this.visible=true;this.saving=false;this.metadataReady=false;this.category=configuration.page || 'overview';this.selectCategory(this.category)
      try {
        const {data}=await getCrmViewMetadata({resource:'dashboard'});if(sequence!==this.sequence || !this.visible)return
        this.metadata=data.data || data;this.metadataReady=true;this.$emit('catalog',this.metadata.dashboard_components || [])
        let unavailable=false
        this.configuration.components.forEach(item=>{if(!this.componentAvailable(item.key)){unavailable=unavailable || item.enabled;item.enabled=false}})
        if(unavailable)this.warnings.push('部分组件已失效或当前无权查看，已停用；其他输入已保留。')
        if(!this.shareOptions.some(item=>item.key===this.shareKey))this.shareKey='personal:'
      }
      catch(error) {if(sequence===this.sequence)this.error=crmError(error,'看板配置加载失败，请重新打开后重试')}
    },
    close(done) {if(this.saving)return;this.sequence+=1;this.picker.sequence+=1;this.visible=false;this.picker.visible=false;if(typeof done==='function')done()},
    departmentChanged() {this.$set(this.configuration.filters,'group_legacy_id',null);this.clearSales()},
    clearSales() {this.$set(this.configuration.filters,'sales_user_legacy_id',null);this.$set(this.configuration.filters,'sales_user_unassigned',false)},
    componentAvailable(key) {const item=(this.metadata.dashboard_components || []).find(item=>item.key===key);return this.metadataReady && !!item && item.available!==false},
    ordered() {return this.configuration.components.filter(item=>item.position===this.selected.position).sort((a,b)=>a.order-b.order)},
    canMove(delta) {if(!this.selected)return false;const items=this.ordered();const index=items.indexOf(this.selected);return index+delta>=0 && index+delta<items.length},
    move(delta) {const items=this.ordered();const index=items.indexOf(this.selected);if(!this.canMove(delta))return;items.splice(index,1);items.splice(index+delta,0,this.selected);items.forEach((item,i)=>{item.order=i})},
    dropBefore(key) {const source=this.component(this.dragKey);const target=this.component(key);if(this.saving || !source || !target || source===target)return;source.position=target.position;const items=this.configuration.components.filter(item=>item.position===target.position && item!==source).sort((a,b)=>a.order-b.order);items.splice(items.indexOf(target),0,source);items.forEach((item,index)=>{item.order=index});this.selectedKey=source.key;this.dragKey=''},
    restoreDefault() {this.current=null;this.configuration.components=defaultDashboardComponents();this.name='我的经营看板';this.shareKey='personal:';this.error='';this.warnings=[];this.$emit('default');this.close()},
    async save(asNew) {
      if(this.saving || !this.metadataReady)return
      if(!this.name.trim()){this.error='请填写看板名称';return}
      if(this.configuration.components.some(item=>!item.title.trim())){this.error='请填写组件名称';return}
      const share=this.shareOptions.find(item=>item.key===this.shareKey)
      if(!share){this.error='共享范围已失效，请重新选择';return}
      if(share.scope!=='personal' && this.configuration.settings.dimension==='custom_tag'){this.error='个人标签仅可保存为个人看板，请先更换分析维度';return}
      const sequence=this.sequence;const update=!asNew && this.current && this.current.can_update
      const body={resource:'dashboard',name:this.name.trim(),scope:share.scope,scope_id:share.scope_id,configuration:copy(this.configuration)}
      if(update)body.expected_version=this.current.version
      this.saving=true;this.error=''
      try {const {data}=await (update?updateCrmView(this.current.id,body):createCrmView(body));if(sequence!==this.sequence || !this.visible)return;this.$emit('saved',data);this.saving=false;this.close()}
      catch(error){if(sequence===this.sequence)this.error=crmError(error,'看板保存失败，已保留当前输入，请重试')}
      finally{if(sequence===this.sequence)this.saving=false}
    },
    scopeLabel(row) {return row.scope_name || (row.scope==='personal'?'仅自己':row.scope==='group'?'小组共享':'部门共享')},
    openPicker() {this.picker.visible=true;this.picker.keyword='';this.loadViews(1)},
    async loadViews(page=1) {this.picker.page=page;const sequence=++this.picker.sequence;this.picker.loading=true;this.picker.error='';try{const {data}=await listCrmViews({resource:'dashboard',keyword:this.picker.keyword,page,per_page:10});if(sequence!==this.picker.sequence || !this.visible)return;const result=Array.isArray(data.data)?data:data.data || data;this.picker.rows=result.data || [];this.picker.total=result.total || 0}catch(error){if(sequence===this.picker.sequence)this.picker.error=crmError(error,'已保存看板加载失败')}finally{if(sequence===this.picker.sequence)this.picker.loading=false}},
    async restore(row) {if(this.picker.restoring)return;const sequence=++this.picker.sequence;this.picker.restoring=true;this.picker.error='';try{const {data}=await getCrmView(row.id);if(sequence!==this.picker.sequence || !this.visible)return;this.$emit('saved',data);this.close()}catch(error){if(sequence===this.picker.sequence)this.picker.error=crmError(error,'看板恢复失败，当前输入已保留')}finally{this.picker.restoring=false}},
    async remove(row) {
      const sequence=this.sequence
      try{await this.$confirm(`删除看板“${row.name}”？`,'删除看板',{type:'warning',confirmButtonText:'删除',cancelButtonText:'取消'})}catch(_){return}
      if(sequence!==this.sequence || !this.visible || !this.picker.visible || this.saving)return
      try{await deleteCrmView(row.id,{expected_version:row.version});if(sequence!==this.sequence || !this.visible)return;if(this.current && this.current.id===row.id){this.current=null;this.$emit('deleted',row.id)}await this.loadViews(this.picker.page)}catch(error){if(sequence===this.sequence)this.picker.error=crmError(error,'删除失败，请刷新后重试')}
    }
  }
}
</script>
