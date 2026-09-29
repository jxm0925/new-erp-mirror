<template>
  <section class="sku-detail-page" v-loading="loading">
    <!-- 顶部统一页面头部卡片 -->
    <header class="page-head">
      <div class="head-left">
        <el-button size="small" icon="el-icon-back" class="btn-back" @click="goBack">返回</el-button>
        <span class="head-icon"><i class="el-icon-collection" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">SKU 详情</h1>
            <span v-if="sku && sku.sku_code" class="code-mono sku-code-tag">
              <i class="el-icon-postcard" />
              {{ sku.sku_code }}
            </span>
            <el-tag v-if="sku" size="small" :type="sku.status === 'enabled' ? 'success' : sku.status === 'draft' ? 'warning' : 'info'" effect="plain">
              {{ statusText(sku.status) }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-refresh" class="btn-refresh" @click="refresh">刷新</el-button>
        <el-button
          size="small"
          type="success"
          icon="el-icon-edit"
          class="btn-theme-create"
          @click="$router.push({ path: '/master/skus/' + id + '/edit', query: $route.query })"
        >
          编辑 SKU
        </el-button>
      </div>
    </header>

    <!-- 业务状态提示条 -->
    <div :class="['erp-page-tip', `tip-${noticeType}`]">
      <i :class="noticeType === 'success' ? 'el-icon-circle-check' : noticeType === 'warning' ? 'el-icon-warning-outline' : 'el-icon-info'" />
      <span>{{ itemNotice }}</span>
    </div>

    <!-- 详情主工作区 -->
    <main v-if="sku" class="content-grid">
      <!-- 左侧主体信息区 -->
      <div class="main-column">
        <!-- 基础规格卡片 -->
        <section class="detail-card">
          <div class="card-header">
            <div class="header-title">
              <i class="el-icon-postcard" />
              <h2>基础规格信息</h2>
            </div>
            <span class="type-pill" :class="`type-${sku.line_type || sku.order_line_type}`">
              {{ typeText(sku.line_type || sku.order_line_type) }}
            </span>
          </div>

          <div class="basic-body">
            <div class="image-wrap">
              <el-image v-if="imageUrl" :src="imageUrl" fit="cover" :preview-src-list="[imageUrl]" class="product-image">
                <div slot="error" class="image-empty"><i class="el-icon-picture-outline" /></div>
              </el-image>
              <div v-else class="product-image image-empty">
                <i class="el-icon-picture-outline" />
                <span>暂无图片</span>
              </div>
              <span class="image-label">SKU 展示图</span>
            </div>

            <div class="detail-grid">
              <div v-for="field in fields" :key="field.key" class="field-item">
                <span class="field-lbl">{{ field.label }}</span>
                <span class="field-val" :class="{ 'code-mono': field.key === 'sku_code' || field.key === 'sale_price' }">
                  {{ field.value || '—' }}
                </span>
              </div>
            </div>
          </div>
        </section>

        <!-- 默认 Item 物料关系与履历卡片 -->
        <section class="detail-card">
          <div class="card-header">
            <div class="header-title">
              <i class="el-icon-connection" />
              <h2>默认物料（Item）关系与履历</h2>
            </div>
            <el-button
              v-if="(sku.line_type || sku.order_line_type) === 'physical'"
              type="text"
              size="small"
              class="action-link-theme"
              @click="$router.push('/master/sku-item-relations')"
            >
              前往关系维护中心 →
            </el-button>
          </div>

          <div class="table-wrap">
            <el-table :data="relations" size="small" border class="relation-table" empty-text="当前尚未配置关联物料">
              <el-table-column label="Item 编码" min-width="140" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span class="code-mono font-medium text-success">{{ row.item && row.item.item_code || '—' }}</span>
                </template>
              </el-table-column>
              <el-table-column label="Item 名称" min-width="160" show-overflow-tooltip>
                <template slot-scope="{ row }">
                  <span>{{ row.item && row.item.item_name || '—' }}</span>
                </template>
              </el-table-column>
              <el-table-column label="关系类型" width="110" align="center">
                <template slot-scope="{ row }">
                  <el-tag size="mini" :type="row.is_primary ? 'success' : 'info'" effect="plain">
                    {{ row.is_primary ? '默认 Item' : (row.relation_type || '辅料') }}
                  </el-tag>
                </template>
              </el-table-column>
              <el-table-column prop="effective_at" label="生效时间" width="145" align="center">
                <template slot-scope="{ row }">
                  <span class="code-mono time-text">{{ date(row.effective_at) }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="expired_at" label="失效时间" width="145" align="center">
                <template slot-scope="{ row }">
                  <span class="code-mono time-text">{{ date(row.expired_at) }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="operator_name" label="操作人" width="100" show-overflow-tooltip />
              <el-table-column label="状态" width="85" align="center">
                <template slot-scope="{ row }">
                  <span :class="['relation-badge', row.status === 'active' ? 'status-normal' : 'status-none']">
                    {{ row.status === 'active' ? '生效中' : '已失效' }}
                  </span>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </section>

        <!-- 订单属性支持卡片 -->
        <section class="detail-card">
          <div class="card-header">
            <div class="header-title">
              <i class="el-icon-s-operation" />
              <h2>销售订单属性规则</h2>
            </div>
          </div>
          <el-table :data="attrs" size="small" border class="attr-table">
            <el-table-column prop="name" label="属性名称" width="130" />
            <el-table-column prop="support" label="订单行显示" width="110" align="center" />
            <el-table-column prop="required" label="是否必填" width="100" align="center">
              <template slot-scope="{ row }">
                <span :class="{ 'text-danger font-medium': row.required === '是' }">{{ row.required }}</span>
              </template>
            </el-table-column>
            <el-table-column prop="values" label="控制说明" min-width="180" />
          </el-table>
        </section>

        <!-- 定制与交付要求卡片 -->
        <section class="detail-card">
          <div class="card-header">
            <div class="header-title">
              <i class="el-icon-s-claim" />
              <h2>定制与交付控制门禁</h2>
            </div>
          </div>
          <div class="capabilities-grid">
            <div class="cap-item">
              <span class="cap-lbl">普通定制</span>
              <span :class="['cap-val', sku.allow_customized ? 'yes' : 'no']">
                <i :class="sku.allow_customized ? 'el-icon-circle-check' : 'el-icon-circle-close'" />
                {{ sku.allow_customized ? '允许普通定制' : '不允许' }}
              </span>
            </div>
            <div class="cap-item">
              <span class="cap-lbl">特殊定制</span>
              <span :class="['cap-val', sku.allow_special_customized ? 'yes' : 'no']">
                <i :class="sku.allow_special_customized ? 'el-icon-circle-check' : 'el-icon-circle-close'" />
                {{ sku.allow_special_customized ? '允许特殊定制' : '不允许' }}
              </span>
            </div>
            <template v-if="sku.allow_special_customized">
              <div class="cap-item">
                <span class="cap-lbl">设计图纸门禁</span>
                <span :class="['cap-val', sku.special_custom_drawing_required ? 'warn' : 'neutral']">
                  {{ sku.special_custom_drawing_required ? '特殊定制必传图纸' : '不强制上传' }}
                </span>
              </div>
              <div class="cap-item">
                <span class="cap-lbl">技术协议门禁</span>
                <span :class="['cap-val', sku.special_custom_agreement_required ? 'warn' : 'neutral']">
                  {{ sku.special_custom_agreement_required ? '特殊定制必传协议' : '不强制上传' }}
                </span>
              </div>
              <div class="cap-item">
                <span class="cap-lbl">配置说明门禁</span>
                <span :class="['cap-val', sku.special_custom_description_required ? 'warn' : 'neutral']">
                  {{ sku.special_custom_description_required ? '特殊定制必填配置' : '不强制填写' }}
                </span>
              </div>
            </template>
            <div class="cap-item">
              <span class="cap-lbl">交付前质检</span>
              <span :class="['cap-val', sku.delivery_inspection_required ? 'warn' : 'neutral']">
                <i :class="sku.delivery_inspection_required ? 'el-icon-warning' : 'el-icon-circle-check'" />
                {{ sku.delivery_inspection_required ? '强制要求发货前终检' : '无需特殊终检' }}
              </span>
            </div>
          </div>
        </section>
      </div>

      <!-- 右侧订单行显示预览 -->
      <aside class="preview-sidebar">
        <div class="preview-header">
          <i class="el-icon-view" />
          <h3>销售订单行显示预览</h3>
        </div>

        <div class="preview-product-banner">
          <el-image v-if="imageUrl" :src="imageUrl" fit="cover" class="preview-thumb" />
          <div v-else class="preview-thumb empty"><i class="el-icon-picture-outline" /></div>
          <div class="preview-product-info">
            <span class="preview-prod-title">{{ sku.product && sku.product.product_name || '—' }}</span>
            <span class="preview-sku-name">{{ sku.sku_name }}</span>
            <span class="preview-spec">{{ sku.spec_model || sku.spec_text || '—' }}</span>
          </div>
        </div>

        <div class="preview-rows-list">
          <div class="preview-row">
            <span class="lbl">所属 Product</span>
            <span class="val">{{ sku.product ? sku.product.product_code : '—' }}</span>
          </div>
          <div class="preview-row">
            <span class="lbl">SKU 编码</span>
            <span class="val code-mono">{{ sku.sku_code }}</span>
          </div>
          <div class="preview-row">
            <span class="lbl">销售数量</span>
            <span class="val">输入数量 <b class="unit-code">{{ unitName }}</b></span>
          </div>
          <div class="preview-row">
            <span class="lbl">销售单价</span>
            <span class="val code-mono text-price">{{ money(sku.sale_price) }}</span>
          </div>
          <div class="preview-row">
            <span class="lbl">订单备注</span>
            <span class="val text-muted">可填可选</span>
          </div>
          <div v-if="sku.electric_mode !== 'hidden'" class="preview-row">
            <span class="lbl">电压 <em v-if="sku.electric_mode === 'required'">*</em></span>
            <span class="val">{{ modeText(sku.electric_mode) }}</span>
          </div>
          <div v-if="sku.need_pump_mode !== 'hidden'" class="preview-row">
            <span class="lbl">原水泵控制 <em v-if="sku.need_pump_mode === 'required'">*</em></span>
            <span class="val">{{ modeText(sku.need_pump_mode) }}</span>
          </div>
          <div v-if="sku.allow_customized" class="preview-row">
            <span class="lbl">普通定制</span>
            <span class="val text-success">订单行可选择</span>
          </div>
          <template v-if="sku.allow_special_customized">
            <div class="preview-row">
              <span class="lbl">特殊定制</span>
              <span class="val text-success">订单行可选择</span>
            </div>
            <div v-if="sku.special_custom_drawing_required" class="preview-row">
              <span class="lbl">设计图纸 <em>*</em></span>
              <span class="val text-warning">特殊定制时必传</span>
            </div>
            <div v-if="sku.special_custom_agreement_required" class="preview-row">
              <span class="lbl">技术协议 <em>*</em></span>
              <span class="val text-warning">特殊定制时必传</span>
            </div>
            <div v-if="sku.special_custom_description_required" class="preview-row">
              <span class="lbl">配置说明 <em>*</em></span>
              <span class="val text-warning">特殊定制时必填</span>
            </div>
          </template>
          <div v-if="sku.delivery_inspection_required" class="preview-row">
            <span class="lbl">交付前检验</span>
            <span class="val text-danger">强制终检</span>
          </div>
        </div>

        <div class="preview-footer-tip">
          <i class="el-icon-info" />
          <span>此预览与客户下单行保持 1:1 结构映射，业务人员开立销售订单时将按此规格约束录入。</span>
        </div>
      </aside>
    </main>
  </section>
</template>

<script>
import { getEntity, listRelations } from '../../../api/erp/master'
import { legacyMediaUrl } from '../../../utils/legacyMedia'

export default {
  name: 'SkuDetail',
  data () {
    return {
      loading: false,
      sku: null,
      relations: []
    }
  },
  computed: {
    id () { return this.$route.params.id },
    imageUrl () {
      return this.sku ? legacyMediaUrl(this.sku.image || (this.sku.product && this.sku.product.image)) : ''
    },
    unitName () {
      return (this.sku.sales_unit && this.sku.sales_unit.unit_name) || this.sku.sales_unit_snapshot || '—'
    },
    fields () {
      if (!this.sku) return []
      return [
        { key: 'sku_code', label: 'SKU编码', value: this.sku.sku_code },
        { key: 'sku_name', label: 'SKU名称', value: this.sku.sku_name },
        { key: 'product', label: '所属Product', value: this.sku.product ? `${this.sku.product.product_code}｜${this.sku.product.product_name}` : '' },
        { key: 'spec', label: '规格型号', value: this.sku.spec_model || this.sku.spec_text },
        { key: 'unit', label: '销售单位', value: this.unitName },
        { key: 'type', label: '订单行类型', value: this.typeText(this.sku.line_type || this.sku.order_line_type) },
        { key: 'sellable', label: '允许销售', value: this.sku.is_sellable ? '是' : '否' },
        { key: 'sale_price', label: '默认销售价格', value: this.money(this.sku.sale_price) }
      ]
    },
    valid () {
      return this.relations.some(row => row.status === 'active' && row.is_primary && row.item && row.item.status === 'enabled')
    },
    lineType () {
      return this.sku && (this.sku.line_type || this.sku.order_line_type)
    },
    noticeType () {
      return this.lineType !== 'physical' ? 'info' : this.valid ? 'success' : 'warning'
    },
    itemNotice () {
      return this.lineType !== 'physical'
        ? '当前订单行类型为非实物类（服务/无需发货），无需配置默认 Item。'
        : this.valid
          ? '当前 SKU 已成功绑定有效默认 Item 物料，可正常投入采购、销售下单、BOM 分解与工单排产。'
          : '当前实物 SKU 尚未绑定有效默认 Item，暂不可在销售订单中履约排产，请前往关系维护中心进行配置。'
    },
    attrs () {
      const map = value => ({
        hidden: ['不显示', '否', '订单行不展示该参数字段'],
        optional: ['显示', '否', '订单行展示该字段，由业务按需填写'],
        required: ['显示', '是', '订单确认提交前必须输入有效值']
      })[value] || ['不显示', '否', '—']

      const electric = map(this.sku.electric_mode)
      const pump = map(this.sku.need_pump_mode)
      return [
        { name: '电压', support: electric[0], required: electric[1], values: electric[2] },
        { name: '原水泵控制', support: pump[0], required: pump[1], values: this.sku.need_pump_mode === 'hidden' ? '—' : '需要 / 不需要' }
      ]
    }
  },
  async created () {
    this.refresh()
  },
  methods: {
    async refresh () {
      this.loading = true
      try {
        const [sku, relations] = await Promise.all([
          getEntity('skus', this.id),
          listRelations({ sku_id: this.id, per_page: 100 })
        ])
        this.sku = sku.data
        this.relations = relations.data.data || []
      } catch (error) {
        this.$message.error(error.userMessage || 'SKU详情加载失败')
      } finally {
        this.loading = false
      }
    },
    goBack () {
      this.$router.push(this.$route.query.from === 'product' ? '/master/products' : '/master/skus')
    },
    typeText (value) {
      return ({ physical: '实物', service: '服务', no_delivery: '无需发货' })[value] || value || '—'
    },
    modeText (value) {
      return value === 'required' ? '必填' : '可选'
    },
    statusText (value) {
      return ({ draft: '草稿', enabled: '启用', disabled: '停用' })[value] || value || '—'
    },
    date (v) {
      return v ? String(v).slice(0, 16).replace('T', ' ') : '—'
    },
    money (value) {
      return value === null || value === undefined || value === '' ? '—' : `¥ ${Number(value).toFixed(2)}`
    }
  }
}
</script>

<style scoped>
.sku-detail-page {
  padding: 16px 20px 30px;
  background: #f8fafc;
  min-height: calc(100vh - 52px);
  color: #1f2937;
  box-sizing: border-box;
}

/* 顶部页面头部卡片 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
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

.btn-back {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-back:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
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

.sku-code-tag {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 8px;
  border-radius: 4px;
  color: #00763f;
  font-size: 13px;
  font-weight: 600;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

.btn-refresh {
  border-color: #cbd5e1 !important;
  color: #475569 !important;
}

.btn-refresh:hover {
  border-color: #008b4b !important;
  color: #008b4b !important;
}

.btn-theme-create {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-create:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

/* 提示条 */
.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 14px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 13px;
  line-height: 1.5;
  margin-bottom: 14px;
}

.tip-warning {
  background: #fffbeb !important;
  border-color: #fde68a !important;
  color: #b45309 !important;
}

.tip-info {
  background: #f1f5f9 !important;
  border-color: #e2e8f0 !important;
  color: #475569 !important;
}

/* 主内容网格 */
.content-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 340px;
  gap: 16px;
  align-items: start;
}

.main-column {
  display: flex;
  flex-direction: column;
  gap: 14px;
  min-width: 0;
}

/* 卡片通用样式 */
.detail-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 18px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  box-sizing: border-box;
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #f1f5f9;
}

