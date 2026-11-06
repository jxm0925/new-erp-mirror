const warehouse = require('../../services/warehouse');
const request = require('../../utils/erp-request');
const ui = require('../../utils/warehouse-page');
const { getErpApiBaseUrl } = require('../../config/erp');

const ENDPOINT = 'inventory/shipment-outbound-applications';
const STATUS = {
  pending: '待审核',
  approved: '审核通过',
  rejected: '已驳回',
  posted: '已出库',
  withdrawn: '已撤回',
};

function describe(row) {
  return Object.assign({}, row, {
    kindLabel: row.kind === 'materials' ? '包装用料' : (row.kind === 'products' ? '打包缺项领用' : '原发货申请'),
    statusLabel: row.kind === 'products' && row.status === 'posted' ? '已交付' : (STATUS[row.status] || '状态待确认'),
    createdAt: ui.dateTime(row.created_at),
    deliveryLabel: { dispatch: '发货', pickup: '自提' }[row.delivery_mode] || '',
    lines: (row.lines || []).map((line, index) => Object.assign({}, line, {
      key: index,
      quantityText: ui.quantity(line.quantity),
      serialText: (line.serial_nos || []).join('、'),
      physicalText: (line.physical_nos || []).join('、'),
    })),
  });
}

function scope() {
  const user = wx.getStorageSync('erp_user') || {};
  const id = Number(user.legacy_id || user.id || 0);
  return id ? `erp_shipment_outbound_review:${encodeURIComponent(getErpApiBaseUrl())}:${id}` : '';
}

Component({
  data: {
    status: 'pending', keyword: '', page: 1, lastPage: 1, total: 0, rows: [],
    detailId: 0, detail: null, decision: '', reason: '', pendingDecision: '',
    loading: false, busy: false, error: '',
  },
  lifetimes: {
    attached() {
      this.active = true;
      this.token = wx.getStorageSync('erp_token');
      this.reviewScope = scope();
      this.searchKeyword = '';
      this.load();
    },
    detached() {
      this.active = false;
    },
  },
  methods: {
    sameSession() {
      return this.token && this.token === wx.getStorageSync('erp_token') && this.reviewScope === scope();
    },
    stop() {},
    close() {
      if (!this.data.busy) this.triggerEvent('close');
    },
    async load() {
      const { detailId, status, page } = this.data;
      const sequence = this.sequence = (this.sequence || 0) + 1;
      this.setData({ loading: true, error: '' });
      try {
        if (!this.sameSession()) throw new Error('登录状态已变化，请重新打开审核');
        const response = detailId
          ? await warehouse.get(`${ENDPOINT}/${detailId}`)
          : await warehouse.get(ENDPOINT, { status, keyword: this.searchKeyword, page, per_page: 10 });
        if (!this.active || sequence !== this.sequence) return;
        if (!this.sameSession()) throw new Error('登录状态已变化，请重新打开审核');
        if (detailId) {
          const detail = describe(response.data);
          const key = `${this.reviewScope}:${detailId}`;
          const pending = this.reviewScope && wx.getStorageSync(key);
          if (pending && detail.status !== 'pending') wx.removeStorageSync(key);
          this.setData({
            detail, loading: false,
            pendingDecision: pending && detail.can_review ? pending.decision : '',
          });
          return;
        }
        const parsed = ui.rows(response);
        if (parsed.page > parsed.lastPage) {
          this.setData({ page: parsed.lastPage });
          return this.load();
        }
        this.setData(Object.assign({}, parsed, { loading: false, rows: parsed.rows.map(describe) }));
      } catch (error) {
        if (this.active && sequence === this.sequence) {
          this.setData({ loading: false, detail: null, error: ui.errorText(error) });
        }
      }
    },
    keywordInput(event) {
      this.setData({ keyword: event.detail.value });
    },
    search() {
      this.searchKeyword = this.data.keyword.trim();
      this.setData({ page: 1 });
      this.load();
    },
    switchStatus(event) {
      const status = event.currentTarget.dataset.status;
      if (status === this.data.status) return;
      this.setData({ status, page: 1 });
      this.load();
    },
    turnPage(event) {
      const page = this.data.page + Number(event.currentTarget.dataset.delta);
      if (this.data.loading || page < 1 || page > this.data.lastPage) return;
      this.setData({ page });
      this.load();
    },
    openDetail(event) {
      const id = Number(event.currentTarget.dataset.id);
      if (!id || this.data.detailId) return;
      this.setData({ detailId: id, detail: null, pendingDecision: '', decision: '', reason: '' });
      this.load();
    },
    backToQueue() {
      if (this.data.busy) return;
      this.setData({ detailId: 0, detail: null, pendingDecision: '', decision: '', reason: '' });
      this.load();
    },
    chooseDecision(event) {
      if (this.data.busy || this.data.loading || !this.data.detail || !this.data.detail.can_review) return;
      this.setData({ decision: event.currentTarget.dataset.decision, error: '' });
    },
    reasonInput(event) {
      this.setData({ reason: event.detail.value });
    },
    cancelDecision() {
      if (!this.data.busy) this.setData({ decision: '', error: '' });
    },
    async submit() {
      const { detail, detailId, decision, pendingDecision, reason, busy, loading } = this.data;
      if (busy || loading || !detail || !detail.can_review || (!decision && !pendingDecision)) return;
      if (!this.sameSession() || !this.reviewScope) {
        this.setData({ detail: null, error: '登录状态已变化，请重新打开审核' });
        return;
      }

      const key = `${this.reviewScope}:${detailId}`;
      this.setData({ busy: true, error: '' });
      let job;
      try {
        job = wx.getStorageSync(key);
        if (!job) {
          if (decision === 'reject' && !reason.trim()) {
            this.setData({ error: '请填写驳回原因' });
            return;
          }
          if (!decision) {
            await this.load();
            return;
          }
          job = {
            decision, reason: decision === 'reject' ? reason.trim() : '',
            client_command_id: request.createClientCommandId('shipment-review'),
          };
          // 审核可能已经扣库，重试须复用原决定和命令编号。
          wx.setStorageSync(key, job);
        }
        const { data } = await request.write(`${ENDPOINT}/${detailId}/review`, job, { loading: false });
        if (!this.sameSession()) throw new Error('登录状态已变化，请重新打开审核');
        const reviewed = describe(data);
        wx.removeStorageSync(key);
        if (!this.active) return;
        this.setData({ detail: reviewed, pendingDecision: '', decision: '', reason: '' });
        wx.showToast({ title: job.decision === 'approve' ? '审核通过' : '已驳回申请', icon: 'none' });
      } catch (error) {
        if (!this.active) return;
        if (!this.sameSession()) {
          this.setData({ detail: null, error: '登录状态已变化，请重新打开审核' });
          return;
        }
        if ([400, 403, 404, 409, 422].includes(error.statusCode)
          && !['command_processing', 'idempotency_hash_conflict'].includes(error.errorCode)) {
          wx.removeStorageSync(key);
          this.setData({ pendingDecision: '', decision: '' });
          await this.load();
        } else if (job) {
          this.setData({ pendingDecision: job.decision });
        }
        if (this.active) this.setData({ error: ui.errorText(error) });
      } finally {
        if (this.active) this.setData({ busy: false });
      }
    },
  },
});
