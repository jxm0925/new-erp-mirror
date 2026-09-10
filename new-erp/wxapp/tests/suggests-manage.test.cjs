const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

function mount(apiMock = {}, storageData = {}) {
  let page;
  let rawSource = fs.readFileSync(path.join(__dirname, '../pages/suggests/manage/index.js'), 'utf8');
  // strip import statements for pure cjs vm execution
  const source = rawSource
    .replace(/import Dialog from [^\n]+;?/g, 'const Dialog = { confirm: () => Promise.resolve() };')
    .replace(/import Notify from [^\n]+;?/g, 'const Notify = () => {};');

  const storage = Object.assign({
    userInfo: { user_id: 101, name: '张委员', is_login: true }
  }, storageData);

  const mockApi = {
    FeedbackManageList: 'mock/feedback/manage/list',
    AcceptFeedback: 'mock/feedback/manage/accept',
    FeedbackInfo: 'mock/feedback/index/info'
  };

  const mockUtil = {
    request: (url, data) => {
      if (apiMock[url]) {
        return apiMock[url](data);
      }
      return Promise.resolve({ code: 1, data: { data: [], current_page: 1, last_page: 1 } });
    }
  };

  vm.runInNewContext(source, {
    Page: value => { page = value; },
    require: (mod) => {
      if (mod.includes('util.js')) return mockUtil;
      if (mod.includes('api.js')) return mockApi;
      return {};
    },
    wx: {
      getStorageSync: key => storage[key] || null,
      setStorageSync: (key, val) => { storage[key] = val; },
      showToast() {},
      navigateTo() {},
      previewImage() {},
      stopPullDownRefresh() {}
    },
    console,
    Date,
    Math,
    Number,
    String,
    Object,
    Array,
    Set,
    Promise,
    setTimeout
  });

  page.data = JSON.parse(JSON.stringify(page.data));
  page.setData = function (value) {
    Object.entries(value).forEach(([k, v]) => {
      this.data[k] = v;
    });
  };

  return page;
}

test('suggests-manage loads initial list and sets displayList', async () => {
  let requestedStatus = null;
  let requestedPage = null;

  const page = mount({
    'mock/feedback/manage/list': (data) => {
      requestedStatus = data.status;
      requestedPage = data.page;
      return Promise.resolve({
        code: 1,
        data: {
          current_page: 1,
          last_page: 2,
          data: [
            {
              id: 12,
              name: '王小二',
              content: '车间物料箱增加分类标识',
              create_date: '2026-09-08',
              status: 0,
              status_text: '待认领',
              media: ['https://example.com/img1.jpg']
            }
          ]
        }
      });
    }
  });

  page.onShow();
  await new Promise(r => setTimeout(r, 20));

  assert.equal(requestedStatus, 0);
  assert.equal(requestedPage, 1);
  assert.equal(page.data.list.length, 1);
  assert.equal(page.data.displayList.length, 1);
  assert.equal(page.data.displayList[0]._name, '王小二');
  assert.equal(page.data.displayList[0]._firstChar, '王');
  assert.equal(page.data.totalPages, 2);
  assert.equal(page.data.nomore, false);
});

test('suggests-manage switches tabs and filters by keyword', async () => {
  let requestedStatus = null;

  const page = mount({
    'mock/feedback/manage/list': (data) => {
      requestedStatus = data.status;
      return Promise.resolve({
        code: 1,
        data: {
          current_page: 1,
          last_page: 1,
          data: [
            { id: 21, name: '李师傅', content: '测试提案A', status: 1 },
            { id: 22, name: '赵工', content: '模具改良B', status: 1 }
          ]
        }
      });
    }
  });

  // Switch to status 1 (推进中)
  page.changeStatus({ currentTarget: { dataset: { status: 1 } } });
  await new Promise(r => setTimeout(r, 20));

  assert.equal(requestedStatus, 1);
  assert.equal(page.data.status, 1);
  assert.equal(page.data.displayList.length, 2);

  // Test keyword search filter
  page.onSearchInput({ detail: '模具' });
  assert.equal(page.data.displayList.length, 1);
  assert.equal(page.data.displayList[0].name, '赵工');

  // Clear search keyword
  page.onSearchClear();
  assert.equal(page.data.displayList.length, 2);
});

