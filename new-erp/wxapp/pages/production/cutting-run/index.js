// 集中开料（按实际工时）：一次只开一个 BOM 的料。选 BOM、开几套 → 开始计时（几个人一起开各自点开始）→ 切完结束，登记每种料实际切了多少件。
// 工单下料点「已备料」时按件数领走：带走的工时 = 这批总工时 ÷ 总件数 × 领走件数；没领完的留在这批里，以后谁领谁带走。
const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const hm = minutes => {
  const m = Math.max(0, Math.round(Number(minutes || 0)));
  return m >= 60 ? `${Math.floor(m / 60)} 小时 ${m % 60} 分` : `${m} 分钟`;
};
const sizeText = l => l.total_length_mm ? `总长 ${Number(l.total_length_mm)}mm` : [l.length_mm, l.width_mm, l.thickness_mm].filter(v => v !== null && v !== undefined).map(Number).join('×') + 'mm';

function runView(run) {
  return Object.assign({}, run, {
    laborText: hm(run.labor_minutes), allocatedText: hm(run.allocated_minutes),
    startedText: String(run.started_at || '').slice(5, 16), finishedText: String(run.finished_at || '').slice(5, 16),
    lines: (run.lines || []).map(l => Object.assign({}, l, { sizeText: sizeText(l), input: String(l.actual_pieces) })),
    people: (run.people || []).map(p => Object.assign({}, p, { minutesText: hm(p.labor_minutes) })),
  });
}

