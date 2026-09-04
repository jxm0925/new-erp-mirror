// pages/suggests/manage/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Dialog from '@vant/weapp/dialog/dialog';
import Notify from '@vant/weapp/notify/notify';
Page({

  /**
   * 页面的初始数据
   */
  data: {
    status:0,
    option1: [
      { text: '待认领', value: 0 },
      { text: '已认领', value: 1 },
      { text: '待审核', value: 2 },
      { text: '已完成', value: 3 },
    ],
    option2: [
      { text: '默认排序', value: 'a' },
      { text: '好评排序', value: 'b' },
      { text: '销量排序', value: 'c' },
    ],
    value1: 0,
    value2: 'a',
    list:[],
    page:1,
    totalPages:1,
    loadmoreText: '正在加载更多数据',
    nomoreText: '全部加载完成',
    nomore: false,
    scrollTop: 0,
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
  },

  /**
   * 生命周期函数--监听页面显示
   */
  onShow() {
    var userInfo = wx.getStorageSync('userInfo');
    var that=this;
    let data = {};
    data.userInfo = userInfo;
    that.setData(data);
    if(userInfo.is_login){
      this.setData({
        page:1,
        totalPages:1,
        list:[]
      });
      this.getList();
    }
  },

  /**
   * 页面上拉触底事件的处理函数
   */
  onReachBottom() {
    console.log(111);
    this.getList();
  },

  /**
   * 用户点击右上角分享
   */
  onShareAppMessage() {

  },
  changeStatus(e){
    var that = this;
    that.setData({
      status:e.detail,
      page:1,
      totalPages:1,
      list:[]
    })
    this.getList();
  },
  getList(){
    var that = this;
    if (that.data.totalPages <= that.data.page-1) {
      that.setData({
        nomore: true
      })
      return;
    }
    util.request(api.FeedbackManageList,{status:that.data.status,page:that.data.page}).then(function(res){
      if(res.code==1){
        that.setData({
          list: that.data.list.concat(res.data.data),
          page: res.data.current_page+1,
          totalPages: res.data.last_page
        })
      }
    })
  },
  accept(e){
    console.log(e)
    var that = this;
    const id = e.currentTarget.dataset.id;
    Dialog.confirm({
      title: '是否认领该条提案？',
    }).then(() => {
      util.request(api.AcceptFeedback,{id:id}).then(function(res){
        if(res.code){
          Notify({ type: 'success', message: '操作成功' });
          that.setData({
            list:[],
            page:1,
            totalPages: 1
          });
          that.getList();
        }else{
          Notify({ type: 'warning', message: res.msg });
        }
      })
    
    }).catch(() => {
      // on cancel
    });
  },
  result(e){
    wx.navigateTo({
      url: '/pages/suggests/manage/result?id='+e.currentTarget.dataset.id,
    })
  },
  showInfo(e){
    var that = this;
    const id = e.currentTarget.dataset.id;
    console.log(e)
    util.request(api.FeedbackInfo,{id:id}).then(function(res){
      that.setData({
        infoModel:true,
        suggestInfo:res.data
      })
    });
  },
  onClose(){
    this.setData({
      infoModel:0
    })
  },
  
  previewImage(e) {
    console.log(e)
    wx.previewImage({
      current:e.currentTarget.dataset.url,
      urls:[e.currentTarget.dataset.url]
    });
  },
})