const util = require('../../../../utils/util.js'); 
const api = require('../../../../config/api.js');

Page({
  data: {
    recordId: 0,
    detail: {},      
    renderList: [],
    
    currentUserId: 0,
    reviews: [],
    showReview: false,
    reviewContent: '',
    canReview: false,

    showPoints: false,
    pointsValue: 1,
    pointsRemark: '',
    is_super_admin: false,
  },

  onLoad(options) {
    const userInfo = wx.getStorageSync('userInfo') || {};
    console.log(userInfo);
    this.setData({ 
      currentUserId: userInfo.user_id || 0 ,
      is_super_admin: userInfo.is_super_admin == 1 || userInfo.is_super_admin === true
    });

    if (options.id) {
      this.setData({ recordId: options.id });
      this.fetchDetail(options.id);
      this.fetchReviews(); 
    }
  },

  fetchDetail(id) {
    wx.showLoading({ title: '加载中...' });

    util.request(api.getReportDetail, { id: id }, 'GET').then(res => {
      wx.hideLoading();
      if (res.code === 1) {
        let record = res.data;
        console.log(res.data)
        let handlerIds = record.current_handler_ids ? String(record.current_handler_ids).split(',') : [];
        let hasPermission = handlerIds.includes(String(this.data.currentUserId));

        this.setData({ 
          detail: record,
          canReview: hasPermission 
        });
        this.buildRenderList(record.form_data);
      } else {
        wx.showToast({ title: res.msg || '获取失败', icon: 'none' });
      }
    }).catch(err => {
      wx.hideLoading();
      console.error('获取详情异常:', err);
    });
  },

  buildRenderList(formDataRaw) {
    let savedData = {};
    try {
      savedData = typeof formDataRaw === 'string' ? JSON.parse(formDataRaw) : formDataRaw;
    } catch(e) {
      console.error('解析快照失败', e);
      return;
    }

    let snapshot = savedData.template_snapshot || [];
    let values = savedData.form_values || {};

    let list = snapshot.map((item, index) => {
      let val = values['field_' + index];
      return {
        ...item,
        value: val
      };
    });

    this.setData({ renderList: list });
  },

  previewImage(e) {
    const currentUrl = e.currentTarget.dataset.url;
    const rawUrls = e.currentTarget.dataset.urls || [];
    const urls = rawUrls.map(item => item.url);

    wx.previewImage({ current: currentUrl, urls: urls });
  },

  fetchReviews() {
    util.request(api.getReviews, { record_id: this.data.recordId }, 'GET').then(res => {
      if (res.code === 1) {
        this.setData({ reviews: res.data });
      }
    });
  },

  showReviewPopup() { this.setData({ showReview: true, reviewContent: '' }); },
  hideReviewPopup() { this.setData({ showReview: false }); },
  onReviewInput(e) { this.setData({ reviewContent: e.detail.value }); },

  submitReview() {
    if (!this.data.reviewContent.trim()) {
      return wx.showToast({ title: '内容不能为空', icon: 'none' });
    }
    
    wx.showLoading({ title: '提交中...', mask: true });
    util.request(api.addReview, {
      record_id: this.data.recordId,
      content: this.data.reviewContent
    }, 'POST').then(res => {
      wx.hideLoading();
      if (res.code === 1) {
        wx.showToast({ title: '处理成功', icon: 'success' });
        this.setData({ showReview: false, reviewContent: '' });
        this.fetchReviews(); 
      } else {
        wx.showToast({ title: res.msg || '提交失败', icon: 'none' });
      }
    });
  },

  showPointsPopup() { 
    this.setData({ showPoints: true, pointsValue: 1 }); 
  },
  hidePointsPopup() { 
    this.setData({ showPoints: false }); 
  },
  onPointsChange(e) { 
    this.setData({ pointsValue: e.detail }); 
  },

  onPointsRemarkInput(e) {
    this.setData({ pointsRemark: e.detail.value });
  },

  submitPoints() {
    const points = this.data.pointsValue;
    const remark = this.data.pointsRemark;

    if (!points || points < 1 || points > 10) {
      return wx.showToast({ title: '请填写正确的积分(1-10)', icon: 'none' });
    }

    wx.showLoading({ title: '加分中...', mask: true });
    
    util.request(api.addReportPoints, { 
      record_id: this.data.recordId,
      points: points,
      remark: remark 
    }, 'POST').then(res => {
      wx.hideLoading();
      if (res.code === 1) {
        wx.showToast({ title: '加分成功！', icon: 'success' });
        this.setData({ showPoints: false });
        this.fetchDetail(this.data.recordId);
        this.fetchReviews();
      } else {
        wx.showToast({ title: res.msg || '加分失败', icon: 'none' });
      }
    }).catch(err => {
      wx.hideLoading();
      console.error(err);
      wx.showToast({ title: '网络异常', icon: 'none' });
    });
  }
});