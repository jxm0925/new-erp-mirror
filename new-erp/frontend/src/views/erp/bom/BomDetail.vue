<template>
  <div class="bom-detail-redirect" v-loading="true">
    <p>正在载入 BOM 方案详情...</p>
  </div>
</template>

<script>
export default {
  name: 'BomDetailRedirect',
  created() {
    const id = this.$route.params.id
    const query = { view_id: id }
    if (this.$route.query.deleted === 'only') query.deleted = 'only'
    this.$router.replace({ path: '/bom/boms', query })
  }
}
</script>

<style scoped>
.bom-detail-redirect {
  display: flex;
  justify-content: center;
  align-items: center;
  height: calc(100vh - 60px);
  color: #64748b;
  font-size: 14px;
}
</style>