.header-title {
  display: flex;
  align-items: center;
  gap: 8px;
}

.header-title i {
  color: #008b4b;
  font-size: 17px;
}

.header-title h2 {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

/* 基础规格 */
.basic-body {
  display: flex;
  gap: 24px;
  align-items: flex-start;
}

.image-wrap {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.product-image {
  width: 120px;
  height: 120px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
}

.image-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 4px;
  background: #f8fafc;
  color: #94a3b8;
  font-size: 12px;
}

.image-empty i {
  font-size: 28px;
}

.image-label {
  font-size: 11px;
  color: #94a3b8;
}

.detail-grid {
  flex: 1;
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: 12px 20px;
}

.field-item {
  display: flex;
  flex-direction: column;
  gap: 3px;
  padding: 6px 10px;
  background: #f8fafc;
  border-radius: 6px;
  border: 1px solid #f1f5f9;
}

.field-lbl {
  font-size: 11px;
  color: #64748b;
}

.field-val {
  font-size: 13px;
  font-weight: 500;
  color: #1e293b;
  word-break: break-all;
}

/* 定制门禁能力网格 */
.capabilities-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 12px;
}

.cap-item {
  display: flex;
  flex-direction: column;
  gap: 5px;
  padding: 10px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}

.cap-lbl {
  font-size: 11px;
  color: #64748b;
}

