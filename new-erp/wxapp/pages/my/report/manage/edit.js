const util = require('../../../../utils/util.js');
const api = require('../../../../config/api.js');

const ITEM_HEIGHT = 115; 
const sysInfo = wx.getSystemInfoSync();
const WINDOW_HEIGHT = sysInfo.windowHeight;

Page({
  data: {
    templateId: '', 
    templateData: { name: '', icon: 'https://dummyimage.com/100/ffffff/ff4d4d&text=表', bg_color: '#ff4d4d' },
    baseControls: [
      { type: 'text', name: '单行文本', icon: 'font-o' },
      { type: 'textarea', name: '多行文本', icon: 'notes-o' },
      { type: 'image', name: '图片', icon: 'photo-o' },
      { type: 'detail_list', name: '明细列表', icon: 'bars' },
      { type: 'rate', name: '评分', icon: 'star-o' }
    ],
    formConfig: [],
    advancedConfig: null, 

    showControlPicker: false,
    showFieldConfig: false,
    editingIndex: -1,
    editingField: {},

    // ===== 拖拽及滚动引擎核心变量 =====
    dragIndex: -1,
    startY: 0,
    startTop: 0,
    scrollTop: 0,       
    startScrollTop: 0,  

    showIconPicker: false,
    tempColor: '',
    tempIcon: '',
    colorList: ['#ff4d4d', '#ff9500', '#34c759', '#007AFF', '#5856D6', '#8e8e93'],
    iconList: [
      'https://media.sdjiantan.com/wechat/daily_report.png',
      'https://media.sdjiantan.com/wechat/sale_daily_report.png',
      'https://dummyimage.com/100/ffffff/34c759&text=报'
    ]
  },

  onPageScroll(e) {
    this.setData({ scrollTop: e.scrollTop });
  },

  onLoad(options) {
    if (options.id) {
      this.setData({ templateId: options.id });
      wx.setNavigationBarTitle({ title: '编辑表单' });
      this.fetchTemplateDetail(options.id);
    } else {
      wx.setNavigationBarTitle({ title: '创建表单' });
    }
  },

  fetchTemplateDetail(id) {
    var that = this;
    wx.showLoading({ title: '加载中...' });
    util.request(api.getTemplateDetail, { id: id }, 'GET').then(function(res) {
      wx.hideLoading();
      if (res.code === 1) { 
        var data = res.data;
        that.setData({
          templateData: { name: data.name, icon: data.icon, bg_color: data.bg_color },
          advancedConfig: data.advanced_config || null
        });
        that.formatFields(data.form_config || []);
      } else {
        wx.showToast({ title: res.msg || '获取表单失败', icon: 'none' });
      }
    }).catch(function(err) {
      wx.hideLoading();
      console.error('拉取模板详情报错:', err);
    });
  },

  onNameChange(e) { this.setData({ 'templateData.name': e.detail }); },
  openControlPicker() { this.setData({ showControlPicker: true }); },
  closeControlPicker() { this.setData({ showControlPicker: false }); },

  addField(e) {
    const type = e.currentTarget.dataset.type;
    const controlItem = this.data.baseControls.find(c => c.type === type);
    
    let newField = {
      type: type, typeName: controlItem.name, iconName: controlItem.icon, label: controlItem.name,
      required: false, print: true
    };
    if (type === 'text') { newField.placeholder = '请输入内容'; newField.scan = false; } 
    else if (type === 'textarea') { newField.placeholder = '请输入内容'; newField.maxLength = ''; } 
    else if (type === 'image') { newField.placeholder = '添加图片'; newField.onlyCamera = false; } 
    else if (type === 'detail_list') {
      newField.actionName = '新增明细';
      newField.children = [{ type: 'text', label: '单行文本', placeholder: '请输入', required: true }];
      newField.autoFill = { sourceLabel: '', timeOffset: 'last' };
    }else if (type === 'rate') { 
      newField.placeholder = '五星评分'; 
    }

    let currentFields = [...this.data.formConfig, newField];
    this.formatFields(currentFields);
    this.setData({ showControlPicker: false });
    
    setTimeout(() => { wx.pageScrollTo({ scrollTop: 9999, duration: 300 }); }, 100);
  },

  // 💥 完美融合版的 openFieldConfig (砍掉了时间选框) 💥
  openFieldConfig(e) {
    const index = e.currentTarget.dataset.index;
    let field = JSON.parse(JSON.stringify(this.data.formConfig[index]));

    // 专属赋能：如果打开的是【明细列表】，确保它拥有 autoFill 规则对象
    if (field.type === 'detail_list') {
      if (!field.autoFill) {
        field.autoFill = { sourceLabel: '', timeOffset: 'last' }; // 直接锁死为 last
      }
    }

    this.setData({ editingIndex: index, editingField: field, showFieldConfig: true });
  },
  
  closeFieldConfig() { this.setData({ showFieldConfig: false }); },
  
  onFieldInput(e) { const key = `editingField.${e.currentTarget.dataset.key}`; this.setData({ [key]: e.detail }); },
  onSwitchChange(e) { const key = `editingField.${e.currentTarget.dataset.key}`; this.setData({ [key]: e.detail }); },

  saveFieldConfig() {
    const idx = this.data.editingIndex;
    this.setData({ [`formConfig[${idx}]`]: this.data.editingField, showFieldConfig: false });
  },
  
  deleteCurrentField() {
    const newConfig = [...this.data.formConfig];
    newConfig.splice(this.data.editingIndex, 1);
    this.formatFields(newConfig);
    this.setData({ showFieldConfig: false });
  },

  openIconPicker() { this.setData({ showIconPicker: true, tempColor: this.data.templateData.bg_color, tempIcon: this.data.templateData.icon }); },
  closeIconPicker() { this.setData({ showIconPicker: false }); },
  selectColor(e) { this.setData({ tempColor: e.currentTarget.dataset.color }); },
  selectIcon(e) { this.setData({ tempIcon: e.currentTarget.dataset.icon }); },
  saveIconPicker() { this.setData({ 'templateData.bg_color': this.data.tempColor, 'templateData.icon': this.data.tempIcon, showIconPicker: false }); },
  uploadCustomIcon() {
    wx.chooseMedia({
      count: 1, mediaType: ['image'],
      success: (res) => {
        wx.showLoading({ title: '上传中...' });
        setTimeout(() => { wx.hideLoading(); const newOssUrl = 'https://dummyimage.com/100/ffffff/111111&text=新'; this.setData({ iconList: [newOssUrl, ...this.data.iconList], tempIcon: newOssUrl }); }, 1000);
      }
    });
  },
  goToAdvanced() { wx.navigateTo({ url: '/pages/my/report/manage/advanced' }); },
  previewForm() { wx.showToast({ title: '即将跳转填报页预览', icon: 'none' }); },

  // =========================================================
  // 💥 终极：触边自动滚屏引擎 + 动态坐标补偿 💥
  // =========================================================
  formatFields(configList) {
    let formatted = configList.map((item, index) => {
      return { ...item, uniqueId: item.uniqueId || ('f_' + Date.now() + '_' + index), tempIndex: index, top: index * ITEM_HEIGHT };
    });
    this.setData({ formConfig: formatted });
  },

  dragStart(e) {
    const index = e.currentTarget.dataset.index;
    wx.vibrateShort({ type: 'medium' }); 
    this.setData({
      dragIndex: index,
      startY: e.touches[0].clientY,
      startTop: this.data.formConfig[index].top,
      startScrollTop: this.data.scrollTop || 0
    });
    this.lastClientY = e.touches[0].clientY; 
  },

  updateDragPosition(clientY) {
    if (this.data.dragIndex === -1) return;
    
    let currentScrollTop = this.data.scrollTop || 0;
    let deltaY = (clientY - this.data.startY) + (currentScrollTop - this.data.startScrollTop);
    let newTop = this.data.startTop + deltaY;
    
    const maxTop = (this.data.formConfig.length - 1) * ITEM_HEIGHT;
    if (newTop < 0) newTop = 0;
    if (newTop > maxTop) newTop = maxTop;

    const currentTempIndex = Math.round(newTop / ITEM_HEIGHT);
    let fields = this.data.formConfig;
    fields[this.data.dragIndex].top = newTop;
    let draggedItemTempIndex = fields[this.data.dragIndex].tempIndex;
    
    if (currentTempIndex !== draggedItemTempIndex) {
      fields.forEach((item, idx) => {
        if (idx !== this.data.dragIndex) {
          if (draggedItemTempIndex < currentTempIndex) {
            if (item.tempIndex > draggedItemTempIndex && item.tempIndex <= currentTempIndex) {
              item.tempIndex--; item.top = item.tempIndex * ITEM_HEIGHT;
            }
          } else {
            if (item.tempIndex >= currentTempIndex && item.tempIndex < draggedItemTempIndex) {
              item.tempIndex++; item.top = item.tempIndex * ITEM_HEIGHT;
            }
          }
        }
      });
      fields[this.data.dragIndex].tempIndex = currentTempIndex;
      wx.vibrateShort({ type: 'light' });
    }
    this.setData({ formConfig: fields });
  },

  dragMove(e) {
    this.lastClientY = e.touches[0].clientY;
    this.updateDragPosition(this.lastClientY);

    if (this.lastClientY < 120) {
      this.startAutoScroll(-1);
    } else if (this.lastClientY > WINDOW_HEIGHT - 160) {
      this.startAutoScroll(1);
    } else {
      this.stopAutoScroll();
    }
  },

  startAutoScroll(direction) {
    if (this.scrollTimer) return;
    this.scrollTimer = setInterval(() => {
      let targetScrollTop = this.data.scrollTop + (direction * 15); 
      if (targetScrollTop < 0) targetScrollTop = 0;
      
      wx.pageScrollTo({ scrollTop: targetScrollTop, duration: 0 });
      this.updateDragPosition(this.lastClientY);
    }, 20); 
  },

  stopAutoScroll() {
    if (this.scrollTimer) {
      clearInterval(this.scrollTimer);
      this.scrollTimer = null;
    }
  },

  dragEnd(e) {
    this.stopAutoScroll(); 
    if (this.data.dragIndex === -1) return;
    
    let fields = this.data.formConfig;
    fields.sort((a, b) => a.tempIndex - b.tempIndex);
    fields.forEach((item, index) => {
      item.tempIndex = index;
      item.top = index * ITEM_HEIGHT;
    });
    this.setData({ formConfig: fields, dragIndex: -1 });
  },

  // =========================================================

  publishForm() {
    var that = this;
    if (!that.data.templateData.name || that.data.templateData.name.trim() === '') return wx.showToast({ title: '表单名称未填写', icon: 'none' });
    if (that.data.advancedConfig && that.data.advancedConfig.length === 0) return wx.showToast({ title: '请编辑审批流', icon: 'none' });
    if (that.data.formConfig.length === 0) return wx.showToast({ title: '请至少添加一个控件', icon: 'none' });

    let cleanConfig = that.data.formConfig.map(item => {
      let { uniqueId, tempIndex, top, ...pureData } = item;
      return pureData;
    });

    var payload = {
      id: that.data.templateId || 0, 
      name: that.data.templateData.name, icon: that.data.templateData.icon, bg_color: that.data.templateData.bg_color,
      form_config: JSON.stringify(cleanConfig), flow_config: JSON.stringify([]), advanced_config: JSON.stringify(that.data.advancedConfig || {}) 
    };

    console.log('【准备发射给后端的超级 JSON】:', payload);
    wx.showLoading({ title: '正在发布...', mask: true });
    util.request(api.saveTemplate, payload, 'POST').then(function(res) {
      wx.hideLoading();
      if (res.code === 1) { wx.showToast({ title: '发布成功', icon: 'success' }); setTimeout(function() { wx.navigateBack(); }, 1500); } 
      else { wx.showToast({ title: res.data.msg || '发布失败', icon: 'none' }); }
    }).catch(function(err) { wx.hideLoading(); console.error('发布接口异常:', err); wx.showToast({ title: '网络异常', icon: 'none' }); });
  }
})