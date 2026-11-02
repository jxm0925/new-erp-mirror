Component({
  data: { actorName: '', today: '' },
  lifetimes: { attached() { this.refresh(); } },
  pageLifetimes: { show() { this.refresh(); } },
  methods: {
    refresh() {
      const user = wx.getStorageSync('erp_user') || {}; const now = new Date();
      this.setData({ actorName: user.nickname || user.name || user.username || '', today: [now.getFullYear(), String(now.getMonth() + 1).padStart(2, '0'), String(now.getDate()).padStart(2, '0')].join('-') });
    },
  },
});
