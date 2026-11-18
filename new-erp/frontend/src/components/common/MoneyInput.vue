<template>
  <el-input-number v-if="numberControl" v-bind="$attrs" :value="numericValue"
    :max="displayMax" :precision="editing ? precision : 2"
    @focus="editing = true" @blur="editing = false" @change="changeNumber" />
  <el-input v-else v-bind="$attrs" :value="editing ? editValue : displayValue"
    @focus="editing = true" @blur="editing = false"
    @input="$emit('input', $event)" @change="$emit('change', $event)">
    <template v-if="$slots.append" slot="append"><slot name="append" /></template>
  </el-input>
</template>

<script>
import { settlementAmount } from '@/utils/money'
import { purchaseDecimal, purchasePriceText } from '@/utils/purchaseLineAmount'

export default {
  name: 'MoneyInput',
  inheritAttrs: false,
  props: {
    value: [Number, String],
    unitPrice: Boolean,
    numberControl: Boolean,
    precision: { type: Number, default: 2 },
    max: { type: Number, default: Infinity }
  },
  data: () => ({ editing: false }),
  computed: {
    editValue() { return this.unitPrice && typeof this.value === 'number' ? purchaseDecimal(this.value) : this.value },
    displayValue() { return this.unitPrice ? purchasePriceText(this.value) : settlementAmount(this.value) || (this.value == null ? '' : String(this.value)) },
    numericValue() {
      const value = this.editing ? this.value : this.displayValue
      return value == null || value === '' ? undefined : Number(value)
    },
    displayMax() { return this.editing || !Number.isFinite(this.max) ? this.max : Number(settlementAmount(this.max)) }
  },
  methods: {
    // Element 的 input-number 挂载时也发 input，只有用户真正修改时才替换精确单价。
    changeNumber(value, previous) {
      this.$emit('input', value)
      this.$emit('change', value, previous)
    }
  }
}
</script>
