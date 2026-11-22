<template>
  <el-dialog
    :title="dialogTitle"
    :visible.sync="innerVisible"
    width="560px"
    top="10vh"
    custom-class="secret-decrypt-dialog"
    :close-on-click-modal="false"
    append-to-body
    @open="handleOpen"
    @closed="handleClosed"
  >
    <div class="decrypt-intro">
      <div class="decrypt-intro-head">
        <span class="decrypt-service-name">{{ serviceName }}</span>
        <span class="algo-chip"><i class="el-icon-lock" /> SHA-256 · AES-256-GCM</span>
      </div>
      <div v-if="maskedPreview" class="decrypt-masked-row">
        <span class="masked-label">{{ targetLabel }}：</span>
        <code class="masked-code">{{ maskedPreview }}</code>
      </div>
    </div>

    <template v-if="!decryptedData">
      <el-form
        ref="decryptForm"
        :model="form"
        :rules="rules"
        label-position="top"
        size="small"
        @submit.native.prevent="handleDecrypt"
      >
        <el-form-item label="加密密码" prop="password">
          <el-input
            ref="passwordInput"
            v-model="form.password"
            type="password"
            show-password
            autocomplete="off"
            placeholder="输入保存该密钥时设置的加密密码"
            @keyup.enter.native="handleDecrypt"
          />
        </el-form-item>
      </el-form>

      <div v-if="errorMsg" class="decrypt-error-box">
        <i class="el-icon-warning-outline" />
        <span>{{ errorMsg }}</span>
      </div>
    </template>

    <template v-else>
      <div class="decrypt-success-banner">
        <i class="el-icon-circle-check" />
        <span>
          {{ autoCopied ? `解密成功，已复制「${targetLabel}」到剪贴板` : '解密成功，可点击右侧按钮复制原密钥' }}
        </span>
      </div>

      <div class="decrypted-list">
        <div
          v-for="item in displayFields"
          :key="item.key"
          class="decrypted-item"
          :class="{ 'is-target': item.key === targetKey }"
        >
          <div class="decrypted-item-head">
            <span class="decrypted-item-label">{{ item.label }}</span>
            <span v-if="item.key === targetKey" class="target-pill">当前目标</span>
          </div>
          <div class="decrypted-item-body">
            <code class="decrypted-plain-text">{{ decryptedData[item.key] || '' }}</code>
            <el-button
              size="mini"
              icon="el-icon-document-copy"
              class="btn-copy-plain"
              @click="copyField(item)"
            >
              复制
            </el-button>
          </div>
        </div>
      </div>
    </template>

    <span slot="footer" class="dialog-footer">
      <el-button size="small" :disabled="submitting" @click="innerVisible = false">
        {{ decryptedData ? '关闭' : '取消' }}
      </el-button>
      <el-button
        v-if="!decryptedData"
        size="small"
        type="success"
        icon="el-icon-unlock"
        class="btn-theme-primary"
        :loading="submitting"
        @click="handleDecrypt"
      >
        解密并复制
      </el-button>
      <el-button
        v-else
        size="small"
        type="success"
        icon="el-icon-document-copy"
        class="btn-theme-primary"
        @click="copyTargetField"
      >
        再次复制{{ targetLabel }}
      </el-button>
    </span>
  </el-dialog>
</template>

<script>
import { copyPlainText } from '@/utils/clipboard'

