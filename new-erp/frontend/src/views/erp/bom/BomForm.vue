<template>
  <div class="bom-form-page" v-loading="saving">
    <!-- 顶部标题与轻量返回入口 (对标物料管理 ItemForm.vue 规范) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-tickets" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ isEdit ? (canEdit ? '编辑' : '查看') : '新增' }}BOM方案</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              {{ isEdit ? (canEdit ? '编辑模式' : '只读模式') : '录入新BOM' }}
            </el-tag>
            <el-tag size="small" type="success" effect="plain">{{ bomTypeLabel }}</el-tag>
            <span v-if="form.bom_no" class="head-code-chip">
              <i class="el-icon-postcard" />
              <strong class="code-mono">{{ form.bom_no }}</strong>
            </span>
            <el-tag v-if="form.version" size="small" type="info" effect="plain">
              版本 {{ form.version }}
            </el-tag>
            <el-tag size="small" :type="displayStatusType" effect="plain">{{ displayStatusText }}</el-tag>
            <el-tag size="small" :type="displayAuditType" effect="plain">{{ displayAuditText }}</el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" @click="$router.push('/bom/boms')">返回列表</el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 (紧凑收纳) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>{{ form.status === 'inactive' && canEdit ? '停用方案保存后转为草稿，需重新提交生效。' : '维护生产装配与半成品 BOM 结构清单、产出物料与关联SKU、版本有效周期及子件物料组成配比。带 * 为必填项。' }}</span>
    </div>

    <!-- 只读状态警示提示条 (针对待审核/已审核/已启用不可编辑单据) -->
    <div v-if="!canEdit" class="readonly-alert-banner">
      <div class="alert-icon"><i class="el-icon-warning-outline" /></div>
      <div class="alert-content">
        <strong class="alert-title">{{ !hasEditPermission ? '当前账号仅可查看 BOM 方案。' : `当前 BOM 方案已处于【${displayStatusText} / ${displayAuditText}】状态，已被系统锁定为只读。` }}</strong>
        <span class="alert-desc">{{ !hasEditPermission ? '查看方案信息和物料明细；修改方案需要对应的 BOM 编辑权限。' : '已提交审核或已启用的 BOM 配方不能直接就地修改。如需调整配方定额或下料要求，请在列表中复制新版本后进行修改。' }}</span>
      </div>
      <div class="alert-action">
        <el-button size="mini" type="success" plain @click="$router.push('/bom/boms')">返回列表查看</el-button>
      </div>
    </div>

    <!-- 表单卡片区域（并列卡片自适应布局，消除右侧大面积空白） -->
    <div class="form-cards-layout">
      <!-- 顶部双卡片并列网格 (卡片1与卡片2并列排版，彻底解决右侧大片空白问题) -->
      <div class="bom-top-cards-grid">
        <!-- 卡片 1: 基础属性与版本控制 -->
        <section class="form-card master-card">
          <div class="card-header">
            <div class="header-main">
              <span class="card-icon-badge"><i class="el-icon-tickets" /></span>
              <div>
                <h2 class="card-title">基础属性与版本控制</h2>
                <span class="card-subtitle">设置 BOM 编号、配方名称、版本号与有效期</span>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="card-fields-grid-2">
              <div class="form-field-unit required">
                <label class="field-label">BOM 编号</label>
                <el-input v-model="form.bom_no" size="small" placeholder="系统自动预生成" disabled class="code-mono full-width erp-system-number">
                  <i slot="prefix" class="el-icon-lock input-lock-icon" />
                </el-input>
              </div>
              <div class="form-field-unit required">
                <label class="field-label">BOM 名称</label>
                <el-input v-model.trim="form.bom_name" size="small" :disabled="!canEdit" placeholder="请输入 BOM 方案名称" class="full-width" />
              </div>
              <div class="form-field-unit required">
                <label class="field-label">BOM 类型</label>
                <el-select v-model="form.bom_type" size="small" :disabled="!canEdit" class="full-width">
                  <el-option label="标准 BOM" value="standard" />
                  <el-option label="定制 BOM" value="custom" />
                  <el-option label="试制 BOM" value="trial" />
                </el-select>
              </div>
              <div class="form-field-unit required">
                <label class="field-label">版本号</label>
                <el-input v-model.trim="form.version" size="small" :disabled="!canEdit" placeholder="例如：V1.0" class="code-mono full-width" />
              </div>
              <div class="form-field-unit">
                <label class="field-label">生效日期</label>
                <el-date-picker v-model="form.effective_date" size="small" value-format="yyyy-MM-dd" :disabled="!canEdit" placeholder="选择生效日期" class="full-width" />
              </div>
              <div class="form-field-unit">
                <label class="field-label">失效日期</label>
                <el-date-picker v-model="form.expire_date" size="small" value-format="yyyy-MM-dd" :disabled="!canEdit" placeholder="留空长期有效" class="full-width" />
              </div>
              <div class="form-field-unit col-span-2">
                <label class="field-label">方案描述 / 定制说明</label>
                <el-input v-model="form.custom_description" size="small" :disabled="!canEdit" placeholder="客户定制需求说明、机型改型设计或补充描述..." class="full-width" />
              </div>

              <!-- 定制 BOM 专用来源追溯区块 -->
              <div v-if="isCustomBom" class="custom-trace-box col-span-2">
                <div class="trace-box-title">
                  <i class="el-icon-link" />
                  <span>定制来源追溯（定制 BOM 必须关联来源商品、来源 SKU 与来源标准 BOM）</span>
                </div>
                <div class="trace-fields-grid">
                  <div class="form-field-unit required">
                    <label class="field-label">来源商品</label>
                    <paged-record-select v-model="form.source_product_id" clearable size="small" :disabled="!canEdit" placeholder="请选择来源商品" class="full-width" entity="products" title="选择来源商品" category-type="product" />
                  </div>
                  <div class="form-field-unit required">
                    <label class="field-label">来源 SKU</label>
                    <paged-record-select v-model="form.source_sku_id" clearable size="small" :disabled="!canEdit" placeholder="请选择来源 SKU" class="full-width" entity="skus" title="选择来源SKU" category-type="product" />
                  </div>
                  <div class="form-field-unit required col-span-2">
                    <label class="field-label">来源标准 BOM</label>
                    <paged-record-select v-model="form.source_standard_bom_id" clearable size="small" :disabled="!canEdit" placeholder="请选择来源标准 BOM" class="full-width" :request="requestBoms" title="选择来源标准BOM" :params="{ bom_type: 'standard' }" :label="bomLabel" :columns="bomColumns" />
                  </div>
                </div>
              </div>
            </div>
          </div>
        </section>

        <!-- 卡片 2: 产出对象与单位 -->
        <section class="form-card output-card">
          <div class="card-header">
            <div class="header-main">
              <span class="card-icon-badge"><i class="el-icon-goods" /></span>
              <div>
                <h2 class="card-title">产出对象与单位</h2>
                <span class="card-subtitle">指定本配方最终产出的物料、关联商品/SKU与计量单位</span>
              </div>
            </div>
          </div>
          <div class="card-body">
            <div class="card-fields-grid-2">
              <div class="form-field-unit required col-span-2">
                <label class="field-label">产出物料（制成品/半成品）</label>
                <div class="output-picker-trigger full-width" :class="{ disabled: !canEdit }" @click="canEdit && openPicker('output')">
                  <div class="picker-trigger-content">
                    <i class="el-icon-goods trigger-icon" />
                    <span v-if="form.output_item" class="selected-item-text" :title="`${form.output_item.item_code} ${form.output_item.item_name}`">
                      <strong class="code-mono">{{ form.output_item.item_code }}</strong>
                      <span class="item-name">{{ form.output_item.item_name }}</span>
                      <span v-if="form.output_item.spec" class="item-spec">{{ form.output_item.spec }}</span>
                    </span>
                    <span v-else class="placeholder-text">点击从物料库选择产出物料...</span>
                  </div>
                  <el-button v-if="canEdit" size="mini" type="success" plain class="trigger-btn">
                    {{ form.output_item ? '更换物料' : '选择物料' }}
                  </el-button>
                </div>
              </div>
              <div class="form-field-unit">
                <label class="field-label">计量单位（只读带出）</label>
                <div class="unit-display-box full-width">
                  <span class="unit-name">{{ outputUnitName }}</span>
                  <span class="unit-hint">标准单位</span>
                </div>
              </div>
              <div class="form-field-unit">
                <label class="field-label">编制归属部门</label>
                <div class="dept-display-box full-width">
                  <span class="dept-name">生产技术部</span>
                  <span class="dept-hint">工艺编制</span>
                </div>
              </div>
              <div class="form-field-unit">
                <label class="field-label" title="可选；须与关联SKU同时填写">归属商品（可选）</label>
                <paged-record-select v-model="form.product_id" clearable size="small" :disabled="!canEdit" placeholder="与关联SKU成对选填" class="full-width" entity="products" title="选择归属商品" category-type="product" />
              </div>
              <div class="form-field-unit">
                <label class="field-label" title="可选；须与归属商品同时填写">关联 SKU（可选）</label>
                <paged-record-select v-model="form.sku_id" clearable size="small" :disabled="!canEdit" placeholder="与归属商品成对选填" class="full-width" entity="skus" title="选择关联SKU" category-type="product" />
              </div>
              <div class="output-tip-strip col-span-2">
                <i class="el-icon-info" />
                <span>产出物料为本配方的入库标的；关联商品/SKU可选，用于销售订单及独立商用型号穿透。</span>
              </div>
            </div>
          </div>
        </section>
      </div>

      <!-- 卡片 2.5: 工艺路线与工时提成 -->
      <section class="form-card standards-card">
        <div class="card-header">
          <div class="header-main">
            <span class="card-icon-badge"><i class="el-icon-guide" /></span>
            <div>
              <h2 class="card-title">工艺路线与工时提成</h2>
              <span class="card-subtitle">选择工艺路线，按路线每道节点维护准备工时、单件工时与提成方式；空白表示未设，与填 0 区分</span>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="routing-select-row">
            <span class="routing-select-label">工艺路线</span>
            <el-select
              v-model="form.routing_id"
              size="small"
              clearable
              filterable
              class="routing-select"
              :disabled="!canEdit || !form.output_item_id || standardsLoading"
              :placeholder="form.output_item_id ? '只列出适用于产出物料的有效路线' : '请先选择产出物料'"
              @change="onRoutingChange"
            >
              <el-option
                v-for="r in routingOptions"
                :key="r.id"
                :value="r.id"
                :label="`${r.routing_no} V${r.version} · ${r.routing_name}`"
              />
            </el-select>
            <span class="muted-hint">只列出适用于产出物料的有效路线；工单默认使用这里选的路线</span>
          </div>
          <div v-if="newerRouting" class="standards-tip warn">
            <i class="el-icon-warning-outline" />
            <span>所选路线已有新版本 V{{ newerRouting.version }}，请重新选择并核对工时提成</span>
          </div>
          <div v-if="form.routing_id" v-loading="standardsLoading">
            <el-table :data="standards.nodes" border size="mini" class="standards-table">
              <el-table-column label="顺序" width="60" align="center">
                <template slot-scope="{ row }">{{ row.sequence }}</template>
              </el-table-column>
              <el-table-column label="工序" min-width="110">
                <template slot-scope="{ row }"><b>{{ row.operation_name }}</b></template>
              </el-table-column>
              <el-table-column label="执行域" width="76" align="center">
                <template slot-scope="{ row }">
                  <el-tag size="mini" :type="row.execution_context === 'shipment' ? 'info' : 'success'" effect="plain">
                    {{ row.execution_context === 'shipment' ? '发货' : '生产' }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column label="准备工时（小时/张工单）" min-width="150" align="center">
                <template slot-scope="{ row }">
                  <el-input v-model="row.setup_hours" size="mini" :disabled="!canEdit" placeholder="未设" class="hours-input" />
                </template>
              </el-table-column>
              <el-table-column label="单件工时（小时/台）" min-width="130" align="center">
                <template slot-scope="{ row }">
                  <el-input v-model="row.unit_hours" size="mini" :disabled="!canEdit" placeholder="未设" class="hours-input" />
                </template>
              </el-table-column>
              <el-table-column v-if="perfView" label="提成方式" min-width="210" align="center">
                <template slot-scope="{ row }">
                  <el-radio-group v-model="row.performance_mode" size="mini" class="perf-mode-group" :disabled="!canEdit || !perfEdit">
                    <el-radio-button label="inherit">沿用模板</el-radio-button>
                    <el-radio-button label="override">指定比例</el-radio-button>
                    <el-radio-button label="zero">不计</el-radio-button>
                  </el-radio-group>
                </template>
              </el-table-column>
              <el-table-column v-if="perfView" label="比例" min-width="105" align="center">
                <template slot-scope="{ row }">
                  <el-input
                    v-if="row.performance_mode === 'override'"
                    v-model="row.performance_percent"
                    size="mini"
                    :disabled="!canEdit || !perfEdit"
                    placeholder="如 2.5"
                    class="rate-input"
                  >
                    <template slot="append">%</template>
                  </el-input>
                  <span v-else class="rate-static">{{ row.performance_mode === 'zero' ? '0' : '—' }}</span>
                </template>
              </el-table-column>
              <el-table-column v-if="perfView" label="生效结果" min-width="150" align="center">
                <template slot-scope="{ row }">
                  <span class="std-pill" :class="resolvedDisplay(row).type">{{ resolvedDisplay(row).text }}</span>
                </template>
              </el-table-column>
            </el-table>
            <div class="standards-summary">
              <span>工时：<b>{{ standardsSummary.statusText }}</b></span>
              <span>每台标准人时 <b>{{ standardsSummary.unitHours }}</b> 小时 + 每张工单准备 <b>{{ standardsSummary.setupHours }}</b> 小时</span>
              <span v-if="perfView">提成：<b>{{ standardsSummary.perfConfigured }} / {{ standards.nodes.length }} 道已确定</b>，合计 <b>{{ standardsSummary.perfTotalRate }}%</b><template v-if="standardsSummary.perfMissing">，{{ standardsSummary.perfMissing }} 道未配置</template></span>
              <span class="muted-hint">工时未设、提成未配置都不阻止生产，只提示</span>
            </div>
          </div>
          <div v-else class="muted-hint standards-empty">
            {{ form.output_item_id ? '不选工艺路线则不维护工时与提成。' : '请先选择产出物料，再选择工艺路线。' }}
          </div>

          <!-- 半成品工序提成（整机整套）：位置来自服务端 position_performance 树 -->
          <div v-if="perfView && posPerfPositions.length" class="pos-perf">
            <div class="pos-perf-head">
              <span class="pos-perf-title">半成品工序提成（整机整套）</span>
              <span class="muted-hint">半成品自己的 BOM 提成只在它单独销售时使用；随整机使用时按这里计算。</span>
            </div>
            <div v-if="posPerfStale" class="muted-hint pos-perf-stale">保存后刷新半成品位置</div>
            <div v-for="pos in posPerfPositions" :key="pos.position_path" class="pos-block" :style="{ marginLeft: (pos.depth * 20) + 'px' }">
              <div class="pos-head" @click="$set(posPerfExpanded, pos.position_path, posPerfExpanded[pos.position_path] === false)">
                <i :class="posPerfExpanded[pos.position_path] === false ? 'el-icon-arrow-right' : 'el-icon-arrow-down'" />
                <span class="pos-name">{{ pos.item_name }}</span>
                <span class="muted-hint">× {{ pos.qty_per_root }}</span>
                <el-tag v-if="pos.child_bom" size="mini" type="success" effect="plain" class="pos-bom-tag">{{ pos.child_bom.bom_no }} {{ pos.child_bom.version }}</el-tag>
                <span v-else class="muted-hint">{{ pos.reason || '无子 BOM' }}</span>
              </div>
              <div v-if="posPerfExpanded[pos.position_path] !== false && pos.nodes.length" class="pos-rows">
                <div v-for="n in pos.nodes" :key="pos.position_path + '|' + n.operation_id + '|' + n.occurrence" class="pos-row">
                  <span class="pos-op-name">{{ n.operation_name }}</span>
                  <span class="pos-occ muted-hint">第 {{ n.occurrence }} 次</span>
                  <el-radio-group v-model="posPerfEdit(pos, n).performance_mode" size="mini" class="perf-mode-group" :disabled="!canEdit || !perfEdit">
                    <el-radio-button label="inherit">沿用模板</el-radio-button>
                    <el-radio-button label="override">指定比例</el-radio-button>
                    <el-radio-button label="zero">不计</el-radio-button>
                  </el-radio-group>
                  <el-input
                    v-if="posPerfEdit(pos, n).performance_mode === 'override'"
                    v-model="posPerfEdit(pos, n).performance_percent"
                    size="mini"
                    :disabled="!canEdit || !perfEdit"
                    placeholder="如 2.5"
                    class="rate-input"
                  >
                    <template slot="append">%</template>
                  </el-input>
                  <span v-else class="rate-static">{{ posPerfEdit(pos, n).performance_mode === 'zero' ? '0' : '—' }}</span>
                  <span class="std-pill" :class="resolvedDisplay(posPerfEdit(pos, n)).type">{{ resolvedDisplay(posPerfEdit(pos, n)).text }}</span>
                </div>
              </div>
            </div>
          </div>
        </div>
      </section>

      <!-- 卡片 3: BOM 组成物料清单明细 -->
      <section class="form-card detail-card">
        <div class="card-header items-card-header">
          <div class="card-header-left">
            <span class="card-icon-badge"><i class="el-icon-share" /></span>
            <div class="header-titles">
              <h2 class="card-title">BOM 组成物料清单明细</h2>
              <span class="card-subtitle">录入组成子项物料定额、损耗比例及下料切割尺寸计算参数</span>
            </div>
            <el-tag size="small" type="success" effect="plain" class="count-tag">
              共 {{ form.items.length }} 行物料
            </el-tag>
          </div>
          <div class="card-header-right">
            <el-button :disabled="!canEdit" size="small" type="success" plain icon="el-icon-plus" @click="addLine">添加组成物料</el-button>
          </div>
        </div>

        <div class="table-container">
          <el-table :data="form.items" border size="mini" class="bom-lines-table">
            <el-table-column prop="line_no" label="行号" width="55" align="center">
              <template slot-scope="{ row }">
                <span class="line-badge code-mono">{{ row.line_no }}</span>
              </template>
            </el-table-column>
            <el-table-column label="组成物料" min-width="190">
              <template slot="header">
                <span class="required-head">组成物料</span>
              </template>
              <template slot-scope="{ row, $index }">
                <div class="cell-item-select" :class="{ disabled: !canEdit, empty: !row.component_item_code }" @click="canEdit && openPicker('component', $index)">
                  <div class="item-select-text">
                    <strong v-if="row.component_item_code" class="code-mono">{{ row.component_item_code }}</strong>
                    <span v-else class="empty-hint">点击选择物料</span>
                  </div>
                  <i class="el-icon-search search-icon" />
                </div>
              </template>
            </el-table-column>
            <el-table-column prop="component_item_name" label="物料名称" min-width="140" show-overflow-tooltip />
            <el-table-column prop="component_item_spec" label="规格型号" min-width="120" show-overflow-tooltip>
              <template slot-scope="{ row }">{{ row.component_item_spec || '—' }}</template>
            </el-table-column>
            <el-table-column label="下料要求与尺寸" min-width="250" align="center">
              <template slot-scope="{ row }">
                <div v-if="row.cutting_mode === 'sheet'" class="sheet-cut-fields">
                  <div class="cut-input-group">
                    <el-input-number v-model="row.cut_length_mm" size="mini" :min="0.01" :precision="2" :disabled="!canEdit" :controls="false" placeholder="长 mm" />
                    <span class="x-sep">×</span>
                    <el-input-number v-model="row.cut_width_mm" size="mini" :min="0.01" :precision="2" :disabled="!canEdit" :controls="false" placeholder="宽 mm" />
                    <span class="x-sep">×</span>
                    <el-input-number v-model="row.cut_thickness_mm" size="mini" :min="0.01" :precision="2" :disabled="!canEdit" :controls="false" placeholder="厚 mm" />
                    <span class="x-sep">×</span>
                    <el-input-number v-model="row.piece_qty" size="mini" :min="1" :precision="0" :disabled="!canEdit" :controls="false" placeholder="件数" />
                  </div>
                  <div class="cut-options-row">
                    <el-checkbox v-model="row.allow_cut_rotation" :disabled="!canEdit">允许旋转</el-checkbox>
                    <template v-if="cuttingUnit(row) === 'sheet'">
                      <span class="stock-label">原料标准:</span>
                      <el-input-number v-model="row.stock_dimensions.length_mm" size="mini" :min="0.01" :precision="2" :disabled="!canEdit" :controls="false" placeholder="原料长" class="mini-dim" />
                      <span class="x-sep">×</span>
                      <el-input-number v-model="row.stock_dimensions.width_mm" size="mini" :min="0.01" :precision="2" :disabled="!canEdit" :controls="false" placeholder="原料宽" class="mini-dim" />
                    </template>
                  </div>
                </div>
                <div v-else-if="row.cutting_mode === 'length'" class="length-cut-fields">
                  <el-select v-model="row.cutting_quantity_basis" size="mini" :disabled="!canEdit" class="cut-basis-select" @change="changeCuttingBasis(row)"><el-option label="按总用量" value="total_length" /><el-option label="按单段尺寸" value="dimensions_pieces" /></el-select>
                  <template v-if="row.cutting_quantity_basis === 'total_length'">
                    <el-input-number :value="metresValue(row.total_length_mm)" size="mini" :min="0.00001" :max="9999999.99999" :precision="5" :disabled="!canEdit" :controls="false" placeholder="每份总用量" class="length-total-input" @change="setTotalLength(row, $event)" />
                    <span class="unit-text">m / 份</span>
                  </template>
                  <template v-else>
                    <el-input-number v-model="row.cut_length_mm" size="mini" :min="0.01" :max="Number(row.stock_dimensions.length_mm || row.standard_stock_length_mm || 9999999999)" :precision="2" :disabled="!canEdit" :controls="false" placeholder="长度" />
                    <span class="unit-text">mm ×</span>
                    <el-input-number v-model="row.piece_qty" size="mini" :min="1" :precision="0" :disabled="!canEdit" :controls="false" placeholder="段数" />
                    <span class="unit-text">段</span>
                  </template>
                  <el-input-number v-if="cuttingUnit(row) === 'root'" v-model="row.stock_dimensions.length_mm" size="mini" :min="0.01" :precision="2" :disabled="!canEdit" :controls="false" placeholder="原料标准长 mm" class="mini-dim" />
                </div>
                <span v-else class="ordinary-material-tag">无需下料</span>
              </template>
            </el-table-column>
            <el-table-column label="定额用量" width="120" align="center">
              <template slot="header">
                <span class="required-head">定额用量</span>
              </template>
              <template slot-scope="{ row }">
                <el-input-number v-model="row.qty" size="mini" :min="0" :precision="4" :disabled="!canEdit || isCuttingLine(row)" controls-position="right" class="qty-number-input" />
                <small v-if="isCuttingLine(row)" class="auto-calc-tag">系统试算</small>
              </template>
            </el-table-column>
            <el-table-column label="单位" width="75" align="center">
              <template slot-scope="{ row }">
                <span class="unit-badge">{{ lineUnitName(row) }}</span>
              </template>
            </el-table-column>
            <el-table-column label="损耗率(%)" width="95" align="center">
              <template slot-scope="{ row }">
                <el-input-number v-model="row.loss_rate" size="mini" :min="0" :max="100" :precision="2" :disabled="!canEdit" controls-position="right" class="w-100" />
              </template>
            </el-table-column>
            <el-table-column label="固定用量" width="95" align="center">
              <template slot-scope="{ row }">
                <el-input-number v-model="row.fixed_qty" size="mini" :min="0" :precision="4" :disabled="!canEdit" controls-position="right" class="w-100" />
              </template>
            </el-table-column>
            <el-table-column label="可替代" width="70" align="center">
              <template slot-scope="{ row }">
                <el-checkbox v-model="row.replaceable" :disabled="!canEdit" />
              </template>
            </el-table-column>
            <el-table-column label="备注" min-width="110">
              <template slot-scope="{ row }">
                <el-input v-model="row.remark" size="mini" :disabled="!canEdit" placeholder="工艺备注" />
              </template>
            </el-table-column>
            <el-table-column label="用料工序" width="130" align="center">
              <template slot-scope="{ row }">
                <el-select v-model="row.operation_id" size="mini" :disabled="!canEdit" clearable filterable :placeholder="form.output_item_id ? '按路线规则' : '先选产出物料'" :no-data-text="form.output_item_id ? '适用工艺路线里没有车间工序' : '请先选择产出物料'">
                  <el-option v-for="op in lineOperationOptions" :key="op.id" :label="op.operation_name" :value="op.id">
                    <span>{{ op.operation_name }}</span>
                    <span class="operation-routing-hint">{{ op.routings.map(r => r.routing_name || r.routing_no).join('、') }}</span>
                  </el-option>
                  <!-- 已保存但已不在适用路线中的工序：只用于显示名称，不作为候选；保存时后端会要求重新选择。 -->
                  <el-option v-if="row.operation_id && !operationValid(row.operation_id)" v-show="false" :key="'selected-' + row.operation_id" :label="operationLabel(row.operation_id) + '（不在适用路线）'" :value="row.operation_id" />
                </el-select>
              </template>
            </el-table-column>
            <el-table-column label="子 BOM" width="150" align="center">
              <template slot-scope="{ row }">
                <el-select
                  v-if="madeComponent(row)"
                  v-model="row.child_bom_id"
                  size="mini"
                  :disabled="!canEdit"
                  clearable
                  filterable
                  placeholder="默认 BOM"
                  :loading="!!childBomLoading[row.component_item_id]"
                  :no-data-text="'该物料没有已审核的 BOM'"
                  @focus="loadChildBoms(row)"
                  @change="posPerfStale = true"
                >
                  <el-option v-for="bom in childBomOptions[row.component_item_id] || []" :key="bom.id" :label="bom.bom_no + ' ' + (bom.version || '')" :value="bom.id" />
                </el-select>
                <span v-else class="muted-hint">—</span>
              </template>
            </el-table-column>
            <el-table-column label="操作" width="60" align="center" fixed="right">
              <template slot-scope="{ $index }">
                <el-button :disabled="!canEdit" type="text" size="mini" class="delete-btn-link" @click="removeLine($index)">删除</el-button>
              </template>
            </el-table-column>
          </el-table>
        </div>

        <div class="table-bottom-bar">
          <div class="bottom-tips">
            <i class="el-icon-info" />
            <span>定额用量按 1 个单位产出物料的基础消耗设置；带有型材/板材开料计算的物料将按尺寸及标准原料规格自动推算定额用量。</span>
          </div>
          <div class="bottom-actions">
            <el-button :disabled="!canEdit" size="small" type="success" plain icon="el-icon-plus" @click="addLine">添加组成物料</el-button>
          </div>
        </div>
      </section>

      <!-- 卡片 4: 方案状态与单据备注 -->
      <section class="form-card">
        <div class="card-header">
          <div class="header-main">
            <span class="card-icon-badge"><i class="el-icon-document" /></span>
            <div>
              <h2 class="card-title">方案状态与单据备注</h2>
              <span class="card-subtitle">查看当前单据流转状态，并填写补充审核备注</span>
            </div>
          </div>
        </div>
        <div class="card-body">
          <div class="status-remark-grid">
            <div class="status-summary-box">
              <div class="status-item">
                <span class="s-label">方案流转状态</span>
                <el-tag size="small" :type="displayStatusType" effect="plain">{{ displayStatusText }}</el-tag>
              </div>
              <div class="status-item">
                <span class="s-label">审核状态</span>
                <el-tag size="small" :type="displayAuditType" effect="plain">{{ displayAuditText }}</el-tag>
              </div>
              <div class="status-desc-text">
                {{ canEdit ? (form.status === 'inactive' ? '保存后转为草稿，需重新提交生效。' : '当前方案可保存草稿或提交生效。') : '当前方案已生效或待审核，不可直接变更。' }}
              </div>
            </div>
            <div class="form-field-unit flex-1">
              <label class="field-label">补充备注</label>
              <el-input v-model="form.remark" type="textarea" :rows="3" :disabled="!canEdit" placeholder="请输入本 BOM 方案的补充工艺说明、适用产线或审核备注..." class="full-width" />
            </div>
          </div>
        </div>
      </section>
      <section v-if="form.id" class="form-card"><div class="card-body"><technical-drawing-source-panel :source="{type:'bom',id:form.id,version:drawingSourceVersion,position_uid:''}" :source-label="'BOM · ' + form.bom_no" :editable="canEdit && ['draft','inactive'].includes(form.status) && !form.submitted_at" /></div></section>
    </div>

    <!-- 底部固定吸底操作栏 (全局唯一持久保存入口，完全对标 ItemForm.vue 规范) -->
    <footer class="footer-bar">
      <div class="footer-left">
        <div class="footer-target-info">
          <span class="footer-icon"><i class="el-icon-tickets" /></span>
          <span class="footer-target-name">{{ form.bom_name || '未命名 BOM 方案' }}</span>
          <span v-if="form.bom_no" class="footer-code-chip">
            <i class="el-icon-postcard" />
            <strong class="code-mono">{{ form.bom_no }}</strong>
          </span>
          <span v-if="form.version" class="footer-version-tag code-mono">
            {{ form.version }}
          </span>
        </div>
        <div class="footer-status-pill" :class="isFormReady ? 'is-valid' : 'is-pending'">
          <i :class="isFormReady ? 'el-icon-circle-check' : 'el-icon-warning-outline'" />
          <span>{{ formValidationTip }}</span>
        </div>
      </div>
      <div class="footer-actions">
        <el-button size="small" @click="$router.push('/bom/boms')">返回列表</el-button>
        <el-checkbox v-if="canSubmit && $can('bom.manage.toggle_status')" v-model="setDefaultAfterApproval" :disabled="saving">生效后设为默认版本</el-checkbox>
        <el-button
          v-if="canEdit"
          size="small"
          icon="el-icon-folder"
          class="btn-save-draft"
          :loading="saving"
          @click="save(false)"
        >
          保存草稿
        </el-button>
        <el-button
          v-if="canSubmit"
          type="success"
          size="small"
          icon="el-icon-check"
          class="btn-theme-submit"
          :loading="saving"
          @click="save(true)"
        >
          保存并提交生效
        </el-button>
      </div>
    </footer>

    <bom-item-picker :picker="picker" :categories="categories" :can-edit="canEdit" :current-single-picked-item-id="currentSinglePickedItemId" :disabled-reason="itemDisableReason" @load="loadItems" @select="selectItem" @confirm="confirmPickerItems" />
  </div>
</template>

<script>
import PagedRecordSelect from '@/components/common/PagedRecordSelect.vue'
import BomItemPicker from '@/components/bom/BomItemPicker.vue'
import TechnicalDrawingSourcePanel from '@/components/technical-drawings/TechnicalDrawingSourcePanel.vue'
import { listEntity } from '@/api/erp/master'
import { listBoms, getBom, saveBom, getBomOperationOptions } from '@/api/erp/bom'
import { listProductionRoutings, getProductionRouting } from '@/api/erp/production'
import { clearCreatePageReservation, reserveForCreatePage } from '@/utils/documentNumberReservation'
import { cuttingUnitKind, cuttingQuantityBasis, previewCuttingQuantity, totalLengthMetres, totalLengthMillimetres } from '@/utils/cutting-quantity.mjs'
import { executeProductionDecision, pendingProductionDecision } from '@/api/erp/production-decisions'

const emptyLine = i => ({
  line_no: i + 1,
  component_item_id: null,
  component_item_code: '',
  component_item_name: '',
  component_item_spec: '',
  is_length_cut_material: false,
  cutting_mode: 'none',
  cutting_quantity_basis: 'dimensions_pieces',
  total_length_mm: null,
  standard_stock_length_mm: null,
  stock_dimensions: { length_mm: null, width_mm: null },
  cut_length_mm: null,
  cut_width_mm: null,
  cut_thickness_mm: null,
  allow_cut_rotation: false,
  piece_qty: null,
  qty: 1,
  unit_id: null,
  loss_rate: 0,
  fixed_qty: 0,
  replaceable: false,
  remark: '',
  operation_id: null,
  child_bom_id: null
})

export default {
  components: { PagedRecordSelect, BomItemPicker, TechnicalDrawingSourcePanel },
  name: 'BomForm',
  data: () => ({
    drawingSourceVersion: null,
    operationOptions: [],
    operationNames: {},
    operationOptionsSeq: 0,
    posPerf: [],
    posPerfEdits: {},
    posPerfExpanded: {},
    posPerfStale: false,
    childBomOptions: {},
    childBomLoading: {},
    form: {
      bom_no: '',
      bom_name: '',
      product_id: null,
      sku_id: null,
      output_item_id: null,
      output_item: null,
      routing_id: null,
      bom_type: 'standard',
      version: 'V1.0',
      effective_date: '',
      expire_date: '',
      source_product_id: null,
      source_sku_id: null,
      source_standard_bom_id: null,
      custom_description: '',
      remark: '',
      status: 'draft',
      audit_status: 'pending',
      submitted_at: null,
      items: [emptyLine(0)]
    },
    products: [],
    skus: [],
    units: [],
    categories: [],
    boms: [],
    routingOptions: [],
    routingFetched: null,
    lastRoutingId: null,
    standardsLoading: false,
    standards: { nodes: [] },
    standardsMeta: { routing: null, newer_routing: null },
    savedStandards: { routing_id: null, nodes: [], newer_routing: null },
    performancePermissions: { view: false, edit: false },
    bomColumns: [{ prop: 'bom_no', label: 'BOM编码' }, { prop: 'bom_name', label: 'BOM名称' }, { prop: 'version', label: '版本' }],
    numberReservation: null,
    setDefaultAfterApproval: false,
    saving: false,
    picker: {
      visible: false,
      mode: 'component',
      index: -1,
      multiple: false,
      selected: [],
      keyword: '',
      category_id: '',
      item_type: '',
      rows: [],
      page: 1,
      perPage: 10,
      total: 0,
      loading: false
    }
  }),
  watch: {
    // 用料工序候选来自产出物料的适用工艺路线；换产出物料时，原绑定不在新路线里的行改回按路线规则。
    'form.output_item_id'(value, oldValue) {
      this.loadOperationOptions({ clearInvalid: Boolean(oldValue) && Number(value) !== Number(oldValue) })
      this.onOutputItemForRouting(value, oldValue)
    },
    'form.items': {
      deep: true,
      handler(lines) { lines.forEach(row => this.recalculateCutLine(row)) }
    }
  },
  computed: {
    isEdit() {
      return Boolean(this.$route.params.id)
    },
    hasEditPermission() { return this.$can(this.isEdit ? 'bom.manage.edit' : 'bom.manage.create') },
    canEdit() {
      if (!this.isEdit) return this.$can('bom.manage.create')
      return this.$can('bom.manage.edit') && !this.form.deleted_at && !this.isPendingAudit && (this.isDraftEditable || this.form.status === 'inactive')
    },
    canSubmit() {
      return this.canEdit && this.$can('bom.manage.edit')
    },
    isCustomBom() {
      return this.form.bom_type === 'custom'
    },
    posPerfPositions() {
      const out = []
      const walk = (list, depth) => (list || []).forEach(pos => {
        out.push({ ...pos, depth })
        walk(pos.children, depth + 1)
      })
      walk(this.posPerf, 0)
      return out
    },
    bomTypeLabel() {
      const map = { standard: '标准BOM', custom: '定制BOM', trial: '试制BOM' }
      return map[this.form.bom_type] || '标准BOM'
    },
    isFormReady() {
      return !!(this.form.bom_name && this.form.bom_type && this.form.version && this.form.output_item_id && this.form.items && this.form.items.length > 0)
    },
    perfView() {
      return Boolean(this.performancePermissions.view)
    },
    perfEdit() {
      return Boolean(this.performancePermissions.edit)
    },
    newerRouting() {
      return this.form.routing_id && this.standardsMeta.newer_routing ? this.standardsMeta.newer_routing : null
    },
    // 明细行的用料工序候选：已选路线时只留该路线生产节点对应的工序，未选路线时保持全部适用工序。
    lineOperationOptions() {
      if (!this.form.routing_id || this.standardsLoading) return this.operationOptions
      const ids = new Set(this.standards.nodes.filter(node => node.execution_context !== 'shipment').map(node => Number(node.operation_id)))
      return this.operationOptions.filter(op => ids.has(Number(op.id)))
    },
    standardsSummary() {
      const nodes = this.standards.nodes
      const total = nodes.length
      const unset = nodes.filter(row => String(row.setup_hours).trim() === '' || String(row.unit_hours).trim() === '').length
      const statusText = !total ? '未设' : (unset === 0 ? '完整' : (unset === total ? '未设' : `部分设置（${unset} 道未设）`))
      const unitHours = Number(nodes.reduce((sum, row) => sum + (Number(row.unit_hours) || 0), 0).toFixed(4))
      const setupHours = Number(nodes.reduce((sum, row) => sum + (Number(row.setup_hours) || 0), 0).toFixed(4))
      let perfConfigured = 0
      let perfMissing = 0
      let perfTotalRate = 0
      for (const row of nodes) {
        if (row.performance_mode === 'override') {
          perfConfigured += 1
          perfTotalRate += Number(row.performance_percent) || 0
        } else if (row.performance_mode === 'zero') {
          perfConfigured += 1
        } else if (row.saved_mode === 'inherit' && row.resolved && row.resolved.source !== 'missing' && row.resolved.rate !== null) {
          perfConfigured += 1
          perfTotalRate += Number(this.rateToPercentText(row.resolved.rate)) || 0
        } else {
          perfMissing += 1
        }
      }
      return { statusText, unitHours, setupHours, perfConfigured, perfMissing, perfTotalRate: Number(perfTotalRate.toFixed(4)) }
    },
    formValidationTip() {
      if (!this.form.bom_name) return '请填写 BOM 方案名称'
      if (!this.form.output_item_id) return '请选择最终产出物料'
      if (!this.form.items || !this.form.items.length) return '请至少添加 1 行组成物料'
      if (!this.canEdit) return this.isPendingAudit ? '当前方案待审批，审批通过后生效' : '当前方案只读'
      return '核心必填信息已完善，可保存草稿或提交生效'
    },
    outputItemLabel() {
      return this.form.output_item ? [this.form.output_item.item_code, this.form.output_item.item_name, this.form.output_item.spec].filter(Boolean).join('　') : '请选择产出物料'
    },
    outputUnitName() {
      if (!this.form.output_item || !this.form.output_item.unit) return '—'
      const unit = this.form.output_item.unit.standard_unit || this.form.output_item.unit.standardUnit || this.form.output_item.unit
      return `${unit.unit_code || ''} ${unit.unit_name || ''}`.trim() || '—'
    },
    isDraftEditable() {
      return this.form.status === 'draft' && !this.form.submitted_at
    },
    isPendingAudit() {
      return this.form.audit_status === 'pending' && Boolean(this.form.submitted_at)
    },
    displayStatusText() {
      if (this.form.status === 'inactive') return this.statusText(this.form.status)
      if (this.isPendingAudit) return '已提交'
      if (this.form.audit_status === 'approved' && this.form.status !== 'active') return '已审核'
      return this.statusText(this.form.status || 'draft')
    },
    displayAuditText() {
      if (this.isDraftEditable) return '未提交'
      return this.auditText(this.form.audit_status || 'pending')
    },
    displayStatusType() {
      if (this.form.status === 'inactive') return 'warning'
      if (this.isPendingAudit) return 'warning'
      if (this.form.audit_status === 'approved' && this.form.status !== 'active') return 'success'
      return ({ active: 'success', inactive: 'warning', archived: 'info', draft: 'info' })[this.form.status] || 'info'
    },
    displayAuditType() {
      if (this.isDraftEditable) return 'info'
      return ({ approved: 'success', rejected: 'danger', pending: 'warning' })[this.form.audit_status] || 'warning'
    },
    todayDate() {
      const now = new Date()
      const y = now.getFullYear()
      const m = String(now.getMonth() + 1).padStart(2, '0')
      const d = String(now.getDate()).padStart(2, '0')
      return `${y}-${m}-${d}`
    },
    currentSinglePickedItemId() {
      if (this.picker.multiple) return null
      if (this.picker.mode === 'output') return this.form.output_item_id || null
      const row = this.form.items[this.picker.index]
      return (row && row.component_item_id) || null
    }
  },
  async created() {
    // 新建 BOM 尚无服务端返回的提成权限，先按本地授权码判断；编辑时以详情返回的 performance_permissions 为准。
    this.performancePermissions = { view: this.$can('master.bom.performance.view'), edit: this.$can('master.bom.performance.edit') }
    await this.loadRefs()
    if (this.isEdit) await this.loadDetail()
    else await this.reserveBomNumber()
    if (this.$route.query.openPicker) {
      this.$nextTick(() => setTimeout(() => {
        const query = this.$route.query
        this.picker.keyword = query.pickerKeyword || ''
        this.picker.item_type = query.pickerType || ''
        this.picker.page = Number(query.pickerPage || 1)
        this.openPicker(query.pickerMode || 'component', Number(query.pickerIndex || 0), { keepQuery: true })
      }, 500))
    }
  },
  methods: {
    async loadOperationOptions({ clearInvalid = false } = {}) {
      const itemId = this.form.output_item_id
      const seq = ++this.operationOptionsSeq
      if (!itemId) { this.operationOptions = []; return }
      try {
        const { data } = await getBomOperationOptions({ output_item_id: itemId })
        if (seq !== this.operationOptionsSeq) return
        this.operationOptions = (data.data || []).map(op => ({ ...op, id: Number(op.id) }))
        this.rememberOperations(this.operationOptions)
        if (!clearInvalid) return
        const cleared = this.form.items.filter(row => row.operation_id && !this.operationValid(row.operation_id))
        cleared.forEach(row => { row.operation_id = null })
        if (cleared.length) this.$message.warning(`产出物料已更换，${cleared.map(row => row.component_item_name || '物料').join('、')} 原指定的用料工序不在新物料的适用路线中，已改回按路线规则`)
      } catch (e) {
        if (seq === this.operationOptionsSeq) this.$message.error(e.userMessage || '用料工序候选加载失败')
      }
    },
    operationValid(id) {
      return this.lineOperationOptions.some(op => Number(op.id) === Number(id))
    },
    // 已选工序名称单独保存，候选被搜索结果替换后仍能显示名称。
    rememberOperations(rows) {
      rows.filter(op => op && op.id).forEach(op => this.$set(this.operationNames, Number(op.id), op.operation_name))
    },
    operationLabel(id) {
      return this.operationNames[Number(id)] || `工序 ${id}`
    },
    // 路线候选只取适用于产出物料的已生效与草稿路线；已选但不再符合条件的路线保留为只读选项用于显示。
    // routingFetched 只装接口真正返回的路线，注入的当前路线不算数，否则“不再适用”永远判不出来。
    async loadRoutingOptions() {
      const itemId = this.form.output_item_id
      const currentOption = () => (this.form.routing_id && this.standardsMeta.routing ? [this.standardsMeta.routing] : [])
      this.routingFetched = null
      if (!itemId) { this.routingOptions = currentOption(); return }
      try {
        const [active, draft] = await Promise.all([
          listProductionRoutings({ output_item_id: itemId, status: 'active', per_page: 100 }),
          listProductionRoutings({ output_item_id: itemId, status: 'draft', per_page: 100 })
        ])
        const rows = [...(active.data.data || []), ...(draft.data.data || [])]
        this.routingFetched = rows
        this.routingOptions = (this.form.routing_id && this.standardsMeta.routing && !rows.some(r => Number(r.id) === Number(this.form.routing_id)))
          ? [this.standardsMeta.routing, ...rows]
          : rows
      } catch (e) {
        this.routingOptions = currentOption()
      }
    },
    async onOutputItemForRouting(value, oldValue) {
      await this.loadRoutingOptions()
      const changed = Boolean(oldValue) && Number(value) !== Number(oldValue)
      if (!changed || !this.form.routing_id || this.routingFetched === null) return
      if (this.routingFetched.some(r => Number(r.id) === Number(this.form.routing_id))) return
      this.form.routing_id = null
      this.lastRoutingId = null
      this.standards = { nodes: [] }
      this.standardsMeta = { routing: null, newer_routing: null }
      this.$message.warning('产出物料已更换，原工艺路线不适用于新物料，已清除路线与工时提成设置')
    },
    async onRoutingChange(id, isRevert = false) {
      const previous = this.lastRoutingId
      if (!id) {
        this.standards = { nodes: [] }
        this.standardsMeta = { routing: null, newer_routing: null }
        this.lastRoutingId = null
        return
      }
      const option = this.routingOptions.find(r => Number(r.id) === Number(id))
      this.standardsMeta = { routing: option || this.standardsMeta.routing, newer_routing: null }
      // 选回本 BOM 已保存的路线时还原已存设置，不把未保存的节点值带到其他路线。
      if (Number(id) === Number(this.savedStandards.routing_id)) {
        this.standards = { nodes: this.buildStandardRows(this.savedStandards.nodes) }
        this.standardsMeta.newer_routing = this.savedStandards.newer_routing
        this.lastRoutingId = Number(id)
        return
      }
      this.standardsLoading = true
      try {
        const r = await getProductionRouting(id)
        const nodes = (r.data.data.operations || [])
          .slice()
          .sort((a, b) => Number(a.sequence) - Number(b.sequence))
          .map(op => ({
            routing_operation_id: op.id,
            operation_id: op.operation_id,
            operation_name: (op.operation && op.operation.operation_name) || '',
            sequence: op.sequence,
            execution_context: op.execution_context || 'production',
            shipment_operation_type: op.shipment_operation_type || null
          }))
        this.standards = { nodes: this.buildStandardRows(nodes) }
        this.lastRoutingId = Number(id)
      } catch (e) {
        this.$message.error(e.userMessage || '工艺路线节点加载失败')
        // 节点拉不到就退回原选择：留下空表又保存会把已存工时提成全部清掉。
        if (isRevert) {
          this.standards = { nodes: [] }
        } else {
          this.form.routing_id = previous
          await this.onRoutingChange(previous, true)
        }
      } finally {
        this.standardsLoading = false
      }
    },
    // 页面按小时录入、按百分数录入比例；服务端分别存分钟与小数比例，空串与 0 要区分。
    buildStandardRows(nodes) {
      return (nodes || []).map(node => {
        const mode = node.performance_mode || 'inherit'
        const blank = value => value === null || value === undefined
        return {
          routing_operation_id: Number(node.routing_operation_id),
          operation_id: Number(node.operation_id),
          operation_name: node.operation_name || `工序 ${node.operation_id || ''}`,
          sequence: node.sequence,
          execution_context: node.execution_context || 'production',
          shipment_operation_type: node.shipment_operation_type || null,
          setup_hours: blank(node.setup_minutes) ? '' : this.minutesToHoursText(node.setup_minutes),
          unit_hours: blank(node.unit_minutes) ? '' : this.minutesToHoursText(node.unit_minutes),
          performance_mode: mode,
          performance_percent: mode === 'override' && !blank(node.performance_rate) ? this.rateToPercentText(node.performance_rate) : '',
          resolved: node.resolved || null,
          saved_mode: mode
        }
      })
    },
    posPerfEdit(pos, node) {
      const key = pos.position_path + '|' + node.operation_id + '|' + node.occurrence
      return this.posPerfEdits[key] || { performance_mode: 'inherit', performance_percent: '', saved_mode: 'inherit', resolved: null }
    },
    initPosPerf(tree) {
      this.posPerf = (tree && tree.positions) || []
      this.posPerfStale = false
      const edits = {}
      const walk = list => (list || []).forEach(pos => {
        (pos.nodes || []).forEach(node => {
          const mode = node.mode || 'inherit'
          edits[pos.position_path + '|' + node.operation_id + '|' + node.occurrence] = {
            performance_mode: mode,
            performance_percent: mode === 'override' && node.rate !== null && node.rate !== undefined ? this.rateToPercentText(node.rate) : '',
            resolved: node.resolved || null,
            saved_mode: mode
          }
        })
        walk(pos.children)
      })
      walk(this.posPerf)
      this.posPerfEdits = edits
    },
    flattenPosPerf() {
      const rows = []
      const walk = list => (list || []).forEach(pos => {
        (pos.nodes || []).forEach(node => {
          const edit = this.posPerfEdit(pos, node)
          rows.push({
            position_path: pos.position_path,
            operation_id: node.operation_id,
            occurrence: node.occurrence,
            performance_mode: edit.performance_mode,
            performance_rate: edit.performance_mode === 'override' && String(edit.performance_percent).trim() !== '' ? Number((Number(edit.performance_percent) / 100).toFixed(6)) : null
          })
        })
        walk(pos.children)
      })
      walk(this.posPerf)
      return rows
    },
    madeComponent(row) {
      const type = row.component_item && row.component_item.item_type
      return ! type || ['semi_finished', 'finished_product'].includes(type)
    },
    async loadChildBoms(row) {
      const id = row && row.component_item_id
      if (! id || this.childBomOptions[id] || this.childBomLoading[id]) return
      this.$set(this.childBomLoading, id, true)
      try {
        const res = await listBoms({ output_item_id: id, status: 'active', audit_status: 'approved', per_page: 100 })
        this.$set(this.childBomOptions, id, res.data.data || res.data || [])
      } catch (e) {
        this.$set(this.childBomOptions, id, [])
      } finally {
        this.$set(this.childBomLoading, id, false)
      }
    },
    minutesToHoursText(minutes) {
      return String(Number((Number(minutes) / 60).toFixed(4)))
    },
    hoursToMinutes(text) {
      const value = String(text ?? '').trim()
      return value === '' ? null : Number((Number(value) * 60).toFixed(2))
    },
    rateToPercentText(rate) {
      return String(Number((Number(rate) * 100).toFixed(4)))
    },
    resolvedDisplay(row) {
      if (row.performance_mode === 'override') {
        const percent = String(row.performance_percent).trim()
        return percent === '' ? { type: 'muted', text: '保存后显示' } : { type: 'ok', text: `${percent}% · 本 BOM` }
      }
      if (row.performance_mode === 'zero') return { type: 'zero', text: '明确不计' }
      // 沿用模板：只有已存方式就是沿用模板时服务端的 resolved 才有效，否则保存后才能解析。
      if (row.saved_mode !== 'inherit' || !row.resolved) return { type: 'muted', text: '保存后显示' }
      const resolved = row.resolved
      if (resolved.source === 'missing' || resolved.rate === null) return { type: 'warn', text: '未配置' }
      const percent = this.rateToPercentText(resolved.rate)
      const template = (resolved.template && resolved.template.template_name) || ''
      if (resolved.source === 'category') return { type: 'ok', text: `${percent}% · 分类模板「${template}」` }
      if (resolved.source === 'default') return { type: 'ok', text: `${percent}% · 默认模板「${template}」` }
      if (resolved.source === 'bom') return { type: 'ok', text: `${percent}% · 本 BOM` }
      return { type: 'muted', text: '保存后显示' }
    },
    validateStandards() {
      if (!this.form.routing_id) return true
      for (const [index, row] of this.standards.nodes.entries()) {
        const seq = index + 1
        for (const [key, label] of [['setup_hours', '准备工时'], ['unit_hours', '单件工时']]) {
          const value = String(row[key]).trim()
          if (value !== '' && (!/^\d+(\.\d+)?$/.test(value) || Number(value) * 60 > 999999)) {
            this.$message.error(`第 ${seq} 道节点「${row.operation_name}」的${label}须为不小于 0 的小时数，留空表示未设`)
            return false
          }
        }
        if (row.performance_mode === 'override') {
          const percent = String(row.performance_percent).trim()
          if (!/^\d+(\.\d{1,4})?$/.test(percent) || Number(percent) <= 0 || Number(percent) > 100) {
            this.$message.error(`第 ${seq} 道节点「${row.operation_name}」的指定比例须大于 0% 且不超过 100%`)
            return false
          }
        }
      }
      return true
    },
    async reserveBomNumber() {
      try {
        this.numberReservation = await reserveForCreatePage('bom', '/bom/create')
        this.form.bom_no = this.numberReservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || 'BOM 编号预生成失败，请刷新后重试')
      }
    },
    requestBoms: listBoms,
    bomLabel(row) { return [row.bom_no, row.bom_name, row.version].filter(Boolean).join(' / ') },
    async loadRefs() {
      const { data } = await listEntity('categories', { category_type: 'item', management_scope: 'factory', tree: 1 })
      this.categories = data.data || []
      this.form.items.forEach(row => this.recalculateCutLine(row))
    },
    async loadDetail() {
      const { data } = await getBom(this.$route.params.id)
      this.drawingSourceVersion = data.version
      this.rememberOperations((data.items || []).map(item => item.operation_binding && item.operation_binding.operation).filter(Boolean))
      this.form = {
        ...data,
        items: (data.items || []).map((item, index) => ({
          ...item,
          operation_id: item.operation_binding ? Number(item.operation_binding.operation_id) : null,
          component_item_spec: (item.component_item && item.component_item.spec) || '',
          line_no: index + 1,
          qty: Number(item.qty),
          loss_rate: Number(item.loss_rate),
          fixed_qty: Number(item.fixed_qty),
          is_length_cut_material: Boolean(item.component_item && item.component_item.is_length_cut_material),
          cutting_mode: (item.component_item && item.component_item.cutting_mode) || (item.component_item && item.component_item.is_length_cut_material ? 'length' : 'none'),
          cutting_quantity_basis: cuttingQuantityBasis(item),
          total_length_mm: item.cutting_quantity_snapshot?.total_length_mm ?? item.total_length_mm ?? null,
          standard_stock_length_mm: item.component_item && item.component_item.standard_stock_length_mm !== null ? Number(item.component_item.standard_stock_length_mm) : null,
          stock_dimensions: {
            length_mm: item.cutting_quantity_snapshot && item.cutting_quantity_snapshot.stock_dimensions ? Number(item.cutting_quantity_snapshot.stock_dimensions.length_mm) || null : (item.component_item && item.component_item.standard_stock_length_mm ? Number(item.component_item.standard_stock_length_mm) : null),
            width_mm: item.cutting_quantity_snapshot && item.cutting_quantity_snapshot.stock_dimensions ? Number(item.cutting_quantity_snapshot.stock_dimensions.width_mm) || null : null
          },
          cut_length_mm: item.cut_length_mm === null ? null : Number(item.cut_length_mm),
          cut_width_mm: item.cut_width_mm == null ? null : Number(item.cut_width_mm),
          cut_thickness_mm: item.cut_thickness_mm == null ? null : Number(item.cut_thickness_mm),
          allow_cut_rotation: Boolean(item.allow_cut_rotation),
          piece_qty: item.piece_qty === null ? null : Number(item.piece_qty),
          replaceable: Boolean(item.replaceable)
        }))
      }
      const standardsData = data.operation_standards || {}
      this.standards = { nodes: this.buildStandardRows(standardsData.nodes || []) }
      this.initPosPerf(data.position_performance || {})
      this.standardsMeta = { routing: standardsData.routing || null, newer_routing: standardsData.newer_routing || null }
      // 深拷贝已存节点，用户改选其他路线再选回时按这份还原，不用重新拉详情。
      this.savedStandards = {
        routing_id: this.form.routing_id || null,
        nodes: JSON.parse(JSON.stringify(standardsData.nodes || [])),
        newer_routing: standardsData.newer_routing || null
      }
      this.lastRoutingId = this.form.routing_id || null
      if (data.performance_permissions) {
        this.performancePermissions = {
          view: Boolean(data.performance_permissions.view),
          edit: Boolean(data.performance_permissions.edit)
        }
      }
      await this.loadRoutingOptions()
    },
    addLine() {
      this.posPerfStale = true
      if (!this.canEdit) return
      this.openPicker('component', this.form.items.findIndex(row => !row.component_item_id))
    },
    removeLine(index) {
      this.posPerfStale = true
      if (this.form.items.length === 1) return this.$message.warning('至少保留一行物料明细')
      this.form.items.splice(index, 1)
      this.form.items.forEach((line, idx) => { line.line_no = idx + 1 })
    },
    openPicker(mode, index = -1, options = {}) {
      if (!this.canEdit) return
      const target = this.form.items[index]
      this.picker = { ...this.picker, visible: true, mode, index, multiple: mode === 'component' && (!target || !target.component_item_id), selected: [], page: options.keepQuery ? this.picker.page : 1, perPage: 10 }
      this.loadItems()
    },
    async loadItems() {
      this.picker.loading = true
      try {
        const { data } = await listEntity('items', {
          management_scope: 'factory',
          keyword: this.picker.keyword,
          category_id: this.picker.category_id,
          item_type: this.picker.item_type,
          status: 'enabled',
          page: this.picker.page,
          per_page: this.picker.perPage
        })
        this.picker.rows = data.data || []
        this.picker.total = data.total || 0
      } finally {
        this.picker.loading = false
      }
    },
    isItemDisabled(item) {
      return Boolean(this.itemDisableReason(item))
    },
    itemDisableReason(item) {
      if (this.picker.mode === 'component' && Number(item.id) === Number(this.form.output_item_id)) return '产出物料不能作为组成物料'
      if (['disabled', 'inactive'].includes(item.status)) return '停用物料不能选择'
      return ''
    },
    selectItem(item) {
      if (!this.canEdit) return
      if (this.isItemDisabled(item)) return this.$message.warning('停用物料或当前产出物料不能选择')
      if (this.picker.mode === 'output') {
        this.$set(this.form, 'output_item_id', item.id)
        this.$set(this.form, 'output_item', { ...item })
      } else {
        const row = this.form.items[this.picker.index]
        if (!row) return this.$message.error('请重新选择 BOM 明细行')
        this.applyComponentItem(row, item)
      }
      this.picker.visible = false
    },
    confirmPickerItems() {
      if (!this.canEdit || !this.picker.multiple || !this.picker.selected.length) return
      if (this.picker.selected.some(item => this.isItemDisabled(item))) return this.$message.warning('请移除不可选择的组成物料')
      const target = this.form.items[this.picker.index]
      if (this.picker.index >= 0 && (!target || target.component_item_id)) return this.$message.warning('明细行已变化，请重新选择组成物料')
      this.picker.selected.forEach((item, index) => {
        let row = index === 0 ? target : null
        if (!row) {
          row = emptyLine(this.form.items.length)
          this.form.items.push(row)
        }
        this.applyComponentItem(row, item)
      })
      this.picker.visible = false
    },
    applyComponentItem(row, item) {
      this.$set(row, 'component_item_id', item.id)
      this.$set(row, 'component_item', item)
      this.$set(row, 'child_bom_id', null)
      this.posPerfStale = true
      this.$set(row, 'component_item_code', item.item_code)
      this.$set(row, 'component_item_name', item.item_name)
      this.$set(row, 'component_item_spec', item.spec || '')
      this.$set(row, 'is_length_cut_material', Boolean(item.is_length_cut_material))
      this.$set(row, 'cutting_mode', item.cutting_mode || (item.is_length_cut_material ? 'length' : 'none'))
      this.$set(row, 'cutting_quantity_basis', 'dimensions_pieces')
      this.$set(row, 'total_length_mm', null)
      this.$set(row, 'standard_stock_length_mm', item.standard_stock_length_mm === null ? null : Number(item.standard_stock_length_mm))
      this.$set(row, 'stock_dimensions', { length_mm: item.standard_stock_length_mm ? Number(item.standard_stock_length_mm) : null, width_mm: null })
      this.$set(row, 'cut_length_mm', null)
      this.$set(row, 'cut_width_mm', null)
      this.$set(row, 'cut_thickness_mm', null)
      this.$set(row, 'allow_cut_rotation', false)
      this.$set(row, 'piece_qty', null)
      this.$set(row, 'unit_id', item.unit_id)
      this.$set(row, 'unit', item.unit ? { ...item.unit } : null)
      this.recalculateCutLine(row)
    },
    async save(andSubmit = false) {
      if (this.saving || !this.canEdit || (andSubmit && !this.canSubmit)) return
      const fields = ['id', 'bom_no', 'bom_name', 'product_id', 'sku_id', 'output_item_id', 'routing_id', 'bom_type', 'version', 'effective_date', 'expire_date', 'source_product_id', 'source_sku_id', 'source_standard_bom_id', 'custom_description', 'remark']
      const payload = {
        ...Object.fromEntries(fields.map(field => [field, this.form[field]])),
        submit_after_save: Boolean(andSubmit),
        set_default: Boolean(andSubmit && this.setDefaultAfterApproval && this.$can('bom.manage.toggle_status')),
        items: this.form.items.map((line, index) => ({
          ...line,
          child_bom_id: line.child_bom_id || null,
          line_no: (index + 1) * 10,
          stock_dimensions: Object.fromEntries(Object.entries(line.stock_dimensions || {}).filter(([, value]) => value !== null && value !== undefined && value !== ''))
        }))
      }
      // 详情返回的 operation_standards 是展示结构，保存时必须转成服务端要求的节点数组；无提成编辑权限时不提交比例字段，服务端保留原值。
      payload.operation_standards = this.form.routing_id
        ? this.standards.nodes.map(row => {
          const record = {
            routing_operation_id: row.routing_operation_id,
            setup_minutes: this.hoursToMinutes(row.setup_hours),
            unit_minutes: this.hoursToMinutes(row.unit_hours)
          }
          if (this.perfEdit) {
            record.performance_mode = row.performance_mode
            record.performance_rate = row.performance_mode === 'override' ? Number((Number(row.performance_percent) / 100).toFixed(6)) : null
          }
          return record
        })
        : []
      if (this.perfEdit) payload.position_performance = this.flattenPosPerf()
      if (!this.isEdit) {
        if (!this.numberReservation) return this.$message.error('BOM 编号尚未生成，请刷新页面后重试')
        payload.reservation_token = this.numberReservation.reservation_token
        payload.creation_session_id = this.numberReservation.creation_session_id
      }
      if (payload.bom_type !== 'custom') {
        payload.source_product_id = null
        payload.source_sku_id = null
        payload.source_standard_bom_id = null
      }
      if (payload.bom_type === 'custom' && (!payload.source_product_id || !payload.source_sku_id || !payload.source_standard_bom_id)) {
        return this.$message.error('定制 BOM 需要填写来源商品、来源SKU和来源标准BOM，用于后续追溯')
      }
      if (Boolean(payload.product_id) !== Boolean(payload.sku_id)) {
        return this.$message.error('归属商品和关联 SKU 必须同时填写，或同时留空')
      }
      if (!payload.output_item_id) return this.$message.error('请选择产出物料')
      if (payload.items.some(item => !item.component_item_id)) return this.$message.error('BOM 明细中存在未选择物料的行')
      const invalidCut = payload.items.find(row => this.isCuttingLine(row) && previewCuttingQuantity(row, this.rowUnit(row)).error)
      if (invalidCut) return this.$message.error(`${invalidCut.component_item_name}：${previewCuttingQuantity(invalidCut, this.rowUnit(invalidCut)).error}`)
      if (payload.items.some(item => item.cutting_mode === 'length' && item.cutting_quantity_basis !== 'total_length' && (!(Number(item.cut_length_mm) > 0) || !(Number(item.piece_qty) > 0)))) return this.$message.error('按单段尺寸的长料必须填写每段长度和段数')
      if (payload.items.some(item => item.cutting_mode === 'sheet' && ['cut_length_mm', 'cut_width_mm', 'cut_thickness_mm', 'piece_qty'].some(key => !(Number(item[key]) > 0)))) return this.$message.error('板材必须填写单件长、宽、厚和每份产出的件数')
      if (!this.validateStandards()) return
      this.saving = true
      try {
        const decisionKey = 'bom_save_' + (this.form.id || this.numberReservation.creation_session_id)
        const pending = pendingProductionDecision(decisionKey)
        if (pending) {
          const previous = { ...pending }
          delete previous.client_command_id
          if (JSON.stringify(previous) !== JSON.stringify(payload)) return this.$message.warning('上次保存结果尚未确认，请先刷新核对 BOM 状态；继续重试时须保持上次内容和操作一致。')
        }
        const { data } = await executeProductionDecision(decisionKey, payload, saveBom)
        const savedId = data.data.id
        this.initPosPerf((data.data && data.data.position_performance) || {})
        if (!this.isEdit) clearCreatePageReservation(this.numberReservation)
        this.$message.success(data.message || 'BOM 草稿已保存')
        this.$router.push({ path: '/bom/boms', query: { view_id: savedId } })
      } catch (error) {
        this.$message.error(error.userMessage || 'BOM 保存失败，请稍后重试')
      } finally {
        this.saving = false
      }
    },

    rowUnit(row) {
      const unit = row.unit || this.units.find(unit => Number(unit.id) === Number(row.unit_id))
      return unit && unit.is_legacy && unit.standard_unit ? unit.standard_unit : unit
    },
    isCuttingLine(row) {
      return ['length', 'sheet'].includes(row.cutting_mode)
    },
    cuttingUnit(row) {
      return cuttingUnitKind(this.rowUnit(row))
    },
    metresValue(value) { const text = totalLengthMetres(value); return text === '' ? undefined : Number(text) },
    setTotalLength(row, value) { this.$set(row, 'total_length_mm', totalLengthMillimetres(value)) },
    changeCuttingBasis(row) {
      this.$set(row, 'total_length_mm', null)
      for (const key of ['cut_length_mm', 'cut_width_mm', 'cut_thickness_mm', 'piece_qty']) this.$set(row, key, null)
      this.$set(row, 'allow_cut_rotation', false)
    },
    recalculateCutLine(row) {
      if (!this.isCuttingLine(row)) return
      const result = previewCuttingQuantity(row, this.rowUnit(row))
      if (row.qty !== result.quantity) this.$set(row, 'qty', result.quantity)
    },
    lineUnitName(row) {
      const unit = this.rowUnit(row)
      return unit ? `${unit.unit_name}${unit.unit_symbol ? `（${unit.unit_symbol}）` : ''}` : '-'
    },
    statusText(value) {
      return ({ draft: '草稿', enabled: '启用', active: '启用', disabled: '停用', inactive: '停用', archived: '已归档' })[value] || value
    },
    auditText(value) {
      return ({ pending: '待审核', approved: '已审核', rejected: '已驳回' })[value] || value
    }
  }
}
</script>

