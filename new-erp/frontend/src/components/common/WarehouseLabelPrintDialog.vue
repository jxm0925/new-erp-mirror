<template>
  <el-dialog
    title="打印标签"
    :visible="visible"
    width="660px"
    top="0"
    append-to-body
    class="warehouse-label-dialog"
    :close-on-click-modal="false"
    @close="close"
  >
    <div class="warehouse-label-options">
      <div class="warehouse-label-form-row">
        <label>标签尺寸 <span>*</span></label>
        <el-radio-group v-model="size" :disabled="printing">
          <el-radio v-for="item in sizeOptions" :key="item.value" :label="item.value">{{ item.name }}</el-radio>
        </el-radio-group>
      </div>
      <div class="warehouse-label-form-row">
        <label>编码形式 <span>*</span></label>
        <el-radio-group v-model="mode" :disabled="printing">
          <el-radio v-for="item in modeOptions" :key="item.value" :label="item.value">{{ item.name }}</el-radio>
        </el-radio-group>
      </div>
      <div class="warehouse-label-form-row">
        <label>每项份数 <span>*</span></label>
        <el-input-number v-model="copies" size="small" controls-position="right" :min="1" :max="100" :precision="0" :disabled="printing" />
      </div>
    </div>

    <div class="warehouse-label-summary warehouse-label-two-column">
      <div class="warehouse-label-field-title">已选 <strong>{{ labels.length }}</strong> 项</div>
      <div class="warehouse-label-summary-card">
        <div><span>{{ captions.name }}：</span><strong>{{ firstLabel.name || '—' }}</strong></div>
        <div><span>{{ captions.code }}：</span><strong>{{ firstLabel.code || '—' }}</strong></div>
        <div><span>{{ captions.spec }}：</span><strong>{{ firstLabel.spec || '—' }}</strong></div>
      </div>
    </div>

    <div class="warehouse-label-two-column">
      <div class="warehouse-label-field-title">标签预览</div>
      <div>
        <div v-loading="preparing" class="warehouse-label-preview-area">
          <div v-if="error" class="warehouse-label-error" role="alert"><i class="el-icon-warning-outline" />{{ error }}</div>
          <div v-else-if="preview" class="warehouse-label-preview" :class="`warehouse-label-preview--${mode}`">
            <div class="warehouse-label-preview-top">
              <div v-if="preview.qrcode" class="warehouse-label-preview-qr" v-html="preview.qrcode.svg" />
              <div class="warehouse-label-preview-copy">
                <strong>{{ preview.name }}</strong>
                <div v-if="preview.spec">{{ preview.spec }}</div>
                <small v-if="preview.detail">{{ preview.detail }}</small>
              </div>
            </div>
            <div v-if="preview.barcode" class="warehouse-label-preview-barcode" v-html="preview.barcode.svg" />
            <div class="warehouse-label-preview-code">{{ preview.barcode ? preview.barcodeValue : preview.code }}</div>
          </div>
        </div>
        <p class="warehouse-label-content">标签内容：{{ captions.content }}</p>
      </div>
    </div>

    <div slot="footer" class="warehouse-label-footer">
      <el-button size="small" :disabled="printing" @click="close">取消</el-button>
      <el-button size="small" type="success" :loading="printing" :disabled="preparing || !!error || !prepared" @click="print">打印</el-button>
    </div>
  </el-dialog>
</template>

<script>
import { WAREHOUSE_LABEL_MODES, WAREHOUSE_LABEL_SIZES, prepareWarehouseLabels, printWarehouseLabels } from '@/utils/warehouseLabels'

const LABEL_CAPTIONS = {
  item: { name: '物料名称', code: '物料编码', spec: '规格型号', content: '物料编码、名称、规格' },
  location: { name: '库位名称', code: '库位编码', spec: '仓库 / 区域', content: '库位编码、名称、仓库' },
  serial: { name: '物料名称', code: '序列号', spec: '规格型号', content: '序列号、物料名称、规格' },
  physical: { name: '物料名称', code: '实物编号', spec: '规格 / 尺寸', content: '实物编号、物料名称、尺寸' },
  document: { name: '单据名称', code: '单据编号', spec: '规格说明', content: '单据编号、名称' }
}

