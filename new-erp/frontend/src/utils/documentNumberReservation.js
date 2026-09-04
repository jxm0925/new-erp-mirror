import { reserveDocumentNumber } from '../api/erp/master'

const sessionKey = (documentType, page) => `erp:number-session:${documentType}:${page || 'create'}`

const uuid = () => {
  if (window.crypto && typeof window.crypto.randomUUID === 'function') return window.crypto.randomUUID()
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, c => {
    const r = Math.random() * 16 | 0
    return (c === 'x' ? r : (r & 0x3 | 0x8)).toString(16)
  })
}

export const reserveForCreatePage = async (documentType, page) => {
  const key = sessionKey(documentType, page)
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

export const reserveFreshDocumentNumber = async (documentType, page) => {
  const creationSessionId = uuid()
  const response = await reserveDocumentNumber({
    document_type: documentType,
    creation_session_id: creationSessionId,
    page
  })

  return {
    ...response.data.data,
    creation_session_id: creationSessionId
  }
}

export const clearCreatePageReservation = reservation => {
  if (reservation?.storage_key) sessionStorage.removeItem(reservation.storage_key)
}