<style scoped>
.bom-form-page {
  min-width: 0;
  max-width: 100%;
  min-height: calc(100vh - 54px);
  padding: 14px 20px 80px;
  background: #f5f7fa;
  color: #1f2937;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  box-sizing: border-box;
}

/* 高清等宽数字与编码字体规范 */
.code-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif !important;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  letter-spacing: 0.5px;
}

/* 顶部轻量头部 (对标 ItemForm.vue 规范) */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 10px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  min-width: 0;
  max-width: 100%;
  flex: 1;
}

.head-icon {
  width: 38px;
  height: 38px;
  border-radius: 8px;
  background: #f0fdf4;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 20px;
  border: 1px solid #bbf7d0;
  flex-shrink: 0;
}

.head-title-wrap {
  display: flex;
  flex-direction: column;
  min-width: 0;
  flex: 1;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.title-row .el-tag {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  box-sizing: border-box;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #111827;
}

.head-tag {
  font-weight: 500;
}

.head-code-chip {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 8px;
  border-radius: 4px;
  color: #00763f;
  font-size: 13px;
  max-width: 100%;
  min-width: 0;
  box-sizing: border-box;
}

.head-code-chip strong {
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-save-draft {
  background: #f0fdf4;
  border-color: #86efac;
  color: #15803d;
  font-weight: 600;
}
.btn-save-draft:hover {
  background: #dcfce7;
  border-color: #4ade80;
  color: #166534;
}

.btn-theme-submit {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
  box-shadow: 0 1px 2px rgba(0, 139, 75, 0.2);
}
.btn-theme-submit:hover,
.btn-theme-submit:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
}

/* 全局统一页面提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 8px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 12px;
  line-height: 1.4;
  margin-bottom: 12px;
}

.erp-page-tip i {
  font-size: 15px;
  color: #008b4b;
  flex-shrink: 0;
}

/* 只读状态警告横幅 */
.readonly-alert-banner {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-left: 4px solid #d97706;
  border-radius: 6px;
  padding: 10px 14px;
  margin-bottom: 12px;
}
.alert-icon {
  font-size: 20px;
  color: #d97706;
  flex-shrink: 0;
}
.alert-content {
  display: flex;
  flex-direction: column;
  gap: 2px;
  flex: 1;
}
.alert-title {
  font-size: 13px;
  color: #92400e;
}
.alert-desc {
  font-size: 12px;
  color: #b45309;
  line-height: 1.4;
}
.alert-action {
  flex-shrink: 0;
}

/* 表单卡片容器 */
.form-cards-layout {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* 顶部卡片并列双栏网格 (解决用户反馈的右侧大片空白问题) */
.bom-top-cards-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 14px;
}

.form-card {
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.03);
  overflow: hidden;
  box-sizing: border-box;
}

