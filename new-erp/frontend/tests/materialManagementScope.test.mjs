import test from 'node:test'
import assert from 'node:assert/strict'
import { readFile } from 'node:fs/promises'
import { createRequire } from 'node:module'
import * as scope from '../src/utils/materialManagementScope.mjs'
import * as purchaseScope from '../src/utils/purchaseManagementScope.mjs'
const require = createRequire(import.meta.url)
const compiler = require('vue-template-compiler')
const paths = {
  ItemForm: 'views/erp/master/ItemForm.vue', ItemList: 'views/erp/master/ItemList.vue',
  ItemCategoryList: 'views/erp/master/ItemCategoryList.vue', PurchaseItemPicker: 'components/purchase/PurchaseItemPicker.vue',
  InventoryBoard: 'views/erp/inventory/InventoryBoard.vue', ImportWorkbench: 'views/erp/master/ImportWorkbench.vue',
  ProductionRoutingForm: 'views/erp/production/ProductionRoutingForm.vue', RoutingOutputRulesDialog: 'views/erp/production/RoutingOutputRulesDialog.vue'
}
const sources = Object.fromEntries(await Promise.all(Object.entries(paths).map(async ([name,path]) => [name, await readFile(new URL('../src/' + path, import.meta.url), 'utf8')])))
function mount(name, dependencies = {}, routeScope = 'office') {
  const bindings = { ...purchaseScope, ...scope, cachedPageRoute: {}, pagedScroll: {},
    createPageState: per_page => ({ per_page, rows: [] }), invalidatePage() {},
    ...dependencies }
  const script = compiler.parseComponent(sources[name]).script.content.replace(/import[\s\S]*?from ['"][^'"]+['"]\s*/g, '').replace('export default', 'return')
  const component = new Function(...Object.keys(bindings), script)(...Object.values(bindings))
  const route = { path: scope.materialListPath(routeScope) + '/new', params: {}, query: { type: 'Item', management_scope: routeScope }, meta: { materialScope: routeScope } }
  const messages = [], routes = []
  const vm = { $route: route, pageRoute: route, $router: { push: value => routes.push(value) },
    $nextTick: async () => {}, $refs: { form: { clearValidate() {}, validate(fn) { fn(true) } } },
    $set: (row,key,value) => { row[key] = value }, $delete: (row,key) => { delete row[key] }, $emit() {}, $can: () => true,
    $message: { error: message => messages.push(message), warning: message => messages.push(message), success() {} },
    messages, routes, component }
  for (const [key,fn] of Object.entries(component.methods || {})) vm[key] = fn.bind(vm)
  Object.assign(vm, component.data.call(vm))
  for (const [key,fn] of Object.entries(component.computed || {})) Object.defineProperty(vm,key,{ get: () => typeof fn === 'function' ? fn.call(vm) : fn.get.call(vm) })
  return vm
}
const itemResponse = (id, management_scope) => ({ data: { data: [{ id, management_scope }], total: 21, stats: { total: 21 } } })

test('scope pages and shared selectors compile with the installed Vue compiler', () => {
  for (const [name,source] of Object.entries(sources)) assert.deepEqual(compiler.compile(compiler.parseComponent(source).template.content).errors, [], name)
})
test('the unified material list retains its own scope filter after another page becomes active', async () => {
  const requests = []
  const vm = mount('ItemList', { listEntity: async (entity,query) => { requests.push(query); return itemResponse(1,query.management_scope) } })
  vm.$route = { path: '/master/items', meta: { materialScope: 'factory' } }
  await vm.load()
  assert.equal(requests[0].management_scope,'office'); assert.equal(vm.scopeLabel,'办公用品'); assert.equal(vm.listPath,'/master/items')
})

