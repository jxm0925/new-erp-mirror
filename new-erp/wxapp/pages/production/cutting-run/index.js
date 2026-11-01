// 集中开料（按实际工时统计）：单次按单一 BOM 启动开料批次并记录作业人员实际工时，完工登记各子件实际产出件数。
// 切完结束时按数量管理的板材／管材按 件数 × 每件用量 从仓库扣料（库存不够不能结束），逐张管理的板材须先领板并登记余料。
// 各工单下料工序执行「已备料」时按件数分摊开料工时（批次总工时 ÷ 总件数 × 领用件数），同时按件数带走已扣的料，不再从仓库扣。
const production = require('../../../services/production');
const view = require('../../../utils/warehouse-page');

const hm = minutes => {
  const m = Math.max(0, Math.round(Number(minutes || 0)));
  return m >= 60 ? `${Math.floor(m / 60)} 小时 ${m % 60} 分` : `${m} 分钟`;
};
const num = v => String(Math.round(Number(v || 0) * 10000) / 10000);
// 开料中：按填的件数估算切完要扣多少库存；已结束：已扣多少、还剩多少没被工单领走。
const materialText = (l, input) => {
  const unit = l.unit_name || '';
  if (l.material_qty !== undefined && l.material_qty !== null) return { text: `已扣库存 ${num(l.material_qty)}${unit} · 未领 ${num(l.left_material_qty)}${unit}`, short: false };
  if (l.material_per_piece === null || l.material_per_piece === undefined) return { text: l.physical ? '逐张板材：须先领用板材并登记余料' : '', short: false };
  const need = Number(l.material_per_piece) * Number(input || 0);
  const stock = Number(l.stock_available || 0);
  return { text: `切完扣库存约 ${num(need)}${unit}（仓库可用 ${num(stock)}${unit}）`, short: need > stock + 0.00001 };
};
const lineView = (l, input) => { const m = materialText(l, input); return Object.assign({}, l, { input: String(input), materialText: m.text, materialShort: m.short }); };
const sizeText = l => l.total_length_mm ? `总长 ${Number(l.total_length_mm)}mm` : [l.length_mm, l.width_mm, l.thickness_mm].filter(v => v !== null && v !== undefined).map(Number).join('×') + 'mm';

function runView(run) {
  return Object.assign({}, run, {
    laborText: hm(run.labor_minutes), allocatedText: hm(run.allocated_minutes),
    startedText: String(run.started_at || '').slice(5, 16), finishedText: String(run.finished_at || '').slice(5, 16),
    lines: (run.lines || []).map(l => lineView(Object.assign({}, l, { sizeText: sizeText(l) }), l.actual_pieces)),
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
    view.modal(this, {
      title: '启动集中开料批次',
      subtitle: '单一开料批次仅限加工同一 BOM',
      kvRows: [
        { k: '目标半成品', v: `${bom.item_name}（${bom.bom_no}）` },
        { k: '计划开料套数', v: `${this.data.setQty} 套`, red: true },
      ],
      confirmText: '开始计时',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.cuttingRunStart({ bom_id: bom.bom_id, set_qty: this.data.setQty, remark: String(this.data.remark || '').trim() || null }).then(response => {
          wx.showToast({ title: '已启动开料并开始计时', icon: 'none' });
          this.setData({ tab: 'runs', picked: null, remark: '' });
          this.load();
          this.showRun(response.data);
        }).catch(error => view.modal(this, { theme: 'warn', title: '启动失败', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      } });
  },

  openRunTap(event) { this.openRun(Number(event.currentTarget.dataset.id)); },
  openRun(id) {
    return production.cuttingRun(id).then(response => this.showRun(response.data || response))
      .catch(error => view.modal(this, { theme: 'warn', title: '读取失败', content: view.errorText(error), showCancel: false }));
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
    }).catch(error => view.modal(this, { theme: 'warn', title: '操作失败', content: view.errorText(error), showCancel: false }))
      .finally(() => this.setData({ busy: false }));
  },
  piecesInput(event) {
    const index = Number(event.currentTarget.dataset.index);
    this.setData({ [`run.lines[${index}]`]: lineView(this.data.run.lines[index], event.detail.value) });
  },
  finishRemarkInput(event) { this.setData({ finishRemark: event.detail.value }); },
  finish() {
    const run = this.data.run;
    if (this.data.busy || !run) return;
    const lines = [];
    for (const l of run.lines) {
      const v = String(l.input).trim();
      if (!/^\d+$/.test(v)) { wx.showToast({ title: `${l.component_item_name} 的产出件数须为整数`, icon: 'none' }); return; }
      lines.push({ line_key: l.line_key, actual_pieces: Number(v) });
    }
    const total = lines.reduce((s, l) => s + l.actual_pieces, 0);
    if (!total) { wx.showToast({ title: '请填写实际开料产出件数', icon: 'none' }); return; }
    const short = run.lines.filter(l => l.materialShort);
    if (short.length) { view.modal(this, { theme: 'warn', title: '库存不够', content: short.map(l => `${l.component_item_name} ${l.sizeText}：${l.materialText}`).join('\n') + '\n请先办好入库再结束。', showCancel: false }); return; }
    view.modal(this, {
      title: '确认开料完工结算',
      subtitle: '确认结束后将停止本批次全部人员计时、锁定产出件数，并按件数从仓库扣料',
      kvRows: [
        { k: '合计产出件数', v: `${total} 件`, red: true },
        { k: '累计作业工时', v: `约 ${this.data.liveText}` },
      ],
      confirmText: '确认完工',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.cuttingRunAction(run.id, { action: 'finish', expected_version: run.business_version, lines, remark: String(this.data.finishRemark || '').trim() || null }).then(response => {
          wx.showToast({ title: '开料已完工，各工单可执行备料交接', icon: 'none', duration: 2500 });
          this.showRun(response.data);
          this.load();
        }).catch(error => view.modal(this, { theme: 'warn', title: '结算失败', content: view.errorText(error), showCancel: false }))
          .finally(() => this.setData({ busy: false }));
      } });
  },
  cancelRun() {
    const run = this.data.run;
    view.modal(this, {
      theme: 'danger',
      title: '作废当前开料批次',
      infoMain: `开料批次：${run.run_no || ''}`,
      infoSub: '仅在选错 BOM 且尚未领用板材时可作废；作废后本批次计时将不计入工时统计。确认作废？',
      confirmText: '确认作废',
      success: res => {
        if (!res.confirm) return;
        this.setData({ busy: true });
        production.cuttingRunAction(run.id, { action: 'cancel' }).then(() => { this.setData({ panel: '', run: null }); this.stopTick(); this.load(); })
          .catch(error => view.modal(this, { theme: 'warn', title: '作废失败', content: view.errorText(error), showCancel: false }))
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
