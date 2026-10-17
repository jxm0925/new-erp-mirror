import { createErpApi } from './http'
import { completionCommandStore, completionErrorIsDefinitive } from '@/utils/completionCommand'

const api = createErpApi({ fallbackMessage: '操作未完成，请核对后重试原操作' })
export const executionBase = '/v1/erp/production'
export const targetPath = (taskId, target) => `${executionBase}/tasks/${taskId}/targets/${target.target_type}/${target.target_id}`
export const getExecutionTask = id => api.get(`${executionBase}/tasks/${id}`)
export const getKittingRequirements = (id, target) => api.get(`${targetPath(id, target)}/kitting-requirements`)
export const getCuttingOperation = (id, target, params) => api.get(`${targetPath(id, target)}/cutting`, { params })
export const getCuttingMaterials = (id, target, params) => api.get(`${targetPath(id, target)}/cutting/materials`, { params })
export const getPendingHandovers = params => api.get(`${executionBase}/handovers/pending`, { params })
export const getExecutionOutputs = params => api.get(`${executionBase}/outputs`, { params })
export const getExecutionOutput = id => api.get(`${executionBase}/outputs/${id}`)
export const getCompletionPreflight = id => api.get(`${executionBase}/work-orders/${id}/completion-preflight`)

function actorPrefix() {
  const user = JSON.parse(localStorage.getItem('erp_user') || '{}')
  return `erp_pc_execution_${Number(user.legacy_id || user.id || 0)}_`
}

function commandStore(method, path) {
  return completionCommandStore(localStorage, actorPrefix() + method.toUpperCase() + '_' + path)
}

export function pendingExecutionCommands(prefix = executionBase) {
  const actor = actorPrefix()
  return Object.keys(localStorage).filter(key => key.startsWith(actor)).map(key => completionCommandStore(localStorage, key).read())
    .filter(job => job && (job.path === prefix || job.path.startsWith(prefix.endsWith('/') ? prefix : prefix + '/')))
}

export async function writeExecutionCommand(method, path, id, payload, context = {}) {
  const store = commandStore(method, path)
  const job = store.prepare({ id, method: method.toUpperCase(), path, task_id: context.task_id || null, payload: {
    ...payload, client_command_id: payload.client_command_id || `pc-execution-${window.crypto.randomUUID ? window.crypto.randomUUID() : Date.now() + '-' + Math.random().toString(16).slice(2)}`
  } })
  try {
    const result = await api.request({ method: job.method, url: job.path, data: job.payload })
    store.clear()
    return result
  } catch (error) {
    if (completionErrorIsDefinitive(error) && error.errorCode !== 'recovery_required') store.clear()
    error.executionCommand = job
    error.pendingCommand = !!store.read()
    throw error
  }
}

export const retryExecutionCommand = job => writeExecutionCommand(job.method, job.path, job.id, job.payload)
