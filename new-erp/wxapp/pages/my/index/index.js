// pages/my/index/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
var erpAuth = require('../../../services/erp-auth.js');
import Notify from '@vant/weapp/notify/notify';

Page({
  /**
   * 页面的初始数据
   */
  data: {
    userInfo: {
      is_login: 0
    },
    score: 0,
    unifiedLoggedIn: false,
    erpUser: null,
    logoutBusy: false
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {},

  /**
   * 生命周期函数--监听页面初次渲染完成
   */
  onReady() {},

  /**
   * 生命周期函数--监听页面显示
   */
  onShow() {
    var userInfo = wx.getStorageSync('userInfo');
    this.setData({
      userInfo: userInfo,
      erpUser: wx.getStorageSync('erp_user') || null,
      unifiedLoggedIn:Boolean(wx.getStorageSync('token') && wx.getStorageSync('erp_token'))
    });
    console.log(userInfo);
    if (userInfo && userInfo.is_login) {
      this.getAdminInfo();
    }
    if (typeof this.getTabBar === 'function' && this.getTabBar()) {
      this.getTabBar().setData({
        active: 2
      });
    }
  },

  closeTank() {
    if (!this.data.userInfo.is_login) {
      util.BadgePopup();
    }
  },

  getAdminInfo() {
    var that = this;
    util.request(api.getAdminInfo).then(function (res) {
      console.log(res);
      if (res && res.data) {
        that.setData({
          score: res.data.score || 0
        });
      }
    });
  },

  loginPop() {
    util.BadgePopup();
  },

  /**
   * 页面通用快捷跳转
   */
  navTo(e) {
    const url = e.currentTarget.dataset.url;
    const needAuth = e.currentTarget.dataset.auth;
    const isTab = e.currentTarget.dataset.tab;

    if (needAuth && (!this.data.userInfo || !this.data.userInfo.is_login)) {
      util.BadgePopup();
      return;
    }
    if (!url) return;

    if (isTab) {
      wx.switchTab({ url: url });
    } else {
      wx.navigateTo({ url: url });
    }
  },

  /**
   * 生命周期函数--监听页面隐藏
   */
  onHide() {},

  /**
   * 生命周期函数--监听页面卸载
   */
  onUnload() {},

  /**
   * 页面相关事件处理函数--监听用户下拉动作
   */
  onPullDownRefresh() {
    if (this.data.userInfo && this.data.userInfo.is_login) {
      this.getAdminInfo();
    }
    wx.stopPullDownRefresh();
  },

  /**
   * 页面上拉触底事件的处理函数
   */
  onReachBottom() {},

  /**
   * 用户点击右上角分享
   */
  onShareAppMessage() {},

  suggestManage() {
    if (!this.data.userInfo.is_login) {
      util.BadgePopup();
      return;
    }
    util.request(api.CheckAccess).then(function (res) {
      console.log(res);
    });
    wx.navigateTo({
      url: '/pages/suggests/manage/index'
    });
  },

  logoutAll() {
    if (this.data.logoutBusy) return;
    this.setData({ logoutBusy: true });
    erpAuth.logoutAll().then(() => {
        this.setData({ userInfo: { is_login: 0 }, erpUser: null, unifiedLoggedIn: false, score: 0 });
        Notify({ type: 'success', message: '已退出统一账号' });
      })
      .finally(() => this.setData({ logoutBusy: false }));
  }
});
