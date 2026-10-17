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
    isShow: Number,
    defaultMode: { type: String, value: 'unified' }
  },

  /**
   * 组件的初始数据
   */
  data: {
    show: false,
    account:'',
    password:'',
    userInfo:{},
    loginMode: 'unified',
    loading: false,
  },
// 这里可以忽略，自己业务需求
  attached(){
    const {isShow} = this.data
    if(isShow){
      if (isShow == 1) this.open();
    }
  },
  /**
   * 组件的方法列表
   */
  methods: {

    open(mode) {
      if (this.data.loading) return;
      const selectedMode = mode === 'production' || mode === 'unified' ? mode : this.data.defaultMode;
      this.setData({
        show: true,
        loginMode: selectedMode === 'production' ? 'production' : 'unified',
        account: '',
        password: '',
        account_error: false,
        password_error: false,
      });
    },

    switchMode() {
      this.open(this.data.loginMode === 'production' ? 'unified' : 'production');
    },
    
    //打开/关闭授权弹窗
    closeTank(e) {
      if (this.data.loading) return;
      if (!this.data.show) {
        this.open();
      } else {
          this.setData({
            show: false,
            account: '',
            password: ''
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
    if (this.data.loading) return;
    let that = this;
    let data = {};
    let no_err = 1;
    const account = that.data.account.trim();
    const password = that.data.password;
    if (!account) {
      data.account_error = true;
      no_err=0;
    }
    if (!password) {
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
         mask: true
     })
     const authentication = that.data.loginMode === 'production'
       ? erpAuth.login(account, password).then(erpResult => ({ erpResult }))
       : that.unifiedLogin(account, password);
     return authentication.then(({ erpResult, legacyResult }) => that.saveSession(erpResult, legacyResult))
       .catch(function(error){
         let message = error.message || '登录失败';
         if(error.statusCode === 409){
           message = error.message || '统一登录票据已失效，请重新登录';
         }else if(error.statusCode === 422){
           message = error.message || '账号或密码错误，请重新登录';
         }
         Notify({ type: 'danger', message });
       }).finally(function(){
         wx.hideLoading();
         that.setData({ loading:false });
       });
   },

   unifiedLogin(account, password) {
     return new Promise((resolve, reject) => {
     wx.login({
       success: (res) => {
          if(!res.code){
            reject(new Error('微信登录凭证获取失败'));
            return;
          }
          util.request(api.UserLogin,{code:res.code,account,password,is_erp:1}).then(function(legacyResult){
            if(!legacyResult || legacyResult.code!=1){
              throw new Error((legacyResult && legacyResult.msg) || '账号或密码错误');
            }
            const ticket = legacyResult.data && legacyResult.data.erp_sso_ticket;
            if(!ticket){
              throw new Error('旧 ERP 未返回统一登录票据，请确认认证服务已更新');
            }
            return erpAuth.sso(ticket).then(erpResult => ({ erpResult, legacyResult }));
          }).then(resolve, reject);
       },
       fail: () => reject(new Error('微信登录失败，请重新登录'))
     })
     });
   },

   saveSession(erpResult, legacyResult) {
     return erpAuth.logoutAll().then(() => {
       try {
         if (legacyResult) {
           const legacyUser = legacyResult.data.staff_info || {};
           legacyUser.is_login = 1;
           wx.setStorageSync('userInfo', legacyUser);
           wx.setStorageSync('token', legacyResult.data.token);
         }
         erpAuth.persistSession(erpResult);
       } catch (storageError) {
         wx.removeStorageSync('userInfo');
         wx.removeStorageSync('token');
         erpAuth.clearSession();
         throw new Error('保存登录信息失败');
       }
       this.setData({ show:false, userInfo_tank:false, account:'', password:'' });
       this.getUserInfo();
     });
   },
   async getUserInfo(){
     util.refreshPage()
     Notify({ type: 'success', message: '登录成功' });
   }
  },
})
