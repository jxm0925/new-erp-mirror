import axios from 'axios'

// 与 training 项目一致：前端直连后端固定 API 入口，不使用 Vue dev-server 代理。
// VUE_APP_BASE_API 是唯一配置来源，避免迁移目录后仍误连旧 Nginx 站点。
//
// 所有 ERP 接口客户端都必须由这里创建：此前各模块各自 axios.create，只有主数据客户端
// 处理 401，生产/下料页面在令牌过期后停在原页反复报错而不回登录页。
// 统一后各客户端只保留超时和兜底提示的差异，令牌、401 清理和错误文案提取只有一处实现。
const SESSION_KEYS = ['erp_token', 'erp_user', 'erp_me', 'erp_permissions', 'erp_menus']

function firstValidationMessage(errors) {
  if (!errors) return ''
  return Object.values(errors).flat().find(Boolean) || ''
}

export function createErpApi({ timeout = 20000, fallbackMessage = '请求失败，请稍后重试' } = {}) {
  const api = axios.create({ baseURL: process.env.VUE_APP_BASE_API, timeout })
  api.interceptors.request.use(config => {
    const token = localStorage.getItem('erp_token')
    if (token) config.headers.Authorization = `Bearer ${token}`
    return config
  })
  api.interceptors.response.use(response => response, error => {
    const body = error.response?.data
    if (error.response?.status === 401) {
      SESSION_KEYS.forEach(key => localStorage.removeItem(key))
      try { sessionStorage.removeItem('erp_visited_views') } catch (e) { /* 存储不可用时忽略 */ }
      if (window.location.hash !== '#/login') window.location.hash = '#/login'
    }
    // 生产/销售接口的校验失败统一返回“请求参数校验失败。”，具体字段原因在 errors 中，优先展示。
    const message = firstValidationMessage(body?.errors) || body?.message || fallbackMessage
    return Promise.reject(Object.assign(error, { userMessage: message, errorCode: body?.error_code }))
  })
  return api
}
