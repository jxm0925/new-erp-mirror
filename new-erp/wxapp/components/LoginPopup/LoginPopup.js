var util = require('../../utils/util.js');
var api = require('../../config/api.js');
var erpAuth = require('../../services/erp-auth.js');
import Notify from '@vant/weapp/notify/notify';

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
    account: '',
    password: '',
    lastAccount: '',
    showPassword: false,
    activeField: '',
    account_error: false,
    password_error: false,
    errorMessage: '',
    userInfo: {},
    loginMode: 'unified',
    loading: false,
  },

  attached() {
    const lastAccount = wx.getStorageSync('last_login_account') || '';
    if (lastAccount && !this.data.account) {
      this.setData({ account: lastAccount, lastAccount });
    }
    const { isShow } = this.data;
    if (isShow && isShow == 1) {
      this.open();
    }
  },

  /**
   * 组件的方法列表
   */
  methods: {
    open(mode) {
      if (this.data.loading) return;
      const selectedMode = mode === 'production' || mode === 'unified' ? mode : this.data.defaultMode;
      const lastAccount = wx.getStorageSync('last_login_account') || '';
      this.setData({
        show: true,
        loginMode: selectedMode === 'production' ? 'production' : 'unified',
        account: this.data.account || lastAccount || '',
        password: '',
        lastAccount: lastAccount,
        showPassword: false,
        activeField: '',
        account_error: false,
        password_error: false,
        errorMessage: '',
      });
    },

    switchMode() {
      if (this.data.loading) return;
      const nextMode = this.data.loginMode === 'production' ? 'unified' : 'production';
      this.setData({
        loginMode: nextMode,
        account_error: false,
        password_error: false,
        errorMessage: '',
      });
    },

    onSelectMode(e) {
      if (this.data.loading) return;
      const mode = e && e.currentTarget && e.currentTarget.dataset && e.currentTarget.dataset.mode;
      if (!mode || mode === this.data.loginMode) return;
      this.setData({
        loginMode: mode === 'production' ? 'production' : 'unified',
        account_error: false,
        password_error: false,
        errorMessage: '',
      });
    },

    closeTank() {
      if (this.data.loading) return;
      if (!this.data.show) {
        this.open();
      } else {
        this.setData({
          show: false,
          password: '',
          activeField: '',
          account_error: false,
          password_error: false,
          errorMessage: '',
        });
      }
    },

    onAccountFocus() {
      this.setData({ activeField: 'account' });
    },

    onAccountBlur(e) {
      const value = e && e.detail && typeof e.detail.value === 'string' ? e.detail.value : this.data.account;
      this.setData({
        activeField: this.data.activeField === 'account' ? '' : this.data.activeField,
        account: value,
      });
    },

    onAccountInput(e) {
      const value = (e && e.detail && typeof e.detail.value === 'string') ? e.detail.value : '';
      const next = { account: value };
      if (this.data.account_error && value.trim()) next.account_error = false;
      if (this.data.errorMessage) next.errorMessage = '';
      this.setData(next);
    },

    clearAccount() {
      if (this.data.loading) return;
      this.setData({ account: '', account_error: false, errorMessage: '' });
    },

    fillLastAccount() {
      if (this.data.loading || !this.data.lastAccount) return;
      this.setData({
        account: this.data.lastAccount,
        account_error: false,
        errorMessage: '',
      });
    },

    onPasswordFocus() {
      this.setData({ activeField: 'password' });
    },

    onPasswordBlur(e) {
      const value = e && e.detail && typeof e.detail.value === 'string' ? e.detail.value : this.data.password;
      this.setData({
        activeField: this.data.activeField === 'password' ? '' : this.data.activeField,
        password: value,
      });
    },

    onPasswordInput(e) {
      const value = (e && e.detail && typeof e.detail.value === 'string') ? e.detail.value : '';
      const next = { password: value };
      if (this.data.password_error && value) next.password_error = false;
      if (this.data.errorMessage) next.errorMessage = '';
      this.setData(next);
    },

    clearPassword() {
      if (this.data.loading) return;
      this.setData({ password: '', password_error: false, errorMessage: '' });
    },

    togglePassword() {
      this.setData({ showPassword: !this.data.showPassword });
    },

    onChooseAvatar(e) {
      this.setData({
        'userInfo.avatar': e.detail.avatarUrl
      });
    },

    getNickName(e) {
      this.setData({
        'userInfo.nickName': e.detail.value
      });
    },

    showErrorFeedback(message) {
      this.setData({ errorMessage: message });
      try {
        Notify({ type: 'danger', zIndex: 10001, message });
      } catch (err) {}
      wx.showToast({
        title: message.length > 18 ? message.slice(0, 18) + '...' : message,
        icon: 'none',
        duration: 2400
      });
    },

    /**
     * 提交登录
     */
    submit() {
      if (this.data.loading) return;
      let that = this;
      const account = (that.data.account || '').trim();
      const password = that.data.password || '';
      const accountError = !account;
      const passwordError = !password;

      if (accountError || passwordError) {
        const msg = accountError && passwordError
          ? '请输入登录账号和密码'
          : accountError
            ? '请输入登录账号'
            : '请输入登录密码';
        that.setData({
          account_error: accountError,
          password_error: passwordError,
        });
        that.showErrorFeedback(msg);
        return;
      }

      that.setData({
        loading: true,
        activeField: '',
        account_error: false,
        password_error: false,
        errorMessage: '',
      });
      wx.showLoading({
        title: '正在登录',
        mask: true
      });
      const finish = function () {
        wx.hideLoading();
        that.setData({ loading: false });
      };
      // 从同步调用到网络失败共用收尾，不依赖客户端 Promise.finally 支持。
      return Promise.resolve().then(() => that.data.loginMode === 'production'
        ? erpAuth.login(account, password).then(erpResult => ({ erpResult }))
        : that.unifiedLogin(account, password))
        .then(({ erpResult, legacyResult }) => that.saveSession(erpResult, legacyResult, account))
        .then(finish)
        .catch(function (error) {
          finish();
          const message = (error && (error.message || error.errMsg)) || (typeof error === 'string' && error) || '登录失败，请重试';
          that.showErrorFeedback(message);
        });
    },

    unifiedLogin(account, password) {
      let stage = '微信凭证获取';
      return new Promise((resolve, reject) => {
        const timer = setTimeout(() => reject(new Error('请求超时，请重试')), 10000);
        try {
          wx.login({
            timeout: 10000,
            success: (res) => {
              clearTimeout(timer);
              if (!res || !res.code) {
                reject(new Error('微信登录凭证获取失败'));
                return;
              }
              resolve(res.code);
            },
            fail: (error) => {
              clearTimeout(timer);
              reject(error);
            }
          });
        } catch (error) {
          clearTimeout(timer);
          reject(error);
        }
      }).then(code => {
        stage = '旧系统认证';
        return util.request(api.UserLogin, { code, account, password, is_erp: 1 }, 'POST', 'application/x-www-form-urlencoded', 0, { loading: false, timeout: 15000 });
      }).then(legacyResult => {
        if (!legacyResult || legacyResult.code != 1) {
          throw new Error((legacyResult && legacyResult.msg) || '账号或密码错误');
        }
        if (!legacyResult.data || !legacyResult.data.token || !legacyResult.data.staff_info || !legacyResult.data.staff_info.user_id) {
          throw new Error('未返回有效登录信息，请重试');
        }
        const ticket = legacyResult.data.erp_sso_ticket;
        if (!ticket) {
          throw new Error('旧 ERP 未返回统一登录票据，请确认认证服务已更新');
        }
        stage = '新 ERP 认证';
        return erpAuth.sso(ticket).then(erpResult => ({ erpResult, legacyResult }));
      }).catch(error => {
        const message = (error && (error.message || error.errMsg)) || (typeof error === 'string' && error) || '请重试';
        throw new Error(`${stage}失败：${message}`);
      });
    },

    saveSession(erpResult, legacyResult, account) {
      if (!erpResult || !erpResult.token || !erpResult.user || !erpResult.user.legacy_id) {
        throw new Error('新 ERP 认证失败：未返回有效登录信息，请重试');
      }
      return erpAuth.logoutAll().then(() => {
        try {
          if (legacyResult) {
            const legacyUser = legacyResult.data.staff_info || {};
            legacyUser.is_login = 1;
            wx.setStorageSync('userInfo', legacyUser);
            wx.setStorageSync('token', legacyResult.data.token);
          }
          erpAuth.persistSession(erpResult);
          if (account) {
            wx.setStorageSync('last_login_account', account);
          }
        } catch (storageError) {
          wx.removeStorageSync('userInfo');
          wx.removeStorageSync('token');
          erpAuth.clearSession();
          throw new Error('保存登录信息失败');
        }
        this.setData({
          show: false,
          userInfo_tank: false,
          password: '',
          lastAccount: account || this.data.lastAccount,
          errorMessage: '',
        });
        this.getUserInfo();
      });
    },

    async getUserInfo() {
      util.refreshPage();
      try {
        Notify({ type: 'success', zIndex: 10001, message: '登录成功' });
      } catch (err) {}
    }
  },
});
