<template>
  <section v-loading="busy" class="workspace-card ai-workspace">
    <!-- 顶部多厂商总览 -->
    <div class="ai-overview-head">
      <div class="ai-overview-left">
        <h2 class="workspace-title">AI 模型服务</h2>
        <el-tag
          size="small"
          :type="overviewState.enabled_count > 0 ? 'success' : 'info'"
          effect="plain"
          class="active-summary-tag"
        >
          <template v-if="overviewState.active_vendor_name && overviewState.active_model">
            当前生效：{{ overviewState.active_vendor_name }} · {{ overviewState.active_model }}
          </template>
          <template v-else-if="overviewState.active_vendor_name">
            当前默认：{{ overviewState.active_vendor_name }}（未选择模型）
          </template>
          <template v-else>
            未配置厂商
          </template>
        </el-tag>
        <el-tag
          v-if="providers.length > 0"
          size="small"
          type="info"
          effect="plain"
        >
          已配 {{ providers.length }} 家 · 启用 {{ overviewState.enabled_count || 0 }} 家
        </el-tag>
      </div>

      <div v-if="canManage && isNew && providers.length > 0" class="ai-overview-actions">
        <el-button
          size="small"
          class="btn-cancel-inline"
          :disabled="busy"
          @click="cancelCreateProvider"
        >
          取消新建
        </el-button>
      </div>
    </div>

    <!-- 多厂商切换卡片条（含添加厂商卡片） -->
    <div class="vendor-card-strip">
      <div
        v-for="item in providers"
        :key="item.service_code"
        class="vendor-card"
        :class="{
          'is-selected': !isNew && selectedCode === item.service_code,
          'is-default': item.is_default
        }"
        @click="selectProvider(item)"
      >
        <div class="vendor-card-top">
          <div class="vendor-card-title-wrap">
            <span class="status-dot" :class="item.enabled ? 'dot-active' : 'dot-idle'" />
            <span class="vendor-card-name">{{ item.vendor_name }}</span>
          </div>
          <div class="vendor-card-badges">
            <span v-if="item.is_default" class="vendor-badge badge-default">默认</span>
            <span class="vendor-badge" :class="item.enabled ? 'badge-on' : 'badge-off'">
              {{ item.enabled ? '启用' : '停用' }}
            </span>
          </div>
        </div>
        <div class="vendor-card-bottom">
          <code class="vendor-model-code" :title="item.model || '未选择模型'">
            {{ item.model || '未选择模型' }}
          </code>
          <span v-if="item.models && item.models.length > 1" class="vendor-model-count">
            {{ item.models.length }} 个模型
          </span>
        </div>
      </div>

      <div v-if="isNew" class="vendor-card is-selected is-draft">
        <div class="vendor-card-top">
          <div class="vendor-card-title-wrap">
            <span class="status-dot dot-draft" />
            <span class="vendor-card-name">{{ form.vendor_name || currentPresetName }}</span>
          </div>
          <span class="vendor-badge badge-draft">待保存</span>
        </div>
        <div class="vendor-card-bottom">
          <code class="vendor-model-code">{{ form.model || '待选择模型' }}</code>
        </div>
      </div>

      <div
        v-if="canManage && !isNew"
        class="vendor-card vendor-add-card"
        @click="openAddVendorDialog(false)"
      >
        <i class="el-icon-plus" />
        <span>添加厂商</span>
      </div>
    </div>

    <!-- 当前选中厂商操作头 -->
    <div class="workspace-head">
      <div class="workspace-head-left">
        <div class="workspace-title-row">
          <template v-if="editingName && canManage">
            <el-input
              ref="vendorNameInput"
              v-model.trim="form.vendor_name"
              size="mini"
              maxlength="80"
              class="inline-name-input"
              placeholder="输入厂商显示名称"
              @keyup.enter.native="finishEditName"
              @blur="finishEditName"
            />
          </template>
          <template v-else>
            <h3 class="vendor-workspace-title">
              {{ form.vendor_name || currentPresetName }}
            </h3>
            <i
              v-if="canManage"
              class="el-icon-edit-outline rename-icon"
              title="修改显示名称"
              @click="startEditName"
            />
          </template>
          <el-tag size="small" type="info" effect="plain">{{ currentPresetName }}</el-tag>
          <el-tag size="small" :type="serviceTagType" effect="plain">
            {{ serviceHeaderBadge }}
          </el-tag>
          <el-tag v-if="!isNew && savedRow.is_default" size="small" type="success" effect="dark">
            当前默认
          </el-tag>
          <el-button
            v-if="canManage && isNew"
            type="text"
            size="mini"
            icon="el-icon-refresh"
            class="btn-reselect-vendor"
            @click="openAddVendorDialog(true)"
          >
            重选厂商
          </el-button>
        </div>
        <div v-if="!isNew" class="workspace-meta-row">
          <span>最近检测：{{ savedRow.last_checked_at || '尚未检测' }}</span>
          <template v-if="savedRow.updated_by_name">
            <span class="meta-sep">·</span>
            <span>更新人：{{ savedRow.updated_by_name }}</span>
          </template>
          <template v-if="savedRow.updated_at">
            <span class="meta-sep">·</span>
            <span>{{ savedRow.updated_at }}</span>
          </template>
        </div>
      </div>

      <div class="workspace-head-actions">
        <div class="enable-switch-wrap">
          <span class="enable-label">{{ form.enabled ? '已启用' : '未启用' }}</span>
          <el-switch
            v-model="form.enabled"
            :disabled="!canManage || busy"
            active-color="#008b4b"
            inactive-color="#cbd5e1"
          />
        </div>
        <el-button
          v-if="canManage && !isNew && !savedRow.is_default"
          size="small"
          icon="el-icon-star-off"
          class="btn-check"
          :disabled="busy || !savedRow.has_key || !form.model"
          @click="handleSetDefault"
        >
          设为默认
        </el-button>
        <el-button
          v-if="canManage"
          size="small"
          icon="el-icon-link"
          class="btn-check"
          :loading="checkingModel"
          :disabled="busy || !canConnect || !form.model"
          @click="check"
        >
          测试模型
        </el-button>
        <el-button
          v-if="canManage"
          size="small"
          type="success"
          icon="el-icon-check"
          class="btn-theme-save"
          :loading="saving"
          :disabled="busy || (form.enabled && (!canConnect || !form.model))"
          @click="save"
        >
          保存配置
        </el-button>
        <el-button
          v-if="canManage && !isNew"
          size="small"
          type="danger"
          plain
          icon="el-icon-delete"
          :disabled="busy"
          @click="handleDelete"
        >
          删除
        </el-button>
      </div>
    </div>

    <!-- 错误或实时测试提示 -->
    <el-alert
      v-if="error"
      :title="error"
      type="error"
      show-icon
      closable
      class="check-alert"
      @close="error = ''"
    />
    <el-alert
      v-else-if="checkNote"
      title="模型测试通过"
      :description="checkNote"
      type="success"
      show-icon
      closable
      class="check-alert"
      @close="checkNote = ''"
    />

    <el-form
      v-if="ready"
      ref="aiForm"
      :model="form"
      :rules="rules"
      label-position="top"
      size="small"
      :disabled="!canManage || busy"
      class="ai-config-form"
      :class="{ 'is-readonly-view': !canManage }"
    >
      <!-- 第一组：连接与密钥（API 地址 + API Key） -->
      <div class="form-section">
        <div class="section-head">
          <div class="section-title-group">
            <span class="section-bar" />
            <h3>连接与密钥</h3>
          </div>
          <div class="section-head-actions">
            <el-button
              v-if="canManage && showSavedKeyCard"
              size="mini"
              icon="el-icon-key"
              class="btn-rotate-inline"
              :disabled="busy"
              @click="startEditKey"
            >
              更换密钥
            </el-button>
            <el-button
              v-else-if="canManage && editingKey && savedRow.has_key && sameEndpoint"
              size="mini"
              class="btn-cancel-inline"
              :disabled="busy"
              @click="cancelEditKey"
            >
              取消更换
            </el-button>
          </div>
        </div>

        <el-form-item label="API 基础地址" prop="base_url">
          <el-input
            v-model.trim="form.base_url"
            :disabled="!canManage || busy || isUrlLocked"
            placeholder="https://.../v1"
            @input="changeAddress"
          />
        </el-form-item>

        <div v-if="showSavedKeyCard" class="saved-secret-panel">
          <el-form-item label="API Key">
            <div class="secret-value-bar" :class="{ 'is-revealed': !!revealedKey }">
              <div class="secret-value-main">
                <code class="secret-code">{{ revealedKey || savedRow.api_key_masked || '已加密保存' }}</code>
                <span v-if="revealedKey" class="secret-chip chip-revealed">
                  <i class="el-icon-view" /> 已解密
                </span>
                <span v-else-if="savedRow.has_secret_password" class="secret-chip chip-encrypted">
                  <i class="el-icon-lock" /> SHA-256 加密
                </span>
                <span v-else class="secret-chip chip-legacy">
                  <i class="el-icon-warning-outline" /> 旧版未设密码
                </span>
              </div>

              <div class="secret-value-actions">
                <template v-if="revealedKey">
                  <el-button
                    size="mini"
                    icon="el-icon-document-copy"
                    class="btn-secret-action"
                    :disabled="false"
                    @click="copyRevealedKey"
                  >
                    复制
                  </el-button>
                  <el-button
                    size="mini"
                    icon="el-icon-view"
                    class="btn-secret-ghost"
                    :disabled="false"
                    @click="revealedKey = ''"
                  >
                    恢复掩码
                  </el-button>
                </template>
                <template v-else>
                  <el-button
                    size="mini"
                    icon="el-icon-document-copy"
                    class="btn-secret-action"
                    :disabled="!savedRow.has_secret_password"
                    @click="openDecryptDialog"
                  >
                    解密复制
                  </el-button>
                </template>
              </div>
            </div>
            <div v-if="!savedRow.has_secret_password" class="field-hint-warn">
              旧版方式保存，暂未设置解密密码；如需解密复制请点击「更换密钥」。
            </div>
          </el-form-item>
        </div>

        <div v-else-if="!canManage" class="saved-secret-panel">
          <el-form-item label="API Key">
            <div class="secret-value-bar">
              <span class="secret-empty-text">尚未配置 API Key</span>
            </div>
          </el-form-item>
        </div>

        <div v-else class="new-secret-panel">
          <el-form-item label="API Key" prop="api_key">
            <div class="plain-input-row">
              <el-input
                v-model.trim="form.api_key"
                type="text"
                autocomplete="off"
                placeholder="输入服务商提供的 API Key，如 sk-..."
                @input="onKeyInput"
              />
              <el-button
                v-if="form.api_key"
                size="small"
                icon="el-icon-document-copy"
                class="btn-inline-copy"
                :disabled="false"
                @click="copyInputKey"
              >
                复制
              </el-button>
            </div>
          </el-form-item>

          <div class="form-grid-2">
            <el-form-item label="密钥加密密码" prop="encryption_password">
              <el-input
                v-model="form.encryption_password"
                type="password"
                show-password
                autocomplete="new-password"
                placeholder="至少 4 位，解密复制时需输入此密码"
              />
            </el-form-item>

            <el-form-item label="确认加密密码" prop="encryption_password_confirm">
              <el-input
                v-model="form.encryption_password_confirm"
                type="password"
                show-password
                autocomplete="new-password"
                placeholder="再次输入加密密码"
              />
            </el-form-item>
          </div>
        </div>
      </div>

      <!-- 第二组：模型配置 -->
      <div class="form-section form-section-last">
        <div class="section-head">
          <div class="section-title-group">
            <span class="section-bar" />
            <h3>模型配置</h3>
            <el-tag
              size="mini"
              :type="form.model ? 'success' : 'info'"
              effect="plain"
              class="current-model-badge"
            >
              当前模型：{{ form.model || '未选择' }}
            </el-tag>
          </div>
          <div class="section-head-actions">
            <el-button
              v-if="canManage"
              size="mini"
              icon="el-icon-refresh"
              class="btn-rotate-inline"
              :loading="listingModels"
              :disabled="busy || !canConnect"
              @click="listModels"
            >
              查询厂商模型
            </el-button>
          </div>
        </div>

        <div v-if="catalogNote" class="probe-status-banner" role="status">
          <i class="el-icon-info" />
          <span>{{ catalogNote }}</span>
        </div>

        <div class="form-grid-2">
          <el-form-item label="当前默认模型" prop="model">
            <el-select
              v-model="form.model"
              filterable
              allow-create
              default-first-option
              style="width: 100%"
              placeholder="选择或输入默认模型 ID"
              @change="onPrimaryModelChange"
            >
              <el-option
                v-for="item in selectOptions"
                :key="item.id"
                :value="item.id"
                :label="item.name === item.id ? item.id : `${item.name} · ${item.id}`"
              />
            </el-select>
          </el-form-item>

          <el-form-item v-if="canManage" label="添加其他模型（可选）">
            <div class="plain-input-row">
              <el-input
                v-model.trim="customModelInput"
                placeholder="输入模型 ID 回车加入（如 ep-xxx / qwen-max）"
                @keyup.enter.native="addCustomModel"
              />
              <el-button
                size="small"
                icon="el-icon-plus"
                class="btn-inline-copy"
                :disabled="!customModelInput"
                @click="addCustomModel"
              >
                添加
              </el-button>
            </div>
          </el-form-item>
        </div>

        <!-- 已启用模型列表 -->
        <div class="model-block">
          <div class="model-block-head">
            <span class="model-block-title">已启用模型（{{ form.models.length }}）</span>
            <span v-if="form.models.length > 1 && canManage" class="model-block-tip">点击标签切换默认模型</span>
          </div>
          <div v-if="form.models.length > 0" class="selected-model-tags">
            <div
              v-for="modelId in form.models"
              :key="modelId"
              class="selected-model-tag"
              :class="{ 'is-primary': modelId === form.model, 'is-clickable': canManage }"
              @click="setAsPrimaryModel(modelId)"
            >
              <span v-if="modelId === form.model" class="primary-dot">默认</span>
              <code class="selected-model-id">{{ modelId }}</code>
              <i
                v-if="canManage"
                class="el-icon-close remove-model-icon"
                title="移出"
                @click.stop="removeSelectedModel(modelId)"
              />
            </div>
          </div>
          <div v-else class="model-empty-note">暂无已选模型，请在上方选择或点击下方厂商模型。</div>
        </div>

        <!-- 厂商返回模型回显 -->
        <div class="model-block">
          <div class="model-block-head">
            <span class="model-block-title">厂商返回模型（{{ catalogModels.length }}）</span>
            <el-input
              v-if="catalogModels.length > 6"
              v-model.trim="modelFilter"
              size="mini"
              clearable
              prefix-icon="el-icon-search"
              placeholder="筛选模型 ID..."
              class="catalog-filter-input"
              :disabled="false"
            />
          </div>

          <div v-if="modelNotInCatalog" class="model-mismatch-tip">
            <i class="el-icon-warning-outline" />
            <span>当前模型 <code>{{ form.model }}</code> 不在厂商本次返回列表中，可点击下方模型切换。</span>
          </div>

          <div v-if="filteredCatalogModels.length > 0" class="catalog-grid">
            <div
              v-for="item in filteredCatalogModels"
              :key="item.id"
              class="catalog-item"
              :class="{
                'is-picked': form.models.includes(item.id),
                'is-primary': form.model === item.id,
                'is-clickable': canManage
              }"
              @click="toggleCatalogModel(item)"
            >
              <div class="catalog-item-main">
                <code class="catalog-item-id">{{ item.id }}</code>
                <span v-if="item.name && item.name !== item.id" class="catalog-item-name">{{ item.name }}</span>
              </div>
              <span v-if="form.model === item.id" class="catalog-badge badge-primary">默认</span>
              <span v-else-if="form.models.includes(item.id)" class="catalog-badge badge-picked">已选</span>
            </div>
          </div>
          <div v-else-if="catalogModels.length > 0" class="model-empty-note">
            未找到匹配「{{ modelFilter }}」的模型。
          </div>
          <div v-else class="model-empty-note">
            {{ manualMode ? '该厂商未提供模型列表接口，请在上方下拉框或输入框手工录入模型 ID。' : '尚未查询厂商模型，可点击右上角「查询厂商模型」获取并缓存。' }}
          </div>
        </div>
      </div>
    </el-form>

    <!-- 添加 / 选择厂商弹窗 -->
    <el-dialog
      :title="addDialog.reselect ? '选择 AI 厂商' : '添加 AI 厂商'"
      :visible.sync="addDialog.visible"
      width="600px"
      top="8vh"
      custom-class="add-vendor-dialog"
      :close-on-click-modal="false"
      append-to-body
    >
      <div class="preset-section-label">选择厂商</div>
      <div class="preset-grid">
        <div
          v-for="preset in presets"
          :key="preset.code"
          class="preset-card"
          :class="{ 'is-active': addDialog.provider === preset.code }"
          @click="pickDialogPreset(preset)"
        >
          <div class="preset-card-head">
            <span class="preset-card-name">{{ preset.name }}</span>
            <i v-if="addDialog.provider === preset.code" class="el-icon-check preset-check-icon" />
            <span v-else-if="configuredProviderCodes.has(preset.code)" class="preset-configured-tag">已配置</span>
          </div>
          <div class="preset-card-url">{{ preset.base_url || '自定义 HTTPS 接口地址' }}</div>
        </div>
      </div>

      <el-form
        ref="addVendorForm"
        :model="addDialog"
        :rules="addDialogRules"
        label-position="top"
        size="small"
      >
        <div class="form-grid-2">
          <el-form-item label="显示名称" prop="vendor_name">
            <el-input
              v-model.trim="addDialog.vendor_name"
              placeholder="例如：DeepSeek / 阿里云百炼"
              maxlength="80"
            />
          </el-form-item>

          <el-form-item label="API 基础地址" prop="base_url">
            <el-input
              v-model.trim="addDialog.base_url"
              :disabled="isDialogUrlLocked"
              placeholder="https://.../v1"
            />
          </el-form-item>
        </div>
      </el-form>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" @click="addDialog.visible = false">取消</el-button>
        <el-button
          size="small"
          type="success"
          class="btn-theme-save"
          @click="confirmAddVendor"
        >
          {{ addDialog.reselect ? '确认切换' : '下一步：配置密钥' }}
        </el-button>
      </span>
    </el-dialog>

    <!-- 解密复制弹窗 -->
    <secret-decrypt-dialog
      :visible.sync="decryptDialogVisible"
      :service-name="form.vendor_name || currentPresetName"
      target-key="api_key"
      target-label="API Key"
      :masked-preview="savedRow.api_key_masked || ''"
      :fields="[{ key: 'api_key', label: 'API Key' }]"
      :decrypt-fn="decryptAiRequest"
      @decrypted="onDecrypted"
    />
  </section>
