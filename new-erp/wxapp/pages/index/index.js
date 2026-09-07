// index.js
import Notify from '@vant/weapp/notify/notify';
var util = require('../../utils/util.js');
var api = require('../../config/api.js');
Page({
  data: {
    autosize: {
      minHeight: 100
    },
    pageHeight:0,
    list:[{
      url:"",
      icon:"/static/images/order.png",
      name:"订单"
    },{
      url:"",
      icon:"/static/images/up.png",
      name:"改良改善"
    },{
      url:"",
      icon:"/static/images/points-mall.png",
      name:"积分商城"
    },{
        url:"",
        icon:"/static/images/work_order.png",
        name:"工单"
      }]
    // },{
    //   url:"",
    //   icon:"/static/images/work_order.png",
    //   name:"工单"
    // },{
    //   url:"",
    //   icon:"/static/images/score.png",
    //   name:"评分"
    // 
  },
  onLoad: function () {
    this.getIndexData();
  },
  onShow:function(){
    var userInfo = wx.getStorageSync('userInfo');
    this.setData({
        userInfo:userInfo
    });
  },
  onReady: function () {
    
  },
  onHide: function () {
    // 页面隐藏

  },
  getIndexData: function () {
    let that = this;
    var data = new Object();
    util.request(api.IndexUrlHome).then(function (res) {
        if (res.code) {
          data.banner = res.data.banner
          that.setData(data);
        }
    });
  },
  jumpUrl:function(event){
    var info = event.currentTarget.dataset;
    
    var that = this;
    // 工单页会校验统一登录是否包含 ERP 会话，避免旧版本遗留的单边 token 阻塞首页跳转。
    if(info.index!=1 && info.index!=3 && !wx.getStorageSync('userInfo').user_id){
      util.BadgePopup();
      return;
    }
    if(info.index==0 && !that.data.userInfo.scope){
      Notify({ type: 'danger', message: '暂无权限，请联系管理员' });
      return;
    }
    console.log(info.index);
    switch (info.index) {
      case 0:
          wx.navigateTo({
              url: '/pages/orders/index/index',
          })
          break;
      case 1:
          wx.navigateTo({
              url: '/pages/suggests/sub/index',
          })
          break;
      case 2:
          wx.navigateTo({
              url: '/pages/mall/index/index',
          })
          break;
      case 3:
          wx.navigateTo({
              url: '/pages/production/workbench/index',
          })
          break;
      default:
          wx.navigateTo({
              url: '/pages/ucenter/help/help',
          })
          break;
    }
  }
})