test('all materials share one paginated query, and a scope change clears incompatible filters', async () => {
  const requests=[]
  const vm=mount('ItemList',{listEntity:async(entity,query)=>{requests.push(query);return itemResponse(1,'factory')}},'')
  await vm.load(); assert.equal(Object.hasOwn(requests[0],'management_scope'),false)
  assert.equal(vm.itemTypes.some(type=>type.value==='office_consumable'),true)
  vm.query.category_id=10; vm.query.item_type='raw_material'; vm.query.page=5; vm.query.management_scope='office'
  vm.loadOptions=async()=>{}; vm.changeScope(); await Promise.resolve()
  assert.equal(vm.query.category_id,''); assert.equal(vm.query.item_type,''); assert.equal(vm.query.page,1)
  assert.equal(requests.at(-1).management_scope,'office')
  assert.deepEqual(vm.itemTypes.map(type=>type.value),['office_consumable','service'])
  vm.openCreate(); assert.deepEqual(vm.routes.at(-1),{path:'/master/items/new',query:{management_scope:'office'}})
  vm.openEdit({id:8,management_scope:'office'}); assert.equal(vm.routes.at(-1),'/master/items/8/edit')
})

test('scopeTabs navigation allows clicking tags to switch factory, office and all materials directly', async () => {
  const requests = []
  const vm = mount('ItemList', { listEntity: async (entity, query) => { requests.push(query); return itemResponse(1, query.management_scope) } }, '')
  assert.deepEqual(vm.scopeTabs.map(t => ({ value: t.value, label: t.label })), [
    { value: '', label: '全部物料' },
    { value: 'factory', label: '工厂物料' },
    { value: 'office', label: '办公用品' }
  ])
  vm.loadOptions = async () => {}
  await vm.selectScopeTab('factory')
  assert.equal(vm.query.management_scope, 'factory')
  assert.equal(requests.at(-1).management_scope, 'factory')

  await vm.selectScopeTab('office')
  assert.equal(vm.query.management_scope, 'office')
  assert.equal(requests.at(-1).management_scope, 'office')

  await vm.selectScopeTab('')
  assert.equal(vm.query.management_scope, '')
  assert.equal(vm.activeScopeTab, 'all')
  assert.equal(Object.hasOwn(requests.at(-1), 'management_scope'), false)

  vm.handleScopeTabClick({ name: 'office' })
  assert.equal(vm.query.management_scope, 'office')
  assert.equal(vm.activeScopeTab, 'office')

  vm.handleScopeTabClick({ name: 'all' })
  assert.equal(vm.query.management_scope, '')
  assert.equal(vm.activeScopeTab, 'all')
})

test('itemList loads scope counts from stats and exposes badges for all, factory, and office unconditionally', async () => {
  const vm = mount('ItemList', {
    listEntity: async () => ({
      data: {
        data: [{ id: 1, management_scope: 'factory' }],
        total: 21,
        stats: { factory_total: 18, office_total: 3, all_total: 21 }
      }
    })
  }, '')
  assert.equal(vm.activeScopeTab, 'all')
  await vm.load()
  assert.equal(vm.scopeCount('all'), '21')
  assert.equal(vm.scopeCount('factory'), '18')
  assert.equal(vm.scopeCount('office'), '3')

  // When keyword is searched on the active tab, filtered total is reflected on current tab
  vm.query.keyword = '钢板'
  vm.total = 5
  assert.equal(vm.scopeCount('all'), '5')
  assert.equal(vm.scopeCount('factory'), '18')
  assert.equal(vm.scopeCount('office'), '3')
})