Page({
  data: {
    tab: 'runs', loading: true, busy: false, error: '', runs: [], page: 1, lastPage: 1,
    boms: [], keyword: '', bomLoading: false, picked: null, setQty: 1, remark: '',
    panel: '', run: null, liveText: '', finishRemark: '',
  },
  onLoad(options) { if (options.id) this.openRun(Number(options.id)); },
  onShow() { this.load(); if (this.data.run && this.data.panel === 'run') this.openRun(this.data.run.id); },
  onHide() { this.stopTick(); },
  onUnload() { this.stopTick(); },
  onPullDownRefresh() { this.load().finally(() => wx.stopPullDownRefresh()); },
  stop() {},

  load() {
    this.setData({ loading: true, error: '' });
    return production.cuttingRuns({ page: this.data.page, per_page: 20 }).then(list => {
      const runs = (list.data || []).map(runView);
      this.setData({ runs, lastPage: list.last_page || 1, loading: false });
    }).catch(error => this.setData({ loading: false, error: view.errorText(error) }));
  },
  pickTab(event) {
    const tab = event.currentTarget.dataset.tab;
    this.setData({ tab });
    if (tab === 'new' && !this.data.boms.length) this.loadBoms();
  },
  turnPage(event) {
    const page = this.data.page + Number(event.currentTarget.dataset.delta || 0);
    if (page < 1 || page > this.data.lastPage) return;
    this.setData({ page });
    this.load();
  },

  // 新开一批：选 BOM
  keywordInput(event) { this.setData({ keyword: event.detail.value }); },
  loadBoms() {
    this.setData({ bomLoading: true, error: '' });
    return production.cuttingRunBoms({ keyword: this.data.keyword }).then(response => {
      const boms = ((response.data || response).items || []).map(b => Object.assign({}, b, {
        linesText: (b.lines || []).map(l => `${l.component_item_name} ${sizeText(l)} ×${l.per_set}`).join('；'),
        hasSheet: (b.lines || []).some(l => l.mode === 'sheet' && l.physical),
      }));
      this.setData({ boms, bomLoading: false });
    }).catch(error => this.setData({ bomLoading: false, error: view.errorText(error) }));
  },
  pickBom(event) {
    const bom = this.data.boms[Number(event.currentTarget.dataset.index)];
    this.setData({ picked: bom, setQty: Math.max(1, Number(bom.need_sets || 0) - Number(bom.stock_sets || 0)) || 1 });
  },
  changeSet(event) { this.setData({ setQty: Math.max(1, this.data.setQty + Number(event.currentTarget.dataset.delta || 0)) }); },
  setInput(event) { this.setData({ setQty: Math.max(1, Math.floor(Number(event.detail.value) || 1)) }); },
  remarkInput(event) { this.setData({ remark: event.detail.value }); },
  startRun() {
    const bom = this.data.picked;
    if (this.data.busy || !bom) return;
    wx.showModal({ title: '开始开料', content: `开 ${bom.item_name}（${bom.bom_no}）${this.data.setQty} 套的料，现在开始计时？一次只开这一个 BOM 的料。`, confirmText: '开始计时',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.cuttingRunStart({ bom_id: bom.bom_id, set_qty: this.data.setQty, remark: String(this.data.remark || '').trim() || null }).then(response => {
          wx.showToast({ title: '已开始计时', icon: 'none' });
          this.setData({ tab: 'runs', picked: null, remark: '' });
          this.load();
          this.showRun(response.data);
        }).catch(error => wx.showModal({ title: '没能开始', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      } });
  },

  // 详情：计时、结束
  openRunTap(event) { this.openRun(Number(event.currentTarget.dataset.id)); },
  openRun(id) {
    return production.cuttingRun(id).then(response => this.showRun(response.data || response))
      .catch(error => wx.showModal({ title: '读取失败', content: view.errorText(error), showCancel: false }));
  },
  showRun(raw) {
    const run = runView(raw);
    this.loadedAt = Date.now();
    this.setData({ panel: 'run', run, finishRemark: run.remark || '' });
    this.tick();
    this.stopTick();
    if (run.status === 'running') this.timer = setInterval(() => this.tick(), 15000);
  },
  tick() {
    const run = this.data.run;
    if (!run) return;
    const timing = (run.people || []).filter(p => p.timing).length;
    const extra = run.status === 'running' ? timing * (Date.now() - (this.loadedAt || Date.now())) / 60000 : 0;
    this.setData({ liveText: hm(Number(run.labor_minutes || 0) + extra) });
  },
  stopTick() { if (this.timer) { clearInterval(this.timer); this.timer = null; } },
  timing(event) {
    const action = event.currentTarget.dataset.action;
    if (this.data.busy) return;
    this.setData({ busy: true });
    production.cuttingRunAction(this.data.run.id, { action }).then(response => {
      wx.showToast({ title: action === 'start' ? '开始计时' : '已暂停', icon: 'none' });
      this.showRun(response.data);
    }).catch(error => wx.showModal({ title: '没成功', content: view.errorText(error), showCancel: false }))
      .finally(() => this.setData({ busy: false }));
  },
  piecesInput(event) { this.setData({ [`run.lines[${Number(event.currentTarget.dataset.index)}].input`]: event.detail.value }); },
  finishRemarkInput(event) { this.setData({ finishRemark: event.detail.value }); },
  finish() {
    const run = this.data.run;
    if (this.data.busy || !run) return;
    const lines = [];
    for (const l of run.lines) {
      const v = String(l.input).trim();
      if (!/^\d+$/.test(v)) { wx.showToast({ title: `${l.component_item_name} 的件数请填整数`, icon: 'none' }); return; }
      lines.push({ line_key: l.line_key, actual_pieces: Number(v) });
    }
    const total = lines.reduce((s, l) => s + l.actual_pieces, 0);
    if (!total) { wx.showToast({ title: '请填实际切了多少件', icon: 'none' }); return; }
    wx.showModal({ title: '切完结束', content: `一共 ${total} 件，用时约 ${this.data.liveText}。结束后所有人的计时都会停，件数不能再改。`, confirmText: '确认结束',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.cuttingRunAction(run.id, { action: 'finish', expected_version: run.business_version, lines, remark: String(this.data.finishRemark || '').trim() || null }).then(response => {
          wx.showToast({ title: '已结束，各工单下料可点已备料', icon: 'none', duration: 2500 });
          this.showRun(response.data);
          this.load();
        }).catch(error => wx.showModal({ title: '没能结束', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      } });
  },
  cancelRun() {
    const run = this.data.run;
    wx.showModal({ title: '作废这批', content: '开错了 BOM 才作废，作废后这批的计时不算工时。确定作废？', confirmText: '作废', confirmColor: '#c0392b',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.cuttingRunAction(run.id, { action: 'cancel' }).then(() => { this.setData({ panel: '', run: null }); this.stopTick(); this.load(); })
          .catch(error => wx.showModal({ title: '没能作废', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      } });
  },
  openSheet(event) {
    const item = this.data.run.sheet_items[Number(event.currentTarget.dataset.index)];
    wx.navigateTo({ url: `/pages/production/shared-cutting/index?run_id=${this.data.run.id}&item_id=${item.item_id}` });
  },
  openSheetBatches() { wx.navigateTo({ url: `/pages/production/shared-cutting/index?run_id=${this.data.run.id}&tab=batches` }); },
  closePanel() { this.stopTick(); this.setData({ panel: '' }); },
});
