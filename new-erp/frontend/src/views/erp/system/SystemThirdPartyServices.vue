<template>
  <div class="third-party-page" v-loading="loading">
    <!-- 顶部标准白底页头 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-connection" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">第三方服务配置</h1>
            <el-tag size="small" :type="enabledServiceCount > 0 ? 'success' : 'info'" effect="plain">
              已启用 {{ enabledServiceCount }} 项
            </el-tag>
          </div>
          <div class="head-sub-row">
            <span>统一管理 ERP 外部云服务凭据与连接参数；密钥经 AES-256 加密存储，保存后不回显明文</span>
          </div>
        </div>
      </div>

      <div class="head-actions">
        <el-button
          size="small"
          icon="el-icon-refresh"
          class="btn-refresh"
          :loading="loading"
          @click="load"
        >
          刷新
        </el-button>
      </div>
    </header>

    <!-- 主体双栏：左侧服务目录 + 右侧配置工作区 -->
    <div class="service-layout">
      <!-- 左侧服务目录 -->
      <aside class="service-nav-card">
        <div class="nav-card-title">服务目录</div>

        <div
          v-for="group in serviceGroups"
          :key="group.key"
          class="nav-group"
        >
          <div class="nav-group-label">
            <i :class="group.icon" />
            <span>{{ group.label }}</span>
          </div>

          <div
            v-for="svc in group.items"
            :key="svc.code"
            class="nav-service-item"
            :class="{ active: activeService === svc.code }"
            @click="activeService = svc.code"
          >
            <div class="nav-item-main">
              <span class="status-dot" :class="svc.dotClass" />
              <span class="nav-item-name">{{ svc.name }}</span>
            </div>
            <span class="nav-item-badge" :class="svc.badgeClass">{{ svc.statusText }}</span>
          </div>
        </div>

        <div class="nav-card-foot">
          后续接入短信、AI 等第三方服务时，将按能力分类自动归入此目录。
        </div>
      </aside>

      <!-- 右侧配置工作区 -->
      <main class="service-workspace">
        <section v-if="activeService === 'aliyun_oss'" class="workspace-card">
          <!-- 服务顶部状态与操作栏 -->
          <div class="workspace-head">
            <div class="workspace-head-left">
              <div class="workspace-title-row">
                <h2 class="workspace-title">阿里云对象存储 (OSS)</h2>
                <el-tag size="small" :type="serviceTagType" effect="plain">
                  {{ serviceHeaderBadge }}
                </el-tag>
              </div>
              <div class="workspace-meta-row">
                <span>生效来源：<b>{{ effectiveSourceLabel }}</b></span>
                <span class="meta-sep">·</span>
                <span>最近检测：{{ meta.last_checked_at || '尚未检测' }}</span>
                <template v-if="meta.updated_by_name">
                  <span class="meta-sep">·</span>
                  <span>更新人：{{ meta.updated_by_name }}</span>
                </template>
              </div>
            </div>

            <div class="workspace-head-actions">
              <div class="enable-switch-wrap">
                <span class="enable-label">{{ form.enabled ? '已启用' : '未启用' }}</span>
                <el-switch
                  v-model="form.enabled"
                  :disabled="!canManage"
                  active-color="#008b4b"
                  inactive-color="#cbd5e1"
                />
              </div>
              <el-button
                v-if="canManage"
                size="small"
                icon="el-icon-link"
                class="btn-check"
                :loading="checking"
                :disabled="saving"
                @click="handleCheckConnection"
              >
                连通性检测
              </el-button>
              <el-button
                v-if="canManage"
                size="small"
                type="success"
                icon="el-icon-check"
                class="btn-theme-save"
                :loading="saving"
                :disabled="checking"
                @click="handleSave"
              >
                保存配置
              </el-button>
            </div>
          </div>

          <!-- 实时检测结果或最近检测摘要 -->
          <el-alert
            v-if="liveCheck"
            :title="liveCheck.ok ? '连通性检测通过' : '连通性检测失败'"
            :description="liveCheck.message"
            :type="liveCheck.ok ? 'success' : 'error'"
            show-icon
            closable
            class="check-alert"
            @close="liveCheck = null"
          />
          <div
            v-else-if="meta.last_check_status === 'failed' && meta.last_check_message"
            class="last-check-warn"
          >
            <i class="el-icon-warning-outline" />
            <span>上次检测异常（{{ meta.last_checked_at }}）：{{ meta.last_check_message }}</span>
          </div>

          <!-- 主配置表单 -->
          <el-form
            ref="ossForm"
            :model="form"
            :rules="rules"
            label-position="top"
            size="small"
            :disabled="!canManage"
            class="oss-config-form"
          >
            <!-- 分块 1：基础连接信息 -->
            <div class="form-section">
              <div class="section-head">
                <div class="section-title-group">
                  <span class="section-bar" />
                  <h3>基础连接信息</h3>
                  <span class="section-sub">配置存储空间与外网连接地址</span>
                </div>
              </div>

              <div class="form-grid-2">
                <el-form-item label="地域 Region" prop="region">
                  <el-select
                    v-model="form.region"
                    filterable
                    allow-create
                    default-first-option
                    placeholder="选择或输入官方地域 ID，如 cn-qingdao"
                    style="width: 100%"
                    @change="onRegionChange"
                  >
                    <el-option
                      v-for="item in regions"
                      :key="item.region"
                      :label="`${item.label} · ${item.region}`"
                      :value="item.region"
                    />
                  </el-select>
                  <div class="field-hint">切换预设地域将自动填充对应的外网 Endpoint；内网连接需在高级配置中手动开启。</div>
                </el-form-item>

                <el-form-item label="访问端点 Endpoint（外网）" prop="endpoint">
                  <el-input
                    v-model.trim="form.endpoint"
                    placeholder="例如：oss-cn-qingdao.aliyuncs.com"
                  />
                  <div class="field-hint">填写不含协议头的 OSS 地域域名，HTTPS 由高级配置中的开关统一控制。</div>
                </el-form-item>

                <el-form-item label="存储空间 Bucket" prop="bucket">
                  <el-input
                    v-model.trim="form.bucket"
                    placeholder="例如：sdjiantan-erp-prod"
                  />
                  <div class="field-hint">3~63 位小写字母、数字或短横线，且须以字母或数字开头结尾。</div>
                </el-form-item>
              </div>
            </div>

            <!-- 分块 2：访问凭证 -->
            <div class="form-section">
              <div class="section-head">
                <div class="section-title-group">
                  <span class="section-bar" />
                  <h3>访问凭证（AccessKey）</h3>
                  <span class="section-sub">建议使用具备最小 Bucket 读写权限的 RAM 子账号密钥</span>
                </div>
                <el-button
                  v-if="canManage && meta.has_credentials"
                  size="mini"
                  icon="el-icon-key"
                  class="btn-rotate-inline"
                  @click="openRotateDialog"
                >
                  更换密钥
                </el-button>
              </div>

              <div v-if="meta.has_credentials" class="form-grid-2">
                <el-form-item label="AccessKey ID">
                  <el-input
                    :value="meta.access_key_id_masked"
                    readonly
                    class="readonly-secret-input"
                  />
                  <div class="field-hint">已脱敏显示；如需更新请点击右侧「更换密钥」。</div>
                </el-form-item>

                <el-form-item label="AccessKey Secret">
                  <div class="secret-locked-box">
                    <i class="el-icon-lock text-success" />
                    <span>已加密保存（系统永不回显明文）</span>
                  </div>
                  <div class="field-hint">支持在更换密钥弹窗中先检测连通性再保存覆盖。</div>
                </el-form-item>
              </div>

              <div v-else class="form-grid-2">
                <el-form-item label="AccessKey ID" prop="access_key_id">
                  <el-input
                    v-model.trim="form.access_key_id"
                    placeholder="输入阿里云 RAM 用户的 AccessKey ID"
                    autocomplete="off"
                  />
                </el-form-item>

                <el-form-item label="AccessKey Secret" prop="access_key_secret">
                  <el-input
                    v-model.trim="form.access_key_secret"
                    type="password"
                    show-password
                    placeholder="输入对应的 AccessKey Secret"
                    autocomplete="new-password"
                  />
                </el-form-item>
              </div>
            </div>

            <!-- 分块 3：高级配置 -->
            <el-collapse ref="advancedConfig" v-model="advancedPanels" class="advanced-config">
              <el-collapse-item name="advanced">
                <div slot="title" class="section-title-group">
                  <span class="section-bar" />
                  <h3>高级配置</h3>
                  <span class="section-sub">目录前缀、内网连接、签名、域名与连接超时</span>
                </div>

                <div class="form-grid-2">
                  <el-form-item label="对象目录前缀 Prefix（选填）" prop="prefix">
                    <el-input
                      v-model.trim="form.prefix"
                      placeholder="例如：erp/prod（留空写入根目录）"
                    />
                    <div class="field-hint">用于同 Bucket 下隔离不同业务或环境目录，无需前导斜杠。</div>
                  </el-form-item>

                  <el-form-item label="启用内网连接">
                    <div class="switch-row">
                      <el-switch
                        v-model="useInternalEndpoint"
                        active-color="#008b4b"
                        inactive-color="#cbd5e1"
                        @change="onInternalEndpointChange"
                      />
                      <span class="switch-desc">{{ useInternalEndpoint ? '使用内网端点连接 OSS' : '使用外网端点连接 OSS' }}</span>
                    </div>
                    <div class="field-hint">仅当服务器位于同地域阿里云内网时开启。</div>
                  </el-form-item>

                  <el-form-item label="内网端点 Internal Endpoint" prop="internal_endpoint">
                    <el-input
                      v-model.trim="form.internal_endpoint"
                      :disabled="!useInternalEndpoint"
                      placeholder="例如：oss-cn-qingdao-internal.aliyuncs.com"
                    />
                  </el-form-item>

                  <el-form-item label="签名版本 Signature Version" prop="signature_version">
                    <el-radio-group v-model="form.signature_version" class="theme-radio-group">
                      <el-radio label="v4">V4 签名（官方推荐）</el-radio>
                      <el-radio label="v1">V1 签名（兼容旧环境）</el-radio>
                    </el-radio-group>
                    <div class="field-hint">阿里云 OSS 官方 SDK 默认推荐使用 V4 签名算法并校验 Region。</div>
                  </el-form-item>

                  <el-form-item label="启用 HTTPS (SSL)">
                    <div class="switch-row">
                      <el-switch
                        v-model="form.use_ssl"
                        active-color="#008b4b"
                        inactive-color="#cbd5e1"
                      />
                      <span class="switch-desc">{{ form.use_ssl ? '强制使用 https:// 通信与访问链接（推荐）' : '使用 http:// 通信' }}</span>
                    </div>
                  </el-form-item>

                  <el-form-item label="启用 CNAME 自定义域名">
                    <div class="switch-row">
                      <el-switch
                        v-model="form.is_cname"
                        active-color="#008b4b"
                        inactive-color="#cbd5e1"
                      />
                      <span class="switch-desc">{{ form.is_cname ? '通过下方自定义域名直接访问 OSS' : '使用默认 Endpoint 访问 OSS' }}</span>
                    </div>
                  </el-form-item>

                  <el-form-item label="自定义访问域名 Domain" prop="domain">
                    <el-input
                      v-model.trim="form.domain"
                      placeholder="例如：media.sdjiantan.com（开启 CNAME 时必填）"
                    />
                    <div class="field-hint">对应 Bucket 绑定的自定义域名或 CDN 域名，无需填写协议头。</div>
                  </el-form-item>
                </div>

                <div class="form-grid-3 timeout-grid">
                  <el-form-item label="请求超时 Timeout（秒）" prop="timeout">
                    <el-input-number
                      v-model="form.timeout"
                      :min="1"
                      :max="300"
                      controls-position="right"
                      style="width: 100%"
                    />
                  </el-form-item>

                  <el-form-item label="连接超时 Connect Timeout（秒）" prop="connect_timeout">
                    <el-input-number
                      v-model="form.connect_timeout"
                      :min="1"
                      :max="120"
                      controls-position="right"
                      style="width: 100%"
                    />
                  </el-form-item>

                  <el-form-item label="最大重试次数 Max Retries" prop="max_retries">
                    <el-input-number
                      v-model="form.max_retries"
                      :min="0"
                      :max="10"
                      controls-position="right"
                      style="width: 100%"
                    />
                  </el-form-item>
                </div>
              </el-collapse-item>
            </el-collapse>
          </el-form>
        </section>
      </main>
    </div>

    <!-- 居中遮罩弹窗：更换访问密钥 -->
    <el-dialog
      title="更换访问密钥（阿里云 OSS）"
      :visible.sync="rotate.visible"
      width="540px"
      top="8vh"
      custom-class="oss-secret-dialog"
      :close-on-click-modal="false"
      @closed="resetRotateForm"
    >
      <div class="dialog-warn-banner">
        <i class="el-icon-warning-outline" />
        <span>新密钥将以 AES-256 加密存储，保存后不再回显明文。建议点击「校验连通并更换」，确认新密钥可正常访问当前 Bucket 后再覆盖生效。</span>
      </div>

      <el-form
        ref="rotateForm"
        :model="rotate"
        :rules="rotateRules"
        label-position="top"
        size="small"
      >
        <el-form-item label="当前 AccessKey ID">
          <el-input :value="meta.access_key_id_masked" readonly class="readonly-secret-input" />
        </el-form-item>
        <el-form-item label="新 AccessKey ID" prop="access_key_id">
          <el-input
            v-model.trim="rotate.access_key_id"
            placeholder="请输入新的阿里云 AccessKey ID"
            autocomplete="off"
          />
        </el-form-item>
        <el-form-item label="新 AccessKey Secret" prop="access_key_secret">
          <el-input
            v-model.trim="rotate.access_key_secret"
            type="password"
            show-password
            placeholder="请输入对应的 AccessKey Secret"
            autocomplete="new-password"
          />
        </el-form-item>
      </el-form>

      <span slot="footer" class="dialog-footer">
        <el-button size="small" :disabled="rotate.saving" @click="rotate.visible = false">取消</el-button>
        <el-button
          size="small"
          class="btn-check"
          :loading="rotate.saving && rotate.verifyMode"
          :disabled="rotate.saving"
          @click="submitRotate(true)"
        >
          校验连通并更换
        </el-button>
        <el-button
          size="small"
          type="success"
          class="btn-theme-save"
          :loading="rotate.saving && !rotate.verifyMode"
          :disabled="rotate.saving"
          @click="submitRotate(false)"
        >
          直接更换
        </el-button>
      </span>
    </el-dialog>
  </div>
