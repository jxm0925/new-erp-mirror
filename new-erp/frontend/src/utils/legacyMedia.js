const legacyMediaOrigin = (process.env.VUE_APP_LEGACY_MEDIA_ORIGIN || 'https://media.sdjiantan.com').replace(/\/$/, '')

/**
 * Old master data stores an object key such as /uploads/YYYYMMDD/file.jpg.
 * Runtime pages only read the new ERP database; this helper merely renders
 * the already-imported key through the legacy CDN and never triggers a sync.
 */
export function legacyMediaUrl (path) {
  const value = String(path || '').trim()
  if (!value) return ''
  if (/^https?:\/\//i.test(value)) return value
  return `${legacyMediaOrigin}/${value.replace(/^\/+/, '')}`
}
