// index.js
import Notify from '@vant/weapp/notify/notify';
var util = require('../../utils/util.js');
var api = require('../../config/api.js');

Page({
  data: {
    autosize: { minHeight: 100 },
    pageHeight:0,
    list:[
      { url:"", icon:"/static/images/work_order.png", name:"工单", show: true },
      { url:"", icon:"/static/images/up.png", name:"改良改善", show: true },
      { url:"", icon:"/static/images/points-mall.png", name:"简云幸福加油站", show: true },
      { url:"", icon:"/static/images/outin.png", name:"出入库", show: true },
      { url:"", icon:"/static/images/check.png", name:"产品核价", show: true },
      { url:"", icon:"/static/images/score.png", name:"汇报中心", show: true },
      { url:"", icon:"/static/images/needs.png", name:"客户需求单", show: false } // 默认先隐藏，等判断权限
    ]
  },

  onLoad: function () {
    this.getIndexData();
  },

  onShow: function(){
    var userInfo = wx.getStorageSync('userInfo');
    console.log(userInfo)
    var isSales = false; 
    if (userInfo && userInfo.isSales) {
      isSales = true;
    }

    // 动态修改菜单的显示状态
    var currentList = this.data.list;
    currentList[6].show = isSales;

    this.setData({
      userInfo: userInfo,
      list: currentList
    });
  },

  onReady: function () {},
  onHide: function () {},

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

    if(info.index!=1 && !wx.getStorageSync('userInfo').user_id){
      util.BadgePopup();
      return;
    }
    if(info.index==4 && !that.data.userInfo.can_in){
      Notify({ type: 'danger', message: '暂无权限，请联系管理员' });
      return;
    }
    
    //终极兜底：万一前端显示错乱，点击时再拦截一次
    if(info.index==6 && !this.data.list[6].show){
      Notify({ type: 'danger', message: '暂无权限，仅限销售人员访问' });
      return;
    }

    switch (info.index) {
      case 0: wx.navigateTo({ url: '/pages/work/index/index' }); break;
      case 1: wx.navigateTo({ url: '/pages/suggests/sub/index' }); break;
      case 2: wx.navigateTo({ url: '/pages/mall/index/index' }); break;
      case 3: wx.navigateTo({ url: '/pages/stock/stockin/stockin' }); break;
      case 4: wx.navigateTo({ url: '/pages/stock/product/cost' }); break;
      case 5: wx.navigateTo({ url: '/pages/my/report/index/index' }); break;
      case 6: wx.navigateTo({ url: '/pages/my/needs/needs' }); break;
      default: wx.navigateTo({ url: '/pages/ucenter/help/help' }); break;
    }
  }
})