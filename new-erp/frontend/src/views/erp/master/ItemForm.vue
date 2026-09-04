<template>
  <main class="item-form-page" v-loading="loading">
    <!-- Top Breadcrumb + Header + Actions -->
    <div class="page-topbar">
      <div class="breadcrumb">
        <span>主数据中心</span> <i>/</i> <span>物料管理</span> <i>/</i> <b>{{ isEdit ? '编辑物料' : '新增物料' }}</b>
      </div>
      <div class="top-actions">
        <el-button size="small" class="btn-plain" @click="$router.push('/master/items')">返回列表</el-button>
        <el-button size="small" class="btn-plain" :loading="saving" @click="save(false)">保存草稿</el-button>
        <el-button size="small" type="success" class="btn-primary-green" :loading="saving" @click="save(true)">保存并启用</el-button>
      </div>
    </div>

    <!-- Top Alert Notification Banner -->
    <div v-if="showAlert" class="info-alert-banner">
      <div class="alert-content">
        <i class="el-icon-info alert-icon" />
        <span>库存管理与经济归属独立配置：采购只做预处理策略，采维护会计科目。</span>
      </div>
      <i class="el-icon-close alert-close-btn" @click="showAlert = false" />
    </div>

    <el-form ref="form" :model="form" :rules="rules" size="small" label-position="left" label-width="100px">
      <!-- Row 1: Top 3 Cards (基础信息 | 采购/库存属性 | 单件追溯) -->
      <div class="top-cards-grid">
        <!-- 1. 基础信息 -->
        <article class="form-card basic-card">
          <h2 class="card-title">基础信息</h2>
          <div class="basic-fields">
            <el-form-item label="Item编码" prop="item_code" class="form-item-full">
              <el-input :value="form.item_code" disabled class="input-disabled" />
            </el-form-item>

            <el-form-item label="物料名称" prop="item_name" required class="form-item-full">
              <el-input v-model.trim="form.item_name" placeholder="请输入物料名称，例如：商务办公 A4 纸" />
            </el-form-item>

            <div class="form-row-2col">
              <el-form-item label="物料类型" prop="item_type" required>
                <el-select v-model="form.item_type" placeholder="请选择物料类型">
                  <el-option v-for="type in itemTypes" :key="type.value" :label="type.label" :value="type.value" />
                </el-select>
              </el-form-item>

              <el-form-item label="Item类目" prop="category_id" required>
                <el-select v-model="form.category_id" filterable placeholder="请选择类目">
                  <el-option v-for="row in categories" :key="row.id" :label="row.full_path" :value="row.id" />
                </el-select>
              </el-form-item>
            </div>

            <div class="form-row-2col">
              <el-form-item label="库存基本单位" prop="unit_id" required>
                <el-select v-model="form.unit_id" :disabled="form.base_unit_locked" placeholder="选择单位">
                  <el-option v-for="row in units" :key="row.id" :label="unitLabel(row)" :value="row.id" />
                </el-select>
              </el-form-item>

              <el-form-item label="规格型号">
                <el-input v-model.trim="form.spec" placeholder="例如：A4 70g 500张/包" />
              </el-form-item>
            </div>

            <el-form-item label="启用状态" class="form-item-switch">
              <div class="switch-field-wrap">
                <el-switch v-model="enabled" active-color="#008b4b" inactive-color="#dcdfe6" />
                <span class="switch-label-text">{{ enabled ? '启用' : '停用' }}</span>
              </div>
            </el-form-item>

            <el-form-item label="备注" class="form-item-full remark-item">
              <el-input
                v-model="form.remark"
                type="textarea"
                :rows="2"
                maxlength="200"
                show-word-limit
                placeholder="用于日常打印、复印等办公场景。"
              />
            </el-form-item>
          </div>
        </article>

        <!-- 2. 采购 / 库存属性 -->
        <article class="form-card property-card">
          <h2 class="card-title">采购 / 库存属性</h2>
          <div class="property-checkbox-row">
            <el-checkbox v-model="form.is_purchase_item" class="custom-checkbox">可采购</el-checkbox>
            <el-checkbox v-model="policy.is_stock_managed" class="custom-checkbox" @change="normalizeStock">库存管理</el-checkbox>
            <el-checkbox v-model="form.is_production_item" class="custom-checkbox">生产使用</el-checkbox>
          </div>

          <div class="property-notes">
            <p><strong>可采购：</strong>允许通过采购申请或采购订单采购本物料。</p>
            <p><strong>库存管理：</strong>物料纳入仓库管理，可进行收发存变动。</p>
            <p><strong>生产使用：</strong>可作为生产领料来源，用于生产工单领料。</p>
          </div>
        </article>

        <!-- 3. 单件追溯 -->
        <article class="form-card serial-card">
          <h2 class="card-title">单件追溯</h2>
          <div class="serial-fields">
            <div class="serial-item">
              <label class="field-top-label">序列号策略</label>
              <el-select v-model="policy.serial_tracking_mode" placeholder="选择策略" style="width: 100%;">
                <el-option label="无需序列号" value="none" />
                <el-option label="按需逐件编号" value="optional" />
                <el-option label="必须逐件编号" value="required" />
              </el-select>
            </div>

            <div class="serial-item">
              <label class="field-top-label">系统编号前缀</label>
              <el-input v-model.trim="form.serial_number_prefix" maxlength="30" placeholder="例如：BW" />
            </div>

            <div class="serial-notes">
              <p><strong>无需序列号：</strong>不跟踪每件物料的唯一序列号。</p>
              <p><strong>系统自动生成编号：</strong>出入库时系统自动生成内部编号。</p>
            </div>
          </div>
        </article>
      </div>

      <!-- Row 2: Middle Section (物资归属配置 + 保存前校验/策略说明) -->
      <div class="policy-main-grid">
        <!-- Left Card: 物资归属配置 -->
        <article class="form-card policy-card">
          <h2 class="card-title">物资归属配置</h2>

          <!-- Inline Switch Controls -->
          <div class="policy-top-controls">
            <div class="control-unit template-unit">
              <label>物资管理属性（模板）</label>
              <el-select v-model="policy.template_code" size="small" @change="applyTemplate">
                <el-option v-for="row in templates" :key="row.value" :label="row.label" :value="row.value" />
              </el-select>
            </div>

            <div class="control-unit">
              <label>是否需要责任人</label>
              <div class="switch-inline">
                <el-switch v-model="policy.requires_custodian" active-color="#008b4b" inactive-color="#dcdfe6" />
                <span class="switch-text">{{ policy.requires_custodian ? '是' : '否' }}</span>
              </div>
            </div>

            <div class="control-unit">
              <label>是否可归还</label>
              <div class="switch-inline">
                <el-switch v-model="policy.is_returnable" :disabled="!policy.requires_custodian" active-color="#008b4b" inactive-color="#dcdfe6" />
                <span class="switch-text">{{ policy.is_returnable ? '是' : '否' }}</span>
              </div>
            </div>

            <div class="control-unit">
              <label>是否需要资产化</label>
              <div class="switch-inline">
                <el-switch v-model="policy.requires_capitalization" active-color="#008b4b" inactive-color="#dcdfe6" />
                <span class="switch-text">{{ policy.requires_capitalization ? '是' : '否' }}</span>
              </div>
            </div>
          </div>

          <!-- 4 Route Cards (默认经济归属) -->
          <div class="routes-section">
            <h3 class="section-subtitle">默认经济归属</h3>
            <div class="routes-card-grid">
              <button
                v-for="route in routes"
                :key="route.value"
                type="button"
                class="route-card-item"
                :class="{ active: policy.future_route === route.value }"
                @click="applyRoute(route.value)"
              >
                <div class="route-icon-box">
                  <svg v-if="route.value === 'inventory'" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"></path>
                    <polyline points="3.27 6.96 12 12.01 20.73 6.96"></polyline>
                    <line x1="12" y1="22.08" x2="12" y2="12"></line>
                  </svg>
                  <svg v-else-if="route.value === 'expense'" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8">
                    <path d="M16 4h2a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a2 2 0 0 1 2-2h2"></path>
                    <rect x="8" y="2" width="8" height="4" rx="1" ry="1"></rect>
                  </svg>
                  <svg v-else-if="route.value === 'asset'" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8">
                    <rect x="2" y="7" width="20" height="14" rx="2" ry="2"></rect>
                    <path d="M16 21V5a2 2 0 0 0-2-2h-4a2 2 0 0 0-2 2v16"></path>
                  </svg>
                  <svg v-else viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="1.8">
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
              </button>
            </div>
          </div>

          <!-- Possible Cost Allocation Intent Tags -->
          <div class="cost-tags-section">
            <span class="cost-tags-label">可能工单/销售单成本归集意向（预留）</span>
            <div class="cost-tags-list">
              <span v-for="label in ['直接费用', '直接人工', '制造费用', '项目成本', '销售费用', '管理费用']" :key="label" class="cost-tag-pill">
                {{ label }} <small class="tag-badge">预留</small>
              </span>
            </div>
          </div>

          <!-- Bottom 2-row Fields -->
          <div class="policy-bottom-fields">
            <div class="bottom-fields-row">
              <div class="field-item">
                <label>领用后归属</label>
                <el-select v-model="policy.future_bearer_type" placeholder="选择归属">
                  <el-option label="公司公共" value="company" />
                  <el-option label="部门办公费用" value="department" />
                  <el-option label="员工责任" value="employee" />
                  <el-option label="工单承担（预留）" value="work_order" />
                  <el-option label="销售订单承担（预留）" value="sales_order" />
                </el-select>
              </div>

              <div class="field-item">
                <label>默认承担部门</label>
                <el-input placeholder="后续按部门权限选择">
                  <i slot="suffix" class="el-icon-search input-search-icon" />
                </el-input>
              </div>

              <div class="field-item">
                <label>采购后处理策略</label>
                <el-select v-model="policy.post_purchase_action" placeholder="选择策略">
                  <el-option v-for="action in actions" :key="action.value" :label="action.label" :value="action.value" />
                </el-select>
              </div>
            </div>

            <div class="bottom-fields-row">
              <div class="field-item">
                <label>消耗/确认方式</label>
                <el-select v-model="policy.consumption_confirmation_mode" placeholder="选择确认方式">
                  <el-option label="按领用确认" value="issue" />
                  <el-option label="无需确认" value="none" />
                  <el-option label="资产验收" value="asset_acceptance" />
                  <el-option label="服务验收" value="service_acceptance" />
                </el-select>
              </div>

              <div class="field-item">
                <label class="required-label">变更原因</label>
                <el-select v-model="policy.change_reason" placeholder="请选择变更原因">
                  <el-option label="业务调整" value="业务调整" />
                  <el-option label="规范统一" value="规范统一" />
                  <el-option label="策略升级" value="策略升级" />
                  <el-option label="其他变更" value="其他变更" />
                </el-select>
              </div>

              <div class="field-item">
                <label>备注</label>
                <el-input v-model="policy.remark" maxlength="200" placeholder="请输入备注，最多 200 字" />
              </div>
            </div>
          </div>
        </article>

        <!-- Right Card: 保存前校验 & 策略说明 -->
        <aside class="form-card side-validation-card">
          <h2 class="card-title">保存前校验</h2>
          <div class="validation-items-list">
            <div class="val-check-row" :class="form.unit_id ? 'pass' : 'fail'">
              <i class="el-icon-circle-check check-icon" />
              <span>基本单位已设置</span>
            </div>
            <div class="val-check-row" :class="policy.template_code ? 'pass' : 'fail'">
              <i class="el-icon-circle-check check-icon" />
              <span>物资管理属性完整</span>
            </div>
            <div class="val-check-row" :class="routeValid && actionValid && capitalizationValid ? 'pass' : 'fail'">
              <i class="el-icon-circle-check check-icon" />
              <span>库存管理与归属策略一致</span>
            </div>
            <div class="val-check-row" :class="returnableValid ? 'pass' : 'fail'">
              <i class="el-icon-circle-check check-icon" />
              <span>责任人规则有效</span>
            </div>
            <div class="val-check-row pass">
              <i class="el-icon-circle-check check-icon" />
              <span>序列号规则有效</span>
            </div>
          </div>

          <div class="strategy-flow-section">
            <h3 class="section-subtitle">策略说明</h3>
            <div class="flowchart-steps">
              <div class="flow-step">
                <div class="flow-circle-icon">
                  <i class="el-icon-shopping-cart-2" />
                </div>
                <span class="flow-step-label">采购到货</span>
              </div>
              <span class="flow-arrow">→</span>
              <div class="flow-step">
                <div class="flow-circle-icon">
                  <i class="el-icon-box" />
                </div>
                <span class="flow-step-label">{{ preview.stock === '是' ? '库存管理' : '非库存处理' }}</span>
              </div>
              <span class="flow-arrow">→</span>
              <div class="flow-step">
                <div class="flow-circle-icon">
                  <i class="el-icon-user" />
                </div>
                <span class="flow-step-label">{{ preview.custodian === '是' ? '责任人领用' : '无需领用确认' }}</span>
              </div>
              <span class="flow-arrow">→</span>
              <div class="flow-step">
                <div class="flow-circle-icon">
                  <span class="flow-yen">¥</span>
                </div>
                <span class="flow-step-label">{{ preview.route || '-' }}</span>
              </div>
            </div>
          </div>
        </aside>
      </div>

      <!-- Row 3: 当前库存余额 (只读) -->
      <article class="form-card balance-summary-card">
        <h2 class="card-title">当前库存余额（只读）</h2>
        <div class="balance-stats-strip">
          <div class="stat-col">
            <span class="stat-label">账面库存</span>
            <strong class="stat-val">{{ qty(balance.quantity_on_hand) || '-' }} {{ unitSymbol }}</strong>
          </div>
          <div class="stat-col">
            <span class="stat-label">已锁定/已预留</span>
            <strong class="stat-val">{{ qty(balance.quantity_locked) || '-' }} {{ unitSymbol }}</strong>
          </div>
          <div class="stat-col">
            <span class="stat-label">不良/待处理</span>
            <strong class="stat-val">{{ qty(balance.quantity_defective + balance.quantity_pending) || '-' }} {{ unitSymbol }}</strong>
          </div>
          <div class="stat-col">
            <span class="stat-label">可用库存</span>
            <strong class="stat-val text-green">{{ qty(balance.quantity_available) || '-' }} {{ unitSymbol }}</strong>
          </div>
          <div class="stat-col">
            <span class="stat-label">覆盖仓库数</span>
            <strong class="stat-val">{{ balance.warehouse_count === undefined || balance.warehouse_count === null ? '-' : balance.warehouse_count }} 个</strong>
          </div>
          <div class="stat-col last-col">
            <span class="stat-label">最近更新时间</span>
            <strong class="stat-val text-time">{{ date(balance.last_transaction_at) }}</strong>
          </div>
        </div>
      </article>

      <!-- Row 4: 3 Lower Cards (未来采购带出预览 | 归属策略变更记录 | 旧数据映射) -->
      <div class="bottom-preview-grid">
        <!-- 1. 未来采购带出预览 -->
        <article class="form-card preview-table-card">
          <h2 class="card-title">未来采购带出预览</h2>
          <table class="simple-data-table">
            <thead>
              <tr>
                <th>采购用途</th>
                <th>库存管理</th>
                <th>经济归属</th>
                <th>是否责任人</th>
                <th>资产化</th>
                <th>后续业务</th>
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
        </article>

        <!-- 2. 归属策略变更记录 -->
        <article class="form-card history-table-card">
          <h2 class="card-title">归属策略变更记录</h2>
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
              <template v-if="history.length">
                <tr v-for="row in history" :key="row.id">
                  <td>{{ date(row.created_at) }}</td>
                  <td>{{ routeLabel(row.previous_route || row.previous_route_label) }}</td>
                  <td>{{ routeLabel(row.future_route || row.new_route || row.new_route_label) }}</td>
                  <td>{{ row.change_reason || '-' }}</td>
                  <td>{{ row.operator_name || '-' }}</td>
                </tr>
              </template>
              <tr v-else><td colspan="5">暂无策略变更记录</td></tr>
            </tbody>
          </table>
        </article>

        <!-- 3. 旧数据映射 -->
        <article class="form-card legacy-table-card">
          <h2 class="card-title">旧数据映射</h2>
          <table class="simple-data-table">
            <thead>
              <tr>
                <th>旧系统物料编码</th>
                <th>旧系统名称</th>
                <th>旧系统单位</th>
              </tr>
            </thead>
            <tbody>
              <tr>
                <td>{{ form.legacy_code || '-' }}</td>
                <td>{{ form.legacy_name || '-' }}</td>
                <td>{{ form.legacy_unit_name || '-' }}</td>
              </tr>
            </tbody>
          </table>
        </article>
      </div>
    </el-form>
  </main>