</template>

<script>
import SecretDecryptDialog from '@/components/system/SecretDecryptDialog.vue'
import { copyPlainText } from '@/utils/clipboard'
import {
  getCrmAiConfig,
  saveCrmAiConfig,
  listCrmAiModels,
  checkCrmAiModel,
  decryptCrmAiSecret,
  setDefaultCrmAi,
  deleteCrmAi
} from '@/api/erp/thirdPartyService'

const FALLBACK_PRESETS = [
  { code: 'deepseek', name: 'DeepSeek', base_url: 'https://api.deepseek.com', default_model: 'deepseek-chat', editable_url: false },
  { code: 'qwen', name: '通义千问（阿里云百炼）', base_url: 'https://dashscope.aliyuncs.com/compatible-mode/v1', default_model: 'qwen-plus', editable_url: true },
  { code: 'zhipu', name: '智谱 GLM', base_url: 'https://open.bigmodel.cn/api/paas/v4', default_model: 'glm-4-plus', editable_url: true },
  { code: 'moonshot', name: 'Kimi（Moonshot）', base_url: 'https://api.moonshot.cn/v1', default_model: 'moonshot-v1-8k', editable_url: true },
  { code: 'doubao', name: '豆包（火山方舟）', base_url: 'https://ark.cn-beijing.volces.com/api/v3', default_model: '', editable_url: true },
  { code: 'openai', name: 'OpenAI', base_url: 'https://api.openai.com/v1', default_model: 'gpt-4o-mini', editable_url: true },
  { code: 'compatible', name: '自定义兼容服务', base_url: '', default_model: '', editable_url: true }
]

