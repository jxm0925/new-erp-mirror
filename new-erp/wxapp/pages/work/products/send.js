// pages/orders/detail/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Notify from '@vant/weapp/notify/notify';
var app = getApp();
Page({

  /**
   * 页面的初始数据
   */
  data: {
    popShow:false,
    delivery_show:'请选择发货快递',
    order_id:'',
    order_no:'',
    orderInfo:'',
    send_no:'',
    send_cost:'',
    result: [],
  },
  onChange(event) {
    console.log(event)
    this.setData({
      result: event.detail,
    });
  },
  
  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    var that=this;
    let rpx=app.globalData.rpx;
    that.setData({
      scrollHeight: Math.floor(rpx)-150,//240为距离顶部高度，130为距离底部高度，即导航栏高度
      userInfo:wx.getStorageSync('userInfo')
    });
    if (options.order_id) this.setData({ order_id: options.order_id});
    that.getOrderInfo();
    that.getDelivery();
  },
  onConfirm(e){
    var that = this;
    that.setData({
      carrier_id:e.detail.value.value,
      delivery_show:e.detail.value.text,
      popShow:false
    })
  },
  showPopup(e){
    this.setData({
      popShow:true
    })
  },
  onClose(e){
    this.setData({
      popShow:false
    })
  },
  orderCopy(e){
    var that=this;
    wx.setClipboardData({data: 'JT11110000'});
  },
  getOrderInfo:function(){
    var that=this;
    wx.showLoading({ title: "正在加载中" });
    util.request(api.NewOrderInfo2,{order_id:this.data.order_id}).then(function(res){
      var data = {};
      if(res.data.carrier_id){
        data.delivery_show = res.data.delivery.name;
        data.carrier_id = res.data.carrier_id;
      }
      data.orderInfo=res.data;
      if(res.code){
        that.setData(data);
      }else{
        Notify({ type: 'error', message: res.msg });
      }
    });
  },
  getDelivery:function(){
    var that=this;
    util.request(api.DeliveryList).then(function(res){
      if(res.code){
        that.setData({
          columns:res.data
        })
      }
    });
  },
  scan: function (e) {
    var that=this;
    wx.scanCode({
      success: (res) => {
        wx.showToast({
          title: res.result,
        })
        //处理扫码结果
        try
        {
          that.data.send_no = res.result
          that.setData({ send_no:res.result }); 
        }
        catch(err)
        {
          wx.showToast({
            title: res.result,
          })
          return;
        }
      }
    })
  },
  //文件上传
  afterPicRead(e){
    let picsList = this.data.picsList
    if (!picsList) {
      picsList = []
    }
    picsList = picsList.concat(e.detail.file)
    this.setData({
      picsList
    })
  },
  afterPicDel(e){
    let picsList = this.data.picsList
    picsList.splice(e.detail.index, 1)
    this.setData({
      picsList
    })
  },
  inputCost(e){
    var that = this;
    that.setData({
      send_cost:e.detail
    });
  },
  async send(){
    var that=this;
    var images = [];
    // wx.showLoading({
    //   title: "正在发货请稍后..." ,
    //   mask: true
    // });
    const extJsonStr = {}
    if(!that.data.orderInfo.carrier_id && !that.data.carrier_id){
      Notify({ type: 'warning', message: '请选择快递' });
      return;
    }
    if(!that.data.send_no){
      Notify({ type: 'warning', message: '请填写快递单号' });
      return;
    }
    if(!that.data.send_cost){
      Notify({ type: 'warning', message: '请填写快递费用' });
      return;
    }
    if(!that.data.picsList){
      Notify({ type: 'warning', message: '请上传快递凭证' });
      return;
    }
    extJsonStr['carrier_id'] = that.data.carrier_id
    extJsonStr['order_id'] = that.data.order_id
    extJsonStr['carrier_fee'] = that.data.send_cost
    extJsonStr['carrier_tracking_ref'] = that.data.send_no
    extJsonStr['sender'] = that.data.userInfo.name
    // 批量上传附件
    if (that.data.picsList) {
      for (let index = 0; index < that.data.picsList.length; index++) {
        const pic = that.data.picsList[index];
        const res = await util.uploadFile(pic.url)
        if (res.code == 1) {
          images.push(res.data.url)
        }else{
          Notify({ type: 'warning', message: '上传文件失败' });
          return;
        }
      }
    }
    extJsonStr['imgs'] = images;
    util.request(api.NewOrderSend,extJsonStr).then(function(res){
      wx.hideLoading();
      that.getOrderInfo()
      if(!res.code){
        wx.showToast({
          title: res.msg,
          icon: 'error'
        })
        return;
      }
      Notify({ type: 'success', message: '发货成功' });
    });
  }
})