</template>

<script>
import { getItemCategoryTree, listEntity, getItemIntegratedForm, saveItemIntegratedForm } from '../../../api/erp/master'
import { reserveForCreatePage } from '../../../utils/documentNumberReservation'

const blankItem = () => ({
  item_code: '',
  item_name: '',
  item_type: '',
  category_id: null,
  unit_id: null,
  spec: '',
  is_purchase_item: false,
  is_stock_item: false,
  is_production_item: false,
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
  name: 'ItemForm',
  data() {
    return {
      loading: false,
      saving: false,
      showAlert: true,
      reservation: null,
      form: blankItem(),
      policy: blankPolicy(),
      balance: {},
      history: [],
      categories: [],
      units: [],
      itemTypes: [
        { value: 'office_consumable', label: '办公耗材' },
        { value: 'finished_product', label: '成品' },
        { value: 'semi_finished', label: '半成品' },
        { value: 'raw_material', label: '原材料' },
        { value: 'packaging', label: '包装物' },
        { value: 'service', label: '服务' }
      ],
      templates: [
        { value: 'office_consumable', label: '消耗品' },
        { value: 'inventory_material', label: '库存材料/商品' },
        { value: 'low_value_custody', label: '低值责任物资' },
        { value: 'fixed_asset_pending', label: '固定资产待处理' },
        { value: 'direct_non_stock', label: '直接非库存处理' }
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
          help: '用于生产或销售的库存材料或商品。',
          icon: 'el-icon-box'
        },
        {
          value: 'expense',
          label: '库存后领用消耗',
          help: '办公耗材领用后，领用后计入期间费用。',
          icon: 'el-icon-notebook-2'
        },
        {
          value: 'asset',
          label: '固定资产待验收',
          help: '资产类物料，采购到货后待验收流程。',
          icon: 'el-icon-office-building'
        },
        {
          value: 'direct_expense',
          label: '直接非库存处理',
          help: '不入库，直接计入使用的采购用途。',
          icon: 'el-icon-wallet'
        }
      ],
      rules: {
        item_code: [{ required: true, message: '系统未取得 Item 编码' }],
        item_name: [{ required: true, message: '请输入物料名称' }],
        item_type: [{ required: true, message: '请选择物料类型' }],
        category_id: [{ required: true, message: '请选择末级 Item 类目' }],
        unit_id: [{ required: true, message: '请选择库存基本单位' }]
      }
    }
  },
  computed: {
    isEdit() {
      return !!this.$route.params.id
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
      return !!route && (allowedActions[route] || []).includes(this.policy.post_purchase_action) && (allowedConfirmations[route] || []).includes(this.policy.consumption_confirmation_mode)
    },
    capitalizationValid() {
      return !this.policy.requires_capitalization || this.policy.future_route === 'asset'
    },
    returnableValid() {
      return !this.policy.is_returnable || this.policy.requires_custodian
    },
    routeText() {
      return {
        inventory: '库存材料/商品',
        expense: '库存后领用消耗',
        asset: '固定资产待验收',
        direct_expense: '直接非库存处理',
        work_order_cost: '工单成本意图',
        sales_order_direct_cost: '订单直接费用意图'
      }[this.policy.future_route] || ''
    },
    preview() {
      return {
        purpose: (this.actions.find(x => x.value === this.policy.post_purchase_action) || {}).label || '',
        stock: this.policy.is_stock_managed ? '是' : '否',
        route: this.routeText,
        custodian: this.policy.requires_custodian ? '是' : '否',
        capitalization: this.policy.requires_capitalization ? '是' : '否',
        next: {
          none: '无需确认',
          issue: '按领用确认',
          asset_acceptance: '资产验收',
          service_acceptance: '服务验收'
        }[this.policy.consumption_confirmation_mode] || ''
      }
    }
  },
  async created() {
    await this.loadOptions()
    if (this.isEdit) {
      await this.loadEdit()
    } else {
      await this.reserveCode()
    }
  },
  methods: {
    async loadOptions() {
      const [tree, units] = await Promise.all([
        getItemCategoryTree(),
        listEntity('units', { page: 1, per_page: 100, status: 'enabled' })
      ])
      const flat = []
      const visit = rows => (rows || []).forEach(x => {
        if (x.is_leaf && x.status === 'enabled') flat.push(x)
        visit(x.children)
      })
      visit(tree.data.data || [])
      this.categories = flat
      this.units = (units.data.data || []).filter(x => !x.is_legacy)
    },
    async reserveCode() {
      try {
        this.reservation = await reserveForCreatePage('item', '/master/items/new')
        this.form.item_code = this.reservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || 'Item 编码预生成失败')
      }
    },
    async loadEdit() {
      this.loading = true
      try {
        const { data } = await getItemIntegratedForm(this.$route.params.id)
        this.form = { ...blankItem(), ...data.item }
        const current = data.policy.draft || data.policy.active
        this.policy = {
          ...blankPolicy(),
          ...(current || {}),
          serial_tracking_mode: (current && current.serial_tracking_mode) || data.item.serial_tracking_mode || ''
        }
        this.balance = data.balance || {}
        this.history = (data.history && data.history.data) || []
      } catch (e) {
        this.$message.error(e.userMessage || '物料数据加载失败')
      } finally {
        this.loading = false
      }
    },
    normalizeStock() {
      if (this.policy.is_stock_managed) {
        this.policy.inventory_management_mode = 'standard'
        if (this.policy.future_route && !['inventory', 'expense'].includes(this.policy.future_route)) this.applyRoute('inventory')
      } else {
        this.policy.inventory_management_mode = 'none'
        if (this.policy.future_route === 'inventory') this.applyRoute('direct_expense')
      }
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
      const error = !this.policy.template_code ? '请选择物资管理属性模板' : !this.routeValid ? '请选择与库存管理一致的默认经济归属' : !this.actionValid ? '采购后处理策略与经济归属不一致' : !this.capitalizationValid ? '需要资产化的物资必须选择固定资产待验收' : !this.policy.serial_tracking_mode ? '请选择序列号策略' : !this.policy.post_purchase_action ? '请选择采购后处理策略' : !this.policy.consumption_confirmation_mode ? '请选择消耗/确认方式' : !this.policy.future_bearer_type ? '请选择领用后归属' : !this.returnableValid ? '可归还物资必须启用责任人管理' : ''
      if (error) return this.$message.error(error)
      this.$refs.form.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          const payload = {
            item: {
              ...this.form,
              reservation_token: this.reservation && this.reservation.token,
              creation_session_id: this.reservation && this.reservation.sessionId
            },
            policy: {
              ...this.policy,
              inventory_management_mode: this.policy.is_stock_managed ? 'standard' : 'none'
            },
            activate
          }
          const { data } = await saveItemIntegratedForm(this.form.id, payload)
          this.$message.success(data.message || '保存成功')
          this.$router.push(`/master/items/${data.data.id}/edit`)
        } catch (e) {
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
      return {
        inventory: '库存材料/商品',
        expense: '库存后领用消耗',
        asset: '固定资产待验收',
        direct_expense: '直接非库存处理',
        work_order_cost: '工单成本意图',
        sales_order_direct_cost: '订单直接费用意图'
      }[value] || '-'
    }
  }
}
</script>

