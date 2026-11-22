/**
 * 将纯文本复制到系统剪贴板，优先使用 Clipboard API，自动兼容非 HTTPS 本地环境降级。
 */
export async function copyPlainText(text) {
  const value = String(text == null ? '' : text)
  if (!value) return false

  if (typeof navigator !== 'undefined' && navigator.clipboard && typeof navigator.clipboard.writeText === 'function') {
    try {
      await navigator.clipboard.writeText(value)
      return true
    } catch (_) {
      // 降级到 execCommand
    }
  }

  if (typeof document === 'undefined') return false
  const textarea = document.createElement('textarea')
  textarea.value = value
  textarea.setAttribute('readonly', '')
  textarea.style.position = 'fixed'
  textarea.style.top = '-9999px'
  textarea.style.left = '-9999px'
  textarea.style.opacity = '0'
  document.body.appendChild(textarea)
  textarea.focus()
  textarea.select()
  let copied = false
  try {
    copied = document.execCommand('copy')
  } catch (_) {
    copied = false
  } finally {
    document.body.removeChild(textarea)
  }
  return copied
}
