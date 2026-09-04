<template>
  <section class="auth-page">
    <div class="auth-shell">
      <div class="auth-visual">
        <img :src="visual" alt="ERP系统登录视觉" />
      </div>

      <div class="auth-form-pane">
        <div class="env-switch">
          <i class="el-icon-platform-eleme" />
          <span>当前环境：生产环境</span>
          <i class="el-icon-arrow-down" />
        </div>

        <el-form class="auth-form" autocomplete="off" @submit.native.prevent="submit">
          <h1>欢迎登录ERP系统</h1>
          <p>请使用您的账号登录系统</p>

          <el-form-item label="用户名">
            <el-input v-model="form.username" prefix-icon="el-icon-user" placeholder="请输入用户名" />
          </el-form-item>

          <el-form-item label="密码">
            <el-input v-model="form.password" type="password" prefix-icon="el-icon-lock" placeholder="请输入密码" show-password />
          </el-form-item>

          <div class="auth-options">
            <el-checkbox v-model="remember">记住我</el-checkbox>
            <span class="password-help">忘记密码请联系系统管理员</span>
          </div>

          <el-button class="login-button" type="success" :loading="loading" @click="submit">登 录</el-button>

          <div class="security-note">
            <i class="el-icon-lock" />
            <span>安全提示：为了保障您的账号安全，请勿将账号信息泄露给他人</span>
          </div>
        </el-form>
      </div>

      <div class="auth-capabilities">
        <div>
          <i class="el-icon-user" />
          <strong>统一账号</strong>
          <span>统一身份认证，一次登录即可访问所有ERP应用，安全便捷。</span>
        </div>
        <div>
          <i class="el-icon-shield" />
          <strong>RBAC权限</strong>
          <span>基于角色的精细化权限控制，精确到按钮级别，确保操作安全。</span>
        </div>
        <div>
          <i class="el-icon-office-building" />
          <strong>部门数据范围</strong>
          <span>支持按部门、个人或全局的数据范围控制，确保数据隔离与合规。</span>
        </div>
      </div>
    </div>

    <footer>© {{ currentYear }} ERP系统 版权所有　|　版本：v1.0.0　|　今天是 {{ todayText }}</footer>
  </section>
</template>

<script>
import { login } from '@/api/erp/auth'
import visual from '@/assets/erp-login-visual.png'

export default {
  data: () => ({
    visual,
    remember: false,
    loading: false,
    form: { username: '', password: '' }
  }),
  computed: {
    currentYear() { return new Date().getFullYear() },
    todayText() { return new Intl.DateTimeFormat('zh-CN', { year: 'numeric', month: '2-digit', day: '2-digit', weekday: 'long' }).format(new Date()) }
  },
  methods: {
    async submit() {
      if (!this.form.username || !this.form.password) return this.$message.error('请输入用户名和密码')
      this.loading = true
      try {
        const { data } = await login(this.form)
        localStorage.setItem('erp_token', data.token)
        localStorage.setItem('erp_user', JSON.stringify(data.user || {}))
        localStorage.setItem('erp_me', JSON.stringify({
          data_scope: data.data_scope,
          is_super_admin: !!data.is_super_admin,
          is_department_principal: !!data.is_department_principal
        }))
        localStorage.setItem('erp_permissions', JSON.stringify(data.permissions || []))
        this.$router.replace(this.$route.query.redirect || '/console')
      } catch (error) {
        this.$message.error(error.userMessage || '登录失败')
      } finally {
        this.loading = false
      }
    }
  }
}
</script>

<style scoped>
.password-help{color:#7b8794;font-size:13px}
.auth-page{box-sizing:border-box;width:100%;min-height:100vh;padding:24px;background:#f4f7fa;color:#0f1f33;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:12px}
.auth-shell{width:min(1320px,calc(100vw - 48px));height:calc(100vh - 82px);min-height:660px;max-height:860px;margin:0 auto;background:#fff;border-radius:8px;box-shadow:0 12px 36px rgba(15,31,51,.12);overflow:hidden;display:grid;grid-template-columns:47% 53%;grid-template-rows:minmax(500px,1fr) 150px}
.auth-visual{background:#06192c;overflow:hidden}
.auth-visual img{width:100%;height:100%;object-fit:cover;display:block}
.auth-form-pane{position:relative;background:#fff;display:flex;align-items:center;justify-content:center}
.env-switch{position:absolute;right:56px;top:30px;height:32px;display:flex;align-items:center;gap:8px;color:#2d3b4d;font-size:13px}
.env-switch i:first-child{color:#07883f;font-size:18px}
.auth-form{width:430px;margin-top:0}
.auth-form h1{margin:0 0 12px;text-align:center;font-size:28px;line-height:1.2;font-weight:700;color:#102033}
.auth-form p{margin:0 0 28px;text-align:center;color:#657184;font-size:16px}
.auth-form ::v-deep .el-form-item{margin-bottom:20px}
.auth-form ::v-deep .el-form-item__label{float:none;display:block;text-align:left;color:#15263b;font-size:14px;font-weight:600;line-height:22px;padding-bottom:8px}
.auth-form ::v-deep .el-input__inner{height:48px;border-color:#d8dee7;border-radius:4px;font-size:14px}
.auth-form ::v-deep .el-input--prefix .el-input__inner{padding-left:44px!important}
.auth-form ::v-deep .el-input__prefix{left:12px;width:22px;color:#6f7c8d;font-size:18px;z-index:1}
.auth-form ::v-deep .el-input__prefix .el-input__icon{line-height:48px}
.auth-options{display:flex;justify-content:space-between;align-items:center;margin:0 0 24px}
.auth-options ::v-deep .el-checkbox__input.is-checked .el-checkbox__inner{background:#07883f;border-color:#07883f}
.auth-options ::v-deep .el-checkbox__label,.auth-options .el-button{font-size:14px}
.auth-options .el-button{color:#07883f}
.login-button{width:100%;height:52px;font-size:18px;font-weight:700;border-radius:4px;background:#07883f;border-color:#07883f;box-shadow:0 8px 16px rgba(7,136,63,.18)}
.security-note{margin-top:20px;display:flex;justify-content:center;align-items:center;gap:8px;color:#687686;font-size:13px}
.security-note i{color:#60778d}
.auth-capabilities{grid-column:1/3;display:grid;grid-template-columns:repeat(3,1fr);border-top:1px solid #e6ebf0;background:#fff}
.auth-capabilities>div{display:grid;grid-template-columns:64px 1fr;grid-template-rows:auto auto;column-gap:16px;align-content:center;padding:0 52px;border-right:1px solid #dfe5eb}
.auth-capabilities>div:last-child{border-right:0}
.auth-capabilities i{grid-row:1/3;width:58px;height:58px;border-radius:50%;display:grid;place-items:center;background:#e8f6ef;color:#07883f;font-size:26px}
.auth-capabilities strong{margin-bottom:8px;font-size:18px;color:#102033}
.auth-capabilities span{color:#5d697a;font-size:14px;line-height:1.7}
footer{font-size:13px;color:#6f7b8b}
@media(max-width:1100px){.auth-shell{grid-template-columns:1fr;grid-template-rows:260px 1fr auto}.auth-capabilities{grid-column:1;grid-template-columns:1fr}.auth-capabilities>div{padding:20px 36px}.env-switch{right:36px}.auth-form{width:min(440px,calc(100% - 48px))}}
</style>
