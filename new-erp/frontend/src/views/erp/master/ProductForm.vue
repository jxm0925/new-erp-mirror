<template>
  <section class="product-form-page">
    <!-- 顶部标题与轻量返回入口 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-goods" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">{{ isEdit ? '编辑商品档案' : '新增商品档案' }}</h1>
            <el-tag size="small" type="success" effect="plain" class="head-tag">
              {{ isEdit ? '编辑模式' : '录入新商品' }}
            </el-tag>
          </div>
        </div>
      </div>
      <div class="head-actions">
        <el-button size="small" icon="el-icon-back" @click="back">返回商品列表</el-button>
      </div>
    </header>

    <!-- 全局统一页面提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>在此维护商品核心主档案与主图。系统支持单规格直接归档，或在下方通过规格维度矩阵批量预设笛卡尔积 SKU，规格组合自动继承当前商品计量单位。</span>
    </div>

    <el-form ref="form" :model="form" :rules="rules" label-position="top" size="small" class="product-form-flow">
      <!-- 模块一：核心基础信息 (宽屏三列 / 笔记本双列自适应) -->
      <section class="form-card master-card">
        <div class="card-header">
          <div class="header-main">
            <span class="card-icon-badge"><i class="el-icon-document" /></span>
            <div>
              <h2 class="card-title">基本信息</h2>
              <span class="card-subtitle">录入商品编码、名称、分类、计量单位与核心属性</span>
            </div>
          </div>
        </div>

        <div class="fields-grid">
          <el-form-item label="商品编码" prop="product_code">
            <el-input v-model.trim="form.product_code" disabled placeholder="系统预生成">
              <template slot="append">预生成</template>
            </el-input>
          </el-form-item>

          <el-form-item label="商品名称" prop="product_name">
            <el-input
              v-model.trim="form.product_name"
              maxlength="160"
              show-word-limit
              clearable
              placeholder="请输入商品标准名称，如：高精度铝合金型材"
            />
          </el-form-item>

          <el-form-item label="所属分类">
            <el-select v-model="form.category_id" clearable class="full-width" placeholder="请选择商品所属类目">
              <el-option v-for="item in categories" :key="item.id" :label="item.category_name" :value="item.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="商品类型" prop="product_type">
            <el-select v-model="form.product_type" class="full-width">
              <el-option label="标准商品 (单件/产成品)" value="standard" />
              <el-option label="套装商品 (组合销售件)" value="bundle" />
            </el-select>
          </el-form-item>

          <el-form-item label="计量单位" prop="unit_id">
            <el-select v-model="form.unit_id" clearable class="full-width" placeholder="SKU 将统一继承此销售单位">
              <el-option v-for="item in units" :key="item.id" :label="item.unit_name" :value="item.id" />
            </el-select>
          </el-form-item>

          <el-form-item label="销售状态">
            <el-radio-group v-model="form.status" size="small" class="status-radio-group">
              <el-radio-button label="enabled"><i class="el-icon-circle-check" /> 启用销售</el-radio-button>
              <el-radio-button label="disabled"><i class="el-icon-circle-close" /> 暂停销售</el-radio-button>
            </el-radio-group>
          </el-form-item>

          <el-form-item label="品牌">
            <el-input v-model.trim="form.brand" clearable placeholder="选填，如：简谈" />
          </el-form-item>

          <el-form-item label="规格型号">
            <el-input v-model.trim="form.model" clearable placeholder="选填，如：JT-AL-2026" />
          </el-form-item>

          <el-form-item label="原产地">
            <el-input v-model.trim="form.origin" clearable placeholder="选填，如：山东临沂" />
          </el-form-item>

          <el-form-item label="商品说明 / 描述" class="full-col">
            <el-input
              v-model="form.description"
              type="textarea"
              :rows="3"
              maxlength="500"
              show-word-limit
              placeholder="填写商品适用范围、加工材质说明、物理特性或销售注意事项等"
            />
          </el-form-item>
        </div>
      </section>

      <!-- 模块二：商品主图与媒体资料 -->
      <section class="form-card media-card">
        <div class="card-header">
          <div class="header-main">
            <span class="card-icon-badge"><i class="el-icon-picture-outline" /></span>
            <div>
              <h2 class="card-title">商品主图</h2>
              <span class="card-subtitle">展示于商品详情、报价单与发货单据</span>
            </div>
          </div>
        </div>

        <div class="media-body">
          <div class="upload-zone">
            <el-upload
              class="product-uploader"
              action="#"
              :show-file-list="false"
              :http-request="uploadImage"
              accept="image/jpeg,image/png,image/webp,image/gif"
            >
              <div v-if="form.image" class="preview-wrap">
                <img :src="form.image" class="cover-image" alt="商品主图" />
                <div class="preview-mask">
                  <i class="el-icon-edit" />
                  <span>点击更换图片</span>
                </div>
              </div>
              <div v-else class="upload-placeholder">
                <i class="el-icon-upload" />
                <span class="upload-title">点击或拖拽上传主图</span>
                <span class="upload-sub">支持 JPG / PNG / WebP 格式</span>
              </div>
            </el-upload>
          </div>

          <div class="media-hints">
            <div class="hint-item">
              <i class="el-icon-circle-check" />
              <div>
                <strong>建议比例与尺寸</strong>
                <p>建议上传正方形 800×800 像素以上图片，白底或透明底最佳</p>
              </div>
            </div>
            <div class="hint-item">
              <i class="el-icon-info" />
              <div>
                <strong>存储与安全性</strong>
                <p>文件大小不超过 5MB，图片经安全检测后持久化托管至云存储 OSS</p>
              </div>
            </div>
            <div v-if="form.image" class="image-actions">
              <el-button size="mini" type="text" icon="el-icon-delete" class="danger-text" @click="form.image = ''">
                移除当前主图
              </el-button>
            </div>
          </div>
        </div>
      </section>

      <!-- 模块三：规格与 SKU 矩阵生成器 (全宽独立区域，告别狭窄挤压) -->
      <section class="form-card matrix-section-card">
        <div class="card-header">
          <div class="header-main">
            <span class="card-icon-badge"><i class="el-icon-s-grid" /></span>
            <div>
              <h2 class="card-title">规格与 SKU 矩阵生成器</h2>
              <span class="card-subtitle">自由组合多维度规格，系统实时计算笛卡尔积并批量生成草稿 SKU 档案</span>
            </div>
          </div>
          <div class="header-aside">
            <el-tag v-if="matrixRows.length" size="small" type="success" effect="plain" class="matrix-count-tag">
              已生成 {{ matrixRows.length }} 款规格组合
            </el-tag>
            <el-tag v-else size="small" type="info" effect="plain">单规格模式</el-tag>
          </div>
        </div>

        <div class="matrix-body">
          <!-- 规格维度定义区 -->
          <div class="dimension-box">
            <div class="dimension-box-title">
              <i class="el-icon-c-scale-to-original" />
              <span>规格维度配置（例如：颜色、尺码、厚度）</span>
            </div>

            <div class="dimension-list">
              <div v-for="(dimension, index) in matrix.dimensions" :key="index" class="dimension-edit-card">
                <span class="dim-badge">维度 {{ index + 1 }}</span>
                <el-input
                  v-model.trim="dimension.name"
                  placeholder="规格名（如：颜色、材质）"
                  class="dim-name-input"
                />
                <el-input
                  v-model="dimension.valuesText"
                  placeholder="规格值（多个用逗号隔开，如：黑色, 白色, 银灰色）"
                  class="dim-values-input"
                  clearable
                />
                <el-button
                  type="text"
                  icon="el-icon-delete"
                  class="danger-text"
                  title="删除该维度"
                  @click="removeDimension(index)"
                >
                  删除
                </el-button>
              </div>
            </div>

            <div class="dim-actions-bar">
              <el-button size="small" icon="el-icon-plus" class="btn-theme-plain" @click="addDimension">
                添加规格维度
              </el-button>
              <span class="dim-hint">提示：每添加一个维度并录入规格值，系统将自动生成交叉组合预览。</span>
            </div>
          </div>

          <!-- 批量预设参数栏 -->
          <div class="batch-settings-panel">
            <div class="batch-col">
              <span class="batch-label"><i class="el-icon-postcard" /> SKU编码前缀</span>
              <el-input v-model.trim="matrix.codePrefix" size="small" placeholder="默认 SKU" class="batch-input" />
            </div>
            <div class="batch-col">
              <span class="batch-label"><i class="el-icon-money" /> 统一预设销售价</span>
              <el-input-number
                v-model="matrix.sale_price"
                size="small"
                :min="0"
                :precision="2"
                controls-position="right"
                placeholder="0.00"
                class="batch-number"
              />
            </div>
            <div class="batch-col">
              <span class="batch-label"><i class="el-icon-coin" /> 继承计量单位</span>
              <div class="batch-unit-box">
                <el-tag :type="form.unit_id ? 'success' : 'danger'" effect="plain" size="small">
                  {{ currentUnitName }}
                </el-tag>
              </div>
            </div>
          </div>

          <!-- 全宽 SKU 规格预览表格 -->
          <div class="matrix-table-wrap">
            <div class="matrix-table-header">
              <div class="table-title">
                <i class="el-icon-view" />
                <strong>SKU 组合预览清单</strong>
                <span class="table-sub-count">（共 {{ matrixRows.length }} 款组合）</span>
              </div>
              <el-button type="text" size="mini" icon="el-icon-refresh" class="theme-link-btn" @click="refreshPreview">
                刷新预览数据
              </el-button>
            </div>

            <el-table
              :data="matrixRows"
              size="small"
              border
              max-height="340"
              class="matrix-preview-table"
              empty-text="请在上方配置规格维度（名称与值）及统一销售价，系统将实时计算生成 SKU 列表"
            >
              <el-table-column type="index" label="序号" width="60" align="center" />
              <el-table-column prop="spec_text" label="规格组合" min-width="180">
                <template slot-scope="{ row }">
                  <span class="spec-tag">{{ row.spec_text }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="sku_code" label="预生成SKU编码" min-width="160">
                <template slot-scope="{ row }">
                  <span class="font-mono sku-code-cell">{{ row.sku_code }}</span>
                </template>
              </el-table-column>
              <el-table-column label="销售单位" width="100" align="center">
                <template>
                  <span class="unit-badge">{{ currentUnitName }}</span>
                </template>
              </el-table-column>
              <el-table-column prop="sale_price" label="销售单价" width="130" align="right">
                <template slot-scope="{ row }">
                  <span class="price-val">¥{{ Number(row.sale_price || 0).toFixed(2) }}</span>
                </template>
              </el-table-column>
              <el-table-column label="生成状态" width="110" align="center">
                <template>
                  <el-tag size="mini" type="info" effect="plain">待生成草稿</el-tag>
                </template>
              </el-table-column>
            </el-table>
          </div>
        </div>
      </section>
    </el-form>

    <!-- 底部固定吸底操作栏 (全局唯一提交入口) -->
    <footer class="footer-bar">
      <div class="footer-left">
        <span class="footer-target-name">
          <i class="el-icon-goods" />
          {{ form.product_name || '未命名商品' }}
        </span>
        <span v-if="matrixRows.length" class="footer-sku-hint">
          将自动生成并绑定 <strong>{{ matrixRows.length }}</strong> 款草稿 SKU
        </span>
        <span v-else class="footer-sku-hint">
          未设置规格矩阵，仅保存商品主档案
        </span>
      </div>
      <div class="footer-actions">
        <el-button size="small" @click="back">取消</el-button>
        <el-button size="small" :loading="saving" @click="save('draft')">保存为草稿</el-button>
        <el-button type="primary" size="small" :loading="saving" class="btn-theme-submit" @click="save('save')">
          {{ isEdit ? '保存修改' : '保存商品与SKU' }}
        </el-button>
      </div>
    </footer>
  </section>
</template>

<script>
import { getEntity, listEntity, saveEntity, uploadProductImage } from '../../../api/erp/master'
import { clearCreatePageReservation, reserveForCreatePage, reserveFreshDocumentNumber } from '../../../utils/documentNumberReservation'

const empty = () => ({
  id: null,
  product_code: '',
  product_name: '',
  product_type: 'standard',
  category_id: null,
  unit_id: null,
  brand: '',
  model: '',
  origin: '',
  image: '',
  description: '',
  status: 'enabled'
})

export default {
  name: 'ProductForm',
  data() {
    return {
      saving: false,
      loading: false,
      reservation: null,
      form: empty(),
      categories: [],
      units: [],
      matrix: {
        codePrefix: 'SKU',
        sale_price: null,
        dimensions: [
          { name: '规格', valuesText: '' }
        ]
      },
      rules: {
        product_code: [{ required: true, message: '系统未取得商品编码，请重新打开新增页', trigger: 'blur' }],
        product_name: [{ required: true, message: '请输入商品名称', trigger: 'blur' }],
        product_type: [{ required: true, message: '请选择商品类型', trigger: 'change' }],
        unit_id: [{ required: true, message: '请选择计量单位', trigger: 'change' }]
      }
    }
  },
  computed: {
    isEdit() {
      return !!this.$route.params.id
    },
    currentUnitName() {
      const unit = this.units.find(item => Number(item.id) === Number(this.form.unit_id))
      return unit ? unit.unit_name : '未选择'
    },
    matrixRows() {
      const dims = this.matrix.dimensions
        .map(d => ({
          name: (d.name || '').trim(),
          values: (d.valuesText || '').split(/[,，]/).map(v => v.trim()).filter(Boolean)
        }))
        .filter(d => d.name && d.values.length)
      if (!dims.length || this.matrix.sale_price === null || this.matrix.sale_price === '') return []
      const group = (i, picked) => i >= dims.length ? [picked] : dims[i].values.flatMap(value => group(i + 1, [...picked, { name: dims[i].name, value }]))
      return group(0, []).map(combo => {
        const spec = combo.map(x => x.value).join(' / ')
        return {
          sku_code: '保存时系统预分配',
          sku_name: `${this.form.product_name || '商品'}-${spec}`,
          spec_text: spec,
          sale_price: this.matrix.sale_price
        }
      })
    }
  },
  async created() {
    await this.loadOptions()
    if (this.isEdit) await this.loadProduct()
    else await this.reserveProductNumber()
  },
  methods: {
    async reserveProductNumber() {
      try {
        this.reservation = await reserveForCreatePage('product', '/master/products/new')
        this.form.product_code = this.reservation.document_no
      } catch (e) {
        this.$message.error(e.userMessage || '商品编码预生成失败，请重新打开新增页')
      }
    },
    async loadOptions() {
      const [categories, units] = await Promise.all([
        listEntity('categories', { per_page: 100, category_type: 'product' }),
        listEntity('units', { per_page: 100, status: 'enabled' })
      ])
      this.categories = categories.data.data || []
      this.units = units.data.data || []
    },
    async loadProduct() {
      this.loading = true
      try {
        const { data } = await getEntity('products', this.$route.params.id)
        this.form = { ...empty(), ...data }
      } catch (e) {
        this.$message.error(e.userMessage || '商品不存在')
        this.back()
      } finally {
        this.loading = false
      }
    },
    addDimension() {
      this.matrix.dimensions.push({ name: '', valuesText: '' })
    },
    removeDimension(index) {
      this.matrix.dimensions.splice(index, 1)
      if (!this.matrix.dimensions.length) {
        this.matrix.dimensions.push({ name: '', valuesText: '' })
      }
    },
    refreshPreview() {
      this.$forceUpdate()
    },
    async uploadImage(request) {
      try {
        const form = new FormData()
        form.append('image', request.file)
        const { data } = await uploadProductImage(form)
        this.form.image = data.data.url
        this.$message.success('商品主图已上传成功')
      } catch (e) {
        this.$message.error(e.userMessage || '图片上传失败')
      }
    },
    back() {
      this.$router.push('/master/products')
    },
    async save(mode) {
      if (this.saving) return
      this.$refs.form.validate(async ok => {
        if (!ok) return
        if (mode === 'save' && !this.isEdit && this.matrix.dimensions.some(d => d.name || d.valuesText) && !this.matrixRows.length) {
          return this.$message.error('请补齐规格维度、规格值与统一销售价格以生成矩阵')
        }
        this.saving = true
        try {
          const productPayload = { ...this.form, status: mode === 'draft' ? 'draft' : this.form.status }
          if (!this.isEdit && this.reservation) {
            productPayload.reservation_token = this.reservation.reservation_token
            productPayload.creation_session_id = this.reservation.creation_session_id
          }
          const matrix = mode === 'save' ? this.matrixRows : []
          if (matrix.length) {
            productPayload.sku_matrix = []
            for (const row of matrix) {
              const reservation = await reserveFreshDocumentNumber('sku', '/master/products/new#sku-matrix')
              productPayload.sku_matrix.push({ ...row, sku_code: reservation.document_no,
                reservation_token: reservation.reservation_token, creation_session_id: reservation.creation_session_id })
            }
          }
          await saveEntity('products', productPayload)
          if (!this.isEdit) clearCreatePageReservation(this.reservation)
          if (matrix.length) this.$message.success(`商品已保存，并成功生成 ${matrix.length} 款规格 SKU`)
          else this.$message.success('商品档案保存成功')
          this.$router.push('/master/products')
        } catch (e) {
          this.$message.error(e.userMessage || '商品保存失败')
        } finally {
          this.saving = false
        }
      })
    }
  }
}
</script>

<style scoped>
.product-form-page {
  min-height: calc(100vh - 84px);
  padding: 16px 20px 84px;
  background: #f5f7fa;
  color: #1f2937;
  box-sizing: border-box;
}

/* 顶部标题区 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 12px;
  gap: 16px;
  flex-wrap: wrap;
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
  background: #eaf7ef;
  color: #008b4b;
  display: grid;
  place-items: center;
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
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #0f172a;
  letter-spacing: -0.01em;
}

.head-tag {
  font-weight: 600;
  border-radius: 10px;
  background-color: #eaf7ef;
  border-color: #b7ebc7;
  color: #008b4b;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* 页面表单流式布局 */
.product-form-flow {
  display: flex;
  flex-direction: column;
  gap: 16px;
}

.form-card {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  background: #ffffff;
  padding: 18px 20px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
}

.card-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 16px;
  padding-bottom: 12px;
  border-bottom: 1px solid #f1f5f9;
  flex-wrap: wrap;
  gap: 10px;
}