export default {
  name: 'SecretDecryptDialog',
  props: {
    visible: {
      type: Boolean,
      default: false
    },
    serviceName: {
      type: String,
      default: ''
    },
    targetKey: {
      type: String,
      default: ''
    },
    targetLabel: {
      type: String,
      default: '密钥'
    },
    maskedPreview: {
      type: String,
      default: ''
    },
    fields: {
      type: Array,
      default: () => []
    },
    decryptFn: {
      type: Function,
      default: null
    }
  },
  data() {
    return {
      form: {
        password: ''
      },
      rules: {
        password: [{ required: true, message: '请输入加密密码', trigger: 'blur' }]
      },
      submitting: false,
      errorMsg: '',
      decryptedData: null,
      autoCopied: false
    }
  },
  computed: {
    innerVisible: {
      get() {
        return this.visible
      },
      set(val) {
        this.$emit('update:visible', val)
      }
    },
    dialogTitle() {
      return this.decryptedData ? `原始密钥已解密（${this.serviceName}）` : `解密并复制密钥（${this.serviceName}）`
    },
    displayFields() {
      if (Array.isArray(this.fields) && this.fields.length > 0) {
        return this.fields
      }
      if (this.targetKey) {
        return [{ key: this.targetKey, label: this.targetLabel }]
      }
      return []
    }
  },
  methods: {
    extractError(error, fallback) {
      const resp = error && error.response && error.response.data
      if (resp && resp.errors) {
        const firstKey = Object.keys(resp.errors)[0]
        if (firstKey && resp.errors[firstKey] && resp.errors[firstKey][0]) {
          return resp.errors[firstKey][0]
        }
      }
      return (resp && resp.message) || error.userMessage || fallback
    },
    handleOpen() {
      this.form.password = ''
      this.errorMsg = ''
      this.decryptedData = null
      this.autoCopied = false
      this.$nextTick(() => {
        if (this.$refs.decryptForm) this.$refs.decryptForm.clearValidate()
        if (this.$refs.passwordInput && this.$refs.passwordInput.focus) {
          this.$refs.passwordInput.focus()
        }
      })
    },
    handleClosed() {
      this.form.password = ''
      this.errorMsg = ''
      this.decryptedData = null
      this.autoCopied = false
      this.submitting = false
    },
    handleDecrypt() {
      if (this.submitting || !this.decryptFn) return
      this.$refs.decryptForm.validate(async valid => {
        if (!valid) return
        this.submitting = true
        this.errorMsg = ''
        try {
          const result = await this.decryptFn(this.form.password)
          const secrets = (result && result.data && result.data.data) || result || {}
          this.decryptedData = secrets
          this.$emit('decrypted', secrets)

          const targetVal = secrets[this.targetKey] || ''
          const copied = targetVal ? await copyPlainText(targetVal) : false
          this.autoCopied = copied
          if (copied) {
            this.$message.success(`解密成功，${this.targetLabel}已复制到剪贴板`)
          } else {
            this.$message.success('解密成功，请点击按钮复制原始密钥')
          }
        } catch (e) {
          this.errorMsg = this.extractError(e, '加密密码不正确，解密失败')
          this.$nextTick(() => {
            if (this.$refs.passwordInput && this.$refs.passwordInput.focus) {
              this.$refs.passwordInput.focus()
            }
          })
        } finally {
          this.submitting = false
        }
      })
    },
    async copyField(item) {
      const value = (this.decryptedData && this.decryptedData[item.key]) || ''
      if (!value) return
      const ok = await copyPlainText(value)
      if (ok) {
        this.$message.success(`${item.label}已复制到剪贴板`)
      } else {
        this.$message.warning('自动复制受限，请直接选中上方密钥文本复制')
      }
    },
    copyTargetField() {
      const found = this.displayFields.find(f => f.key === this.targetKey) || this.displayFields[0]
      if (found) this.copyField(found)
    }
  }
}
</script>

<style scoped>
.decrypt-intro {
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 12px 14px;
  margin-bottom: 16px;
}

.decrypt-intro-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
}

.decrypt-service-name {
  font-size: 14px;
  font-weight: 700;
  color: #0f172a;
}

.algo-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 600;
  color: #00763f;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  padding: 2px 8px;
  border-radius: 999px;
}

.decrypt-masked-row {
  margin-top: 8px;
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 12px;
  color: #64748b;
}

.masked-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  color: #1e293b;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 1px 7px;
}

.decrypt-hint {
  margin-top: 5px;
  font-size: 12px;
  color: #94a3b8;
  line-height: 1.45;
}

.decrypt-error-box {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 9px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
  color: #b91c1c;
  font-size: 12px;
  line-height: 1.5;
}

.decrypt-error-box i {
  font-size: 15px;
  margin-top: 1px;
  flex-shrink: 0;
}

.decrypt-success-banner {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 10px 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  color: #00763f;
  font-size: 12px;
  line-height: 1.5;
  margin-bottom: 14px;
}

.decrypt-success-banner i {
  font-size: 16px;
  color: #008b4b;
  margin-top: 1px;
  flex-shrink: 0;
}

.decrypted-list {
  display: flex;
  flex-direction: column;
  gap: 10px;
}

.decrypted-item {
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
  background: #ffffff;
}

.decrypted-item.is-target {
  border-color: #86efac;
  background: #f8fffb;
}

.decrypted-item-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 6px;
}

.decrypted-item-label {
  font-size: 12px;
  font-weight: 600;
  color: #334155;
}

.target-pill {
  font-size: 11px;
  color: #00763f;
  background: #dcfce7;
  padding: 1px 7px;
  border-radius: 999px;
  font-weight: 600;
}

.decrypted-item-body {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 10px;
}

.decrypted-plain-text {
  flex: 1;
  min-width: 0;
  word-break: break-all;
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 13px;
  color: #0f172a;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  padding: 6px 10px;
  user-select: all;
}

.btn-copy-plain {
  background: #f0fdf4 !important;
  border: 1px solid #bbf7d0 !important;
  color: #00763f !important;
  flex-shrink: 0;
}

.btn-copy-plain:hover {
  background: #dcfce7 !important;
  border-color: #86efac !important;
}

.btn-theme-primary {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
}

.btn-theme-primary:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}
</style>
