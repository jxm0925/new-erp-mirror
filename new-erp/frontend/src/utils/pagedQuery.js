export const createPageState = (perPage = 50) => ({
  rows: [], page: 0, perPage, total: 0, lastPage: 1, loading: false, sequence: 0, params: {}, stats: {}, warehouseStats: {}
})

export const hasNextPage = state => state.page > 0 && state.page < state.lastPage

export function invalidatePage (state) {
  state.sequence += 1
  state.loading = false
}

// 每次搜索拥有独立序号；旧响应不能覆盖新搜索，也不能推进新搜索的页码。
// 下一页只在用户滚动至末尾后请求，不在后台循环加载整个档案库。
export async function queryPage (state, request, params = {}, append = false) {
  if (append && (state.loading || !hasNextPage(state))) return null
  const sequence = ++state.sequence
  const page = append ? state.page + 1 : 1
  const query = append ? state.params : { ...params }
  state.loading = true
  if (!append) {
    state.params = query
    state.rows = []
    state.page = 0
    state.total = 0
  }
  try {
    const response = await request({ ...query, page, per_page: state.perPage })
    if (sequence !== state.sequence) return null
    const data = response.data
    const rows = data.data || []
    const merged = append ? [...state.rows, ...rows] : rows
    state.rows = Array.from(new Map(merged.map(row => [row.id, row])).values())
    state.total = Number(data.total || 0)
    state.page = Number(data.current_page || page)
    state.lastPage = Number(data.last_page || Math.max(1, Math.ceil(state.total / state.perPage)))
    if (data.stats) state.stats = data.stats
    if (data.warehouse_stats) state.warehouseStats = data.warehouse_stats
    return data
  } catch (error) {
    if (sequence === state.sequence) throw error
    return null
  } finally {
    if (sequence === state.sequence) state.loading = false
  }
}

export function includeSelected (rows, selected) {
  const list = Array.isArray(selected) ? selected.filter(Boolean) : selected ? [selected] : []
  return Array.from(new Map([...list, ...rows].map(row => [Number(row.id), row])).values())
}
