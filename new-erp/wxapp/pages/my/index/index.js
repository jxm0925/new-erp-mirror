// pages/my/index/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
Page({

  /**
   * 页面的初始数据
   */
  data: {
    userInfo:{
      is_login:0
    },
    score:0
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
  },
  /**
   * 生命周期函数--监听页面初次渲染完成
   */
  onReady() {

  },

  /**
   * 生命周期函数--监听页面显示
   */
  onShow() {
    var userInfo = wx.getStorageSync('userInfo');
    this.setData({
        userInfo:userInfo
    });
    console.log(userInfo)
    if(userInfo.is_login){
      this.getAdminInfo();
    }
    if(typeof this.getTabBar === "function" && this.getTabBar()){
      this.getTabBar().setData({
        active:2
      })
    }
  },
  closeTank() {
    if(!this.data.userInfo.is_login){
      util.BadgePopup();
    }else{
      return;
    }
  },
  getAdminInfo(){
    var that = this;
    util.request(api.getAdminInfo).then(function(res){
      console.log(res);
      that.setData({
        score:res.data.score
      })
    });
  },
  loginPop(){
    util.BadgePopup();
  },
  /**
   * 生命周期函数--监听页面隐藏
   */
  onHide() {

  },

  /**
   * 生命周期函数--监听页面卸载
   */
  onUnload() {

  },

  /**
   * 页面相关事件处理函数--监听用户下拉动作
   */
  onPullDownRefresh() {

  },

  /**
   * 页面上拉触底事件的处理函数
   */
  onReachBottom() {

  },

  /**
   * 用户点击右上角分享
   */
  onShareAppMessage() {

  },
  suggestManage(){
    if(!this.data.userInfo.is_login){
      util.BadgePopup();
      return;
    }
    util.request(api.CheckAccess).then(function(res){
      console.log(res);
    });
    wx.navigateTo({
      url: '/pages/suggests/manage/index',
    })
  }
})