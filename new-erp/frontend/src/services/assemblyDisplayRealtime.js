import Echo from 'laravel-echo'
import Pusher from 'pusher-js'

export function connectAssemblyDisplay(onEvent, onState) {
  const secure = window.location.protocol === 'https:'
  const host = ['localhost', '127.0.0.1'].includes(window.location.hostname) ? '127.0.0.1' : window.location.hostname
  const echo = new Echo({
    broadcaster: 'reverb',
    client: new Pusher(process.env.VUE_APP_REVERB_APP_KEY || 'erp-local-key-20260820', {
      wsHost: host,
      wsPort: Number(process.env.VUE_APP_REVERB_PORT || 8083),
      wssPort: Number(process.env.VUE_APP_REVERB_PORT || 8083),
      forceTLS: secure,
      enabledTransports: secure ? ['wss'] : ['ws'],
      cluster: process.env.VUE_APP_REVERB_CLUSTER || 'mt1',
      disableStats: true
    })
  })
  const connection = echo.connector.pusher.connection
  let subscribed = false
  const changed = ({ current }) => {
    if (current !== 'connected') subscribed = false
    onState(current === 'connected' && !subscribed ? 'connecting' : current)
  }
  const failed = () => { subscribed = false; onState('failed') }
  connection.bind('state_change', changed)
  connection.bind('error', failed)
  echo.channel('assembly-display').listen('.assembly-display.changed', onEvent).subscribed(() => {
    subscribed = true
    onState('connected')
  }).error(failed)
  onState(connection.state === 'connected' ? 'connecting' : connection.state)
  return () => {
    connection.unbind('state_change', changed)
    connection.unbind('error', failed)
    echo.leave('assembly-display')
    echo.disconnect()
  }
}