.card-header {
  padding: 11px 16px;
  background: #fafbfc;
  border-bottom: 1px solid #edf1f5;
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.header-main {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.card-icon-badge {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: #f0fdf4;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 15px;
  flex-shrink: 0;
}

.card-title {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #111827;
  line-height: 1.3;
}

.card-subtitle {
  font-size: 12px;
  color: #6b7280;
}

.header-titles {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.items-card-header {
  justify-content: space-between;
}

.card-header-left {
  display: flex;
  align-items: center;
  gap: 8px;
}

.card-header-right {
  display: flex;
  gap: 8px;
}

.count-tag {
  background: #f0fdf4;
  border-color: #bbf7d0;
  color: #008b4b;
  font-weight: 600;
}

.card-body {
  padding: 16px;
}

/* 卡片内部 2 列紧凑字段网格 */
.card-fields-grid-2 {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 12px 16px;
}

.col-span-2 {
  grid-column: span 2;
}

.full-width {
  width: 100% !important;
  box-sizing: border-box;
}

.form-field-unit {
  display: flex;
  flex-direction: column;
  gap: 4px;
  min-width: 0;
}

.field-label {
  font-size: 12px;
  font-weight: 600;
  color: #374151;
  display: flex;
  align-items: center;
  line-height: 1.3;
}

.form-field-unit.required .field-label::before,
.required-head::before {
  content: '*';
  color: #ef4444;
  margin-right: 4px;
  font-weight: 700;
}

.input-lock-icon {
  color: #94a3b8;
  line-height: 32px;
}

/* 规范宽度控制类 */
.w-auto { width: auto; }
.w-100 { width: 100%; }
.flex-1 { flex: 1; }

/* 部门与单位展示框 */
.unit-display-box,
.dept-display-box {
  display: flex;
  align-items: center;
  justify-content: space-between;
  height: 32px;
  padding: 0 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  box-sizing: border-box;
}

.unit-name,
.dept-name {
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
}

.unit-hint,
.dept-hint {
  font-size: 11px;
  color: #64748b;
  background: #f1f5f9;
  padding: 1px 6px;
  border-radius: 3px;
}

.output-tip-strip {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #f8fafc;
  border: 1px dashed #cbd5e1;
  border-radius: 6px;
  padding: 8px 12px;
  color: #64748b;
  font-size: 12px;
  line-height: 1.4;
}

.output-tip-strip i {
  color: #008b4b;
  font-size: 14px;
  flex-shrink: 0;
}

/* 定制 BOM 追溯子区块 */
.custom-trace-box {
  background: #f0fdf4;
  border: 1px dashed #86efac;
  border-radius: 6px;
  padding: 10px 14px;
}

.trace-box-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #166534;
  margin-bottom: 8px;
}

.trace-fields-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 10px 14px;
}

/* 产出物料选择卡片触发器 */
.output-picker-trigger {
  height: 32px;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 10px;
  cursor: pointer;
  transition: all 0.2s;
  box-sizing: border-box;
}

.output-picker-trigger:hover:not(.disabled) {
  border-color: #008b4b;
}

.output-picker-trigger.disabled {
  background: #f8fafc;
  cursor: not-allowed;
}

.picker-trigger-content {
  display: flex;
  align-items: center;
  gap: 8px;
  flex: 1;
  overflow: hidden;
  min-width: 0;
}

.trigger-icon {
  color: #008b4b;
  font-size: 15px;
  flex-shrink: 0;
}

.selected-item-text {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #0f172a;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  min-width: 0;
}

.item-name {
  color: #334155;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.item-spec {
  color: #64748b;
  font-size: 12px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.placeholder-text {
  color: #94a3b8;
  font-size: 12px;
}

.trigger-btn {
  margin-left: 8px;
  flex-shrink: 0;
}

/* BOM 组成物料明细表格 */
.table-container {
  padding: 0 16px;
  overflow-x: auto;
}

.bom-lines-table :deep(th) {
  background: #f8fafc !important;
  color: #1e293b;
  font-weight: 700;
}

.line-badge {
  background: #e2e8f0;
  color: #334155;
  padding: 1px 5px;
  border-radius: 3px;
  font-size: 11px;
}

.cell-item-select {
  height: 28px;
  border: 1px solid #dcdfe6;
  border-radius: 4px;
  background: #fff;
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0 8px;
  cursor: pointer;
  transition: all 0.2s;
}

.cell-item-select:hover:not(.disabled) {
  border-color: #008b4b;
}

.cell-item-select.disabled {
  background: #f8fafc;
  cursor: not-allowed;
}

.cell-item-select.empty {
  border-style: dashed;
}

.empty-hint {
  color: #94a3b8;
  font-size: 12px;
}

.search-icon {
  color: #94a3b8;
  font-size: 13px;
}

.sheet-cut-fields {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 2px 0;
}

.cut-input-group {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 3px;
}

.cut-input-group :deep(.el-input-number--mini) {
  width: 54px;
}

.x-sep {
  color: #94a3b8;
  font-size: 11px;
}

.cut-options-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  font-size: 11px;
}

.stock-label {
  color: #64748b;
  font-size: 11px;
}

.mini-dim {
  width: 50px !important;
}

.length-cut-fields {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  justify-content: center;
  gap: 4px;
}

.length-cut-fields :deep(.el-input-number--mini) {
  width: 66px;
}

.length-cut-fields .cut-basis-select { flex-basis: 100%; width: 100%; }
.length-cut-fields .length-total-input { width: 120px; }

.unit-text {
  font-size: 12px;
  color: #64748b;
}

.ordinary-material-tag {
  color: #94a3b8;
  font-size: 12px;
}

.qty-number-input {
  width: 100%;
}

.auto-calc-tag {
  color: #008b4b;
  font-size: 10px;
  display: block;
  line-height: 12px;
  margin-top: 1px;
}

.unit-badge {
  color: #008b4b;
  background: #f0fdf4;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 12px;
  font-weight: 600;
}

.delete-btn-link {
  color: #ef4444;
}

.delete-btn-link:hover {
  color: #dc2626;
}

.table-bottom-bar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 10px 16px;
  border-top: 1px solid #f1f5f9;
  background: #fafafa;
}