.header-main {
  display: flex;
  align-items: center;
  gap: 10px;
}

.card-icon-badge {
  width: 28px;
  height: 28px;
  border-radius: 6px;
  background: #eaf7ef;
  color: #008b4b;
  display: grid;
  place-items: center;
  font-size: 15px;
  flex-shrink: 0;
}

.card-title {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
  line-height: 1.2;
}

.card-subtitle {
  font-size: 12px;
  color: #64748b;
  margin-top: 2px;
  display: block;
}

/* 字段栅格布局 */
.fields-grid {
  display: grid;
  grid-template-columns: repeat(3, 1fr);
  gap: 8px 20px;
}

.full-col {
  grid-column: 1 / -1;
}

.full-width {
  width: 100%;
}

::v-deep .el-form-item__label {
  font-weight: 600;
  color: #334155;
  padding-bottom: 4px !important;
}

/* 状态分段按钮组 */
.status-radio-group {
  display: flex;
}

::v-deep .status-radio-group .el-radio-button {
  flex: 1;
}

::v-deep .status-radio-group .el-radio-button__inner {
  width: 100%;
  text-align: center;
}

::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  box-shadow: -1px 0 0 0 #008b4b !important;
}

::v-deep .el-radio-button__inner:hover {
  color: #008b4b;
}