export default {
  name: 'WarehouseLabelPrintDialog',
  props: {
    visible: { type: Boolean, default: false },
    labels: { type: Array, default: () => [] }
  },
  data() {
    return { size: '60x40', mode: 'both', copies: 1, preparing: false, printing: false, prepared: null, error: '', prepareVersion: 0 }
  },
  computed: {
    sizeOptions() { return Object.values(WAREHOUSE_LABEL_SIZES) },
    modeOptions() { return WAREHOUSE_LABEL_MODES },
    firstLabel() { return this.labels[0] || {} },
    captions() { return LABEL_CAPTIONS[this.firstLabel.type] || LABEL_CAPTIONS.item },
    preview() { return this.prepared && this.prepared.labels[0] }
  },
  watch: {
    visible(open) {
      if (open) {
        this.size = '60x40'
        this.mode = 'both'
        this.copies = 1
        this.prepare()
      } else {
        ++this.prepareVersion
        this.prepared = null
        this.preparing = false
      }
    },
    labels() { if (this.visible) this.prepare() },
    size() { if (this.visible) this.prepare() },
    mode() { if (this.visible) this.prepare() },
    copies() { if (this.visible) this.prepare() }
  },
  beforeDestroy() { ++this.prepareVersion },
  methods: {
    close() { if (!this.printing) this.$emit('update:visible', false) },
    async prepare() {
      const version = ++this.prepareVersion
      this.preparing = true
      this.prepared = null
      this.error = ''
      try {
        if (!Number.isInteger(this.copies) || this.copies < 1 || this.copies > 100) throw new Error('请输入 1 至 100 的整数份数。')
        const prepared = await prepareWarehouseLabels(this.labels, { size: this.size, mode: this.mode, copies: this.copies })
        if (version === this.prepareVersion) this.prepared = prepared
      } catch (error) {
        if (version === this.prepareVersion) this.error = error.message || '标签生成失败，请重试。'
      } finally {
        if (version === this.prepareVersion) this.preparing = false
      }
    },
    async print() {
      if (this.printing || this.preparing || !this.prepared || this.error) return
      this.printing = true
      try {
        await printWarehouseLabels(this.prepared)
      } catch (error) {
        this.$message.error(error.message || '打印失败，请重试。')
      } finally {
        this.printing = false
      }
    }
  }
}
</script>

