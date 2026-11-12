const { getErpApiBaseUrl } = require('../../config/erp');

function fileUrl(value) {
  const url = String(value || '').trim();
  if (!url || /^(?:data|javascript|file):/i.test(url)) return '';
  const origin = (getErpApiBaseUrl().match(/^https?:\/\/[^/]+/i) || [])[0] || '';
  if (/^https?:\/\//i.test(url)) {
    return url.replace(/^https?:\/\/(?:localhost|127\.0\.0\.1|0\.0\.0\.0)(?::\d+)?(?=\/(?:storage|uploads)\/)/i, origin);
  }
  if (url.startsWith('//')) return 'https:' + url;
  return origin + '/' + url.replace(/^\/+/, '');
}

Component({
  options: { styleIsolation: 'apply-shared' },
  properties: { reference: { type: Object, value: null, observer: 'prepare' } },
  data: { notes: [], files: [], busy: false },
  lifetimes: { detached() { this.prepareSerial = (this.prepareSerial || 0) + 1; } },
  methods: {
    prepare(reference) {
      const serial = this.prepareSerial = (this.prepareSerial || 0) + 1;
      const origin = (getErpApiBaseUrl().match(/^https?:\/\/[^/]+/i) || [])[0] || '';
      const seen = {};
      const files = ((reference && reference.files) || []).map((file, index) => {
        const url = fileUrl(file.url);
        const extension = ((String(file.original_name || file.name || '').match(/\.([a-z0-9]+)$/i) || String(url).split(/[?#]/)[0].match(/\.([a-z0-9]+)$/i) || [])[1] || '').toLowerCase();
        const isImage = /^image\//i.test(file.mime_type || '') || /^(png|jpe?g|webp|gif|bmp)$/.test(extension);
        const canOpen = /^(pdf|doc|docx|xls|xlsx|ppt|pptx)$/.test(extension);
        const authenticated = url.startsWith(origin + '/api/');
        return Object.assign({}, file, { key: file.id || 'file-' + index, url, extension, isImage, canOpen, authenticated, previewUrl: authenticated ? '' : url,
          name: file.name || '生产资料', action: isImage ? '点图放大' : (canOpen ? '打开文档' : '复制链接'),
          timeText: String(file.updated_at || '').replace('T', ' ').slice(0, 16) });
      }).filter(file => {
        if (!file.url || seen[file.url]) return false;
        seen[file.url] = true;
        return true;
      });
      this.setData({ notes: ((reference && reference.notes) || []).map((note, index) => Object.assign({}, note, { key: 'note-' + index })), files });
      files.filter(file => file.isImage && file.authenticated).forEach(file => {
        this.download(file).then(path => {
          if (serial !== this.prepareSerial) return;
          const index = this.data.files.findIndex(row => row.key === file.key && row.url === file.url);
          if (index >= 0) this.setData({ ['files[' + index + '].previewUrl']: path });
        }).catch(() => {}); // 缩略图失败保留文件入口，点击时可重试并显示错误。
      });
    },
    download(file) {
      const origin = (getErpApiBaseUrl().match(/^https?:\/\/[^/]+/i) || [])[0];
      const token = wx.getStorageSync('erp_token');
      const header = token && file.url.startsWith(origin + '/') ? { Authorization: 'Bearer ' + token } : {};
      return new Promise((resolve, reject) => wx.downloadFile({ url: file.url, header, timeout: 30000,
        success: response => response.statusCode === 200 ? resolve(response.tempFilePath) : reject(new Error('资料下载失败')),
        fail: reject }));
    },
    activity(active) { this.triggerEvent('activity', { active }); },
    released() { setTimeout(() => this.activity(false), 500); },
    async open(event) {
      const file = this.data.files.find(row => row.key === event.currentTarget.dataset.key);
      if (!file || this.data.busy) return;
      if (file.isImage && !file.authenticated) {
        this.activity(true);
        wx.previewImage({ current: file.url, urls: this.data.files.filter(row => row.isImage && row.previewUrl).map(row => row.previewUrl),
          fail: () => wx.showToast({ title: '图片打开失败，请刷新重试', icon: 'none' }), complete: () => this.released() });
        return;
      }
      if (!file.isImage && !file.canOpen) { wx.setClipboardData({ data: file.url }); return; }
      const serial = this.prepareSerial;
      this.setData({ busy: true });
      wx.showLoading({ title: '正在打开资料…', mask: true });
      const finish = () => { wx.hideLoading(); this.setData({ busy: false }); };
      try {
        const path = file.isImage && file.previewUrl ? file.previewUrl : await this.download(file);
        if (serial !== this.prepareSerial) return;
        this.activity(true);
        if (file.isImage) wx.previewImage({ current: path, urls: [path],
          fail: () => wx.showToast({ title: '图片打开失败，请刷新重试', icon: 'none' }), complete: () => this.released() });
        else wx.openDocument({ filePath: path, fileType: file.extension, showMenu: true,
          fail: () => wx.showToast({ title: '该文件无法打开，可长按复制链接', icon: 'none' }), complete: () => this.released() });
      } catch (error) {
        if (serial === this.prepareSerial) wx.showToast({ title: '资料下载失败，请检查权限或网络', icon: 'none' });
      } finally { finish(); }
    },
    copy(event) {
      const file = this.data.files.find(row => row.key === event.currentTarget.dataset.key);
      if (file) wx.setClipboardData({ data: file.url });
    },
  },
});
