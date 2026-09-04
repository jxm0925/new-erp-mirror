const util = require('../../../../utils/util.js');
const api = require('../../../../config/api.js');

Page({
  data: { banners: [], templateId: '', recordId: 0, deadlineText: '', formConfig: [], advancedConfig: {}, formData: {}, showTargetPopup: false, userList: [], filteredUserList: [], searchKeyword: '', tempSelectedTargets: [], selectedTargetIds: [], selectedTargetNames: '', historyList: [], showHistory: false },
  
  onLoad(options) {
    if (options.templateId) {
      this.setData({ templateId: options.templateId, recordId: options.recordId || 0 });
      wx.setNavigationBarTitle({ title: options.title || '工作汇报' });
      this.initPageData(options.templateId, options.recordId);
      this.fetchUserList();
      this.fetchBanners();
    }
  },

  initPageData(templateId, explicitRecordId) {
    wx.showLoading({ title: '加载中...' });
    util.request(api.getTemplateDetail, { id: templateId }, 'GET').then(res => {
      if (res.code !== 1) return wx.hideLoading();
      let config = typeof res.data.form_config === 'string' ? JSON.parse(res.data.form_config) : (res.data.form_config || []);
      let advanced = typeof res.data.advanced_config === 'string' ? JSON.parse(res.data.advanced_config) : (res.data.advanced_config || {});
      util.request(api.checkTodayRecord, { template_id: templateId, record_id: explicitRecordId || 0 }, 'GET').then(recordRes => {
        wx.hideLoading();
        const hasRecord = recordRes.code === 1;
        const recordData = recordRes.data || {};
        if (explicitRecordId && hasRecord) {
          if (recordData.status && recordData.status !== 'pending') return wx.showModal({ title: '提示', content: '领导已查阅，无法修改。', showCancel: false, confirmColor: '#ff4d4d', success: () => { wx.navigateBack(); } });
          return this.loadRecordData(recordData, advanced, config);
        }
        if (hasRecord && !advanced.rules?.allowRepeat) return wx.showModal({ title: '提示', content: '今日已提交，不可重复。', showCancel: false, confirmColor: '#ff4d4d', success: () => { wx.navigateBack(); } });
        this.checkDraftAndInit(config, advanced);
      });
    });
  },

  // 💥 满血版恢复：连带汇报对象一起恢复 💥
  checkDraftAndInit(config, advanced) {
    const draftRaw = wx.getStorageSync(`draft_template_${this.data.templateId}`);
    if (draftRaw && Object.keys(draftRaw).length > 0) {
      wx.showModal({ title: '提示', content: '发现您有未提交的草稿，是否恢复？', confirmColor: '#ff4d4d', success: (res) => {
          if (res.confirm) {
            wx.showLoading({ title: '恢复草稿中...', mask: true });
            
            // 兼容老版本的纯表单草稿 和 新版本的全状态草稿
            let draftFormData = draftRaw.formData ? draftRaw.formData : draftRaw;
            let draftTargetIds = draftRaw.selectedTargetIds || [];
            let draftTargetNames = draftRaw.selectedTargetNames || '';

            setTimeout(() => { 
              this.setData({ 
                formConfig: config, 
                advancedConfig: advanced, 
                deadlineText: advanced.rules?.deadline || '', 
                formData: draftFormData,
                selectedTargetIds: draftTargetIds,
                selectedTargetNames: draftTargetNames, 
                recordId: 0 
              }); 
              wx.hideLoading(); 
              this.fetchHistoryForView(this.data.templateId); 
            }, 300);
          } else {
            wx.removeStorageSync(`draft_template_${this.data.templateId}`);
            this.prepareAutoFillOrEmpty(config, advanced);
          }
        }
      });
    } else {
      this.prepareAutoFillOrEmpty(config, advanced);
    }
  },

  prepareAutoFillOrEmpty(config, advanced) {
    let initialData = {};
    config.forEach((item, index) => {
      if (item.type === 'detail_list') { let emptyRow = {}; item.children.forEach((child, cIdx) => { emptyRow['child_' + cIdx] = ''; }); initialData['field_' + index] = [emptyRow]; } 
      else if (item.type === 'image') { initialData['field_' + index] = []; } 
      else { initialData['field_' + index] = ''; }
    });
    util.request(api.getHistoryRecord, { template_id: this.data.templateId, time_offset: 'last' }, 'GET').then(res => {
      if (res.code === 1 && res.data) {
        let snapshot = res.data.template_snapshot || [], values = res.data.form_values || {};
        config.forEach((item, index) => {
          if (item.autoFill && item.autoFill.sourceLabel) {
            let sIdx = snapshot.findIndex(s => s.label.includes(item.autoFill.sourceLabel));
            if (sIdx !== -1 && values['field_' + sIdx]) {
              initialData['field_' + index] = values['field_' + sIdx].map(old => { 
                let n = {}; n['child_0'] = old['child_0'] || ''; 
                item.children.forEach((_, ci) => { if(ci>0) n['child_'+ci] = ''; });
                return n;
              });
            }
          }
        });
      }
      this.setData({ formConfig: config, advancedConfig: advanced, deadlineText: advanced.rules?.deadline || '', formData: initialData });
      this.fetchHistoryForView(this.data.templateId);
    });
  },

  loadRecordData(record, advanced, fallbackConfig) {
    let savedData = typeof record.form_data === 'string' ? JSON.parse(record.form_data) : record.form_data;
    this.setData({ formConfig: savedData.template_snapshot || fallbackConfig, advancedConfig: advanced, recordId: record.id, formData: savedData.form_values || {} });
    this.fetchHistoryForView(this.data.templateId);
  },

  onInputChange(e) { this.setData({ [`formData.field_${e.currentTarget.dataset.index}`]: e.detail }); },
  onDetailInputChange(e) { 
    const { pindex, dindex, cindex } = e.currentTarget.dataset; 
    const val = (e.detail && e.detail.value !== undefined) ? e.detail.value : e.detail;
    this.setData({ [`formData.field_${pindex}[${dindex}].child_${cindex}`]: val }); 
  },  
  onCustomRadioClick(e) { const { pindex, dindex, cindex, val } = e.currentTarget.dataset; this.setData({ [`formData.field_${pindex}[${dindex}].child_${cindex}`]: val }); },
  onCascadeInputChange(e) { const { pindex, dindex, cindex } = e.currentTarget.dataset; this.setData({ [`formData.field_${pindex}[${dindex}].cascade_${cindex}`]: e.detail.value }); },

  onToggleRowNone(e) {
    const { pindex, dindex } = e.currentTarget.dataset;
    const currentVal = this.data.formData['field_'+pindex][dindex]['child_0'];
    if (currentVal === 'nothing') {
      this.setData({ [`formData.field_${pindex}[${dindex}].child_0`]: '' });
    } else {
      let updates = {}; updates[`formData.field_${pindex}`] = [{ child_0: 'nothing' }];
      this.data.formConfig[pindex].children.forEach((_, i) => { if(i>0) updates[`formData.field_${pindex}[0].child_${i}`] = ''; });
      this.setData(updates);
    }
  },

  addDetailItem(e) {
    const pindex = e.currentTarget.dataset.pindex; let list = this.data.formData['field_'+pindex] || [];
    let row = {}; this.data.formConfig[pindex].children.forEach((_, i) => row['child_'+i] = '');
    list.push(row); this.setData({ [`formData.field_${pindex}`]: list });
  },
  removeDetailItem(e) {
    const { pindex, dindex } = e.currentTarget.dataset; let list = this.data.formData['field_'+pindex];
    list.splice(dindex, 1); this.setData({ [`formData.field_${pindex}`]: list });
  },

  fetchHistoryForView(templateId) {
    util.request(api.getHistoryRecord, { template_id: templateId, time_offset: 'last' }, 'GET').then(res => {
      if (res.code === 1 && res.data) {
        let snapshot = res.data.template_snapshot || [], values = res.data.form_values || {};
        this.setData({ historyList: snapshot.map((item, index) => { return { ...item, value: values['field_' + index] }; }) });
      }
    });
  },
  openHistory() { this.setData({ showHistory: true }); },
  closeHistory() { this.setData({ showHistory: false }); },

  afterRead(e) {
    const { file } = e.detail; const index = e.currentTarget.dataset.index;
    wx.showLoading({ title: '图片上传中...' });
    util.request(api.getOssSign, {}, 'GET').then(res => {
      const signData = res.data; const fileName = `${signData.dir}${Date.now()}.png`;
      wx.uploadFile({ url: signData.host, filePath: file.url, name: 'file', formData: { 'key': fileName, 'policy': signData.policy, 'OSSAccessKeyId': signData.accessid, 'signature': signData.signature },
        success: () => { wx.hideLoading(); let list = this.data.formData['field_'+index] || []; list.push({ url: `${signData.host}/${fileName}` }); this.setData({ [`formData.field_${index}`]: list }); }
      });
    });
  },
  deleteImage(e) { const { index } = e.detail; const fIdx = e.currentTarget.dataset.index; let list = this.data.formData['field_'+fIdx]; list.splice(index, 1); this.setData({ [`formData.field_${fIdx}`]: list }); },

  fetchUserList() { util.request(api.getUserDict).then(res => { let users = res.data.map(u => ({ id: String(u.id), name: u.nickname })); this.setData({ userList: users, filteredUserList: users }); }); },
  updateSelectedNames(ids) { this.setData({ selectedTargetNames: this.data.userList.filter(u => ids.includes(u.id)).map(u => u.name).join('，') }); },
  openTargetPopup() { this.setData({ showTargetPopup: true, tempSelectedTargets: this.data.selectedTargetIds }); },
  confirmTarget() { this.setData({ selectedTargetIds: this.data.tempSelectedTargets, showTargetPopup: false }); this.updateSelectedNames(this.data.tempSelectedTargets); },
  onTargetChange(e) { this.setData({ tempSelectedTargets: e.detail }); },
  toggleTarget(e) { let id = e.currentTarget.dataset.name, list = this.data.tempSelectedTargets; if(list.includes(id)) list = list.filter(i => i!==id); else list.push(id); this.setData({ tempSelectedTargets: list }); },
  closeTargetPopup() { this.setData({ showTargetPopup: false }); },
  onSearchTarget(e) { let k = e.detail; this.setData({ filteredUserList: k ? this.data.userList.filter(u => u.name.includes(k)) : this.data.userList }); },

  // 💥 满血版存档：保存所有数据维度 💥
  saveDraft() { 
    if (!this.data.templateId) return; 
    const draftData = {
      formData: this.data.formData,
      selectedTargetIds: this.data.selectedTargetIds,
      selectedTargetNames: this.data.selectedTargetNames
    };
    wx.setStorageSync(`draft_template_${this.data.templateId}`, draftData); 
    wx.showToast({ title: '草稿保存成功', icon: 'success' }); 
  },
  
  submitReport() {
    const { formConfig, formData, templateId, recordId, selectedTargetIds } = this.data;
    
    // 基础防呆校验
    if (this.data.advancedConfig.rules && this.data.advancedConfig.rules.allowManualTarget && selectedTargetIds.length === 0) return wx.showToast({ title: '请选择汇报给谁', icon: 'none' });
    
    let requestPayload = { template_id: templateId, record_id: recordId, form_data: JSON.stringify({ template_snapshot: formConfig, form_values: formData }), handler_ids: selectedTargetIds.join(',') };
    
    wx.showLoading({ title: '提交中...' });
    util.request(api.submitReport, requestPayload, 'POST').then(res => {
      wx.hideLoading(); 
      if (res.code === 1) { 
        wx.removeStorageSync(`draft_template_${templateId}`); // 提交成功后自动清理草稿
        wx.showToast({ title: '成功' }); 
        setTimeout(() => wx.navigateBack(), 1500); 
      }
    });
  },

  fetchBanners() { util.request(api.getReportBanners).then(res => { if (res.code === 1) { this.setData({ banners: res.data || [] }); } }); },
  onRateChange(e) { this.setData({ [`formData.field_${e.currentTarget.dataset.index}`]: e.detail }); }
});