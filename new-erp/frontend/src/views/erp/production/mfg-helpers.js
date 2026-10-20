// 生产 v2 页面共用的小工具：状态颜色、数量显示、负责人目录。
import { listUsers } from '../../../api/erp/rbac'

export const STATUS_CHIP = { planned: 'c-n', released: 'c-g', in_progress: 'c-o', paused: 'c-n', completed: 'c-g', closed: 'c-n', cancelled: 'c-r' }
export const HERO_PILL = { planned: 'p-n', released: 'p-b', in_progress: 'p-y', paused: 'p-n', completed: 'p-g', closed: 'p-n', cancelled: 'p-r' }
export const STATUS_TABS = [
  { value: '', label: '全部' }, { value: 'planned', label: '计划' }, { value: 'released', label: '已下达' },
  { value: 'in_progress', label: '生产中' }, { value: 'paused', label: '暂停' }, { value: 'completed', label: '已完工' },
  { value: 'closed', label: '已关闭' }, { value: 'cancelled', label: '已取消' }
]

export function qty(value) {
  const n = Number(value || 0)
  return Number.isInteger(n) ? String(n) : String(Number(n.toFixed(4)))
}

export function money(value) {
  if (value === null || value === undefined || value === '') return '-'
  return '¥' + Number(value).toLocaleString('zh-CN', { minimumFractionDigits: 0, maximumFractionDigits: 2 })
}

export function shortDate(value) {
  return value ? String(value).slice(5, 10) : '-'
}

export function bomLabel(name, number, version) {
  const rawVersion = version === null || version === undefined ? '' : String(version)
  const numericVersion = rawVersion.match(/^v?(\d+(?:\.\d+)*)$/i)
  const displayVersion = numericVersion ? (/[1-9]/.test(numericVersion[1]) ? `V${numericVersion[1]}` : '') : rawVersion
  return [name, number, displayVersion].filter(value => value !== null && value !== undefined && value !== '').join(' · ')
}

export function errorText(e, fallback) {
  return (e && e.userMessage) || fallback
}

export function isCancel(e) {
  return e === 'cancel' || e === 'close'
}

export async function searchProductionUsers(keyword) {
  const { data } = await listUsers({ scope: 'production', status: 'normal', keyword: keyword || undefined, page: 1, per_page: 20 })
  return (data.data || data || []).map(u => ({ id: u.legacy_id || u.user_id, name: u.nickname || u.display_name || u.username }))
}
