import Notify from '@vant/weapp/notify/notify';
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
var app = getApp();

Page({

  /**
   * 页面的初始数据
   */
  data: {
    showCartPop: false,
    showQuickPopup: false,
    showPreProductPopup: false,
    pickQuickSelect: '请选择产品',
    columns: [],
    option1: [
      { text: '配件', value: 2 },
      { text: '成品', value: 1 },
    ],
    scanInfo: {
      sku_name: '123',
      amount: 1,
      sku_id: 4212
    },
    value1: 2,
    value: '',
    showScan: false
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad() {
    var userInfo = wx.getStorageSync('userInfo');
    console.log(userInfo)
    if (!userInfo.staff_login) {
      wx.redirectTo({
        url: '/pages/staff/login/login',
      })
    }
    this.setData({
      userInfo: userInfo
    });
    this.getOptions();
  },

  onShow() {
    this.cartList();
  },

  async getOptions() {
    const res = await util.request(api.AccessoryFirst, { type: this.data.value1 });
    if (res.code) {
      this.setData({
        accessoryArray: res.data,
        children: res.data[0].sku,
        allArray: res.data
      })
    }
    this.processBadge()
  },

  accessoryClick(e) {
    const index = e.currentTarget.dataset.idx
    const selected = this.data.accessoryArray[index]
    this.setData({
      children: selected.sku,
      scrolltop: 0
    })
    this.processBadge()
  },

  async cartList() {
    const res = await util.request(api.AccessoryCart)
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

  async getColumns() {
    const res = await util.request(api.QuickAccessoryList)
    if (res.code) {
      this.setData({
        columns: res.data
      })
    }
    console.log(res.data)
  },

  processBadge() {
    const accessoryArray = this.data.accessoryArray
    const children = this.data.children
    const shippingCarInfo = this.data.shippingCarInfo
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
    if (shippingCarInfo && shippingCarInfo.items) {
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
      const goods = this.data.shippingCarInfo.items.find(ele => { return ele.children_id == item.id })
      if (goods) {
        number = 1
      }
    }
    const res = await util.request(
      api.AddAccessoryCart,
      {
        accessory_id: item.goods_id,
        num: number,
        children_id: item.id,
        univalence: item.univalence,
        name: item.sku_name
      })
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

  addStock() {
    var that = this;
    util.request(api.AccessoryStockIn).then(function (res) {
      if (res.code) {
        Notify({ type: 'success', message: '入库成功' });
        that.cartList();
      } else {
        Notify({ type: 'danger', message: res.msg });
      }
    })
  },

  outStock() {
    var that = this;
    util.request(api.AccessoryStockOut).then(function (res) {
      if (res.code) {
        Notify({ type: 'success', message: '出库成功' });
        that.cartList();
      } else {
        Notify({ type: 'danger', message: res.msg });
      }
    })
  },

  async cartStepChange(e) {
    const cartInfo = this.data.shippingCarInfo.items;
    let item = null;
    for (let i = 0; i < cartInfo.length; i++) {
      if (cartInfo[i].children_id == e.currentTarget.dataset.idx) {
        item = cartInfo[i]
      }
    }
    if (!item) {
      Notify({ type: 'danger', message: '添加失败' });
      return;
    }
    if (e.detail < 1) {
      wx.showLoading({
        title: '',
      })
      if (item) {
        await util.request(api.RemoveAccessoryCart, { id: item.id })
      }
      wx.hideLoading()
      this.cartList()
      this.processBadge()
    } else {
      wx.showLoading({
        title: '',
      })
      const res = await util.request(api.changeAccessoryCart, { id: item.id, nums: e.detail })
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
    const res = await util.request(api.EmptyStockList)
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

  showPop(e) {
    const type = e.currentTarget.dataset.type;
    var data = {};
    if (type == "cart") {
      data = {
        showCartPop: !this.data.showCartPop
      }
    } else if (type == "list") {
      // 修正此处变量名，确保与 data 定义一致
      data = {
        showPreProductPopup: !this.data.showPreProductPopup
      }
    } else if (type == 'scan') {
      console.log('开始扫码...');
      wx.scanCode({
        success: (res) => {
          // 使用箭头函数确保 this 指向 Page 实例
          console.log('扫码原始结果:', res.result);
          
          // 健壮性判断：确保结果包含分隔符
          if (!res.result || res.result.indexOf('-') === -1) {
            wx.showToast({ title: '无效的条码格式', icon: 'none' });
            return;
          }

          var result = res.result.split('-');
          var skuId = result[1];
          console.log('解析到的sku_id:', skuId);

          util.request(api.ScanInfo, { sku_id: skuId }).then((res) => {
            if (res.code && res.data) {
              console.log('接口返回数据:', res.data);
              this.setData({
                showScan: true, // 明确开启扫码结果弹窗
                'scanInfo.sku_name': res.data.goods_name + '(' + res.data.sku_name + ')',
                'scanInfo.amount': 1,
                'scanInfo.sku_id': skuId
              });
            } else {
              wx.showToast({ title: res.msg || '未找到该商品', icon: 'none' });
            }
          }).catch(err => {
            console.error('请求失败:', err);
          });
        },
        fail: (err) => {
          console.log('用户取消扫码或扫码失败', err);
        }
      });
      // 重要：扫码是异步触发，直接 return，不执行函数末尾的通用 setData
      return;
    } else {
      this.getColumns();
      data = {
        showQuickPopup: !this.data.showQuickPopup // 修正此处逻辑判断参考值
      }
    }
    this.setData(data)
  },

  hidePop(e) {
    const type = e.currentTarget.dataset.type;
    var data = {};
    if (type == "cart") {
      data = { showCartPop: false }
    } else if (type == "list") {
      data = { showPreProductPopup: false }
    } else if (type == 'scan') {
      data = { showScan: false }
    } else {
      data = { showQuickPopup: false }
    }
    this.setData(data)
  },

  async searchChange(e) {
    let children = [];
    var that = this;
    const res = await util.request(api.SearchFields, { value: e.detail, type: that.data.value1 })
    if (res.data.length) {
      children = res.data[0].sku
    }
    that.setData({
      accessoryArray: res.data,
      children: children,
      value: e.detail
    })
  },

  onChange(e) {
    var that = this;
    that.setData({
      value: e.detail
    })
  },

  async searchField(e) {
    let children = [];
    var that = this;
    const res = await util.request(api.SearchFields, { value: that.data.value, type: that.data.value1 })
    if (res.data.length) {
      children = res.data[0].sku
    }
    that.setData({
      accessoryArray: res.data,
      children: children
    })
  },

  searchCancel(e) {
    this.getOptions();
  },

  async uploadImg(e) {
    var that = this;
    const children = that.data.children;
    const index = e.currentTarget.dataset.idx;

    if (!e.detail.file) {
      Notify({ type: 'warning', message: '请选择上传图片' });
      return;
    }
    wx.showLoading({
      title: '',
      mask: 'true'
    })
    const res = await util.uploadFile(e.detail.file.url)
    if (!res.code) {
      Notify({ type: 'danger', message: '上传失败' });
      wx.hideLoading();
      return;
    }
    const res1 = await util.request(api.ChangeImage, { id: children[index].id, url: '/' + res.data.key })
    if (!res1.code) {
      Notify({ type: 'danger', message: '修改失败' });
      wx.hideLoading();
      return;
    }
    Notify({ type: 'success', message: '修改成功' });
    children[index].image = res.data.url;
    that.setData({
      children: children
    })
  },

  async changePrice(e) {
    var that = this;
    const items = that.data.shippingCarInfo.items;
    const index = e.currentTarget.dataset.idx;
    const res = await util.request(api.ChangePrice, { id: items[index].id, univalence: e.detail.value })

    if (!res.code) {
      Notify({ type: 'danger', message: res.msg });
      return;
    }

    items[index].univalence = e.detail.value
    that.setData({
      "shippingCarInfo.items": items
    })
  },

  quickAdd() {
    this.setData({
      quickPopup: true
    })
  },

  onConfirm(event) {
    const { picker, value, index } = event.detail;
    this.setData({
      pickQuickSelect: value.full_name,
      showPreProductPopup: false,
      pickQuickList: value.accessory_arr
    })
  },

  async onSubmitQuick() {
    var that = this;
    const res = await util.request(
      api.QuickAddAccessoryCart,
      {
        data: JSON.stringify(that.data.pickQuickList)
      })
    if (res.code) {
      that.cartList();
      that.setData({
        pickQuickSelect: '请选择产品',
        pickQuickList: [],
        showQuickPopup: false,
      })
    }
  },

  onSubmitScan() {
    var that = this;
    var info = JSON.stringify(that.data.scanInfo);
    util.request(api.ScanAddAccessoryCart, { scanInfo: info }).then(function (res) {
      if (res.code) {
        that.cartList();
        that.setData({
          showScan: false,
          'scanInfo.sku_name': '',
          'scanInfo.amount': 0,
          'scanInfo.sku_id': ''
        })
      }
    })
  },

  changeType(e) {
    this.setData({
      value1: e.detail,
      accessoryArray: [],
    })
    this.getOptions();
  },

  changeScanStep(e) {
    var that = this;
    that.setData({
      'scanInfo.amount': e.detail
    })
  }
})