test('mixed-list detail uses the selected item identity rather than the current filter', async () => {
  let params
  const vm=mount('ItemList',{getEntity:async(entity,id,query)=>{params=query;return {data:{id,management_scope:'office'}}}},'')
  vm.selected=await vm.fetchItem({id:8,management_scope:'office'})
  assert.deepEqual(params,{management_scope:'office'}); assert.equal(vm.selectedIsOffice,true)
  assert.equal(vm.recordScopeLabel(vm.selected),'办公用品'); assert.equal(vm.recordScopeLabel({management_scope:'factory'}),'工厂物料')
})
test('stale list response cannot overwrite newer results and statistics', async () => {
  let release
  const vm = mount('ItemList', { listEntity: (entity,query) => query.keyword === 'old' ? new Promise(resolve => { release = resolve }) : Promise.resolve(itemResponse(2,'office')) })
  vm.query.keyword = 'old'; const first = vm.load()
  vm.query.keyword = 'new'; await vm.load()
  release({ data: { data: [{id:1}], total:99, stats: {total:99} } }); await first
  assert.equal(vm.rows[0].id,2); assert.equal(vm.total,21); assert.equal(vm.stats.total,21)
})
test('moving an unused item to office removes production settings and preserves its financial policy', async () => {
  const vm = mount('ItemForm',{ getItemCategoryTree: async () => ({data:{data:[]}}), listEntity: async () => ({data:{data:[]}}) },'factory')
  Object.assign(vm.form,{management_scope:'office',item_type:'raw_material',is_production_item:true,manufacturing_strategy:'self_make',cutting_mode:'length',standard_stock_length_mm:6000,category_id:1})
  Object.assign(vm.policy,{future_route:'asset',requires_capitalization:true,requires_custodian:true})
  const originalPolicy = structuredClone(vm.policy)
  vm.changeScope('office'); await Promise.resolve()
  assert.equal(vm.form.item_type,'office_consumable'); assert.equal(vm.form.category_id,null)
  assert.equal(vm.form.is_production_item,false); assert.equal(vm.form.manufacturing_strategy,'unspecified')
  assert.equal(vm.form.cutting_mode,'none'); assert.equal(vm.form.standard_stock_length_mm,null)
  assert.deepEqual(vm.policy,originalPolicy)
})
test('category options from the previous scope are ignored after scope changes', async () => {
  let release
  const vm = mount('ItemForm',{ getItemCategoryTree: query => query.management_scope === 'factory' ? new Promise(resolve => { release = resolve }) : Promise.resolve({data:{data:[{id:2,is_leaf:true,status:'enabled'}]}}),listEntity: async () => ({data:{data:[]}}) },'factory')
  vm.form.management_scope='factory'; const first=vm.loadOptions()
  vm.form.management_scope='office'; await vm.loadOptions()
  release({data:{data:[{id:1,is_leaf:true,status:'enabled'}]}}); await first
  assert.deepEqual(vm.categories.map(row=>row.id),[2]); assert.equal(vm.categoriesLoading,false)
})
test('saved creation and editing pages refresh when reopened in the unified entry', () => {
  const vm=mount('ItemForm'); vm.pageHasSaved=true; vm.form.id=8
  let reloads=0; vm.loadPage=()=>reloads++
  vm.component.activated.call(vm); assert.equal(reloads,1)
  vm.pageRoute.params.id=8; vm.form.management_scope='office'; vm.component.activated.call(vm); assert.equal(reloads,2)
  vm.form.management_scope='factory'; vm.component.activated.call(vm); assert.equal(reloads,3)
})

