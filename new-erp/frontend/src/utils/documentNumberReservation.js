import { reserveDocumentNumber } from '../api/erp/master'

const sessionKey = (documentType, page) => `erp:number-session:${documentType}:${page || 'create'}`

const uuid = () => {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16)
  })
}

// 矩阵用规格区分缓存会话，发送后端的页面路径保持长度受限。
export const reserveForCreatePage = async (documentType, page, storageContext = page) => {
  const key = sessionKey(documentType, storageContext)
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