.bottom-tips {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  color: #64748b;
}

.bottom-tips i {
  color: #008b4b;
}

/* 状态与备注卡片 */
.status-remark-grid {
  display: grid;
  grid-template-columns: 280px 1fr;
  gap: 16px;
  align-items: stretch;
}

.status-summary-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 12px 14px;
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.status-item {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.s-label {
  font-size: 12px;
  color: #475569;
}

.status-desc-text {
  font-size: 11px;
  color: #64748b;
  line-height: 1.4;
  margin-top: 2px;
  border-top: 1px dashed #cbd5e1;
  padding-top: 6px;
}

/* 底部固定吸底操作栏 (全局唯一持久保存入口，完全对标 ItemForm.vue 规范) */
.footer-bar {
  position: sticky;
  bottom: 0;
  z-index: 100;
  background: #ffffff;
  border-top: 1px solid #e5e7eb;
  box-shadow: 0 -4px 12px rgba(0, 0, 0, 0.05);
  padding: 10px 20px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-top: 14px;
  box-sizing: border-box;
}

.footer-left {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
  min-width: 0;
  max-width: 100%;
}

.footer-target-info {
  display: flex;
  align-items: center;
  gap: 8px;
  min-width: 0;
  max-width: 100%;
  flex-wrap: wrap;
}

.footer-icon {
  color: #008b4b;
  font-size: 16px;
}

.footer-target-name {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
  min-width: 0;
  max-width: 100%;
  overflow-wrap: anywhere;
}

.footer-code-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 7px;
  border-radius: 4px;
  color: #00763f;
  font-size: 12px;
}

