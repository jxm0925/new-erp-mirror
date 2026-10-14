// 后端 Eloquent 时间统一序列化为 UTC ISO（如 2026-10-12T05:45:34.000000Z），
// 部分 DB::table 查询则直接返回数据库会话时区（与 APP_TIMEZONE 一致）的 "Y-m-d H:i:s" 字符串。
// 页面不能简单截掉 "T"/"Z"，否则 ISO 时间会按 UTC 显示、与业务时间相差数小时。
// 这里只把带时区标记的 ISO 时间换算到业务时区，其余字符串原样截取，保证两种来源显示一致。
//
// 业务时区：设置了 VUE_APP_TIME_ZONE 时使用该值（.env.production 固定为 Asia/Shanghai），
// 否则使用浏览器所在时区。开发期本机为美东时间，交付时按 .env.production 回到北京时间。
const BUSINESS_TIME_ZONE = (typeof process !== 'undefined' && process.env && process.env.VUE_APP_TIME_ZONE) || undefined
const ZONED_ISO = /^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+-]\d{2}:?\d{2})$/

const formatters = new Map()
function partsFormatter(timeZone) {
  const key = timeZone || 'local'
  if (!formatters.has(key)) {
    formatters.set(key, new Intl.DateTimeFormat('en-CA', {
      timeZone,
      year: 'numeric', month: '2-digit', day: '2-digit',
      hour: '2-digit', minute: '2-digit', second: '2-digit', hourCycle: 'h23'
    }))
  }
  return formatters.get(key)
}

export function erpDateTime(value, length = 19, timeZone = BUSINESS_TIME_ZONE) {
  if (value === null || value === undefined || value === '') return ''
  const text = String(value).trim()
  if (ZONED_ISO.test(text)) {
    const date = new Date(text)
    if (!Number.isNaN(date.getTime())) {
      const p = Object.fromEntries(partsFormatter(timeZone).formatToParts(date).map(part => [part.type, part.value]))
      return `${p.year}-${p.month}-${p.day} ${p.hour}:${p.minute}:${p.second}`.slice(0, length)
    }
  }
  return text.replace('T', ' ').replace(/\.\d+$/, '').slice(0, length)
}
