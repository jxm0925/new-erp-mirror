<template>
  <section class="production-page routing-editor" v-loading="loading">
    <!-- 顶部统一标头 (.page-head) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-guide" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ title }}</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              {{ form.routing_no }} · V{{ form.version }}
            </el-tag>
            <el-tag size="small" :type="form.status==='active'?'success':form.status==='retired'?'info':'warning'" effect="plain" class="head-tag">
              {{ statusText(form.status) }}
            </el-tag>
          </div>
          <div class="sub-row">
            维护工艺路线的工序流转、用料定额、检验规范及流转规则
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" class="btn-back" @click="$router.push('/production/routings')">
          返回列表
        </el-button>
        <el-button v-if="!viewOnly" size="small" type="success" plain class="btn-theme-plain" icon="el-icon-document" @click="save(false)">
          保存草稿
        </el-button>
        <el-button v-if="!viewOnly && $can('production.routing.activate')" size="small" type="success" icon="el-icon-check" class="btn-theme-create" @click="save(true)">
          检查并启用
        </el-button>
      </div>
    </header>

    <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small">
      <!-- 基础信息卡片 -->
      <section class="form-card metadata">
        <div class="card-head">
          <div class="card-title">工艺路线基础信息</div>
        </div>
        <div class="form-grid">
          <el-form-item label="路线编码"><el-input v-model="form.routing_no" disabled class="erp-system-number" /></el-form-item>
          <el-form-item label="路线名称" prop="routing_name"><el-input v-model="form.routing_name" :disabled="viewOnly" placeholder="请输入路线名称" /></el-form-item>
          <el-form-item label="版本（系统递增）"><el-input :value="`V${form.version}`" disabled /></el-form-item>
          <el-form-item label="关联产品（可选）"><el-input :value="form.product && form.product.product_name" readonly :disabled="viewOnly" suffix-icon="el-icon-search" placeholder="点击选择关联产品" @click.native="!viewOnly && $refs.productPicker.openProduct()" /></el-form-item>
          <el-form-item label="关联 SKU（可选）"><el-input :value="form.sku && (form.sku.sku_name || form.sku.sku_code)" readonly :disabled="viewOnly" suffix-icon="el-icon-search" placeholder="点击选择关联 SKU" @click.native="!viewOnly && (form.product_id ? $refs.productPicker.openSku(form.product_id) : $refs.productPicker.openGlobalSku())" /></el-form-item>
          <el-form-item label="状态"><el-input :value="statusText(form.status)" disabled /></el-form-item>
          <el-form-item label="是否默认"><el-input :value="form.is_default ? '是' : '否'" disabled /></el-form-item>
          <el-form-item label="适用物料" :required="!form.sku_id"><el-input :value="form.sku_id ? 'SKU 当前默认物料（自动）' : form.applicable_items.map(item => item.item_name).join('、')" readonly :disabled="viewOnly || !!form.sku_id" suffix-icon="el-icon-search" placeholder="可多选；与适用类目至少填一项" @click.native="!form.sku_id && openItems('shared')" /></el-form-item>
          <el-form-item label="适用类目" :required="!form.sku_id"><el-cascader v-model="form.applicable_category_ids" :options="categoryTree" :props="{ multiple: true, checkStrictly: true, emitPath: false, value: 'id', label: 'category_name', children: 'children' }" :disabled="viewOnly || !!form.sku_id" clearable filterable collapse-tags :placeholder="form.sku_id ? '限定 SKU 时不适用' : '类目（含下级）下的物料都用本路线'" /></el-form-item>
        </div>
      </section>

      <!-- 路线工序卡片 -->
      <section class="form-card operation-card">
        <div class="card-head sub-heading">
          <div class="card-title">
            路线工序清单
            <el-tag v-if="canManagePerformance" size="small" type="info" class="perf-tag">提成模板：{{ performanceLabel }}</el-tag>
          </div>
          <div class="catalog-actions">
            <el-button
              v-if="viewOnly && form.operations.length > 1"
              size="small"
              type="success"
              plain
              class="btn-theme-plain"
              :icon="allOperationsExpanded ? 'el-icon-arrow-up' : 'el-icon-arrow-down'"
              @click="toggleAllOperations"
            >
              {{ allOperationsExpanded ? '收起全部工序' : '展开全部工序' }}
            </el-button>
            <template v-if="!viewOnly">
              <el-button size="small" type="success" plain class="btn-theme-plain" icon="el-icon-collection-tag" @click="openCatalog('stages')">维护生产阶段</el-button>
              <el-button size="small" type="success" plain class="btn-theme-plain" icon="el-icon-box" @click="openCatalog('packaging-schemes')">维护包装方案</el-button>
              <el-button size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="addOperation">
                {{ form.operations.length ? '添加下一道工序' : '添加第一道工序' }}
              </el-button>
            </template>
          </div>
        </div>
        <el-table ref="operations" :data="form.operations" border row-key="key" :expand-row-keys="expanded" :row-class-name="tableRowClassName" @expand-change="onExpand" @row-click="onOperationRowClick">
          <template slot="empty">
            <div class="empty-routing-op">
              <i class="el-icon-guide" style="font-size: 32px; color: #94a3b8; margin-bottom: 8px;" />
              <p style="margin: 0 0 10px; color: #64748b; font-size: 13px;">暂无工序，请点击上方按钮添加第一道工序</p>
              <el-button v-if="!viewOnly" size="small" type="success" icon="el-icon-plus" class="btn-theme-create" @click="addOperation">
                添加第一道工序
              </el-button>
            </div>
          </template>
          <el-table-column type="expand" width="1" class-name="native-expand">
            <template slot-scope="s">
              <div class="operation-settings" :key="s.row.key">
                <!-- 顶部专属工序指引条 -->
                <div class="op-panel-head">
                  <div class="op-panel-meta">
                    <span class="op-seq-pill">第 {{ Math.floor(s.row.sequence / 10) }} 道工序</span>
                    <span class="op-panel-title">
                      {{ operationName(s.row) ? operationName(s.row) : '未指定工序（请点击选择）' }}
                    </span>
                    <el-tag size="mini" :type="s.row.execution_context==='shipment'?'warning':'success'" effect="plain">
                      {{ s.row.execution_context==='shipment'?'发货作业':'生产工序' }}
                    </el-tag>
                    <el-tag v-if="operationIsPublic(s.row)" size="mini" type="success" effect="plain">
                      公共工序
                    </el-tag>
                    <span class="op-panel-tip">
                      <i class="el-icon-info" /> {{ viewOnly ? '查看该道工序的参数、工时及用料规则' : '配置该道工序的参数、工时及用料规则' }}
                    </span>
                  </div>
                  <div class="op-panel-btns">
                    <el-button v-if="viewOnly" size="mini" type="success" plain icon="el-icon-arrow-up" @click.stop="toggleOperation(s.row)">
                      收起详情
                    </el-button>
                    <el-button v-else size="mini" type="success" plain icon="el-icon-check" @click="confirmSettings(s.row)">
                      完成配置并收起
                    </el-button>
                  </div>
                </div>

                <section class="settings-group">
                  <h3>工序信息</h3>
                  <label>
                    <span>所属阶段</span>
                    <div class="selector-field">
                      <el-input :value="catalogName('stages',s.row.production_stage_id)" readonly :disabled="viewOnly" suffix-icon="el-icon-search" placeholder="选择阶段（可选）" @click.native="openCatalog('stages',s.row)" />
                      <el-button v-if="!viewOnly && s.row.production_stage_id" type="text" @click="s.row.production_stage_id=null">清除</el-button>
                    </div>
                  </label>
                  <label>
                    <span>执行场景</span>
                    <el-select :value="s.row.execution_context" :disabled="viewOnly" @change="changeExecutionContext(s.row,$event)">
                      <el-option label="生产工序" value="production" />
                      <el-option label="发货作业" value="shipment" />
                    </el-select>
                  </label>
                  <label v-if="s.row.execution_context==='shipment'">
                    <span>发货作业类型</span>
                    <el-select :value="s.row.shipment_operation_type" :disabled="viewOnly" @change="changeShipmentType(s.row,$event)">
                      <el-option label="试水" value="water_test" />
                      <el-option label="打包" value="packing" />
                      <el-option label="装车 / 提货" value="loading" />
                    </el-select>
                  </label>
                  <label>
                    <span>作业方式</span>
                    <el-select v-model="s.row.work_mode" :disabled="viewOnly">
                      <el-option label="人工作业" value="manual" />
                      <el-option label="自动作业" value="automatic" />
                    </el-select>
                  </label>
                  <label v-if="s.row.execution_context==='production'">
                    <span>完成去向</span>
                    <el-select v-model="s.row.output_mode" :disabled="viewOnly" @change="setOutputMode(s.row)">
                      <el-option v-for="(label,value) in outputModes" :key="value" :label="value==='flow_only'?label:`${label}（产出本物料）`" :value="value" :disabled="value!=='flow_only'&&!isLastProduction(s.row)" />
                    </el-select>
                  </label>
                  <label>
                    <span>检验要求</span>
                    <el-select v-model="s.row.quality_mode" :disabled="viewOnly">
                      <el-option label="无" value="none" />
                      <el-option label="必须检验" value="required" />
                    </el-select>
                  </label>
                </section>

                <section class="settings-group">
                  <h3>工时与参数</h3>
                  <label>
                    <span>标准工时（每台设备）</span>
                    <el-input v-model="s.row.unit_hours" type="number" :disabled="viewOnly" min="0" step="0.5" placeholder="例如总装一台 2 小时">
                      <template slot="append">小时</template>
                    </el-input>
                    <small class="field-hint">正常熟练工做好一台设备要多久，做几台就乘几</small>
                  </label>
                  <label>
                    <span>准备工时（每张工单一次）</span>
                    <el-input v-model="s.row.setup_hours" type="number" :disabled="viewOnly" min="0" step="0.5" placeholder="没有可填 0">
                      <template slot="append">小时</template>
                    </el-input>
                    <small class="field-hint">开工前看图、调机、换夹具等，一张工单只算一次，和台数无关</small>
                  </label>
                  <label v-if="canManagePerformance">
                    <span>提成比例（按模板）</span>
                    <el-input :value="performanceText(s.row)" disabled>
                      <template slot="append">%</template>
                    </el-input>
                  </label>
                  <label>
                    <span>必要参数</span>
                    <el-input v-model="s.row.parameters_text" type="textarea" :rows="2" :disabled="viewOnly" placeholder="填写该工序的加工工艺参数、公差等" />
                  </label>
                </section>

                <section class="settings-group material-group">
                  <div class="material-group-head">
                    <div class="material-group-title">
                      <h3>{{ s.row.execution_context==='shipment'?'发货作业':'用料规则' }}</h3>
                      <el-tag
                        v-if="s.row.execution_context!=='shipment' || s.row.shipment_operation_type==='packing'"
                        size="mini"
                        type="info"
                        effect="plain"
                        class="material-count-tag"
                      >
                        共 {{ operationMaterialCount(s.row) }} 项
                      </el-tag>
                    </div>
                    <div class="material-group-tools">
                      <el-input
                        v-if="operationMaterialCount(s.row) > 4"
                        :value="getMaterialKeyword(s.row)"
                        size="mini"
                        clearable
                        prefix-icon="el-icon-search"
                        placeholder="筛选本工序物料"
                        class="material-filter-input"
                        @input="setMaterialKeyword(s.row, $event)"
                      />
                      <el-button
                        v-if="operationMaterialCount(s.row) > 5"
                        type="text"
                        size="mini"
                        class="material-expand-btn"
                        @click="toggleMaterialTableTall(s.row)"
                      >
                        {{ isMaterialTableTall(s.row) ? '紧凑滚动' : '展开更多' }}
                      </el-button>
                      <el-button
                        v-if="!viewOnly && (s.row.execution_context!=='shipment'||s.row.shipment_operation_type==='packing')"
                        type="success"
                        plain
                        size="mini"
                        icon="el-icon-plus"
                        class="btn-theme-plain"
                        @click="openItems(s.row.execution_context==='shipment'?'packaging':'materials',s.row)"
                      >
                        {{ s.row.execution_context==='shipment'?'添加包装用料':'添加用料' }}
                      </el-button>
                    </div>
                  </div>
                  <template v-if="s.row.execution_context==='shipment'">
                    <template v-if="s.row.shipment_operation_type==='packing'">
                      <label>
                        <span>包装方案</span>
                        <div class="selector-field">
                          <el-input :value="catalogName('packaging-schemes',s.row.packaging_scheme_id)" readonly :disabled="viewOnly" suffix-icon="el-icon-search" placeholder="请选择包装方案" @click.native="openCatalog('packaging-schemes',s.row)" />
                          <el-button v-if="!viewOnly && s.row.packaging_scheme_id" type="text" @click="s.row.packaging_scheme_id=null">清除</el-button>
                        </div>
                      </label>
                      <p class="setting-note">发货作业排在生产工序之后；包装用料填写每件产出的基础单位数量。</p>
                      <el-table :data="filteredPackagingMaterials(s.row)" border size="mini" :max-height="isMaterialTableTall(s.row) ? 460 : 240" class="compact-material-table">
                        <el-table-column label="包装物料" min-width="140"><template slot-scope="r">{{ itemName(r.row.component_item_id) }}</template></el-table-column>
                        <el-table-column label="每件用量" min-width="120"><template slot-scope="r"><el-input v-model="r.row.base_qty_per_output_unit" :disabled="viewOnly" size="mini" placeholder="请填写数量" /></template></el-table-column>
                        <el-table-column label="基础单位" min-width="80"><template slot-scope="r">{{ itemUnit(r.row.component_item_id) }}</template></el-table-column>
                        <el-table-column v-if="!viewOnly" label="操作" width="56"><template slot-scope="r"><el-button type="text" @click="removePackagingMaterial(s.row,r.row)">删除</el-button></template></el-table-column>
                      </el-table>
                    </template>
                    <p v-else class="setting-note">本工序无需包装方案或包装用料。</p>
                  </template>
                  <el-table v-else :data="filteredIncomingRules(s.row)" border size="mini" :max-height="isMaterialTableTall(s.row) ? 460 : 240" class="compact-material-table">
                    <el-table-column label="物料名称" min-width="110"><template slot-scope="r">{{ itemName(r.row.component_item_id) }}</template></el-table-column>
                    <el-table-column label="BOM 用量占比" min-width="130"><template slot-scope="r"><el-input-number v-model="r.row.percent" :disabled="viewOnly" :controls="false" :min="0.0001" :max="100" :precision="4" size="mini" /><span>%</span></template></el-table-column>
                    <el-table-column label="供应方式" min-width="130"><template slot-scope="r"><el-select v-model="r.row.supply_mode" :disabled="viewOnly" size="mini"><el-option v-for="(label,value) in supplyModes" :key="value" :label="label" :value="value" /></el-select></template></el-table-column>
                    <el-table-column v-if="!viewOnly" label="操作" width="56"><template slot-scope="r"><el-button type="text" @click="removeMaterial(r.row)">删除</el-button></template></el-table-column>
                  </el-table>
                  <div v-if="!viewOnly" class="setting-actions">
                    <el-button v-if="s.row.execution_context!=='shipment'||s.row.shipment_operation_type==='packing'" type="success" plain size="small" @click="openItems(s.row.execution_context==='shipment'?'packaging':'materials',s.row)">
                      {{ s.row.execution_context==='shipment'?'添加包装用料':'添加用料' }}
                    </el-button>
                    <el-button type="success" size="small" @click="confirmSettings(s.row)">完成本工序设置</el-button>
                    <el-button type="success" plain size="small" icon="el-icon-plus" @click="finishAndAddNext(s.row)">完成并添加下一工序</el-button>
                  </div>
                </section>
              </div>
            </template>
          </el-table-column>

          <el-table-column label="顺序" width="80" align="center">
            <template slot-scope="s">
              <span class="seq-num-badge">{{ s.row.sequence }}</span>
            </template>
          </el-table-column>
          <el-table-column label="工序名称" min-width="160">
            <template slot-scope="s">
              <div class="operation-select-field">
                <el-input :value="operationName(s.row)" :disabled="viewOnly" readonly suffix-icon="el-icon-search" placeholder="点击选择工序" size="small" @click.native="openOperationPicker(s.row)" @keydown.enter.native.prevent="openOperationPicker(s.row)" />
                <el-tag v-if="operationIsPublic(s.row)" size="mini" type="success" class="selected-operation-tag">公共工序</el-tag>
              </div>
            </template>
          </el-table-column>
          <el-table-column label="阶段 / 场景" min-width="125">
            <template slot-scope="s">
              {{ catalogName('stages',s.row.production_stage_id) || '未设置阶段' }}
              <div class="setting-note">{{ s.row.execution_context==='shipment'?'发货作业':'生产工序' }}</div>
            </template>
          </el-table-column>
          <el-table-column label="工序用料" min-width="85">
            <template slot-scope="s">
              {{ s.row.execution_context==='shipment'?s.row.packaging_materials.length:incomingRules(s.row).length }} 种物料
            </template>
          </el-table-column>
          <el-table-column label="标准工时" min-width="95">
            <template slot-scope="s">
              {{ s.row.unit_hours || 0 }} 小时/台<template v-if="Number(s.row.setup_hours) > 0"><br><small>准备 {{ s.row.setup_hours }} 小时</small></template>
            </template>
          </el-table-column>
          <el-table-column label="完成去向" min-width="110">
            <template slot-scope="s">
              {{ s.row.execution_context==='shipment'?shipmentTypeText(s.row.shipment_operation_type):outputModes[s.row.output_mode] }}
            </template>
          </el-table-column>
          <el-table-column label="操作" :width="viewOnly ? 115 : 185" align="center">
            <template slot-scope="s">
              <div v-if="viewOnly" class="row-actions">
                <el-button type="text" @click.stop="toggleOperation(s.row)">
                  <i :class="expanded.includes(s.row.key) ? 'el-icon-arrow-up' : 'el-icon-arrow-down'" />
                  {{ expanded.includes(s.row.key) ? '收起详情' : '查看详情' }}
                </el-button>
              </div>
              <div v-else class="row-actions">
                <el-button type="text" @click.stop="toggleOperation(s.row)">
                  {{ expanded.includes(s.row.key) ? '收起配置' : '编辑配置' }}
                </el-button>
                <el-button type="text" :disabled="!canMove(s.$index,-1)" @click.stop="move(s.$index,-1)">上移</el-button>
                <el-button type="text" :disabled="!canMove(s.$index,1)" @click.stop="move(s.$index,1)">下移</el-button>
                <el-button type="text" class="btn-del" @click.stop="remove(s.$index)">删除</el-button>
              </div>
            </template>
          </el-table-column>
        </el-table>
        <el-form-item label="备注" class="remark"><el-input v-model="form.remark" :disabled="viewOnly" type="textarea" :rows="3" /></el-form-item>
      </section>
    </el-form>
    <purchase-item-picker ref="itemPicker" @select="selectItem" @select-multiple="selectMaterials" />
    <product-sku-picker ref="productPicker" @select="selectProduct" />
    <production-process-catalog-panel ref="processCatalog" :launchers="false" @select="selectCatalog" @updated="rememberCatalog" />
    <system-record-picker :visible.sync="operationPickerOpen" type="operations" :multiple="false" :value="operationPickerValue" @confirm="selectOperation" />
  </section>
