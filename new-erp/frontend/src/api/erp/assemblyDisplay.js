import axios from 'axios'

const configuredBase = (process.env.VUE_APP_BASE_API || '/api').replace(/\/$/, '')
const apiBase = /\/api\/v1$/.test(configuredBase)
  ? configuredBase
  : `${configuredBase}/v1`

// 通知屏是独立访客入口，不读取令牌，也不复用会清会话并跳登录的后台拦截器。
const client = axios.create({ baseURL: `${apiBase}/erp/public/assembly-display`, timeout: 20000, withCredentials: false })
client.interceptors.response.use(response => response, error => {
  const body = error.response && error.response.data
  const message = body && (Object.values(body.errors || {}).flat().find(Boolean) || body.message)
  return Promise.reject(Object.assign(error, { userMessage: message || '加载失败，请稍后重试' }))
})

export const listAssemblyWorkOrders = params => client.get('/work-orders', { params })
export const getAssemblyWorkOrder = id => client.get(`/work-orders/${encodeURIComponent(id)}`)
export const getAssemblyHistory = id => client.get(`/work-orders/${encodeURIComponent(id)}/history`)
export const getAssemblyChange = id => client.get(`/changes/${encodeURIComponent(id)}`)

export function safeAssemblyFileUrl(value) {
  if (!value || typeof value !== 'string') return ''
  try {
    const base = new URL(`${configuredBase}/`, window.location.origin)
    const url = new URL(value, base)
    return ['http:', 'https:'].includes(url.protocol) && !url.username && !url.password ? url.href : ''
  } catch (error) {
    return ''
  }
}

export async function readAssemblyPdf(url) {
  const safeUrl = safeAssemblyFileUrl(url)
  if (!safeUrl) throw new Error('附件地址不可用')
  const { data } = await axios.get(safeUrl, { responseType: 'arraybuffer', timeout: 20000, withCredentials: false })
  return URL.createObjectURL(new Blob([data], { type: 'application/pdf' }))
}