</template>

<script>
import {
  getAliyunOssConfig,
  saveAliyunOssConfig,
  rotateAliyunOssSecret,
  checkAliyunOssConnection
} from '../../../api/erp/thirdPartyService'

export default {
  name: 'SystemThirdPartyServices',
  data() {
    const validateDomain = (rule, value, callback) => {
      if (this.form.is_cname && !String(value || '').trim()) {
        return callback(new Error('启用 CNAME 自定义域名时必须填写自定义访问域名'))
      }
      callback()
    }
    const validateBucket = (rule, value, callback) => {
      const val = String(value || '').trim()
      if (!val) return callback(new Error('请填写存储空间 Bucket 名称'))
      if (!/^[a-z0-9][a-z0-9-]{1,61}[a-z0-9]$/.test(val)) {
        return callback(new Error('Bucket 须为 3~63 位小写字母、数字或短横线，且以字母或数字开头结尾'))
      }
      callback()
    }
    const validateInternalEndpoint = (rule, value, callback) => {
      if (this.useInternalEndpoint && !String(value || '').trim()) {
        return callback(new Error('启用内网连接时必须填写内网端点'))
      }
      callback()
    }
    return {
      activeService: 'aliyun_oss',
      advancedPanels: [],
      useInternalEndpoint: false,
      loading: false,
      saving: false,
      checking: false,
      liveCheck: null,
      regions: [],
      meta: {
        configured: false,
        effective_source: 'unconfigured',
        env_fallback_configured: false,
        has_credentials: false,
        access_key_id_masked: '',
        last_check_status: null,
        last_check_message: '',
        last_checked_at: '',
        business_version: 0,
        updated_by_name: '',
        updated_at: ''
      },
      form: {
        enabled: false,
        region: 'cn-qingdao',
        endpoint: 'oss-cn-qingdao.aliyuncs.com',
        internal_endpoint: '',
        bucket: '',
        prefix: '',
        is_cname: false,
        use_ssl: true,
        domain: '',
        signature_version: 'v4',
        timeout: 60,
        connect_timeout: 10,
        max_retries: 3,
        access_key_id: '',
        access_key_secret: ''
      },
      rules: {
        region: [{ required: true, message: '请选择或输入地域 Region', trigger: 'change' }],
        endpoint: [{ required: true, message: '请填写访问端点 Endpoint', trigger: 'blur' }],
        bucket: [{ required: true, validator: validateBucket, trigger: 'blur' }],
        internal_endpoint: [{ validator: validateInternalEndpoint, trigger: 'blur' }],
        domain: [{ validator: validateDomain, trigger: 'blur' }],
        access_key_id: [{ required: true, message: '首次配置请填写 AccessKey ID', trigger: 'blur' }],
        access_key_secret: [{ required: true, message: '首次配置请填写 AccessKey Secret', trigger: 'blur' }]
      },
      rotate: {
        visible: false,
        saving: false,
        verifyMode: false,
        access_key_id: '',
        access_key_secret: ''
      },
      rotateRules: {
        access_key_id: [
          { required: true, message: '请输入新的 AccessKey ID', trigger: 'blur' },
          { min: 8, message: 'AccessKey ID 长度不能少于 8 位', trigger: 'blur' }
        ],
        access_key_secret: [
          { required: true, message: '请输入新的 AccessKey Secret', trigger: 'blur' },
          { min: 8, message: 'AccessKey Secret 长度不能少于 8 位', trigger: 'blur' }
        ]
      }
    }
  },
  computed: {
    canManage() {
      return this.$can('system.third_party_service.manage')
    },
    enabledServiceCount() {
      return this.meta.configured && this.form.enabled ? 1 : 0
    },
    serviceGroups() {
      const ossEnabled = this.meta.configured && this.form.enabled
      const ossStatusText = !this.meta.configured ? '未配置' : (ossEnabled ? '已启用' : '未启用')
      return [
        {
          key: 'storage',
          label: '对象存储',
          icon: 'el-icon-cloudy',
          items: [
            {
              code: 'aliyun_oss',
              name: '阿里云 OSS',
              statusText: ossStatusText,
              dotClass: ossEnabled ? 'dot-active' : (this.meta.configured ? 'dot-paused' : 'dot-idle'),
              badgeClass: ossEnabled ? 'badge-active' : (this.meta.configured ? 'badge-paused' : 'badge-idle')
            }
          ]
        }
      ]
    },
    serviceHeaderBadge() {
      if (!this.meta.configured) return '尚未配置'
      if (!this.form.enabled) return '已保存 · 未启用'
      if (this.meta.last_check_status === 'ok') return '已启用 · 连接正常'
      if (this.meta.last_check_status === 'failed') return '已启用 · 连接异常'
      return '已启用'
    },
    serviceTagType() {
      if (!this.meta.configured) return 'info'
      if (!this.form.enabled) return 'warning'
      if (this.meta.last_check_status === 'failed') return 'danger'
      return 'success'
    },
    effectiveSourceLabel() {
      if (this.meta.effective_source === 'database') return '本页数据库配置'
      if (this.meta.effective_source === 'env_fallback') return '服务器环境变量 (.env)'
      return '尚未配置'
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
    applyPayload(data) {
      if (!data) return
      const cfg = data.config || {}
      const creds = data.credentials || {}
      this.regions = Array.isArray(data.regions) ? data.regions : this.regions
      this.meta = {
        configured: !!data.configured,
        effective_source: data.effective_source || 'unconfigured',
        env_fallback_configured: !!data.env_fallback_configured,
        has_credentials: !!creds.has_credentials,
        access_key_id_masked: creds.access_key_id_masked || '',
        last_check_status: data.last_check_status || null,
        last_check_message: data.last_check_message || '',
        last_checked_at: data.last_checked_at || '',
        business_version: Number(data.business_version || 0),
        updated_by_name: data.updated_by_name || '',
        updated_at: data.updated_at || ''
      }
      this.form = {
        enabled: !!data.enabled,
        region: cfg.region || 'cn-qingdao',
        endpoint: cfg.endpoint || '',
        internal_endpoint: cfg.internal_endpoint || '',
        bucket: cfg.bucket || '',
        prefix: cfg.prefix || '',
        is_cname: !!cfg.is_cname,
        use_ssl: cfg.use_ssl !== false,
        domain: cfg.domain || '',
        signature_version: cfg.signature_version || 'v4',
        timeout: Number(cfg.timeout || 60),
        connect_timeout: Number(cfg.connect_timeout || 10),
        max_retries: Number(cfg.max_retries != null ? cfg.max_retries : 3),
        access_key_id: '',
        access_key_secret: ''
      }
      this.useInternalEndpoint = !!this.form.internal_endpoint.trim()
      this.$nextTick(() => {
        if (this.$refs.ossForm) this.$refs.ossForm.clearValidate()
      })
    },
    async load() {
      this.loading = true
      try {
        const { data } = await getAliyunOssConfig()
        this.applyPayload(data.data)
      } catch (e) {
        this.$message.error(this.extractError(e, '加载阿里云 OSS 配置失败'))
      } finally {
        this.loading = false
      }
    },
    onRegionChange(regionCode) {
      const matched = this.regions.find(item => item.region === regionCode)
      if (!matched) return
      this.form.endpoint = matched.endpoint
      if (this.useInternalEndpoint && (!this.form.internal_endpoint || this.regions.some(r => r.internal_endpoint === this.form.internal_endpoint))) {
        this.form.internal_endpoint = matched.internal_endpoint
      }
    },
    onInternalEndpointChange(enabled) {
      if (enabled && (!this.form.internal_endpoint || this.regions.some(r => r.internal_endpoint === this.form.internal_endpoint))) {
        const matched = this.regions.find(item => item.region === this.form.region)
        if (matched) this.form.internal_endpoint = matched.internal_endpoint
      }
      this.$refs.ossForm.clearValidate('internal_endpoint')
    },
    revealAdvancedErrors(errors) {
      const advancedFields = ['prefix', 'internal_endpoint', 'signature_version', 'use_ssl', 'is_cname', 'domain', 'timeout', 'connect_timeout', 'max_retries']
      if (!Object.keys(errors || {}).some(field => advancedFields.includes(field))) return
      this.advancedPanels = ['advanced']
      this.$nextTick(() => {
        const section = this.$refs.advancedConfig.$el
        const target = section.querySelector('.el-form-item.is-error') || section
        target.scrollIntoView({ behavior: 'smooth', block: 'center' })
      })
    },
    buildSubmitPayload() {
      const payload = {
        enabled: !!this.form.enabled,
        region: this.form.region,
        endpoint: this.form.endpoint,
        internal_endpoint: this.useInternalEndpoint ? this.form.internal_endpoint : '',
        bucket: this.form.bucket,
        prefix: this.form.prefix,
        is_cname: !!this.form.is_cname,
        use_ssl: !!this.form.use_ssl,
        domain: this.form.domain,
        signature_version: this.form.signature_version,
        timeout: this.form.timeout,
        connect_timeout: this.form.connect_timeout,
        max_retries: this.form.max_retries
      }
      if (this.meta.business_version > 0) {
        payload.expected_version = this.meta.business_version
      }
      if (!this.meta.has_credentials) {
        payload.access_key_id = this.form.access_key_id
        payload.access_key_secret = this.form.access_key_secret
      }
      return payload
    },
    handleSave() {
      this.$refs.ossForm.validate(async (valid, errors) => {
        if (!valid) {
          this.revealAdvancedErrors(errors)
          return
        }
        this.saving = true
        try {
          const { data } = await saveAliyunOssConfig(this.buildSubmitPayload())
          this.applyPayload(data.data)
          this.$message.success(data.message || '阿里云 OSS 配置已保存')
        } catch (e) {
          this.revealAdvancedErrors(e && e.response && e.response.data && e.response.data.errors)
          this.$message.error(this.extractError(e, '保存配置失败'))
          if (e && e.response && e.response.status === 409) this.load()
        } finally {
          this.saving = false
        }
      })
    },
    handleCheckConnection() {
      this.$refs.ossForm.validate(async (valid, errors) => {
        if (!valid) {
          this.revealAdvancedErrors(errors)
          return
        }
        this.checking = true
        this.liveCheck = null
        try {
          const { data } = await checkAliyunOssConnection(this.buildSubmitPayload())
          this.liveCheck = data.check || { ok: !!data.ok, message: data.message }
          // 检测不保存配置，不能用响应中的已保存值覆盖当前编辑。
          if (data.data) {
            this.meta.last_check_status = data.data.last_check_status || null
            this.meta.last_check_message = data.data.last_check_message || ''
            this.meta.last_checked_at = data.data.last_checked_at || ''
          }
          if (this.liveCheck.ok) {
            this.$message.success(this.liveCheck.message)
          } else {
            this.$message.error(this.liveCheck.message)
          }
        } catch (e) {
          this.revealAdvancedErrors(e && e.response && e.response.data && e.response.data.errors)
          const msg = this.extractError(e, '连通性检测失败')
          this.liveCheck = { ok: false, message: msg }
          this.$message.error(msg)
        } finally {
          this.checking = false
        }
      })
    },
    openRotateDialog() {
      this.rotate.visible = true
    },
    resetRotateForm() {
      this.rotate.access_key_id = ''
      this.rotate.access_key_secret = ''
      this.rotate.verifyMode = false
      if (this.$refs.rotateForm) this.$refs.rotateForm.clearValidate()
    },
    submitRotate(verifyBeforeSave) {
      this.$refs.rotateForm.validate(async valid => {
        if (!valid) return
        this.rotate.saving = true
        this.rotate.verifyMode = verifyBeforeSave
        try {
          const payload = {
            access_key_id: this.rotate.access_key_id,
            access_key_secret: this.rotate.access_key_secret,
            verify_before_save: verifyBeforeSave
          }
          if (this.meta.business_version > 0) {
            payload.expected_version = this.meta.business_version
          }
          const { data } = await rotateAliyunOssSecret(payload)
          if (data.data) this.applyPayload(data.data)
          if (data.check) this.liveCheck = data.check
          this.$message.success(data.message || '访问密钥已更新')
          this.rotate.visible = false
        } catch (e) {
          this.$message.error(this.extractError(e, '更换密钥失败'))
          if (e && e.response && e.response.status === 409) {
            this.rotate.visible = false
            this.load()
          }
        } finally {
          this.rotate.saving = false
        }
      })
    }
  }
}
</script>

<style scoped>
.third-party-page {
  position: relative;
  width: 100%;
  max-width: 100%;
  min-width: 0;
  padding: 16px 20px 28px;
  background: #f8fafc;
  min-height: calc(100vh - 90px);
  box-sizing: border-box;
  overflow-x: clip;
}

/* 顶部标准白底页头 */
.page-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 14px;
  background: #ffffff;
  padding: 14px 20px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  margin-bottom: 14px;
}

