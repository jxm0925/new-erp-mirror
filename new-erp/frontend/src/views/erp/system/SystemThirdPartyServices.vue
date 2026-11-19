<template>
  <div class="third-party-page" v-loading="loading">
    <!-- 顶部标准白底页头 -->
    <header class="page-head">
      <div class="head-left">
        <span class="head-icon"><i class="el-icon-connection" /></span>
        <div class="head-title-wrap">
          <div class="title-row">
            <h1 class="page-title">第三方服务配置</h1>
            <el-tag
              size="small"
              :type="serviceTagType"
              effect="plain"
              class="head-tag"
            >
              阿里云 OSS · {{ serviceStatusText }}
            </el-tag>
          </div>
          <div class="head-sub-row">
            <span class="sub-meta-text">当前已接入对象存储服务（阿里云 OSS），参数严格遵循阿里云 OSS 官方 SDK 规范</span>
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
    </header>

    <!-- 规则提示条 -->
    <div class="erp-page-tip">
      <i class="el-icon-info" />
      <span>AccessKey 密钥经后端 AES-256 加密存储，保存后永不回显明文。启用数据库配置后将即时接管系统 <code>oss</code> 存储磁盘；停用时自动回退至服务器环境变量配置。</span>
    </div>

    <!-- 顶部三卡运行状态概览 -->
    <section class="status-overview-grid">
      <div class="status-card">
        <div class="status-card-top">
          <span class="status-card-label">服务启用状态</span>
          <el-switch
            v-model="form.enabled"
            :disabled="!canManage"
            active-color="#008b4b"
            inactive-color="#cbd5e1"
          />
        </div>
        <div class="status-card-value">
          <strong :class="form.enabled ? 'text-success' : 'text-muted'">
            {{ form.enabled ? '已启用（使用本页配置）' : '未启用' }}
          </strong>
        </div>
        <div class="status-card-desc">
          当前实际生效来源：
          <span class="font-medium">{{ effectiveSourceLabel }}</span>
        </div>
      </div>

      <div class="status-card">
        <div class="status-card-top">
          <span class="status-card-label">最近连通性检测</span>
          <el-tag
            size="mini"
            :type="meta.last_check_status === 'ok' ? 'success' : (meta.last_check_status === 'failed' ? 'danger' : 'info')"
            effect="plain"
          >
            {{ meta.last_check_status === 'ok' ? '连接正常' : (meta.last_check_status === 'failed' ? '连接异常' : '尚未检测') }}
          </el-tag>
        </div>
        <div class="status-card-value status-msg" :title="meta.last_check_message || ''">
          {{ meta.last_check_message || '保存配置后可点击右上角「连通性检测」核验 Bucket 可达性' }}
        </div>
        <div class="status-card-desc">
          检测时间：{{ meta.last_checked_at || '—' }}
        </div>
      </div>

      <div class="status-card">
        <div class="status-card-top">
          <span class="status-card-label">访问凭证状态</span>
          <el-button
            v-if="canManage && meta.has_credentials"
            type="text"
            size="mini"
            icon="el-icon-key"
            class="action-link-theme"
            @click="openRotateDialog"
          >
            更换密钥
          </el-button>
        </div>
        <div class="status-card-value code-mono">
          {{ meta.has_credentials ? meta.access_key_id_masked : '尚未配置 AccessKey' }}
        </div>
        <div class="status-card-desc">
          最近更新：{{ meta.updated_by_name ? `${meta.updated_by_name} · ${meta.updated_at || ''}` : '暂无保存记录' }}
        </div>
      </div>
    </section>

    <!-- 实时检测结果提示 -->
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
      <!-- 分块 1：基础连接参数 -->
      <section class="config-card">
        <div class="card-section-head">
          <div class="section-title-group">
            <span class="section-bar" />
            <h2>基础连接参数</h2>
            <span class="section-sub">对应阿里云 OSS 官方 Region、Endpoint、Bucket 与签名版本配置</span>
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
            <div class="field-hint">使用 V4 签名时必填；切换预设地域会自动联动填充默认外网与内网 Endpoint。</div>
          </el-form-item>

          <el-form-item label="访问端点 Endpoint（外网）" prop="endpoint">
            <el-input
              v-model.trim="form.endpoint"
              placeholder="例如：oss-cn-qingdao.aliyuncs.com"
            />
            <div class="field-hint">填写不含协议头的 OSS 地域访问域名（协议由下方 HTTPS 开关统一控制）。</div>
          </el-form-item>

          <el-form-item label="存储空间 Bucket" prop="bucket">
            <el-input
              v-model.trim="form.bucket"
              placeholder="例如：sdjiantan-erp-prod"
            />
            <div class="field-hint">3~63 位小写字母、数字或短横线，且须以小写字母或数字开头和结尾。</div>
          </el-form-item>

          <el-form-item label="对象目录前缀 Prefix（选填）" prop="prefix">
            <el-input
              v-model.trim="form.prefix"
              placeholder="例如：erp/prod（留空表示写入 Bucket 根目录）"
            />
            <div class="field-hint">用于在同一 Bucket 下隔离不同环境或业务系统的存储目录，无需填写前导斜杠。</div>
          </el-form-item>

          <el-form-item label="内网端点 Internal Endpoint（选填）" prop="internal_endpoint">
            <el-input
              v-model.trim="form.internal_endpoint"
              placeholder="例如：oss-cn-qingdao-internal.aliyuncs.com"
            />
            <div class="field-hint">当 ERP 部署在同地域阿里云 ECS 时可填内网域名，降低上传与读取流量成本。</div>
          </el-form-item>

          <el-form-item label="签名版本 Signature Version" prop="signature_version">
            <el-radio-group v-model="form.signature_version" class="theme-radio-group">
              <el-radio label="v4">V4 签名（官方推荐）</el-radio>
              <el-radio label="v1">V1 签名（兼容旧环境）</el-radio>
            </el-radio-group>
            <div class="field-hint">阿里云 OSS 官方 SDK 默认推荐使用 V4 签名算法并配合 Region 校验。</div>
          </el-form-item>
        </div>
      </section>

      <!-- 分块 2：访问凭证 -->
      <section class="config-card">
        <div class="card-section-head">
          <div class="section-title-group">
            <span class="section-bar" />
            <h2>访问凭证（AccessKey）</h2>
            <span class="section-sub">建议使用具备最小 Bucket 读写权限的 RAM 子账号 AccessKey</span>
          </div>
          <el-button
            v-if="canManage && meta.has_credentials"
            size="mini"
            icon="el-icon-key"
            class="btn-rotate-inline"
            @click="openRotateDialog"
          >
            更换访问密钥
          </el-button>
        </div>

        <div v-if="meta.has_credentials" class="form-grid-2">
          <el-form-item label="AccessKey ID">
            <el-input
              :value="meta.access_key_id_masked"
              readonly
              class="readonly-secret-input"
            />
            <div class="field-hint">已脱敏展示；如需变更请点击右上角「更换访问密钥」。</div>
          </el-form-item>

          <el-form-item label="AccessKey Secret">
            <div class="secret-locked-box">
              <i class="el-icon-lock text-success" />
              <span>已使用 AES-256 加密保存（系统永不回显明文）</span>
            </div>
            <div class="field-hint">更换密钥支持先执行连通性校验再落库，避免误填导致业务附件中断。</div>
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
      </section>

      <!-- 分块 3：域名、安全访问与连接超时 -->
      <section class="config-card">
        <div class="card-section-head">
          <div class="section-title-group">
            <span class="section-bar" />
            <h2>域名、安全访问与连接超时</h2>
            <span class="section-sub">控制 HTTPS 协议、CNAME 自定义域名绑定、私有签名有效期及 SDK 网络超时</span>
          </div>
        </div>

        <div class="form-grid-2">
          <el-form-item label="启用 HTTPS (SSL)">
            <div class="switch-row">
              <el-switch
                v-model="form.use_ssl"
                active-color="#008b4b"
                inactive-color="#cbd5e1"
              />
              <span class="switch-desc">{{ form.use_ssl ? '强制通过 https:// 通信与生成访问链接（推荐）' : '使用 http:// 通信' }}</span>
            </div>
          </el-form-item>

          <el-form-item label="启用 CNAME 自定义域名">
            <div class="switch-row">
              <el-switch
                v-model="form.is_cname"
                active-color="#008b4b"
                inactive-color="#cbd5e1"
              />
              <span class="switch-desc">{{ form.is_cname ? '已开启：将通过下方自定义域名直接访问 OSS' : '未开启：使用默认 Endpoint 访问 OSS' }}</span>
            </div>
          </el-form-item>

          <el-form-item label="自定义访问域名 Domain" prop="domain">
            <el-input
              v-model.trim="form.domain"
              placeholder="例如：media.sdjiantan.com（开启 CNAME 时必填）"
            />
            <div class="field-hint">对应 Bucket 绑定的自定义域名或 CDN 加速域名，无需填写协议头。</div>
          </el-form-item>

          <el-form-item label="默认访问策略与签名有效期">
            <div class="visibility-row">
              <el-select v-model="form.visibility" style="flex: 1">
                <el-option label="私有读写（签名 URL 访问，推荐）" value="private" />
                <el-option label="公共读" value="public" />
              </el-select>
              <el-input-number
                v-if="form.visibility === 'private'"
                v-model="form.signed_url_ttl"
                :min="60"
                :max="86400"
                :step="300"
                controls-position="right"
                class="ttl-input"
              />
              <span v-if="form.visibility === 'private'" class="unit-suffix">秒</span>
            </div>
            <div class="field-hint">私有模式下临时签名链接默认有效 1800 秒（范围 60~86400 秒）。</div>
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
      </section>
    </el-form>

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
        <span>新密钥将以 AES-256 加密存储，保存后不再回显明文。建议点击「校验连通并更换」，确认新密钥可访问当前 Bucket 后再覆盖生效。</span>
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
    return {
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
        visibility: 'private',
        signed_url_ttl: 1800,
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
    serviceStatusText() {
      if (!this.meta.configured) return '尚未配置'
      return this.form.enabled ? '已启用' : '已保存（未启用）'
    },
    serviceTagType() {
      if (!this.meta.configured) return 'info'
      return this.form.enabled ? 'success' : 'warning'
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
        visibility: cfg.visibility || 'private',
        signed_url_ttl: Number(cfg.signed_url_ttl || 1800),
        timeout: Number(cfg.timeout || 60),
        connect_timeout: Number(cfg.connect_timeout || 10),
        max_retries: Number(cfg.max_retries != null ? cfg.max_retries : 3),
        access_key_id: '',
        access_key_secret: ''
      }
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
      if (!this.form.internal_endpoint || this.regions.some(r => r.internal_endpoint === this.form.internal_endpoint)) {
        this.form.internal_endpoint = matched.internal_endpoint
      }
    },
    buildSubmitPayload() {
      const payload = {
        enabled: !!this.form.enabled,
        region: this.form.region,
        endpoint: this.form.endpoint,
        internal_endpoint: this.form.internal_endpoint,
        bucket: this.form.bucket,
        prefix: this.form.prefix,
        is_cname: !!this.form.is_cname,
        use_ssl: !!this.form.use_ssl,
        domain: this.form.domain,
        signature_version: this.form.signature_version,
        visibility: this.form.visibility,
        signed_url_ttl: this.form.signed_url_ttl,
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
      this.$refs.ossForm.validate(async valid => {
        if (!valid) return
        this.saving = true
        try {
          const { data } = await saveAliyunOssConfig(this.buildSubmitPayload())
          this.applyPayload(data.data)
          this.$message.success(data.message || '阿里云 OSS 配置已保存')
        } catch (e) {
          this.$message.error(this.extractError(e, '保存配置失败'))
          if (e && e.response && e.response.status === 409) this.load()
        } finally {
          this.saving = false
        }
      })
    },
    handleCheckConnection() {
      this.$refs.ossForm.validate(async valid => {
        if (!valid) return
        this.checking = true
        this.liveCheck = null
        try {
          const { data } = await checkAliyunOssConnection(this.buildSubmitPayload())
          this.liveCheck = data.check || { ok: !!data.ok, message: data.message }
          if (data.data) this.applyPayload(data.data)
          if (this.liveCheck.ok) {
            this.$message.success(this.liveCheck.message)
          } else {
            this.$message.error(this.liveCheck.message)
          }
        } catch (e) {
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

.page-head {
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
  flex-wrap: wrap;
  gap: 14px;
  background: #ffffff;
  padding: 14px 20px;
  border-radius: 8px;
  border: 1px solid #e2e8f0;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.04);
  margin-bottom: 12px;
}

.head-left {
  display: flex;
  align-items: flex-start;
  gap: 12px;
  flex: 1 1 380px;
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
  gap: 4px;
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
  flex-wrap: wrap;
  flex: 0 1 auto;
  max-width: 100%;
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

.erp-page-tip {
  display: flex;
  align-items: center;
  gap: 8px;
  background: #f0fdf4;
  border: 1px solid #bbf7d0;
  border-radius: 6px;
  padding: 9px 14px;
  font-size: 12px;
  color: #166534;
  margin-bottom: 14px;
  line-height: 1.5;
}

.erp-page-tip code {
  background: #dcfce7;
  color: #006334;
  padding: 1px 5px;
  border-radius: 3px;
  font-size: 12px;
}

/* 三卡状态概览 */
.status-overview-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 14px;
  margin-bottom: 14px;
}

.status-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 14px 16px;
  box-shadow: 0 1px 2px rgba(0, 0, 0, 0.02);
  display: flex;
  flex-direction: column;
  gap: 6px;
  min-width: 0;
  box-sizing: border-box;
}

.status-card-top {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: 8px;
}

.status-card-label {
  font-size: 12px;
  font-weight: 600;
  color: #64748b;
}

.status-card-value {
  font-size: 14px;
  font-weight: 600;
  color: #1e293b;
  line-height: 1.4;
}

.status-card-value.status-msg {
  font-size: 13px;
  font-weight: 500;
  color: #334155;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.status-card-desc {
  font-size: 12px;
  color: #94a3b8;
  line-height: 1.4;
}

.check-alert {
  margin-bottom: 14px;
}

/* 配置卡片 */
.oss-config-form {
  display: flex;
  flex-direction: column;
  gap: 14px;
}

.config-card {
  background: #ffffff;
  border: 1px solid #e2e8f0;
  border-radius: 8px;
  padding: 16px 20px 8px;
  box-shadow: 0 1px 3px rgba(0, 0, 0, 0.02);
  box-sizing: border-box;
}

.card-section-head {
  display: flex;
  justify-content: space-between;
  align-items: center;
  flex-wrap: wrap;
  gap: 10px;
  padding-bottom: 12px;
  margin-bottom: 16px;
  border-bottom: 1px solid #f1f5f9;
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
  height: 15px;
  border-radius: 2px;
  background: #008b4b;
  flex-shrink: 0;
}

.section-title-group h2 {
  margin: 0;
  font-size: 15px;
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
  padding-top: 6px;
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

.visibility-row {
  display: flex;
  align-items: center;
  gap: 8px;
}

.ttl-input {
  width: 120px;
  flex-shrink: 0;
}

.unit-suffix {
  font-size: 12px;
  color: #64748b;
  flex-shrink: 0;
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

.text-muted {
  color: #94a3b8;
}

.font-medium {
  font-weight: 600;
  color: #334155;
}

.code-mono {
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Microsoft YaHei", sans-serif;
  font-variant-numeric: tabular-nums;
}

.action-link-theme {
  color: #008b4b !important;
  padding: 0 !important;
  font-weight: 500;
}

.action-link-theme:hover {
  color: #006334 !important;
}

/* 品牌森林绿覆盖，清除 Element UI 原生蓝 */
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

@media (max-width: 1180px) {
  .status-overview-grid {
    grid-template-columns: 1fr;
  }
}

@media (max-width: 780px) {
  .third-party-page {
    padding: 12px 10px 20px;
  }
  .form-grid-2,
  .form-grid-3 {
    grid-template-columns: 1fr;
  }
}
</style>