test('suggests-manage accepts a suggestion and calls AcceptFeedback with id', async () => {
  let acceptedId = null;

  const page = mount({
    'mock/feedback/manage/accept': (data) => {
      acceptedId = data.id;
      return Promise.resolve({ code: 1, msg: 'ok' });
    },
    'mock/feedback/manage/list': () => {
      return Promise.resolve({
        code: 1,
        data: { current_page: 1, last_page: 1, data: [] }
      });
    }
  });

  page.accept({ currentTarget: { dataset: { id: 99 } } });
  await new Promise(r => setTimeout(r, 20));

  assert.equal(acceptedId, 99);
});

test('suggests-manage opens detail drawer and fetches FeedbackInfo', async () => {
  let infoRequestedId = null;

  const page = mount({
    'mock/feedback/index/info': (data) => {
      infoRequestedId = data.id;
      return Promise.resolve({
        code: 1,
        data: {
          id: 55,
          name: '张三',
          status: 1,
          director_name: '李委员',
          result_text: 'A级',
          score: 20,
          after_images_arr: ['https://example.com/after.jpg'],
          media: ['https://example.com/before.jpg']
        }
      });
    }
  });

  page.showInfo({ currentTarget: { dataset: { id: 55 } } });
  await new Promise(r => setTimeout(r, 20));

  assert.equal(infoRequestedId, 55);
  assert.equal(page.data.infoModel, true);
  assert.equal(page.data.suggestInfo.director_name, '李委员');
  assert.equal(page.data.suggestInfo.score, 20);
  assert.equal(page.data.suggestInfo.after_images_arr.length, 1);
  assert.equal(page.data.suggestInfo.before_images_arr.length, 1);
  assert.equal(page.data.detailSteps.length, 4);
  assert.equal(page.data.detailActiveStep, 1);
  assert.equal(page.data.detailPhaseText, '推进落实中');
});

test('suggests-manage previewImage protects against onShow list clearing', async () => {
  let listFetchCount = 0;
  const page = mount({
    'mock/feedback/manage/list': () => {
      listFetchCount++;
      return Promise.resolve({
        code: 1,
        data: {
          current_page: 1,
          last_page: 1,
          data: [{ id: 88, name: '孙工', content: '降本增效' }]
        }
      });
    }
  });

  page.onShow();
  await new Promise(r => setTimeout(r, 20));

  assert.equal(listFetchCount, 1);
  assert.equal(page.data.list.length, 1);

  // Trigger previewImage
  page.previewImage({
    currentTarget: {
      dataset: {
        url: 'https://example.com/test.jpg',
        urls: ['https://example.com/test.jpg']
      }
    }
  });

  assert.equal(page.isImagePreviewing, true);

  // On return from previewImage, onShow is invoked
  page.onShow();
  await new Promise(r => setTimeout(r, 20));

  // Should NOT trigger refreshList or wipe the list!
  assert.equal(listFetchCount, 1, 'list should not be refetched upon returning from image preview');
  assert.equal(page.data.list.length, 1, 'list should remain intact without blanking');
  assert.equal(page.isImagePreviewing, false, 'isImagePreviewing flag reset to false');
});

test('suggests-manage showInfo formats relative paths and preserves before_images from list item', async () => {
  const page = mount({
    'mock/feedback/index/info': () => {
      return Promise.resolve({
        code: 1,
        data: {
          id: 77,
          name: '周工',
          status: 2,
          after_images: '/uploads/2026/09/after.png',
          // Notice: FeedbackInfo did not return media/before_images
        }
      });
    }
  });

  page.data.list = [
    {
      id: 77,
      name: '周工',
      content: '优化刀具耐磨损',
      _mediaList: ['https://example.com/knife.jpg']
    }
  ];

  page.showInfo({ currentTarget: { dataset: { id: 77 } } });
  await new Promise(r => setTimeout(r, 20));

  assert.equal(page.data.infoModel, true);
  // Before images preserved from list item:
  assert.equal(page.data.suggestInfo.before_images_arr.length, 1);
  assert.equal(page.data.suggestInfo.before_images_arr[0], 'https://example.com/knife.jpg');
  // After images extracted from comma/string and formatted with OSS host:
  assert.equal(page.data.suggestInfo.after_images_arr.length, 1);
  assert.equal(
    page.data.suggestInfo.after_images_arr[0],
    'https://jiantan-erp.oss-cn-qingdao.aliyuncs.com/uploads/2026/09/after.png'
  );
  assert.equal(page.data.detailActiveStep, 2);
  assert.equal(page.data.detailPhaseText, '结果待审核');
});