.head-left {
  display: flex;
  align-items: center;
  gap: 12px;
  flex: 1 1 360px;
  min-width: 0;
}

.head-icon {
  width: 38px;
  height: 38px;
  background: linear-gradient(135deg, #e8f5e9 0%, #c8e6c9 100%);
  color: #008b4b;
  border-radius: 8px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 20px;
  flex-shrink: 0;
}

.head-title-wrap {
  display: flex;
  flex-direction: column;
  gap: 3px;
  flex: 1;
  min-width: 0;
}

.title-row {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-wrap: wrap;
}

.page-title {
  margin: 0;
  font-size: 18px;
  font-weight: 700;
  color: #1e293b;
  line-height: 1.3;
}

.head-sub-row {
  font-size: 12px;
  color: #64748b;
  line-height: 1.4;
}

.head-actions {
  display: flex;
  align-items: center;
  gap: 8px;
  flex-shrink: 0;
}

/* 主体左右布局：左侧服务目录 + 右侧配置工作区 */
.service-layout {
  display: grid;
  grid-template-columns: 232px minmax(0, 1fr);
  gap: 16px;
  align-items: start;
}

/* 左侧服务目录卡片 */
.service-nav-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  box-sizing: border-box;
  min-width: 0;
}

.nav-card-title {
  font-size: 14px;
  font-weight: 700;
  color: #1e293b;
  padding: 0 6px 10px;
  margin-bottom: 10px;
  border-bottom: 1px solid #f1f5f9;
}