/* 媒体图片卡片 */
.media-body {
  display: flex;
  align-items: center;
  gap: 24px;
  flex-wrap: wrap;
}

.product-uploader {
  width: 220px;
}

.upload-placeholder {
  width: 220px;
  height: 130px;
  border: 1px dashed #cbd5e1;
  border-radius: 8px;
  background: #f8fafc;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  cursor: pointer;
  transition: all 0.2s ease;
}

.upload-placeholder:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}

.upload-placeholder i {
  font-size: 28px;
  color: #94a3b8;
  transition: color 0.2s;
}

.upload-placeholder:hover i {
  color: #008b4b;
}

.upload-title {
  font-size: 13px;
  font-weight: 600;
  color: #334155;
}

.upload-sub {
  font-size: 11px;
  color: #94a3b8;
}

.preview-wrap {
  width: 220px;
  height: 130px;
  border-radius: 8px;
  overflow: hidden;
  position: relative;
  border: 1px solid #e2e8f0;
  background: #f8fafc;
}

.cover-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.preview-mask {
  position: absolute;
  inset: 0;
  background: rgba(15, 23, 42, 0.6);
  color: #ffffff;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: 6px;
  opacity: 0;
  transition: opacity 0.2s;
  cursor: pointer;
  font-size: 12px;
}

