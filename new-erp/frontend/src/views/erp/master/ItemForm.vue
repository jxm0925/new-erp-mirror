<template>
  <main class="item-form-page" v-loading="loading">
    <!-- 顶部标题与轻量返回入口 (精简紧凑) -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-box" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ isEdit ? '编辑' : '新增' }}物料档案</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              {{ isEdit ? '编辑模式' : '录入新物料' }}
            </el-tag>
            <el-tag size="small" :type="isOffice ? 'warning' : 'success'" effect="plain">{{ scopeLabel }}</el-tag>
            <span v-if="form.item_code" class="head-code-chip">
              <i class="el-icon-postcard" />
              <strong class="code-mono">{{ form.item_code }}</strong>
            </span>
            <el-tag v-if="currentTypeName" size="small" type="info" effect="plain">
              {{ currentTypeName }}
            </el-tag>
            <el-tag v-if="currentCategoryName" size="small" type="info" effect="plain">
              {{ currentCategoryName }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" @click="$router.push(entryListPath)">返回列表</el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 (紧凑收纳) -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>{{ isOffice ? '维护办公用品的基本信息，以及采购、库存、领用和责任人规则。' : '维护工厂物料的基本信息、生产与下料属性，以及采购、库存和核算规则。' }}</span>
    </div>
    <el-alert v-if="pageError" :title="pageError" type="error" :closable="false" show-icon />

    <!-- 紧凑分栏切换导航 (彻底消除纵向无限滚动的冗长排版) -->
    <div class="form-tabs-header">
      <button
        type="button"
        class="tab-btn"
        :class="{ active: currentTab === 'basic' }"
        @click="currentTab = 'basic'"
      >
        <i class="el-icon-document" />
        <span class="tab-label">1. 基础信息与业务属性</span>
        <span v-if="tab1Valid" class="tab-badge pass" title="基础必填项已完善"><i class="el-icon-check" /></span>
        <span v-else class="tab-badge warn" title="尚有必填项未填写"><i class="el-icon-warning-outline" /></span>
      </button>

      <button
        type="button"
        class="tab-btn"
        :class="{ active: currentTab === 'policy' }"
        @click="currentTab = 'policy'"
      >
        <i class="el-icon-money" />
        <span class="tab-label">2. {{ policyTitle }}</span>
        <span v-if="tab2Valid" class="tab-badge pass" title="归属策略校验通过"><i class="el-icon-check" /></span>
        <span v-else class="tab-badge warn" title="策略规则待确认"><i class="el-icon-warning-outline" /></span>
      </button>

      <button
        type="button"
        class="tab-btn"
        :class="{ active: currentTab === 'stock' }"
        @click="currentTab = 'stock'"
      >
        <i class="el-icon-coin" />
        <span class="tab-label">3. 库存数据与变更履历</span>
        <span v-if="isEdit" class="tab-count-tag">{{ history.length }} 条记录</span>
        <span v-else class="tab-count-tag">录入预览</span>
      </button>
    </div>

    <el-form ref="form" :model="form" :rules="rules" :disabled="!!pageError || saving" size="small" label-position="top" class="item-form-flow">
      <!-- ================= 标签页 1：基础信息与业务属性 ================= -->
      <div v-show="currentTab === 'basic'" class="tab-panel-content">
        <!-- 基础主档案 -->
        <section class="form-card master-card">
          <div class="card-header">
            <div class="header-main">
              <span class="card-icon-badge"><i class="el-icon-document" /></span>
              <div>
                <h2 class="card-title">{{ scopeLabel }}基本档案</h2>
                <span class="card-subtitle">{{ isOffice ? '维护名称、分类、单位和规格' : '录入物料编码、标准名称、分类属性、库存基本单位与下料规格' }}</span>
              </div>
            </div>
            <div class="header-extra">
              <el-tag :type="enabled ? 'success' : 'info'" size="small" effect="plain">
                {{ enabled ? '状态：正常启用' : '状态：已停用' }}
              </el-tag>
            </div>
          </div>

          <div class="card-body">
            <div class="fields-grid-4">
              <el-form-item label="管理类型" prop="management_scope" required>
                <el-select v-model="form.management_scope" class="full-width" @change="changeScope">
                  <el-option v-for="scope in managementScopes" :key="scope.value" :label="scope.label" :value="scope.value" />
                </el-select>
              </el-form-item>
              <!-- Item 编码 -->
              <el-form-item label="Item 编码" prop="item_code">
                <el-input :value="form.item_code" disabled class="code-tabular-input">
                  <template slot="append">系统预占</template>
                </el-input>
              </el-form-item>

              <!-- 物料名称 -->
              <el-form-item label="物料名称" prop="item_name" required>
                <el-input
                  v-model.trim="form.item_name"
                  clearable
                  maxlength="160"
                  show-word-limit
                  :placeholder="isOffice ? '如：A4 复印纸' : '如：304 不锈钢管'"
                />
              </el-form-item>

              <!-- 物料类型 -->
              <el-form-item label="物料类型" prop="item_type" required>
                <el-select v-model="form.item_type" class="full-width" placeholder="请选择物料类型">
                  <el-option v-for="type in itemTypes" :key="type.value" :label="type.label" :value="type.value" />
                </el-select>
              </el-form-item>

              <!-- Item 类目 -->
              <el-form-item label="Item 类目" prop="category_id" required>
                <el-select v-model="form.category_id" :loading="categoriesLoading" filterable clearable class="full-width" placeholder="请选择末级类目">
                  <el-option v-for="row in categories" :key="row.id" :label="row.full_path" :value="row.id" :disabled="!!row.legacy_scope_mismatch" />
                </el-select>
                <el-button v-if="!categories.length && !categoriesLoading && canViewCategories" type="text" size="mini" @click="openCategories">新增{{ scopeLabel }}分类</el-button>
              </el-form-item>

              <!-- 库存基本单位 -->
              <el-form-item label="库存基本单位" prop="unit_id" required>
                <el-select v-model="form.unit_id" :disabled="form.base_unit_locked" class="full-width" placeholder="请选择基本计量单位">
                  <el-option v-for="row in units" :key="row.id" :label="unitLabel(row)" :value="row.id" />
                </el-select>
              </el-form-item>

              <!-- 规格型号 -->
              <el-form-item label="规格型号">
                <el-input v-model.trim="form.spec" clearable :placeholder="isOffice ? '例如：A4 70g 500张/包' : '填写实际型号或尺寸'" />
              </el-form-item>

              <!-- 材质牌号 -->
              <el-form-item v-if="!isOffice" label="材质牌号">
                <el-input v-model.trim="form.material_grade" clearable placeholder="例如：304 / 201 / Q235" />
              </el-form-item>

              <!-- 启用状态 -->
              <el-form-item label="启用状态">
                <div class="status-switch-wrap">
                  <el-switch v-model="enabled" active-color="#008b4b" inactive-color="#dcdfe6" />
                  <span class="switch-status-text" :class="{ 'is-active': enabled }">{{ enabled ? '正常启用' : '已停用' }}</span>
                </div>
              </el-form-item>

              <!-- 下料原料分类 (跨2列) -->
              <el-form-item v-if="!isOffice" label="下料原料分类" class="col-span-2">
                <el-radio-group v-model="form.cutting_mode" size="small" class="cutting-radio-group" @change="normalizeCuttingMode">
                  <el-radio-button label="none">非下料原料</el-radio-button>
                  <el-radio-button label="sheet">板材</el-radio-button>
                  <el-radio-button label="length">定长材料</el-radio-button>
                </el-radio-group>
              </el-form-item>

              <!-- 标准原料长度 (定长时显示，跨2列) -->
              <el-form-item v-if="form.cutting_mode === 'length'" label="标准原料定长 (mm)" prop="standard_stock_length_mm" required class="col-span-2">
                <el-input-number
                  v-model="form.standard_stock_length_mm"
                  :min="0.01"
                  :precision="2"
                  controls-position="right"
                  class="full-width"
                  placeholder="原料定长数值 (mm)"
                />
              </el-form-item>

              <!-- 物料备注说明 (跨整行) -->
              <el-form-item label="物料备注说明" :class="form.cutting_mode === 'length' ? 'col-span-full' : 'col-span-2'">
                <el-input
                  v-model="form.remark"
                  type="textarea"
                  :rows="2"
                  maxlength="200"
                  show-word-limit
                  placeholder="用于日常业务协同、采购说明或领用场景备注..."
                />
              </el-form-item>
            </div>
          </div>
        </section>

        <!-- 业务控制属性与单件追溯 (横向平衡并列) -->
        <div class="prop-serial-grid">
          <!-- 业务控制属性 -->
          <section class="form-card property-card">
            <div class="card-header">
              <div class="header-main">
                <span class="card-icon-badge"><i class="el-icon-s-operation" /></span>
                <div>
                  <h2 class="card-title">业务控制属性</h2>
                  <span class="card-subtitle">{{ isOffice ? '控制办公用品采购、仓储核算和领用' : '控制采购申请、仓储核算及车间工单领料' }}</span>
                </div>
              </div>
            </div>

            <div class="card-body">
              <div class="prop-tri-grid" :class="{ 'office-props': isOffice }">
                <div class="prop-option-card" :class="{ active: form.is_purchase_item }" @click="form.is_purchase_item = !form.is_purchase_item">
                  <div class="prop-card-top">
                    <el-checkbox v-model="form.is_purchase_item" @click.native.stop />
                    <strong class="prop-title">可采购</strong>
                  </div>
                  <p class="prop-desc">允许通过采购申请单或采购订单采购本物料</p>
                </div>

                <div class="prop-option-card" :class="{ active: policy.is_stock_managed }" @click="toggleStockManaged">
                  <div class="prop-card-top">
                    <el-checkbox v-model="policy.is_stock_managed" @change="normalizeStock" @click.native.stop />
                    <strong class="prop-title">库存管理</strong>
                  </div>
                  <p class="prop-desc">纳入仓库实物与账面管理，支持收发存及盘点</p>
                </div>

                <div v-if="!isOffice" class="prop-option-card" :class="{ active: form.is_production_item }" @click="form.is_production_item = !form.is_production_item">
                  <div class="prop-card-top">
                    <el-checkbox v-model="form.is_production_item" @click.native.stop />
                    <strong class="prop-title">生产使用</strong>
                  </div>
                  <p class="prop-desc">可作为生产 BOM 子件或工单领料来源</p>
                </div>
              </div>
              <el-form-item v-if="!isOffice" label="生产供给方式" class="manufacturing-strategy-field">
                <el-select v-model="form.manufacturing_strategy" class="full-width" aria-label="生产供给方式">
                  <el-option label="未指定" value="unspecified" />
                  <el-option label="外购" value="purchase" />
                  <el-option label="自制" value="make" />
                </el-select>
              </el-form-item>
            </div>
          </section>

          <!-- 单件追溯策略 -->
          <section class="form-card serial-card">
            <div class="card-header">
              <div class="header-main">
                <span class="card-icon-badge"><i class="el-icon-cpu" /></span>
                <div>
                  <h2 class="card-title">单件序列号追溯</h2>
                  <span class="card-subtitle">一物一码跟踪物料全生命周期</span>
                </div>
              </div>
            </div>

            <div class="card-body">
              <div class="serial-form-inline">
                <el-form-item label="序列号策略" required class="flex-item">
                  <el-select v-model="policy.serial_tracking_mode" class="full-width" placeholder="请选择追溯策略">
                    <el-option label="无需序列号 (按总量/批次流转)" value="none" />
                    <el-option label="按需逐件编号 (出入库可选 SN)" value="optional" />
                    <el-option label="必须逐件编号 (强制每件唯一 SN)" value="required" />
                  </el-select>
                </el-form-item>

                <el-form-item label="系统编号前缀" class="flex-item">
                  <el-input
                    v-model.trim="form.serial_number_prefix"
                    maxlength="30"
                    clearable
                    placeholder="如：MAT、BW（选填）"
                  />
                </el-form-item>
              </div>

              <div class="serial-rules-compact">
                <span><i class="el-icon-info text-theme" /> 无需序列号适用于普通批次耗材；逐件编号出入库时系统自动或扫码记录唯一 SN。</span>
              </div>
            </div>
          </section>
        </div>

        <!-- 下一步引导操作栏 -->
        <div class="tab-bottom-nav">
          <span class="nav-hint">基础信息与属性已配置，下一步设置{{ policyTitle }}。</span>
          <el-button type="success" size="small" class="btn-step-next" @click="currentTab = 'policy'">
            下一步：{{ policyTitle }} <i class="el-icon-right" />
          </el-button>
        </div>
      </div>

      <!-- ================= 标签页 2：物资归属与经济结算 ================= -->
      <div v-show="currentTab === 'policy'" class="tab-panel-content">
        <section class="form-card policy-card">
          <div class="card-header">
            <div class="header-main">
              <span class="card-icon-badge"><i class="el-icon-money" /></span>
              <div>
                <h2 class="card-title">{{ policyTitle }}</h2>
                <span class="card-subtitle">{{ policySubtitle }}</span>
              </div>
            </div>
          </div>

          <div class="card-body">
            <!-- 快速模板与关键属性控制条 -->
            <div class="policy-quick-bar">
              <div class="quick-unit template-unit">
                <label class="quick-label"><i class="el-icon-s-order" /> {{ isOffice ? '办公用品管理方式' : '工厂物料管理方式' }}</label>
                <el-select v-model="policy.template_code" size="small" placeholder="请选择策略模板" @change="applyTemplate">
                  <el-option v-for="row in templateOptions" :key="row.value" :label="row.label" :value="row.value" />
                </el-select>
              </div>

              <div class="quick-switches-group">
                <div class="switch-card-item">
                  <span class="switch-title">责任人管理</span>
                  <div class="switch-box">
                    <el-switch v-model="policy.requires_custodian" active-color="#008b4b" inactive-color="#dcdfe6" />
                    <span class="switch-status">{{ policy.requires_custodian ? '需要' : '无需' }}</span>
                  </div>
                </div>

                <div class="switch-card-item" :class="{ disabled: !policy.requires_custodian }">
                  <span class="switch-title">是否可归还</span>
                  <div class="switch-box">
                    <el-switch v-model="policy.is_returnable" :disabled="!policy.requires_custodian" active-color="#008b4b" inactive-color="#dcdfe6" />
                    <span class="switch-status">{{ policy.is_returnable ? '可还' : '不还' }}</span>
                  </div>
                </div>

                <div class="switch-card-item">
                  <span class="switch-title">{{ isOffice ? '办公设备资产化' : '生产设备资产化' }}</span>
                  <div class="switch-box">
                    <el-switch v-model="policy.requires_capitalization" active-color="#008b4b" inactive-color="#dcdfe6" />
                    <span class="switch-status">{{ policy.requires_capitalization ? '资产' : '非资产' }}</span>
                  </div>
                </div>
              </div>
            </div>

            <el-alert
              v-if="policyScopeConflict"
              title="当前策略包含不适用于办公用品的工单、订单或生产配置，请重新选择适用的归属和处理方式。"
              type="warning"
              :closable="false"
              show-icon
              class="policy-scope-alert"
            >
              <el-button slot="description" type="text" @click="clearIncompatibleOfficePolicy">清除不适用配置</el-button>
            </el-alert>

            <!-- 主体两列排布：左侧路径卡片与详细配置，右侧实时校验与业务流向 -->
            <div class="policy-columns-wrap">
              <div class="policy-left-col">
                <!-- 4 条默认经济归属路径 -->
                <div class="routes-container">
                  <h3 class="inner-subtitle">{{ isOffice ? '办公领用与费用处理' : '工厂库存与核算处理' }}</h3>
                  <div class="routes-card-grid">
                    <div
                      v-for="route in routeOptions"
                      :key="route.value"
                      class="route-card-item"
                      :class="{ active: policy.future_route === route.value }"
                      @click="applyRoute(route.value)"
                    >
                      <div class="route-icon-box">
                        <svg v-if="route.value === 'inventory'" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8">
                          <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                          <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                          <line x1="12" y1="22.08" x2="12" y2="12"></line>
                        </svg>
                        <svg v-else-if="route.value === 'expense'" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8">
                          <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                          <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                        </svg>
                        <svg v-else-if="route.value === 'asset'" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8">
                          <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                          <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                        </svg>
                        <svg v-else viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.8">
                          <rect x="2" y="4" width="20" height="16" rx="2"></rect>
                          <line x1="12" y1="8" x2="12" y2="16"></line>
                          <line x1="8" y1="12" x2="16" y2="12"></line>
                        </svg>
                      </div>
                      <div class="route-texts">
                        <strong class="route-name">{{ route.label }}</strong>
                        <p class="route-desc">{{ route.help }}</p>
                      </div>
                      <i v-if="policy.future_route === route.value" class="el-icon-circle-check route-check-icon" />
                    </div>
                  </div>
                </div>

                <!-- 详细归属与确认参数 -->
                <div class="policy-detail-grid">
                  <el-form-item :label="isOffice ? '办公领用归属' : '物料使用归属'">
                    <el-select v-model="policy.future_bearer_type" class="full-width" placeholder="请选择归属主体">
                      <el-option v-for="bearer in bearerOptions" :key="bearer.value" :label="bearer.label" :value="bearer.value" :disabled="bearer.disabled" />
                    </el-select>
                  </el-form-item>

                  <el-form-item :label="isOffice ? '办公采购后处理' : '物料采购后处理'">
                    <el-select v-model="policy.post_purchase_action" class="full-width" placeholder="请选择处理策略">
                      <el-option v-for="action in actionOptions" :key="action.value" :label="action.label" :value="action.value" :disabled="action.disabled" />
                    </el-select>
                  </el-form-item>

                  <el-form-item :label="isOffice ? '办公领用 / 验收确认' : '物料领用 / 验收确认'">
                    <el-select v-model="policy.consumption_confirmation_mode" class="full-width" placeholder="请选择确认方式">
                      <el-option v-for="confirmation in confirmationOptions" :key="confirmation.value" :label="confirmation.label" :value="confirmation.value" :disabled="confirmation.disabled" />
                    </el-select>
                  </el-form-item>

                  <el-form-item label="变更原因" required>
                    <el-select v-model="policy.change_reason" class="full-width" placeholder="请选择策略变更原因">
                      <el-option label="业务调整" value="业务调整" />
                      <el-option label="规范统一" value="规范统一" />
                      <el-option label="策略升级" value="策略升级" />
                      <el-option label="其他变更" value="其他变更" />
                    </el-select>
                  </el-form-item>

                  <el-form-item label="策略备注说明">
                    <el-input v-model="policy.remark" maxlength="200" clearable placeholder="选填，最多 200 字" />
                  </el-form-item>
                </div>
              </div>

              <!-- 右侧合规校验与业务流向 -->
              <aside class="policy-right-aside">
                <div class="validation-panel">
                  <h4 class="aside-title"><i class="el-icon-finished" /> 保存前合规校验</h4>
                  <div class="val-check-list">
                    <div class="val-check-row" :class="form.unit_id ? 'pass' : 'fail'">
                      <i :class="form.unit_id ? 'el-icon-circle-check' : 'el-icon-circle-close'" class="check-icon" />
                      <span>库存基本单位已设置</span>
                    </div>
                    <div class="val-check-row" :class="policy.template_code ? 'pass' : 'fail'">
                      <i :class="policy.template_code ? 'el-icon-circle-check' : 'el-icon-circle-close'" class="check-icon" />
                      <span>物资管理属性完整</span>
                    </div>
                    <div class="val-check-row" :class="routeValid && actionValid && capitalizationValid ? 'pass' : 'fail'">
                      <i :class="routeValid && actionValid && capitalizationValid ? 'el-icon-circle-check' : 'el-icon-circle-close'" class="check-icon" />
                      <span>{{ isOffice ? '办公费用与库存配置一致' : '工厂库存与核算配置一致' }}</span>
                    </div>
                    <div class="val-check-row" :class="bearerValid && !policyScopeConflict ? 'pass' : 'fail'">
                      <i :class="bearerValid && !policyScopeConflict ? 'el-icon-circle-check' : 'el-icon-circle-close'" class="check-icon" />
                      <span>{{ isOffice ? '办公领用归属有效' : '工厂物料归属有效' }}</span>
                    </div>
                    <div class="val-check-row" :class="returnableValid ? 'pass' : 'fail'">
                      <i :class="returnableValid ? 'el-icon-circle-check' : 'el-icon-circle-close'" class="check-icon" />
                      <span>责任人规则有效</span>
                    </div>
                    <div class="val-check-row" :class="policy.serial_tracking_mode ? 'pass' : 'fail'">
                      <i :class="policy.serial_tracking_mode ? 'el-icon-circle-check' : 'el-icon-circle-close'" class="check-icon" />
                      <span>序列号规则有效</span>
                    </div>
                  </div>
                </div>

                <div class="strategy-flow-box">
                  <h4 class="aside-title"><i class="el-icon-s-promotion" /> {{ isOffice ? '办公处理配置预览' : '工厂处理配置预览' }}</h4>
                  <div class="flowchart-steps">
                    <div class="flow-step">
                      <div class="flow-circle-icon"><i class="el-icon-shopping-cart-2" /></div>
                      <span class="flow-step-label">采购到货</span>
                    </div>
                    <span class="flow-arrow">→</span>
                    <div class="flow-step">
                      <div class="flow-circle-icon"><i class="el-icon-box" /></div>
                      <span class="flow-step-label">{{ preview.stock === '是' ? '库存管理' : '非库存' }}</span>
                    </div>
                    <span class="flow-arrow">→</span>
                    <div class="flow-step">
                      <div class="flow-circle-icon"><i class="el-icon-user" /></div>
                      <span class="flow-step-label">{{ preview.next || '待选择确认方式' }}</span>
                    </div>
                    <span class="flow-arrow">→</span>
                    <div class="flow-step">
                      <div class="flow-circle-icon"><span class="flow-yen">¥</span></div>
                      <span class="flow-step-label">{{ preview.route || '-' }}</span>
                    </div>
                  </div>
                </div>
              </aside>
            </div>
          </div>
        </section>

        <!-- 步骤切换导航 -->
        <div class="tab-bottom-nav">
          <el-button size="small" icon="el-icon-back" @click="currentTab = 'basic'">上一步：基础信息</el-button>
          <el-button v-if="isEdit" type="success" size="small" class="btn-step-next" @click="currentTab = 'stock'">
            下一步：库存与履历 <i class="el-icon-right" />
          </el-button>
        </div>
      </div>

      <!-- ================= 标签页 3：库存数据与变更履历 ================= -->
      <div v-show="currentTab === 'stock'" class="tab-panel-content">
        <!-- 当前库存余额 (编辑模式或有数据时展示) -->
        <section v-if="isEdit || balance.quantity_on_hand !== undefined" class="form-card balance-card">
          <div class="card-header">
            <div class="header-main">
              <span class="card-icon-badge"><i class="el-icon-coin" /></span>
              <div>
                <h2 class="card-title">当前库存余额</h2>
                <span class="card-subtitle">实时汇总量化账面库存、锁定数、不良品及可用库存状态</span>
              </div>
            </div>
          </div>

          <div class="card-body">
            <div class="balance-stats-strip">
              <div class="stat-col">
                <span class="stat-label">账面库存</span>
                <strong class="stat-val code-mono">{{ qty(balance.quantity_on_hand) || '-' }} {{ unitSymbol }}</strong>
              </div>
              <div class="stat-col">
                <span class="stat-label">已锁定 / 已预留</span>
                <strong class="stat-val code-mono">{{ qty(balance.quantity_locked) || '-' }} {{ unitSymbol }}</strong>
              </div>
              <div class="stat-col">
                <span class="stat-label">不良 / 待处理</span>
                <strong class="stat-val code-mono">{{ qty(balance.quantity_defective + balance.quantity_pending) || '-' }} {{ unitSymbol }}</strong>
              </div>
              <div class="stat-col">
                <span class="stat-label">可用库存</span>
                <strong class="stat-val code-mono text-green">{{ qty(balance.quantity_available) || '-' }} {{ unitSymbol }}</strong>
              </div>
              <div class="stat-col">
                <span class="stat-label">覆盖仓库数</span>
                <strong class="stat-val">{{ balance.warehouse_count === undefined || balance.warehouse_count === null ? '-' : balance.warehouse_count }} 个</strong>
              </div>
              <div class="stat-col last-col">
                <span class="stat-label">最近更新时间</span>
                <strong class="stat-val text-time code-mono">{{ date(balance.last_transaction_at) }}</strong>
              </div>
            </div>
          </div>
        </section>

        <!-- 录入模式提示卡 -->
        <div v-else class="new-mode-stock-hint">
          <i class="el-icon-info text-theme" />
          <span>当前为新增录入物料模式，保存档案并入库后将自动生成库存实时余额与账面明细。</span>
        </div>

        <!-- 未来采购带出预览 & 旧系统映射 (横向双列) -->
        <div class="bottom-tables-row">
          <!-- 1. 未来采购带出预览 -->
          <section class="form-card preview-table-card">
            <div class="card-header">
              <div class="header-main">
                <span class="card-icon-badge"><i class="el-icon-view" /></span>
                <div>
                  <h3 class="card-title">未来采购带出预览</h3>
                  <span class="card-subtitle">后续单据中根据本物料自动填报的属性</span>
                </div>
              </div>
            </div>
            <div class="card-body">
              <table class="simple-data-table">
                <thead>
                  <tr>
                    <th>采购用途</th>
                    <th>库存管理</th>
                    <th>经济归属</th>
                    <th>责任人</th>
                    <th>资产化</th>
                    <th>确认方式</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td>{{ preview.purpose || '-' }}</td>
                    <td :class="preview.stock === '是' ? 'text-green font-bold' : 'text-loss font-bold'">{{ preview.stock }}</td>
                    <td>{{ preview.route || '-' }}</td>
                    <td :class="preview.custodian === '是' ? 'text-green font-bold' : 'text-loss font-bold'">{{ preview.custodian }}</td>
                    <td :class="preview.capitalization === '是' ? 'text-green font-bold' : 'text-loss font-bold'">{{ preview.capitalization }}</td>
                    <td>{{ preview.next || '-' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>

          <!-- 2. 旧数据映射 -->
          <section class="form-card legacy-table-card">
            <div class="card-header">
              <div class="header-main">
                <span class="card-icon-badge"><i class="el-icon-connection" /></span>
                <div>
                  <h3 class="card-title">旧系统映射对照</h3>
                  <span class="card-subtitle">历史 ERP 物料数据编码对照</span>
                </div>
              </div>
            </div>
            <div class="card-body">
              <table class="simple-data-table">
                <thead>
                  <tr>
                    <th>旧系统物料编码</th>
                    <th>旧系统物料名称</th>
                    <th>旧系统单位</th>
                  </tr>
                </thead>
                <tbody>
                  <tr>
                    <td class="code-mono">{{ form.legacy_code || '-' }}</td>
                    <td>{{ form.legacy_name || '-' }}</td>
                    <td>{{ form.legacy_unit_name || '-' }}</td>
                  </tr>
                </tbody>
              </table>
            </div>
          </section>
        </div>

        <!-- 3. 归属策略变更记录 (全宽) -->
        <section class="form-card history-table-card">
          <div class="card-header">
            <div class="header-main">
              <span class="card-icon-badge"><i class="el-icon-time" /></span>
              <div>
                <h3 class="card-title">归属策略变更记录</h3>
                <span class="card-subtitle">物料会计属性历史版本追溯</span>
              </div>
            </div>
          </div>
          <div class="card-body">
            <table class="simple-data-table">
              <thead>
                <tr>
                  <th>变更时间</th>
                  <th>变更前策略</th>
                  <th>变更后策略</th>
                  <th>变更原因</th>
                  <th>变更人</th>
                </tr>
              </thead>
              <tbody>
                <template v-if="history && history.length">
                  <tr v-for="row in history" :key="row.id">
                    <td class="code-mono">{{ date(row.created_at) }}</td>
                    <td>{{ routeLabel(row.previous_route || row.previous_route_label) }}</td>
                    <td>{{ routeLabel(row.future_route || row.new_route || row.new_route_label) }}</td>
                    <td>{{ row.change_reason || '-' }}</td>
                    <td>{{ row.operator_name || '-' }}</td>
                  </tr>
                </template>
                <tr v-else><td colspan="5" class="empty-cell">暂无策略变更记录</td></tr>
              </tbody>
            </table>
          </div>
        </section>

        <!-- 步骤切换导航 -->
        <div class="tab-bottom-nav">
          <el-button size="small" icon="el-icon-back" @click="currentTab = 'policy'">返回上一页：{{ policyTitle }}</el-button>
        </div>
      </div>
    </el-form>

    <item-category-manager-dialog
      v-model="categoryDialogVisible"
      :management-scope="managementScope"
      @changed="onCategoriesChanged"
      @closed="onCategoriesClosed"
      @select-items="showCategoryItems"
    />

    <!-- 底部固定吸底操作栏 (全局唯一持久保存入口) -->
    <footer class="footer-bar">
      <div class="footer-left">
        <div class="footer-target-info">
          <span class="footer-icon"><i class="el-icon-box" /></span>
          <span class="footer-target-name">{{ form.item_name || '未命名物料' }}</span>
          <span v-if="form.item_code" class="footer-code-chip">
            <i class="el-icon-postcard" />
            <strong class="code-mono">{{ form.item_code }}</strong>
          </span>
        </div>
        <div class="footer-status-pill" :class="allValid ? 'is-valid' : 'is-pending'">
          <i :class="allValid ? 'el-icon-circle-check' : 'el-icon-warning-outline'" />
          <span>{{ allValid ? '核心必填与策略规则校验通过' : (!tab1Valid ? '基础信息尚有必填项未填写' : '物资归属策略待完善') }}</span>
        </div>
      </div>
      <div class="footer-actions">
        <el-button size="small" @click="$router.push(entryListPath)">取消并返回</el-button>
        <el-button size="small" :loading="saving" :disabled="loading || categoriesLoading || !!pageError" @click="save(false)">保存为草稿</el-button>
        <el-button type="primary" size="small" :loading="saving" :disabled="loading || categoriesLoading || !!pageError" class="btn-theme-submit" @click="save(true)">
          {{ isEdit ? '保存并启用' : '保存并启用' }}
        </el-button>
      </div>
    </footer>
  </main>
</template>

<script>
import cachedPageRoute from '../../../utils/cachedPageRoute'
import { materialScopes, routeMaterialScope, materialScopeLabel, materialListPath, materialTypesForScope } from '../../../utils/materialManagementScope.mjs'
import { getItemCategoryTree, listEntity, getItemIntegratedForm, saveItemIntegratedForm } from '../../../api/erp/master'
import { clearCreatePageReservation, reserveForCreatePage } from '../../../utils/documentNumberReservation'

const blankItem = () => ({
  management_scope: 'factory',
  item_code: '',
  item_name: '',
  item_type: '',
  category_id: null,
  unit_id: null,
  spec: '',
  material_grade: '',
  cutting_mode: 'none',
  material_management_mode: 'quantity',
  standard_stock_length_mm: null,
  is_length_cut_material: false,
  is_purchase_item: false,
  is_stock_item: false,
  is_production_item: false,
  manufacturing_strategy: 'unspecified',
  serial_number_prefix: '',
  cost_method: 'weighted_average',
  status: 'disabled',
  remark: '',
  base_unit_locked: false
})

const blankPolicy = () => ({
  template_code: '',
  is_stock_managed: false,
  inventory_management_mode: 'none',
  requires_custodian: false,
  is_returnable: false,
  requires_capitalization: false,
  serial_tracking_mode: '',
  post_purchase_action: '',
  consumption_confirmation_mode: '',
  future_route: '',
  future_bearer_type: '',
  change_reason: '',
  remark: ''
})

export default {
  mixins: [cachedPageRoute],
  name: 'ItemForm',
  components: {
    ItemCategoryManagerDialog: () => import('../../../components/master/ItemCategoryManagerDialog.vue')
  },
  data() {
    return {
      currentTab: 'basic',
      loading: false,
      pageError: '',
      categoriesLoading: false,
      categoryDialogVisible: false,
      categoriesDirty: false,
      lastCategoryChange: null,
      optionsVersion: 0,
      legacyCategory: null,
      managementScopes: materialScopes,
      saving: false,
      pageLoadVersion: 0,
      pageHasSaved: false,
      reservation: null,
      form: blankItem(),
      policy: blankPolicy(),
      balance: {},
      history: [],
      categories: [],
      units: [],
      templates: [
        { value: 'office_consumable', label: '消耗品 / 办公物资' },
        { value: 'inventory_material', label: '库存材料 / 产成品 / 标准件' },
        { value: 'low_value_custody', label: '低值责任物资 / 仪器工具' },
        { value: 'fixed_asset_pending', label: '固定资产待验收处理' },
        { value: 'direct_non_stock', label: '直接非库存费用化处理' }
      ],
      actions: [
        { value: 'issue_confirmation', label: '到货入库后领用确认' },
        { value: 'inventory_receipt', label: '到货入库' },
        { value: 'asset_acceptance', label: '资产验收（预留）' },
        { value: 'expense_confirmation', label: '费用确认（预留）' },
        { value: 'work_order_cost', label: '工单直接成本（预留）' },
        { value: 'sales_order_direct_cost', label: '订单直接费用（预留）' }
      ],
      routes: [
        {
          value: 'inventory',
          label: '库存材料/商品',
          help: '用于生产或销售的库存材料或商品，计入存货核算。',
          icon: 'el-icon-box'
        },
        {
          value: 'expense',
          label: '库存后领用消耗',
          help: '耗材备件入库，领用后一次性或按期计入费用。',
          icon: 'el-icon-notebook-2'
        },
        {
          value: 'asset',
          label: '固定资产待验收',
          help: '设备仪器类物资，采购到货后转固定资产验收。',
          icon: 'el-icon-office-building'
        },
        {
          value: 'direct_expense',
          label: '直接非库存处理',
          help: '不办理入库，到货直接计入对应使用部门费用。',
          icon: 'el-icon-wallet'
        }
      ],
      rules: {
        item_code: [{ required: true, message: '系统未取得 Item 编码' }],
        item_name: [{ required: true, message: '请输入物料名称', trigger: 'blur' }],
        item_type: [{ required: true, message: '请选择物料类型', trigger: 'change' }],
        category_id: [{ required: true, message: '请选择末级 Item 类目', trigger: 'change' }],
        unit_id: [{ required: true, message: '请选择库存基本单位', trigger: 'change' }],
        standard_stock_length_mm: [
          {
            validator: (rule, value, callback) => {
              if (this.form.cutting_mode === 'length' && !(Number(value) > 0)) {
                callback(new Error('请输入大于 0 的标准原料长度'))
              } else {
                callback()
              }
            },
            trigger: 'blur'
          }
        ]
      }
    }
  },
  computed: {
    canViewCategories() { return this.$can('item_category.view') },
    entryScope() { return routeMaterialScope(this.pageRoute) || 'factory' },
    managementScope() { return this.form.management_scope || this.entryScope },
    isOffice() { return this.managementScope === 'office' },
    scopeLabel() { return materialScopeLabel(this.managementScope) },
    policyTitle() { return this.isOffice ? '办公用品归属与费用' : '工厂物料归属与核算' },
    policySubtitle() {
      return this.isOffice
        ? '设置办公用品的备库、领用归属、责任保管与费用处理规则。'
        : '设置工厂材料的库存、车间领用、设备保管与成本归属规则。'
    },
    entryListPath() { return materialListPath(this.entryScope) },
    itemTypes() { return materialTypesForScope(this.managementScope) },
    templateOptions() {
      const labels = this.isOffice
        ? { office_consumable: '办公消耗品', inventory_material: '办公用品备库', low_value_custody: '可归还办公用品', fixed_asset_pending: '办公设备资产配置', direct_non_stock: '办公采购直接费用配置' }
        : { office_consumable: '车间消耗品', inventory_material: '材料 / 产成品 / 标准件备库', low_value_custody: '仪器 / 工具责任保管', fixed_asset_pending: '生产设备资产配置', direct_non_stock: '生产采购直接费用配置' }
      const rows = this.templates.map(template => ({ ...template, label: labels[template.value] || template.label }))
      if (this.policy.template_code && !rows.some(row => row.value === this.policy.template_code)) {
        const legacy = { inventory_goods: 'inventory_material', inventory_expense: 'office_consumable' }
        const alias = legacy[this.policy.template_code]
        rows.push({ value: this.policy.template_code, label: alias ? `${labels[alias]}（现有配置）` : '现有自定义管理方式' })
      }
      return rows
    },
    routeOptions() {
      const labels = this.isOffice ? {
        inventory: ['办公用品备库', '办公用品入库，保留数量、库位和收发记录。'],
        expense: ['办公领用消耗', '办公消耗品备库，配置领用确认和使用归属。'],
        asset: ['办公设备资产配置', '配置办公设备的资产化与验收要求，不自动办理资产入账。'],
        direct_expense: ['办公采购直接费用配置', '配置非库存办公采购的费用确认意向。']
      } : {
        inventory: ['生产材料 / 商品备库', '原材料、产成品和标准件入库，保留生产及销售的库存来源。'],
        expense: ['车间领用消耗', '车间耗材和备件备库，配置领用确认和使用归属。'],
        asset: ['生产设备资产配置', '配置生产设备和仪器的资产化与验收要求，不自动办理资产入账。'],
        direct_expense: ['生产采购直接费用配置', '配置非库存生产采购的费用确认意向。']
      }
      return this.routes.map(route => ({ ...route, label: labels[route.value][0], help: labels[route.value][1] }))
    },
    bearerOptions() {
      const rows = [
        { value: 'company', label: this.isOffice ? '公司办公共用' : '公司共用' },
        { value: 'department', label: this.isOffice ? '办公使用部门' : '车间 / 使用部门' },
        { value: 'employee', label: this.isOffice ? '员工保管责任' : '人员 / 工具保管责任' }
      ]
      if (!this.isOffice) rows.push(
        { value: 'work_order', label: '工单成本归属配置' },
        { value: 'sales_order', label: '销售订单成本归属配置' }
      )
      // Keep an incompatible historical value visible until the operator explicitly corrects it.
      if (this.policy.future_bearer_type && !rows.some(row => row.value === this.policy.future_bearer_type)) {
        rows.push({ value: this.policy.future_bearer_type, label: '原归属不适用，请重新选择', disabled: true })
      }
      return rows
    },
    bearerValid() {
      return this.bearerOptions.some(row => row.value === this.policy.future_bearer_type && !row.disabled)
    },
    policyScopeConflict() {
      return this.isOffice && (
        ['work_order', 'sales_order'].includes(this.policy.future_bearer_type) ||
        ['work_order_cost', 'sales_order_direct_cost'].includes(this.policy.future_route) ||
        ['work_order_cost', 'sales_order_direct_cost'].includes(this.policy.post_purchase_action) ||
        ['production_unit_created', 'routing_operation_completed'].includes(this.policy.serial_generation_stage) ||
        !!this.policy.serial_generation_routing_operation_id
      )
    },
    actionOptions() {
      const allowed = { inventory: 'inventory_receipt', expense: 'issue_confirmation', asset: 'asset_acceptance', direct_expense: 'expense_confirmation' }
      const labels = {
        inventory_receipt: this.isOffice ? '办公用品到货入库' : '生产物料到货入库',
        issue_confirmation: this.isOffice ? '办公用品入库后领用确认' : '物料入库后领用确认',
        asset_acceptance: this.isOffice ? '办公设备资产验收配置' : '生产设备资产验收配置',
        expense_confirmation: this.isOffice ? '办公采购费用确认配置' : '生产采购费用确认配置'
      }
      const rows = this.actions.filter(action => action.value === allowed[this.policy.future_route])
        .map(action => ({ ...action, label: labels[action.value] }))
      if (this.policy.post_purchase_action && !rows.some(row => row.value === this.policy.post_purchase_action)) {
        rows.push({ value: this.policy.post_purchase_action, label: '原处理方式不适用，请重新选择', disabled: true })
      }
      return rows
    },
    confirmationOptions() {
      const allowed = { inventory: 'none', expense: 'issue', asset: 'asset_acceptance', direct_expense: 'none' }
      const labels = { none: '无需领用确认', issue: this.isOffice ? '办公领用确认' : '物料领用确认', asset_acceptance: '资产验收配置' }
      const value = allowed[this.policy.future_route]
      const rows = value ? [{ value, label: labels[value] }] : []
      if (this.policy.consumption_confirmation_mode && this.policy.consumption_confirmation_mode !== value) {
        rows.push({ value: this.policy.consumption_confirmation_mode, label: '原确认方式不适用，请重新选择', disabled: true })
      }
      return rows
    },
    isEdit() {
      return !!this.pageRoute.params.id
    },
    enabled: {
      get() {
        return this.form.status === 'enabled'
      },
      set(value) {
        this.form.status = value ? 'enabled' : 'disabled'
      }
    },
    currentUnit() {
      return this.units.find(x => Number(x.id) === Number(this.form.unit_id)) || this.form.unit
    },
    unitSymbol() {
      return (this.currentUnit && (this.currentUnit.symbol || this.currentUnit.unit_name)) || '-'
    },
    currentTypeName() {
      const match = this.itemTypes.find(x => x.value === this.form.item_type)
      return match ? match.label : ''
    },
    currentCategoryName() {
      const match = this.categories.find(x => Number(x.id) === Number(this.form.category_id))
      return match ? match.category_name || match.full_path : ''
    },
    routeValid() {
      if (!this.policy.future_route) return false
      return this.policy.is_stock_managed
        ? ['inventory', 'expense'].includes(this.policy.future_route)
        : ['asset', 'direct_expense'].includes(this.policy.future_route)
    },
    actionValid() {
      const allowedActions = {
        inventory: ['inventory_receipt'],
        expense: ['issue_confirmation'],
        asset: ['asset_acceptance'],
        direct_expense: ['expense_confirmation']
      }
      const allowedConfirmations = {
        inventory: ['none'],
        expense: ['issue'],
        asset: ['asset_acceptance'],
        direct_expense: ['none']
      }
      const route = this.policy.future_route
      return (
        !!route &&
        (allowedActions[route] || []).includes(this.policy.post_purchase_action) &&
        (allowedConfirmations[route] || []).includes(this.policy.consumption_confirmation_mode)
      )
    },
    capitalizationValid() {
      return !this.policy.requires_capitalization || this.policy.future_route === 'asset'
    },
    returnableValid() {
      return !this.policy.is_returnable || this.policy.requires_custodian
    },
    tab1Valid() {
      const hasBasic = !!(this.form.item_name && this.form.item_type && this.form.category_id && this.form.unit_id)
      const hasLength = this.form.cutting_mode !== 'length' || Number(this.form.standard_stock_length_mm) > 0
      const hasSerial = !!this.policy.serial_tracking_mode
      return hasBasic && hasLength && hasSerial
    },
    tab2Valid() {
      return !!(
        this.policy.template_code &&
        this.routeValid &&
        this.actionValid &&
        this.capitalizationValid &&
        this.returnableValid &&
        this.policy.post_purchase_action &&
        this.policy.consumption_confirmation_mode &&
        this.bearerValid &&
        !this.policyScopeConflict
      )
    },
    allValid() {
      return this.tab1Valid && this.tab2Valid
    },
    routeText() {
      return (this.routeOptions.find(row => row.value === this.policy.future_route) || {}).label || ''
    },
    preview() {
      return {
        purpose: (this.actionOptions.find(row => row.value === this.policy.post_purchase_action && !row.disabled) || {}).label || '',
        stock: this.policy.is_stock_managed ? '是' : '否',
        route: this.routeText,
        custodian: this.policy.requires_custodian ? '是' : '否',
        capitalization: this.policy.requires_capitalization ? '是' : '否',
        next: (this.confirmationOptions.find(row => row.value === this.policy.consumption_confirmation_mode && !row.disabled) || {}).label || ''
      }
    }
  },
  async created() {
    await this.loadPage()
  },
  activated() {
    if (this.pageHasSaved) this.loadPage()
  },
  watch: {
    'pageRoute.params.id'() {
      this.loadPage()
    },
    'pageRoute.query.management_scope'() {
      this.loadPage()
    }
  },
  methods: {
    async loadPage() {
      const version = ++this.pageLoadVersion
      this.pageHasSaved = false
      this.currentTab = 'basic'
      this.form = { ...blankItem(), management_scope: this.entryScope, item_type: this.entryScope === 'office' ? 'office_consumable' : '' }
      this.policy = blankPolicy()
      this.reservation = null
      this.balance = {}
      this.history = []
      this.pageError = ''
      this.legacyCategory = null
      this.loading = true
      try {
        if (this.isEdit) await this.loadEdit(version)
        else await this.reserveCode(version)
        if (version === this.pageLoadVersion && !this.pageError) await this.loadOptions()
      } finally {
        if (version === this.pageLoadVersion) {
          this.loading = false
          await this.$nextTick()
          this.$refs.form?.clearValidate()
        }
      }
    },
    async loadOptions() {
      const version = ++this.optionsVersion
      const scope = this.managementScope
      this.categoriesLoading = true
      try {
        const [tree, units] = await Promise.all([
        this.canViewCategories ? getItemCategoryTree({ management_scope: scope }) : Promise.resolve({ data: { data: [] } }),
        listEntity('units', { page: 1, per_page: 100, status: 'enabled' })
      ])
      if (version !== this.optionsVersion || scope !== this.managementScope) return
      const flat = []
      const visit = rows =>
        (rows || []).forEach(x => {
          if (x.is_leaf && x.status === 'enabled') flat.push(x)
          visit(x.children)
        })
      visit(tree.data.data || [])
      if (this.legacyCategory && Number(this.form.category_id) === Number(this.legacyCategory.id) && this.form.category_scope_mismatch) {
        flat.push({ ...this.legacyCategory, full_path: `${this.legacyCategory.category_name}（历史分类，需调整）`, legacy_scope_mismatch: true })
      }
      this.categories = flat
      this.units = (units.data.data || []).filter(x => !x.is_legacy)
      } catch (e) {
        if (version === this.optionsVersion) this.$message.error(e.userMessage || '物料分类和单位加载失败')
      } finally {
        if (version === this.optionsVersion) this.categoriesLoading = false
      }
    },
    openCategories() {
      if (this.canViewCategories) this.categoryDialogVisible = true
    },
    onCategoriesChanged(change) {
      this.categoriesDirty = true
      this.lastCategoryChange = change
    },
    async onCategoriesClosed() {
      if (!this.categoriesDirty) return
      this.categoriesDirty = false
      const scope = this.managementScope
      const change = this.lastCategoryChange
      this.lastCategoryChange = null
      await this.loadOptions()
      if (scope !== this.managementScope) return
      if (this.form.category_id && !this.categories.some(row => Number(row.id) === Number(this.form.category_id))) this.form.category_id = null
      if (!this.form.category_id && change?.action === 'saved' && change.management_scope === scope) {
        const created = this.categories.find(row => Number(row.id) === Number(change.id))
        if (created) this.form.category_id = created.id
      }
    },
    showCategoryItems(row) {
      if (!this.$can('master.item.view')) return
      this.$router.push({ path: this.entryListPath, query: { category_id: row.id, management_scope: row.management_scope } })
    },
    changeScope(scope) {
      this.form.category_id = null
      this.form.category_scope_mismatch = false
      this.legacyCategory = null
      this.categories = []
      if (scope === 'office') {
        if (this.form.item_type !== 'service') this.form.item_type = 'office_consumable'
        this.form.is_production_item = false
        this.form.manufacturing_strategy = 'unspecified'
        this.form.cutting_mode = 'none'
        this.normalizeCuttingMode('none')
        this.clearIncompatibleOfficePolicy()
      } else if (this.form.item_type === 'office_consumable') {
        this.form.item_type = ''
      }
      this.loadOptions()
    },
    clearIncompatibleOfficePolicy() {
      if (!this.isOffice) return
      // A new scope must not silently assign costs to a different bearer or replace valid custody/asset settings.
      if (['work_order', 'sales_order'].includes(this.policy.future_bearer_type)) this.policy.future_bearer_type = ''
      if (['work_order_cost', 'sales_order_direct_cost'].includes(this.policy.future_route)) this.policy.future_route = ''
      if (['work_order_cost', 'sales_order_direct_cost'].includes(this.policy.post_purchase_action)) this.policy.post_purchase_action = ''
      if (['production_unit_created', 'routing_operation_completed'].includes(this.policy.serial_generation_stage) || this.policy.serial_generation_routing_operation_id) {
        this.policy.serial_generation_stage = 'before_finished_goods_posting'
        this.policy.serial_generation_routing_operation_id = null
      }
    },
    async reserveCode(version = this.pageLoadVersion) {
      try {
        const reservation = await reserveForCreatePage('item', `${materialListPath(this.entryScope)}/new`)
        if (version !== this.pageLoadVersion || this.isEdit) return
        this.reservation = reservation
        this.form.item_code = this.reservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || 'Item 编码预生成失败')
      }
    },
    async loadEdit(version = this.pageLoadVersion) {
      this.loading = true
      try {
        const scope = routeMaterialScope(this.pageRoute)
        const { data } = await getItemIntegratedForm(this.pageRoute.params.id, scope ? { management_scope: scope } : undefined)
        if (version !== this.pageLoadVersion) return
        this.form = {
          ...blankItem(),
          ...data.item,
          cutting_mode: data.item.cutting_mode || (data.item.is_length_cut_material ? 'length' : 'none')
        }
        this.normalizeCuttingMode(this.form.cutting_mode)
        this.legacyCategory = data.item.category_scope_mismatch ? data.item.category : null
        const current = data.policy.draft || data.policy.active
        this.policy = {
          ...blankPolicy(),
          ...(current || {}),
          serial_tracking_mode: (current && current.serial_tracking_mode) || data.item.serial_tracking_mode || ''
        }
        this.balance = data.balance || {}
        this.history = (data.history && data.history.data) || []
      } catch (e) {
        if (version === this.pageLoadVersion) this.pageError = e.userMessage || '物料数据加载失败'
        this.$message.error(e.userMessage || '物料数据加载失败')
      } finally {
        if (version === this.pageLoadVersion) this.loading = false
      }
    },
    toggleStockManaged() {
      this.policy.is_stock_managed = !this.policy.is_stock_managed
      this.normalizeStock()
    },
    normalizeStock() {
      if (this.policy.is_stock_managed) {
        this.policy.inventory_management_mode = 'standard'
        if (this.policy.future_route && !['inventory', 'expense'].includes(this.policy.future_route)) {
          this.applyRoute('inventory')
        }
      } else {
        this.policy.inventory_management_mode = 'none'
        if (this.policy.future_route === 'inventory') {
          this.applyRoute('direct_expense')
        }
      }
    },
    normalizeCuttingMode(value) {
      this.form.is_length_cut_material = value === 'length'
      this.form.material_management_mode = value === 'sheet' ? 'physical' : 'quantity'
      if (value !== 'length') this.form.standard_stock_length_mm = null
    },
    applyTemplate(value) {
      const map = {
        inventory_material: 'inventory',
        office_consumable: 'expense',
        low_value_custody: 'expense',
        fixed_asset_pending: 'asset',
        direct_non_stock: 'direct_expense'
      }
      this.applyRoute(map[value] || 'inventory')
      if (value === 'low_value_custody') {
        Object.assign(this.policy, { requires_custodian: true, is_returnable: true })
      }
    },
    applyRoute(value) {
      const route = value
      const defaults = {
        inventory: {
          is_stock_managed: true,
          post_purchase_action: 'inventory_receipt',
          consumption_confirmation_mode: 'none',
          requires_capitalization: false
        },
        expense: {
          is_stock_managed: true,
          post_purchase_action: 'issue_confirmation',
          consumption_confirmation_mode: 'issue',
          requires_capitalization: false
        },
        asset: {
          is_stock_managed: false,
          post_purchase_action: 'asset_acceptance',
          consumption_confirmation_mode: 'asset_acceptance',
          requires_capitalization: true
        },
        direct_expense: {
          is_stock_managed: false,
          post_purchase_action: 'expense_confirmation',
          consumption_confirmation_mode: 'none',
          requires_capitalization: false
        }
      }
      this.policy.future_route = route
      Object.assign(this.policy, defaults[route] || {})
      this.normalizeStock()
    },
    save(activate) {
      if (this.saving || this.loading || this.categoriesLoading || this.pageError) return
      const error =
        !this.form.item_name
          ? '请输入物料名称'
          : !this.form.item_type
          ? '请选择物料类型'
          : !this.form.category_id
          ? '请选择末级 Item 类目'
          : !this.form.unit_id
          ? '请选择库存基本单位'
          : this.form.cutting_mode === 'length' && !(Number(this.form.standard_stock_length_mm) > 0)
          ? '定长材料必须填写大于0的标准原料长度'
          : !this.policy.serial_tracking_mode
          ? '请选择序列号追溯策略'
          : !this.policy.template_code
          ? '请选择管理方式'
          : this.policyScopeConflict
          ? '办公用品不能使用工单、订单或生产归属配置，请重新选择'
          : !this.routeValid
          ? '请选择与库存管理一致的处理方式'
          : !this.actionValid
          ? '采购后处理或确认方式与当前处理规则不一致'
          : !this.capitalizationValid
          ? '需要资产化的物资必须选择固定资产待验收'
          : !this.policy.post_purchase_action
          ? '请选择采购后处理策略'
          : !this.policy.consumption_confirmation_mode
          ? '请选择消耗/确认方式'
          : !this.bearerValid
          ? '请选择适用于当前管理类型的使用归属'
          : !this.returnableValid
          ? '可归还物资必须启用责任人管理'
          : ''

      if (error) {
        if (!this.tab1Valid) this.currentTab = 'basic'
        else if (!this.tab2Valid) this.currentTab = 'policy'
        return this.$message.error(error)
      }

      this.$refs.form.validate(async valid => {
        if (!valid) {
          if (!this.tab1Valid) this.currentTab = 'basic'
          return
        }
        this.saving = true
        try {
          const itemPayload = { ...this.form }
          delete itemPayload.base_unit_locked
          const payload = {
            item: {
              ...itemPayload,
              reservation_token: this.reservation && this.reservation.reservation_token,
              creation_session_id: this.reservation && this.reservation.creation_session_id
            },
            policy: {
              ...this.policy,
              inventory_management_mode: this.policy.is_stock_managed ? 'standard' : 'none'
            },
            activate
          }
          const scope = routeMaterialScope(this.pageRoute)
          const { data } = await saveItemIntegratedForm(this.form.id, payload, this.isEdit && scope ? { management_scope: scope } : undefined)
          if (!this.isEdit) clearCreatePageReservation(this.reservation)
          this.form = { ...this.form, ...data.data }
          this.pageHasSaved = true
          this.$message.success(data.message || '保存成功')
          const savedPath = `${materialListPath(data.data.management_scope || this.managementScope)}/${data.data.id}/edit`
          if (this.pageRoute.path !== savedPath || scope) this.$router.push(savedPath)
        } catch (e) {
          if (!this.isEdit && this.reservation && e.response?.status === 422 && e.response?.data?.errors?.['item.item_code']) {
            clearCreatePageReservation(this.reservation)
            await this.reserveCode()
            this.$message.warning('原物料编号已不可用，已重新预占编号，请核对后保存。')
            return
          }
          this.$message.error(e.userMessage || '保存失败')
        } finally {
          this.saving = false
        }
      })
    },
    unitLabel(row) {
      return `${row.unit_name || ''} (${row.symbol || row.unit_code || ''})`.trim()
    },
    qty(value) {
      if (value === null || value === undefined || value === '') return ''
      const numeric = Number(value)
      return Number.isFinite(numeric) ? numeric.toFixed(2) : ''
    },
    date(value) {
      return value ? String(value).replace('T', ' ').slice(0, 16) : '-'
    },
    routeLabel(value) {
      return (
        {
          inventory: '库存材料/商品',
          expense: '库存后领用消耗',
          asset: '固定资产待验收',
          direct_expense: '直接非库存处理',
          work_order_cost: '工单成本意图',
          sales_order_direct_cost: '订单直接费用意图'
        }[value] || '-'
      )
    }
  }
}
</script>

<style scoped>
.item-form-page {
  min-width: 0;
  max-width: 100%;
  min-height: calc(100vh - 54px);
  padding: 14px 20px 75px;
  background: #f5f7fa;
  color: #1f2937;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  box-sizing: border-box;
}

/* 高清等宽数字与编码字体规范 */
.code-mono,
.code-tabular-input ::v-deep .el-input__inner {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif !important;
  font-variant-numeric: tabular-nums;
  font-weight: 600;
  letter-spacing: 0.5px;
}

.code-tabular-input ::v-deep .el-input__inner {
  background-color: #f9fafb !important;
  color: #111827 !important;
  border-color: #e5e7eb !important;
}

/* 顶部轻量头部 */
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

.title-row .el-tag {
  max-width: 100%;
  overflow: hidden;
  text-overflow: ellipsis;
  box-sizing: border-box;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
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

.head-code-chip strong { min-width: 0; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

.head-actions {
  display: flex;
  gap: 10px;
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

/* 顶部紧凑分栏切换导航 */
.form-tabs-header {
  display: flex;
  gap: 8px;
  background: #ffffff;
  padding: 6px;
  border: 1px solid #e5e7eb;
  border-radius: 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  margin-bottom: 14px;
  box-sizing: border-box;
}

.tab-btn {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 8px;
  padding: 9px 16px;
  background: transparent;
  border: none;
  border-radius: 6px;
  font-size: 13px;
  font-weight: 600;
  color: #4b5563;
  cursor: pointer;
  transition: all 0.2s ease;
  white-space: nowrap;
}

.tab-btn i {
  font-size: 15px;
}

.tab-btn:hover {
  background: #f0fdf4;
  color: #008b4b;
}

.tab-btn.active {
  background: #008b4b;
  color: #ffffff;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.25);
}

.tab-badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 18px;
  height: 18px;
  border-radius: 50%;
  font-size: 11px;
}

.tab-btn.active .tab-badge.pass {
  background: rgba(255, 255, 255, 0.25);
  color: #ffffff;
}

.tab-badge.pass {
  background: #dcfce7;
  color: #008b4b;
}

.tab-badge.warn {
  background: #fef3c7;
  color: #d97706;
}

.tab-count-tag {
  background: #f3f4f6;
  color: #4b5563;
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 10px;
}

.tab-btn.active .tab-count-tag {
  background: rgba(255, 255, 255, 0.2);
  color: #ffffff;
}

/* 标签面板容器 */
.tab-panel-content {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

/* 通用卡片容器 */
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

.card-body {
  padding: 16px;
}

/* 模块一：4列紧凑网格 */
.fields-grid-4 {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 12px 16px;
}

.fields-grid-4 ::v-deep .el-form-item {
  margin-bottom: 0;
}

.fields-grid-4 ::v-deep .el-form-item__label {
  font-size: 12px;
  font-weight: 600;
  color: #374151;
  padding-bottom: 3px;
  line-height: 1.2;
}

.col-span-2 {
  grid-column: span 2;
}

.col-span-full {
  grid-column: 1 / -1;
}

.full-width {
  width: 100%;
}

.cutting-radio-group {
  width: 100%;
  display: flex;
}

.cutting-radio-group ::v-deep .el-radio-button {
  flex: 1;
}

.cutting-radio-group ::v-deep .el-radio-button__inner {
  width: 100%;
  padding: 8px 10px;
  text-align: center;
}

.cutting-radio-group ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background-color: #008b4b;
  border-color: #008b4b;
  box-shadow: -1px 0 0 0 #008b4b;
}

.status-switch-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 32px;
}

.switch-status-text {
  font-size: 12px;
  color: #9ca3af;
  font-weight: 500;
}

.switch-status-text.is-active {
  color: #008b4b;
  font-weight: 600;
}

/* 业务属性与单件追溯并列 */
.prop-serial-grid {
  display: grid;
  grid-template-columns: 1.2fr 0.8fr;
  gap: 14px;
}

.prop-tri-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 10px;
}

.prop-tri-grid.office-props {
  grid-template-columns: repeat(2, minmax(0, 1fr));
}

.prop-option-card {
  display: flex;
  flex-direction: column;
  gap: 4px;
  padding: 10px 12px;
  background: #fcfcfd;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.prop-option-card:hover {
  border-color: #bbf7d0;
  background: #fafffc;
}

.prop-option-card.active {
  background: #f0fdf4;
  border: 1.5px solid #008b4b;
}

.prop-card-top {
  display: flex;
  align-items: center;
  gap: 6px;
}

.prop-title {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
}

.prop-desc {
  margin: 0;
  font-size: 11px;
  color: #6b7280;
  line-height: 1.35;
}

.prop-option-card ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}

.serial-form-inline {
  display: flex;
  gap: 12px;
}

.flex-item {
  flex: 1;
  margin-bottom: 0 !important;
}

.serial-form-inline ::v-deep .el-form-item__label {
  font-size: 12px;
  font-weight: 600;
  color: #374151;
  padding-bottom: 3px;
}

.serial-rules-compact {
  margin-top: 10px;
  font-size: 11px;
  color: #6b7280;
  line-height: 1.4;
  display: flex;
  align-items: center;
  gap: 4px;
}

/* 标签底部引导栏 */
.tab-bottom-nav {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 8px 12px;
  background: #ffffff;
  border: 1px dashed #d1fae5;
  border-radius: 6px;
}

.nav-hint {
  font-size: 12px;
  color: #6b7280;
}

.btn-step-next {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-step-next:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 模块二：物资归属与财务配置 */
.policy-scope-alert {
  margin-bottom: 16px;
}

.policy-quick-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 14px;
  padding-bottom: 14px;
  border-bottom: 1px solid #edf1f5;
  margin-bottom: 14px;
  flex-wrap: wrap;
}

.quick-unit {
  display: flex;
  align-items: center;
  gap: 8px;
}

.quick-label {
  font-size: 13px;
  font-weight: 600;
  color: #374151;
  white-space: nowrap;
}

.quick-unit.template-unit ::v-deep .el-select {
  width: 250px;
}

.quick-switches-group {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.switch-card-item {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  background: #f9fafb;
  border: 1px solid #e5e7eb;
  border-radius: 6px;
}

.switch-card-item.disabled {
  opacity: 0.6;
}

.switch-title {
  font-size: 12px;
  color: #374151;
  font-weight: 500;
}

.switch-box {
  display: flex;
  align-items: center;
  gap: 6px;
}

.switch-status {
  font-size: 12px;
  color: #6b7280;
  min-width: 24px;
}

.policy-columns-wrap {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 280px;
  gap: 16px;
}

.inner-subtitle {
  margin: 0 0 8px 0;
  font-size: 13px;
  font-weight: 700;
  color: #1f2937;
}

.routes-card-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 10px;
  margin-bottom: 12px;
}

.route-card-item {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 10px;
  background: #ffffff;
  border: 1px solid #e5eaf2;
  border-radius: 6px;
  cursor: pointer;
  text-align: left;
  transition: all 0.2s ease;
}

.route-card-item:hover {
  border-color: #008b4b;
}

.route-card-item.active {
  border: 1.5px solid #008b4b;
  background: #f0fdf4;
}

.route-icon-box {
  width: 30px;
  height: 30px;
  border-radius: 6px;
  display: grid;
  place-items: center;
  background: #e6f9f0;
  color: #008b4b;
  flex-shrink: 0;
}

.route-texts {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.route-name {
  font-size: 12px;
  font-weight: 700;
  color: #111827;
}

.route-desc {
  margin: 0;
  font-size: 11px;
  color: #6b7280;
  line-height: 1.3;
}

.route-check-icon {
  position: absolute;
  top: 6px;
  right: 6px;
  color: #008b4b;
  font-size: 15px;
}

.cost-tags-section {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 6px 10px;
  background: #fafbfc;
  border: 1px solid #edf1f5;
  border-radius: 6px;
  margin-bottom: 12px;
  flex-wrap: wrap;
}

.cost-tags-label {
  font-size: 12px;
  color: #6b7280;
  white-space: nowrap;
  font-weight: 500;
}

.cost-tags-list {
  display: flex;
  flex-wrap: wrap;
  gap: 6px;
}

.cost-tag-pill {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 6px;
  background: #ffffff;
  border: 1px solid #d1fae5;
  border-radius: 4px;
  color: #065f46;
  font-size: 11px;
  font-weight: 500;
}

.tag-badge {
  background: #e6f9f0;
  color: #008b4b;
  padding: 1px 3px;
  border-radius: 3px;
  font-size: 9px;
}

.policy-detail-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 10px 12px;
}

.policy-columns-wrap > *,
.policy-detail-grid > *,
.prop-serial-grid > * {
  min-width: 0;
}

.policy-detail-grid ::v-deep .el-form-item {
  margin-bottom: 0;
}

.policy-detail-grid ::v-deep .el-form-item__label {
  font-size: 12px;
  font-weight: 600;
  color: #374151;
  padding-bottom: 2px;
}

.input-search-icon {
  color: #9ca3af;
  margin-top: 8px;
  margin-right: 6px;
}

/* 右侧合规校验 */
.policy-right-aside {
  display: flex;
  flex-direction: column;
  gap: 12px;
  background: #fafbfc;
  border: 1px solid #edf1f5;
  border-radius: 6px;
  padding: 12px;
  box-sizing: border-box;
}

.aside-title {
  margin: 0 0 8px 0;
  font-size: 13px;
  font-weight: 700;
  color: #1f2937;
  display: flex;
  align-items: center;
  gap: 6px;
}

.val-check-list {
  display: flex;
  flex-direction: column;
  gap: 7px;
}

.val-check-row {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 500;
}

.val-check-row.pass {
  color: #008b4b;
}

.val-check-row.fail {
  color: #ef4444;
}

.check-icon {
  font-size: 14px;
}

.strategy-flow-box {
  border-top: 1px solid #edf1f5;
  padding-top: 10px;
}

.flowchart-steps {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 4px;
  margin-top: 6px;
}

.flow-step {
  display: flex;
  flex: 1 1 0;
  min-width: 0;
  flex-direction: column;
  align-items: center;
  gap: 4px;
}

.flow-circle-icon {
  width: 30px;
  height: 30px;
  border-radius: 50%;
  background: #e6f9f0;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 14px;
}

.flow-yen {
  font-size: 14px;
  font-weight: 700;
}

.flow-step-label {
  font-size: 10px;
  color: #4b5563;
  text-align: center;
  max-width: 100%;
  line-height: 1.4;
  white-space: normal;
  overflow-wrap: anywhere;
}

.flow-arrow {
  color: #9ca3af;
  font-size: 12px;
  flex-shrink: 0;
  margin-top: 8px;
}

/* 模块三：当前库存与履历 */
.balance-stats-strip {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  background: #fcfcfd;
  border: 1px solid #edf1f5;
  border-radius: 6px;
  overflow: hidden;
}

.stat-col {
  padding: 10px 12px;
  border-right: 1px solid #edf1f5;
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.stat-col.last-col {
  border-right: none;
}

.stat-label {
  font-size: 11px;
  color: #6b7280;
}

.stat-val {
  font-size: 14px;
  font-weight: 700;
  color: #111827;
}

.text-time {
  font-size: 12px;
  color: #4b5563;
}

.new-mode-stock-hint {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 12px 16px;
  background: #ffffff;
  border: 1px dashed #d1d5db;
  border-radius: 8px;
  color: #4b5563;
  font-size: 13px;
}

.bottom-tables-row {
  display: grid;
  grid-template-columns: 1.2fr 0.8fr;
  gap: 14px;
}

.simple-data-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 12px;
  text-align: center;
}

.simple-data-table th {
  background: #f8fafc;
  color: #4b5563;
  font-weight: 600;
  height: 30px;
  padding: 3px 6px;
  border: 1px solid #edf1f5;
  white-space: nowrap;
}

.simple-data-table td {
  height: 32px;
  padding: 3px 6px;
  border: 1px solid #edf1f5;
  color: #1f2937;
  white-space: nowrap;
}

.empty-cell {
  color: #9ca3af;
}

.text-green {
  color: #008b4b !important;
}

.text-loss {
  color: #ef4444 !important;
}

.font-bold {
  font-weight: 700;
}

.text-theme {
  color: #008b4b !important;
}

/* 底部固定吸底操作栏 (全局唯一提交入口) */
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

.footer-status-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 9px;
  border-radius: 20px;
  font-size: 12px;
  font-weight: 500;
}

