// index.js
var util = require('../../utils/util.js');
var api = require('../../config/api.js');

Page({
  data: {
    autosize: {
      minHeight: 100
    },
    pageHeight: 0,
    banner: [],
    list: [
      {
        key: 'legacy_work',
        url: '/pages/work/index/index',
        icon: '/static/images/work_order.png',
        vanIcon: '/static/images/work_order.png',
        name: '旧工单'
      },
      {
        key: 'suggests',
        url: '/pages/suggests/sub/index',
        icon: '/static/images/up.png',
        vanIcon: 'chart-trending-o',
        name: '改良改善'
      },
      {
        key: 'mall',
        url: '/pages/mall/index/index',
        icon: '/static/images/points-mall.png',
        vanIcon: 'shop-o',
        name: '积分商城'
      },
      {
        key: 'production_warehouse',
        url: '/pages/production/hub/index',
        icon: '/static/images/work_order.png',
        vanIcon: 'apps-o',
        name: '生产仓储',
        sub: '工单·仓库·发货·统计',
        badge: '整合入口',
        highlight: true
      },
      {
        key: 'report',
        url: '/pages/my/report/index/index',
        icon: '/static/images/todo.png',
        vanIcon: 'edit',
        name: '汇报中心'
      },
      {
        key: 'needs',
        url: '/pages/my/needs/needs',
        icon: '/static/images/needs.png',
        vanIcon: 'contact',
        name: '客户需求单'
      }
    ]
  },
  onLoad: function () {
    this.getIndexData();
  },
  onShow: function () {
    var userInfo = wx.getStorageSync('userInfo');
    this.setData({
      userInfo: userInfo
    });
    if (typeof this.getTabBar === 'function' && this.getTabBar()) {
      this.getTabBar().setData({
        active: 0
      });
    }
  },
  onReady: function () {},
  onHide: function () {},
  getIndexData: function () {
    let that = this;
    util.request(api.IndexUrlHome).then(function (res) {
      if (res && res.code && res.data && Array.isArray(res.data.banner)) {
        that.setData({ banner: res.data.banner });
      }
    }).catch(function () {});
  },
  jumpUrl: function (event) {
    var info = event.currentTarget.dataset || {};
    var index = Number(info.index);
    var item = this.data.list[index] || {};
    var userInfo = wx.getStorageSync('userInfo') || {};

    // 生产仓储页会自行校验统一登录是否包含 ERP 会话，避免旧版本遗留的单边 token 阻塞首页跳转。
    if (item.key === 'production_warehouse' || index === 3) {
      wx.navigateTo({
        url: '/pages/production/hub/index'
      });
      return;
    }
    if (item.key === 'suggests' || index === 1) {
      wx.navigateTo({
        url: '/pages/suggests/sub/index'
      });
      return;
    }
    if (item.key === 'mall' || index === 2) {
      wx.switchTab({
        url: '/pages/mall/index/index'
      });
      return;
    }
    if (!userInfo.user_id) {
      util.BadgePopup();
      return;
    }
    if (item.url) {
      wx.navigateTo({
        url: item.url
      });
      return;
    }
    wx.navigateTo({
      url: '/pages/ucenter/help/help'
    });
  }
});
