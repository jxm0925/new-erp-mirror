<template>
  <div class="bom-form-page" v-loading="saving">
    <!-- 顶部标题与轻量返回入口 (对标物料管理 ItemForm.vue 规范) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-tickets" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ isEdit ? '编辑' : '新增' }}BOM方案</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              {{ isEdit ? '编辑模式' : '录入新BOM' }}
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
          type="primary"
          size="small"
          icon="el-icon-check"
          class="btn-theme-submit"
          :loading="saving"
          @click="save(true)"
        >
          提交审核
        </el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 (紧凑收纳) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>{{ form.status === 'inactive' && canEdit ? '停用方案保存后转为草稿，需重新提交审核。' : '维护生产装配与半成品 BOM 结构清单、产出物料与关联SKU、版本有效周期及子件物料组成配比。带 * 为必填项。' }}</span>
    </div>

    <!-- 只读状态警示提示条 (针对待审核/已审核/已启用不可编辑单据) -->
    <div v-if="!canEdit" class="readonly-alert-banner">
      <div class="alert-icon"><i class="el-icon-warning-outline" /></div>
      <div class="alert-content">
        <strong class="alert-title">当前 BOM 方案已处于【{{ displayStatusText }} / {{ displayAuditText }}】状态，已被系统锁定为只读。</strong>
        <span class="alert-desc">已提交审核或已启用的 BOM 配方不能直接就地修改，以保证生产历史工单数据的一致性。如需调整配方定额或下料要求，请在列表中点击“复制新版本”生成新版本草稿后进行修改。</span>
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
                <el-input v-model="form.bom_no" size="small" placeholder="系统自动预生成" disabled class="code-mono full-width">
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
                    <el-select v-model="form.source_product_id" filterable clearable size="small" :disabled="!canEdit" placeholder="请选择来源商品" class="full-width">
                      <el-option v-for="p in products" :key="p.id" :label="`${p.product_code} / ${p.product_name}`" :value="p.id" />
                    </el-select>
                  </div>
                  <div class="form-field-unit required">
                    <label class="field-label">来源 SKU</label>
                    <el-select v-model="form.source_sku_id" filterable clearable size="small" :disabled="!canEdit" placeholder="请选择来源 SKU" class="full-width">
                      <el-option v-for="s in skus" :key="s.id" :label="`${s.sku_code} / ${s.sku_name}`" :value="s.id" />
                    </el-select>
                  </div>
                  <div class="form-field-unit required col-span-2">
                    <label class="field-label">来源标准 BOM</label>
                    <el-select v-model="form.source_standard_bom_id" filterable clearable size="small" :disabled="!canEdit" placeholder="请选择来源标准 BOM" class="full-width">
                      <el-option v-for="b in boms" :key="b.id" :label="`${b.bom_no} / ${b.bom_name} / ${b.version}`" :value="b.id" />
                    </el-select>
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
                <el-select v-model="form.product_id" filterable clearable size="small" :disabled="!canEdit" placeholder="与关联SKU成对选填" class="full-width">
                  <el-option v-for="p in products" :key="p.id" :label="`${p.product_code} / ${p.product_name}`" :value="p.id" />
                </el-select>
              </div>
              <div class="form-field-unit">
                <label class="field-label" title="可选；须与归属商品同时填写">关联 SKU（可选）</label>
                <el-select v-model="form.sku_id" filterable clearable size="small" :disabled="!canEdit" placeholder="与归属商品成对选填" class="full-width">
                  <el-option v-for="s in skus" :key="s.id" :label="`${s.sku_code} / ${s.sku_name}`" :value="s.id" />
                </el-select>
              </div>
              <div class="output-tip-strip col-span-2">
                <i class="el-icon-info" />
                <span>产出物料为本配方的入库标的；关联商品/SKU可选，用于销售订单及独立商用型号穿透。</span>
              </div>
            </div>
          </div>
        </section>
      </div>

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
            <el-button :disabled="!canEdit" size="small" plain icon="el-icon-upload2" class="btn-default">批量导入</el-button>
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
                {{ canEdit ? (form.status === 'inactive' ? '保存后转为草稿，需重新审核。' : '当前方案可直接保存草稿或提交审核。') : '当前方案已生效或待审核，不可直接变更。' }}
              </div>
            </div>
            <div class="form-field-unit flex-1">
              <label class="field-label">补充备注</label>
              <el-input v-model="form.remark" type="textarea" :rows="3" :disabled="!canEdit" placeholder="请输入本 BOM 方案的补充工艺说明、适用产线或审核备注..." class="full-width" />
            </div>
          </div>
        </div>
      </section>
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
          type="primary"
          size="small"
          icon="el-icon-check"
          class="btn-theme-submit"
          :loading="saving"
          @click="save(true)"
        >
          提交审核
        </el-button>
      </div>
    </footer>

    <!-- 物料库挑选居中弹窗 (改用柔和浅底标头与显眼高对比度关闭按钮，绝不刺眼) -->
    <el-dialog
      :visible.sync="picker.visible"
      append-to-body
      width="960px"
      top="7vh"
      custom-class="item-picker-dialog bom-component-picker-dialog"
      :close-on-click-modal="false"
    >
      <div slot="title" class="dialog-custom-header">
        <div class="dialog-title-left">
          <div class="dialog-icon-circle">
            <i class="el-icon-goods"></i>
          </div>
          <div class="dialog-title-texts">
            <div class="dialog-title-line">
              <span class="dialog-title-main">{{ picker.mode === 'output' ? '选择产出物料' : '选择组成物料' }}</span>
              <span class="dialog-title-tag">主数据物料库</span>
            </div>
            <p class="dialog-subtitle-text">
              {{ picker.mode === 'output' ? '挑选本 BOM 方案产出的成品或半成品，自动带出标准计量单位' : '挑选组成子项物料，自动继承规格型号、计量单位与下料计算参数' }}
            </p>
          </div>
        </div>
      </div>

      <div class="picker-filter-toolbar">
        <el-input v-model="picker.keyword" size="small" clearable placeholder="物料编码 / 名称 / 规格" class="filter-search" @keyup.enter.native="searchItems" @clear="searchItems">
          <i slot="prefix" class="el-icon-search"></i>
        </el-input>
        <el-select v-model="picker.category_id" size="small" clearable placeholder="物料分类" class="filter-select" @change="searchItems">
          <el-option v-for="c in categories" :key="c.id" :label="c.category_name" :value="c.id" />
        </el-select>
        <el-select v-model="picker.item_type" size="small" clearable placeholder="物料类型" class="filter-select" @change="searchItems">
          <el-option label="成品" value="finished_product" />
          <el-option label="半成品" value="semi_finished" />
          <el-option label="原材料" value="raw_material" />
          <el-option label="包材" value="packaging" />
          <el-option label="服务" value="service" />
        </el-select>
        <el-button size="small" type="success" icon="el-icon-search" @click="searchItems">查询</el-button>
        <el-button size="small" icon="el-icon-refresh" @click="resetItems">重置</el-button>
      </div>

      <div class="picker-tip-box">
        <i class="el-icon-info"></i>
        <span>仅显示启用物料；组成物料不可与产出物料相同；重置或切换筛选将自动返回第 1 页。</span>
      </div>

      <el-table :data="picker.rows" border size="mini" height="340" v-loading="picker.loading" class="picker-table">
        <el-table-column prop="item_code" label="物料编码" width="135">
          <template slot-scope="{ row }">
            <span class="code-mono font-bold">{{ row.item_code }}</span>
          </template>
        </el-table-column>
        <el-table-column prop="item_name" label="物料名称" min-width="160" show-overflow-tooltip />
        <el-table-column prop="spec" label="规格型号" min-width="130" show-overflow-tooltip>
          <template slot-scope="{ row }">{{ row.spec || '—' }}</template>
        </el-table-column>
        <el-table-column label="物料类型" width="90" align="center">
          <template slot-scope="{ row }">
            <el-tag size="mini" type="info">{{ itemTypeText(row.item_type) }}</el-tag>
          </template>
        </el-table-column>
        <el-table-column label="分类" width="110" show-overflow-tooltip>
          <template slot-scope="{ row }">{{ row.category ? row.category.category_name : '-' }}</template>
        </el-table-column>
        <el-table-column label="单位" width="75" align="center">
          <template slot-scope="{ row }">{{ row.unit ? row.unit.unit_name : '-' }}</template>
        </el-table-column>
        <el-table-column label="状态" width="80" align="center">
          <template slot-scope="{ row }">
            <span class="status-dot" :class="row.status === 'enabled' || row.status === 'active' ? 'enabled' : 'disabled'"></span>
            {{ statusText(row.status) }}
          </template>
        </el-table-column>
        <el-table-column label="选择" width="110" align="center">
          <template slot-scope="{ row }">
            <div class="picker-select-cell">
              <el-checkbox v-if="picker.multiple" :value="isPickerItemSelected(row)" :disabled="!canEdit || isItemDisabled(row)" :aria-label="`选择 ${row.item_code} ${row.item_name}`" @change="togglePickerItem(row, $event)" />
              <el-button v-else size="mini" plain type="success" :disabled="!canEdit || isItemDisabled(row)" @click="selectItem(row)">
                {{ isItemDisabled(row) ? '不可选' : '选择' }}
              </el-button>
              <small v-if="itemDisableReason(row)" class="disable-reason">{{ itemDisableReason(row) }}</small>
            </div>
          </template>
        </el-table-column>
      </el-table>

      <div v-if="picker.multiple" class="picker-selected">
        <div class="picker-selected-head">
          <strong>已选 {{ picker.selected.length }} 项组成物料</strong>
          <el-button v-if="picker.selected.length" type="text" size="mini" :disabled="!canEdit" @click="picker.selected = []">清空已选</el-button>
        </div>
        <div v-if="picker.selected.length" class="picker-selected-tags">
          <el-tag v-for="item in picker.selected" :key="item.id" size="small" type="success" :closable="canEdit" @close="removePickerItem(item)">{{ item.item_code }} / {{ item.item_name }}</el-tag>
        </div>
      </div>

      <div class="picker-pager">
        <span class="picker-total-text">共 {{ picker.total }} 条物料记录</span>
        <el-pagination
          background
          small
          layout="sizes, prev, pager, next"
          :current-page.sync="picker.page"
          :page-size.sync="picker.perPage"
          :page-sizes="[10, 20, 50]"
          :total="picker.total"
          @current-change="loadItems"
          @size-change="loadItems"
        />
      </div>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" @click="picker.visible = false">{{ picker.multiple ? '取消' : '关闭' }}</el-button>
        <el-button v-if="picker.multiple" size="small" type="success" :disabled="!canEdit || !picker.selected.length" @click="confirmPickerItems">确认添加（{{ picker.selected.length }}）</el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import { listEntity } from '@/api/erp/master'