<style>
.warehouse-label-source .el-checkbox__input.is-checked .el-checkbox__inner,
.warehouse-label-source .el-checkbox__input.is-indeterminate .el-checkbox__inner { background: #008b4b; border-color: #008b4b; }
.warehouse-label-source .el-checkbox__inner:hover,
.warehouse-label-source .el-checkbox__input.is-focus .el-checkbox__inner { border-color: #008b4b; }
.warehouse-label-dialog {
  display: flex;
  align-items: center;
  justify-content: center;
  font-family: Arial,"Microsoft YaHei",sans-serif;
}
.warehouse-label-dialog .el-dialog {
  max-width: calc(100vw - 40px);
  max-height: calc(100vh - 40px);
  margin: 20px !important;
  display: flex;
  flex-direction: column;
  border-radius: 7px;
  overflow: hidden;
}
.warehouse-label-dialog .el-dialog__header {
  flex: none;
  padding: 18px 24px;
  border-bottom: 1px solid #e5e9ef;
}
.warehouse-label-dialog .el-dialog__title {
  color: #172033;
  font-size: 16px;
  font-weight: 700;
  line-height: 20px;
}
.warehouse-label-dialog .el-dialog__headerbtn { right: 22px; top: 20px; }
.warehouse-label-dialog .el-dialog__headerbtn .el-dialog__close { color: #334155; }
.warehouse-label-dialog .el-dialog__headerbtn:hover .el-dialog__close { color: #008b4b; }
.warehouse-label-dialog .el-dialog__body {
  min-height: 0;
  padding: 8px 24px 12px;
  overflow-y: auto;
  color: #263244;
  font-size: 14px;
}
.warehouse-label-options {
  padding-bottom: 14px;
  border-bottom: 1px solid #e5e9ef;
}
.warehouse-label-form-row {
  display: grid;
  grid-template-columns: 114px minmax(0, 1fr);
  align-items: center;
  min-height: 46px;
}
.warehouse-label-form-row > label { font-weight: 500; }
.warehouse-label-form-row > label > span { color: #e54545; }
.warehouse-label-form-row .el-radio-group { display: flex; align-items: center; gap: 22px; flex-wrap: wrap; }
.warehouse-label-form-row .el-radio { margin: 0; color: #172033; font-size: 14px; }
.warehouse-label-form-row .el-radio__label { padding-left: 9px; font-size: 14px; }
.warehouse-label-form-row .el-radio__input.is-checked .el-radio__inner { border-color: #008b4b; background: #008b4b; }
.warehouse-label-form-row .el-radio__input.is-checked + .el-radio__label { color: #172033; }
.warehouse-label-form-row .el-radio__inner { width: 16px; height: 16px; border-color: #c6ced9; }
.warehouse-label-form-row .el-radio__inner:hover,
.warehouse-label-form-row .el-input__inner:focus { border-color: #008b4b; }
.warehouse-label-form-row .el-input-number__increase:hover,
.warehouse-label-form-row .el-input-number__decrease:hover { color: #008b4b; }
.warehouse-label-form-row .el-input-number { width: 114px; }
.warehouse-label-form-row .el-input-number .el-input__inner { text-align: left; padding-left: 12px; }
.warehouse-label-two-column { display: grid; grid-template-columns: 114px minmax(0, 1fr); align-items: start; }
.warehouse-label-field-title { padding-top: 10px; line-height: 22px; }
.warehouse-label-field-title strong { font-weight: 600; }
.warehouse-label-summary { margin: 16px 0 12px; }
.warehouse-label-summary-card { min-width: 0; padding: 11px 20px; background: #f4f6f8; border-radius: 5px; }
.warehouse-label-summary-card > div { display: flex; gap: 8px; line-height: 26px; }
.warehouse-label-summary-card span { flex: none; color: #4c586b; }
.warehouse-label-summary-card strong { min-width: 0; font-weight: 500; color: #152033; overflow-wrap: anywhere; }
.warehouse-label-preview-area { padding: 20px; background: #f7f8fa; border-radius: 5px; min-height: 250px; }
.warehouse-label-preview { min-width: 0; padding: 10px 15px 4px; border: 1px solid #cbd1da; background: #fff; border-radius: 6px; color: #060a11; }
.warehouse-label-preview-top { display: flex; align-items: center; gap: 28px; min-width: 0; }
.warehouse-label-preview-qr { flex: none; line-height: 0; }
.warehouse-label-preview-qr > svg { width: 112px !important; height: 112px !important; }
.warehouse-label-preview-copy { flex: 1; min-width: 0; overflow-wrap: anywhere; }
.warehouse-label-preview-copy strong { display: block; font-size: 23px; font-weight: 700; line-height: 1.35; }
.warehouse-label-preview-copy > div { margin-top: 5px; font-size: 19px; line-height: 1.3; }
.warehouse-label-preview-copy small { display: block; margin-top: 4px; font-size: 12px; line-height: 1.35; }
.warehouse-label-preview-barcode { margin-top: 10px; line-height: 0; }
.warehouse-label-preview-barcode > svg { width: 100% !important; height: 52px !important; max-width: 100% !important; }
.warehouse-label-preview-code { margin-top: 2px; font-family: Arial,"Microsoft YaHei",sans-serif; font-size: 20px; line-height: 1.2; text-align: center; overflow-wrap: anywhere; }
.warehouse-label-preview--qrcode .warehouse-label-preview-code { margin-top: 14px; font-size: 16px; }
.warehouse-label-preview--qrcode .warehouse-label-preview-qr > svg { width: 128px !important; height: 128px !important; }
.warehouse-label-preview--barcode .warehouse-label-preview-copy { text-align: center; }
.warehouse-label-preview--barcode .warehouse-label-preview-barcode { margin-top: 18px; }
.warehouse-label-content { margin: 6px 0 0; font-size: 12px; color: #647084; line-height: 20px; }
.warehouse-label-error { display: flex; align-items: flex-start; gap: 8px; padding: 14px 0; color: #b73b35; line-height: 1.6; overflow-wrap: anywhere; }
.warehouse-label-error i { padding-top: 4px; flex: none; }
.warehouse-label-dialog .el-dialog__footer { flex: none; padding: 20px 24px; border-top: 1px solid #e5e9ef; }
.warehouse-label-footer { display: flex; gap: 16px; justify-content: flex-end; }
.warehouse-label-footer .el-button { min-width: 84px; margin: 0; height: 36px; font-size: 14px; }
.warehouse-label-footer .el-button--success { background: #008b4b; border-color: #008b4b; }
.warehouse-label-footer .el-button--success:hover { background: #00763f; border-color: #00763f; }
.warehouse-label-footer .el-button:not(.el-button--success):hover { color: #008b4b; border-color: #bbf7d0; background: #f0fdf4; }
@media (max-width: 620px) {
  .warehouse-label-dialog .el-dialog { max-width: calc(100vw - 24px); margin: 12px !important; }
  .warehouse-label-dialog .el-dialog__body { padding: 12px 16px; }
  .warehouse-label-form-row, .warehouse-label-two-column { grid-template-columns: 1fr; gap: 8px; }
  .warehouse-label-form-row { padding: 8px 0; }
  .warehouse-label-form-row .el-radio-group { gap: 12px; }
  .warehouse-label-field-title { padding-top: 0; }
  .warehouse-label-preview-area { padding: 12px; }
  .warehouse-label-preview { padding: 14px 12px 10px; }
  .warehouse-label-preview-top { gap: 16px; }
  .warehouse-label-preview-copy strong { font-size: 20px; }
  .warehouse-label-preview-copy > div { font-size: 16px; }
}
</style>