export default {
  name: 'CrmAiProviderSettings',
  components: { SecretDecryptDialog },
  props: { canManage: Boolean },
  data() {
    const validateEncPassword = (rule, value, callback) => {
      if (!this.needsNewSecretEncryption) return callback()
      const val = String(value || '')
      if (!val) return callback(new Error('保存新 API Key 时请设置加密密码'))
      if (val.length < 4) return callback(new Error('密钥加密密码长度不能少于 4 位'))
      callback()
    }
    const validateEncPasswordConfirm = (rule, value, callback) => {
      if (!this.needsNewSecretEncryption) return callback()
      if (!value) return callback(new Error('请再次输入加密密码'))
      if (value !== this.form.encryption_password) return callback(new Error('两次输入的加密密码不一致'))
      callback()
    }
    return {
      overviewState: {},
      presets: FALLBACK_PRESETS,
      providers: [],
      selectedCode: '',
      isNew: false,
      editingName: false,
      savedRow: {},
      form: {
        service_code: '',
        enabled: true,
        is_default: false,
        provider: 'deepseek',
        vendor_name: 'DeepSeek',
        base_url: 'https://api.deepseek.com',
        model: 'deepseek-chat',
        models: ['deepseek-chat'],
        api_key: '',
        encryption_password: '',
        encryption_password_confirm: ''
      },
      addDialog: {
        visible: false,
        reselect: false,
        provider: 'deepseek',
        vendor_name: 'DeepSeek',
        base_url: 'https://api.deepseek.com'
      },
      addDialogRules: {
        vendor_name: [{ required: true, message: '请填写厂商显示名称', trigger: 'blur' }],
        base_url: [
          { required: true, message: '请填写 HTTPS API 基础地址', trigger: 'blur' },
          { pattern: /^https:\/\/.+/i, message: '须为 https:// 开头的地址', trigger: 'blur' }
        ]
      },
      catalogModels: [],
      customModelInput: '',
      modelFilter: '',
      busy: false,
      saving: false,
      listingModels: false,
      checkingModel: false,
      ready: false,
      error: '',
      manualMode: false,
      catalogNote: '',
      checkNote: '',
      editingKey: false,
      revealedKey: '',
      decryptDialogVisible: false,
      rules: {
        base_url: [
          { required: true, message: '请填写 HTTPS API 基础地址', trigger: 'blur' },
          { pattern: /^https:\/\/.+/i, message: '须为 https:// 开头的地址', trigger: 'blur' }
        ],
        encryption_password: [{ validator: validateEncPassword, trigger: 'blur' }],
        encryption_password_confirm: [{ validator: validateEncPasswordConfirm, trigger: 'blur' }]
      }
    }
  },
  computed: {
    currentPreset() {
      return this.presets.find(p => p.code === this.form.provider) || null
    },
    currentPresetName() {
      return (this.currentPreset && this.currentPreset.name) || '自定义兼容服务'
    },
    isUrlLocked() {
      return !!(this.currentPreset && this.currentPreset.editable_url === false)
    },
    isDialogUrlLocked() {
      const preset = this.presets.find(p => p.code === this.addDialog.provider)
      return !!(preset && preset.editable_url === false)
    },
    configuredProviderCodes() {
      return new Set(this.providers.map(p => p.provider))
    },
    sameEndpoint() {
      if (this.isNew || !this.savedRow || !this.savedRow.service_code) return false
      return this.form.provider === this.savedRow.provider &&
        (this.form.base_url || '').replace(/\/+$/, '') === (this.savedRow.base_url || '')
    },
    showSavedKeyCard() {
      return !!(this.savedRow.has_key && this.sameEndpoint && !this.editingKey)
    },
    needsNewSecretEncryption() {
      return !!String(this.form.api_key || '').trim()
    },
    canConnect() {
      return !!this.form.base_url && (!!this.form.api_key || (this.sameEndpoint && this.savedRow.has_key))
    },
    selectOptions() {
      const map = new Map()
      const pushOption = (id, name) => {
        const cleanId = String(id || '').trim()
        if (!cleanId) return
        if (!map.has(cleanId)) {
          map.set(cleanId, { id: cleanId, name: String(name || cleanId).trim() || cleanId })
        }
      }
      if (this.form.model) {
        pushOption(this.form.model, this.form.model)
      }
      ;(this.form.models || []).forEach(id => pushOption(id, id))
      ;(this.catalogModels || []).forEach(item => pushOption(item.id, item.name))
      return Array.from(map.values())
    },
    filteredCatalogModels() {
      const kw = String(this.modelFilter || '').trim().toLowerCase()
      if (!kw) return this.catalogModels
      return this.catalogModels.filter(item =>
        String(item.id || '').toLowerCase().includes(kw) ||
        String(item.name || '').toLowerCase().includes(kw)
      )
    },
    modelNotInCatalog() {
      if (!this.form.model || !this.catalogModels.length) return false
      return !this.catalogModels.some(item => item.id === this.form.model)
    },
    serviceHeaderBadge() {
      if (this.isNew) return '新建配置'
      if (!this.form.enabled) return '已停用'
      if (this.savedRow.last_check_status === 'ok') return '已启用 · 连接正常'
      if (this.savedRow.last_check_status === 'failed') return '已启用 · 连接异常'
      return '已启用'
    },
    serviceTagType() {
      if (this.isNew) return 'info'
      if (!this.form.enabled) return 'warning'
      if (this.savedRow.last_check_status === 'failed') return 'danger'
      return 'success'
    }
  },
  created() {
    this.load()
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
    applySettings(payload, preferCode = '') {
      const data = payload || {}
      this.overviewState = data
      if (Array.isArray(data.provider_presets) && data.provider_presets.length > 0) {
        this.presets = data.provider_presets
      }
      this.providers = Array.isArray(data.providers) ? data.providers : []
      this.ready = true
      this.$emit('state', data)

      const targetCode = preferCode || data.saved_service_code || this.selectedCode || data.active_service_code
      const matched = this.providers.find(p => p.service_code === targetCode) ||
        this.providers.find(p => p.is_default) ||
        this.providers[0] ||
        null

      if (matched) {
        this.selectProvider(matched)
      } else {
        this.enterDraftFromPreset(this.presets[0] || FALLBACK_PRESETS[0])
      }
    },
    selectProvider(item) {
      this.isNew = false
      this.editingName = false
      this.selectedCode = item.service_code
      this.savedRow = { ...item }
      const selectedModels = Array.isArray(item.models) ? [...item.models] : []
      if (item.model && !selectedModels.includes(item.model)) {
        selectedModels.unshift(item.model)
      }
      this.form = {
        service_code: item.service_code,
        enabled: !!item.enabled,
        is_default: !!item.is_default,
        provider: item.provider || 'deepseek',
        vendor_name: item.vendor_name || '',
        base_url: item.base_url || '',
        model: item.model || selectedModels[0] || '',
        models: selectedModels,
        api_key: '',
        encryption_password: '',
        encryption_password_confirm: ''
      }
      this.catalogModels = Array.isArray(item.available_models) ? [...item.available_models] : []
      this.customModelInput = ''
      this.modelFilter = ''
      this.editingKey = false
      this.revealedKey = ''
      this.manualMode = false
      this.catalogNote = ''
      this.checkNote = ''
      this.error = ''
      this.$nextTick(() => {
        if (this.$refs.aiForm) this.$refs.aiForm.clearValidate()
      })
    },
    enterDraftFromPreset(preset, customName = '', customBaseUrl = '') {
      const defaultModel = (preset && preset.default_model) || ''
      const base = customBaseUrl !== '' ? customBaseUrl : ((preset && preset.base_url) || '')
      const name = customName !== '' ? customName : ((preset && preset.name) || '新 AI 厂商')
      this.isNew = true
      this.editingName = false
      this.selectedCode = ''
      this.savedRow = {}
      this.form = {
        service_code: '',
        enabled: true,
        is_default: this.providers.length === 0,
        provider: (preset && preset.code) || 'compatible',
        vendor_name: name,
        base_url: base,
        model: defaultModel,
        models: defaultModel ? [defaultModel] : [],
        api_key: '',
        encryption_password: '',
        encryption_password_confirm: ''
      }
      this.catalogModels = []
      this.customModelInput = ''
      this.modelFilter = ''
      this.editingKey = true
      this.revealedKey = ''
      this.manualMode = false
      this.catalogNote = ''
      this.checkNote = ''
      this.error = ''
      this.$nextTick(() => {
        if (this.$refs.aiForm) this.$refs.aiForm.clearValidate()
      })
    },
    openAddVendorDialog(reselect = false) {
      const usedCodes = this.configuredProviderCodes
      const preset = reselect
        ? (this.presets.find(p => p.code === this.form.provider) || this.presets[0] || FALLBACK_PRESETS[0])
        : (this.presets.find(p => !usedCodes.has(p.code)) || this.presets[0] || FALLBACK_PRESETS[0])
      this.addDialog = {
        visible: true,
        reselect: !!reselect,
        provider: preset.code,
        vendor_name: reselect && this.form.vendor_name ? this.form.vendor_name : preset.name,
        base_url: reselect && this.form.base_url ? this.form.base_url : (preset.base_url || '')
      }
      this.$nextTick(() => {
        if (this.$refs.addVendorForm) this.$refs.addVendorForm.clearValidate()
      })
    },
    pickDialogPreset(preset) {
      this.addDialog.provider = preset.code
      this.addDialog.vendor_name = preset.name
      this.addDialog.base_url = preset.base_url || ''
      this.$nextTick(() => {
        if (this.$refs.addVendorForm) this.$refs.addVendorForm.clearValidate()
      })
    },
    confirmAddVendor() {
      if (!this.$refs.addVendorForm) return
      this.$refs.addVendorForm.validate(valid => {
        if (!valid) return
        const preset = this.presets.find(p => p.code === this.addDialog.provider) || FALLBACK_PRESETS[0]
        this.enterDraftFromPreset(preset, this.addDialog.vendor_name, this.addDialog.base_url)
        this.addDialog.visible = false
      })
    },
    cancelCreateProvider() {
      if (this.providers.length === 0) return
      const target = this.providers.find(p => p.service_code === this.selectedCode) ||
        this.providers.find(p => p.is_default) ||
        this.providers[0]
      if (target) this.selectProvider(target)
    },
    startEditName() {
      this.editingName = true
      this.$nextTick(() => {
        if (this.$refs.vendorNameInput && this.$refs.vendorNameInput.focus) {
          this.$refs.vendorNameInput.focus()
        }
      })
    },
    finishEditName() {
      if (!this.form.vendor_name) {
        this.form.vendor_name = this.currentPresetName
      }
      this.editingName = false
    },
    changeAddress() {
      this.form.api_key = ''
      this.form.encryption_password = ''
      this.form.encryption_password_confirm = ''
      this.editingKey = false
      this.revealedKey = ''
      this.catalogModels = []
      this.manualMode = false
      this.catalogNote = ''
      this.checkNote = ''
      this.error = ''
    },
    onKeyInput() {
      this.catalogNote = ''
      this.checkNote = ''
      this.error = ''
    },
    startEditKey() {
      this.editingKey = true
      this.form.api_key = ''
      this.form.encryption_password = ''
      this.form.encryption_password_confirm = ''
      this.revealedKey = ''
      this.$nextTick(() => {
        if (this.$refs.aiForm) this.$refs.aiForm.clearValidate()
      })
    },
    cancelEditKey() {
      this.editingKey = false
      this.form.api_key = ''
      this.form.encryption_password = ''
      this.form.encryption_password_confirm = ''
      this.$nextTick(() => {
        if (this.$refs.aiForm) this.$refs.aiForm.clearValidate()
      })
    },
    onPrimaryModelChange(val) {
      const modelId = String(val || '').trim()
      this.form.model = modelId
      if (modelId && !this.form.models.includes(modelId)) {
        this.form.models.push(modelId)
      }
      this.checkNote = ''
      this.error = ''
    },
    setAsPrimaryModel(modelId) {
      if (!this.canManage) return
      this.form.model = modelId
      this.checkNote = ''
    },
    removeSelectedModel(modelId) {
      if (!this.canManage) return
      const next = this.form.models.filter(id => id !== modelId)
      this.form.models = next
      if (this.form.model === modelId) {
        this.form.model = next[0] || ''
      }
    },
    addCustomModel() {
      if (!this.canManage) return
      const id = String(this.customModelInput || '').trim()
      if (!id) return
      if (!this.form.models.includes(id)) {
        this.form.models.push(id)
      }
      if (!this.form.model) {
        this.form.model = id
      }
      this.customModelInput = ''
    },
    toggleCatalogModel(item) {
      if (!this.canManage || !item || !item.id) return
      const id = item.id
      if (this.form.models.includes(id)) {
        this.removeSelectedModel(id)
      } else {
        this.form.models.push(id)
        if (!this.form.model) {
          this.form.model = id
        }
      }
      this.checkNote = ''
    },
    connection() {
      return {
        service_code: this.isNew ? '' : this.form.service_code,
        provider: this.form.provider,
        base_url: this.form.base_url,
        api_key: this.form.api_key,
        version: this.isNew ? 0 : this.savedRow.version
      }
    },
    async run(fn, flagName = '') {
      if (this.busy) return
      this.busy = true
      if (flagName) this[flagName] = true
      this.error = ''
      try {
        await fn()
      } catch (e) {
        this.error = this.extractError(e, '模型服务操作失败')
        if (e && e.response && e.response.status === 409) {
          this.load()
        }
      } finally {
        this.busy = false
        if (flagName) this[flagName] = false
      }
    },
    load() {
      return this.run(async () => {
        const { data } = await getCrmAiConfig()
        this.applySettings(data.data, this.selectedCode)
      })
    },
    save() {
      if (!this.$refs.aiForm) return
      this.$refs.aiForm.validate(valid => {
        if (!valid) return
        const payload = {
          service_code: this.isNew ? '' : this.form.service_code,
          provider: this.form.provider,
          vendor_name: this.form.vendor_name || this.currentPresetName,
          base_url: this.form.base_url,
          model: this.form.model,
          models: this.form.models,
          available_models: this.catalogModels,
          enabled: !!this.form.enabled,
          is_default: !!this.form.is_default,
          version: this.isNew ? 0 : Number(this.savedRow.version || 0),
          api_key: this.form.api_key || ''
        }
        if (payload.api_key) {
          payload.encryption_password = this.form.encryption_password
        }
        this.run(async () => {
          const { data } = await saveCrmAiConfig(payload)
          this.applySettings(data.data, data.data && data.data.saved_service_code)
          this.$message.success('AI 厂商配置已保存')
        }, 'saving')
      })
    },
    listModels() {
      return this.run(async () => {
        this.manualMode = false
        this.catalogNote = ''
        this.checkNote = ''
        const { data } = await listCrmAiModels(this.connection())
        const { models, listing_supported } = data.data
        if (!listing_supported) {
          this.manualMode = true
          this.catalogModels = []
          this.catalogNote = '该厂商未提供模型列表接口，请在上方下拉框或输入框手工填写模型 ID。'
          return
        }
        this.catalogModels = Array.isArray(models) ? models : []
        if (!this.isNew && this.sameEndpoint) {
          this.savedRow.available_models = [...this.catalogModels]
          const idx = this.providers.findIndex(p => p.service_code === this.savedRow.service_code)
          if (idx >= 0) {
            this.$set(this.providers[idx], 'available_models', [...this.catalogModels])
          }
        }
        // 仅在尚未选中模型时默认勾选推荐模型，不清空用户当前已选模型
        if (!this.form.model && this.catalogModels.length > 0) {
          const presetDefault = this.currentPreset && this.currentPreset.default_model
          const matchedPreset = presetDefault && this.catalogModels.find(m => m.id === presetDefault)
          const picked = (matchedPreset && matchedPreset.id) || this.catalogModels[0].id
          this.form.model = picked
          if (!this.form.models.includes(picked)) {
            this.form.models.push(picked)
          }
        }
        this.catalogNote = this.catalogModels.length
          ? `已获取 ${this.catalogModels.length} 个模型，点击下方卡片可勾选加入。`
          : '连接成功，厂商未返回可用文本模型。'
      }, 'listingModels')
    },
    check() {
      return this.run(async () => {
        this.checkNote = ''
        const { data } = await checkCrmAiModel({ ...this.connection(), model: this.form.model })
        if (data && data.data) {
          const savedCode = this.form.service_code
          this.overviewState = data.data
          this.providers = Array.isArray(data.data.providers) ? data.data.providers : this.providers
          const refreshed = this.providers.find(p => p.service_code === savedCode)
          if (refreshed) {
            this.savedRow = {
              ...this.savedRow,
              last_check_status: refreshed.last_check_status,
              last_check_message: refreshed.last_check_message,
              last_checked_at: refreshed.last_checked_at
            }
          }
          this.$emit('state', data.data)
        }
        this.checkNote = `${this.form.model} 已通过 JSON 对话测试。`
        this.$message.success(this.checkNote)
      }, 'checkingModel')
    },
    handleSetDefault() {
      if (this.isNew || !this.form.service_code) return
      this.run(async () => {
        const { data } = await setDefaultCrmAi(this.form.service_code, { model: this.form.model })
        this.applySettings(data.data, this.form.service_code)
        this.$message.success('已切换为默认 AI 厂商')
      })
    },
    handleDelete() {
      if (this.isNew || !this.form.service_code) return
      const name = this.form.vendor_name || this.currentPresetName
      this.$confirm(`确认删除「${name}」配置及已保存的密钥吗？`, '删除确认', {
        type: 'warning',
        confirmButtonText: '确认删除',
        cancelButtonText: '取消'
      }).then(() => {
        this.run(async () => {
          const { data } = await deleteCrmAi(this.form.service_code)
          this.selectedCode = ''
          this.applySettings(data.data)
          this.$message.success('已删除该厂商配置')
        })
      }).catch(() => {})
    },
    openDecryptDialog() {
      if (!this.savedRow.has_secret_password) return
      this.decryptDialogVisible = true
    },
    decryptAiRequest(password) {
      return decryptCrmAiSecret({
        service_code: this.savedRow.service_code,
        password
      })
    },
    onDecrypted(secrets) {
      if (secrets && secrets.api_key) {
        this.revealedKey = secrets.api_key
      }
    },
    async copyRevealedKey() {
      if (!this.revealedKey) return
      const ok = await copyPlainText(this.revealedKey)
      if (ok) {
        this.$message.success('API Key 已复制到剪贴板')
      } else {
        this.$message.warning('自动复制受限，请直接选中文本复制')
      }
    },
    async copyInputKey() {
      if (!this.form.api_key) return
      const ok = await copyPlainText(this.form.api_key)
      if (ok) {
        this.$message.success('API Key 已复制到剪贴板')
      }
    }
  }
}
</script>

