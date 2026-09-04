<template>
  <div class="category-tree-picker">
    <el-input v-model="keyword" size="small" clearable prefix-icon="el-icon-search" placeholder="搜索类目名称或编码" @input="filterTree" />
    <el-tree ref="tree" :data="tree" node-key="id" :props="{ label: 'category_name', children: 'children' }" :filter-node-method="filterNode" default-expand-all class="category-tree">
      <span slot-scope="{ node, data }" class="category-node" :class="{ disabled: data.status !== 'enabled' }">
        <i :class="data.is_leaf ? 'el-icon-document' : 'el-icon-folder-opened'" />
        <span class="category-name">{{ data.category_name }}</span>
        <el-checkbox v-if="data.is_leaf" :value="isSelected(data.id)" :disabled="data.status !== 'enabled'" @change="toggle(data, $event)" @click.native.stop />
        <span v-else class="navigation-only">导航</span>
      </span>
    </el-tree>
    <div v-if="selectedRows.length" class="selected-paths">
      <el-tag v-for="row in selectedRows" :key="row.id" size="mini" closable @close="remove(row.id)">{{ row.full_path }}</el-tag>
    </div>
    <p v-else class="empty-note">尚未选择 Item 类目</p>
  </div>
</template>

<script>
export default {
  name: 'ItemCategoryTreePicker',
  props: {
    tree: { type: Array, default: () => [] },
    value: { type: [Array, Number, String], default: () => [] },
    multiple: { type: Boolean, default: false }
  },
  data: () => ({ keyword: '' }),
  computed: {
    flatRows() {
      const result = []
      const visit = rows => (rows || []).forEach(row => { result.push(row); visit(row.children) })
      visit(this.tree)
      return result
    },
    selectedIds() {
      if (this.multiple) return (Array.isArray(this.value) ? this.value : []).map(Number)
      return this.value ? [Number(this.value)] : []
    },
    selectedRows() { return this.flatRows.filter(row => this.selectedIds.includes(Number(row.id))) }
  },
  methods: {
    filterTree(value) { this.$refs.tree && this.$refs.tree.filter(value) },
    filterNode(value, data) {
      if (!value) return true
      const keyword = String(value).trim().toLowerCase()
      return `${data.category_code || ''}${data.category_name || ''}${data.full_path || ''}`.toLowerCase().includes(keyword)
    },
    isSelected(id) { return this.selectedIds.includes(Number(id)) },
    toggle(row, checked) {
      if (!row.is_leaf || row.status !== 'enabled') return
      if (!this.multiple) return this.$emit('input', checked ? Number(row.id) : null)
      const values = new Set(this.selectedIds)
      checked ? values.add(Number(row.id)) : values.delete(Number(row.id))
      this.$emit('input', Array.from(values))
    },
    remove(id) {
      if (!this.multiple) return this.$emit('input', null)
      this.$emit('input', this.selectedIds.filter(value => value !== Number(id)))
    }
  }
}
</script>

<style scoped>
.category-tree-picker{width:100%}.category-tree-picker>.el-input{margin-bottom:10px}.category-tree{max-height:280px;overflow:auto;border:1px solid #e1e7eb;border-radius:4px;padding:8px 6px}.category-node{display:flex;align-items:center;gap:7px;width:100%;font-size:12px}.category-node>i{color:#0a9a53}.category-name{flex:1;overflow:hidden;text-overflow:ellipsis}.category-node.disabled{color:#a4adb5}.category-node.disabled>i{color:#a4adb5}.navigation-only{color:#98a2aa;font-size:10px}.selected-paths{display:flex;gap:6px;flex-wrap:wrap;margin-top:9px}.selected-paths .el-tag{max-width:100%;height:auto;white-space:normal;line-height:20px}.empty-note{margin:8px 0 0;color:#8a959e;font-size:11px}
</style>
