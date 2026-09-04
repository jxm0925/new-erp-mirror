// pages/suggests/index/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Dialog from '@vant/weapp/dialog/dialog';
import Notify from '@vant/weapp/notify/notify';
var app = getApp();
Page({

  /**
   * 页面的初始数据
   */
  data: {
    active:0,
    list:[],
    status_list:[{
      title:'已提交'
    },{
      title:'已认领'
    },{
      title:'待审核'
    },{
      title:'已完成'
    }],
    loadmoreText: '正在加载更多数据',
    nomoreText: '全部加载完成',
    nomore: false,
    page:1,
    totalPages:1,
    scrollTop: 0,
    infoModel:0
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    var userInfo = wx.getStorageSync('userInfo');
    var that=this;
    let data = {};
    data.userInfo = userInfo;
    if(options.active){
      data.active = parseInt(options.active)
    }
    that.setData(data);

    if(userInfo.is_login){
      this.getList();
    }
  },

  /**
   * 生命周期函数--监听页面显示
   */
  onShow() {

  },
  onClose(){
    this.setData({
      infoModel:0
    })
  },
  changeTags(e){
    this.setData({
      list:[],
      active:e.detail.index,
      page:1,
      totalPages: 1,
      nomore:false
    });
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
    util.request(api.FeedbackList,{active:that.data.active,page:that.data.page}).then(function(res){
      if(res.code==1){
        that.setData({
          list: that.data.list.concat(res.data.data),
          page: res.data.current_page+1,
          totalPages: res.data.last_page
        })
      }
    })
  },
  /**
   * 页面上拉触底事件的处理函数
   */
  onReachBottom() {
    this.getList();
  },
  showInfo(e){
    var that = this;
    const id = e.currentTarget.dataset.id;
    console.log(e)
    util.request(api.FeedbackInfo,{id:id}).then(function(res){
      that.setData({
        infoModel:1,
        suggestInfo:res.data
      })
    });
  },
  del(e){
    var that =this;
    console.log(e)
    const id = e.currentTarget.dataset.id;
    if(!id){
      return;
    }
    Dialog.confirm({
      title: '是否删除？',
    }).then(() => {
        util.request(api.FeedbackDel,{id:id}).then(function(res){
          Notify('删除成功');
          that.setData({
            list:[],
            page:1,
            totalPages:1,
          })
          that.onLoad();
        });
      }).catch(() => {
        // on cancel
      });
    
  }
})