var util = require('../../utils/util.js');
var api = require('../../config/api.js');
import Notify from '@vant/weapp/notify/notify';
// components/LoginPopup.js
Component({
  /**
   * 组件的属性列表
   */
  properties: {
    isShow:Number
  },

  /**
   * 组件的初始数据
   */
  data: {
    show: false,
    account:'',
    password:'',
    userInfo:{},
  },
// 这里可以忽略，自己业务需求
  attached(){
    const {isShow} = this.data
    if(isShow){
      this.setData({
        show:isShow==1?true:false
      })
    }
  },
  /**
   * 组件的方法列表
   */
  methods: {
    
    //打开/关闭授权弹窗
    closeTank(e) {
      if (!this.data.show) {
        this.setData({
          show: true,
        })
      } else {
          this.setData({
            show: false
          })
      }

    },
    /**
     * 获取头像
     */
    onChooseAvatar(e) {
      this.setData({
          'userInfo.avatar': e.detail.avatarUrl
      })
    },
    
    /**
     * 获取用户昵称
     */
    getNickName(e) {
      this.setData({
          'userInfo.nickName': e.detail.value
      })
    },
    /**
    * 提交
    */
   submit(e) {
    let that = this;
    let data = {};
    let no_err = 1;
    if (!that.data.account) {
      data.account_error = true;
      no_err=0;
    }
    if (!that.data.password) {
      data.password_error = true;
      no_err=0;
    }
    if(!no_err){
      that.setData(data)
      return;
    }
     wx.showLoading({
         title: '正在登录',
         mask: 'true'
     })
     wx.login({
       success: (res) => {
          wx.hideLoading();
           if(res.code){
               util.request(api.UserLogin,{code:res.code,account:that.data.account,password:that.data.password,is_erp:1}).then(function(res){
                   if(res.code==1){
                      var userInfo = res.data.staff_info;
                      that.setData({
                        userInfo_tank: false
                      })
                     userInfo.is_login = 1;
                     try{
                       wx.setStorageSync('userInfo', userInfo)
                       wx.setStorageSync('token', res.data.token)
                       that.getUserInfo();
                     }catch(e){
                        Notify({ type: 'danger', message: '保存用户信息失败' });
                     }
                   }else{
                    Notify({ type: 'danger', message: res.msg });
                   }
               })
           }
       },
       fail:(res) => {
           Notify({ type: 'danger', message: '登录失败' });
       }
     })
     that.setData({
       show:false
     })
   },
   async getUserInfo(){
     util.refreshPage()
     Notify({ type: 'success', message: '登录成功' });
   }
  },
})