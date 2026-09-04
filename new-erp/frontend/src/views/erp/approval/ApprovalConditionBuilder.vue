<template>
  <div class="condition-builder">
    <div class="group-head">
      <el-radio-group v-model="group.logic" size="mini" :disabled="disabled">
        <el-radio-button label="AND">全部满足</el-radio-button>
        <el-radio-button label="OR">任一满足</el-radio-button>
      </el-radio-group>
      <span>当前组 {{ (group.children || []).length }} 项</span>
    </div>
    <div v-for="(child, index) in group.children" :key="child._key || index" class="condition-row">
      <approval-condition-builder
        v-if="child.children"
        :value="child"
        :fields="fields"
        :operators="operators"
        :disabled="disabled"
        nested
        @remove="remove(index)"
      />
      <template v-else>
        <el-select v-model="child.field" filterable placeholder="选择字段" :disabled="disabled" @change="fieldChanged(child)">
          <el-option v-for="field in fields" :key="field.value" :label="field.label" :value="field.value" />
        </el-select>
        <el-select v-model="child.operator" :disabled="disabled" @change="$forceUpdate()">
          <el-option v-for="operator in availableOperators(child)" :key="operator.value" :label="operator.label" :value="operator.value" />
        </el-select>
        <el-select v-if="fieldMeta(child).options && fieldMeta(child).options.length && !noValue(child)" v-model="child.value" :multiple="['in','not_in'].includes(child.operator)" filterable :disabled="disabled">
          <el-option v-for="option in fieldMeta(child).options" :key="String(option.value)" :label="option.label" :value="option.value" />
        </el-select>
        <el-date-picker v-else-if="['date','datetime'].includes(fieldMeta(child).type) && !noValue(child)" v-model="child.value" :type="fieldMeta(child).type === 'datetime' ? 'datetime' : 'date'" :value-format="fieldMeta(child).type === 'datetime' ? 'yyyy-MM-dd HH:mm:ss' : 'yyyy-MM-dd'" :disabled="disabled" />
        <el-input-number v-else-if="['integer','decimal','number'].includes(fieldMeta(child).type) && !noValue(child)" v-model="child.value" :controls="false" :disabled="disabled" />
        <el-input v-else-if="!noValue(child)" v-model="child.value" :disabled="disabled" placeholder="比较值" />
        <span v-else class="no-value">无需填写比较值</span>
        <el-button v-if="!disabled" type="text" icon="el-icon-delete" @click="remove(index)" />
      </template>
    </div>
    <div v-if="!disabled" class="group-actions">
      <el-button size="mini" icon="el-icon-plus" @click="addCondition">添加条件</el-button>
      <el-button v-if="!nested" size="mini" icon="el-icon-connection" @click="addGroup">添加条件组</el-button>
      <el-button v-else type="text" class="danger" @click="$emit('remove')">删除条件组</el-button>
    </div>
  </div>
</template>

<script>
export default {
  name: "ApprovalConditionBuilder",
  props: { value: { type: Object, required: true }, fields: { type: Array, default: () => [] }, operators: { type: Array, default: () => [] }, disabled: Boolean, nested: Boolean },
  computed: { group() { return this.value; } },
  methods: {
    fieldMeta(row) { return this.fields.find((field) => field.value === row.field) || { type: "string", options: [] }; },
    noValue(row) { return ["empty", "not_empty"].includes(row.operator); },
    availableOperators(row) {
      const type = this.fieldMeta(row).type;
      const allowed = ["integer", "decimal", "number", "date", "datetime"].includes(type)
        ? ["=", "!=", ">", ">=", "<", "<=", "in", "not_in", "empty", "not_empty"]
        : ["=", "!=", "in", "not_in", "contains", "not_contains", "empty", "not_empty"];
      return this.operators.filter((operator) => allowed.includes(operator.value));
    },
    fieldChanged(row) { this.$set(row, "type", this.fieldMeta(row).type || "string"); this.$set(row, "operator", "="); this.$set(row, "value", ""); },
    addCondition() { const field = this.fields[0]; this.group.children.push({ _key: `${Date.now()}_${Math.random()}`, field: field ? field.value : "", type: field ? field.type : "string", operator: "=", value: "" }); },
    addGroup() { this.group.children.push({ _key: `${Date.now()}_${Math.random()}`, logic: "AND", children: [] }); },
    remove(index) { this.group.children.splice(index, 1); },
  },
};
</script>

<style scoped>
.condition-builder { border: 1px solid #e4e8ee; border-radius: 4px; padding: 8px; background: #fafbfd; }
.condition-builder .condition-builder { margin: 6px 0; background: #fff; }
.group-head, .group-actions { display: flex; align-items: center; justify-content: space-between; gap: 8px; margin-bottom: 8px; color: #8492a6; font-size: 12px; }
.condition-row { display: grid; grid-template-columns: minmax(110px,1.25fr) 96px minmax(110px,1fr) 26px; gap: 6px; align-items: center; margin-bottom: 6px; }
.condition-row > .condition-builder { grid-column: 1 / -1; }
.no-value { color: #98a2b3; font-size: 12px; }
.danger { color: #f56c6c; }
@media (max-width: 1280px) { .condition-row { grid-template-columns: 1fr 90px 1fr 24px; } }
</style>