<style scoped>
.item-form-page {
  min-height: calc(100vh - 54px);
  padding: 16px 20px 30px;
  background: #f5f7fa;
  color: #1f2937;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  box-sizing: border-box;
}

/* Top Bar: Breadcrumb + Buttons */
.page-topbar {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
}

.breadcrumb {
  font-size: 13px;
  color: #6b7280;
}

.breadcrumb i {
  font-style: normal;
  color: #9ca3af;
  margin: 0 6px;
}

.breadcrumb b {
  color: #1f2937;
  font-weight: 600;
}

.top-actions {
  display: flex;
  gap: 10px;
}

.btn-plain {
  background: #ffffff !important;
  border: 1px solid #d9d9d9 !important;
  color: #1f2937 !important;
}

.btn-plain:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.btn-primary-green {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-primary-green:hover {
  background: #007a41 !important;
  border-color: #007a41 !important;
}

/* Page Heading */
.page-heading {
  margin-bottom: 14px;
}

.page-heading h1 {
  margin: 0;
  font-size: 22px;
  font-weight: 700;
  color: #111827;
}

/* Alert Notification Banner */
.info-alert-banner {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 9px 14px;
  background: #e6f4ff;
  border: 1px solid #bae0ff;
  border-radius: 4px;
  color: #1677ff;
  font-size: 13px;
  margin-bottom: 14px;
}

.alert-content {
  display: flex;
  align-items: center;
  gap: 8px;
}

.alert-icon {
  font-size: 15px;
  color: #1677ff;
}

.alert-close-btn {
  cursor: pointer;
  color: #6b7280;
  font-size: 14px;
}

.alert-close-btn:hover {
  color: #111827;
}

/* Cards Base */
.form-card {
  background: #ffffff;
  border: 1px solid #e5eaf2;
  border-radius: 6px;
  padding: 16px 18px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.025);
  box-sizing: border-box;
  min-width: 0;
}