<style scoped>
.workspace-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 18px 22px 18px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  box-sizing: border-box;
}

.ai-overview-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  margin-bottom: 14px;
}

.ai-overview-left {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
}

.workspace-title {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.active-summary-tag {
  font-weight: 600;
}

.vendor-card-strip {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(205px, 1fr));
  gap: 10px;
  margin-bottom: 16px;
  padding: 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
}

.vendor-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  padding: 10px 12px;
  cursor: pointer;
  transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
}

.vendor-card:hover {
  border-color: #86efac;
}

.vendor-card.is-selected {
  border-color: #008b4b;
  background: #f0fdf4;
  box-shadow: 0 0 0 1px rgba(0, 139, 75, 0.15);
}

.vendor-card.is-draft {
  border-style: dashed;
}

.vendor-add-card {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 6px;
  min-height: 54px;
  border-style: dashed;
  border-color: #cbd5e1;
  color: #00763f;
  font-size: 13px;
  font-weight: 600;
  background: #ffffff;
}

.vendor-add-card:hover {
  border-color: #008b4b;
  background: #f0fdf4;
}

.vendor-card-top {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 6px;
}

.vendor-card-title-wrap {
  display: flex;
  align-items: center;
  gap: 6px;
  min-width: 0;
}

.status-dot {
  width: 7px;
  height: 7px;
  border-radius: 50%;
  flex-shrink: 0;
}