.preview-wrap:hover .preview-mask {
  opacity: 1;
}

.media-hints {
  display: flex;
  flex-direction: column;
  gap: 12px;
  flex: 1;
  min-width: 260px;
}

.hint-item {
  display: flex;
  gap: 10px;
  align-items: flex-start;
  font-size: 12px;
}

.hint-item i {
  font-size: 16px;
  color: #008b4b;
  margin-top: 2px;
}

.hint-item strong {
  display: block;
  color: #1e293b;
  margin-bottom: 2px;
}

.hint-item p {
  margin: 0;
  color: #64748b;
  line-height: 1.4;
}

.danger-text {
  color: #ef4444 !important;
}

.danger-text:hover {
  color: #dc2626 !important;
}

/* 规格矩阵独立板块 */
.matrix-section-card {
  padding-bottom: 22px;
}

.matrix-count-tag {
  font-weight: 600;
  border-radius: 12px;
  background-color: #eaf7ef;
  border-color: #b7ebc7;
  color: #008b4b;
}

.dimension-box {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  margin-bottom: 14px;
}

.dimension-box-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
  margin-bottom: 12px;
}

.dimension-box-title i {
  color: #008b4b;
}

.dimension-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
  margin-bottom: 12px;
}

.dimension-edit-card {
  display: flex;
  align-items: center;
  gap: 12px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 8px 12px;
}

