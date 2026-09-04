const util = require('../../../../utils/util.js');
const api = require('../../../../config/api.js');

Page({
  data: {
    templateList: [],
    page: 1,
    limit: 10,
    hasMore: true,
    isLoading: false
  },

  onShow() {
    console.log('【页面 onShow】开始初始化列表...');
    this.setData({ page: 1, templateList: [], hasMore: true });
    this.fetchManageList();
  },

  // ===== 核心：修复了 WXML 绑定的触底事件 =====
  loadMore() {
    console.log('【触发了触底加载 loadMore】');
    this.fetchManageList();
  },

  fetchManageList() {
    // 1. 拦截防抖
    if (!this.data.hasMore || this.data.isLoading) {
      console.log('【拦截请求】没有更多数据了，或者正在加载中...');
      return;
    }
    
    // 2. 致命错误拦截：检查 api 是否配好了！
    if (!api.getTemplateList) {
      wx.hideLoading();
      wx.showToast({ title: 'api.js未配置 getTemplateList', icon: 'none' });
      console.error('【致命错误】：api.getTemplateList 是 undefined，请检查 config/api.js！');
      return;
    }

    this.setData({ isLoading: true });
    wx.showNavigationBarLoading();
    
    console.log('【准备发送真实网络请求】 -> URL:', api.getTemplateList, '参数:', { page: this.data.page, limit: this.data.limit });

    // 3. 发送请求
    util.request(api.getTemplateList, {
      page: this.data.page,
      limit: this.data.limit
    }, 'GET').then(res => {
      console.log('【接口请求成功返回】:', res);
      wx.hideNavigationBarLoading();
      this.setData({ isLoading: false });

      if (res.code === 1) {
        const newList = res.data.list || [];
        
        // 解析 JSON 数据，计算展示信息
        newList.forEach(item => {
          let formConfig = [];
          let advancedConfig = {};
          
          try {
            formConfig = typeof item.form_config === 'string' ? JSON.parse(item.form_config) : (item.form_config || []);
            advancedConfig = typeof item.advanced_config === 'string' ? JSON.parse(item.advanced_config) : (item.advanced_config || {});
          } catch (e) { 
            console.error('JSON解析异常', e); 
          }

          item.fieldCount = formConfig.length || 0;
          item.periodName = (advancedConfig.rules && advancedConfig.rules.periodName) ? advancedConfig.rules.periodName : '按天';
        });

        this.setData({
          templateList: this.data.templateList.concat(newList),
          hasMore: newList.length === this.data.limit,
          page: this.data.page + 1
        });
      }
    }).catch(err => {
      console.error('【接口请求彻底报错炸了】:', err);
      wx.hideNavigationBarLoading();
      this.setData({ isLoading: false });
    });
  },

  // ===== 新建与编辑 =====
  goToAdd() {
    wx.navigateTo({ url: '/pages/my/report/manage/edit' });
  },

  goToEdit(e) {
    const id = e.currentTarget.dataset.id || '';
    wx.navigateTo({ url: `/pages/my/report/manage/edit?id=${id}` });
  },

  // ===== 左滑：停用/启用表单 =====
  toggleStatus(e) {
    const id = e.currentTarget.dataset.id;
    const currentStatus = e.currentTarget.dataset.status;
    const targetStatus = currentStatus === 'hidden' ? 'normal' : 'hidden';
    const actionText = currentStatus === 'hidden' ? '启用' : '停用';

    wx.showModal({
      title: '提示',
      content: `确定要${actionText}该表单吗？`,
      confirmColor: '#ff4d4d',
      success: (res) => {
        if (res.confirm) {
          util.request(api.changeTemplateStatus, { id: id, status: targetStatus }, 'POST').then(res => {
            if (res.code === 1) {
              wx.showToast({ title: '操作成功', icon: 'success' });
              this.onShow(); // 暴力刷新当前页
            }
          });
        }
      }
    });
  },

  // ===== 左滑：删除表单 =====
  deleteTemplate(e) {
    const id = e.currentTarget.dataset.id;
    wx.showModal({
      title: '警告',
      content: '删除后无法恢复，确定要删除吗？',
      confirmColor: '#ff4d4d',
      success: (res) => {
        if (res.confirm) {
          util.request(api.delTemplate, { id: id }, 'POST').then(res => {
            if (res.code === 1) {
              wx.showToast({ title: '已删除', icon: 'success' });
              this.onShow();
            } else {
              wx.showToast({ title: res.msg || '删除失败', icon: 'none' });
            }
          });
        }
      }
    });
  }
})