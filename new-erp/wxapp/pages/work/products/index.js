// pages/work/products/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Notify from '@vant/weapp/notify/notify';
import Dialog from '@vant/weapp/dialog/dialog';
Page({

  /**
   * 页面的初始数据
   */
  data: {
    order_id:'',
    process:[],
    search_process:'',
    noteExpanded: false
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    this.setData({
      order_id:options.id,
    })
    this.getOption();
    this.homeIndex();
    this.calcScrollHeight();
    console.log(this.data);
  },
  calcScrollHeight() {
    const query = wx.createSelectorQuery();
    query.select('.top-area').boundingClientRect(); // 我们会给顶部区域加 class
    query.exec(res => {
      const topHeight = res[0].height;
      const system = wx.getSystemInfoSync();
      const windowHeight = system.windowHeight;
      const scrollHeight = windowHeight - topHeight - 50;
  
      this.setData({ scrollHeight });
    });
  },
  toggleNote() {
    this.setData({
      noteExpanded: !this.data.noteExpanded
    });
  },
  accept(e){
    console.log(e);
    var that = this;
    var id = e.currentTarget.dataset.id;
    if(!id){
      Notify({ type: 'warning', message: '参数错误' });
      return;
    }
    Dialog.confirm({
      title: '接单后将会开始计算订单工时，是否确认？',
    }).then(() => {
      util.request(api.ProductsOrderAccept,{id:id}).then(function(res){
        if(res.code){
          Notify({ type: 'success', message: '接单成功' });
          that.onLoad({id:that.data.order_id});
        }else{
          Notify({ type: 'warning', message: res.msg });
        }
      })
    
    }).catch(() => {
      // on cancel
    });
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
  
  goDetail(e){
    wx.navigateTo({
      url: '/pages/work/products/detail?id='+e.currentTarget.dataset.id,
    })
  },
  getOption(){
    var that = this;
    util.request(api.ProductsPre).then(function(res){
      if(res.code){
         that.setData({
           process:res.data
         });
      }
  });
  },
  homeIndex(){
    var that = this;
    util.request(api.ProductsIndex,{order_id:that.data.order_id,process:that.data.search_process}).then(function(res){
        var data = {};
        if(res.code){
           data.info = res.data.info;
           data.list = res.data.list;
        }

        that.setData(data);
    });
  },
  onTabChange(e){
    console.log(e)
    this.setData({
      search_process:e.detail.name
    })
    this.homeIndex();
  }
})