.dim-badge {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
  background: #f1f5f9;
  padding: 4px 8px;
  border-radius: 4px;
  white-space: nowrap;
}

.dim-name-input {
  width: 180px;
  flex-shrink: 0;
}

.dim-values-input {
  flex: 1;
}

.dim-actions-bar {
  display: flex;
  align-items: center;
  gap: 14px;
  flex-wrap: wrap;
}

.btn-theme-plain {
  border-color: #b7ebc7;
  color: #008b4b;
  background: #eaf7ef;
  font-weight: 500;
}

.btn-theme-plain:hover,
.btn-theme-plain:focus {
  border-color: #008b4b;
  color: #ffffff;
  background: #008b4b;
}

.dim-hint {
  font-size: 12px;
  color: #94a3b8;
}

/* 批量预设参数栏 */
.batch-settings-panel {
  display: flex;
  align-items: center;
  gap: 20px;
  padding: 12px 18px;
  background: #f1f5f9;
  border-radius: 8px;
  margin-bottom: 16px;
  flex-wrap: wrap;
}

.batch-col {
  display: flex;
  align-items: center;
  gap: 10px;
}

.batch-label {
  font-size: 12px;
  font-weight: 600;
  color: #334155;
  display: inline-flex;
  align-items: center;
  gap: 4px;
  white-space: nowrap;
}

