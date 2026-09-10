// custom-tab-bar/index.js
Component({
  data: {
    active: 0,
    list: [
      {
        pagePath: "pages/index/index",
        icon: "home-o",
        text: "首页"
      },
      {
        pagePath: "pages/mall/index/index",
        icon: "gift-o",
        text: "商城"
      },
      {
        pagePath: "pages/my/index/index",
        icon: "user-o",
        text: "我的"
      }
    ]
  },
  lifetimes: {
    attached() {
      this.updateActiveByRoute();
    }
  },
  pageLifetimes: {
    show() {
      this.updateActiveByRoute();
    }
  },
  methods: {
    updateActiveByRoute() {
      const pages = getCurrentPages();
      if (!pages || !pages.length) return;
      const currentPage = pages[pages.length - 1];
      if (!currentPage || !currentPage.route) return;
      const route = currentPage.route;
      const index = this.data.list.findIndex(item => item.pagePath === route);
      if (index !== -1 && index !== this.data.active) {
        this.setData({ active: index });
      }
    },
    select(event) {
      const index = Number(event.currentTarget.dataset.index);
      if (isNaN(index) || !this.data.list[index]) return;
      this.setData({ active: index });
      wx.switchTab({
        url: '/' + this.data.list[index].pagePath
      });
    }
  }
});