import { listBoms, getBom, saveBom, submitBom } from '@/api/erp/bom'
import { clearCreatePageReservation, reserveForCreatePage } from '@/utils/documentNumberReservation'
import { cuttingUnitKind, cuttingQuantityBasis, previewCuttingQuantity, totalLengthMetres, totalLengthMillimetres } from '@/utils/cutting-quantity.mjs'

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
  remark: ''
})

export default {
  name: 'BomForm',
  data: () => ({
    form: {
      bom_no: '',
      bom_name: '',
      product_id: null,
      sku_id: null,
      output_item_id: null,
      output_item: null,
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
    numberReservation: null,
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
    'form.items': {
      deep: true,
      handler(lines) { lines.forEach(row => this.recalculateCutLine(row)) }
    }
  },
  computed: {
    isEdit() {
      return Boolean(this.$route.params.id)
    },
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
    bomTypeLabel() {
      const map = { standard: '标准BOM', custom: '定制BOM', trial: '试制BOM' }
      return map[this.form.bom_type] || '标准BOM'
    },
    isFormReady() {
      return !!(this.form.bom_name && this.form.bom_type && this.form.version && this.form.output_item_id && this.form.items && this.form.items.length > 0)
    },
    formValidationTip() {
      if (!this.form.bom_name) return '请填写 BOM 方案名称'
      if (!this.form.output_item_id) return '请选择最终产出物料'
      if (!this.form.items || !this.form.items.length) return '请至少添加 1 行组成物料'
      return '核心必填信息已完善，可保存草稿或提交审核'
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
    }
  },
  async created() {
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
    async reserveBomNumber() {
      try {
        this.numberReservation = await reserveForCreatePage('bom', '/bom/create')
        this.form.bom_no = this.numberReservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || 'BOM 编号预生成失败，请刷新后重试')
      }
    },
    async loadRefs() {
      const [p, s, u, c, b] = await Promise.all([
        listEntity('products', { per_page: 100 }),
        listEntity('skus', { per_page: 100 }),
        listEntity('units', { per_page: 100 }),
        listEntity('categories', { per_page: 100, category_type: 'item', management_scope: 'factory' }),
        listBoms({ per_page: 100, bom_type: 'standard' })
      ])
      this.products = p.data.data || []
      this.skus = s.data.data || []
      this.units = u.data.data || []
      this.form.items.forEach(row => this.recalculateCutLine(row))
      this.categories = c.data.data || []
      this.boms = b.data.data || []
    },
    async loadDetail() {
      const { data } = await getBom(this.$route.params.id)
      this.form = {
        ...data,
        items: (data.items || []).map((item, index) => ({
          ...item,
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
    },
    addLine() {
      if (!this.canEdit) return
      this.openPicker('component', this.form.items.findIndex(row => !row.component_item_id))
    },
    removeLine(index) {
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
    searchItems() {
      this.picker.page = 1
      this.loadItems()
    },
    resetItems() {
      Object.assign(this.picker, { keyword: '', category_id: '', item_type: '', page: 1 })
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
    isPickerItemSelected(item) {
      return this.picker.selected.some(row => Number(row.id) === Number(item.id))
    },
    togglePickerItem(item, selected) {
      if (!this.canEdit || this.isItemDisabled(item)) return
      if (selected && !this.isPickerItemSelected(item)) this.picker.selected.push(item)
      else if (!selected) this.removePickerItem(item)
    },
    removePickerItem(item) {
      if (!this.canEdit) return
      this.picker.selected = this.picker.selected.filter(row => Number(row.id) !== Number(item.id))
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
      const payload = {
        ...this.form,
        items: this.form.items.map((line, index) => ({
          ...line,
          line_no: (index + 1) * 10,
          stock_dimensions: Object.fromEntries(Object.entries(line.stock_dimensions || {}).filter(([, value]) => value !== null && value !== undefined && value !== ''))
        }))
      }
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
      this.saving = true
      let savedId = null
      try {
        const { data } = await saveBom(payload)
        savedId = data.data.id
        if (!this.isEdit) clearCreatePageReservation(this.numberReservation)
        if (andSubmit) await submitBom(savedId)
        this.$message.success(andSubmit ? 'BOM 已保存并提交审核' : 'BOM 草稿已保存')
        this.$router.push({ path: '/bom/boms', query: { view_id: savedId } })
      } catch (error) {
        this.$message.error(error.userMessage || 'BOM 保存失败，请稍后重试')
        if (savedId) this.$router.push({ path: '/bom/boms', query: { view_id: savedId } })
      } finally {
        this.saving = false
      }
    },
    itemTypeText(value) {
      return ({ finished_product: '成品', semi_finished: '半成品', raw_material: '原材料', packaging: '包材', service: '服务' })[value] || value
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
  flex-shrink: 0;
}

/* 物料挑选弹窗内部筛选 */
.picker-filter-toolbar {
  display: flex;
  gap: 8px;
  margin-bottom: 10px;
  flex-wrap: wrap;
}
.filter-search {
  width: 200px;
}
.filter-select {
  width: 120px;
}

.picker-tip-box {
  display: flex;
  align-items: center;
  gap: 6px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 4px;
  padding: 6px 12px;
  margin-bottom: 10px;
  font-size: 12px;
  color: #166534;
}

.picker-table :deep(th) {
  background: #f8fafc !important;
  color: #1e293b;
  font-weight: 700;
}

.picker-select-cell {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 2px;
}
.picker-select-cell ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}
.picker-select-cell ::v-deep .el-checkbox__inner:hover {
  border-color: #008b4b;
}
.picker-selected {
  margin-top: 10px;
  padding: 8px 10px;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  background: #f8fafc;
}
.picker-selected-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
  color: #334155;
}
.picker-selected-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
  max-height: 96px;
  overflow: auto;
  margin-top: 6px;
}
.picker-selected-tags .el-tag {
  max-width: 100%;
  height: auto;
  white-space: normal;
  overflow-wrap: anywhere;
}
.disable-reason {
  color: #f59e0b;
  font-size: 10px;
  line-height: 12px;
}

.status-dot {
  display: inline-block;
  width: 6px;
  height: 6px;
  border-radius: 50%;
  margin-right: 4px;
}
.status-dot.enabled {
  background-color: #10b981;
}
.status-dot.disabled {
  background-color: #94a3b8;
}

.picker-pager {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 10px;
}
.picker-total-text {
  font-size: 12px;
  color: #64748b;
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
.bom-component-picker-dialog.el-dialog {
  display: flex;
  flex-direction: column;
  max-width: calc(100vw - 32px);
  max-height: calc(93vh - 16px);
}
.bom-component-picker-dialog .el-dialog__body {
  min-height: 0;
  overflow-y: auto;
}
.bom-component-picker-dialog .el-dialog__header,
.bom-component-picker-dialog .el-dialog__footer {
  flex-shrink: 0;
}
/* 针对 append-to-body 的物料选择弹窗全局层样式：浅底柔和标头、高对比度显眼关闭按钮 */
.item-picker-dialog.el-dialog {
  border-radius: 8px !important;
  overflow: hidden !important;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.1), 0 10px 10px -5px rgba(0, 0, 0, 0.04) !important;
}

.item-picker-dialog .el-dialog__header {
  padding: 16px 20px 14px !important;
  background: #ffffff !important;
  border-bottom: 1px solid #e2e8f0 !important;
  border-radius: 8px 8px 0 0 !important;
  position: relative !important;
}

.item-picker-dialog .el-dialog__body {
  padding: 16px 20px !important;
}

.item-picker-dialog .el-dialog__footer {
  padding: 12px 20px !important;
  border-top: 1px solid #e2e8f0 !important;
  background: #ffffff !important;
}

.dialog-custom-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
}

.dialog-title-left {
  display: flex;
  align-items: center;
  gap: 12px;
}

.dialog-icon-circle {
  width: 36px;
  height: 36px;
  border-radius: 8px;
  background: #ecfdf5;
  color: #008b4b;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 18px;
  border: 1px solid #bbf7d0;
  flex-shrink: 0;
}

.dialog-title-texts {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.dialog-title-line {
  display: flex;
  align-items: center;
  gap: 8px;
}

.dialog-title-main {
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
  letter-spacing: -0.2px;
}

.dialog-title-tag {
  font-size: 11px;
  color: #15803d;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 1px 7px;
  border-radius: 4px;
  font-weight: 600;
}

.dialog-subtitle-text {
  margin: 0;
  font-size: 12px;
  color: #64748b;
  line-height: 1.4;
}

/* 弹窗显眼高对比度关闭按钮 - 独立浅灰方块与深色粗体X，悬停醒目红色 */
.item-picker-dialog .el-dialog__headerbtn {
  top: 16px !important;
  right: 18px !important;
  width: 32px !important;
  height: 32px !important;
  border-radius: 6px !important;
  background: #f1f5f9 !important;
  border: 1px solid #cbd5e1 !important;
  display: flex !important;
  align-items: center !important;
  justify-content: center !important;
  transition: all 0.2s cubic-bezier(0.4, 0, 0.2, 1) !important;
  cursor: pointer !important;
  z-index: 10 !important;
}

.item-picker-dialog .el-dialog__headerbtn .el-dialog__close {
  color: #334155 !important;
  font-size: 16px !important;
  font-weight: 700 !important;
  transition: color 0.2s !important;
}

.item-picker-dialog .el-dialog__headerbtn:hover {
  background: #fee2e2 !important;
  border-color: #fca5a5 !important;
}

.item-picker-dialog .el-dialog__headerbtn:hover .el-dialog__close {
  color: #dc2626 !important;
}
</style>
