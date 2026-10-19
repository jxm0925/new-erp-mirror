<template>
  <section class="packing-panel" v-loading="loading">
    <div class="packing-head">
      <h3>包装作业</h3>
      <div class="packing-actions">
        <el-button v-if="editable && $can('sales_order.shipment.packing.configure')" size="small" type="success" icon="el-icon-setting" :disabled="busy" @click="openPlan">{{ pendingPlan ? '查询原方案结果' : '设置包装及包裹' }}</el-button>
        <el-button size="small" icon="el-icon-refresh" :disabled="busy" @click="load">刷新</el-button>
      </div>
    </div>
    <el-alert v-if="error" :title="error" type="error" :closable="false" show-icon />
    <div v-for="line in blockedProductionLines" :key="'continuation-' + line.shipment_line_id" class="packing-continuation">
      <strong>{{ line.product_name || line.item_name }}</strong>
      <p v-for="message in line.production_blockers" :key="message">{{ message }}</p>
      <el-button v-for="row in line.continuations" v-if="row.target_work_order_id && $can('production.work_order.view')" :key="row.source_output_record_id + ':' + (row.inventory_serial_id || 0)" type="text" @click="openContinuation(row)">{{ row.continuation_mode === 'same_work_order' ? '办理原工单续接领用' : '绑定库存并继续生产' }}</el-button>
    </div>
    <el-table :data="operations" border size="small" class="packing-table">
      <el-table-column prop="package_no" label="包裹编号" min-width="160" />
      <el-table-column prop="operation_name" label="工序" min-width="110"><template slot-scope="{ row }"><div>{{ row.operation_name }}</div><el-tag v-if="isPublicSnapshot(row.is_public_snapshot)" size="mini" type="success">公共工序</el-tag></template></el-table-column>
      <el-table-column label="产品及数量" min-width="175"><template slot-scope="{ row }"><div v-for="content in row.contents" :key="content.id">{{ content.product_name || content.item_name }} · {{ qty(content.base_qty) }} · {{ content.packaging_scheme_name }}</div></template></el-table-column>
      <el-table-column label="接单人" min-width="100"><template slot-scope="{ row }">{{ ownerName(row) }}</template></el-table-column>
      <el-table-column label="状态" width="100"><template slot-scope="{ row }"><el-tag size="mini" :type="row.status === 'COMPLETED' ? 'success' : 'warning'">{{ statusText(row.status) }}</el-tag></template></el-table-column>
      <el-table-column label="操作" min-width="145"><template slot-scope="{ row }"><el-button type="text" icon="el-icon-view" @click="openOperation(row)">查看作业</el-button><el-button v-if="row.allowed_actions.claim && $can('sales_order.shipment.packing.execute')" type="text" :disabled="busy" @click="execute(row, 'claim')">接单</el-button></template></el-table-column>
    </el-table>
    <div class="packing-pager"><el-pagination background :current-page="page" :page-size="10" :total="total" layout="total, prev, pager, next" @current-change="changePage" /></div>

    <el-dialog title="设置包装及包裹" :visible.sync="planOpen" append-to-body width="min(1100px, calc(100vw - 32px))" top="5vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false" :show-close="!busy" :close-on-press-escape="!busy">
      <div v-if="pendingPlan" class="packing-notice">上次保存结果待确认，当前内容已锁定。</div>
      <section v-if="!executionOnly && $can('sales_order.shipment.packing.configure')" class="packing-route-settings">
        <h4>本次包装工艺版本</h4>
        <div v-for="line in requirements.filter(row => row.packing_required)" :key="'route-' + line.shipment_line_id" class="packing-route-entry">
          <strong>{{ line.product_name || line.item_name }} / {{ line.batch_no }}</strong>
          <p>来源版本：{{ routeLabel(line.packing_route) }}</p>
          <template v-if="routeSelections[line.shipment_line_id]">
            <p>本次选用：{{ routeSelections[line.shipment_line_id].pending_snapshot ? '已提交，等待回读确认版本' : routeLabel(routeSelections[line.shipment_line_id].packing_route) }}</p>
            <el-input v-model.trim="routeSelections[line.shipment_line_id].reason" type="textarea" :rows="2" maxlength="500" :disabled="busy || pendingPlan" placeholder="请填写本次选用该包装版本的原因" />
            <el-table v-if="!routeSelections[line.shipment_line_id].pending_snapshot" :data="routeMaterialPreview(routeSelections[line.shipment_line_id])" border size="mini" class="packing-route-materials"><el-table-column prop="version_label" label="版本" width="100" /><el-table-column prop="operation_name" label="工序" min-width="110" /><el-table-column label="包装用料" min-width="180"><template slot-scope="{row}">{{ row.item_code }} {{ row.item_name }}</template></el-table-column><el-table-column prop="base_qty_per_output_unit" label="每件用量" width="115" /></el-table>
            <p v-if="!routeSelections[line.shipment_line_id].pending_snapshot && !routeSelections[line.shipment_line_id].packaging_materials.length" class="packing-balance">本次版本未配置强制包装用料。</p>
          </template>
          <p v-else>本次沿用来源版本。</p>
          <el-button size="small" :disabled="busy || pendingPlan" @click="openRoutePicker(line)">选择本次包装版本</el-button><el-button v-if="routeSelections[line.shipment_line_id]" type="text" :disabled="busy || pendingPlan" @click="$delete(routeSelections, line.shipment_line_id)">沿用来源版本</el-button>
        </div>
      </section>
      <div v-for="(parcel, parcelIndex) in planPackages" :key="parcel.uid" class="packing-parcel">
        <div class="packing-parcel-head"><b>包裹 {{ parcelIndex + 1 }}</b><el-button type="text" class="packing-danger" :disabled="busy || pendingPlan" @click="planPackages.splice(parcelIndex, 1)">移除包裹</el-button></div>
        <div class="packing-grid">
          <label>包裹编号<el-input v-model.trim="parcel.package_no" size="small" :disabled="busy || pendingPlan" /></label>
          <label>承运商<el-input v-model.trim="parcel.carrier_name" size="small" :disabled="busy || pendingPlan" /></label>
          <label>运单号<el-input v-model.trim="parcel.tracking_no" size="small" :disabled="busy || pendingPlan" /></label>
        </div>
        <div class="packing-table-scroll">
          <el-table :data="parcel.contents" border size="mini">
            <el-table-column label="本次发货产品 / 来源批次" min-width="235"><template slot-scope="{ row }"><el-select v-model="row.shipment_line_id" size="small" :disabled="busy || pendingPlan" @change="resetContent(row)"><el-option v-for="line in requirements" :key="line.shipment_line_id" :value="line.shipment_line_id" :label="`${line.product_name || line.item_name} / ${line.batch_no} / ${qty(line.base_qty)}`" /></el-select></template></el-table-column>
            <el-table-column label="包装方案" min-width="150"><template slot-scope="{ row }"><el-select v-if="requirement(row).packing_required" v-model="row.packaging_scheme_id" size="small" :disabled="busy || pendingPlan" placeholder="请选择"><el-option v-for="scheme in requirement(row).schemes" :key="scheme.id" :value="scheme.id" :label="scheme.name" /></el-select><span v-else>无需包装作业</span></template></el-table-column>
            <el-table-column label="数量" width="125"><template slot-scope="{ row }"><el-input-number v-model="row.base_qty" size="mini" :min="0" :max="Number(requirement(row).base_qty || 0)" :precision="8" :controls="false" :disabled="busy || pendingPlan || requirement(row).serial_tracking_mode === 'required'" /></template></el-table-column>
            <el-table-column label="设备编号 / 序列号" min-width="210"><template slot-scope="{ row }"><el-select v-if="(requirement(row).serial_ids || []).length" v-model="row.inventory_serial_ids" multiple size="small" :disabled="busy || pendingPlan" @change="changeContentSerials(row)"><el-option v-for="serial in requirement(row).serials" :key="serial.id" :value="Number(serial.id)" :label="serial.serial_no" /></el-select><span v-else>—</span></template></el-table-column>
            <el-table-column label="操作" width="70"><template slot-scope="{ $index }"><el-button type="text" class="packing-danger" :disabled="busy || pendingPlan" @click="parcel.contents.splice($index, 1)">移除</el-button></template></el-table-column>
          </el-table>
        </div>
        <el-button size="mini" icon="el-icon-plus" :disabled="busy || pendingPlan" @click="parcel.contents.push(newContent())">添加产品</el-button>
      </div>
      <el-button size="small" icon="el-icon-plus" :disabled="busy || pendingPlan" @click="addParcel">添加包裹</el-button>
      <div v-for="line in requirements" :key="line.shipment_line_id" class="packing-balance">{{ line.product_name || line.item_name }} / {{ line.batch_no }}：已分配 {{ allocated(line.shipment_line_id) }} / 本次发货 {{ qty(line.base_qty) }}</div>
      <el-alert v-if="planError" :title="planError" type="error" :closable="false" />
      <span slot="footer"><el-button size="small" :disabled="busy" @click="planOpen = false">关闭</el-button><el-button type="success" size="small" :disabled="!planReady && !pendingPlan" :loading="busy" @click="savePlan">{{ pendingPlan ? '查询原操作结果' : '保存包装方案' }}</el-button></span>
    </el-dialog>

    <el-dialog title="选择本次包装工艺版本" :visible.sync="routePicker.visible" append-to-body width="min(1000px, calc(100vw - 32px))" top="8vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <div class="packing-search"><el-input v-model.trim="routePicker.keyword" size="small" clearable placeholder="工艺编号 / 名称" @keyup.enter.native="searchRoutes" @clear="searchRoutes" /><el-button size="small" type="success" @click="searchRoutes">查询</el-button></div>
      <el-alert v-if="routePicker.error" :title="routePicker.error" type="error" :closable="false" />
      <el-table :data="routePicker.rows" v-loading="routePicker.loading" border size="small" highlight-current-row @row-click="routePicker.current = $event">
        <el-table-column width="55"><template slot-scope="{row}"><el-radio :value="routePicker.current && routePicker.current.id" :label="row.id" @change="routePicker.current = row">&nbsp;</el-radio></template></el-table-column><el-table-column prop="routing_no" label="工艺编号" min-width="150" /><el-table-column prop="routing_name" label="工艺名称" min-width="180" /><el-table-column prop="version" label="版本" width="90" />
      </el-table>
      <div class="packing-pager"><el-pagination :current-page="routePicker.page" :page-size="10" :total="routePicker.total" layout="total, prev, pager, next" :disabled="routePicker.loading" @current-change="routePage" /></div>
      <template v-if="routePicker.current"><p>来源版本：{{ routeLabel(routePicker.line && routePicker.line.packing_route) }}；本次选择：{{ routeLabel(routePicker.current) }}</p><el-table :data="routeMaterialPreview(routePicker.current)" border size="mini"><el-table-column prop="version_label" label="版本" width="100" /><el-table-column prop="operation_name" label="工序" min-width="110" /><el-table-column label="包装用料" min-width="180"><template slot-scope="{row}">{{ row.item_code }} {{ row.item_name }}</template></el-table-column><el-table-column prop="base_qty_per_output_unit" label="每件用量" width="115" /></el-table></template>
      <span slot="footer"><el-button size="small" @click="routePicker.visible = false">取消</el-button><el-button size="small" type="success" :disabled="routePicker.loading || !routePicker.current || busy || pendingPlan" @click="chooseRoute">确定选择</el-button></span>
    </el-dialog>

    <el-dialog :title="activeOperation.operation_name || '包装作业'" :visible.sync="operationOpen" append-to-body width="min(850px, calc(100vw - 32px))" top="5vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <el-tag v-if="isPublicSnapshot(activeOperation.is_public_snapshot)" size="mini" type="success">公共工序</el-tag>
      <div class="packing-grid"><div>包裹：{{ activeOperation.package_no }}</div><div>状态：{{ statusText(activeOperation.status) }}</div><div>接单人：{{ ownerName(activeOperation) }}</div></div>
      <div v-for="content in activeOperation.contents" :key="content.id" class="packing-balance">{{ content.product_name || content.item_name }} · {{ qty(content.base_qty) }} · {{ content.packaging_scheme_name }}<div v-if="content.serial_nos.length">{{ content.serial_nos.join('、') }}</div></div>
      <h4>参与人员及实际工时</h4>
      <el-table :data="activeOperation.participants || []" border size="small"><el-table-column prop="name" label="人员" /><el-table-column label="身份"><template slot-scope="{ row }">{{ row.role === 'OWNER' ? '接单人' : '协同人' }}{{ row.is_active ? '' : '（已移除）' }}</template></el-table-column><el-table-column prop="actual_labor_minutes" label="实际工时（分钟）" /><el-table-column label="计时状态"><template slot-scope="{ row }">{{ row.is_timing ? '计时中' : '已暂停' }}</template></el-table-column></el-table>
      <ProductionLaborSummaryPanel v-if="activeOperation.labor" :labor="activeOperation.labor" :busy="busy || laborLoading || pendingOperation" @page="loadPackingLabor" />
      <h4>实际用料</h4>
      <el-table :data="activeOperation.materials || []" border size="small"><el-table-column prop="item_name" label="物料" /><el-table-column prop="base_qty" label="实际耗用数量" /><el-table-column label="裁切尺寸 / 用料备注" min-width="180"><template slot-scope="{ row }">{{ usageText(row.usage_snapshot) }}</template></el-table-column><el-table-column prop="posted_at" label="过账时间" /></el-table>
      <el-alert v-if="operationError" :title="operationError" type="error" :closable="false" />
      <div slot="footer" class="packing-actions">
        <el-button size="small" :disabled="busy" @click="operationOpen = false">关闭</el-button>
        <el-button v-if="pendingOperation" type="success" size="small" :loading="busy" @click="execute(activeOperation, 'recover')">查询原操作结果</el-button>
        <template v-else>
          <el-button v-if="allow('claim')" size="small" type="success" :loading="busy" @click="execute(activeOperation, 'claim')">接单</el-button>
          <el-button v-if="allow('collaborators')" size="small" :disabled="busy" @click="openPeople">选择协同人</el-button>
          <el-button v-if="allow('start')" size="small" type="success" :loading="busy" @click="execute(activeOperation, 'start')">开始我的作业</el-button>
          <el-button v-if="allow('pause')" size="small" :loading="busy" @click="execute(activeOperation, 'pause')">暂停我的作业</el-button>
          <el-button v-if="allow('materials')" size="small" :disabled="busy" @click="openMaterials">登记实际用料</el-button>
          <el-button v-if="allow('complete')" size="small" type="success" :loading="busy" @click="complete">完成工序</el-button>
          <el-button v-if="allow('inspect')" size="small" type="success" :disabled="busy" @click="inspectionOpen = true">包装质检</el-button>
        </template>
      </div>
    </el-dialog>

    <el-dialog title="选择协同人" :visible.sync="peopleOpen" append-to-body width="min(850px, calc(100vw - 32px))" top="8vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <div class="packing-picker"><aside><el-tree :data="peopleTree" node-key="id" :props="{ label: 'name', children: 'children' }" default-expand-all @node-click="selectDepartment" /></aside><main>
        <div class="packing-search"><el-input v-model.trim="peopleQuery.keyword" size="small" clearable placeholder="姓名 / 账号" @keyup.enter.native="searchPeople" /><el-button size="small" type="success" @click="searchPeople">查询</el-button></div>
        <el-table :data="peopleRows" border size="small"><el-table-column width="60" label="选择"><template slot-scope="{ row }"><el-checkbox :value="Boolean(peopleSelected[row.legacy_id])" :disabled="busy || pendingOperation" @change="togglePerson(row, $event)" /></template></el-table-column><el-table-column prop="nickname" label="姓名" /><el-table-column prop="username" label="账号" /></el-table>
        <el-pagination background :current-page="peopleQuery.page" :page-size="10" :total="peopleTotal" layout="total, prev, pager, next" @current-change="peoplePage" />
      </main></div>
      <div class="packing-selected"><el-tag v-for="person in Object.values(peopleSelected)" :key="person.legacy_id" :closable="!busy && !pendingOperation" @close="togglePerson(person, false)">{{ person.nickname || person.username }}</el-tag></div>
      <div class="packing-share-head"><h4>个人份额</h4><el-button size="mini" :disabled="busy || pendingOperation" @click="rebalancePeopleShares">平分份额</el-button></div>
      <el-table :data="peopleShareRows" border size="small"><el-table-column label="参与人员" min-width="150"><template slot-scope="{ row }">{{ row.name }}<el-tag size="mini">{{ row.owner ? '接单人' : (row.pastParticipant ? '已参与' : '协同人') }}</el-tag></template></el-table-column><el-table-column prop="actual_labor_minutes" label="实际工时（分钟）" width="145" /><el-table-column label="个人份额（%）" min-width="150"><template slot-scope="{ row }"><el-input v-if="peopleShares[row.employee_legacy_id]" v-model="peopleShares[row.employee_legacy_id].percent" size="small" inputmode="decimal" :disabled="busy || pendingOperation" /></template></el-table-column></el-table>
      <div class="packing-balance">个人份额合计 {{ peopleShareTotal }}% / 100%。已登记的参与工时保留。</div>
      <el-alert v-if="peopleShareError" :title="peopleShareError" type="error" :closable="false" />
      <div slot="footer"><el-button size="small" :disabled="busy" @click="peopleOpen = false">取消</el-button><el-button size="small" type="success" :loading="busy" @click="savePeople">{{ pendingOperation ? '查询原操作结果' : '确认协同人及份额' }}</el-button></div>
    </el-dialog>

    <el-dialog title="登记实际用料" :visible.sync="materialsOpen" append-to-body width="min(1050px, calc(100vw - 32px))" top="5vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <div class="packing-requirements"><div v-for="material in activeOperation.material_requirements" :key="material.item_id">{{ material.item_name }} · 工艺用料数量 {{ qty(material.required_base_qty) }}</div></div>
      <div class="packing-picker"><aside><el-tree :data="materialTree" node-key="id" :props="{ label: 'name', children: 'children' }" default-expand-all @node-click="selectCategory" /></aside><main>
        <div class="packing-search"><el-input v-model.trim="materialQuery.keyword" size="small" clearable placeholder="物料编码 / 名称 / 规格" @keyup.enter.native="searchMaterials" /><el-button size="small" type="success" @click="searchMaterials">查询</el-button></div>
        <el-table :data="materialRows" border size="small"><el-table-column label="物料" min-width="165"><template slot-scope="{ row }">{{ row.item_code }} / {{ row.item_name }}<div>{{ row.spec }}</div></template></el-table-column><el-table-column label="库存来源" min-width="180"><template slot-scope="{ row }">{{ row.warehouse_name }} / {{ row.location_name }}<div>{{ row.batch_no }}</div></template></el-table-column><el-table-column prop="quantity_available" label="可用数量" width="95" /><el-table-column label="选择" width="85"><template slot-scope="{ row }"><el-button type="text" :disabled="Boolean(materialDrafts[row.id])" @click="addMaterial(row)">添加</el-button></template></el-table-column></el-table>
        <el-pagination background :current-page="materialQuery.page" :page-size="10" :total="materialTotal" layout="total, prev, pager, next" @current-change="materialPage" />
      </main></div>
      <h4>已选库存来源</h4>
      <div v-for="material in Object.values(materialDrafts)" :key="material.id" class="packing-material-entry">
        <div class="packing-material-draft"><div>{{ material.item_name }} / {{ material.batch_no }} / {{ material.unit_name }}</div><el-input-number v-model="material.base_qty" size="small" :controls="false" :min="0" :max="Number(material.quantity_available)" :precision="8" :disabled="material.physical || material.serial_tracking_mode === 'required' || busy || pendingOperation" /><el-button v-if="material.physical || material.serial_tracking_mode !== 'none'" size="small" :disabled="busy || pendingOperation" @click="openIdentities(material)">{{ material.physical ? '选择实物板料' : '选择序列号' }}（{{ material.identities.length }}）</el-button><el-button type="text" class="packing-danger" :disabled="busy || pendingOperation" @click="$delete(materialDrafts, material.id)">移除</el-button></div>
        <div class="packing-usage-dimensions">
          <label v-for="field in usageFields" :key="field.key">{{ field.label }}（mm）<el-input-number v-model="material.usage_dimensions[field.key]" size="small" :controls="false" :min="0" :precision="2" :disabled="busy || pendingOperation" /></label>
        </div>
        <div v-if="!material.physical && material.area" class="packing-area">
          <el-checkbox v-model="material.area.enabled" :disabled="busy || pendingOperation" @change="applyArea(material)">木板按面积扣库存</el-checkbox>
          <template v-if="material.area.enabled">
            <div class="packing-area-row">整板<el-input-number v-model="material.area.board_length_mm" size="mini" :controls="false" :min="1" :precision="2" placeholder="长 mm" @change="applyArea(material)" />×<el-input-number v-model="material.area.board_width_mm" size="mini" :controls="false" :min="1" :precision="2" placeholder="宽 mm" @change="applyArea(material)" />mm</div>
            <div v-for="(piece, index) in material.area.pieces" :key="index" class="packing-area-row">裁切块<el-input-number v-model="piece.length_mm" size="mini" :controls="false" :min="1" :precision="2" placeholder="长 mm" @change="applyArea(material)" />×<el-input-number v-model="piece.width_mm" size="mini" :controls="false" :min="1" :precision="2" placeholder="宽 mm" @change="applyArea(material)" />mm ×<el-input-number v-model="piece.qty" size="mini" :min="1" :step="1" step-strictly @change="applyArea(material)" />块<el-button v-if="material.area.pieces.length > 1" type="text" class="packing-danger" @click="material.area.pieces.splice(index, 1); applyArea(material)">删除</el-button></div>
            <div class="packing-area-row"><el-button type="text" icon="el-icon-plus" @click="material.area.pieces.push({ length_mm: undefined, width_mm: undefined, qty: 1 })">加裁切块</el-button><el-radio-group v-model="material.area.remnant" size="mini" @change="applyArea(material)"><el-radio label="return">余板回库（按面积扣）</el-radio><el-radio label="loss">余板算损耗（按整张扣）</el-radio></el-radio-group></div>
            <div class="packing-area-tip">{{ areaText(material) }}</div>
          </template>
        </div>
        <el-input v-model.trim="material.usage_remark" type="textarea" :rows="2" maxlength="500" :disabled="busy || pendingOperation" placeholder="裁切与用料备注（选填）" />
      </div>
      <el-alert v-if="materialError" :title="materialError" type="error" :closable="false" />
      <span slot="footer"><el-button size="small" :disabled="busy" @click="materialsOpen = false">取消</el-button><el-button size="small" type="success" :loading="busy" @click="saveMaterials">确认实际耗用</el-button></span>
    </el-dialog>

    <el-dialog :title="identityMaterial.physical ? '选择实物板料' : '选择序列号'" :visible.sync="identityOpen" append-to-body width="min(800px, calc(100vw - 32px))" top="8vh" custom-class="shipment-packing-dialog" :close-on-click-modal="false">
      <div class="packing-search"><el-input v-model="identityKeyword" size="small" clearable placeholder="搜索实物编号 / 序列号" @keyup.enter.native="searchIdentities" @clear="searchIdentities" /><el-button type="success" size="small" @click="searchIdentities">查询</el-button></div>
      <el-table :data="identityRows" border size="small"><el-table-column label="选择" width="60"><template slot-scope="{ row }"><el-checkbox :value="Boolean(identitySelected[row.id])" @change="toggleIdentity(row, $event)" /></template></el-table-column><el-table-column label="实物编号 / 序列号"><template slot-scope="{ row }">{{ row.physical_no || row.serial_no }}</template></el-table-column><el-table-column v-if="identityMaterial.physical" label="真实尺寸（mm）"><template slot-scope="{ row }">{{ row.length_mm }} × {{ row.width_mm }} × {{ row.thickness_mm }}</template></el-table-column></el-table>
      <el-pagination background :current-page="identityPage" :page-size="10" :total="identityTotal" layout="total, prev, pager, next" @current-change="changeIdentityPage" />
      <div class="packing-selected"><el-tag v-for="row in Object.values(identitySelected)" :key="row.id" closable @close="$delete(identitySelected, row.id)">{{ row.physical_no || row.serial_no }}</el-tag></div>
      <span slot="footer"><el-button size="small" @click="identityOpen = false">取消</el-button><el-button size="small" type="success" @click="confirmIdentities">确认选择</el-button></span>
    </el-dialog>
    <el-dialog title="包装质检" :visible.sync="inspectionOpen" append-to-body width="min(560px, calc(100vw - 32px))" top="12vh" :close-on-click-modal="false">
      <el-radio-group v-model="inspection.result"><el-radio label="passed">合格</el-radio><el-radio label="failed">不合格，退回加工</el-radio></el-radio-group><el-input v-model.trim="inspection.reason" type="textarea" :rows="3" maxlength="500" placeholder="检验说明" class="packing-inspection-reason" />
      <span slot="footer"><el-button size="small" @click="inspectionOpen = false">取消</el-button><el-button size="small" type="success" :loading="busy" @click="saveInspection">确认检验结果</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { getShipmentPacking, configureShipmentPacking, listPackingOperations, getPackingOperation, actPackingOperation, listPackingPeople, listPackingMaterials, listPackingIdentities, listPackingRoutings, pendingPackingCommand } from '@/api/erp/shipment-packing'
