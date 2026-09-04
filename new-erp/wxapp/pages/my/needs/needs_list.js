const util = require('../../../utils/util.js'); 
const api = require('../../../config/api.js'); 

Page({
  data: {
    list: [],
    keyword: '',
    loading: true,

    // 💥 新增：分页控制核心变量 💥
    page: 1,
    limit: 10,
    total: 0,
    hasMore: true
  },

  onShow() {
    // 💥 每次进入页面，强制重置页码并刷新最新状态
    this.resetAndFetch();
  },

  // 💥 1. 核心方法：重置并全新查询 (搜索、清空全靠它) 💥
  resetAndFetch() {
    this.setData({
      page: 1,
      list: [],
      hasMore: true
    }, () => {
      this.fetchList(false); // false 表示全新拉取，不是追加
    });
  },

  // 💥 2. 核心方法：触底加载下一页 💥
  // (如果你用的是 scroll-view，记得在 wxml 里加上 bindscrolltolower="loadMore")
  loadMore() {
    if (!this.data.hasMore) {
      return; // 到底了，不再发请求
    }
    this.setData({ page: this.data.page + 1 });
    this.fetchList(true); // true 表示追加数据
  },

  // 兼容原生页面的触底事件
  onReachBottom() {
    this.loadMore();
  },

  onSearchChange(e) {
    this.setData({ keyword: e.detail });
  },

  onSearch() {
    this.resetAndFetch(); // 💥 搜索必须重置页码
  },

  onClear() {
    this.setData({ keyword: '' });
    this.resetAndFetch(); // 💥 清空必须重置页码
  },

  // 💥 3. 真实请求：处理覆盖还是追加 💥
  fetchList(isLoadMore = false) {
    if (!isLoadMore) this.setData({ loading: true });
    else wx.showNavigationBarLoading(); // 追加数据时顶部转圈，体验更好

    const payload = {
      keyword: this.data.keyword,
      page: this.data.page,
      limit: this.data.limit
    };

    util.request(api.GetNeedsList, payload, 'GET').then(res => {
      if (!isLoadMore) this.setData({ loading: false });
      else wx.hideNavigationBarLoading();

      if (res.code === 1) {
        // 💥 解析后端返回的 list 和 total 💥
        let rawList = res.data.list || [];
        let totalCount = res.data.total || 0;

        this.setData({
          // 如果是下拉加载，就用 concat 拼在后面；否则直接覆盖
          list: isLoadMore ? this.data.list.concat(rawList) : rawList,
          total: totalCount,
          // 判断是否还有下一页
          hasMore: (isLoadMore ? this.data.list.length : 0) + rawList.length < totalCount
        });
      }
    }).catch(() => {
      if (!isLoadMore) this.setData({ loading: false });
      else wx.hideNavigationBarLoading();
    });
  },

  // 跳去“录入页”
  goToAdd() {
    wx.navigateTo({ url: '/pages/needs/needs' }); // 这里的路径以你实际的为主
  },

  // 点击卡片跳往“历史迭代时光机”页
  goToHistory(e) {
    console.log(e)
    const name = e.currentTarget.dataset.name;
    const id = e.currentTarget.dataset.id; // 拿到这单的唯一 ID
    wx.navigateTo({ 
      url: `/pages/my/needs/needs_history?id=${id}&name=${encodeURIComponent(name)}` 
    });
  }
});