.card-title {
  margin: 0 0 14px 0;
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}

/* Top 3 Cards Grid */
.top-cards-grid {
  display: grid;
  grid-template-columns: 1.22fr 1.02fr 0.9fr;
  gap: 14px;
  margin-bottom: 14px;
}

/* Basic Fields */
.basic-fields {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.form-item-full {
  margin-bottom: 0 !important;
}

.form-row-2col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 12px;
}

.form-row-2col ::v-deep .el-form-item {
  margin-bottom: 0 !important;
}

.input-disabled ::v-deep .el-input__inner {
  background-color: #f9fafb !important;
  color: #6b7280 !important;
  border-color: #e5e7eb !important;
}

.form-item-switch {
  margin-bottom: 0 !important;
}

.switch-field-wrap {
  display: flex;
  align-items: center;
  gap: 8px;
  height: 32px;
}

.switch-label-text {
  font-size: 13px;
  color: #1f2937;
}

.remark-item ::v-deep .el-textarea__inner {
  font-family: inherit;
  resize: vertical;
}

/* Property Card */
.property-card {
  display: flex;
  flex-direction: column;
}

.property-checkbox-row {
  display: flex;
  gap: 20px;
  padding: 8px 0 14px;
  border-bottom: 1px solid #edf1f5;
  margin-bottom: 14px;
}

