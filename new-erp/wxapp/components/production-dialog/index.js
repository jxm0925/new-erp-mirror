Component({
  data: {
    open: false,
    mode: 'confirm', // 'confirm' | 'completion' | 'stepper' | 'input'
    theme: 'default', // 'default' | 'warn' | 'danger'
    title: '',
    subtitle: '',
    infoMain: '',
    infoSub: '',
    content: '',
    kvRows: [],
    tableRows: [],
    showCancel: true,
    cancelText: '取消',
    confirmText: '确认',
    // Mode: completion
    choice: 'handoff',
    handoffAvailable: true,
    handoffTitle: '直接交接至下一工序',
    handoffDesc: '',
    storeTitle: '完工入库（入成品/半成品库）',
    storeDesc: '下一步选择目标仓库与库位后正式办理完工入库',
    handoffConfirmText: '确认交接',
    storeConfirmText: '下一步：选择入库库位',
    // Mode: stepper
    stats: [],
    stepperLabel: '本次数量',
    qty: 1,
    minQty: 1,
    maxQty: 9999,
    // Mode: input
    quickTagsLabel: '快捷选择：',
    quickTags: [],
    selectedTag: '',
    inputLabel: '',
    inputValue: '',
    placeholderText: '',
    inputRequired: false,
    inputRequiredToast: '请填写必填内容',
  },
  methods: {
    show(options = {}) {
      this._callbacks = {
        success: typeof options.success === 'function' ? options.success : null,
        fail: typeof options.fail === 'function' ? options.fail : null,
      };
      const showCancel = options.showCancel !== false;
      let theme = options.theme || 'default';
      if (!options.theme) {
        if (options.confirmColor === '#c0261b' || options.confirmColor === '#c0392b') theme = 'danger';
        else if (!showCancel && /失败|未通过|不可|待签收/.test(options.title || '')) theme = 'warn';
      }
      const mode = options.mode || (options.editable ? 'input' : 'confirm');
      const handoffAvailable = options.handoffAvailable !== false;
      this.setData({
        open: true,
        mode,
        theme,
        title: options.title || '提示',
        subtitle: options.subtitle || '',
        infoMain: options.infoMain || '',
        infoSub: options.infoSub || '',
        content: mode === 'input' && options.editable && !options.infoMain ? '' : (options.content || ''),
        kvRows: options.kvRows || [],
        tableRows: options.tableRows || [],
        showCancel,
        cancelText: options.cancelText || '取消',
        confirmText: options.confirmText || (showCancel ? '确认' : '我知道了'),
        choice: options.choice || (handoffAvailable ? 'handoff' : 'store'),
        handoffAvailable,
        handoffTitle: options.handoffTitle || '直接交接至下一工序',
        handoffDesc: options.handoffDesc || '',
        storeTitle: options.storeTitle || '完工入库（入成品/半成品库）',
        storeDesc: options.storeDesc || '下一步选择目标仓库与库位后正式办理完工入库',
        handoffConfirmText: options.handoffConfirmText || '确认交接',
        storeConfirmText: options.storeConfirmText || '下一步：选择入库库位',
        stats: options.stats || [],
        stepperLabel: options.stepperLabel || '本次数量',
        qty: Number(options.qty || options.content || 1) || 1,
        minQty: Number(options.minQty || 1) || 1,
        maxQty: Number(options.maxQty || 9999) || 9999,
        quickTagsLabel: options.quickTagsLabel || '快捷选择：',
        quickTags: options.quickTags || [],
        selectedTag: '',
        inputLabel: options.inputLabel || '',
        inputValue: mode === 'input' ? String(options.inputValue !== undefined ? options.inputValue : (options.editable && options.content ? options.content : '')) : '',
        placeholderText: options.placeholderText || '请输入内容',
        inputRequired: Boolean(options.inputRequired),
        inputRequiredToast: options.inputRequiredToast || '请填写内容',
      });
    },
    pickChoice(e) {
      const choice = e.currentTarget.dataset.choice;
      if (choice === 'handoff' && !this.data.handoffAvailable) {
        wx.showToast({ title: this.data.handoffDesc || '当前暂不可直接交接', icon: 'none' });
        return;
      }
      this.setData({ choice });
    },
    stepQty(e) {
      const delta = Number(e.currentTarget.dataset.delta || 0);
      const next = Math.max(this.data.minQty, Math.min(this.data.maxQty, (Number(this.data.qty) || 1) + delta));
      this.setData({ qty: next });
    },
    qtyInput(e) {
      const raw = parseInt(String(e.detail.value || '').trim(), 10);
      if (!isNaN(raw)) {
        this.setData({ qty: Math.max(this.data.minQty, Math.min(this.data.maxQty, raw)) });
      } else {
        this.setData({ qty: '' });
      }
    },
    pickQuickTag(e) {
      const tag = e.currentTarget.dataset.tag;
      const cur = String(this.data.inputValue || '').trim();
      const next = cur ? (cur.indexOf(tag) >= 0 ? cur : `${tag}：${cur}`) : tag;
      this.setData({ selectedTag: tag, inputValue: next });
    },
    onTextInput(e) {
      this.setData({ inputValue: e.detail.value });
    },
    onMaskTap() {
      if (this.data.showCancel) this.cancel();
    },
    cancel() {
      const cb = this._callbacks && this._callbacks.success;
      this._callbacks = null;
      this.setData({ open: false });
      if (cb) cb({ confirm: false, cancel: true, content: '' });
    },
    confirm() {
      if (this.data.mode === 'stepper') {
        const qty = parseInt(String(this.data.qty || '').trim(), 10);
        if (!(qty >= this.data.minQty)) {
          wx.showToast({ title: `数量须不小于 ${this.data.minQty}`, icon: 'none' });
          return;
        }
        if (qty > this.data.maxQty) {
          wx.showToast({ title: `数量最多不可超过 ${this.data.maxQty}`, icon: 'none' });
          return;
        }
        const cb = this._callbacks && this._callbacks.success;
        this._callbacks = null;
        this.setData({ open: false });
        if (cb) cb({ confirm: true, cancel: false, qty, content: String(qty) });
        return;
      }
      if (this.data.mode === 'input') {
        const text = String(this.data.inputValue || '').trim();
        if (this.data.inputRequired && !text) {
          wx.showToast({ title: this.data.inputRequiredToast || '请填写必填内容', icon: 'none' });
          return;
        }
        const cb = this._callbacks && this._callbacks.success;
        this._callbacks = null;
        this.setData({ open: false });
        if (cb) cb({ confirm: true, cancel: false, content: text });
        return;
      }
      if (this.data.mode === 'completion') {
        const choice = this.data.choice;
        if (choice === 'handoff' && !this.data.handoffAvailable) {
          wx.showToast({ title: this.data.handoffDesc || '当前暂不可直接交接', icon: 'none' });
          return;
        }
        const cb = this._callbacks && this._callbacks.success;
        this._callbacks = null;
        this.setData({ open: false });
        if (cb) cb({ confirm: true, cancel: false, choice });
        return;
      }
      const cb = this._callbacks && this._callbacks.success;
      this._callbacks = null;
      this.setData({ open: false });
      if (cb) cb({ confirm: true, cancel: false, content: '' });
    },
    stop() {},
  },
});
