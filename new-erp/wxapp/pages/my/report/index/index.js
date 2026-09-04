const util = require('../../../../utils/util.js');
const api = require('../../../../config/api.js');

Page({
  data: {
    greeting: '',
    currentDate: '',
    userName: '加载中...',
    roleName: '员工',
    pendingCount: 0,
    submittedCount: 0,
    templateList: [],
    isLoading: true,
    staffInfo: {}
  },
  onShow() {
    this.initHeaderInfo();
    this.fetchAvailableTemplates();
    this.fetchStats(); 
  },

  // ===== 1. 动态生成头部信息 =====
  initHeaderInfo() {
    const hour = new Date().getHours();
    let greet = '晚上好';
    if (hour < 9) greet = '早上好';
    else if (hour < 12) greet = '上午好';
    else if (hour < 14) greet = '中午好';
    else if (hour < 18) greet = '下午好';

    const date = new Date();
    const dateStr = `${date.getFullYear()}年${date.getMonth() + 1}月${date.getDate()}日`;

    // 从本地缓存强取用户信息
    let userInfo = wx.getStorageSync('userInfo') || {};
    
    let currentName = userInfo.name || userInfo.nickname || '姓名获取中';
    let currentRole = userInfo.role_name || '成员'; 

    this.setData({
      greeting: greet,
      currentDate: dateStr,
      userName: currentName,
      roleName: currentRole,
      staffInfo: userInfo // 💥 绑定到界面 💥
    });
  },

  // ===== 2. 核心：从后端拉取首页看板统计数 =====
  fetchStats() {
    if (!api.getReportStats) return;
    
    util.request(api.getReportStats, {}, 'GET').then(res => {
      if (res.code === 1) {
        this.setData({
          pendingCount: res.data.pendingCount || 0,
          submittedCount: res.data.submittedCount || 0
        });
      }
    }).catch(err => {
      console.error('拉取统计数据失败:', err);
    });
  },

  // ===== 3. 核心：从后端拉取启用的表单九宫格 =====
  fetchAvailableTemplates() {
    if (!api.getAvailableTemplates) return;

    this.setData({ isLoading: true });
    
    util.request(api.getAvailableTemplates, {}, 'GET').then(res => {
      this.setData({ isLoading: false });
      if (res.code === 1) {
        this.setData({ templateList: res.data || [] });
      }
    }).catch(err => {
      this.setData({ isLoading: false });
      console.error('拉取模板列表失败:', err);
    });
  },

  // ===== 跳转事件：点击九宫格进表单 =====
  goToForm(e) {
    const { id, name } = e.currentTarget.dataset;
    wx.navigateTo({
      url: `/pages/my/report/form/form?templateId=${id}&title=${name}`
    });
  },

  // ===== 跳转事件：点击头部看板进列表 =====
  jumpToList(e) {
    const tab = e.currentTarget.dataset.tab;
    wx.navigateTo({
      url: `/pages/my/report/list/list?tab=${tab}`
    });
  },
  goGlobalStats() {
    const defaultTemplateId = 101; 
    const templateName = '工作日报'; 
    
    wx.navigateTo({
      url: `/pages/my/report/stats/stats?templateId=${defaultTemplateId}&title=${templateName}`
    });
  }
})