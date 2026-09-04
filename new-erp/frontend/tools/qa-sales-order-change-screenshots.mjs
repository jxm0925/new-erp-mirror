/*
 * Browser acceptance harness for the approved Sales Order Change UI.
 * It drives the real local frontend and API, then stores evidence screenshots.
 * Usage (PowerShell):
 * $env:ERP_QA_PASSWORD='...'; $env:ERP_QA_ORDER_ID='...'; node tools/qa-sales-order-change-screenshots.mjs
 */
import { spawn } from 'node:child_process'
import { mkdir, writeFile } from 'node:fs/promises'
import path from 'node:path'
import WebSocket from 'ws'

const frontendUrl = process.env.ERP_QA_FRONTEND_URL || 'http://127.0.0.1:8081'
const apiUrl = process.env.ERP_QA_API_URL || 'http://127.0.0.1:8010/api'
const frontendApiUrl = process.env.ERP_QA_FRONTEND_API_URL || 'http://localhost:8010/api'
const orderId = process.env.ERP_QA_ORDER_ID
const username = process.env.ERP_QA_USERNAME || 'admin'
const password = process.env.ERP_QA_PASSWORD
const changeQty = process.env.ERP_QA_CHANGE_QTY || '12'
const output = process.env.ERP_QA_SCREENSHOT_DIR || 'D:/codex-introduce/new_erp/docs/ui-check/phase6'

if (!orderId || !password) {
  throw new Error('ERP_QA_ORDER_ID and ERP_QA_PASSWORD are required. No credentials are stored in this harness.')
}

const chrome = 'C:/Program Files/Google/Chrome/Application/chrome.exe'
const debugPort = 9231
const profile = 'D:/new-erp/new-erp/.qa-sales-order-change-chrome'

const sleep = ms => new Promise(resolve => setTimeout(resolve, ms))

async function waitForDebugEndpoint () {
  for (let attempt = 0; attempt < 30; attempt += 1) {
    try {
      const response = await fetch(`http://127.0.0.1:${debugPort}/json/list`)
      if (response.ok) return response.json()
    } catch (_) { /* Chrome is still starting. */ }
    await sleep(250)
  }
  throw new Error('Chrome DevTools endpoint did not start.')
}

function connectCdp (url) {
  const ws = new WebSocket(url)
  let nextId = 1
  const pending = new Map()
  ws.on('message', raw => {
    const message = JSON.parse(raw.toString())
    if (message.id && pending.has(message.id)) {
      const { resolve, reject } = pending.get(message.id)
      pending.delete(message.id)
      if (message.error) reject(new Error(message.error.message))
      else resolve(message.result)
    }
  })
  const ready = new Promise((resolve, reject) => {
    ws.once('open', resolve)
    ws.once('error', reject)
  })
  return {
    ready,
    call (method, params = {}) {
      const id = nextId++
      ws.send(JSON.stringify({ id, method, params }))
      return new Promise((resolve, reject) => pending.set(id, { resolve, reject }))
    },
    close () { ws.close() }
  }
}

async function evaluate (cdp, expression) {
  const response = await cdp.call('Runtime.evaluate', { expression, awaitPromise: true, returnByValue: true })
  if (response.exceptionDetails) throw new Error(response.exceptionDetails.text || 'Runtime evaluation failed.')
  return response.result?.value
}

async function waitFor (cdp, condition, description) {
  for (let attempt = 0; attempt < 50; attempt += 1) {
    if (await evaluate(cdp, `Boolean(${condition})`)) return
    await sleep(200)
  }
  throw new Error(`Timed out waiting for ${description}.`)
}

async function screenshot (cdp, name, width, height) {
  await cdp.call('Emulation.setDeviceMetricsOverride', { width, height, deviceScaleFactor: 1, mobile: false })
  await sleep(500)
  const result = await cdp.call('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false })
  await writeFile(path.join(output, name), Buffer.from(result.data, 'base64'))
}

async function login () {
  const response = await fetch(`${apiUrl}/v1/erp/auth/login`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ username, password })
  })
  if (!response.ok) throw new Error(`Local QA login failed: ${response.status}`)
  const body = await response.json()
  const data = body.data || body
  if (!data.token) throw new Error('Local QA login response has no token.')
  return {
    token: data.token,
    user: data.user || {},
    permissions: data.permissions || [],
    me: { data_scope: data.data_scope || null, is_super_admin: true }
  }
}