.footer-version-tag {
  background: #f8fafc;
  border: 1px solid #cbd5e1;
  padding: 1px 6px;
  border-radius: 4px;
  color: #475569;
  font-size: 11px;
  font-weight: 700;
}

.footer-status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 10px;
  border-radius: 12px;
  font-size: 12px;
  font-weight: 500;
}

.footer-status-pill.is-valid {
  background: #f0fdf4;
  color: #15803d;
  border: 1px solid #bbf7d0;
}

.footer-status-pill.is-pending {
  background: #fffbeb;
  color: #b45309;
  border: 1px solid #fde68a;
}

.footer-actions {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  flex-shrink: 0;
}

/* 响应式自适应适配 (2026-09-08 锁定规范) */
@media (max-width: 1024px) {
  .bom-top-cards-grid {
    grid-template-columns: 1fr;
  }
  .status-remark-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 768px) {
  .bom-form-page {
    padding: 8px 10px 90px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-end;
  }
  .card-fields-grid-2 {
    grid-template-columns: 1fr;
  }
  .col-span-2 {
    grid-column: span 1;
  }
  .trace-fields-grid {
    grid-template-columns: 1fr;
  }
  .footer-bar {
    flex-direction: column;
    align-items: stretch;
    gap: 10px;
  }
  .footer-actions {
    justify-content: flex-end;
  }
}
</style>