import { defaultPerformanceShares, ratioToPercent, buildPerformanceShares, performanceSelectionTotals } from '@/utils/production-performance.mjs'
import ProductionLaborSummaryPanel from '../production/ProductionLaborSummaryPanel.vue'

let localId = 0
const uid = () => `packing-${++localId}`
const tree = (rows, label) => {
  const nodes = rows.map(row => ({ ...row, name: row[label], children: [] }))
  const byId = Object.fromEntries(nodes.map(row => [row.id, row]))
  const roots = []
  nodes.forEach(row => { const parent = byId[row.parent_id]; if (parent && parent !== row) parent.children.push(row); else roots.push(row) })
  return [{ id: 0, name: '全部', children: roots }]
}
export default {
  name: 'ShipmentPackingPanel',
  components: { ProductionLaborSummaryPanel },
  props: { shipmentId: { type: Number, default: 0 }, shipmentStatus: String, executionOnly: { type: Boolean, default: false } },
  data: () => ({ loading: false, busy: false, error: '', page: 1, total: 0, operations: [], requirements: [], packingVersion: 1, loadSequence: 0,
    planOpen: false, planReady: false, planPackages: [], planError: '', pendingPlan: false, routeSelections: {}, routeSequence: 0, routePicker: { visible: false, line: null, keyword: '', page: 1, rows: [], total: 0, current: null, loading: false, error: '' }, operationOpen: false, activeOperation: {}, operationError: '', pendingOperation: false, laborLoading: false, laborSequence: 0,
    peopleOpen: false, peopleRows: [], peopleSelected: {}, peopleShares: {}, peopleShareError: '', peopleTree: [], peopleTotal: 0, peopleQuery: { keyword: '', department_id: 0, page: 1 },
    materialsOpen: false, materialRows: [], materialTree: [], materialTotal: 0, materialDrafts: {}, materialError: '', materialQuery: { keyword: '', category_id: 0, page: 1 },
    identityOpen: false, identityRows: [], identitySelected: {}, identityMaterial: {}, identityPage: 1, identityTotal: 0, identityKeyword: '',
    inspectionOpen: false, inspection: { result: 'passed', reason: '' } }),
  computed: {
    packingContext () { return `${this.executionOnly ? 'execution' : 'shipment'}:${this.shipmentId}` },
    usageFields () { return [{ key: 'length_mm', label: '裁切长' }, { key: 'width_mm', label: '裁切宽' }, { key: 'thickness_mm', label: '厚度' }] },
    blockedProductionLines () { return this.requirements.filter(line => line.production_ready === false) },
    editable () { return !this.executionOnly && ['draft', 'pending_outbound', 'outbound_posted'].includes(this.shipmentStatus) },
    peopleShareTotal () { return performanceSelectionTotals(Object.values(this.peopleShares)).declared },
    peopleShareRows () {
      const recorded = this.activeOperation.participants || []
      const byId = Object.fromEntries(recorded.map(row => [row.employee_legacy_id, row]))
      const rows = {}
      const owner = recorded.find(row => row.role === 'OWNER')
      if (owner) rows[owner.employee_legacy_id] = { employee_legacy_id: owner.employee_legacy_id, name: owner.name, owner: true, actual_labor_minutes: owner.actual_labor_minutes }
      for (const person of Object.values(this.peopleSelected)) {
        const actual = byId[person.legacy_id]
        rows[person.legacy_id] = { employee_legacy_id: Number(person.legacy_id), name: person.nickname || person.username || actual?.name,
          actual_labor_minutes: actual?.actual_labor_minutes || 0 }
      }
      // Removing future collaboration does not remove already recorded work
      // from the current operation's 100% allocation.
      for (const person of recorded) if (!rows[person.employee_legacy_id] && (person.has_labor || person.is_timing || Number(person.actual_labor_minutes) > 0)) {
        rows[person.employee_legacy_id] = { employee_legacy_id: person.employee_legacy_id, name: person.name,
          actual_labor_minutes: person.actual_labor_minutes, pastParticipant: true }
      }
      return Object.values(rows).sort((a, b) => a.employee_legacy_id - b.employee_legacy_id)
    }
  },
  watch: { packingContext: { immediate: true, handler () { this.loadSequence++; this.page = 1; this.operations = []; this.total = 0; this.requirements = []; this.packingVersion = 1; this.operationOpen = false; this.activeOperation = {}; this.operationError = ''; this.pendingOperation = false; this.planOpen = false; this.planPackages = []; this.routeSelections = {}; this.planError = ''; this.pendingPlan = false; this.error = ''; this.loading = false; this.resetSelectors(); this.load() } }, planOpen(value) { if (!value) { this.routeSequence++; this.routePicker.visible = false } } },
  beforeDestroy () { this.loadSequence++; this.resetSelectors() },
  methods: {
    usageText (snapshot) { const area = snapshot?.area_usage; if (area) return `木板 ${area.board_length_mm}×${area.board_width_mm} mm：用料 ${area.used_area_m2} m²，扣 ${Number(area.consumed_boards)} 张，${area.remnant === 'loss' ? '损耗 ' + area.loss_area_m2 + ' m²' : '余板回库'}${snapshot.remark ? '；' + snapshot.remark : ''}`; const d = snapshot?.dimensions || {}; const size = [['length_mm', '长'], ['width_mm', '宽'], ['thickness_mm', '厚']].filter(([key]) => d[key]).map(([key, label]) => `${label}${d[key]} mm`).join(' × '); return [size, snapshot?.remark].filter(Boolean).join('；') || '—' },
    openContinuation (row) {
      this.$router.push({ path: `/production/work-orders/${row.target_work_order_id}`, query: {
        continuation_output_id: String(row.source_output_record_id), continuation_balance_id: String(row.inventory_balance_id || ''),
        continuation_serial_id: String(row.inventory_serial_id || '')
      } })
    },
    isPublicSnapshot (value) { return value === true || value === 1 || value === '1' },
    resetSelectors () { this.operationSequence = (this.operationSequence || 0) + 1; this.peopleSequence = (this.peopleSequence || 0) + 1; this.materialSequence = (this.materialSequence || 0) + 1; this.identitySequence = (this.identitySequence || 0) + 1; this.laborSequence++; this.routeSequence++; this.routePicker.visible = false; this.laborLoading = false; this.peopleOpen = false; this.peopleShares = {}; this.peopleShareError = ''; this.materialsOpen = false; this.identityOpen = false; this.inspectionOpen = false },
    qty (value) { return Number(value || 0).toLocaleString('zh-CN', { maximumFractionDigits: 8 }) },
    statusText (value) { return ({ READY: '待加工', WAITING: '等待前序', IN_PROGRESS: '加工中', WAIT_QUALITY: '待质检', COMPLETED: '已完成', CANCELLED: '已取消' })[value] || value || '—' },
    ownerName (op) { const owner = (op.participants || []).find(row => row.role === 'OWNER'); return owner ? owner.name : '未接单' },
    async load () {
      if (!this.executionOnly && !this.shipmentId) return
      const sequence = ++this.loadSequence
      const executionOnly = this.executionOnly
      this.loading = true; this.error = ''
      try {
        const params = { page: this.page, per_page: 10 }
        const { data } = executionOnly ? await listPackingOperations(params) : await getShipmentPacking(this.shipmentId, params)
        if (sequence !== this.loadSequence) return
        const result = data.data
        this.operations = executionOnly ? result.data : result.operations.data
        this.total = executionOnly ? result.total : result.operations.total
        this.requirements = executionOnly ? [] : result.requirements
        this.packingVersion = executionOnly ? 1 : result.packing_version
        this.pendingPlan = !executionOnly && Boolean(pendingPackingCommand('configure', this.shipmentId))
      } catch (e) { if (sequence === this.loadSequence) this.error = e.userMessage || '包装作业读取失败' } finally { if (sequence === this.loadSequence) this.loading = false }
    },
    changePage (page) { this.page = page; this.load() },
    newContent (line = this.requirements[0]) { return { uid: uid(), shipment_line_id: line && line.shipment_line_id, base_qty: line ? Number(line.base_qty) : 0, packaging_scheme_id: line && line.schemes.length === 1 ? line.schemes[0].id : null, inventory_serial_ids: line ? [...line.serial_ids] : [] } },
    requirement (content) { return this.requirements.find(row => row.shipment_line_id === content.shipment_line_id) || { schemes: [], serial_ids: [], serials: [] } },
    resetContent (content) { const replacement = this.newContent(this.requirement(content)); Object.assign(content, replacement) },
    changeContentSerials (content) { if (this.requirement(content).serial_tracking_mode === 'required') content.base_qty = content.inventory_serial_ids.length },
    allocated (id) { return this.qty(this.planPackages.reduce((sum, parcel) => sum + parcel.contents.filter(row => row.shipment_line_id === id).reduce((total, row) => total + Number(row.base_qty || 0), 0), 0)) },
    routeLabel(route) { return route ? [route.routing_no, route.routing_name, route.version == null ? '' : `V${route.version}`].filter(Boolean).join(' / ') || '版本待回读' : '来源版本未提供' },
    routeMaterialPreview(row) { return (row.before_materials || []).map(item => ({ ...item, version_label: '来源版本' })).concat((row.packaging_materials || []).map(item => ({ ...item, version_label: '本次版本' }))) },
    openRoutePicker(line) {
      if (this.busy || this.pendingPlan || !this.planOpen || this.executionOnly || !this.$can('sales_order.shipment.packing.configure')) return
      this.routeSequence++; this.routePicker = { visible: true, line, keyword: '', page: 1, rows: [], total: 0, current: null, loading: false, error: '' }; this.loadRoutes()
    },
    async loadRoutes() {
      const picker = this.routePicker; const context = this.packingContext; const sequence = ++this.routeSequence
      if (!picker.visible || !picker.line || !this.planOpen || this.executionOnly) return
      picker.loading = true; picker.error = ''
      try { const { data } = await listPackingRoutings(this.shipmentId, { shipment_line_id: picker.line.shipment_line_id, keyword: picker.keyword, page: picker.page, per_page: 10 }); if (picker === this.routePicker && sequence === this.routeSequence && context === this.packingContext && picker.visible && this.planOpen) { picker.rows = data.data.data; picker.total = Number(data.data.total) } }
      catch (e) { if (picker === this.routePicker && sequence === this.routeSequence && context === this.packingContext) picker.error = e.userMessage || '包装工艺版本读取失败' }
      finally { if (picker === this.routePicker && sequence === this.routeSequence) picker.loading = false }
    },
    searchRoutes() { this.routePicker.page = 1; this.routePicker.current = null; this.loadRoutes() },
    routePage(page) { this.routePicker.page = page; this.loadRoutes() },
    chooseRoute() {
      const picker = this.routePicker; const row = picker.current
      if (this.busy || this.pendingPlan || picker.loading || !picker.visible || !this.planOpen || !row) return
      const previous = this.routeSelections[picker.line.shipment_line_id]
      this.$set(this.routeSelections, picker.line.shipment_line_id, { shipment_line_id: picker.line.shipment_line_id, production_routing_id: row.id, expected_routing_version: row.business_version, reason: previous?.production_routing_id === row.id && previous?.expected_routing_version === row.business_version ? previous.reason : '', packing_route: { id: row.id, routing_no: row.routing_no, routing_name: row.routing_name, version: row.version }, before_materials: row.before_materials || [], packaging_materials: row.packaging_materials || [] })
      picker.visible = false
    },
    addParcel () { this.planPackages.push({ uid: uid(), package_no: '', carrier_name: '', tracking_no: '', contents: [this.newContent()] }) },
    async openPlan () {
      if (this.busy || this.executionOnly || !this.shipmentId || !this.$can('sales_order.shipment.packing.configure')) return
      const context = this.packingContext; const sequence = this.operationSequence
      this.busy = true; this.planError = ''; this.planReady = false; this.planOpen = true; this.routeSelections = {}; this.planPackages = []
      this.pendingPlan = Boolean(pendingPackingCommand('configure', this.shipmentId))
      try {
        const { data } = await getShipmentPacking(this.shipmentId, { plan: 1, per_page: 1 })
        if (context !== this.packingContext || sequence !== this.operationSequence || !this.planOpen) return
        const result = data.data
        this.packingVersion = result.packing_version; this.requirements = result.requirements
        const pending = pendingPackingCommand('configure', this.shipmentId)
        this.pendingPlan = Boolean(pending)
        const savedRoutes = result.packing_route_selections || []
        const selections = pending ? (pending.payload.packing_route_selections || []).map(row => ({ ...(savedRoutes.find(saved => Number(saved.shipment_line_id) === Number(row.shipment_line_id) && Number(saved.production_routing_id) === Number(row.production_routing_id) && Number(saved.expected_routing_version) === Number(row.expected_routing_version)) || { pending_snapshot: true }), ...row })) : savedRoutes
        this.routeSelections = Object.fromEntries(selections.map(row => [row.shipment_line_id, { ...row }]))
        const parcels = pending ? pending.payload.packages : result.plan_packages
        this.planPackages = parcels && parcels.some(row => row.contents.length) ? parcels.map(row => ({ ...row, uid: uid(), contents: row.contents.map(content => ({ ...content, uid: uid() })) })) : [{ uid: uid(), package_no: '', carrier_name: '', tracking_no: '', contents: this.requirements.map(line => this.newContent(line)) }]
        this.planReady = true
      } catch (e) { if (context === this.packingContext && sequence === this.operationSequence) this.planError = e.userMessage || '包装方案读取失败' }
      finally { this.busy = false }
    },
    async savePlan () {
      if (this.busy || this.executionOnly || !this.shipmentId || !this.planReady && !this.pendingPlan) return
      if (!this.pendingPlan && Object.values(this.routeSelections).some(row => !String(row.reason || '').trim() || String(row.reason).trim().length > 500 || !Number.isInteger(Number(row.expected_routing_version)) || Number(row.expected_routing_version) < 1)) { this.planError = '请核对所选包装版本，并填写不超过500字的选用原因。'; return }
      const context = this.packingContext; const sequence = this.operationSequence
      this.busy = true; this.planError = ''
      try { await configureShipmentPacking(this.shipmentId, { expected_version: this.packingVersion, packing_route_selections: Object.values(this.routeSelections).map(row => ({ shipment_line_id: row.shipment_line_id, production_routing_id: row.production_routing_id, expected_routing_version: row.expected_routing_version, reason: String(row.reason || '').trim() })), packages: this.planPackages.map(parcel => ({ package_no: parcel.package_no, carrier_name: parcel.carrier_name, tracking_no: parcel.tracking_no, weight: parcel.weight, volume: parcel.volume, contents: parcel.contents.map(row => ({ shipment_line_id: row.shipment_line_id, packaging_scheme_id: row.packaging_scheme_id, base_qty: row.base_qty, inventory_serial_ids: row.inventory_serial_ids })) })) }); if (context !== this.packingContext || sequence !== this.operationSequence) return; this.planOpen = false; this.$message.success('包装方案已保存'); await this.load(); if (context === this.packingContext && sequence === this.operationSequence) this.$emit('refresh') } catch (e) { if (context === this.packingContext && sequence === this.operationSequence) { this.planError = e.userMessage || '包装方案保存失败'; this.pendingPlan = Boolean(e.pendingCommand) } } finally { this.busy = false }
    },
    async openOperation (row) { this.resetSelectors(); const sequence = this.operationSequence; const context = this.packingContext; this.activeOperation = row; this.operationOpen = true; this.operationError = ''; this.pendingOperation = Boolean(pendingPackingCommand('operation', row.id)); try { const { data } = await getPackingOperation(row.id); if (sequence === this.operationSequence && this.operationOpen && context === this.packingContext) this.activeOperation = data.data } catch (e) { if (sequence === this.operationSequence && context === this.packingContext) this.operationError = e.userMessage || '作业详情读取失败' } },
    async loadPackingLabor(page) {
      if (this.busy || this.laborLoading || this.pendingOperation || !this.operationOpen || !this.activeOperation.id) return
      const id = this.activeOperation.id; const context = this.packingContext; const operationSequence = this.operationSequence; const sequence = ++this.laborSequence
      this.laborLoading = true; this.operationError = ''
      try { const { data } = await getPackingOperation(id, { labor_page: page, labor_per_page: 10 }); if (sequence === this.laborSequence && operationSequence === this.operationSequence && context === this.packingContext && this.operationOpen && this.activeOperation.id === id) this.activeOperation = data.data }
      catch (e) { if (sequence === this.laborSequence && operationSequence === this.operationSequence && context === this.packingContext) this.operationError = e.userMessage || '实际工时读取失败' }
      finally { if (sequence === this.laborSequence) this.laborLoading = false }
    },
    allow (action) { return Boolean((this.activeOperation.allowed_actions || {})[action]) && this.$can(action === 'inspect' ? 'sales_order.shipment.packing.quality' : 'sales_order.shipment.packing.execute') },
    async execute (op, action, extra = {}) {
      if (this.busy) return false
      const context = this.packingContext; const sequence = this.operationSequence
      this.busy = true; this.operationError = ''; this.error = ''
      try { await actPackingOperation(op.id, { action, expected_version: op.business_version, ...extra }); if (context !== this.packingContext || sequence !== this.operationSequence) return true; this.peopleOpen = false; this.materialsOpen = false; this.inspectionOpen = false; this.pendingOperation = false; await this.load(); if (context !== this.packingContext || sequence !== this.operationSequence) return true; if (this.operationOpen && this.activeOperation.id === op.id) { const { data } = await getPackingOperation(op.id); if (context === this.packingContext && sequence === this.operationSequence && this.operationOpen && this.activeOperation.id === op.id) this.activeOperation = data.data } if (context === this.packingContext && sequence === this.operationSequence) this.$emit('refresh'); return true } catch (e) { if (context !== this.packingContext || sequence !== this.operationSequence) return false; const message = e.userMessage || '包装作业操作失败'; this.operationError = message; this.error = message; this.materialError = this.materialsOpen ? message : ''; this.peopleShareError = this.peopleOpen ? message : ''; this.pendingOperation = Boolean(e.pendingCommand); return false } finally { this.busy = false }
    },
    async complete () { try { await this.$confirm(`确认本包裹中 ${this.qty(this.activeOperation.planned_base_qty)} 的产品已完成本工序？`, '完成包装工序', { type: 'warning' }); await this.execute(this.activeOperation, 'complete', { completed_base_qty: this.activeOperation.planned_base_qty }) } catch (_) { /* User cancelled the confirmation. */ } },
    async openPeople () {
      this.peopleRows = []; this.peopleTotal = 0; this.peopleShareError = ''
      this.peopleSelected = Object.fromEntries((this.activeOperation.participants || []).filter(row => row.role === 'COLLABORATOR' && row.is_active).map(row => [row.employee_legacy_id, { legacy_id: row.employee_legacy_id, nickname: row.name }]))
      this.rebalancePeopleShares()
      for (const share of this.activeOperation.share_plan?.shares || []) if (this.peopleShares[share.employee_legacy_id]) this.$set(this.peopleShares[share.employee_legacy_id], 'percent', ratioToPercent(share.share_ratio))
      this.peopleQuery = { keyword: '', department_id: 0, page: 1 }; this.peopleOpen = true; await this.loadPeople(true)
    },
    async loadPeople (include = false) { const sequence = this.peopleSequence = (this.peopleSequence || 0) + 1; const operationId = this.activeOperation.id; try { const { data } = await listPackingPeople(operationId, { ...this.peopleQuery, department_id: this.peopleQuery.department_id || undefined, per_page: 10, include_departments: include ? 1 : undefined }); if (sequence !== this.peopleSequence || !this.peopleOpen || operationId !== this.activeOperation.id) return; this.peopleRows = data.data.data; this.peopleTotal = data.data.total; if (data.departments) this.peopleTree = tree(data.departments, 'name') } catch (e) { if (sequence === this.peopleSequence && this.peopleOpen) this.$message.error(e.userMessage || '人员读取失败') } },
    togglePerson (row, selected) { if (this.busy || this.pendingOperation) return; if (selected) this.$set(this.peopleSelected, row.legacy_id, row); else this.$delete(this.peopleSelected, row.legacy_id); this.rebalancePeopleShares() },
    rebalancePeopleShares () {
      if (this.busy || this.pendingOperation) return
      this.peopleShares = Object.fromEntries(defaultPerformanceShares(this.peopleShareRows).map(share => [share.employee_legacy_id, { employee_legacy_id: share.employee_legacy_id, percent: ratioToPercent(share.share_ratio) }]))
      this.peopleShareError = ''
    },
    searchPeople () { this.peopleQuery.page = 1; this.loadPeople() },
    selectDepartment (row) { this.peopleQuery.department_id = row.id; this.searchPeople() },
    peoplePage (page) { this.peopleQuery.page = page; this.loadPeople() },
    savePeople () {
      if (this.pendingOperation) return this.execute(this.activeOperation, 'recover')
      let shares
      try { shares = buildPerformanceShares(this.peopleShareRows.map(person => this.peopleShares[person.employee_legacy_id] || { employee_legacy_id: person.employee_legacy_id })) } catch (error) { this.peopleShareError = error.message; return }
      return this.execute(this.activeOperation, 'collaborators', { employee_legacy_ids: Object.keys(this.peopleSelected).map(Number), shares })
    },
    async openMaterials () { this.materialDrafts = {}; this.materialRows = []; this.materialTotal = 0; this.materialError = ''; this.materialQuery = { keyword: '', category_id: 0, page: 1 }; this.materialsOpen = true; await this.loadMaterials(true) },
    async loadMaterials (include = false) { const sequence = this.materialSequence = (this.materialSequence || 0) + 1; const operationId = this.activeOperation.id; try { const { data } = await listPackingMaterials(operationId, { ...this.materialQuery, category_id: this.materialQuery.category_id || undefined, per_page: 10, include_categories: include ? 1 : undefined }); if (sequence !== this.materialSequence || !this.materialsOpen || operationId !== this.activeOperation.id) return; this.materialRows = data.data.data; this.materialTotal = data.data.total; if (data.categories) this.materialTree = tree(data.categories, 'category_name') } catch (e) { if (sequence === this.materialSequence && this.materialsOpen) this.materialError = e.userMessage || '库存来源读取失败' } },
    searchMaterials () { this.materialQuery.page = 1; this.loadMaterials() },
    selectCategory (row) { this.materialQuery.category_id = row.id; this.searchMaterials() },
    materialPage (page) { this.materialQuery.page = page; this.loadMaterials() },
    addMaterial (row) {
      const size = String(row.spec || '').match(/(\d+(?:\.\d+)?)\s*[*×xX]\s*(\d+(?:\.\d+)?)/)
      this.$set(this.materialDrafts, row.id, { ...row, base_qty: 0, identities: [], usage_dimensions: { length_mm: null, width_mm: null, thickness_mm: null }, usage_remark: '',
        area: { enabled: false, board_length_mm: size ? Number(size[1]) : undefined, board_width_mm: size ? Number(size[2]) : undefined, pieces: [{ length_mm: undefined, width_mm: undefined, qty: 1 }], remnant: 'return' } })
    },
    // 与后端同一口径：裁切块面积之和 ÷ 整板面积；回库按库存单位精度向上取，损耗按整张向上取。
    areaBoards (material) {
      const a = material.area; const board = Number(a.board_length_mm) * Number(a.board_width_mm)
      if (!(board > 0) || a.pieces.some(p => !(Number(p.length_mm) > 0 && Number(p.width_mm) > 0 && Number(p.qty) >= 1))) return null
      const used = a.pieces.reduce((sum, p) => sum + Number(p.length_mm) * Number(p.width_mm) * Number(p.qty), 0)
      const places = a.remnant === 'loss' || material.unit_allow_decimal === false ? 0 : Number(material.unit_decimal_places || 0)
      const factor = Math.pow(10, places)
      return { used, board, boards: Math.ceil(Number((used / board * factor).toFixed(6))) / factor }
    },
    applyArea (material) { const result = material.area.enabled ? this.areaBoards(material) : null; if (result) material.base_qty = result.boards },
    areaText (material) {
      const r = this.areaBoards(material); if (!r) return '请填写整板和裁切块尺寸'
      const loss = material.area.remnant === 'loss' ? `，损耗 ${((r.boards * r.board - r.used) / 1e6).toFixed(4)} m²` : ''
      const whole = material.area.remnant !== 'loss' && (material.unit_allow_decimal === false || !Number(material.unit_decimal_places || 0)) ? '。该木板单位不允许小数，只能按整张扣，请在“计量单位”中改为允许 2 位小数' : ''
      return `用料 ${(r.used / 1e6).toFixed(4)} m²，扣库存 ${r.boards} ${material.unit_name || ''}${loss}；成本按该批次单价计算${whole}`
    },
    async openIdentities (material) { this.identityMaterial = material; this.identitySelected = Object.fromEntries(material.identities.map(row => [row.id, row])); this.identityRows = []; this.identityTotal = 0; this.identityKeyword = ''; this.identityPage = 1; this.identityOpen = true; await this.loadIdentities() },
    async loadIdentities () { const sequence = this.identitySequence = (this.identitySequence || 0) + 1; const operationId = this.activeOperation.id; const sourceId = this.identityMaterial.id; try { const { data } = await listPackingIdentities(operationId, { inventory_balance_id: sourceId, keyword: this.identityKeyword.trim(), page: this.identityPage, per_page: 10 }); if (sequence !== this.identitySequence || !this.identityOpen || sourceId !== this.identityMaterial.id || operationId !== this.activeOperation.id) return; this.identityRows = data.data.data; this.identityTotal = data.data.total } catch (e) { if (sequence === this.identitySequence && this.identityOpen) this.$message.error(e.userMessage || '实物身份读取失败') } },
    searchIdentities () { this.identityPage = 1; this.loadIdentities() },
    changeIdentityPage (page) { this.identityPage = page; this.loadIdentities() },
    toggleIdentity (row, selected) { if (selected) this.$set(this.identitySelected, row.id, row); else this.$delete(this.identitySelected, row.id) },
    confirmIdentities () { const selected = Object.values(this.identitySelected); this.identityMaterial.identities = selected; this.identityMaterial.base_qty = this.identityMaterial.physical || this.identityMaterial.serial_tracking_mode === 'required' ? selected.length : Math.max(Number(this.identityMaterial.base_qty) || 0, selected.length); this.identityOpen = false },
    async saveMaterials () {
      if (this.pendingOperation) return this.execute(this.activeOperation, 'recover')
      const rows = Object.values(this.materialDrafts)
      if (!rows.length || rows.some(row => !Number.isFinite(Number(row.base_qty)) || Number(row.base_qty) <= 0 || Number(row.base_qty) > Number(row.quantity_available))) { this.materialError = '请填写不超过可用库存的实际耗用数量，实物板料须选择真实身份。'; return }
      await this.execute(this.activeOperation, 'materials', { materials: rows.map(row => ({
        inventory_balance_id: row.id, base_qty: Number(row.base_qty).toFixed(8),
        physical_material_ids: row.physical ? row.identities.map(item => item.id) : [],
        inventory_serial_ids: !row.physical && row.serial_tracking_mode !== 'none' ? row.identities.map(item => item.id) : [],
        usage_dimensions: Object.fromEntries(Object.entries(row.usage_dimensions || {}).filter(([, value]) => value != null && value !== '').map(([key, value]) => [key, Number(value).toFixed(2)])),
        usage_remark: row.usage_remark || null,
        ...(row.area && row.area.enabled && !row.physical ? { area_usage: { board_length_mm: Number(row.area.board_length_mm).toFixed(2), board_width_mm: Number(row.area.board_width_mm).toFixed(2), remnant: row.area.remnant,
          pieces: row.area.pieces.map(p => ({ length_mm: Number(p.length_mm).toFixed(2), width_mm: Number(p.width_mm).toFixed(2), qty: Number(p.qty) })) } } : {})
      })) })
    },
    saveInspection () { this.execute(this.activeOperation, 'inspect', this.inspection) }
  }
}
</script>

