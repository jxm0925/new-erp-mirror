
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');

Page({
  data: {
    list:[],
    crossAxisCount: 2,
    crossAxisGap: 8,
    mainAxisGap: 8,
    type:''
  },
  onShow(){
    this.getList();
  },
  getList(){
    var that = this;
    util.request(api.GetGoodsList,{type:that.data.type}).then(function(res){
      console.log(res);
      that.setData({list:res.data.data})
    });
  },
  changeTab(e){
    console.log(e)
    this.setData({
      type:e.detail.index
    })
    this.getList();
  },
  goDetail(e){
    console.log(e)
    wx.navigateTo({
      url: '/pages/mall/detail/index?id='+e.currentTarget.dataset.id,
    })
  }
})
