// 为现有表格、列表和 Element 下拉框接入滚动分页，不新增 DOM、按钮或样式。
// 下拉菜单会被挂到 body，必须监听真实弹层，不能监听选择框本身。
const KEY = '__erpPagedScroll'

function refresh (el, vnode) {
  const state = el[KEY]
  if (!state) return
  const vm = vnode.componentInstance
  const isSelect = vm && vm.$options.name === 'ElSelect'
  if (isSelect && !vm.visible) {
    state.observer.disconnect()
    state.target = null
    return
  }
  const root = isSelect ? vm.$refs.popper && vm.$refs.popper.$el : el
  if (!root) return
  const rows = root.querySelectorAll(isSelect ? '.el-select-dropdown__item' : '.el-table__body-wrapper tbody > tr')
  const last = rows.length ? rows[rows.length - 1] : root.lastElementChild
  // 同一末行保持观察，失败后等下一次真实滚动再重试，避免响应失败触发无休止重连。
  if (last === state.target) return
  state.observer.disconnect()
  state.target = last
  if (last) state.observer.observe(last)
}

export default {
  inserted (el, binding, vnode) {
    const state = { load: binding.value, pending: false, unwatch: null, observer: null, target: null }
    state.observer = new IntersectionObserver(async entries => {
      if (state.pending || !entries.some(entry => entry.target === state.target && entry.isIntersecting && entry.target.getClientRects().length)) return
      state.pending = true
      try { await state.load() } finally { state.pending = false }
    }, { threshold: 0.1 })
    el[KEY] = state
    const vm = vnode.componentInstance
    if (vm && vm.$options.name === 'ElSelect') {
      state.unwatch = vm.$watch('visible', () => vm.$nextTick(() => refresh(el, vnode)))
    }
    vnode.context.$nextTick(() => refresh(el, vnode))
  },
  componentUpdated (el, binding, vnode) {
    if (el[KEY]) el[KEY].load = binding.value
    vnode.context.$nextTick(() => refresh(el, vnode))
  },
  unbind (el) {
    const state = el[KEY]
    if (!state) return
    state.observer.disconnect()
    if (state.unwatch) state.unwatch()
    delete el[KEY]
  }
}