<style scoped>
.packing-panel,
.packing-panel * {
  box-sizing: border-box;
}
.packing-panel { min-width: 0; }
.packing-head,
.packing-parcel-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
.packing-head h3 { margin: 0; color: #1e293b; }
.packing-actions { display: flex; align-items: center; justify-content: flex-end; gap: 8px; flex-wrap: wrap; }
.packing-actions .el-button { margin: 0; }
.packing-panel .el-button--text { color: #008b4b; }
.packing-panel .packing-danger { color: #ef4444; }
.packing-pager { display: flex; justify-content: flex-end; padding-top: 12px; max-width: 100%; overflow-x: auto; }
.packing-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 12px; margin: 12px 0; }
.packing-grid > * { min-width: 0; }
.packing-grid label { display: flex; flex-direction: column; gap: 7px; }
.packing-parcel { background: #fff; border-bottom: 1px solid #e2e8f0; padding: 12px 0 16px; }
.packing-table-scroll { min-width: 0; overflow-x: auto; margin-bottom: 12px; }
.packing-table-scroll .el-select { width: 100%; }
.packing-table-scroll .el-input-number { width: 100%; }
.packing-balance,
.packing-requirements { margin: 12px 0; color: #475569; line-height: 1.7; overflow-wrap: anywhere; }
.packing-notice { color: #a16207; margin-bottom: 12px; }
.packing-picker { display: grid; grid-template-columns: 170px minmax(0, 1fr); gap: 14px; }
.packing-picker aside { min-width: 0; padding-right: 8px; border-right: 1px solid #e2e8f0; max-height: 350px; overflow: auto; }
.packing-picker main { min-width: 0; overflow-x: auto; }
.packing-search { display: flex; gap: 8px; margin-bottom: 12px; }
.packing-search .el-input { min-width: 0; }
.packing-selected { display: flex; gap: 8px; flex-wrap: wrap; padding-top: 12px; }
.packing-share-head { display: flex; align-items: center; justify-content: space-between; gap: 12px; flex-wrap: wrap; margin: 16px 0 12px; }
.packing-share-head h4 { margin: 0; }
.packing-route-entry { padding: 12px 0; border-bottom: 1px solid #e2e8f0; overflow-wrap: anywhere; }
.packing-route-materials { margin: 12px 0; }
.packing-material-draft { display: flex; align-items: center; flex-wrap: wrap; gap: 10px; padding: 10px 0; border-bottom: 1px solid #e2e8f0; }
.packing-material-draft > div { flex: 1; min-width: 150px; overflow-wrap: anywhere; }
.packing-inspection-reason { margin-top: 18px; }
@media (max-width: 780px) {
  .packing-grid { grid-template-columns: minmax(0, 1fr); }
  .packing-picker { grid-template-columns: minmax(0, 1fr); }
  .packing-picker aside { max-height: 140px; border-right: 0; border-bottom: 1px solid #e2e8f0; }
}
.packing-material-entry {
  margin: 12px 0;
  padding: 12px 0;
  border-bottom: 1px solid #e2e8f0;
  min-width: 0;
}
.packing-usage-dimensions {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin: 12px 0;
}
.packing-usage-dimensions label { min-width: 0; color: #475569; font-size: 13px; }
.packing-usage-dimensions .el-input-number { display: block; margin-top: 6px; width: 100%; }
.packing-continuation { margin: 12px 0; overflow-wrap: anywhere; color: #92400e; }
.packing-continuation p { margin: 8px 0; }
@media (max-width: 780px) {
  .packing-usage-dimensions { grid-template-columns: minmax(0, 1fr); }
}
</style>
<style>
.shipment-packing-dialog { max-width: calc(100vw - 32px); }
.shipment-packing-dialog .el-dialog__body { max-height: 65vh; overflow: auto; }
.shipment-packing-dialog .el-dialog__footer { border-top: 1px solid #e2e8f0; }
.shipment-packing-dialog .el-button--text { color: #008b4b; }
.shipment-packing-dialog .packing-danger { color: #ef4444; }
.packing-material-entry {
  margin: 12px 0;
  padding: 12px 0;
  border-bottom: 1px solid #e2e8f0;
  min-width: 0;
}
.packing-usage-dimensions {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 12px;
  margin: 12px 0;
}
.packing-usage-dimensions label { min-width: 0; color: #475569; font-size: 13px; }
.packing-usage-dimensions .el-input-number { display: block; margin-top: 6px; width: 100%; }
.packing-continuation { margin: 12px 0; overflow-wrap: anywhere; color: #92400e; }
.packing-continuation p { margin: 8px 0; }
@media (max-width: 780px) {
  .packing-usage-dimensions { grid-template-columns: minmax(0, 1fr); }
}
.packing-area { margin: 6px 0; padding: 6px 8px; border: 1px dashed #dcdfe6; border-radius: 4px; }
.packing-area-row { display: flex; flex-wrap: wrap; align-items: center; gap: 6px; margin-top: 6px; font-size: 12px; }
.packing-area-row .el-input-number { width: 96px; }
.packing-area-tip { margin-top: 6px; font-size: 12px; color: #909399; }
</style>
