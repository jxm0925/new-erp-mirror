// pages/suggests/manage/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Dialog from '@vant/weapp/dialog/dialog';
import Notify from '@vant/weapp/notify/notify';

function formatImageUrl(url) {
  if (!url || typeof url !== 'string') return '';
  url = url.trim();
  if (!url) return '';
  if (url.startsWith('http://') || url.startsWith('https://')) {
    return url;
  }
  if (url.startsWith('//')) {
    return 'https:' + url;
  }
  if (url.startsWith('wxfile://') || url.startsWith('http://tmp') || url.startsWith('data:')) {
    return url;
  }
  var base = 'https://jiantan-erp.oss-cn-qingdao.aliyuncs.com';
  if (url.startsWith('/')) {
    return base + url;
  }
  return base + '/' + url;
}

function normalizeImageItem(item) {
  if (!item) return '';
  if (typeof item === 'string') return formatImageUrl(item);
  if (typeof item === 'object') {
    return formatImageUrl(item.url || item.path || item.pic || item.src || item.key || item.file || '');
  }
  return '';
}

function extractImages(data, possibleKeys) {
  if (!data) return [];
  for (var i = 0; i < possibleKeys.length; i++) {
    var key = possibleKeys[i];
    var val = data[key];
    if (val !== undefined && val !== null && val !== '') {
      var list = [];
      if (Array.isArray(val)) {
        list = val;
      } else if (typeof val === 'string') {
        val = val.trim();
        if (val.startsWith('[') && val.endsWith(']')) {
          try {
            var parsed = JSON.parse(val);
            if (Array.isArray(parsed)) list = parsed;
          } catch (e) {
            list = [val];
          }
        } else if (val.indexOf(',') !== -1) {
          list = val.split(',');
        } else {
          list = [val];
        }
      } else if (typeof val === 'object') {
        var keys = Object.keys(val);
        var isArrayLike = keys.length > 0 && keys.every(function(k) { return !isNaN(Number(k)); });
        if (isArrayLike) {
          list = keys.sort(function(a, b) { return Number(a) - Number(b); }).map(function(k) { return val[k]; });
        } else if (val.url || val.path || val.src || val.key || val.file) {
          list = [val];
        }
      }
      var normalized = list.map(normalizeImageItem).filter(Boolean);
      if (normalized.length > 0) {
        return normalized;
      }
    }
  }
  return [];
}

