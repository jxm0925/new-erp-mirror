const production = require('../../services/production');

Component({
  properties: {
    value: { type: Array, value: [] },
    readonly: { type: Boolean, value: false },
    label: { type: String, value: '图片 / 视频（选填）' },
  },
  data: { uploading: false, video: null },
  lifetimes: {
    attached() { this._detached = false; },
    detached() { this._detached = true; this.triggerEvent('busy', { busy: false }); this.activity(false); },
  },
  methods: {
    activity(active) { this.triggerEvent('activity', { active }, { bubbles: true, composed: true }); },
    add() {
      if (this.properties.readonly || this.data.uploading || this.choosing || this.properties.value.length >= 9) return;
      this.choosing = true;
      this.activity(true);
      wx.chooseMedia({ count: 9 - this.properties.value.length, mediaType: ['image', 'video'], maxDuration: 60,
        sourceType: ['camera', 'album'], sizeType: ['compressed'],
        success: async result => {
          if (this._detached) return;
          this.setData({ uploading: true });
          this.triggerEvent('busy', { busy: true });
          let items = this.properties.value.slice();
          try {
            for (const file of result.tempFiles || []) {
              const video = file.fileType === 'video';
              if (file.size > (video ? 100 : 10) * 1024 * 1024) throw new Error(video ? '视频不能超过 100MB' : '图片不能超过 10MB');
              if (video && file.duration > 60) throw new Error('视频时长不能超过 60 秒');
              const item = await production.uploadMedia(file.tempFilePath);
              if (video) {
                item.duration = Number(file.duration || 0);
                if (file.thumbTempFilePath) item.poster_url = (await production.uploadMedia(file.thumbTempFilePath)).url;
              }
              if (this._detached) return;
              items = items.concat([item]);
              this.triggerEvent('change', { value: items });
            }
          } catch (error) { wx.showToast({ title: error.message || '上传失败，请重试', icon: 'none' }); }
          finally {
            this.choosing = false;
            if (!this._detached) { this.setData({ uploading: false }); this.triggerEvent('busy', { busy: false }); this.activity(false); }
          }
        },
        fail: error => {
          this.choosing = false;
          this.activity(false);
          if (!/cancel/.test(error.errMsg || '')) wx.showToast({ title: '无法打开相机或相册，请检查授权', icon: 'none' });
        },
      });
    },
    remove(event) {
      if (this.data.uploading || this.properties.readonly) return;
      const value = this.properties.value.slice();
      value.splice(Number(event.currentTarget.dataset.index), 1);
      this.triggerEvent('change', { value });
    },
    preview(event) {
      const item = this.properties.value[Number(event.currentTarget.dataset.index)];
      if (!item) return;
      this.activity(true);
      if (item.type === 'video') {
        this.setData({ video: item }, () => {
          const player = wx.createVideoContext('mediaVideo', this);
          player.requestFullScreen({ direction: 0 });
          player.play();
        });
      } else {
        wx.previewImage({ urls: this.properties.value.filter(row => row.type !== 'video').map(row => row.url), current: item.url,
          complete: () => this.activity(false) });
      }
    },
    fullScreen(event) { if (!event.detail.fullScreen) this.closeVideo(); },
    closeVideo() { wx.createVideoContext('mediaVideo', this).stop(); this.setData({ video: null }); this.activity(false); },
  },
});
