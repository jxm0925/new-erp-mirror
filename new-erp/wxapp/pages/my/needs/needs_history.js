const util = require('../../../utils/util.js');
const api = require('../../../config/api.js');

Page({
  data: {
    customerName: '',
    historyList: [], // 包含了所有的骨架和数据
    activeIndex: 0,  // 当前高亮的版本索引，0 默认最新版
    renderList: []   // 拼装后显示的数据
  },

  onLoad(options) {
    if (options.id) {
      if (options.name) {
        this.setData({ customerName: decodeURIComponent(options.name) });
      }
      
      this.fetchFullHistory(options.id);
    } else {
      wx.showToast({ title: '缺少档案ID', icon: 'none' });
    }
  },

  fetchFullHistory(id) {
    wx.showLoading({ title: '时空穿梭中...' });

    util.request(api.GetCustomerHistory, { id: id }, 'GET').then(res => {
      wx.hideLoading();
      if (res.code === 1 && res.data.length > 0) {
        this.setData({ historyList: res.data });
        
        // 如果上面没有传 name，就用接口返回的精准真实名字兜底展示
        if (!this.data.customerName) {
           this.setData({ customerName: res.data[0].customer_name });
        }
        
        // 默认一进来渲染第 0 个（最新版本）
        this.switchVersionCore(0);
      } else {
        wx.showToast({ title: '获取档案失败', icon: 'none' });
      }
    }).catch(() => {
      wx.hideLoading();
    });
  },

  switchVersion(e) {
    const index = e.currentTarget.dataset.index;
    if (this.data.activeIndex === index) return; // 点自己不刷新
    
    // 给个极短的加载提示，体验更高级
    wx.showLoading({ title: '版本切换中...', mask: true });
    setTimeout(() => {
      this.switchVersionCore(index);
      wx.hideLoading();
    }, 200);
  },

  switchVersionCore(index) {
    const record = this.data.historyList[index];
    const snapshot = record.form_snapshot || [];
    const values = record.form_data || {};

    // 完美融合骨架和数据
    let list = snapshot.map((item, i) => {
      let val = values[item.id] || ''; // 注意：这里你存的时候用的是 item.id，那就取 item.id
      return { ...item, value: val };
    });

    this.setData({ 
      activeIndex: index,
      renderList: list 
    });
  }
});