</template>
<script>
import cachedPageRoute from '@/utils/cachedPageRoute'
import { getProductionRouting, createProductionRouting, updateProductionRouting, activateProductionRouting } from '../../../api/erp/production'
import { getItemCategoryTree } from '../../../api/erp/master'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'
import PurchaseItemPicker from '@/components/purchase/PurchaseItemPicker.vue'
import ProductSkuPicker from '@/components/sales/ProductSkuPicker.vue'
import ProductionProcessCatalogPanel from './ProductionProcessCatalogPanel.vue'
import SystemRecordPicker from '@/components/system/SystemRecordPicker.vue'
import { executeProductionDecision } from '../../../api/erp/production-decisions'
const uuid = () => window.crypto && window.crypto.randomUUID ? window.crypto.randomUUID() : 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g,c=>{const r=Math.random()*16|0;return (c==='x'?r:(r&3|8)).toString(16)})
const emptyPercent = value => value === '' || value === null || value === undefined
const percentFromRate = value => emptyPercent(value) ? '' : String(Number((Number(value) * 100).toFixed(4)))
const validPercent = value => emptyPercent(value) || (/^\d{1,3}(?:\.\d{1,4})?$/.test(String(value)) && Number(value) <= 100)
const asBoolean = value => value === true || value === 1 || value === '1'
// 工时页面上按小时填，后台仍按分钟存。
const toHours=minutes=>Number((Number(minutes||0)/60).toFixed(2))
const toMinutes=hours=>Math.round(Number(hours||0)*60*100)/100

