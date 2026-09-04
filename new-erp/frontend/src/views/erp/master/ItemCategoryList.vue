<template>
  <section class="category-page" :class="{ 'drawer-open': drawerVisible }">
    <div class="category-workspace">
      <header class="page-head">
        <div><h1>Item类目管理</h1><p>维护 Item 物料类目树；上级类目仅用于导航，业务对象只能选择末级类目。</p></div>
        <el-button v-if="canManage" size="small" type="success" icon="el-icon-plus" @click="openCreate(null)">新增一级类目</el-button>
      </header>

      <div class="filter-bar">
        <el-input v-model="query.keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="类目编码/名称" @keyup.enter.native="loadChildren" />
        <el-select v-model="query.status" size="small" clearable placeholder="状态"><el-option label="启用" value="enabled"/><el-option label="停用" value="disabled"/></el-select>
        <el-button size="small" type="success" @click="search">查询</el-button><el-button size="small" @click="reset">重置</el-button>
      </div>

      <div class="content-grid">
        <aside class="tree-card">
          <div class="card-title"><b>Item类目树</b><el-button type="text" size="mini" icon="el-icon-refresh" @click="loadTree">刷新</el-button></div>
          <el-input v-model="treeKeyword" size="mini" clearable prefix-icon="el-icon-search" placeholder="搜索类目" @input="filterTree" />
          <el-tree ref="categoryTree" :data="tree" node-key="id" default-expand-all highlight-current :filter-node-method="filterNode" :props="{ label:'category_name', children:'children' }" @node-click="selectCategory">
            <span slot-scope="{ data }" class="tree-node"><i :class="data.is_leaf?'el-icon-document':'el-icon-folder-opened'"/><span>{{ data.category_name }}</span><em>{{ data.subtree_item_count }}</em></span>
          </el-tree>
        </aside>

        <main class="detail-column">
          <section v-if="selected.id" class="detail-card">
            <div class="detail-head"><div><h2>{{ selected.category_name }}</h2><span>{{ selected.full_path }}</span></div><div class="detail-actions"><el-button v-if="canManage" size="mini" @click="openCreate(selected)">新增子类目</el-button><el-button v-if="canManage" size="mini" @click="openEdit(selected)">编辑</el-button><el-button v-if="canManage" size="mini" :type="selected.status==='enabled'?'danger':'success'" plain @click="toggleStatus(selected)">{{ selected.status==='enabled'?'停用':'启用' }}</el-button><el-button v-if="canManage&&selected.status!=='enabled'" size="mini" type="danger" plain @click="deleteCategory(selected)">删除</el-button></div></div>
            <div class="meta-grid"><dl><dt>类目编码</dt><dd>{{ selected.category_code }}</dd><dt>父级类目</dt><dd>{{ selected.parent_id ? parentName(selected.parent_id) : '一级类目' }}</dd></dl><dl><dt>排序</dt><dd>{{ selected.sort_order }}</dd><dt>状态</dt><dd><el-tag size="mini" :type="selected.status==='enabled'?'success':'info'">{{ selected.status==='enabled'?'启用':'停用' }}</el-tag></dd></dl><dl><dt>备注</dt><dd>{{ selected.remark || '-' }}</dd><dt>更新时间</dt><dd>{{ formatDate(selected.updated_at) }}</dd></dl></div>
          </section>

          <div v-if="selected.id" class="stat-row">
            <button @click="goItems"><i class="el-icon-box"/><span>关联Item</span><strong>{{ selected.direct_item_count || 0 }}</strong></button>
            <button @click="goSuppliers"><i class="el-icon-truck"/><span>关联供应商</span><strong>{{ selected.direct_supplier_count || 0 }}</strong></button>
            <div><i class="el-icon-folder"/><span>下级类目</span><strong>{{ selected.direct_child_count || 0 }}</strong></div>
          </div>

          <section class="children-card">
            <div class="card-title"><b>{{ selected.id ? '下级类目' : '一级类目' }}</b><span>共 {{ total }} 条</span></div>
            <el-table v-loading="loading" :data="rows" size="mini" border @row-click="selectCategory">
              <el-table-column prop="category_code" label="类目编码" min-width="120"/><el-table-column prop="category_name" label="类目名称" min-width="150"/>
              <el-table-column prop="direct_child_count" label="子类目" width="78"/><el-table-column prop="direct_item_count" label="关联Item" width="86"><template slot-scope="{row}"><el-button type="text" size="mini" @click.stop="goItems(row)">{{ row.direct_item_count }}</el-button></template></el-table-column>
              <el-table-column prop="direct_supplier_count" label="供应商" width="82"><template slot-scope="{row}"><el-button type="text" size="mini" @click.stop="goSuppliers(row)">{{ row.direct_supplier_count }}</el-button></template></el-table-column>
              <el-table-column label="状态" width="72"><template slot-scope="{row}"><el-tag size="mini" :type="row.status==='enabled'?'success':'info'">{{ row.status==='enabled'?'启用':'停用' }}</el-tag></template></el-table-column>
              <el-table-column label="操作" width="150" fixed="right"><template slot-scope="{row}"><el-button type="text" size="mini" @click.stop="selectCategory(row)">查看</el-button><el-button v-if="canManage" type="text" size="mini" @click.stop="openEdit(row)">编辑</el-button><el-button v-if="canManage" type="text" size="mini" @click.stop="openCreate(row)">加子类</el-button></template></el-table-column>
            </el-table>
            <div class="pager"><span>共 {{ total }} 条</span><el-pagination small layout="prev, pager, next, sizes" :current-page.sync="query.page" :page-size.sync="query.per_page" :page-sizes="[10,20,50]" :total="total" @current-change="loadChildren" @size-change="loadChildren"/></div>
          </section>
        </main>
      </div>
    </div>

    <aside v-if="drawerVisible" class="edit-drawer">
      <div class="drawer-head"><div><h2>{{ form.id?'编辑Item类目':'新增Item类目' }}</h2><small>{{ form.parent_id ? `父级：${parentName(form.parent_id)}` : '一级类目' }}</small></div><i class="el-icon-close" @click="drawerVisible=false"/></div>
      <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small" class="drawer-body">
        <el-form-item label="类目编码" prop="category_code"><el-input v-model.trim="form.category_code" disabled placeholder="正在预生成"><template slot="append">系统预生成</template></el-input></el-form-item>
        <el-form-item label="类目名称" prop="category_name"><el-input v-model.trim="form.category_name"/></el-form-item>
        <el-form-item label="父级类目"><el-select v-model="form.parent_id" clearable filterable class="full" placeholder="不选则为一级类目"><el-option v-for="row in parentOptions" :key="row.id" :label="row.full_path" :value="row.id"/></el-select></el-form-item>
        <el-form-item label="排序"><el-input-number v-model="form.sort_order" :min="0" controls-position="right"/></el-form-item>
        <el-form-item label="状态"><el-radio-group v-model="form.status"><el-radio label="enabled">启用</el-radio><el-radio label="disabled">停用</el-radio></el-radio-group></el-form-item>
        <el-form-item label="备注"><el-input v-model="form.remark" type="textarea" :rows="4" maxlength="1000" show-word-limit/></el-form-item>
        <el-alert title="类目编码创建后不可修改；已有业务引用的类目只允许停用，不删除。" type="info" :closable="false" show-icon/>
      </el-form>
      <div class="drawer-footer"><el-button size="small" @click="drawerVisible=false">取消</el-button><el-button v-if="canManage" size="small" type="success" :loading="saving || numberLoading" :disabled="!form.id && (!reservation || !form.category_code)" @click="save">保存</el-button></div>
    </aside>
  </section>