.custom-checkbox ::v-deep .el-checkbox__label {
  color: #1f2937;
  font-weight: 500;
}

.custom-checkbox ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner {
  background-color: #008b4b;
  border-color: #008b4b;
}

.property-notes {
  display: flex;
  flex-direction: column;
  gap: 10px;
  font-size: 12px;
  color: #6b7280;
  line-height: 1.6;
}

.property-notes p {
  margin: 0;
}

.property-notes strong {
  color: #374151;
}

/* Serial Card */
.serial-card {
  display: flex;
  flex-direction: column;
}

.serial-fields {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.field-top-label {
  display: block;
  font-size: 13px;
  color: #374151;
  margin-bottom: 6px;
}

.serial-notes {
  margin-top: 10px;
  padding-top: 10px;
  border-top: 1px solid #edf1f5;
  display: flex;
  flex-direction: column;
  gap: 8px;
  font-size: 12px;
  color: #6b7280;
  line-height: 1.55;
}

.serial-notes p {
  margin: 0;
}

.serial-notes strong {
  color: #374151;
}

/* Policy Main Grid (Middle Row) */
.policy-main-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 280px;
  gap: 14px;
  margin-bottom: 14px;
}

/* Policy Top Controls */
.policy-top-controls {
  display: flex;
  align-items: center;
  gap: 24px;
  padding-bottom: 16px;
  border-bottom: 1px solid #edf1f5;
  margin-bottom: 16px;
}