.batch-label i {
  color: #008b4b;
}

.batch-input {
  width: 140px;
}

.batch-number {
  width: 150px;
}

.batch-unit-box {
  display: flex;
  align-items: center;
}

/* 预览表格区域 */
.matrix-table-wrap {
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  overflow: hidden;
  background: #ffffff;
}

.matrix-table-header {
  padding: 10px 16px;
  background: #f8fafc;
  border-bottom: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.table-title {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  color: #0f172a;
}

.table-title i {
  color: #008b4b;
}

.table-sub-count {
  font-size: 12px;
  color: #64748b;
  font-weight: normal;
}

.theme-link-btn {
  color: #008b4b !important;
}

.theme-link-btn:hover {
  color: #00763f !important;
}

.matrix-preview-table {
  width: 100%;
}

.spec-tag {
  font-weight: 600;
  color: #1e293b;
}

.sku-code-cell {
  color: #475569;
}

.font-mono {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
}

.unit-badge {
  background: #f1f5f9;
  color: #475569;
  padding: 2px 8px;
  border-radius: 4px;
  font-size: 12px;
}

.price-val {
  font-family: ui-monospace, SFMono-Regular, Menlo, monospace;
  font-weight: 700;
  color: #b91c1c;
  font-size: 13px;
}

/* 底部操作固定栏 */
.footer-bar {
  position: fixed;
  bottom: 0;
  right: 0;
  left: var(--sidebar-width, 192px);
  z-index: 99;
  height: 56px;
  padding: 0 24px;
  background: #ffffff;
  border-top: 1px solid #e2e8f0;
  display: flex;
  justify-content: space-between;
  align-items: center;
  box-shadow: 0 -2px 8px rgba(0, 0, 0, 0.04);
  box-sizing: border-box;
  transition: left 0.24s cubic-bezier(0.22, 0.61, 0.36, 1);
}

.sidebar-collapsed .footer-bar {
  left: 64px;
}

.footer-left {
  display: flex;
  align-items: center;
  gap: 12px;
  font-size: 13px;
}

.footer-target-name {
  font-weight: 700;
  color: #0f172a;
  display: inline-flex;
  align-items: center;
  gap: 6px;
}

.footer-target-name i {
  color: #008b4b;
}

.footer-sku-hint {
  color: #64748b;
  font-size: 12px;
}

.footer-sku-hint strong {
  color: #008b4b;
}

.footer-actions {
  display: flex;
  align-items: center;
  gap: 10px;
}

/* 主题提交按钮样式 */
::v-deep .btn-theme-submit,
.btn-theme-submit {
  background-color: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 600;
  transition: all 0.2s ease;
}

::v-deep .btn-theme-submit:hover,
::v-deep .btn-theme-submit:focus,
.btn-theme-submit:hover,
.btn-theme-submit:focus {
  background-color: #00763f !important;
  border-color: #00763f !important;
  color: #ffffff !important;
  box-shadow: 0 2px 6px rgba(0, 139, 75, 0.25);
}

::v-deep .btn-theme-submit:active,
.btn-theme-submit:active {
  background-color: #006233 !important;
  border-color: #006233 !important;
}

/* 焦点与高亮适配主题绿 */
::v-deep .el-input.is-active .el-input__inner,
::v-deep .el-input__inner:focus,
::v-deep .el-select .el-input.is-focus .el-input__inner,
::v-deep .el-textarea__inner:focus {
  border-color: #008b4b !important;
  box-shadow: 0 0 0 2px rgba(0, 139, 75, 0.12);
}

/* 响应式断点 */
@media (max-width: 1280px) {
  .fields-grid {
    grid-template-columns: repeat(2, 1fr);
  }
}

@media (max-width: 768px) {
  .product-form-page {
    padding: 12px 14px 76px;
  }
  .fields-grid {
    grid-template-columns: 1fr;
  }
  .dimension-edit-card {
    flex-direction: column;
    align-items: stretch;
  }
  .dim-name-input {
    width: 100%;
  }
  .batch-settings-panel {
    flex-direction: column;
    align-items: flex-start;
  }
  .batch-input, .batch-number {
    width: 100%;
  }
  .footer-bar {
    left: 0;
    padding: 0 16px;
  }
}
</style>