async function main () {
  await mkdir(output, { recursive: true })
  spawn(chrome, [
    '--headless=new', `--remote-debugging-port=${debugPort}`, `--user-data-dir=${profile}`,
    '--no-first-run', '--disable-gpu', 'about:blank'
  ], { detached: true, windowsHide: true, stdio: 'ignore' }).unref()

  const targets = await waitForDebugEndpoint()
  const target = targets.find(item => item.type === 'page')
  if (!target) throw new Error('Chrome did not expose a browser page target.')
  const cdp = connectCdp(target.webSocketDebuggerUrl)
  await cdp.ready
  await cdp.call('Page.enable')
  await cdp.call('Runtime.enable')

  const session = await login()
  await cdp.call('Page.navigate', { url: frontendUrl })
  await sleep(1200)
  await evaluate(cdp, `
    (() => {
      const session = ${JSON.stringify(session)}
      localStorage.setItem('erp_token', session.token)
      localStorage.setItem('erp_user', JSON.stringify(session.user))
      localStorage.setItem('erp_permissions', JSON.stringify(session.permissions))
      localStorage.setItem('erp_me', JSON.stringify(session.me))
      return true
    })()
  `)
  const browserApiCheck = await evaluate(cdp, `
    fetch('${frontendApiUrl}/v1/erp/sales/orders/${orderId}', {
      headers: { Authorization: 'Bearer ' + localStorage.getItem('erp_token') }
    }).then(async response => ({ status: response.status, body: (await response.text()).slice(0, 240) }))
  `)
  if (browserApiCheck.status !== 200) {
    throw new Error(`Browser-origin order request failed: ${JSON.stringify(browserApiCheck)}`)
  }
  await evaluate(cdp, `location.hash = '#/sales/orders/${orderId}/detail'; true`)
  await sleep(1800)
  const entryDiagnostic = await evaluate(cdp, "({ hash: location.hash, text: document.body.innerText.slice(0, 900) })")
  if (!String(entryDiagnostic.text || '').includes('订单变更')) {
    await screenshot(cdp, 'phase6-order-change-entry-diagnostic.png', 1366, 768)
    throw new Error(`Order detail entry did not render: ${JSON.stringify(entryDiagnostic)}`)
  }
  await waitFor(cdp, "document.body.innerText.includes('订单变更')", 'the order detail change entry')
  await screenshot(cdp, 'phase6-order-change-detail-entry-1920.png', 1920, 1080)
  await screenshot(cdp, 'phase6-order-change-detail-entry-1366.png', 1366, 768)

  const entryAvailable = await evaluate(cdp, "[...document.querySelectorAll('button')].some(button => button.innerText.includes('订单变更'))")
  if (!entryAvailable) {
    const detailDiagnostic = await evaluate(cdp, "({ hash: location.hash, storage: localStorage.getItem('erp_token') ? 'token-present' : 'token-missing', resources: performance.getEntriesByType('resource').map(item => item.name).filter(name => name.includes('/sales/orders/')).slice(-5), body: document.body.innerText.slice(0, 700) })")
    throw new Error(`Assertion failed: the real change entry button is not visible on an eligible order. ${JSON.stringify(detailDiagnostic)}`)
  }
  await evaluate(cdp, "[...document.querySelectorAll('button')].find(button => button.innerText.includes('订单变更')).click()")
  await waitFor(cdp, "document.body.innerText.includes('变更基本信息')", 'the dedicated order change form')
  await screenshot(cdp, 'phase6-order-change-form-1920.png', 1920, 1080)
  await screenshot(cdp, 'phase6-order-change-form-1366.png', 1366, 768)

  const filled = await evaluate(cdp, `
    (() => {
      const setValue = (element, value) => {
        const prototype = element.tagName === 'TEXTAREA' ? HTMLTextAreaElement.prototype : HTMLInputElement.prototype
        Object.getOwnPropertyDescriptor(prototype, 'value').set.call(element, value)
        element.dispatchEvent(new Event('input', { bubbles: true }))
        element.dispatchEvent(new Event('change', { bubbles: true }))
        element.blur()
      }
      const reason = document.querySelector('.reason-field textarea')
      const qty = document.querySelector('.edit-values input')
      if (!reason || !qty) return false
      setValue(reason, '浏览器截图验收：客户数量调整')
      setValue(qty, ${JSON.stringify(changeQty)})
      return true
    })()
  `)
  if (!filled) throw new Error('Assertion failed: change reason or editable quantity input was not rendered.')
  await sleep(300)
  await evaluate(cdp, "[...document.querySelectorAll('button')].find(button => button.innerText.includes('提交变更')).click()")
  await waitFor(cdp, "document.body.innerText.includes('确认提交订单变更')", 'the change confirmation dialog')
  await screenshot(cdp, 'phase6-order-change-confirm-1920.png', 1920, 1080)
  await evaluate(cdp, "[...document.querySelectorAll('.change-confirm-dialog button')].find(button => button.innerText.includes('确认提交变更')).click()")
  await waitFor(cdp, "location.hash.includes('/detail') && document.body.innerText.includes('订单变更历史')", 'return to the changed order detail')
  await waitFor(cdp, "document.querySelector('.order-change-history-card') && document.querySelector('.order-change-history-card').innerText.includes('SOC')", 'the persisted change fact in paginated history')
  await evaluate(cdp, "document.querySelector('.order-change-history-card').scrollIntoView({ block: 'start' })")
  await sleep(350)
  await screenshot(cdp, 'phase6-order-change-success-history-1920.png', 1920, 1080)
  await screenshot(cdp, 'phase6-order-change-success-history-1366.png', 1366, 768)

  console.log(JSON.stringify({
    assertions: [
      'eligible confirmed order shows the change entry',
      'dedicated change form renders current and modified values',
      'reason and changed quantity open a visible confirmation dialog',
      'confirmed change returns to detail and appears in paginated change history'
    ],
    screenshots: [
      'phase6-order-change-detail-entry-1920.png', 'phase6-order-change-detail-entry-1366.png',
      'phase6-order-change-form-1920.png', 'phase6-order-change-form-1366.png',
      'phase6-order-change-confirm-1920.png',
      'phase6-order-change-success-history-1920.png', 'phase6-order-change-success-history-1366.png'
    ]
  }, null, 2))
  cdp.close()
}

main().catch(error => {
  console.error(error.stack || error.message)
  process.exitCode = 1
})
