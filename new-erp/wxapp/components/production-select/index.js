Component({
  properties: {
    options: {
      type: Array,
      value: [],
    },
    labelKey: {
      type: String,
      value: 'name',
    },
    value: {
      type: Number,
      value: 0,
    },
    icon: {
      type: String,
      value: '',
    },
    prefix: {
      type: String,
      value: '',
    },
    placeholder: {
      type: String,
      value: '请选择',
    },
  },
  data: {
    open: false,
    currentLabel: '',
    normalizedOptions: [],
  },
  lifetimes: {
    attached() {
      this.syncOptions(this.data.options, this.data.labelKey, this.data.value);
    },
  },
  observers: {
    'options, labelKey, value': function (options, labelKey, value) {
      this.syncOptions(options, labelKey, value);
    },
  },
  methods: {
    syncOptions(options, labelKey, value) {
      const list = Array.isArray(options) ? options : [];
      const key = labelKey || 'name';
      const normalized = list.map((item, idx) => ({
        idx,
        raw: item,
        label: item && typeof item === 'object' ? (item[key] || item.label || item.name || '') : String(item || ''),
      }));
      const idx = Number(value || 0);
      const current = normalized[idx] ? normalized[idx].label : (normalized[0] ? normalized[0].label : '');
      this.setData({
        normalizedOptions: normalized,
        currentLabel: current,
      });
    },
    toggle() {
      if (!this.data.normalizedOptions.length) return;
      this.setData({ open: !this.data.open });
    },
    close() {
      if (this.data.open) this.setData({ open: false });
    },
    selectOption(event) {
      const index = Number(event.currentTarget.dataset.index);
      const item = this.data.normalizedOptions[index];
      this.setData({ open: false });
      if (index !== Number(this.data.value)) {
        this.triggerEvent('change', {
          value: index,
          item: item ? item.raw : null,
        });
      }
    },
  },
});
