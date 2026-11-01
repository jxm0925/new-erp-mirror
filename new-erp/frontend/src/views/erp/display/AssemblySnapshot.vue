<template>
  <div class="assembly-snapshot">
    <h3 v-if="showHeadings" class="snapshot-heading">基本信息</h3>
    <table class="snapshot-info" :class="{ 'history-info': !showHeadings }"><tbody>
      <tr v-if="showHeadings"><th>客户名</th><td><span :class="{ 'changed-value': changed('customer_name') }">{{ text(snapshot.customer_name) }}</span><small v-if="changed('customer_name')">修改前：{{ text(previous.customer_name) }}</small></td><th>手机号</th><td><span :class="{ 'changed-value': changed('customer_phone') }">{{ text(snapshot.customer_phone) }}</span><small v-if="changed('customer_phone')">修改前：{{ text(previous.customer_phone) }}</small></td></tr>
      <template v-else><tr><th>客户名</th><td>{{ text(snapshot.customer_name) }}</td></tr><tr><th>手机号</th><td>{{ text(snapshot.customer_phone) }}</td></tr></template>
      <tr><th>客户地址</th><td :colspan="showHeadings ? 3 : 1"><span :class="{ 'changed-value': changed('address') }">{{ text(snapshot.address) }}</span><small v-if="changed('address')">修改前：{{ text(previous.address) }}</small></td></tr>
      <tr><th>工单备注</th><td :colspan="showHeadings ? 3 : 1"><span :class="{ 'changed-value': changed('order_note') }">{{ text(snapshot.order_note) }}<b v-if="changed('order_note')">已变更</b></span><small v-if="changed('order_note')">修改前：{{ text(previous.order_note) }}</small></td></tr>
    </tbody></table>
    <h3 class="snapshot-heading">{{ showHeadings ? '设备清单' : '设备信息' }}</h3>
    <div class="snapshot-products-scroll"><table class="snapshot-products"><thead><tr><th>名称</th><th>数量</th><th>备注</th><th>图纸</th></tr></thead><tbody>
      <tr v-for="(product, index) in products" :key="product.id || index">
        <td><span :class="{ 'changed-value': productChanged(product, index, 'name') }">{{ text(product.name) }}</span><small v-if="productChanged(product, index, 'name')">修改前：{{ text(previousProduct(product, index).name) }}</small></td>
        <td><span :class="{ 'changed-value': productChanged(product, index, 'quantity') || productChanged(product, index, 'unit') }">{{ text(product.quantity) }}{{ product.unit }}</span><small v-if="productChanged(product, index, 'quantity') || productChanged(product, index, 'unit')">修改前：{{ text(previousProduct(product, index).quantity) }}{{ previousProduct(product, index).unit }}</small></td>
        <td><span :class="{ 'changed-value': productChanged(product, index, 'remark') }">{{ text(product.remark) }}<b v-if="productChanged(product, index, 'remark')">已变更</b></span><small v-if="productChanged(product, index, 'remark')">修改前：{{ text(previousProduct(product, index).remark) }}</small></td>
        <td><AssemblyAttachments :files="product.attachments || []" /><small v-if="productChanged(product, index, 'attachments')">图纸已变更</small></td>
      </tr>
      <tr v-if="!products.length"><td colspan="4" class="snapshot-empty">暂无设备信息</td></tr>
    </tbody></table></div>
  </div>
</template>

<script>
import AssemblyAttachments from './AssemblyAttachments.vue'

export default {
  name: 'AssemblySnapshot',
  components: { AssemblyAttachments },
  props: { snapshot: { type: Object, required: true }, previous: { type: Object, default: null }, showHeadings: { type: Boolean, default: false } },
  computed: { products() { return this.snapshot.products || [] } },
  methods: {
    text(value) { return value === null || value === undefined || value === '' ? '—' : value },
    changed(field) { return !!this.previous && JSON.stringify(this.snapshot[field] || '') !== JSON.stringify(this.previous[field] || '') },
    previousProduct(product, index) {
      const products = (this.previous && this.previous.products) || []
      return (product.id != null ? products.find(item => String(item.id) === String(product.id)) : products[index]) || {}
    },
    productChanged(product, index, field) {
      return !!this.previous && JSON.stringify(product[field] == null ? '' : product[field]) !== JSON.stringify(this.previousProduct(product, index)[field] == null ? '' : this.previousProduct(product, index)[field])
    }
  }
}
</script>

<style scoped>
.assembly-snapshot {
  min-width: 0;
}
.snapshot-heading {
  margin: 22px 0 12px;
  padding-left: 16px;
  border-left: 4px solid #008b4b;
  font-size: 20px;
  line-height: 1.1;
  color: #172033;
}
.snapshot-info,
.snapshot-products {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  overflow: hidden;
  border: 1px solid #dfe5ec;
  border-radius: 6px;
  color: #172033;
}
.snapshot-info th,
.snapshot-info td,
.snapshot-products th,
.snapshot-products td {
  padding: 13px 20px;
  border-right: 1px solid #e2e8f0;
  border-bottom: 1px solid #e2e8f0;
  text-align: left;
  font-weight: 400;
  white-space: pre-wrap;
  overflow-wrap: anywhere;
}
.snapshot-info th {
  width: 140px;
  background: #f8fafc;
  color: #475569;
}
.snapshot-info.history-info th {
  width: 180px;
  background: #f1f5f9;
}
.snapshot-info tr:last-child > *,
.snapshot-products tbody tr:last-child > * {
  border-bottom: 0;
}
.snapshot-info tr > *:last-child,
.snapshot-products tr > *:last-child {
  border-right: 0;
}
.snapshot-products th {
  background: #f1f5f9;
  font-weight: 600;
}
.snapshot-products td:nth-child(1) {
  width: 30%;
}
.snapshot-products td:nth-child(2) {
  width: 12%;
}
.snapshot-products td:nth-child(3) {
  width: 32%;
}
.snapshot-products-scroll {
  overflow: auto;
}
.snapshot-products small,
.snapshot-info small {
  display: block;
  margin-top: 4px;
  color: #7b8798;
  font-size: 14px;
}
.changed-value {
  display: inline-block;
  padding: 5px 10px;
  border-radius: 5px;
  background: #fff4dc;
}
.changed-value b {
  margin-left: 18px;
  color: #b96a00;
  font-size: 13px;
}
.snapshot-empty {
  text-align: center !important;
  color: #94a3b8;
}
@media (max-width: 780px) {
  .snapshot-info th,
  .snapshot-info td,
  .snapshot-products th,
  .snapshot-products td {
    padding: 10px;
  }
  .snapshot-info th {
    width: 75px;
  }
  .snapshot-products {
    min-width: 550px;
  }
}
</style>
