// Preserve the original decision, quantity and version until its result is known.
// Reloading or editing a form must never turn an uncertain write into a second command.
export function completionCommandStore(storage, key) {
  return {
    read() {
      const raw = storage.getItem(key)
      if (!raw) return null
      const job = JSON.parse(raw)
      if (!job || !job.id || !job.payload || !job.payload.client_command_id) throw new Error('上次操作记录无法读取，请联系管理员核对结果。')
      return job
    },
    prepare(job) {
      const previous = this.read()
      if (previous) {
        if (Number(previous.id) !== Number(job.id)) throw new Error('请先确认上次操作结果。')
        return previous
      }
      storage.setItem(key, JSON.stringify(job))
      return this.read()
    },
    clear() { storage.removeItem(key) }
  }
}

export function completionErrorIsDefinitive(error) {
  const status = error.response && error.response.status
  return status >= 400 && status < 500 && ![401, 403, 408, 429].includes(status)
    && !['command_processing', 'command_recovery_required'].includes(error.errorCode)
}
