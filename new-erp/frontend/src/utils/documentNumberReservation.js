import { reserveDocumentNumber } from '../api/erp/master'

const currentUserId = () => {
  try {
    const user = JSON.parse(localStorage.getItem('erp_user') || '{}')
    return Number(user.legacy_id || user.id || 0)
  } catch (_) { return 0 }
}

const sessionKey = (userId, documentType, page) => `erp:number-session:v2:${encodeURIComponent(process.env.VUE_APP_BASE_API || '')}:${userId}:${documentType}:${page || 'create'}`

const uuid = () => {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16)
  })
}

// 矩阵用规格区分缓存会话，发送后端的页面路径保持长度受限。
export const reserveForCreatePage = async (documentType, page, storageContext = page) => {
  const userId = currentUserId()
  const token = localStorage.getItem('erp_token')
  if (!token || !Number.isSafeInteger(userId) || userId <= 0) {
    throw Object.assign(new Error('请先登录后再新建单据'), { userMessage: '请先登录后再新建单据' })
  }
  // 旧缓存没有账号归属，不能给当前账号复用或迁移。
  const key = sessionKey(userId, documentType, storageContext)
  let creationSessionId = sessionStorage.getItem(key)
  if (!creationSessionId) {
    creationSessionId = uuid()
    sessionStorage.setItem(key, creationSessionId)
  }
  const response = await reserveDocumentNumber({
    document_type: documentType,
    creation_session_id: creationSessionId,
    page
  })
  if (localStorage.getItem('erp_token') !== token || currentUserId() !== userId) {
    throw Object.assign(new Error('登录账号已变化，请重新打开新增页面'), { userMessage: '登录账号已变化，请重新打开新增页面' })
  }
  return {
    ...response.data.data,
    creation_session_id: creationSessionId,
    storage_key: key
  }
}

export const clearCreatePageReservation = reservation => {
  if (reservation?.storage_key && reservation.creation_session_id
    && sessionStorage.getItem(reservation.storage_key) === reservation.creation_session_id) {
    sessionStorage.removeItem(reservation.storage_key)
  }
}
