const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
function fixture(platform = 'windows', statusCode = 200) {
  const calls = []; const module = { exports: {} };
  const native = name => options => { calls.push({ name, options }); options.success({}); };
  vm.runInNewContext(fs.readFileSync(require('node:path').join(__dirname, '../services/work-order-attachments.js'), 'utf8'), {
    module, require: () => ({ ERP_TOKEN_KEY: 'erp_token', buildUrl: p => 'https://erp.example/api/' + p }),
    wx: { getStorageSync: () => 'private-token', getDeviceInfo: () => ({ platform }),
      downloadFile: options => { calls.push({ name: 'download', options }); options.success({ statusCode, tempFilePath: '/tmp/file' }); },
      previewImage: native('previewImage'), openDocument: native('openDocument'), saveFileToDisk: native('saveFileToDisk'), shareFileMessage: native('shareFileMessage') },
  });
  return { service: module.exports, calls };
}
test('PDF uses scoped authenticated download then document viewer', async () => {
  const f = fixture(); await f.service.open(8, { id: 3, mime_type: 'application/pdf', original_name: '图纸.pdf', previewable: true });
  assert.equal(f.calls[0].options.url, 'https://erp.example/api/production/work-orders/8/technical-attachments/3');
  assert.equal(f.calls[0].options.header.Authorization, 'Bearer private-token');
  assert.equal(f.calls[1].name, 'openDocument'); assert.equal(f.calls[1].options.fileType, 'pdf');
});
test('CAD exports through platform file UI and preserves mobile filename', async () => {
  for (const platform of ['windows', 'ios']) {
    const f = fixture(platform); await f.service.open(8, { id: 4, original_name: '管件.dxf', previewable: false });
    assert.equal(f.calls[1].name, platform === 'windows' ? 'saveFileToDisk' : 'shareFileMessage');
    if (platform === 'ios') assert.equal(f.calls[1].options.fileName, '管件.dxf');
  }
});
test('denied download never opens or exports a response body', async () => {
  const f = fixture('windows', 403); await assert.rejects(f.service.open(8, { id: 3 }), /没有查看/); assert.equal(f.calls.length, 1);
});
