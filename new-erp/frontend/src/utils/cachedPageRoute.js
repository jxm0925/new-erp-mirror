// App 的 KeepAlive 以 route.path 缓存页面；停用实例仍会收到全局 $route 更新。
// 每个实例只接受自身路径的查询参数变化，避免其他单据 ID 触发误加载、清空表单或跳转。
// 若缓存键策略改变，必须同步调整归属判断，不能直接恢复监听全局路由。
export default {
  data() {
    return { pageRoute: this.$route }
  },
  computed: {
    isPageRouteActive() {
      return this.$route.path === this.pageRoute.path
    }
  },
  watch: {
    $route(route) {
      if (route.path === this.pageRoute.path) this.pageRoute = route
    }
  }
}