.status-dot.dot-active {
  background: #008b4b;
}

.status-dot.dot-idle {
  background: #cbd5e1;
}

.status-dot.dot-draft {
  background: #f59e0b;
}

.vendor-card-name {
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.vendor-card-badges {
  display: flex;
  align-items: center;
  gap: 4px;
  flex-shrink: 0;
}

.vendor-badge {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 999px;
}

.badge-default {
  background: #008b4b;
  color: #ffffff;
  font-weight: 600;
}

.badge-on {
  background: #dcfce7;
  color: #00763f;
}

.badge-off {
  background: #f1f5f9;
  color: #64748b;
}

.badge-draft {
  background: #fef3c7;
  color: #b45309;
}

.vendor-card-bottom {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
}

.vendor-model-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  color: #475569;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.vendor-model-count {
  font-size: 11px;
  color: #64748b;
  flex-shrink: 0;
}

.workspace-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 12px;
  padding-bottom: 14px;
  margin-bottom: 16px;
  border-bottom: 1px solid #e2e8f0;
}

.workspace-head-left {
  display: flex;
  flex-direction: column;
  gap: 5px;
  min-width: 0;
  flex: 1 1 280px;
}

.workspace-title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.vendor-workspace-title {
  margin: 0;
  font-size: 15px;
  font-weight: 700;
  color: #0f172a;
}

