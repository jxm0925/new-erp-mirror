// pages/orders/index/index.js
var util = require('../../../../utils/util.js');
var api = require('../../../../config/api.js');
var app = getApp();
Page({

  /**
   * 页面的初始数据
   */
  data: {
    imageURL:'https://img.yzcdn.cn/vant/cat.jpeg',
    selected_status: 10,
    status: [
      { text: '待打印', value: 10 },
      { text: '待发货', value: 20 },
      { text: '已发货', value: 30 },
    ],
    selected_saler:0,
    saler:[
      { text: '全部销售', value: 0},
      { text: '姜纤', value: 1}
    ],
    orderList:[],
    page:1,
    limit:10,
    orderStatus:10,
    searchs:''
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    this.getOrderList();
  },
  /**
   * 页面上拉触底事件的处理函数
   */
  onReachBottom() {
    // var that = this;
    // that.setData({
    //   page:that.data.page+1
    // })
    this.getOrderList();
  },
  goOrderDetail(event){
    const order_no = event.currentTarget.dataset.order_no;
    wx.navigateTo({url: '/pages/orders/detail/index?order_no=' + order_no})
  },
  changeStatus(e){
    var that = this;
    that.setData({
      orderStatus:e.detail,
      page:1,
      orderList:[]
    })
    this.getOrderList();
  },
  getOrderList(){
    var that = this;
    util.request(api.OrderList,{
      type: that.data.orderStatus,
      orderno:that.data.searchs,
      page:that.data.page,
      limit:that.data.limit,
    },'GET','application/json',1).then(function(res){
      var list=res.data.orderlist || [];
      var loadend=list.length < that.data.limit;
      if(that.data.searchs){
        that.data.orderList = res.data.orderlist;
      }else{
        that.data.orderList = util.SplitArray(list, that.data.orderList);
      }
      that.setData({
        orderList:that.data.orderList,
        orderData:res.data.orderdata,
        loadend: loadend,
        loading:false,
        loadTitle: loadend ? "我也是有底线的" : '加载更多',
        page:that.data.page+1,
      });
    });
  },
  onSearch(e){
    this.setData({
      searchs:e.detail,
      page:1
    })
    this.getOrderList()
  }
})