.nav-group {
  margin-bottom: 12px;
}

.nav-group-label {
  display: flex;
  align-items: center;
  gap: 6px;
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
  padding: 4px 8px;
  margin-bottom: 4px;
}

.nav-service-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: 8px;
  padding: 9px 10px;
  border-radius: 6px;
  cursor: pointer;
  border: 1px solid transparent;
  transition: background-color 0.15s ease, border-color 0.15s ease;
}

.nav-service-item:hover {
  background: #f8fafc;
}

.nav-service-item.active {
  background: #f0fdf4;
  border-color: #bbf7d0;
}

.nav-item-main {
  display: flex;
  align-items: center;
  gap: 7px;
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

.status-dot.dot-paused {
  background: #f59e0b;
}

.status-dot.dot-idle {
  background: #cbd5e1;
}

.nav-item-name {
  font-size: 13px;
  font-weight: 600;
  color: #1e293b;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.nav-service-item.active .nav-item-name {
  color: #00763f;
}

.nav-item-badge {
  font-size: 11px;
  padding: 1px 6px;
  border-radius: 999px;
  flex-shrink: 0;
}

.nav-item-badge.badge-active {
  background: #dcfce7;
  color: #00763f;
  font-weight: 600;
}

.nav-item-badge.badge-paused {
  background: #fef3c7;
  color: #b45309;
}

.nav-item-badge.badge-idle {
  background: #f1f5f9;
  color: #64748b;
}

.nav-card-foot {
  margin-top: 10px;
  padding: 10px 8px 2px;
  border-top: 1px dashed #e2e8f0;
  font-size: 12px;
  color: #94a3b8;
  line-height: 1.5;
}

/* 右侧工作区 */
.service-workspace {
  min-width: 0;
}

.workspace-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 18px 22px 12px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  box-sizing: border-box;
}