test('an office item opens in the same edit page without a forced factory context', async () => {
  let params
  const vm=mount('ItemForm',{getItemIntegratedForm:async(id,query)=>{params=query;return {data:{item:{id,management_scope:'office',item_type:'office_consumable',cutting_mode:'none'},policy:{},balance:{},history:{data:[]}}}}},'')
  vm.pageRoute.params.id=8; await vm.loadEdit()
  assert.equal(params,undefined); assert.equal(vm.pageError,''); assert.equal(vm.isOffice,true)
  assert.equal(vm.entryListPath,'/master/items')
  assert.equal(vm.actionOptions.some(action=>action.value==='work_order_cost'),false)
  vm.form.management_scope='factory'; assert.equal(vm.itemTypes.some(type=>type.value==='office_consumable'),false)
  assert.equal(vm.templateOptions.some(option=>option.label.includes('办公')),false)
})
test('a mismatched edit entry reports a read-only error and cannot save', async () => {
  let writes=0
  const vm=mount('ItemForm',{getItemIntegratedForm:async()=>{throw {userMessage:'管理范围不一致'}},saveItemIntegratedForm:async()=>writes++})
  vm.pageRoute.params.id=8; await vm.loadEdit(); vm.save(false)
  assert.equal(vm.pageError,'管理范围不一致'); assert.equal(writes,0)
})
test('shared purchase picker clears cross-scope selection, keeps fixed scope and resets on reopening', async () => {
  const requests=[]
  const vm=mount('PurchaseItemPicker',{getItemCategoryTree:async()=>({data:{data:[]}}),listEntity:async(entity,query)=>{requests.push(query);return itemResponse(query.management_scope==='office'?2:1,query.management_scope||'factory')}})
  await vm.open({multiple:true}); vm.toggleRow(vm.rows[0])
  vm.query.management_scope='office'; vm.changeScope(); await vm.load(); vm.toggleRow(vm.rows[0])
  assert.deepEqual(vm.selectedRows.map(row=>row.id),[2]); assert.equal(requests.at(-1).management_scope,'office')
  await vm.open({multiple:false,params:{management_scope:'factory'}})
  vm.query.management_scope='office'; await vm.load()
  assert.equal(requests.at(-1).management_scope,'factory'); assert.equal(vm.selectedRows.length,0); assert.equal(vm.multiple,false)
})
test('inventory scope selection clears old selection and rejects a stale balance response', async () => {
  let release
  const vm=mount('InventoryBoard',{listInventoryBalances:query=>query.management_scope==='factory'?new Promise(resolve=>{release=resolve}):Promise.resolve({data:{data:[{item_id:2,item:{management_scope:'office'}}],total:1,stats:{item_count:1}}})})
  vm.balanceQuery.management_scope='factory'; const first=vm.loadBalances()
  vm.selectedBalance={item_id:1}; vm.balanceDetailVisible=true; vm.balanceQuery.management_scope='office'
  vm.searchBalances=()=>{}; vm.changeBalanceScope(); await vm.loadBalances()
  release({data:{data:[{item_id:1,item:{management_scope:'factory'}}],total:99,stats:{item_count:99}}}); await first
  assert.equal(vm.selectedBalance,null); assert.equal(vm.balanceDetailVisible,false)
  assert.equal(vm.balanceRows[0].management_scope,'office'); assert.equal(vm.balanceStats.item_count,1)
})
test('office import upload freezes its selected scope in the multipart request', async () => {
  let request
  const vm=mount('ImportWorkbench',{uploadImport:async form=>{request=Object.fromEntries(form.entries());return {data:{data:{id:9,management_scope:'office'}}}},previewImport:async()=>({data:{data:{id:9,management_scope:'office'},rows:{data:[],per_page:50,current_page:1,total:0,last_page:1}}})})
  vm.file=new Blob(['物料名称,管理范围\n验收纸张,office']); await vm.upload()
  assert.equal(request.management_scope,'office'); assert.equal(request.import_type,'Item')
  assert.equal(vm.batch.management_scope,'office'); assert.equal(vm.stage,1)
})
test('material status actions use the actual row identity in a mixed list', async () => {
  let request
  const vm=mount('ItemList',{disableEntity:async(entity,id,params)=>{request={entity,id,params}}})
  vm.$route={path:'/master/items',meta:{materialScope:'factory'}}
  vm.$confirm=async()=>{}; vm.load=async()=>{}
  vm.query.management_scope='factory'
  await vm.toggleStatus({id:8,status:'enabled',management_scope:'office'})
  assert.deepEqual(request,{entity:'items',id:8,params:{management_scope:'office'}})
})
test('category status actions retain office scope and refresh that same hierarchy', async () => {
  let request
  const vm=mount('ItemCategoryList',{disableItemCategory:async(id,params)=>{request={id,params}}})
  vm.$route={path:'/master/categories',meta:{materialScope:'factory'}}
  vm.$confirm=async()=>{}; vm.loadTree=async()=>{}; vm.selectCategory=async()=>{}
  await vm.toggleStatus({id:9,status:'enabled',management_scope:'office'})
  assert.deepEqual(request,{id:9,params:{management_scope:'office'}})
})
