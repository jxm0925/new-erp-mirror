const util = require('./util.js');

const pad = value => String(value).padStart(2, '0');

function stopClock(page) {
  clearInterval(page.workClockInterval);
  page.workClockInterval = null;
}

function renderClock(page) {
  const clock = page.workClock;
  // 基于收到快照后经过的时间更新显示，避免依赖手机与服务端的时钟同步。
  const advance = clock.running ? Math.max(0, Date.now() - clock.receivedAt) : 0;
  const seconds = clock.elapsed + Math.floor(advance / 1000);
  const hours = pad(Math.floor(seconds / 3600));
  const minutes = pad(Math.floor(seconds % 3600 / 60));
  const remainder = pad(seconds % 60);
  page.setData({ timerClock: { hours, minutes, seconds: remainder, text: `${hours}:${minutes}:${remainder}` } });
}

function startClock(page) {
  stopClock(page);
  if (page.workPageHidden || !page.workClock) return;
  renderClock(page);
  if (page.workClock.running) {
    page.workClockInterval = setInterval(() => renderClock(page), 1000);
  }
}

function applyClock(page, record) {
  const status = record && Number.isFinite(record.elapsed_seconds) ? Number(record.status) : 0;
  const timerStatus = status === 3 ? 2 : status;
  page.workClock = timerStatus ? {
    running: timerStatus === 2,
    elapsed: Math.max(0, Math.floor(record.elapsed_seconds)),
    receivedAt: Date.now(),
    startedAt: record.started_at,
    version: record.timer_version,
  } : null;
  page.setData({ timerStatus, timerCanControl: Boolean(timerStatus && record.can_control) });
  startClock(page);
}

module.exports = function legacyWorkTimer({ infoUrl, pauseUrl, continueUrl, procedureKey }, notify) {
  return {
    onShow() {
      this.workPageHidden = false;
      if (this.workPageShown) {
        startClock(this);
        this.getInfo(true);
      }
      this.workPageShown = true;
    },
    onHide() {
      this.workPageHidden = true;
      stopClock(this);
    },
    onUnload() {
      this.workPageUnloaded = true;
      stopClock(this);
    },
    workProcedureIdentity() {
      const identity = { procedure: this.data.info[procedureKey] };
      if (this.workClock && this.workClock.startedAt > 0) identity.started_at = this.workClock.startedAt;
      return identity;
    },
    async getInfo(silent = false) {
      if (this.workPageUnloaded) return;
      const requestId = (this.workInfoRequestId || 0) + 1;
      this.workInfoRequestId = requestId;
      try {
        const res = await util.request(infoUrl, { id: this.data.id }, 'POST', undefined, 0, { loading: !silent });
        if (this.workPageUnloaded || requestId !== this.workInfoRequestId) return;
        if (!res.code) throw new Error(res.msg || '无法获取工单详情，请重试');
        const current = res.data.info;
        const previous = this.data.info;
        const keepForm = silent && previous && current[procedureKey] === previous[procedureKey]
          && current.step_status === previous.step_status && current.step_admin === previous.step_admin;
        this.applyWorkInfo(res.data, keepForm);
        applyClock(this, res.data.time_record);
      } catch (error) {
        if (!this.workPageHidden && !this.workPageUnloaded && requestId === this.workInfoRequestId) {
          notify({ type: 'warning', message: error.message || '无法获取工单详情，请重试' });
        }
      }
    },
    async switchTimeButton(event) {
      const pause = event.currentTarget.dataset.type === 'paused';
      const { info, timerBusy, timerCanControl, timerStatus } = this.data;
      if (timerBusy || !timerCanControl || timerStatus !== (pause ? 2 : 1)) return;
      const clock = this.workClock;
      this.setData({ timerBusy: true });
      try {
        const res = await util.request(pause ? pauseUrl : continueUrl, {
          id: info.id,
          ...this.workProcedureIdentity(),
          timer_version: clock.version,
        });
        if (!res.code) throw new Error(res.msg || '计时操作失败，请重试');
        if (!this.workPageUnloaded && this.workClock === clock && res.data && res.data.time_record) {
          applyClock(this, res.data.time_record);
        }
      } catch (error) {
        if (!this.workPageHidden && !this.workPageUnloaded) {
          notify({ type: 'warning', message: error.message || '计时操作失败，请重试' });
        }
      } finally {
        // 请求超时也先同步服务端状态，避免在客户端重复切换。
        await this.getInfo(true);
        if (!this.workPageUnloaded) this.setData({ timerBusy: false });
      }
    },
  };
};
