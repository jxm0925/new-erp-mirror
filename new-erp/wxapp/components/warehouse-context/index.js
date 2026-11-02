Component({
  data: { actorName: '', actorInitial: '仓', today: '' },
  lifetimes: { attached() { this.refresh(); } },
  pageLifetimes: { show() { this.refresh(); } },
  methods: {
    refresh() {
      const user = wx.getStorageSync('erp_user') || {};
      const now = new Date();
      const actorName = user.nickname || user.name || user.username || '仓管员';
      this.setData({
        actorName,
        actorInitial: String(actorName).trim().slice(0, 1) || '仓',
        today: [
          now.getFullYear(),
          String(now.getMonth() + 1).padStart(2, '0'),
          String(now.getDate()).padStart(2, '0'),
        ].join('-'),
      });
    },
  },
});