<style>
.operation-routing-hint {
  float: right;
  margin-left: 12px;
  color: #909399;
  font-size: 12px;
}

/* 工艺路线与工时提成卡片 */
.standards-card .card-body {
  padding: 14px 16px 16px;
}

.routing-select-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin-bottom: 12px;
}

.routing-select-label {
  font-size: 12px;
  font-weight: 600;
  color: #374151;
  flex-shrink: 0;
}

.routing-select {
  width: 340px;
  max-width: 100%;
}

.muted-hint {
  font-size: 12px;
  color: #94a3b8;
}

.standards-empty {
  padding: 4px 0;
}

.standards-tip {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  border-radius: 6px;
  font-size: 12px;
  margin-bottom: 10px;
}

.standards-tip.warn {
  background: #fffbeb;
  border: 1px solid #fde68a;
  color: #92400e;
}

.standards-tip.warn i {
  color: #d97706;
}

.standards-table .hours-input,
.standards-table .rate-input {
  max-width: 110px;
}

.standards-table .hours-input ::v-deep .el-input__inner,
.standards-table .rate-input ::v-deep .el-input__inner {
  text-align: right;
}

.rate-static {
  color: #94a3b8;
}

.perf-mode-group ::v-deep .el-radio-button__inner {
  padding: 5px 8px;
}

