<template>
  <el-dialog
    title="管理分类"
    :visible.sync="visible"
    width="1280px"
    top="5vh"
    append-to-body
    destroy-on-close
    class="item-category-manager-dialog"
    :close-on-click-modal="false"
    :close-on-press-escape="false"
    :before-close="close"
    @closed="onClosed"
  >
    <ItemCategoryList
      v-if="visible && canViewCategories"
      ref="categoryManager"
      :embedded="true"
      :initial-management-scope="managementScope"
      @changed="onChanged"
      @select-items="showItems"
    />
    <span slot="footer" class="category-manager-footer">
      <el-button size="small" @click="close">关闭分类管理</el-button>
    </span>
  </el-dialog>
</template>

<script>
import ItemCategoryList from '../../views/erp/master/ItemCategoryList.vue'

export default {
  name: 'ItemCategoryManagerDialog',
  components: { ItemCategoryList },
  props: {
    value: { type: Boolean, default: false },
    managementScope: { type: String, default: '' }
  },
  computed: {
    visible: {
      get() { return this.value },
      set(value) { this.$emit('input', value) }
    },
    canViewCategories() {
      try {
        const profile = JSON.parse(localStorage.getItem('erp_me') || '{}')
        const permissions = JSON.parse(localStorage.getItem('erp_permissions') || '[]')
        return !!(profile && profile.is_super_admin) || (Array.isArray(permissions) && permissions.includes('item_category.view'))
      } catch (_) {
        return false
      }
    }
  },
  methods: {
    canClose() {
      const manager = this.$refs.categoryManager
      if (manager && manager.saving) {
        this.$message.warning('分类正在保存，请稍后关闭')
        return false
      }
      return true
    },
    close(done) {
      if (!this.canClose()) return
      this.$emit('input', false)
      if (typeof done === 'function') done()
    },
    showItems(row) {
      if (!this.canClose()) return
      this.$emit('select-items', row)
      this.close()
    },
    onChanged(...args) { this.$emit('changed', ...args) },
    onClosed() { this.$emit('closed') }
  }
}
</script>

<style scoped>
.item-category-manager-dialog ::v-deep .el-dialog {
  display: flex;
  flex-direction: column;
  max-width: calc(100vw - 32px);
  max-height: 85vh;
}

.item-category-manager-dialog ::v-deep .el-dialog__header,
.item-category-manager-dialog ::v-deep .el-dialog__footer {
  flex: 0 0 auto;
}

.item-category-manager-dialog ::v-deep .el-dialog__body {
  flex: 1 1 auto;
  min-height: 0;
  overflow: auto;
  padding: 16px;
}

@media (max-width: 767px) {
  .item-category-manager-dialog ::v-deep .el-dialog__header,
  .item-category-manager-dialog ::v-deep .el-dialog__body,
  .item-category-manager-dialog ::v-deep .el-dialog__footer {
    padding: 12px;
  }

  .category-manager-footer {
    display: block;
    width: 100%;
  }

  .category-manager-footer .el-button {
    width: 100%;
  }
}
</style>