Page({
  /**
   * 页面的初始数据
   */
  data: {
    status: 0,
    statusTabs: [
      { key: 0, title: '待认领', desc: '待办提案', icon: 'clock-o' },
      { key: 1, title: '推进中', desc: '已认领跟进', icon: 'play-circle-o' },
      { key: 2, title: '待审核', desc: '结果待审', icon: 'certificate' },
      { key: 3, title: '已完成', desc: '已落地成果', icon: 'passed' }
    ],
    option1: [
      { text: '待认领', value: 0 },
      { text: '已认领', value: 1 },
      { text: '待审核', value: 2 },
      { text: '已完成', value: 3 }
    ],
    value1: 0,
    list: [],
    displayList: [],
    searchKeyword: '',
    page: 1,
    totalPages: 1,
    loading: false,
    detailLoading: false,
    loadmoreText: '正在加载更多提案...',
    nomoreText: '已加载全部提案',
    nomore: false,
    scrollTop: 0,
    userInfo: {},
    infoModel: false,
    suggestInfo: null,
    detailSteps: [],
    detailActiveStep: 0,
    detailPhaseText: '',
    detailPhaseDesc: ''
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    if (options && options.status !== undefined) {
      this.setData({
        status: Number(options.status),
        value1: Number(options.status)
      });
    }
  },

  /**
   * 生命周期函数--监听页面显示
   */
  onShow() {
    var userInfo = wx.getStorageSync('userInfo') || {};
    this.setData({
      userInfo: userInfo
    });

    if (this.isImagePreviewing) {
      this.isImagePreviewing = false;
      return;
    }

    if (!this.data.list || this.data.list.length === 0) {
      this.refreshList();
    }
  },

  /**
   * 下拉刷新
   */
  onPullDownRefresh() {
    this.refreshList(function() {
      wx.stopPullDownRefresh();
    });
  },

  /**
   * 页面上拉触底事件的处理函数
   */
  onReachBottom() {
    this.getList();
  },

  /**
   * 用户点击右上角分享
   */
  onShareAppMessage() {
    return {
      title: '改良委工作台 - 提案处理中心',
      path: '/pages/suggests/manage/index'
    };
  },

  /**
   * 刷新列表重置分页
   */
  refreshList(callback) {
    this.setData({
      page: 1,
      totalPages: 1,
      nomore: false,
      loading: false,
      list: [],
      displayList: []
    });
    this.getList(callback);
  },

  /**
   * 切换提案状态
   */
  changeStatus(e) {
    var newStatus = this.data.status;
    if (e && e.currentTarget && e.currentTarget.dataset.status !== undefined) {
      newStatus = Number(e.currentTarget.dataset.status);
    } else if (e && e.detail !== undefined) {
      if (typeof e.detail === 'object' && e.detail !== null) {
        newStatus = Number(e.detail.name !== undefined ? e.detail.name : (e.detail.value !== undefined ? e.detail.value : 0));
      } else {
        newStatus = Number(e.detail);
      }
    }
    if (this.data.status === newStatus && this.data.list.length > 0) {
      return;
    }
    this.setData({
      status: newStatus,
      value1: newStatus,
      page: 1,
      totalPages: 1,
      nomore: false,
      list: [],
      displayList: [],
      searchKeyword: ''
    });
    this.getList();
  },

  /**
   * 获取提案列表（保持 api.FeedbackManageList 原有参数）
   */
  getList(callback) {
    var that = this;
    if (that.data.loading) {
      if (typeof callback === 'function') callback();
      return;
    }
    if (that.data.page > 1 && that.data.totalPages <= that.data.page - 1) {
      that.setData({
        nomore: true
      });
      if (typeof callback === 'function') callback();
      return;
    }

    that.setData({ loading: true });

    util.request(api.FeedbackManageList, {
      status: that.data.status,
      page: that.data.page
    }).then(function(res) {
      that.setData({ loading: false });
      if (res.code == 1) {
        var rawList = (res.data && res.data.data) ? res.data.data : [];
        var formattedList = rawList.map(function(item) {
          var name = item.name || '匿名提案';
          var firstChar = name.charAt(0);
          var mediaList = extractImages(item, ['media', 'images', 'pics', 'media_arr', 'files', 'file']);
          return Object.assign({}, item, {
            _name: name,
            _firstChar: firstChar,
            _mediaList: mediaList
          });
        });

        var newList = that.data.page === 1 ? formattedList : that.data.list.concat(formattedList);
        var curPage = (res.data && res.data.current_page) ? res.data.current_page : that.data.page;
        var lastPage = (res.data && res.data.last_page) ? res.data.last_page : 1;

        that.setData({
          list: newList,
          page: curPage + 1,
          totalPages: lastPage,
          nomore: curPage >= lastPage
        });
        that.updateDisplayList(newList);
      }
      if (typeof callback === 'function') callback();
    }).catch(function(err) {
      console.error('获取提案列表失败', err);
      that.setData({ loading: false });
      if (typeof callback === 'function') callback();
    });
  },

  /**
   * 搜索过滤处理
   */
  onSearchInput(e) {
    var keyword = (e.detail || '').trim();
    this.setData({ searchKeyword: keyword });
    this.updateDisplayList(this.data.list, keyword);
  },

  onSearchClear() {
    this.setData({ searchKeyword: '' });
    this.updateDisplayList(this.data.list, '');
  },

  updateDisplayList(list, keyword) {
    list = list || this.data.list || [];
    keyword = (keyword !== undefined ? keyword : this.data.searchKeyword || '').toLowerCase().trim();
    if (!keyword) {
      this.setData({ displayList: list });
      return;
    }
    var filtered = list.filter(function(item) {
      var name = (item.name || '').toLowerCase();
      var content = (item.content || '').toLowerCase();
      var director = (item.director_name || '').toLowerCase();
      return name.indexOf(keyword) !== -1 || content.indexOf(keyword) !== -1 || director.indexOf(keyword) !== -1;
    });
    this.setData({ displayList: filtered });
  },

  /**
   * 认领提案
   */
  accept(e) {
    var that = this;
    var id = e.currentTarget ? e.currentTarget.dataset.id : e;
    if (!id) return;

    Dialog.confirm({
      title: '认领提案确认',
      message: '认领后您将作为责任人推进落地，是否确认认领？'
    }).then(() => {
      util.request(api.AcceptFeedback, { id: id }).then(function(res) {
        if (res.code) {
          Notify({ type: 'success', message: '认领成功！请积极推进落实' });
          that.setData({
            infoModel: false
          });
          that.refreshList();
        } else {
          Notify({ type: 'warning', message: res.msg || '认领失败，请重试' });
        }
      }).catch(function() {
        Notify({ type: 'danger', message: '网络异常，认领请求失败' });
      });
    }).catch(() => {
    });
  },

  /**
   * 跳转录入落地结果页面
   */
  result(e) {
    var id = e.currentTarget ? e.currentTarget.dataset.id : e;
    if (!id) return;
    wx.navigateTo({
      url: '/pages/suggests/manage/result?id=' + id
    });
  },

  /**
   * 查看详情抽屉
   */
  showInfo(e) {
    var that = this;
    var id = e.currentTarget ? e.currentTarget.dataset.id : e;
    if (!id) return;

    var currentItem = (that.data.list || []).find(function(item) {
      return item.id == id;
    });

    var initialSuggestInfo = currentItem ? Object.assign({}, currentItem) : null;
    if (initialSuggestInfo) {
      if (!initialSuggestInfo.before_images_arr || initialSuggestInfo.before_images_arr.length === 0) {
        initialSuggestInfo.before_images_arr = (initialSuggestInfo._mediaList && initialSuggestInfo._mediaList.length > 0)
          ? initialSuggestInfo._mediaList
          : extractImages(initialSuggestInfo, ['before_images_arr', 'before_images', 'media', 'images', 'pics', 'files']);
      }
    }

    that.setData({
      detailLoading: true,
      infoModel: true,
      suggestInfo: initialSuggestInfo
    });

    util.request(api.FeedbackInfo, { id: id }).then(function(res) {
      that.setData({ detailLoading: false });
      if (res && res.data) {
        var resData = res.data;
        var data = Object.assign({}, currentItem || {}, resData);

        var beforeImages = extractImages(resData, ['before_images_arr', 'before_images', 'media', 'images', 'pics', 'files']);
        if (beforeImages.length === 0 && currentItem) {
          beforeImages = (currentItem._mediaList && currentItem._mediaList.length > 0)
            ? currentItem._mediaList
            : extractImages(currentItem, ['before_images_arr', 'before_images', 'media', 'images', 'pics', 'files']);
        }

        var afterImages = extractImages(resData, ['after_images_arr', 'after_images', 'after_image', 'after_pics', 'result_images', 'result_pics', 'result_media', 'result_files', 'file', 'files']);
        if (afterImages.length === 0 && currentItem) {
          afterImages = extractImages(currentItem, ['after_images_arr', 'after_images', 'after_image', 'after_pics', 'result_images', 'result_pics', 'result_media', 'result_files', 'file', 'files']);
        }

        data.after_images_arr = afterImages;
        data.before_images_arr = beforeImages;

        var status = data.status !== undefined ? Number(data.status) : 0;
        var steps = [
          { text: '员工提案' },
          { text: '委员认领' },
          { text: '落地推进' },
          { text: '审核评定' }
        ];
        var activeStep = 0;
        var phaseText = '待委员认领';
        var phaseDesc = '提案已成功提交，等待改良委委员认领并推进落地';

        if (status === 1) {
          activeStep = 1;
          phaseText = '推进落实中';
          phaseDesc = (data.director_name ? data.director_name : '委员') + ' 已认领该提案，正在组织落地推进';
        } else if (status === 2) {
          activeStep = 2;
          phaseText = '结果待审核';
          phaseDesc = '落地成效与成果对比已提交，等待改良委员会审核评级';
        } else if (status === 3) {
          activeStep = 3;
          phaseText = '已结项评定';
          var levelStr = data.result_text ? ' · 评级「' + data.result_text + '」' : '';
          var scoreStr = (data.score !== undefined && data.score !== null && data.score !== '') ? ' (+' + data.score + '积分)' : '';
          phaseDesc = '提案已办结归档' + levelStr + scoreStr + (data.director_name ? '，推进人：' + data.director_name : '');
        }

        that.setData({
          suggestInfo: data,
          detailSteps: steps,
          detailActiveStep: activeStep,
          detailPhaseText: phaseText,
          detailPhaseDesc: phaseDesc
        });
      }
    }).catch(function(err) {
      console.error('获取提案详情失败', err);
      that.setData({ detailLoading: false });
    });
  },

  /**
   * 关闭详情抽屉
   */
  onClose() {
    this.setData({
      infoModel: false
    });
  },

  /**
   * 预览单张或多张图片
   */
  previewImage(e) {
    this.isImagePreviewing = true;
    var current = '';
    var urls = [];
    if (e && e.currentTarget && e.currentTarget.dataset) {
      var ds = e.currentTarget.dataset;
      current = ds.url || '';
      urls = ds.urls || ds.images_arr || [];
    } else if (typeof e === 'string') {
      current = e;
      urls = [e];
    }
    if (!current && urls && urls.length > 0) {
      current = urls[0];
    }
    if (!current) return;
    if (!Array.isArray(urls) || urls.length === 0) {
      urls = [current];
    }
    wx.previewImage({
      current: current,
      urls: urls
    });
  }
})