.cap-val {
  font-size: 12px;
  font-weight: 600;
  display: flex;
  align-items: center;
  gap: 4px;
}

.cap-val.yes {
  color: #008b4b;
}

.cap-val.no {
  color: #94a3b8;
}

.cap-val.warn {
  color: #d97706;
}

.cap-val.neutral {
  color: #475569;
}

/* 表格与徽章 */
.table-wrap {
  width: 100%;
  overflow-x: auto;
}

.relation-table ::v-deep th,
.attr-table ::v-deep th {
  background: #f8fafc !important;
  font-size: 12px;
}

.relation-badge {
  display: inline-block;
  padding: 1px 6px;
  border-radius: 4px;
  font-size: 11px;
  font-weight: 600;
}

.status-normal {
  color: #00763f;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
}

.status-none {
  color: #64748b;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
}

.type-pill {
  font-size: 11px;
  padding: 2px 8px;
  border-radius: 4px;
  font-weight: 600;
}

.type-physical {
  background: #f0fdf4;
  color: #00763f;
  border: 1px solid #bbf7d0;
}

.type-service {
  background: #faf5ff;
  color: #7c3aed;
  border: 1px solid #e9d5ff;
}

.type-no_delivery {
  background: #fff7ed;
  color: #ea580c;
  border: 1px solid #ffedd5;
}

