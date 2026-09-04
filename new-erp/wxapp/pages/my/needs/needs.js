// 假设这里引入你的请求封装和接口配置（请根据你实际的项目路径修改）
const util = require('../../../utils/util.js');
const api = require('../../../config/api.js');

Page({
  data: {
    customerName: '',      // 客户/项目名称
    currentVersion: '1.0', // 当前版本号
    isIterating: false,    // 是否是迭代老客户
    formConfig: [],        // 后台动态下发的表单配置
    formData: {},          // 填写的表单数据

    // 智能联想下拉框专用
    showCustomerList: false, 
    customerOptions: [],     
    searchTimer: null,
    isSubmitting: false       
  },

  onLoad() {
    this.getFormTemplate();
  },

  // 1. 获取动态表单配置
  getFormTemplate() {
    wx.showLoading({ title: '加载配置中...' });
    
    // 【提醒】后续这里可以换成从后端接口拉取表单模板：
    // util.request(api.GetNeedsTemplate).then(res => { this.setData({ formConfig: res.data.config }); })
    
    // 暂时模拟后台下发的配置项
    setTimeout(() => {
      wx.hideLoading();
      this.setData({
        formConfig: [
          { id: 'water_source', type: 'radio', label: '原水水源', options: ['市政自来水', '地下井水', '河水/湖水', '工业废水'], required: true },
          { id: 'water_usage', type: 'text', label: '产水用途', placeholder: '如：直饮、锅炉补水、食品加工等', required: true },
          { id: 'flow_rate', type: 'number', label: '每小时用水量 (吨/T)', placeholder: '请输入数字 (T/H)', required: true },
          { id: 'tds_value', type: 'text', label: '原水水质 (TDS/硬度等)', placeholder: '有水质检测报告最佳' },
          { id: 'install_space', type: 'text', label: '安装场地尺寸 (长*宽*高)', placeholder: '评估设备能否放得下' },
          { id: 'special_needs', type: 'textarea', label: '特殊需求/备注', placeholder: '是否有指定品牌要求？交期要求等？' }
        ]
      });
    }, 300);
  },

  // 2. 客户名称输入防抖 & 智能联想搜索 (对接你的 search_my_customers 接口)
  onCustomerInput(e) {
    const val = e.detail;
    this.setData({ customerName: val });

    if (this.data.searchTimer) clearTimeout(this.data.searchTimer);

    if (!val.trim()) {
      this.setData({ 
        showCustomerList: false, 
        customerOptions: [],
        currentVersion: '1.0', // 瞬间退回 V1.0
        isIterating: false,    // 取消迭代状态
        formData: {}           // 瞬间清空所有已填、回填的表单数据，干干净净！
      });
      return;
    }

    // 防抖：停顿 500ms 后自动请求后台搜客户
    this.setData({
      searchTimer: setTimeout(() => {
        util.request(api.SearchMyCustomers, { keyword: val }, 'GET').then(res => {
          if (res.code === 1 && res.data && res.data.length > 0) {
            this.setData({ customerOptions: res.data, showCustomerList: true });
          } else {
            this.setData({ showCustomerList: false });
          }
        });
      }, 500)
    });
  },

  selectCustomer(e) {
    const name = e.currentTarget.dataset.name;
    this.setData({ 
      customerName: name, 
      showCustomerList: false // 隐藏下拉浮层
    });
    
    this.checkCustomerHistory(name);
  },

  checkCustomerHistory(name) {
    wx.showLoading({ title: '加载历史方案...' });
    
    util.request(api.CheckCustomerNeedsHistory, { customer_name: name }, 'GET').then(res => {
      wx.hideLoading();
      
      if (res.code === 1 && res.data && res.data.hasHistory) {
        let nextVersion = (res.data.latestVersion + 1.0).toFixed(1); 
        
        wx.showModal({
          title: '发现历史档案',
          content: `检索到该客户有 V${res.data.latestVersion} 版历史需求，是否需要一键回填老数据？\n(注意：回填将覆盖当前内容)`,
          confirmText: '一键回填',
          confirmColor: '#1989fa',
          cancelText: '保留当前',
          success: (modalRes) => {
            if (modalRes.confirm) {
              this.setData({ 
                currentVersion: nextVersion, 
                isIterating: true, 
                formData: res.data.latestData || {} // 把老数据拍进去！
              });
              wx.showToast({ title: '回填成功', icon: 'success' });
            } else {
              this.setData({ currentVersion: nextVersion, isIterating: true });
            }
          }
        });
      } else {
        // 全新客户
        this.setData({ currentVersion: '1.0', isIterating: false, formData: {} });
        wx.showToast({ title: '纯新客户，创建 V1.0', icon: 'success' });
      }
    }).catch(() => { wx.hideLoading(); });
  },

  onFieldInput(e) {
    const { id } = e.currentTarget.dataset;
    const val = (e.detail && e.detail.value !== undefined) ? e.detail.value : e.detail;
    this.setData({ [`formData.${id}`]: val });
  },

  onTagClick(e) {
    const { id, val } = e.currentTarget.dataset;
    this.setData({ [`formData.${id}`]: val });
  },

  onTagClear(e) {
    const { id } = e.currentTarget.dataset;
    this.setData({ [`formData.${id}`]: '' });
  },

  submitForm() {
    if (this.data.isSubmitting) return; // 防连击锁

    const { customerName, formConfig, formData, currentVersion } = this.data;
    
    if (!customerName.trim()) return wx.showToast({ title: '请先关联客户档案', icon: 'none' });

    // 必填项校验
    for (let i = 0; i < formConfig.length; i++) {
      let item = formConfig[i];
      // 兼容多行文本可能只有空格的情况
      if (item.required && (!formData[item.id] || String(formData[item.id]).trim() === '')) {
        return wx.showToast({ title: `请填写【${item.label}】`, icon: 'none' });
      }
    }

    // 咔嚓！立刻上锁！
    this.setData({ isSubmitting: true });
    wx.showLoading({ title: '提交中...', mask: true });

    const payload = {
      customer_name: customerName,
      version: currentVersion,
      snapshot: JSON.stringify(formConfig), 
      form_data: JSON.stringify(formData)   
    };
    // 发起请求
    util.request(api.SubmitCustomerNeeds, payload, 'POST').then(res => {
      wx.hideLoading();
      
      if (res.code === 1) {
        wx.showToast({ title: '提交完成！', icon: 'success', duration: 1500 });
        setTimeout(() => {
          this.resetForm();
        }, 1500);

      } else {
        // 接口报错，解锁让用户重试
        this.setData({ isSubmitting: false });
        wx.showToast({ title: res.msg || '保存失败', icon: 'none' });
      }
    }).catch(err => {
      wx.hideLoading();
      this.setData({ isSubmitting: false });
      wx.showToast({ title: '网络异常，请重试', icon: 'none' });
    });
  },

  resetForm() {
    this.setData({
      customerName: '',
      currentVersion: '1.0',
      isIterating: false,
      formData: {},
      showCustomerList: false, 
      customerOptions: [],
      isSubmitting: false
    });
    
    // 选做：可以平滑滚动回顶部，让体验更好
    wx.pageScrollTo({
      scrollTop: 0,
      duration: 300
    });
  },
  goToArchiveList() {
    wx.navigateTo({ url: '/pages/my/needs/needs_list' }); // 确保路径正确
  },
});