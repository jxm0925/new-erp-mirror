import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

let echo = null
const subscriptions = new Map()

function socketConfig () {
  const host = ['localhost', '127.0.0.1'].includes(window.location.hostname) ? '127.0.0.1' : window.location.hostname
  return {
    host,
    port: Number(process.env.VUE_APP_REVERB_PORT || 8083),
    token: localStorage.getItem('erp_token')
  }
}

function endpointFor ({ host, port }) {
  return `ws://${host}:${port}`
}

function exposeRuntimeStatus (config, reason = null) {
  if (typeof window === 'undefined') return
  // Diagnostic state only: useful in DevTools and never used as a polling mechanism.
  window.Echo = echo || undefined
  window.__erpRealtime = {
    endpoint: endpointFor(config),
    subscribedChannels: [...new Set([...subscriptions.keys()].map(key => key.slice(0, key.indexOf(':'))))],
    get state () { return echo?.connector?.pusher?.connection?.state || 'disconnected' },
    ...(reason ? { reason } : {})
  }
  // Compatibility alias for the existing inventory alert support instructions.
  window.__erpInventoryAlertRealtime = {
    channel: 'private-inventory-alerts',
    event: '.inventory.alert.changed',
    endpoint: endpointFor(config),
    get state () { return echo?.connector?.pusher?.connection?.state || 'disconnected' },
    ...(reason ? { reason } : {})
  }
}

function ensureEcho () {
  const current = socketConfig()
  if (!current.token) {
    exposeRuntimeStatus(current, 'missing-authentication')
    return null
  }
  if (!echo) {
    window.Pusher = Pusher
    echo = new Echo({
      broadcaster: 'reverb',
      key: process.env.VUE_APP_REVERB_APP_KEY || 'erp-local-key-20260820',
      wsHost: current.host,
      wsPort: current.port,
      wssPort: current.port,
      forceTLS: false,
      enabledTransports: ['ws', 'wss'],
      authEndpoint: `${process.env.VUE_APP_BASE_API}/broadcasting/auth`,
      auth: { headers: { Authorization: `Bearer ${current.token}` } }
    })
  }
  exposeRuntimeStatus(current)
  return echo
}

function subscriptionKey (channel, event) {
  return `${channel}:${event}`
}

function channelHasSubscriptions (channel) {
  return [...subscriptions.keys()].some(key => key.slice(0, key.indexOf(':')) === channel)
}

/**
 * Registers a real private WebSocket subscription. Future domains (approval,
 * work order, etc.) reuse this API but must still expose a server-side channel
 * authorization rule in routes/channels.php.
 */
export function subscribePrivate (channel, event, callback) {
  const client = ensureEcho()
  if (!client) return () => {}

  const key = subscriptionKey(channel, event)
  let subscription = subscriptions.get(key)
  if (!subscription) {
    const callbacks = new Set()
    const relay = payload => callbacks.forEach(handler => handler(payload))
    client.private(channel).listen(event, relay)
    subscription = { callbacks, relay }
    subscriptions.set(key, subscription)
    exposeRuntimeStatus(socketConfig())
  }

  subscription.callbacks.add(callback)
  return () => {
    const active = subscriptions.get(key)
    if (!active) return
    active.callbacks.delete(callback)
    if (active.callbacks.size === 0) {
      subscriptions.delete(key)
      if (!channelHasSubscriptions(channel)) client.leave(`private-${channel}`)
      exposeRuntimeStatus(socketConfig())
    }
  }
}

// Existing inventory code keeps its approved channel and event unchanged.
export function connectInventoryAlerts (callback) {
  return subscribePrivate('inventory-alerts', '.inventory.alert.changed', callback)
}

export function connectApprovalTasks (userId, callback) {
  if (!userId) return () => {}
  return subscribePrivate(`approval-user.${userId}`, '.approval.task.changed', callback)
}

export function disconnectRealtime () {
  if (echo) {
    const channels = new Set([...subscriptions.keys()].map(key => key.slice(0, key.indexOf(':'))))
    channels.forEach(channel => echo.leave(`private-${channel}`))
    echo.disconnect()
  }
  echo = null
  subscriptions.clear()
  if (typeof window !== 'undefined') {
    window.Echo = undefined
    window.__erpRealtime = undefined
    window.__erpInventoryAlertRealtime = undefined
  }
}
