<template>
  <div v-if="suggestion" class="ai-field-hint">
    <div class="ai-hint-content">
      <span class="ai-hint-badge">AI 建议</span>
      <span class="ai-hint-text">{{ suggestion }}</span>
    </div>
    <el-button
      type="text"
      size="mini"
      class="ai-hint-apply"
      :aria-label="actionLabel + '：' + field"
      @click="$emit('apply')"
    >
      {{ actionLabel }}
    </el-button>
  </div>
</template>

<script>
export default {
  props: {
    field: { type: String, required: true },
    suggestion: String,
    actionLabel: { type: String, default: '采用' }
  }
}
</script>

<style scoped>
.ai-field-hint {
  margin-top: 6px;
  padding: 5px 8px;
  background: #eefaf3;
  border: 1px solid #c6ebd6;
  border-radius: 6px;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 8px;
  font-size: 12px;
  line-height: 1.5;
  color: #245345;
}
.ai-hint-content {
  display: flex;
  align-items: flex-start;
  gap: 6px;
  min-width: 0;
  flex: 1;
}
.ai-hint-badge {
  flex-shrink: 0;
  padding: 0 5px;
  border-radius: 4px;
  background: #d3f2e1;
  color: #00763f;
  font-size: 11px;
  font-weight: 700;
  line-height: 18px;
}
.ai-hint-text {
  white-space: normal;
  overflow-wrap: anywhere;
}
.ai-hint-apply {
  flex-shrink: 0;
  padding: 1px 6px;
  font-weight: 600;
  color: #00763f;
}
.ai-hint-apply:hover {
  color: #005c31;
}
</style>