.action-link-theme {
  color: #008b4b !important;
  font-weight: 500;
}

.action-link-theme:hover {
  color: #00763f !important;
}

/* 右侧预览边栏 */
.preview-sidebar {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 18px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  position: sticky;
  top: 14px;
}

.preview-header {
  display: flex;
  align-items: center;
  gap: 6px;
  padding-bottom: 10px;
  margin-bottom: 12px;
  border-bottom: 1px solid #f1f5f9;
}

.preview-header i {
  color: #008b4b;
  font-size: 16px;
}

.preview-header h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}

.preview-product-banner {
  display: flex;
  gap: 10px;
  padding-bottom: 12px;
  margin-bottom: 12px;
  border-bottom: 1px solid #f1f5f9;
}

.preview-thumb {
  width: 54px;
  height: 54px;
  border-radius: 6px;
  border: 1px solid #e2e8f0;
  flex-shrink: 0;
}

.preview-thumb.empty {
  display: grid;
  place-items: center;
  background: #f8fafc;
  color: #94a3b8;
  font-size: 20px;
}

.preview-product-info {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 0;
}

.preview-prod-title {
  font-size: 12px;
  color: #64748b;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.preview-sku-name {
  font-size: 13px;
  font-weight: 700;
  color: #0f172a;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.preview-spec {
  font-size: 11px;
  color: #94a3b8;
}

