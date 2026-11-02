const warehouse = require('../../services/warehouse');

Component({
  properties: {
    value: { type: String, value: '' },
    placeholder: { type: String, value: '扫码或输入编码' },
    disabled: Boolean,
    focus: Boolean,
    compact: Boolean,
    types: { type: Array, value: [] },
    nextInput: { type: String, value: '' },
    clearAfterResolve: Boolean,
    raw: Boolean,
  },
  data: { inputValue: '', inputFocused: false, resolving: false, scanning: false },
  observers: {
    value(value) { this.setData({ inputValue: String(value || '') }); },
    focus(value) { this.setData({ inputFocused: Boolean(value) }); },
  },
  lifetimes: {
    attached() { this.live = true; },
    detached() { this.live = false; this.sequence = (this.sequence || 0) + 1; },
  },
  methods: {
    input(event) {
      this.setData({ inputValue: event.detail.value });
      this.triggerEvent('input', { value: event.detail.value });
    },
    confirm(event) { return this.resolveCode(event.detail.value); },
    focusInput() {
      if (!this.live || this.properties.disabled) return;
      this.setData({ inputFocused: false }, () => {
        if (this.live) this.setData({ inputFocused: true });
      });
    },
    focusNext() {
      const selector = this.properties.nextInput;
      const owner = typeof this.selectOwnerComponent === 'function' ? this.selectOwnerComponent() : null;
      const pages = getCurrentPages();
      const host = owner || pages[pages.length - 1];
      const next = selector && host && host.selectComponent(selector);
      if (next && typeof next.focusInput === 'function') next.focusInput();
      else this.focusInput();
      this.triggerEvent('nextfocus', { selector });
    },
    async resolveCode(value) {
      const code = String(value || '').trim();
      if (!code || this.properties.disabled || this.data.resolving) return;
      const sequence = this.sequence = (this.sequence || 0) + 1;
      const token = wx.getStorageSync('erp_token');
      const permissionSnapshot = JSON.stringify(wx.getStorageSync('erp_permissions') || []);
      let resolved = false;
      this.setData({ inputValue: code, resolving: true });
      try {
        const response = this.properties.raw ? { data: { type: 'raw', code } } : await warehouse.resolveScan(code);
        if (!this.live || sequence !== this.sequence || token !== wx.getStorageSync('erp_token') || permissionSnapshot !== JSON.stringify(wx.getStorageSync('erp_permissions') || [])) return;
        const result = response.data || response;
        if (!this.properties.raw && this.properties.types.length && !this.properties.types.includes(result.type)) throw new Error('该编码不适用于当前输入，请核对后重新扫描');
        this.setData({ inputValue: result.code || code });
        this.triggerEvent('resolved', { code, result });
        if (this.properties.clearAfterResolve) this.setData({ inputValue: '' });
        resolved = true;
      } catch (error) {
        const currentToken = wx.getStorageSync('erp_token');
        if (!this.live || sequence !== this.sequence || (token !== currentToken && !(error.statusCode === 401 && !currentToken))) return;
        const message = error.message || '编码解析失败，请重试';
        this.triggerEvent('error', { message, statusCode: error.statusCode, errorCode: error.errorCode });
        wx.showToast({ title: message, icon: 'none' });
      } finally {
        if (this.live && sequence === this.sequence) this.setData({ resolving: false }, () => {
          if (!this.live || token !== wx.getStorageSync('erp_token')) return;
          if (resolved) this.focusNext(); else this.focusInput();
        });
      }
    },
    scan() {
      if (this.properties.disabled || this.data.resolving || this.data.scanning) return;
      const pages = getCurrentPages(); const host = pages[pages.length - 1];
      // 相机返回先触发页面 onShow；解析完成前保留组件，避免刷新把本次扫码结果丢掉。
      if (host) { host._warehouseScanReturning = true; host._warehouseScanActive = (host._warehouseScanActive || 0) + 1; }
      let resolution;
      this.setData({ scanning: true });
      wx.scanCode({
        onlyFromCamera: false,
        success: response => { if (this.live) resolution = this.resolveCode(response.result); },
        fail: error => {
          if (this.live && !/cancel/i.test(error.errMsg || '')) wx.showToast({ title: '扫码失败，请重试', icon: 'none' });
        },
        complete: () => {
          const finish = () => {
            if (host) host._warehouseScanActive = Math.max(0, (host._warehouseScanActive || 0) - 1);
            if (this.live) this.setData({ scanning: false });
          };
          Promise.resolve(resolution).then(finish, finish);
        },
      });
    },
  },
});