.control-unit {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
}

.control-unit.template-unit {
  min-width: 220px;
}

.control-unit label {
  color: #374151;
  white-space: nowrap;
}

.switch-inline {
  display: flex;
  align-items: center;
  gap: 6px;
}

.switch-text {
  color: #6b7280;
  font-size: 12px;
}

/* Routes Section */
.section-subtitle {
  font-size: 14px;
  font-weight: 600;
  color: #111827;
  margin: 0 0 10px 0;
}

.routes-card-grid {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: 12px;
  margin-bottom: 16px;
}

.route-card-item {
  position: relative;
  display: flex;
  align-items: flex-start;
  gap: 10px;
  padding: 12px;
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
  background: #fcfdfd;
}

.route-icon-box {
  width: 32px;
  height: 32px;
  border-radius: 6px;
  display: grid;
  place-items: center;
  background: #f0fdf4;
  color: #008b4b;
  flex-shrink: 0;
  margin-top: 2px;
}

.route-texts {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.route-name {
  font-size: 13px;
  font-weight: 700;
  color: #111827;
}

.route-desc {
  margin: 0;
  font-size: 11px;
  color: #6b7280;
  line-height: 1.4;
}

.route-check-icon {
  position: absolute;
  top: 8px;
  right: 8px;
  color: #008b4b;
  font-size: 16px;
}

/* Cost Allocation Intent Tags */
.cost-tags-section {
  display: flex;
  align-items: center;
  gap: 12px;
  padding: 8px 12px;
  background: #f8fafc;
  border: 1px solid #edf1f5;
  border-radius: 6px;
  margin-bottom: 16px;
}

.cost-tags-label {
  font-size: 12px;
  color: #6b7280;
  white-space: nowrap;
}

.cost-tags-list {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.cost-tag-pill {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 3px 8px;
  background: #ffffff;
  border: 1px solid #d1fae5;
  border-radius: 4px;
  color: #065f46;
  font-size: 12px;
  font-weight: 500;
}

.tag-badge {
  background: #e6f9f0;
  color: #008b4b;
  padding: 1px 4px;
  border-radius: 3px;
  font-size: 10px;
}

/* Policy Bottom Fields */
.policy-bottom-fields {
  display: flex;
  flex-direction: column;
  gap: 12px;
}

.bottom-fields-row {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 14px;
}

.field-item {
  display: flex;
  flex-direction: column;
  gap: 5px;
}

.field-item label {
  font-size: 12px;
  color: #374151;
  font-weight: 500;
}

.required-label:before {
  content: "* ";
  color: #ef4444;
}

.input-search-icon {
  color: #9ca3af;
  margin-top: 9px;
  margin-right: 8px;
}

/* Side Validation Card */
.side-validation-card {
  display: flex;
  flex-direction: column;
  justify-content: space-between;
}

.validation-items-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  padding-bottom: 16px;
  border-bottom: 1px solid #edf1f5;
}

.val-check-row {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  font-weight: 500;
}

.val-check-row.pass {
  color: #008b4b;
}

.val-check-row.fail {
  color: #ef4444;
}

.check-icon {
  font-size: 16px;
}

/* Strategy Flowchart */
.strategy-flow-section {
  margin-top: 14px;
}

.flowchart-steps {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 4px;
  margin-top: 10px;
}

.flow-step {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
}

.flow-circle-icon {
  width: 38px;
  height: 38px;
  border-radius: 50%;
  background: #e6f9f0;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 18px;
}

.flow-yen {
  font-size: 16px;
  font-weight: 700;
}

.flow-step-label {
  font-size: 11px;
  color: #4b5563;
  text-align: center;
  white-space: nowrap;
}

.flow-arrow {
  color: #9ca3af;
  font-size: 14px;
  margin-bottom: 18px;
}

/* Balance Summary Card (Row 3) */
.balance-summary-card {
  margin-bottom: 14px;
}

.balance-stats-strip {
  display: grid;
  grid-template-columns: repeat(6, 1fr);
  background: #fafbfc;
  border: 1px solid #edf1f5;
  border-radius: 4px;
  overflow: hidden;
}

.stat-col {
  padding: 12px 16px;
  border-right: 1px solid #edf1f5;
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.stat-col.last-col {
  border-right: none;
}

.stat-label {
  font-size: 12px;
  color: #6b7280;
}

.stat-val {
  font-size: 15px;
  font-weight: 700;
  color: #111827;
}

.text-time {
  font-size: 13px;
  color: #4b5563;
}

/* Bottom 3 Tables Grid (Row 4) */
.bottom-preview-grid {
  display: grid;
  grid-template-columns: 1.15fr 1.15fr 0.9fr;
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
  height: 32px;
  padding: 4px 8px;
  border: 1px solid #edf1f5;
  white-space: nowrap;
}

.simple-data-table td {
  height: 36px;
  padding: 4px 8px;
  border: 1px solid #edf1f5;
  color: #1f2937;
  white-space: nowrap;
}

/* Colors */
.text-green {
  color: #008b4b !important;
}

.text-loss {
  color: #ef4444 !important;
}

.font-bold {
  font-weight: 700;
}

/* Responsive */
@media (max-width: 1320px) {
  .top-cards-grid {
    grid-template-columns: 1fr;
  }
  .policy-main-grid {
    grid-template-columns: 1fr;
  }
  .bottom-preview-grid {
    grid-template-columns: 1fr;
  }
  .balance-stats-strip {
    grid-template-columns: repeat(3, 1fr);
  }
}
</style>