export default {
  mixins: [cachedPageRoute],
  name:'ProductionRoutingForm', components:{PurchaseItemPicker,ProductSkuPicker,ProductionProcessCatalogPanel,SystemRecordPicker},
  data:()=>({performance:null,loading:false,sessionId:'',reservationToken:'',reservation:null,savedId:null,operations:[],operationPickerOpen:false,operationPickerTarget:null,operationPickerValue:[],items:{},catalogs:{stages:{},'packaging-schemes':{}},expanded:[],pickerTarget:null,catalogTarget:null,pendingSave:null,pendingActivation:null,
    materialKeywordByOp:{},materialTallByOp:{},
    outputModes:{flow_only:'直接交接',warehouse_optional:'可选入库',warehouse_required:'合格后入库'},
    supplyModes:{dedicated_delivery:'专单配送',workstation_stock:'工位库存',no_per_order_delivery:'无需逐单配送'},
    form:{routing_no:'正在生成…',routing_name:'',product_id:null,sku_id:null,product:null,sku:null,version:1,status:'draft',is_default:false,remark:'',business_version:1,operations:[],applicable_items:[],applicable_category_ids:[]},categoryTree:[],
    rules:{routing_name:[{required:true,message:'请输入路线名称',trigger:'blur'}]}}),
  computed:{id(){return this.savedId || this.pageRoute.params.id},viewOnly(){return (Boolean(this.pageRoute.params.id)&&!this.pageRoute.path.endsWith('/edit')) || this.form.status!=='draft'},title(){return this.id?(this.viewOnly?'查看工艺路线':'编辑工艺路线'):'新增工艺路线'},canManagePerformance(){return this.$can('production.performance.manage')},performanceLabel(){const p=this.performance;if(!p)return '保存后显示';if(!p.template)return '未配置（不计提成）';return p.template.template_name+(p.source==='default'?'（默认）':'（指定）')},allOperationsExpanded(){return this.form.operations.length>0&&this.form.operations.every(row=>this.expanded.includes(row.key))}},
  async created(){this.loadCategories();if(this.id)await this.load();else await this.reserve()},
  methods:{
    performanceText(row){const rates=this.performance&&this.performance.rates;if(!rates)return '';const rate=rates[row.operation_id];return rate===undefined||rate===null?'不计提成':percentFromRate(rate)},
    statusText(v){return({draft:'草稿',active:'已生效',retired:'已退役',disabled:'已退役'})[v]||v},
    shipmentTypeText(v){return({water_test:'试水',packing:'发货打包',loading:'装车 / 提货'})[v]||'发货作业'},
    isPublic(value){return asBoolean(value)},
    operationName(row){return this.operations.find(operation=>Number(operation.id)===Number(row.operation_id))?.operation_name||''},
    operationIsPublic(row){const operation=this.operations.find(candidate=>Number(candidate.id)===Number(row.operation_id));return asBoolean(operation&&operation.is_public!==undefined?operation.is_public:row.is_public??row.operation?.is_public)},
    itemName(id){const item=this.items[id];return item?item.item_name:(id?`物料 ${id}`:'')},
    itemUnit(id){const item=this.items[id]||{};const unit=item.unit?.standard_unit||item.unit?.standardUnit||item.unit||item.inventory_unit;return unit?.symbol||unit?.unit_name||unit?.unit_code||item.base_unit_name||'—'},
    rememberItem(item){if(item&&item.id)this.$set(this.items,item.id,item)},
    rememberCatalog({type,row}){if(row&&row.id&&this.catalogs[type])this.$set(this.catalogs[type],row.id,row)},
    catalogName(type,id){return (this.catalogs[type][id]||{}).name||(id?`档案 ${id}`:'')},
    openCatalog(type,row=null){if(this.viewOnly)return;this.catalogTarget=row?{type,row}:null;this.$refs.processCatalog.open(type,{mode:row?'select':'manage',selected:row?(this.catalogs[type][type==='stages'?row.production_stage_id:row.packaging_scheme_id]||null):null})},
    selectCatalog({type,row}){this.rememberCatalog({type,row});if(!this.catalogTarget||this.catalogTarget.type!==type||!this.form.operations.includes(this.catalogTarget.row))return;this.$set(this.catalogTarget.row,type==='stages'?'production_stage_id':'packaging_scheme_id',row.id);this.catalogTarget=null},
    openOperationPicker(row){if(this.viewOnly||!this.form.operations.includes(row))return;this.operationPickerTarget=row;const current=this.operations.find(operation=>Number(operation.id)===Number(row.operation_id));this.operationPickerValue=current?[current]:[];this.operationPickerOpen=true},
    selectOperation(rows){const row=this.operationPickerTarget;if(this.viewOnly||!this.form.operations.includes(row)||rows.length!==1)return;const operation=rows[0];const index=this.operations.findIndex(current=>Number(current.id)===Number(operation.id));if(index<0)this.operations.push(operation);else this.$set(this.operations,index,operation);row.operation_id=operation.id},
    async reserve(){this.loading=true;try{this.reservation=await reserveForCreatePage('routing',this.pageRoute.path);this.sessionId=this.reservation.creation_session_id;this.form.routing_no=this.reservation.document_no;this.reservationToken=this.reservation.reservation_token;this.doAddOperation({silent:true})}catch(e){this.$message.error(e.userMessage||'编号生成失败')}finally{this.loading=false}},
    async load(){this.loading=true;try{const r=await getProductionRouting(this.id);this.applyData(r.data.data)}catch(e){this.$message.error(e.userMessage||'路线加载失败')}finally{this.loading=false}},
    async loadCategories(){try{const r=await getItemCategoryTree({management_scope:'factory'});this.categoryTree=r.data.data||[]}catch(e){this.categoryTree=[]}},
    applyData(data){
      const scopes=data.applicabilities||[]
      data.applicable_items=scopes.filter(x=>x.scope_type==='item'&&x.item).map(x=>x.item)
      data.applicable_category_ids=scopes.filter(x=>x.scope_type==='category').map(x=>Number(x.item_category_id))
      data.applicable_items.forEach(item=>this.rememberItem(item))
      if(data.performance!==undefined)this.performance=data.performance
      ;(data.packaging_material_items||[]).forEach(item=>this.rememberItem(item))
      const byId=new Map((data.operations||[]).map(x=>[Number(x.id),`op-${x.id}`]))
      const rows=(data.operations||[]).map(x=>{
        if(x.operation){const index=this.operations.findIndex(o=>Number(o.id)===Number(x.operation.id));const operation={...x.operation,is_public:asBoolean(x.is_public??x.operation.is_public)};if(index<0)this.operations.push(operation);else this.$set(this.operations,index,{...this.operations[index],...operation})}
        this.rememberCatalog({type:'stages',row:x.stage||{id:x.production_stage_id,name:x.stage_name,code:x.stage_code}})
        this.rememberCatalog({type:'packaging-schemes',row:x.packaging_scheme||{id:x.packaging_scheme_id,name:x.packaging_scheme_name,code:x.packaging_scheme_code}})
        const ordinary={...x};delete ordinary.performance_rate
        return {...ordinary,key:byId.get(Number(x.id)),production_stage_id:x.production_stage_id||null,execution_context:x.execution_context||'production',shipment_operation_type:x.shipment_operation_type||'packing',packaging_scheme_id:x.packaging_scheme_id||null,packaging_materials:(x.packaging_materials||[]).map(material=>{this.rememberItem(material.component_item);return {...material}}),parameters_text:x.parameters&&x.parameters.text||'',setup_hours:toHours(x.setup_standard_minutes||0),unit_hours:toHours(x.unit_standard_minutes??x.standard_minutes??0),material_supply_rules:(x.material_supply_rules||[]).map(rule=>{this.rememberItem(rule.component_item);return {...rule,target_key:byId.get(Number(rule.target_routing_operation_id)),percent:Number(percentFromRate(rule.required_qty_ratio))}})}
      })
      this.form={...this.form,...data,operations:rows};this.expanded=rows.length?[rows[0].key]:[]
    },
    tableRowClassName({ row }){
      const classes=[]
      if(this.expanded.includes(row.key))classes.push('row-is-expanded')
      if(this.viewOnly)classes.push('row-view-clickable')
      return classes.join(' ')
    },
    operationMaterialCount(row){
      if(!row)return 0
      return row.execution_context==='shipment'?(row.packaging_materials||[]).length:this.incomingRules(row).length
    },
    getMaterialKeyword(row){
      return (row&&this.materialKeywordByOp[row.key])||''
    },
    setMaterialKeyword(row,val){
      if(!row)return
      this.$set(this.materialKeywordByOp,row.key,val)
    },
    isMaterialTableTall(row){
      return Boolean(row&&this.materialTallByOp[row.key])
    },
    toggleMaterialTableTall(row){
      if(!row)return
      this.$set(this.materialTallByOp,row.key,!this.isMaterialTableTall(row))
    },
    filteredIncomingRules(row){
      const list=this.incomingRules(row)
      const kw=String(this.getMaterialKeyword(row)||'').trim().toLowerCase()
      if(!kw)return list
      return list.filter(rule=>{
        const item=this.items[rule.component_item_id]||{}
        const name=String(this.itemName(rule.component_item_id)||'').toLowerCase()
        const code=String(item.item_code||'').toLowerCase()
        return name.includes(kw)||code.includes(kw)
      })
    },
    filteredPackagingMaterials(row){
      const list=(row&&row.packaging_materials)||[]
      const kw=String(this.getMaterialKeyword(row)||'').trim().toLowerCase()
      if(!kw)return list
      return list.filter(material=>{
        const item=this.items[material.component_item_id]||{}
        const name=String(this.itemName(material.component_item_id)||'').toLowerCase()
        const code=String(item.item_code||'').toLowerCase()
        return name.includes(kw)||code.includes(kw)
      })
    },
    toggleAllOperations(){
      if(this.allOperationsExpanded){
        this.expanded=[]
      }else{
        this.expanded=this.form.operations.map(row=>row.key)
      }
    },
    onOperationRowClick(row){
      if(this.viewOnly&&row&&this.form.operations.includes(row)){
        this.toggleOperation(row)
      }
    },
    doAddOperation(options = {}){
      const lastOp=this.form.operations[this.form.operations.length-1];
      const hasShipment=this.form.operations.some(r=>r.execution_context==='shipment');
      const defaultContext=(lastOp&&lastOp.execution_context==='shipment')||hasShipment?'shipment':'production';
      const usedShipmentTypes=this.form.operations.filter(r=>r.execution_context==='shipment').map(r=>r.shipment_operation_type);
      const defaultShipmentType=!usedShipmentTypes.includes('water_test')?'water_test':(!usedShipmentTypes.includes('packing')?'packing':'loading');
      const nextSeq=(this.form.operations.length+1)*10;
      const row={key:uuid(),operation_id:null,production_stage_id:null,execution_context:defaultContext,shipment_operation_type:defaultShipmentType,packaging_scheme_id:null,packaging_materials:[],sequence:nextSeq,is_key_operation:false,parameters:null,parameters_text:'',remark:'',setup_hours:0,unit_hours:0,output_mode:'flow_only',quality_mode:'none',work_mode:'manual',allow_continue_without_warehouse:true,material_supply_rules:[]};
      this.form.operations.push(row);
      this.orderContexts();
      this.expanded=[row.key];
      const opIndex=Math.floor(row.sequence/10);
      if(!options.silent){
        this.$message.success(`已添加第 ${opIndex} 道工序，请选择工序名称并配置参数`)
      }
    },
    addOperation(){
      if(this.viewOnly)return;
      if(this.expanded.length>0){
        const openRow=this.form.operations.find(r=>this.expanded.includes(r.key));
        if(openRow){
          const opIndex=Math.floor(openRow.sequence/10);
          const opName=this.operationName(openRow);
          if(!openRow.operation_id){
            this.$message.warning(`当前第 ${opIndex} 道工序尚未选择工序名称，请先选择工序后再添加下一道工序`);
            return;
          }
          const displayName=opName||`第 ${opIndex} 道工序`;
          this.$confirm(
            `当前第 ${opIndex} 道工序【${displayName}】正在编辑中，尚未完成配置。是否完成当前工序设置并添加下一道工序？`,
            '工序切换确认',
            {
              confirmButtonText:'完成当前并添加下一工序',
              cancelButtonText:'留在当前继续编辑',
              type:'warning',
              distinguishCancelAndClose:true
            }
          ).then(()=>{
            if(!this.validateOperation(openRow))return;
            this.confirmSettings(openRow,{silent:true});
            this.doAddOperation();
          }).catch(()=>{
            // 用户选择留在当前或关闭弹窗，停留在当前工序，绝不擅自切走填写框
          });
          return;
        }
      }
      this.doAddOperation();
    },
    finishAndAddNext(row){
      if(!this.validateOperation(row))return;
      this.expanded=this.expanded.filter(key=>key!==row.key);
      const opIndex=Math.floor(row.sequence/10);
      const name=this.operationName(row)||`第 ${opIndex} 道工序`;
      this.$message.success(`【${name}】配置已完成`);
      this.doAddOperation();
    },
    changeShipmentType(row,type){if(this.viewOnly||row.shipment_operation_type===type)return;if(type!=='packing'&&(row.packaging_scheme_id||row.packaging_materials.length)){this.$message.warning('请先移除包装方案和包装用料，再更改发货作业类型');return}row.shipment_operation_type=type},
    incomingRules(row){return this.form.operations.flatMap(x=>x.material_supply_rules).filter(r=>r.target_key===row.key)},
    removeMaterial(rule){this.form.operations.forEach(row=>{row.material_supply_rules=row.material_supply_rules.filter(r=>r!==rule)})},
    removePackagingMaterial(row,material){row.packaging_materials=row.packaging_materials.filter(current=>current!==material)},
    remove(index){
      const row=this.form.operations[index];
      const opIndex=Math.floor(row.sequence/10);
      const name=this.operationName(row)||`第 ${opIndex} 道工序`;
      this.$confirm(`确定要移除工序【${name}】吗？`,'移除工序提示',{confirmButtonText:'确定移除',cancelButtonText:'取消',type:'warning'}).then(()=>{
        this.form.operations.splice(index,1);
        this.form.operations.forEach(x=>{x.material_supply_rules=x.material_supply_rules.filter(r=>r.target_key!==row.key)});
        this.renumber();
        this.normalizeProductionOutputModes();
        this.expanded=this.expanded.filter(k=>k!==row.key);
        this.$message.success(`已移除工序【${name}】`);
      }).catch(()=>{})
    },
    canMove(index,delta){const next=index+delta;return next>=0&&next<this.form.operations.length&&this.form.operations[index].execution_context===this.form.operations[next].execution_context},
    move(index,delta){if(!this.canMove(index,delta))return;const row=this.form.operations.splice(index,1)[0];this.form.operations.splice(index+delta,0,row);this.renumber();this.normalizeProductionOutputModes()},
    orderContexts(){this.form.operations=[...this.form.operations.filter(row=>row.execution_context!=='shipment'),...this.form.operations.filter(row=>row.execution_context==='shipment')];this.renumber();this.normalizeProductionOutputModes()},
    changeExecutionContext(row,context){if(this.viewOnly||row.execution_context===context)return;if(context==='shipment'&&(row.material_supply_rules.length||this.incomingRules(row).length)){this.$message.warning('请先清除本工序相关的生产用料，再改为发货作业');return}if(context==='production'&&(row.packaging_scheme_id||row.packaging_materials.length)){this.$message.warning('请先清除包装方案和包装用料，再改为生产工序');return}const prevOutputMode=row.output_mode;row.execution_context=context;if(context==='shipment'){row.output_mode='flow_only';row.allow_continue_without_warehouse=true;const prodOps=this.form.operations.filter(op=>op.execution_context==='production');if(prevOutputMode&&prevOutputMode!=='flow_only'&&prodOps.length){const lastProd=prodOps[prodOps.length-1];if(lastProd.output_mode==='flow_only'){lastProd.output_mode=prevOutputMode;lastProd.allow_continue_without_warehouse=prevOutputMode!=='warehouse_required'}}}this.orderContexts()},
    renumber(){this.form.operations.forEach((r,i)=>{r.sequence=(i+1)*10})},
    normalizeProductionOutputModes(){const prodOps=this.form.operations.filter(op=>op.execution_context==='production');if(prodOps.length<=1)return;const lastProd=prodOps[prodOps.length-1];let carried=null;prodOps.slice(0,-1).forEach(op=>{if(op.output_mode&&op.output_mode!=='flow_only'){carried=op.output_mode;op.output_mode='flow_only';op.allow_continue_without_warehouse=true}});if(carried&&lastProd.output_mode==='flow_only'){lastProd.output_mode=carried;lastProd.allow_continue_without_warehouse=carried!=='warehouse_required'}},
    onExpand(row,expanded){this.expanded=expanded.map(x=>x.key)},
    toggleOperation(row){
      if(this.viewOnly){
        if(this.expanded.includes(row.key)){
          this.expanded=this.expanded.filter(key=>key!==row.key);
        }else{
          this.expanded=[...this.expanded,row.key];
        }
        return;
      }
      if(this.expanded.includes(row.key)){
        this.confirmSettings(row);
      }else{
        if(this.expanded.length>0){
          const current=this.form.operations.find(r=>this.expanded.includes(r.key));
          if(current&&current.key!==row.key){
            const currentIdx=Math.floor(current.sequence/10);
            const currentName=this.operationName(current);
            if(!current.operation_id){
              this.$message.warning(`当前第 ${currentIdx} 道工序尚未选择工序名称，请先完善当前工序`);
              return;
            }
            const displayName=currentName||`第 ${currentIdx} 道工序`;
            this.$confirm(
              `当前第 ${currentIdx} 道工序【${displayName}】正在编辑中。是否完成并收起，切换到第 ${Math.floor(row.sequence/10)} 道工序？`,
              '切换编辑工序',
              {
                confirmButtonText:'保存并切换',
                cancelButtonText:'留在当前工序',
                type:'info',
                distinguishCancelAndClose:true
              }
            ).then(()=>{
              if(!this.validateOperation(current))return;
              this.confirmSettings(current,{silent:true});
              this.expanded=[row.key];
            }).catch(()=>{});
            return;
          }
        }
        this.expanded=[row.key];
      }
    },
    setOutputMode(row){
      row.allow_continue_without_warehouse=row.output_mode!=='warehouse_required';
    },
    // 入库只能放在最后一道车间工序，入库的就是工单本身的物料；中间工序只做流转，半成品请用多级 BOM 单独开工单。
    isLastProduction(row){const production=this.form.operations.filter(op=>op.execution_context==='production');return production.length>0&&production.reduce((a,b)=>Number(b.sequence)>Number(a.sequence)?b:a)===row},
    confirmSettings(row, options = {}){
      if(this.validateOperation(row)){
        this.expanded=this.expanded.filter(key=>key!==row.key);
        const opIndex=Math.floor(row.sequence/10);
        const name=this.operationName(row)||`第 ${opIndex} 道工序`;
        if(!options.silent){
          this.$message.success(`【${name}】配置已完成并收起`);
        }
        return true;
      }
      return false;
    },
    openItems(mode,row=null){if(this.viewOnly)return;this.pickerTarget={mode,row};const multiple=['materials','packaging','shared'].includes(mode);const materials=mode==='packaging'?row.packaging_materials:(mode==='materials'?this.incomingRules(row):(mode==='shared'?this.form.applicable_items.map(item=>({component_item_id:item.id,item})):[]));const selected=materials.map(material=>material.item||this.items[material.component_item_id]||{id:material.component_item_id,item_name:this.itemName(material.component_item_id)});this.$refs.itemPicker.open({currentId:null,multiple,selected,params:{management_scope:'factory',is_purchase_item:undefined},title:mode==='packaging'?'选择包装用料':mode==='materials'?'选择工序用料':mode==='shared'?'选择适用物料':'选择物料'})},
    selectItem(item){if(!this.pickerTarget||this.viewOnly)return;this.rememberItem(item)},
    selectMaterials(items){if(this.pickerTarget&&this.pickerTarget.mode==='shared'){items.forEach(item=>this.rememberItem(item));this.form.applicable_items=items.map(item=>({id:item.id,item_code:item.item_code,item_name:item.item_name,item_type:item.item_type,management_scope:item.management_scope}));return}if(!this.pickerTarget||!this.form.operations.includes(this.pickerTarget.row))return;const {row,mode}=this.pickerTarget;items.forEach(item=>{this.rememberItem(item);if(mode==='packaging'){if(!row.packaging_materials.some(material=>Number(material.component_item_id)===Number(item.id)))row.packaging_materials.push({component_item_id:item.id,base_qty_per_output_unit:''})}else if(!this.incomingRules(row).some(r=>Number(r.component_item_id)===Number(item.id)))row.material_supply_rules.push({component_item_id:item.id,target_key:row.key,percent:100,supply_mode:'dedicated_delivery',participates_in_kitting:true,allow_partial_delivery:false,delivery_location_type:'operation_station'})})},
    selectProduct({mode,row}){if(mode==='product'){this.form.product_id=row.id;this.form.product=row;this.form.sku_id=null;this.form.sku=null}else{this.form.sku_id=row.id;this.form.sku=row;this.form.product_id=row.product_id;this.form.product=row.product||{product_name:row.product_name||''}}},
    validateOperation(row){
      const opIndex=Math.floor(row.sequence/10);
      if(!row.operation_id){this.$message.warning(`请为第 ${opIndex} 道工序选择工序名称`);return false}
      if(row.execution_context!=='shipment'&&row.output_mode!=='flow_only'&&!this.isLastProduction(row)){this.$message.warning(`第 ${opIndex} 道工序不是最后一道车间工序，只能直接交接；入库只能设在最后一道车间工序`);return false}
      if([row.setup_hours,row.unit_hours].some(value=>value===''||value===null||!Number.isFinite(Number(value))||Number(value)<0)){this.$message.warning(`第 ${opIndex} 道工序的工时要填大于或等于 0 的小时数`);return false}
      if(row.execution_context==='shipment'){
        if(row.shipment_operation_type==='packing'&&!row.packaging_scheme_id){this.$message.warning(`第 ${opIndex} 道打包工序必须选择包装方案`);return false}
        if(row.shipment_operation_type!=='packing'&&(row.packaging_scheme_id||row.packaging_materials.length)){this.$message.warning(`第 ${opIndex} 道试水或装车工序不能配置包装方案或包装用料`);return false}
        if(row.material_supply_rules.length||this.incomingRules(row).length){this.$message.warning('发货作业不能使用生产 BOM 用料规则');return false}
        if(row.packaging_materials.some(material=>!/^\d+(?:\.\d+)?$/.test(String(material.base_qty_per_output_unit))||Number(material.base_qty_per_output_unit)<=0||Number(material.base_qty_per_output_unit)>999999999)){this.$message.warning('请填写包装用料每件的基础单位数量，必须大于 0');return false}
      }else if(row.material_supply_rules.some(rule=>!validPercent(rule.percent)||emptyPercent(rule.percent)||Number(rule.percent)<=0||!this.form.operations.some(target=>target.key===rule.target_key&&target.execution_context==='production'))){this.$message.warning('生产用料须分配到生产工序，用量占比须大于 0 且不超过 100%');return false}
      return true
    },
    payload(){
      return {routing_name:this.form.routing_name,product_id:this.form.product_id,sku_id:this.form.sku_id,remark:this.form.remark,
        applicable_item_ids:this.form.sku_id?[]:this.form.applicable_items.map(item=>item.id),applicable_category_ids:this.form.sku_id?[]:this.form.applicable_category_ids,
        operations:this.form.operations.map(row=>{
          const operation={operation_id:row.operation_id,production_stage_id:row.production_stage_id||null,execution_context:row.execution_context,sequence:row.sequence,is_key_operation:row.is_key_operation,
            parameters:{...(row.parameters||{}),text:row.parameters_text},remark:row.remark||null,setup_standard_minutes:toMinutes(row.setup_hours),unit_standard_minutes:toMinutes(row.unit_hours),standard_minutes:toMinutes(row.unit_hours),
            output_mode:row.output_mode,quality_mode:row.quality_mode,work_mode:row.work_mode,allow_continue_without_warehouse:row.allow_continue_without_warehouse}
          // 保留明细标识，后端可按原节点保留当前账号不可见的绩效配置。
          if(Number(row.id)>0)operation.id=Number(row.id)
          if(row.execution_context==='shipment'){
            operation.shipment_operation_type=row.shipment_operation_type
            operation.packaging_scheme_id=row.packaging_scheme_id
            operation.packaging_materials=row.packaging_materials.map(material=>({component_item_id:material.component_item_id,base_qty_per_output_unit:Number(material.base_qty_per_output_unit)}))
          }else{
            operation.material_supply_rules=row.material_supply_rules.map(rule=>({component_item_id:rule.component_item_id,target_sequence:(this.form.operations.find(target=>target.key===rule.target_key)||{}).sequence,required_qty_ratio:Number(rule.percent)/100,
              supply_mode:rule.supply_mode,requires_delivery:rule.supply_mode==='dedicated_delivery',participates_in_kitting:rule.participates_in_kitting,allow_partial_delivery:rule.allow_partial_delivery,delivery_location_type:rule.delivery_location_type}))
          }
          return operation
        })}
    },
    async save(activate){
      if(this.loading||this.viewOnly)return
      // 启用响应丢失时只重试原启用命令，不能先重复保存已启用的版本。
      if(activate&&this.pendingActivation){this.loading=true;try{await this.finishActivation();await this.load()}catch(e){this.showSaveError(e)}finally{this.loading=false}return}
      if(!await this.$refs.form.validate().catch(()=>false))return
      if(!this.form.sku_id&&!this.form.applicable_items.length&&!(this.form.applicable_category_ids||[]).length)return this.$message.warning('请至少选择一个适用物料或适用类目')
      if(!this.form.operations.length)return this.$message.warning('请至少添加一道工序')
      if(!this.form.operations.every(this.validateOperation))return
      const common=this.payload();const signature=JSON.stringify(common)
      // 同一表单的网络重试复用命令号，避免已提交但丢失响应时重复生成路线。
      if(!this.pendingSave||this.pendingSave.signature!==signature)this.pendingSave={signature,id:`routing-save-${uuid()}`}
      this.loading=true
      try{
        const id=this.id
        const savePayload=id?{...common,expected_version:this.form.business_version}:{...common,creation_session_id:this.sessionId,reservation_token:this.reservationToken}
        const r=await executeProductionDecision('routing_save_'+(id||this.sessionId),savePayload,payload=>id?updateProductionRouting(id,payload):createProductionRouting(payload))
        if(!id)clearCreatePageReservation(this.reservation)
        this.savedId=r.data.data.id;this.form.business_version=r.data.data.business_version;this.pendingSave=null
        if(activate){
          this.pendingActivation={client_command_id:`routing-activate-${uuid()}`,expected_version:this.form.business_version}
          await this.finishActivation()
        }
        this.$message.success(activate?'工艺路线已启用':'工艺路线草稿已保存');const path=`/production/routings/${this.id}${activate?'':'/edit'}`;if(this.pageRoute.path!==path)await this.$router.replace(path);await this.load()
      }catch(e){this.showSaveError(e)}finally{this.loading=false}
    },
    async finishActivation(){const activated=await executeProductionDecision('routing_activate_'+this.id,this.pendingActivation,payload=>activateProductionRouting(this.id,payload));this.form.status=activated.data.data.status;this.pendingActivation=null},
    showSaveError(e){if(e.response&&e.response.status>=400&&e.response.status<500)this.pendingActivation=null;this.$message.error(e.userMessage||e.response&&e.response.data&&e.response.data.message||'保存失败')}
  }
}
</script>
<style scoped src="./production-master.css"></style>
<style scoped>
.field-hint{display:block;margin-top:4px;font-size:12px;line-height:1.4;color:#8a94a6;font-weight:normal}
.routing-editor {
  padding: 16px 20px 30px;
  background: #f8fafc;
  min-height: calc(100vh - 52px);
  box-sizing: border-box;
}

/* 顶部页面标头 (.page-head) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 12px;
  flex-wrap: wrap;
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
  flex-shrink: 0;
}
.head-title-wrap {
  display: flex;
  flex-direction: column;
  gap: 4px;
}
.title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}
.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
}
.head-tag {
  border-radius: 4px;
  font-weight: 500;
}
.sub-row {
  font-size: 13px;
  color: #64748b;
}
.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}
.btn-back {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}
.btn-back:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}
.btn-theme-plain {
  border-color: #008b4b !important;
  color: #008b4b !important;
  background: #ffffff !important;
}
.btn-theme-plain:hover {
  background: #f0fdf4 !important;
}
.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}
.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 卡片容器 */
.routing-editor .form-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  padding: 18px 20px;
}
.metadata {
  border-radius: 8px 8px 0 0;
}
.operation-card {
  border-top: 0;
  border-radius: 0 0 8px 8px;
}
.card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #e2e8f0;
}
.card-title {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}
.card-title::before {
  content: "";
  display: inline-block;
  width: 3px;
  height: 14px;
  background: #008b4b;
  border-radius: 2px;
}
.perf-tag {
  font-weight: normal;
  margin-left: 6px;
}

