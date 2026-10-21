const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const pad = n => (n < 10 ? '0' : '') + n;
function clock(seconds) {
  const s = Math.max(0, Math.floor(seconds));
  return `${pad(Math.floor(s / 3600))}:${pad(Math.floor(s % 3600 / 60))}:${pad(s % 60)}`;
}
function minutesText(m) {
  const v = Number(m || 0);
  return v >= 60 ? `${Math.floor(v / 60)}时${Math.round(v % 60)}分` : `${Math.round(v)}分`;
}

Page({
  data: {
    id: 0, task: null, loading: true, busy: false, error: '', selected: {}, selectedCount: 0, clockText: '00:00:00',
    shareText: '', isImage: {}, panel: '',
    reject: { mode: 'return', returnId: 0, tags: {}, reason: '', photos: [] }, uploading: false,
    peopleKeyword: '', peopleRows: [], picked: [], shareMode: 'labor', ownerPercent: '', showMaterials: false,
  },
  onLoad(options) { this.setData({ id: Number(options.id || 0) }); this.load(); },
  onShow() { if (this.data.task) this.load(true); },
  onHide() { this.stopClock(); },
  onUnload() { this.stopClock(); this.sequence = (this.sequence || 0) + 1; },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  load(keepSelection) {
    const sequence = this.sequence = (this.sequence || 0) + 1;
    this.setData({ loading: true, error: '' });
    return production.task(this.data.id).then(response => {
      if (sequence === this.sequence) this.apply(response.data || response, keepSelection);
    }).catch(error => { if (sequence === this.sequence) this.setData({ loading: false, error: view.errorText(error) }); });
  },
  apply(task, keepSelection) {
    const selected = {};
    const old = keepSelection ? this.data.selected : {};
    (task.units || []).forEach(u => { if (u.selectable && old[u.id]) selected[u.id] = true; });
    const owner = (task.participants || []).find(p => p.role === 'owner');
    const others = (task.participants || []).filter(p => p.role !== 'owner');
    const shareText = (task.participants || []).length > 1
      ? task.participants.map(p => p.employee_name + (task.share_mode === 'fixed' ? ` ${view.quantity(p.share_percent)}%` : ` ${minutesText(p.labor_minutes)}`)).join(' · ')
      : '';
    const isImage = {};
    (task.drawings || []).forEach(d => { isImage[d.id] = /^image\//.test(d.mime_type || '') || /\.(png|jpe?g|webp|gif)$/i.test(d.url || ''); });
    wx.setNavigationBarTitle({ title: task.operation_name || '报工' });
    this.setData({
      task: Object.assign({}, task, {
        plannedText: view.quantity(task.progress.planned), doneText: view.quantity(task.progress.done),
        ownerName: owner ? owner.employee_name : '', collaboratorNames: others.map(p => p.employee_name).join('、'),
        dueText: String(task.work_order.planned_finish_date || '').slice(5, 10),
        selectableCount: (task.units || []).filter(u => u.selectable).length,
      }),
      selected, selectedCount: Object.keys(selected).length, shareText, isImage, loading: false,
    });
    this.startClock();
  },
  startClock() {
    this.stopClock();
    const task = this.data.task;
    if (!task) return;
    const base = Number(task.my.closed_minutes || 0) * 60;
    const started = task.my.timing && task.my.started_at ? new Date(task.my.started_at).getTime() : 0;
    const tick = () => this.setData({ clockText: clock(base + (started ? (Date.now() - started) / 1000 : 0)) });
    tick();
    if (started) this.timer = setInterval(tick, 1000);
  },
  stopClock() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },

  toggleUnit(event) {
    const id = Number(event.currentTarget.dataset.id);
    const unit = (this.data.task.units || []).find(u => u.id === id);
    if (!unit || !unit.selectable) return;
    const selected = Object.assign({}, this.data.selected);
    if (selected[id]) delete selected[id]; else selected[id] = true;
    this.setData({ selected, selectedCount: Object.keys(selected).length });
  },
  selectAll() {
    const units = (this.data.task.units || []).filter(u => u.selectable);
    const all = this.data.selectedCount === units.length;
    const selected = {};
    if (!all) units.forEach(u => { selected[u.id] = true; });
    this.setData({ selected, selectedCount: Object.keys(selected).length });
  },
  scan() {
    wx.scanCode({
      success: result => {
        const code = String(result.result || '').trim();
        const unit = (this.data.task.units || []).find(u => u.serial_no && (code === u.serial_no || code.endsWith(u.serial_no) || code.indexOf(u.serial_no) >= 0));
        if (!unit) { wx.showToast({ title: '这台不在本道', icon: 'none' }); return; }
        if (!unit.selectable) { wx.showToast({ title: '这台在等报废确认', icon: 'none' }); return; }
        const selected = Object.assign({}, this.data.selected, { [unit.id]: true });
        this.setData({ selected, selectedCount: Object.keys(selected).length });
        wx.showToast({ title: `已选 ${unit.serial_no}`, icon: 'none' });
      },
    });
  },
  selectedIds() { return Object.keys(this.data.selected).map(Number); },

  run(payload, okText) {
    if (this.data.busy) return Promise.resolve(false);
    this.setData({ busy: true });
    return production.action(this.data.id, payload).then(response => {
      wx.showToast({ title: response.message || okText || '已完成', icon: 'none', duration: 2500 });
      this.setData({ panel: '' });
      if (response.data) this.apply(response.data); else this.load();
      return true;
    }).catch(error => {
      if (error && error.errorCode === 'material_not_received') {
        // 本道的料还没收：可以当场收料（从库存扣减），收好再点开始。
        wx.showModal({ title: '料还没收', content: view.errorText(error), confirmText: '现在收料', cancelText: '先不收',
          success: res => { if (res.confirm) this.receiveMaterials(); } });
      } else {
        wx.showModal({ title: '没成功', content: view.errorText(error), showCancel: false });
      }
      this.load(true);
      return false;
    }).finally(() => this.setData({ busy: false }));
  },
  receiveMaterials() {
    const task = this.data.task;
    if (!task || !task.work_order) return;
    production.receiveMaterials(task.work_order.id, task.id).then(response => {
      wx.showToast({ title: response.message || '已收料', icon: 'none', duration: 2500 });
      this.load(true);
    }).catch(error => wx.showModal({ title: '收料没成功', content: view.errorText(error), showCancel: false }));
  },
  claim() { this.run({ action: 'claim' }, '接单成功'); },
  start() { this.run({ action: 'start' }, '开始计时'); },
  pause() { this.run({ action: 'pause' }, '已暂停'); },
  pass() {
    const ids = this.selectedIds();
    if (!ids.length) { wx.showToast({ title: '先勾选或扫码选台', icon: 'none' }); return; }
    const next = this.data.task.progress.next_operation_name;
    wx.showModal({ title: '报合格', content: `${ids.length} 台合格，${next ? '交给' + next : '生产完成，等入库'}？`, confirmText: '确认',
      success: res => { if (res.confirm) this.run({ action: 'pass', unit_ids: ids }); } });
  },

  openReject() {
    if (!this.selectedIds().length) { wx.showToast({ title: '先勾选不合格的台', icon: 'none' }); return; }
    const current = (this.data.task.return_options || []).find(o => o.is_current);
    this.setData({ panel: 'reject', reject: { mode: 'return', returnId: current ? current.id : 0, tags: {}, reason: '', photos: [] } });
  },
  pickReturn(event) {
    const id = Number(event.currentTarget.dataset.id);
    this.setData({ 'reject.mode': id ? 'return' : 'scrap', 'reject.returnId': id });
  },
  toggleTag(event) {
    const tag = event.currentTarget.dataset.tag;
    const tags = Object.assign({}, this.data.reject.tags);
    if (tags[tag]) delete tags[tag]; else tags[tag] = true;
    this.setData({ 'reject.tags': tags });
  },
  reasonInput(event) { this.setData({ 'reject.reason': event.detail.value }); },
  addPhoto() {
    const left = 9 - this.data.reject.photos.length;
    if (left <= 0 || this.data.uploading) return;
    wx.chooseMedia({ count: Math.min(left, 3), mediaType: ['image'], sourceType: ['camera', 'album'], sizeType: ['compressed'],
      success: res => {
        const files = (res.tempFiles || []).map(f => f.tempFilePath);
        if (!files.length) return;
        this.setData({ uploading: true });
        wx.showLoading({ title: '上传照片…', mask: true });
        files.reduce((chain, path) => chain.then(() => production.uploadPhoto(path).then(url => {
          this.setData({ 'reject.photos': this.data.reject.photos.concat([{ path, url }]) });
        })), Promise.resolve())
          .catch(error => wx.showToast({ title: view.errorText(error), icon: 'none' }))
          .finally(() => { wx.hideLoading(); this.setData({ uploading: false }); });
      } });
  },
  removePhoto(event) {
    const photos = this.data.reject.photos.slice();
    photos.splice(Number(event.currentTarget.dataset.index), 1);
    this.setData({ 'reject.photos': photos });
  },
  previewPhoto(event) {
    const urls = this.data.reject.photos.map(p => p.path);
    wx.previewImage({ urls, current: urls[Number(event.currentTarget.dataset.index)] });
  },
  submitReject() {
    const r = this.data.reject;
    const ids = this.selectedIds();
    if (r.mode === 'return' && !r.returnId) { wx.showToast({ title: '选退回哪道', icon: 'none' }); return; }
    const tags = Object.keys(r.tags);
    if (!tags.length && !r.reason.trim()) { wx.showToast({ title: '选个原因或写说明', icon: 'none' }); return; }
    const payload = { action: 'reject', unit_ids: ids, mode: r.mode, return_operation_id: r.mode === 'return' ? r.returnId : null,
      reason_tags: tags, reason: r.reason.trim() || null, photos: r.photos.map(p => p.url) };
    if (r.mode === 'scrap') {
      wx.showModal({ title: '申请报废', content: `${ids.length} 台申请报废，主管确认后才算报废。确定？`, confirmText: '申请报废', confirmColor: '#c0261b',
        success: res => { if (res.confirm) this.run(payload); } });
      return;
    }
    this.run(payload);
  },

  openPeople() {
    const task = this.data.task;
    const owner = (task.participants || []).find(p => p.role === 'owner');
    const picked = (task.participants || []).filter(p => p.role !== 'owner')
      .map(p => ({ id: p.employee_legacy_id, name: p.employee_name, percent: p.share_percent === null ? '' : view.quantity(p.share_percent) }));
    this.setData({ panel: 'people', picked, shareMode: task.share_mode || 'labor', ownerPercent: owner && owner.share_percent !== null ? view.quantity(owner.share_percent) : '', peopleKeyword: '', peopleRows: [] });
    this.searchPeople();
  },
  openAssign() { this.setData({ panel: 'assign', peopleKeyword: '', peopleRows: [] }); this.searchPeople(); },
  peopleInput(event) { this.setData({ peopleKeyword: event.detail.value }); },
  searchPeople() {
    const me = Number((wx.getStorageSync('erp_user') || {}).legacy_id || 0);
    const sequence = this.peopleSequence = (this.peopleSequence || 0) + 1;
    return production.people(this.data.id, { keyword: this.data.peopleKeyword }).then(response => {
      if (sequence !== this.peopleSequence) return;
      const rows = (response.data || []).filter(u => this.data.panel === 'assign' || u.id !== me);
      this.setData({ peopleRows: rows });
    }).catch(error => wx.showToast({ title: view.errorText(error), icon: 'none' }));
  },
  togglePerson(event) {
    const id = Number(event.currentTarget.dataset.id);
    const name = event.currentTarget.dataset.name;
    const picked = this.data.picked.slice();
    const index = picked.findIndex(p => p.id === id);
    if (index >= 0) picked.splice(index, 1); else picked.push({ id, name, percent: '' });
    this.setData({ picked });
  },
  pickShareMode(event) {
    const mode = event.currentTarget.dataset.mode;
    if (mode === 'fixed' && this.data.shareMode !== 'fixed') {
      const n = this.data.picked.length + 1;
      const each = Math.floor(100 / n);
      this.setData({ shareMode: mode, ownerPercent: String(100 - each * (n - 1)), picked: this.data.picked.map(p => Object.assign({}, p, { percent: String(each) })) });
    } else this.setData({ shareMode: mode });
  },
  ownerPercentInput(event) { this.setData({ ownerPercent: event.detail.value }); },
  percentInput(event) { this.setData({ [`picked[${Number(event.currentTarget.dataset.index)}].percent`]: event.detail.value }); },
  savePeople() {
    const fixed = this.data.shareMode === 'fixed' && this.data.picked.length > 0;
    if (fixed) {
      const total = Number(this.data.ownerPercent || 0) + this.data.picked.reduce((s, p) => s + Number(p.percent || 0), 0);
      if (Math.abs(total - 100) > 0.01) { wx.showToast({ title: `份额加起来要 100%，现在 ${view.quantity(total)}%`, icon: 'none' }); return; }
    }
    this.run({ action: 'collaborators', collaborators: this.data.picked.map(p => ({ employee_legacy_id: p.id, share_percent: fixed ? Number(p.percent || 0) : null })),
      owner_share_percent: fixed ? Number(this.data.ownerPercent || 0) : null }, '协同人已保存');
  },
  assignTo(event) {
    const id = Number(event.currentTarget.dataset.id);
    const name = event.currentTarget.dataset.name;
    wx.showModal({ title: '派工', content: `把「${this.data.task.operation_name}」派给 ${name}？`, success: res => { if (res.confirm) this.run({ action: 'assign', employee_legacy_id: id }, '已派工'); } });
  },
  personTap(event) { return this.data.panel === 'assign' ? this.assignTo(event) : this.togglePerson(event); },
  closePanel() { if (!this.data.busy) this.setData({ panel: '' }); },
  stop() {},

  openDrawing(event) {
    const drawing = (this.data.task.drawings || []).find(d => d.id === Number(event.currentTarget.dataset.id));
    if (!drawing) return;
    if (this.data.isImage[drawing.id]) {
      const urls = this.data.task.drawings.filter(d => this.data.isImage[d.id]).map(d => d.url);
      wx.previewImage({ urls, current: drawing.url });
      return;
    }
    wx.showLoading({ title: '打开图纸…', mask: true });
    wx.downloadFile({ url: drawing.url,
      success: res => {
        wx.hideLoading();
        if (res.statusCode !== 200) { wx.showToast({ title: '图纸下载失败', icon: 'none' }); return; }
        wx.openDocument({ filePath: res.tempFilePath, showMenu: true,
          fail: () => wx.setClipboardData({ data: drawing.url, success: () => wx.showToast({ title: '手机打不开这种图纸，链接已复制', icon: 'none' }) }) });
      },
      fail: () => { wx.hideLoading(); wx.showToast({ title: '图纸下载失败', icon: 'none' }); } });
  },
  toggleMaterials() { this.setData({ showMaterials: !this.data.showMaterials }); },
  openWorkOrder() { wx.navigateTo({ url: `/pages/production/work-order/index?id=${Number(this.data.task.work_order.id)}` }); },
});
