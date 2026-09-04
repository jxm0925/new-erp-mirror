// pages/work/my/index.js

var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Notify from '@vant/weapp/notify/notify';
Page({

  /**
   * 页面的初始数据
   */
  data: {
    list:[],
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
    this.setData({
      page:1,
      list:[]
    });
    this.getList();
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
  getList(){
    var that = this;
    console.log(that.data.loadend)

    util.request(api.GetMyWorkList).then(function(res){
      console.log(res.data)
      if(res.code){
        that.setData({
          list:res.data
        })
      }
    })
  },
  
  goDetail(e){
    console.log(e);
    wx.navigateTo({
      url: '/pages/work/detail/index?id='+e.currentTarget.dataset.id,
    })
  },
})