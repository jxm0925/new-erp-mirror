// pages/mall/detail/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Dialog from '@vant/weapp/dialog/dialog';
import Notify from '@vant/weapp/notify/notify';

Page({

  /**
   * 页面的初始数据
   */
  data: {
    id:'',
    info:'',
    show: true,
    score:0
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    this.setData({
      id:options.id,
      userInfo:wx.getStorageSync('userInfo')
    })
    this.getInfo();
  },

  /**
   * 生命周期函数--监听页面显示
   */
  onShow() {

  },
  onClickButton(){
    var that=this;
    var userInfo = that.data.userInfo;
    var info = that.data.info;
    console.log(info);
    console.log(userInfo)
    Dialog.confirm({
      title: '兑换后将会扣除相应积分，是否兑换？(当前剩余积分：'+that.data.score+')',
    }).then(() => {
      util.request(api.GoodsExchange,{id:that.data.id}).then(function(res){
        if(res.code){
          userInfo.points-=info.score;
          wx.setStorageSync('userInfo', userInfo)
          Notify({ type: 'success', message: '兑换成功' });
          that.getInfo();
        }else{
          Notify({ type: 'danger', message: res.msg });
        }
        return;
      })
    }).catch(() => {
      console.log(3)
    });
  },
  getInfo(){
    var that = this;
    util.request(api.GetGoodsInfo,{id:this.data.id}).then(function(res){
      if(res.code){
        that.setData({
          score:res.data.user_info.score,
          info:res.data.goods_info
        })
      }
    });
  },
})