.rename-icon {
  font-size: 14px;
  color: #64748b;
  cursor: pointer;
}

.rename-icon:hover {
  color: #008b4b;
}

.inline-name-input {
  width: 200px;
}

.btn-reselect-vendor {
  color: #00763f !important;
  padding: 2px 4px;
}

.workspace-meta-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 12px;
  color: #64748b;
}

.meta-sep {
  color: #cbd5e1;
}

.workspace-head-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  flex: 0 1 auto;
}

.enable-switch-wrap {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 4px 10px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}

.enable-label {
  font-size: 12px;
  font-weight: 600;
  color: #475569;
}

.btn-check {
  background: #f0fdf4 !important;
  border: 1px solid #bbf7d0 !important;
  color: #00763f !important;
  font-weight: 500;
}

.btn-check:hover:not(.is-disabled) {
  background: #dcfce7 !important;
  border-color: #86efac !important;
  color: #006334 !important;
}

.btn-theme-save {
  background: #008b4b !important;
  border-color: #008b4b !important;
  color: #ffffff !important;
  font-weight: 500;
}

.btn-theme-save:hover:not(.is-disabled) {
  background: #00763f !important;
  border-color: #00763f !important;
}

.check-alert {
  margin-bottom: 14px;
}

.form-section {
  padding-bottom: 10px;
  margin-bottom: 16px;
  border-bottom: 1px solid #f1f5f9;
}