.footer-status-pill.is-valid {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
}

.footer-status-pill.is-pending {
  background: #fffbeb;
  color: #d97706;
  border: 1px solid #fde68a;
}

.footer-actions {
  display: flex;
  gap: 10px;
}

.btn-theme-submit {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
}

.btn-theme-submit:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 响应式断点适配规则 (强制遵循：移动端 320px 到 PC 2560px 全覆盖) */
@media (max-width: 1400px) {
  .routes-card-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .bottom-tables-row {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 1100px) {
  .fields-grid-4 {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
  .prop-serial-grid {
    grid-template-columns: minmax(0, 1fr);
  }
  .policy-columns-wrap {
    grid-template-columns: minmax(0, 1fr);
  }
  .balance-stats-strip {
    grid-template-columns: repeat(3, 1fr);
  }
}

@media (max-width: 768px) {
  .tab-bottom-nav { flex-wrap: wrap; min-width: 0; gap: 10px; }
  .tab-bottom-nav .el-button { width: 100%; max-width: 100%; margin-left: 0; white-space: normal; }
  .nav-hint { min-width: 0; max-width: 100%; }
  .quick-unit { flex-wrap: wrap; min-width: 0; max-width: 100%; }
  .quick-unit.template-unit ::v-deep .el-select { width: 100%; max-width: 100%; }
  .item-form-page {
    padding: 10px 10px 90px;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 8px;
  }
  .head-actions {
    width: 100%;
  }
  .head-actions .el-button {
    width: 100%;
  }
  .form-tabs-header {
    flex-direction: column;
    gap: 4px;
  }
  .tab-btn {
    width: 100%;
    justify-content: flex-start;
  }
  .fields-grid-4 {
    grid-template-columns: 1fr;
  }
  .col-span-2,
  .col-span-full {
    grid-column: 1 / -1;
  }
  .prop-tri-grid, .prop-tri-grid.office-props {
    grid-template-columns: 1fr;
  }
  .serial-form-inline {
    flex-direction: column;
  }
  .routes-card-grid {
    grid-template-columns: 1fr;
  }
  .policy-detail-grid {
    grid-template-columns: minmax(0, 1fr);
  }
  .balance-stats-strip {
    grid-template-columns: repeat(2, 1fr);
  }
  .footer-bar {
    flex-direction: column;
    align-items: flex-start;
    gap: 10px;
    padding: 10px 14px;
  }
  .footer-actions {
    width: 100%;
    justify-content: flex-end;
    flex-wrap: wrap;
    min-width: 0;
  }
  .footer-actions .el-button { flex: 1 1 120px; max-width: 100%; margin-left: 0; }
}
</style>
