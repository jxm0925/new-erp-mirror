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
    showCartPop:false,
    showSubPop:false,
    costInfo:{
      'costName':''
    }
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad() {
    var userInfo = wx.getStorageSync('userInfo')
    if(!userInfo.staff_login){
      wx.redirectTo({
        url: '/pages/staff/login/login',
      })
    }
    this.getOptions();
  },
  onShow(){
    this.cartList();
  },
  
  async getOptions(){
    const res = await util.request(api.AccessoryFirst);
    if(res.code){
      this.setData({
        accessoryArray:res.data,
        children:res.data[0].sku,
        allArray:res.data
      })
    }
    this.processBadge()
  },
  accessoryClick(e) {
    const index = e.currentTarget.dataset.idx
    const selected = this.data.accessoryArray[index]
    this.setData({
      children:selected.sku,
      scrolltop: 0
    })
    this.processBadge()
  },
  async cartList() {
    const res = await util.request(api.AccessoryCostLog)
    if (res.code) {
      this.setData({
        shippingCarInfo: res.data
      })
    } else {
      this.setData({
        shippingCarInfo: null,
        showCartPop: false
      })
    }
    this.processBadge()
  },
  processBadge() {
    const accessoryArray = this.data.accessoryArray
    const children = this.data.children
    const shippingCarInfo = this.data.shippingCarInfo
    console.log(children);
    if (!accessoryArray) {
      return
    }
    if (!children) {
      return
    }
    accessoryArray.forEach(ele => {
      ele.badge = 0
    })
    children.forEach(ele => {
      ele.badge = 0
    })
    if (shippingCarInfo.items) {
      shippingCarInfo.items.forEach(ele => {
        if (ele.accessory_id) {
          const _accessoryArray = accessoryArray.find(a => {
            return a.id == ele.accessory_id
          })
          if (_accessoryArray) {
            _accessoryArray.badge += ele.nums
          }
        }
        if (ele.children_id) {
          const _children = children.find(a => {
            return a.id == ele.children_id
          })
          if (_children) {
            _children.badge += ele.nums
          }
        }
      })
    }
    console.log(shippingCarInfo)
    this.setData({
      accessoryArray,
      children
    })
  },
  async addCart(e) {
    const index = e.currentTarget.dataset.idx
    const item = this.data.children[index]
    wx.showLoading({
      title: '',
    })
    let number = 1 // 加入购物车的数量
    if (this.data.shippingCarInfo && this.data.shippingCarInfo.items) {
      const goods = this.data.shippingCarInfo.items.find(ele => { return ele.children_id == item.id})
      if (goods) {
        number = 1
      }
    }
    const res = await util.request(
      api.AddAccessoryCost,
      {
        accessory_id:item.goods_id,
        num:number,
        children_id:item.id,
        univalence:item.univalence,
        name:item.sku_name
      })
    wx.hideLoading()
    // if (res.code) {
    //   return
    // }
    if (!res.code) {
      wx.showToast({
        title: res.msg,
        icon: 'none'
      })
      return
    }
    this.cartList()
  },
  async cartStepChange(e) {
    const index = e.currentTarget.dataset.idx
    const item = this.data.shippingCarInfo.items[index]
    if (e.detail < 1) {
      // 删除商品
      wx.showLoading({
        title: '',
      })
      const res = await util.request(api.RemoveAccessoryCost,{id: item.id})
      wx.hideLoading()
      if (res.code) {
        this.cartList()
      }
      this.processBadge()
    } else {
      // 修改数量
      wx.showLoading({
        title: '',
      })
      const res = await util.request(api.changeAccessoryCost,{id: item.id,nums:e.detail})
      wx.hideLoading()
      if (!res.code) {
        wx.showToast({
          title: res.msg,
          icon: 'none'
        })
        return
      }
      this.cartList()
    }
  },
  async clearCart() {
    wx.showLoading({
      title: '',
    })
    const res = await util.request(api.EmptyAccessoryCost)
    wx.hideLoading()
    if (!res.code) {
      wx.showToast({
        title: res.msg,
        icon: 'none'
      })
      return
    }
    this.cartList()
  },
  showCartPop() {
    this.setData({
      showCartPop: !this.data.showCartPop
    })
  },
  hideCartPop() {
    this.setData({
      showCartPop: false
    })
  },
  searchField(e){
    let ret = [];
    let children = [];
    var that = this;
    if(!e.detail.length){
      ret = that.getOptions();
    }else{
      that.data.accessoryArray.forEach((item, index) => {
        if(item.goodsname.indexOf(e.detail) > -1){
          ret.push(item)
        }
      })
    }
    if(ret.length){
      children=ret[0].sku
    }
    that.setData({
      accessoryArray: ret,
      children:children
    })
  },
  searchCancel(e){
    this.getOptions();
  },
  onChangeSubPop(e){
    console.log(e)
    const type = e.currentTarget.dataset.type;
    if(type=='open'){
      var sets = true
    }else{
      var sets = false
    }
    this.setData({
      showSubPop:sets
    })
  },
  onchangeCost(e){
    var that=this;
    console.log(e)
    that.setData({
      'costInfo.costName':e.detail
    })
  },
  onSubmit(e){
    var that = this;
    const costInfo = that.data.costInfo
    wx.showLoading({
      title: '',
    })
    console.log(that.data.costInfo)
    util.request(api.SubCostList,{name:costInfo.costName}).then(function (res) {
      console.log(res)
      if(res.code){
        wx.hideLoading();
        Notify({ type: 'success', message: '操作成功' });
        that.setData({
          showSubPop:false,
          'costInfo.costName':''
        })
        that.cartList();
        return;
      }
      Notify({ type: 'danger', message: '操作失败' });
    });
  }
})