.workspace-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 14px;
  padding-bottom: 16px;
  margin-bottom: 18px;
  border-bottom: 1px solid #e2e8f0;
}

.workspace-head-left {
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
  flex: 1 1 320px;
}

.workspace-title-row {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
}

.workspace-title {
  margin: 0;
  font-size: 16px;
  font-weight: 700;
  color: #0f172a;
}

.workspace-meta-row {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  font-size: 12px;
  color: #64748b;
}

.workspace-meta-row b {
  color: #334155;
  font-weight: 600;
}

.meta-sep {
  color: #cbd5e1;
}

.workspace-head-actions {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  flex: 0 1 auto;
  max-width: 100%;
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

.btn-refresh {
  background: #ffffff !important;
  border: 1px solid #dcdfe6 !important;
  color: #475569 !important;
}

.btn-refresh:hover {
  color: #008b4b !important;
  border-color: #bbf7d0 !important;
  background: #f0fdf4 !important;
}

.btn-check {
  background: #f0fdf4 !important;
  border: 1px solid #bbf7d0 !important;
  color: #00763f !important;
  font-weight: 500;
}

.btn-check:hover {
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

.btn-theme-save:hover {
  background: #00763f !important;
  border-color: #00763f !important;
}

.check-alert {
  margin-bottom: 16px;
}

.last-check-warn {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 9px 12px;
  background: #fef2f2;
  border: 1px solid #fecaca;
  border-radius: 6px;
  color: #b91c1c;
  font-size: 12px;
  margin-bottom: 16px;
  line-height: 1.5;
}

/* 表单分块 */
.form-section {
  padding-bottom: 10px;
  margin-bottom: 18px;
  border-bottom: 1px solid #f1f5f9;
}

.section-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 8px;
  margin-bottom: 14px;
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

.section-sub {
  font-size: 12px;
  color: #94a3b8;
}

.form-grid-2 {
  display: grid;
  grid-template-columns: repeat(2, minmax(0, 1fr));
  gap: 4px 20px;
}

.form-grid-3 {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 4px 20px;
}

.timeout-grid {
  padding-top: 8px;
  border-top: 1px dashed #eef2f6;
}

.field-hint {
  margin-top: 4px;
  font-size: 12px;
  color: #94a3b8;
  line-height: 1.45;
}

.secret-locked-box {
  height: 32px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 12px;
  background: #f1f5f9;
  border: 1px solid #e2e8f0;
  border-radius: 4px;
  color: #475569;
  font-size: 13px;
  box-sizing: border-box;
}

.btn-rotate-inline {
  background: #f0fdf4 !important;
  border: 1px solid #bbf7d0 !important;
  color: #00763f !important;
}

.btn-rotate-inline:hover {
  background: #dcfce7 !important;
  border-color: #86efac !important;
}

.switch-row {
  display: flex;
  align-items: center;
  gap: 10px;
  min-height: 32px;
  flex-wrap: wrap;
}

.switch-desc {
  font-size: 12px;
  color: #475569;
}

.dialog-warn-banner {
  display: flex;
  align-items: flex-start;
  gap: 8px;
  padding: 10px 12px;
  background: #fffbeb;
  border: 1px solid #fde68a;
  border-radius: 6px;
  color: #92400e;
  font-size: 12px;
  line-height: 1.5;
  margin-bottom: 14px;
}

.dialog-warn-banner i {
  font-size: 15px;
  color: #d97706;
  margin-top: 1px;
}

.text-success {
  color: #008b4b;
}

/* 品牌森林绿覆盖，清除 Element UI 原生蓝 */
.advanced-config {
  border: 0;
}

.advanced-config ::v-deep .el-collapse-item__header {
  min-height: 44px;
  height: auto;
  line-height: 1.4;
  border-bottom: 0;
}

.advanced-config ::v-deep .el-collapse-item__wrap {
  border-bottom: 0;
}

.advanced-config ::v-deep .el-collapse-item__content {
  padding-bottom: 0;
}

.third-party-page ::v-deep .el-input__inner:focus,
.third-party-page ::v-deep .el-textarea__inner:focus,
.third-party-page ::v-deep .el-select .el-input.is-focus .el-input__inner {
  border-color: #008b4b;
}

.third-party-page ::v-deep .readonly-secret-input .el-input__inner {
  background-color: #f1f5f9;
  color: #334155;
}

.third-party-page ::v-deep .theme-radio-group .el-radio__input.is-checked .el-radio__inner {
  border-color: #008b4b;
  background: #008b4b;
}

.third-party-page ::v-deep .theme-radio-group .el-radio__input.is-checked + .el-radio__label {
  color: #008b4b;
  font-weight: 600;
}

.third-party-page ::v-deep .el-form-item__label {
  font-weight: 600;
  color: #334155;
  padding-bottom: 4px;
  line-height: 1.4;
}

@media (max-width: 1024px) {
  .service-layout {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 780px) {
  .third-party-page {
    padding: 12px 10px 20px;
  }
  .workspace-card {
    padding: 14px 14px 10px;
  }
  .form-grid-2,
  .form-grid-3 {
    grid-template-columns: 1fr;
  }
}
</style>