.seq-num-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 28px;
  height: 22px;
  padding: 0 6px;
  border-radius: 4px;
  background: #f1f5f9;
  color: #334155;
  font-weight: 700;
  font-size: 12px;
  transition: all 0.2s ease;
}
.routing-editor ::v-deep .el-table .row-is-expanded td {
  background-color: #f0fdf4 !important;
}
.routing-editor ::v-deep .el-table .row-is-expanded .seq-num-badge {
  background: #008b4b;
  color: #ffffff;
}
.routing-editor ::v-deep .el-table .row-view-clickable {
  cursor: pointer;
}

.sequence-control {
  display: flex;
  align-items: center;
  gap: 8px;
}
.sequence-control .el-button {
  width: 22px;
  height: 22px;
  padding: 0;
  flex-shrink: 0;
}
.sequence-control .el-input {
  min-width: 0;
}
.routing-editor ::v-deep .native-expand {
  padding: 0;
  border-right: 0;
}
.routing-editor ::v-deep .native-expand .cell {
  padding: 0;
  width: 0;
  overflow: hidden;
}
.settings-group ::v-deep .el-input__inner {
  height: 32px;
  line-height: 32px;
}
.settings-group ::v-deep .el-input__icon {
  line-height: 32px;
}
.material-group .el-input-number {
  width: 94px !important;
}
.material-group ::v-deep .el-input-number .el-input__inner {
  padding: 0 5px;
}
.material-group-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  flex-wrap: wrap;
  margin-bottom: 10px;
}
.material-group-title {
  display: flex;
  align-items: center;
  gap: 8px;
}
.material-group-title h3 {
  margin: 0 !important;
}
.material-count-tag {
  font-weight: 600;
}
.material-group-tools {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.material-filter-input {
  width: 150px;
}
.material-filter-input ::v-deep .el-input__inner {
  height: 28px !important;
  line-height: 28px !important;
}
.material-filter-input ::v-deep .el-input__icon {
  line-height: 28px !important;
}
.material-expand-btn {
  padding: 0 4px !important;
  color: #008b4b !important;
}
.compact-material-table ::v-deep th,
.compact-material-table ::v-deep td {
  padding: 5px 0 !important;
  height: 34px !important;
}
.catalog-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  justify-content: flex-end;
}
.catalog-actions .el-button {
  margin: 0;
}
.selector-field {
  display: flex;
  gap: 6px;
  align-items: center;
  min-width: 0;
}
.selector-field .el-input {
  min-width: 0;
  flex: 1;
}
.setting-note {
  color: #64748b;
  font-size: 12px;
  line-height: 1.5;
  margin: 4px 0 10px;
}