</template>

<script>
import { listItemCategories, getItemCategoryTree, getItemCategory, saveItemCategory, disableItemCategory, enableItemCategory, deleteItemCategory } from '../../../api/erp/master'
import { reserveForCreatePage, reserveFreshDocumentNumber, clearCreatePageReservation } from '../../../utils/documentNumberReservation'
const emptyForm=()=>({id:null,category_code:'',category_name:'',parent_id:null,sort_order:0,status:'enabled',remark:''})
export default {
  name:'ItemCategoryList',
  data(){return{loading:false,saving:false,numberLoading:false,reservation:null,drawerVisible:false,tree:[],treeKeyword:'',rows:[],total:0,selected:{},form:emptyForm(),query:{keyword:'',status:'',page:1,per_page:20},rules:{category_code:[{required:true,message:'系统编号生成失败，请重新打开新增页',trigger:'change'}],category_name:[{required:true,message:'请输入类目名称',trigger:'blur'}]}}},
  computed:{
    flatRows(){const rows=[];const visit=list=>(list||[]).forEach(row=>{rows.push(row);visit(row.children)});visit(this.tree);return rows},
    parentOptions(){return this.flatRows.filter(row=>Number(row.id)!==Number(this.form.id))},
    canManage(){const profile=JSON.parse(localStorage.getItem('erp_me')||'{}');const permissions=JSON.parse(localStorage.getItem('erp_permissions')||'[]');return !!profile.is_super_admin||permissions.includes('item_category.manage')}
  },
  created(){this.initialize()},
  methods:{
    async initialize(){await this.loadTree();if(this.tree.length)await this.selectCategory(this.tree[0]);else await this.loadChildren()},
    async loadTree(){try{const {data}=await getItemCategoryTree();this.tree=data.data||[]}catch(e){this.$message.error(e.userMessage||'Item类目树加载失败')}},
    async loadChildren(){this.loading=true;try{const params={...this.query};if(this.selected.id)params.parent_id=this.selected.id;else params.root_only=1;const {data}=await listItemCategories(params);this.rows=data.data||[];this.total=data.total||0}catch(e){this.$message.error(e.userMessage||'类目列表加载失败')}finally{this.loading=false}},
    async selectCategory(row){try{const {data}=await getItemCategory(row.id);this.selected=data.data||row;this.query.page=1;await this.loadChildren();this.$refs.categoryTree&&this.$refs.categoryTree.setCurrentKey(row.id)}catch(e){this.$message.error(e.userMessage||'类目详情加载失败')}},
    search(){this.query.page=1;this.loadChildren()},reset(){this.query={keyword:'',status:'',page:1,per_page:20};this.loadChildren()},
    filterTree(value){this.$refs.categoryTree&&this.$refs.categoryTree.filter(value)},filterNode(value,data){if(!value)return true;const q=String(value).toLowerCase();return `${data.category_code}${data.category_name}${data.full_path}`.toLowerCase().includes(q)},
    async openCreate(parent){this.form={...emptyForm(),parent_id:parent?.id||null};this.reservation=null;this.drawerVisible=true;this.numberLoading=true;try{this.reservation=await reserveForCreatePage('item_category','/master/categories#create');this.form.category_code=this.reservation.document_no}catch(e){this.$message.error(e.userMessage||'Item类目编号预生成失败，请重新打开新增页')}finally{this.numberLoading=false}},openEdit(row){this.reservation=null;this.form={...emptyForm(),...row};this.drawerVisible=true},
    save(){this.$refs.form.validate(async valid=>{if(!valid)return;this.saving=true;try{const payload={...this.form};if(!this.form.id&&this.reservation){payload.reservation_token=this.reservation.reservation_token;payload.creation_session_id=this.reservation.creation_session_id}const {data}=await saveItemCategory(payload);if(!this.form.id)clearCreatePageReservation(this.reservation);this.$message.success('Item类目保存成功');this.drawerVisible=false;await this.loadTree();if(data?.data?.id)await this.selectCategory({id:data.data.id});else if(this.form.id)await this.selectCategory({id:this.form.id});else if(this.form.parent_id)await this.selectCategory({id:this.form.parent_id});else await this.initialize()}catch(e){const errors=e.response?.data?.errors||{};if(!this.form.id&&(errors.category_code||errors.reservation_token||errors.creation_session_id)){await this.refreshGeneratedNumber(e);return}this.$message.error(e.userMessage||'保存失败')}finally{this.saving=false}})},
    async refreshGeneratedNumber(error){const old=this.reservation;clearCreatePageReservation(old);this.reservation=null;this.form.category_code='';this.numberLoading=true;try{this.reservation=await reserveFreshDocumentNumber('item_category','/master/categories#create');this.form.category_code=this.reservation.document_no;const errors=error.response?.data?.errors||{};const first=Object.values(errors)[0];this.$message.error(`${Array.isArray(first)?first[0]:(first||'编号冲突')}，系统已刷新编号，请重新确认保存。`)}catch(e){this.$message.error(e.userMessage||'新编号生成失败，请关闭并重新打开新增页')}finally{this.numberLoading=false}},
    async toggleStatus(row){const enabling=row.status!=='enabled';try{await this.$confirm(enabling?'启用前系统会检查全部上级类目，确认继续？':'停用前系统会检查启用的子类目；历史 Item 与供应商关系不会删除。',enabling?'启用类目':'停用类目',{type:'warning'});await(enabling?enableItemCategory:disableItemCategory)(row.id);this.$message.success(enabling?'类目已启用':'类目已停用');await this.loadTree();await this.selectCategory({id:row.id})}catch(e){if(e!=='cancel')this.$message.error(e.userMessage||'操作失败')}},
    async deleteCategory(row){try{await this.$confirm(`确认删除 Item 类目 ${row.category_code} / ${row.category_name}？仅无子类目且未被 Item 或供应商引用的停用类目可以删除。`,'删除类目',{type:'warning',confirmButtonText:'确认删除'});await deleteItemCategory(row.id);this.$message.success('Item 类目已删除');this.selected={};await this.loadTree();await this.initialize()}catch(e){if(e!=='cancel'&&e!=='close')this.$message.error(e.userMessage||'类目删除失败')}},
    parentName(id){return(this.flatRows.find(row=>Number(row.id)===Number(id))||{}).category_name||'-'},
    goItems(row=this.selected){this.$router.push({path:'/master/items',query:{category_id:row.id}})},goSuppliers(row=this.selected){this.$router.push({path:'/master/suppliers',query:{category_id:row.id}})},
    formatDate(v){return v?String(v).replace('T',' ').slice(0,16):'-'}
  }
}
</script>

