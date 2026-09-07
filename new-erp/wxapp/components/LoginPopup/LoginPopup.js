var util = require('../../utils/util.js');
var api = require('../../config/api.js');
var erpAuth = require('../../services/erp-auth.js');
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
     that.setData({ loading: true });
     wx.showLoading({
         title: '正在登录',
         mask: 'true'
     })
     wx.login({
       success: (res) => {
          if(!res.code){
            wx.hideLoading();
            that.setData({ loading:false });
            Notify({ type: 'danger', message: '微信登录凭证获取失败' });
            return;
          }
          const account = that.data.account.trim();
          const password = that.data.password;
          util.request(api.UserLogin,{code:res.code,account,password,is_erp:1}).then(function(legacyResult){
            if(legacyResult.code!=1){
              throw new Error(legacyResult.msg || '账号或密码错误');
            }
            const ticket = legacyResult.data && legacyResult.data.erp_sso_ticket;
            if(!ticket){
              throw new Error('旧 ERP 未返回统一登录票据，请确认认证服务已更新');
            }
            return erpAuth.sso(ticket).then(function(erpResult){
              const legacyUser = legacyResult.data.staff_info || {};
              legacyUser.is_login = 1;
              try{
                // 两边均验证成功后才一次性落地，避免出现页面看似已登录但工单仍不可用。
                wx.setStorageSync('userInfo', legacyUser);
                wx.setStorageSync('token', legacyResult.data.token);
                erpAuth.persistSession(erpResult);
              }catch(storageError){
                wx.removeStorageSync('userInfo');
                wx.removeStorageSync('token');
                wx.removeStorageSync('erp_token');
                wx.removeStorageSync('erp_user');
                wx.removeStorageSync('erp_permissions');
                throw new Error('保存统一登录信息失败');
              }
              that.setData({ show:false, userInfo_tank:false, password:'' });
              that.getUserInfo();
            });
          }).catch(function(error){
            let message = error.message || '登录失败';
            if(error.statusCode === 409){
              message = error.message || '统一登录票据已失效，请重新登录';
            }else if(error.statusCode === 422){
              message = error.message || '统一登录票据校验失败，请重新登录';
            }
            Notify({ type: 'danger', message });
          }).finally(function(){
            wx.hideLoading();
            that.setData({ loading:false });
          });
       },
       fail:(res) => {
           wx.hideLoading();
           that.setData({ loading:false });
           Notify({ type: 'danger', message: '登录失败' });
       }
     })
   },
   async getUserInfo(){
     util.refreshPage()
     Notify({ type: 'success', message: '登录成功' });
   }
  },
})