.operation-select-field {
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 4px;
  min-width: 0;
}
.operation-select-field .el-input {
  width: 100%;
  min-width: 0;
}
.selected-operation-tag {
  flex-shrink: 0;
}

.routing-editor .form-grid {
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px 24px;
}
.row-actions,
.setting-actions {
  display: flex;
  align-items: center;
  flex-shrink: 0;
}
.row-actions {
  justify-content: center;
  gap: 8px;
}
.row-actions .el-button {
  padding: 0;
  font-size: 12px;
  color: #008b4b;
}
.row-actions .el-button:hover {
  color: #00763f;
}
.btn-del {
  color: #ef4444 !important;
}
.btn-del:hover {
  color: #dc2626 !important;
}

.empty-routing-op {
  padding: 30px 0;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
}

.operation-settings {
  display: grid;
  grid-template-columns: 1fr 0.85fr 1.4fr;
  align-items: start;
  margin: -12px -16px;
  background: #ffffff;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  box-shadow: 0 2px 8px rgba(0, 139, 75, 0.05);
  overflow: hidden;
}

.op-panel-head {
  grid-column: 1 / -1;
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding: 10px 16px;
  background: #f0fdf4;
  border-bottom: 1px solid #bbf7d0;
  border-radius: 6px 6px 0 0;
}
.op-panel-meta {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}
.op-seq-pill {
  display: inline-flex;
  align-items: center;
  padding: 2px 10px;
  border-radius: 12px;
  background: #008b4b;
  color: #ffffff;
  font-size: 12px;
  font-weight: 600;
}
.op-panel-title {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}
.op-panel-tip {
  font-size: 12px;
  color: #008b4b;
  margin-left: 4px;
}
.op-panel-btns {
  display: flex;
  align-items: center;
  gap: 8px;
}
.settings-group {
  padding: 16px;
  border-right: 1px solid #e2e8f0;
  min-width: 0;
  align-self: stretch;
}
.settings-group:last-child {
  border: 0;
}
.settings-group h3 {
  margin: 0 0 12px;
  font-size: 13px;
  font-weight: 600;
  color: #0f172a;
}
.settings-group > label {
  display: grid;
  grid-template-columns: 80px minmax(0, 1fr);
  align-items: center;
  gap: 10px;
  margin-bottom: 12px;
  font-size: 12px;
  color: #475569;
}
.settings-group .el-select {
  width: 100%;
  min-width: 0;
}
.setting-actions {
  margin-top: 12px;
  gap: 8px;
  flex-wrap: wrap;
}
.remark {
  margin-top: 18px;
  margin-bottom: 0;
}
.routing-editor ::v-deep .el-table th {
  background: #f8fafc;
  color: #334155;
  font-weight: 600;
}
.routing-editor ::v-deep .el-table .cell {
  word-break: break-word;
  white-space: normal;
}
.routing-editor ::v-deep .el-table__expanded-cell {
  padding: 12px 16px;
  background: #f1f5f9;
}
.routing-editor ::v-deep .el-input.is-disabled .el-input__inner {
  color: #475569;
  background: #f8fafc;
}
.routing-editor ::v-deep .el-input-group__append {
  padding: 0 10px;
}
.routing-editor ::v-deep .el-form-item__label {
  font-size: 12px;
  padding-bottom: 4px;
  line-height: 18px;
  color: #475569;
  font-weight: 600;
}
.routing-editor ::v-deep .el-form-item {
  margin-bottom: 14px;
}

@media (max-width: 1500px) {
  .operation-settings {
    grid-template-columns: 1fr 1fr;
  }
  .material-group {
    grid-column: 1 / -1;
  }
  .settings-group:nth-child(2) {
    border-right: 0;
  }
  .settings-group.material-group {
    border-top: 1px solid #e2e8f0;
  }
}
@media (max-width: 1100px) {
  .routing-editor .form-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
@media (max-width: 767px) {
  .routing-editor .form-grid,
  .operation-settings {
    grid-template-columns: minmax(0, 1fr);
  }
  .routing-editor {
    padding: 12px;
  }
  .settings-group {
    border-right: 0;
    border-bottom: 1px solid #e2e8f0;
  }
  .head-actions {
    width: 100%;
    flex-wrap: wrap;
    gap: 8px;
  }
  .head-actions .el-button {
    margin: 0;
  }
  .settings-group > label {
    grid-template-columns: 72px minmax(0, 1fr);
  }
  .setting-actions {
    gap: 8px;
    flex-wrap: wrap;
  }
  .material-filter-input {
    width: 100%;
  }
}
</style>