.perf-mode-group ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background-color: #008b4b;
  border-color: #008b4b;
  box-shadow: -1px 0 0 0 #008b4b;
}

.std-pill {
  display: inline-block;
  font-size: 11px;
  border-radius: 10px;
  padding: 1px 8px;
  background: #f1f5f9;
  color: #64748b;
  white-space: nowrap;
}

.std-pill.ok {
  background: #dcfce7;
  color: #15803d;
}

.pos-perf { margin-top: 14px; border-top: 1px dashed #e4e7ed; padding-top: 12px; }
.pos-perf-head { display: flex; align-items: baseline; gap: 10px; margin-bottom: 8px; flex-wrap: wrap; }
.pos-perf-title { font-weight: 600; color: #303133; font-size: 13px; }
.pos-perf-stale { color: #e6a23c; margin-bottom: 8px; }
.pos-block { margin-bottom: 4px; }
.pos-head { display: flex; align-items: center; gap: 8px; cursor: pointer; padding: 4px 0; user-select: none; }
.pos-head:hover .pos-name { color: #008b4b; }
.pos-name { font-weight: 600; color: #303133; }
.pos-bom-tag { border-color: #008b4b; color: #008b4b; }
.pos-rows { margin: 2px 0 8px 22px; display: flex; flex-direction: column; gap: 6px; }
.pos-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
.pos-op-name { min-width: 90px; color: #303133; }
.pos-occ { min-width: 52px; }
.std-pill.warn {
  background: #fef3c7;
  color: #b45309;
}

.std-pill.zero {
  background: #e2e8f0;
  color: #475569;
}

.standards-summary {
  display: flex;
  gap: 18px;
  flex-wrap: wrap;
  margin-top: 10px;
  font-size: 12px;
  color: #64748b;
}

.standards-summary b {
  color: #1e293b;
}
</style>