.form-section-last {
  padding-bottom: 0;
  margin-bottom: 0;
  border-bottom: 0;
}

.section-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 12px;
}

.section-title-group {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
}

.section-bar {
  width: 3px;
  height: 14px;
  border-radius: 2px;
  background: #008b4b;
  flex-shrink: 0;
}

.section-title-group h3 {
  margin: 0;
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
}

.current-model-badge {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-weight: 600;
}

.form-grid-2 {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 4px 18px;
}

.btn-rotate-inline {
  background: #f0fdf4 !important;
  border: 1px solid #bbf7d0 !important;
  color: #00763f !important;
}

.btn-rotate-inline:hover:not(.is-disabled) {
  background: #dcfce7 !important;
  border-color: #86efac !important;
}

.btn-cancel-inline {
  background: #ffffff !important;
  border: 1px solid #e2e8f0 !important;
  color: #475569 !important;
}

.secret-value-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 10px;
  min-height: 40px;
  padding: 6px 10px 6px 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  box-sizing: border-box;
}

.secret-value-bar.is-revealed {
  background: #f0fdf4;
  border-color: #86efac;
}

.secret-value-main {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  min-width: 0;
  flex: 1 1 220px;
}

.secret-code {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 13px;
  color: #0f172a;
  word-break: break-all;
  user-select: all;
}