<style scoped>
.category-page{position:relative;min-height:calc(100vh - 52px);background:#f7f8f9;min-width:980px}.category-workspace{padding:16px 18px;transition:padding-right .18s}.category-page.drawer-open .category-workspace{padding-right:430px}.page-head{height:68px;display:flex;align-items:flex-start;justify-content:space-between}.page-head h1{margin:0;font-size:19px}.page-head p{margin:5px 0;color:#77828c;font-size:11px}.filter-bar{display:flex;gap:9px;align-items:center;padding:11px;background:#fff;border:1px solid #dfe5e9;border-radius:4px}.filter-bar .el-input{width:240px}.filter-bar .el-select{width:150px}.content-grid{display:grid;grid-template-columns:270px minmax(0,1fr);gap:12px;margin-top:12px}.tree-card,.detail-card,.children-card{background:#fff;border:1px solid #dfe5e9;border-radius:4px}.tree-card{padding:12px;min-height:620px}.card-title{height:34px;display:flex;align-items:center;justify-content:space-between}.card-title span{color:#7c8790;font-size:11px}.tree-card>.el-input{margin-bottom:9px}.tree-node{display:flex;align-items:center;width:100%;gap:7px;font-size:12px}.tree-node i{color:#07883f}.tree-node span{flex:1}.tree-node em{font-style:normal;color:#8a959e}.detail-column{min-width:0}.detail-card{padding:14px}.detail-head{display:flex;justify-content:space-between;align-items:flex-start;border-bottom:1px solid #edf0f2;padding-bottom:12px}.detail-head h2{margin:0;font-size:17px}.detail-head span{display:block;margin-top:4px;color:#77828c;font-size:11px}.detail-actions{display:flex;gap:7px}.meta-grid{display:grid;grid-template-columns:1fr 1fr 1.2fr;gap:14px;padding-top:13px}.meta-grid dl{display:grid;grid-template-columns:72px 1fr;gap:9px;margin:0}.meta-grid dt{color:#78838c}.meta-grid dd{margin:0}.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin:11px 0}.stat-row>button,.stat-row>div{height:74px;padding:12px 15px;display:grid;grid-template-columns:34px 1fr;grid-template-rows:1fr 1fr;text-align:left;background:#fff;border:1px solid #dfe5e9;border-radius:4px}.stat-row>button{cursor:pointer}.stat-row i{grid-row:1/3;align-self:center;color:#0a9952;font-size:25px}.stat-row span{color:#6f7a84}.stat-row strong{font-size:20px}.children-card{overflow:hidden}.children-card>.card-title{height:45px;padding:0 12px}.pager{height:48px;padding:0 12px;display:flex;justify-content:space-between;align-items:center;color:#69747d}.edit-drawer{position:fixed;z-index:9;top:52px;right:0;bottom:0;width:412px;background:#fff;border-left:1px solid #dfe5e9;box-shadow:-8px 0 24px rgba(25,43,58,.08)}.drawer-head{height:62px;padding:0 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #e2e7eb}.drawer-head h2{margin:0;font-size:16px}.drawer-head small{color:#74808a}.drawer-head i{cursor:pointer}.drawer-body{height:calc(100% - 126px);padding:15px 18px 82px;overflow:auto}.full{width:100%}.drawer-footer{position:absolute;left:0;right:0;bottom:0;height:64px;padding:12px 16px;display:grid;grid-template-columns:1fr 1.2fr;gap:12px;background:#fff;border-top:1px solid #e2e7eb}@media(max-width:1180px){.category-page.drawer-open .category-workspace{padding-right:18px}.edit-drawer{width:412px}.content-grid{grid-template-columns:240px minmax(0,1fr)}}
</style>
