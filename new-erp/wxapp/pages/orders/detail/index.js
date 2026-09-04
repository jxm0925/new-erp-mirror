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
    steps_active:'1',
    popShow:false,
    order_id:'',
    order_no:'',
    orderInfo:'',
    steps: [
      {
        text: '待打印'
      },
      {
        text: '待发货',
      },
      {
        text: '已发货'
      }
    ],
    columns: ['工装', '仓储'],
    send_no:'',
    send_cost:'',
    rooms_show:'请选择发货车间',
    send:{
      'show' : '请选择送货方式',
      'type' :'',
      'no':'',
      'cost':'',
      'note':'',
      'rooms':''
    },
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
    if (options.order_no) this.setData({ order_no: options.order_no});
    that.getOrderInfo();
  },
  onConfirm(e){
    var that = this;
    that.setData({
      'send.rooms':e.detail.value,
      rooms_show:e.detail.value,
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
    util.request(api.OrderInfo,{
      order_id:this.data.order_id,
      order_no:this.data.order_no
    },'GET','application/json',1).then(function(res){
      const data = res.data;
      let setdata = {};
      setdata.orderInfo=data;
      if(data.status==10){
        setdata.steps_active=0;
      }else if(data.status==20){
        setdata.steps_active=1;
      }else if(data.status==30){
        setdata.steps_active=2;
      }
      setdata.order_id=data.id;
      setdata.send={
        show:data.delivery,
        no:data.carrier_tracking_ref,
        type: res.data.carrier_id
      }
      that.setData(setdata)
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
          that.data.send.no = res.result
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
      'send.cost':e.detail
    });
  },
  async send(){
    var that=this;
    var images = [];
    wx.showLoading({
      title: "正在发货请稍后..." ,
      mask: true
    });
    that.data.send.no = that.data.send_no;
    const extJsonStr = {}
    extJsonStr['order_id'] = that.data.order_id
    extJsonStr['send'] = that.data.send
    extJsonStr['sender'] = that.data.userInfo.name
    extJsonStr['is_new'] = true;
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
    util.request(api.OrderSend,extJsonStr,'Post','',1).then(function(res){
      wx.hideLoading();
      that.getOrderInfo()
      if(res.code==400){
        wx.showToast({
          title: res.msg,
          icon: 'error'
        })
        //Notify({ type: 'warning', message: res.msg });
      }
    },function (res) {
      wx.hideLoading();
      return;
    });
  }
})