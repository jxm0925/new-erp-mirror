const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const pageDir = path.join(__dirname, '../pages/my/index');
const wxml = fs.readFileSync(path.join(pageDir, 'index.wxml'), 'utf8');
const wxss = fs.readFileSync(path.join(pageDir, 'index.wxss'), 'utf8');
const source = fs.readFileSync(path.join(pageDir, 'index.js'), 'utf8');

test('个人中心只在头像信息后提供退出按钮', () => {
  assert.doesNotMatch(wxml, /erp-auth-card|统一登录状态|完成统一登录后/);
  assert.match(
    wxml,
    /class="portrait-box"[\s\S]*class="info-box"[\s\S]*class="profile-logout"[\s\S]*catchtap="logoutAll"[\s\S]*>退出<\/button>/
  );
  assert.match(wxml, /wx:if="\{\{userInfo\.is_login \|\| unifiedLoggedIn\}\}"/);
});

test('头像、信息和退出按钮具备对齐及窄屏防挤压规则', () => {
  assert.match(wxss, /\.user-info-box\{[^}]*align-items:center[^}]*min-width: 0/);
  assert.match(wxss, /\.portrait-box\{[^}]*flex: 0 0 auto/);
  assert.match(wxss, /\.info-box\{[^}]*flex: 1 1 auto[^}]*min-width: 0/);
  assert.match(wxss, /\.profile-logout\{[^}]*flex: 0 0 auto/);
  assert.match(wxss, /@media screen and \(max-width: 360px\)/);
});

test('个人中心登录状态同时依赖旧业务与 ERP 会话', () => {
  assert.match(source, /unifiedLoggedIn:Boolean\(wx\.getStorageSync\('token'\) && wx\.getStorageSync\('erp_token'\)\)/);
  assert.match(source, /erpAuth\.logoutAll\(\)/);
});
