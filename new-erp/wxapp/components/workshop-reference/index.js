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
  methods: {
    prepare(reference) {
      const seen = {};
      const files = ((reference && reference.files) || []).map((file, index) => {
        const url = fileUrl(file.url);
        const extension = ((String(file.name || '').match(/\.([a-z0-9]+)$/i) || String(url).split(/[?#]/)[0].match(/\.([a-z0-9]+)$/i) || [])[1] || '').toLowerCase();
        const isImage = /^image\//i.test(file.mime_type || '') || /^(png|jpe?g|webp|gif|bmp)$/.test(extension);
        const canOpen = /^(pdf|doc|docx|xls|xlsx|ppt|pptx)$/.test(extension);
        return Object.assign({}, file, { key: file.id || 'file-' + index, url, extension, isImage, canOpen,
          name: file.name || '生产资料', action: isImage ? '点图放大' : (canOpen ? '打开文档' : '复制链接'),
          timeText: String(file.updated_at || '').replace('T', ' ').slice(0, 16) });
      }).filter(file => {
        if (!file.url || seen[file.url]) return false;
        seen[file.url] = true;
        return true;
      });
      this.setData({ notes: ((reference && reference.notes) || []).map((note, index) => Object.assign({}, note, { key: 'note-' + index })), files });
    },
    activity(active) { this.triggerEvent('activity', { active }); },
    released() { setTimeout(() => this.activity(false), 500); },
    open(event) {
      const file = this.data.files.find(row => row.key === event.currentTarget.dataset.key);
      if (!file || this.data.busy) return;
      if (file.isImage) {
        this.activity(true);
        wx.previewImage({ current: file.url, urls: this.data.files.filter(row => row.isImage).map(row => row.url),
          fail: () => wx.showToast({ title: '图片打开失败，请刷新重试', icon: 'none' }), complete: () => this.released() });
        return;
      }
      if (!file.canOpen) { wx.setClipboardData({ data: file.url }); return; }
      this.setData({ busy: true });
      wx.showLoading({ title: '正在打开资料…', mask: true });
      const finish = () => { wx.hideLoading(); this.setData({ busy: false }); };
      const origin = (getErpApiBaseUrl().match(/^https?:\/\/[^/]+/i) || [])[0];
      const token = wx.getStorageSync('erp_token');
      const header = token && file.url.startsWith(origin + '/') ? { Authorization: 'Bearer ' + token } : {};
      wx.downloadFile({ url: file.url, header, timeout: 30000,
        success: response => {
          finish();
          if (response.statusCode !== 200) { wx.showToast({ title: '资料下载失败，请刷新重试', icon: 'none' }); return; }
          this.activity(true);
          wx.openDocument({ filePath: response.tempFilePath, fileType: file.extension, showMenu: true,
            fail: () => wx.showToast({ title: '该文件无法打开，可长按复制链接', icon: 'none' }), complete: () => this.released() });
        }, fail: () => { finish(); wx.showToast({ title: '资料下载失败，请检查网络', icon: 'none' }); } });
    },
    copy(event) {
      const file = this.data.files.find(row => row.key === event.currentTarget.dataset.key);
      if (file) wx.setClipboardData({ data: file.url });
    },
  },
});