.secret-empty-text {
  font-size: 13px;
  color: #94a3b8;
}

.secret-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 11px;
  font-weight: 600;
  padding: 1px 7px;
  border-radius: 999px;
  flex-shrink: 0;
}

.chip-encrypted {
  background: #dcfce7;
  color: #00763f;
}

.chip-revealed {
  background: #008b4b;
  color: #ffffff;
}

.chip-legacy {
  background: #fef3c7;
  color: #b45309;
}

.secret-value-actions {
  display: flex;
  align-items: center;
  gap: 6px;
  flex-shrink: 0;
}

.btn-secret-action {
  background: #f0fdf4 !important;
  border: 1px solid #bbf7d0 !important;
  color: #00763f !important;
  font-weight: 500;
}

.btn-secret-action:hover:not(.is-disabled) {
  background: #dcfce7 !important;
  border-color: #86efac !important;
}

.btn-secret-ghost {
  background: #ffffff !important;
  border: 1px solid #d1d5db !important;
  color: #475569 !important;
}

.field-hint-warn {
  margin-top: 5px;
  font-size: 12px;
  color: #b45309;
}

.plain-input-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.btn-inline-copy {
  background: #f0fdf4 !important;
  border: 1px solid #bbf7d0 !important;
  color: #00763f !important;
  flex-shrink: 0;
}

.probe-status-banner {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 8px 12px;
  margin-bottom: 12px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  font-size: 12px;
  color: #00763f;
}

.model-block {
  margin-top: 12px;
  padding: 12px;
  background: #f8fafc;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
}

.model-block-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 10px;
}

.model-block-title {
  font-size: 12px;
  font-weight: 700;
  color: #334155;
}

.model-block-tip {
  font-size: 12px;
  color: #64748b;
}

.catalog-filter-input {
  width: 220px;
}

.selected-model-tags {
  display: flex;
  flex-wrap: wrap;
  gap: 8px;
}

.selected-model-tag {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  padding: 5px 10px;
  background: #ffffff;
  border: 1px solid #cbd5e1;
  border-radius: 6px;
  font-size: 12px;
  color: #1e293b;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}

.selected-model-tag.is-clickable {
  cursor: pointer;
}

.selected-model-tag.is-clickable:hover {
  border-color: #008b4b;
}

.selected-model-tag.is-primary {
  background: #f0fdf4;
  border-color: #008b4b;
  color: #00763f;
}

.primary-dot {
  font-size: 10px;
  font-weight: 700;
  background: #008b4b;
  color: #ffffff;
  padding: 1px 5px;
  border-radius: 4px;
}

.selected-model-id {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
}

.remove-model-icon {
  font-size: 12px;
  color: #94a3b8;
  cursor: pointer;
}

.remove-model-icon:hover {
  color: #dc2626;
}

.model-mismatch-tip {
  display: flex;
  align-items: center;
  gap: 6px;
  padding: 7px 10px;
  margin-bottom: 10px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 6px;
  font-size: 12px;
  color: #92400e;
}

.catalog-grid {
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
  gap: 8px;
  max-height: 240px;
  overflow-y: auto;
}

.catalog-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 7px 10px;
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}

.catalog-item.is-clickable {
  cursor: pointer;
}

.catalog-item.is-clickable:hover {
  border-color: #86efac;
}

.catalog-item.is-picked {
  border-color: #86efac;
  background: #f8fffb;
}

.catalog-item.is-primary {
  border-color: #008b4b;
  background: #f0fdf4;
}

.catalog-item-main {
  display: flex;
  flex-direction: column;
  min-width: 0;
}

.catalog-item-id {
  font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace;
  font-size: 12px;
  color: #0f172a;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-item-name {
  font-size: 11px;
  color: #64748b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.catalog-badge {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 999px;
  flex-shrink: 0;
}

.badge-primary {
  background: #008b4b;
  color: #ffffff;
  font-weight: 600;
}

.badge-picked {
  background: #dcfce7;
  color: #00763f;
  font-weight: 600;
}

.model-empty-note {
  font-size: 12px;
  color: #94a3b8;
}

/* 添加厂商弹窗预设网格 */
.preset-section-label {
  font-size: 13px;
  font-weight: 600;
  color: #334155;
  margin-bottom: 10px;
}

.preset-grid {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 8px;
  margin-bottom: 16px;
}

.preset-card {
  padding: 9px 12px;
  border: 1px solid #e2e8f0;
  border-radius: 6px;
  background: #ffffff;
  cursor: pointer;
  transition: border-color 0.15s ease, background-color 0.15s ease;
}

.preset-card:hover {
  border-color: #86efac;
}

.preset-card.is-active {
  border-color: #008b4b;
  background: #f0fdf4;
}

.preset-card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 6px;
  margin-bottom: 3px;
}

.preset-card-name {
  font-size: 13px;
  font-weight: 700;
  color: #1e293b;
}

.preset-check-icon {
  color: #008b4b;
  font-weight: 700;
}

.preset-configured-tag {
  font-size: 11px;
  color: #64748b;
  background: #f1f5f9;
  padding: 0 6px;
  border-radius: 999px;
}

.preset-card-url {
  font-size: 11px;
  color: #64748b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

/* 品牌森林绿覆盖 */
.ai-workspace ::v-deep .el-form-item__label {
  font-weight: 600;
  color: #334155;
  padding-bottom: 4px;
  line-height: 1.4;
}

.ai-workspace ::v-deep .el-input__inner:focus,
.ai-workspace ::v-deep .el-select .el-input.is-focus .el-input__inner {
  border-color: #008b4b;
}

.ai-workspace ::v-deep .is-readonly-view .el-input.is-disabled .el-input__inner {
  background-color: #f8fafc;
  border-color: #e2e8f0;
  color: #334155;
  cursor: default;
}

@media (max-width: 780px) {
  .workspace-card {
    padding: 14px 14px 12px;
  }
  .form-grid-2,
  .preset-grid {
    grid-template-columns: 1fr;
  }
  .catalog-filter-input {
    width: 100%;
  }
}
</style>