.preview-rows-list {
  display: flex;
  flex-direction: column;
  gap: 8px;
}

.preview-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 10px;
  padding: 6px 10px;
  background: #f8fafc;
  border-radius: 4px;
  border: 1px solid #f1f5f9;
}

.preview-row .lbl {
  font-size: 12px;
  color: #64748b;
  flex-shrink: 0;
}

.preview-row .lbl em {
  color: #ef4444;
  font-style: normal;
}

.preview-row .val {
  font-size: 12px;
  font-weight: 500;
  color: #1e293b;
  text-align: right;
  word-break: break-all;
}

.unit-code {
  color: #008b4b;
}

.text-price {
  color: #b45309 !important;
  font-weight: 700 !important;
}

.preview-footer-tip {
  margin-top: 14px;
  padding: 10px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 11px;
  line-height: 1.5;
  display: flex;
  gap: 6px;
  align-items: flex-start;
}

.preview-footer-tip i {
  margin-top: 2px;
  font-size: 13px;
}

.code-mono {
  font-family: monospace;
}

.time-text {
  font-size: 12px;
  color: #64748b;
}

.text-success { color: #008b4b !important; }
.text-warning { color: #d97706 !important; }
.text-danger { color: #dc2626 !important; }
.text-muted { color: #94a3b8 !important; }
.font-medium { font-weight: 600 !important; }

/* 响应式断点适配规则 */
@media (max-width: 1180px) {
  .content-grid {
    grid-template-columns: 1fr;
  }
  .preview-sidebar {
    position: static;
  }
}

@media (max-width: 780px) {
  .basic-body {
    flex-direction: column;
    align-items: center;
  }
  .detail-grid {
    grid-template-columns: 1fr;
    width: 100%;
  }
  .capabilities-grid {
    grid-template-columns: 1fr;
  }
  .page-head {
    flex-direction: column;
    align-items: flex-start;
    gap: 12px;
  }
  .head-actions {
    width: 100%;
    justify-content: flex-end;
    flex-wrap: wrap;
  }
}
</style>
