// pages/stock/stockin/stockin.js
import Notify from '@vant/weapp/notify/notify';
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
var app = getApp();
Page({

  /**
   * 页面的初始数据
   */
  data: {
    productName:'请选择配件',
    amount:'',
    code:'',
    showDialog:false,
    thisAmount:'',
    thisStockId:'',
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad() {
    var userInfo = wx.getStorageSync('userInfo')
    console.log(userInfo)
    if(!userInfo.staff_login){
      wx.redirectTo({
        url: '/pages/staff/login/login',
      })
    }
    const {windowHeight} = wx.getSystemInfoSync();
    const tabbarHeight = 200;

    this.setData({
      pageHeight:(windowHeight-tabbarHeight)+'px',
      amount:'',
      code:'',
      out_amount:'',
      out_remark:'',
      productName:'请选择配件',
      stock_goods_id: '',
      stock_out_id:'',
      productShow:false
    })
    this.getOptions();
  },
  showPopup(e){
      var that =this
      that.setData({
        productShow:true,
        productArray:that.data.allArray
      })
  },
  onClose(e){
      this.setData({
        productShow:false
      })
  },
  onFinish(e) {
    const { selectedOptions, value } = e.detail;
    const fieldValue = selectedOptions
        .map((option) => option.text || option.name)
        .join('/');
    this.setData({
      productName:fieldValue,
      stock_goods_id: value,
      stock_out_id:value,
      productShow:false
    })
  },
  getOptions(){
    var that = this;
    util.request(api.AccessoryOptions).then(function (res) {
      if(res.code){
        that.setData({
          productArray:res.data,
          allArray:res.data
        })
      }
    })
  },
  scanCode(e){
    var that = this;
    wx.scanCode({
      onlyFromCamera: false,
      success:res=>{
        if(res.errMsg == 'scanCode:ok'){
          that.setData({
            code:res.result
          })
        }
      },
      fail: res => {
        // 接口调用失败
        wx.showToast({
            icon: 'none',
            title: '接口调用失败！'
        })
      },
      complete: res => {
        // 接口调用结束
        console.log(res)
      }
    })
  },
  //提交
  async bindSave(){
    if(!this.data.stock_goods_id){
      wx.showToast({
        title: '请选择配件',
        icon:'none'
      })
      return;
    }
    if (!this.data.amount) {
      wx.showToast({
        title: '入库数量不能为空',
        icon: 'none',
      })
      return
    }
    if(!wx.getStorageSync('token')){
      wx.redirectTo({
        url: '/pages/staff/login/login',
      })
      return;
    }
    const extJsonStr = {}
    extJsonStr['stock_goods_id'] = this.data.stock_goods_id
    extJsonStr['amount'] = this.data.amount
    extJsonStr['code'] = this.data.code
    console.log(JSON.stringify(extJsonStr));

    util.request(api.AddAccessoryStock,extJsonStr).then(function(res){
      console.log(res);
      if(res.code){
        Notify({ type: 'success', message: '入库成功' });
        util.refreshPage()
      }else{
        Notify({ type: 'fail', message: '入库失败' });
      }
    })
  },
  async bindOut(){
    if(!this.data.stock_out_id){
      wx.showToast({
        title: '请选择配件',
        icon:'none'
      })
      return;
    }
    if (!this.data.out_amount) {
      wx.showToast({
        title: '出库数量不能为空',
        icon: 'none',
      })
      return
    }
    if(!wx.getStorageSync('token')){
      wx.redirectTo({
        url: '/pages/staff/login/login',
      })
      return;
    }
    const extJsonStr = {}
    extJsonStr['stock_out_id'] = this.data.stock_out_id
    extJsonStr['out_amount'] = this.data.out_amount
    extJsonStr['out_remark'] = this.data.out_remark
    console.log(JSON.stringify(extJsonStr));

    util.request(api.OutAccessoryStock,extJsonStr).then(function(res){
      console.log(res);
      if(res.code){
        Notify({ type: 'success', message: '出库成功' });
        util.refreshPage()
      }else{
        Notify({ type: 'fail', message: '出库失败' });
      }
    })
  },
  searchField(e){
    let ret = [];
    var that = this;
    console.log(that.data.allArray)
    if(!e.detail.length){
      ret = that.data.allArray
    }else{
      that.data.allArray.forEach((item, index) => {
        if(item.goodsname.indexOf(e.detail) > -1){
          ret.push(item)
        }
      })
    }
    if(!ret.length){
      ret.push({})
    }
    console.log(ret)
    that.setData({
      accessoryList: ret
    })
  },
  inputAmount(e){
    console.log(e)

    this.setData({
      thisStockId:e.detail.value,
      showDialog:true
    })
  },
  addToCart(e){
    console.log(e)
    //加到后台数据库

    this.setData({
      showDialog:false
    })
  }
})