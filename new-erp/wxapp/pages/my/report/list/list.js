const util = require('../../../../utils/util.js'); 
const api = require('../../../../config/api.js');
import todo from '../../../../components/calendar/plugins/todo'
import selectable from '../../../../components/calendar/plugins/selectable'
import solarLunar from '../../../../components/calendar/plugins/solarLunar/index'
import timeRange from '../../../../components/calendar/plugins/time-range'
import week from '../../../../components/calendar/plugins/week'
import holidays from '../../../../components/calendar/plugins/holidays/index'
import plugin from '../../../../components/calendar/plugins/index'

Page({
  data: {
    isBoss: false, // 💥 是否为老板(超管)
    keyword: '',
    currentTab: 'received',
    showUnreadOnly: false,
    listData: [],

    // 💥 分页控制变量 💥
    page: 1,
    limit: 10,
    total: 0,

    isFilterShow: false,
    dynamicTypes: [], 
    
    timeOptions: [
      { label: '全部', value: 'all' },
      { label: '今天', value: 'today' },
      { label: '昨天', value: 'yesterday' },
      { label: '本周', value: 'this_week' },
      { label: '上周', value: 'last_week' },
      { label: '本月', value: 'this_month' },
      { label: '上月', value: 'last_month' }
    ],
    timeRange: 'all', 
    customDate: '',
    selectedUser: '',

    show_date_choose: false,
    renderCalendar: false, 
    clickStartTemp: '',
    
    calendarConfig: {
      multi: true,
      showLunar: true, 
      chooseAreaMode:true,
      theme: 'elegant',
    },
  },

  onLoad(options) {
    plugin.use(todo).use(solarLunar).use(selectable).use(week).use(timeRange).use(holidays);
    if (options.tab) { this.setData({ currentTab: options.tab }); }
    this.fetchFilterTypes(); 
  },

  onShow() {
    // 💥 识别老板身份 💥
    let userInfo = wx.getStorageSync('userInfo');
    if (userInfo && (userInfo.user_id == 1 || userInfo.user_id == 147)) {
      this.setData({ isBoss: true });
    }
    // 每次显示页面，强制重置页码并刷新
    this.resetAndFetch();
  },

  // 获取动态模板分类
  fetchFilterTypes() {
    if (!api.getAvailableTemplates) return;
    util.request(api.getAvailableTemplates, {}, 'GET').then(res => {
      if (res.code === 1) {
        let types = (res.data || []).map(item => {
          return { id: item.id, name: item.name, selected: false };
        });
        this.setData({ dynamicTypes: types });
      }
    });
  },

  // 💥 核心方法1：重置并全新查询 (切Tab、搜索、筛选全靠它) 💥
  resetAndFetch() {
    this.setData({
      page: 1,         // 页码瞬间重置为1
      listData: [],    // 瞬间清空屏幕上的老数据，防止视觉残留
      total: 0         // 总数归零
    }, () => {
      this.fetchRealList(false); // 传入 false 表示这是全新查询
    });
  },

  // 💥 核心方法2：触底加载下一页 (绑定在 scroll-view 上) 💥
  loadMore() {
    if (this.data.listData.length >= this.data.total) {
      return; // 已经全部加载完，不再请求
    }
    this.setData({ page: this.data.page + 1 });
    this.fetchRealList(true); // 传入 true 表示追加数据
  },

  //核心方法3：真实请求方法
  fetchRealList(isLoadMore = false) {
    if (!isLoadMore) wx.showLoading({ title: '加载中...' });
    
    const selectedTemplateIds = this.data.dynamicTypes.filter(i => i.selected).map(i => i.id).join(',');

    const payload = {
      tab: this.data.currentTab, // 如果是全员，这里传的就是 'all_staff'
      keyword: this.data.selectedUser || this.data.keyword, 
      template_ids: selectedTemplateIds,
      time_range: this.data.timeRange,
      custom_date: this.data.customDate,
      unread_only: this.data.showUnreadOnly ? 'true' : 'false',
      page: this.data.page,
      limit: this.data.limit
    };

    // 💥 核心修改：既然你的后端 get_report_list 已经加入了 is_boss 拦截，这里统一全部走这一个接口就行了！ 💥
    util.request(api.getReportList, payload, 'GET').then(res => {
      if (!isLoadMore) wx.hideLoading();
      
      if (res.code === 1) {
        let rawList = res.data.list || [];
        let totalCount = res.data.total || 0;

        let formattedList = rawList.map(item => {
          let isRead = item.status === 'read';
          let summaryArr = [];
          try {
            let formDataObj = typeof item.form_data === 'string' ? JSON.parse(item.form_data) : item.form_data;
            let snapshot = formDataObj.template_snapshot || [];
            let values = formDataObj.form_values || {};

            let count = 0;
            for (let i = 0; i < snapshot.length; i++) {
              if (count >= 3) break; 
              let field = snapshot[i];
              let val = values['field_' + i];
              if (field.type !== 'image' && field.type !== 'detail_list' && val && String(val).trim() !== '') {
                summaryArr.push({ label: field.label, value: val });
                count++;
              }
            }
          } catch(e) {}

          if (summaryArr.length === 0) summaryArr.push({ label: '提示', value: '点击查看详情' });

          return {
            id: item.id,
            template_id: item.template_id,
            title: `${item.submitter_name || '我'}提交的${item.template_name}`,
            submitter: item.submitter_name || '我',
            date: item.create_date ? item.create_date.split(' ')[0] : '', 
            status: item.status, 
            // 如果是“我收到的”显示待查看，其他的（我提交的、全员日报）都显示“未查看”
            statusText: item.status === 'pending' ? (this.data.currentTab === 'received' ? '待查看' : '未查看') : '已查看',
            is_read: isRead,
            summary: summaryArr 
          };
        });

        // 追加或者覆盖数据
        this.setData({ 
          listData: isLoadMore ? this.data.listData.concat(formattedList) : formattedList,
          total: totalCount
        });
      }
    }).catch(() => {
      if (!isLoadMore) wx.hideLoading();
    });
  },

  // ==========================================
  // 事件交互区 (全部改为触发 resetAndFetch)
  // ==========================================
  switchTab(e) {
    this.setData({ currentTab: e.detail.name, keyword: '', showUnreadOnly: false }, () => { this.resetAndFetch(); });
  },
  toggleUnread(e) { this.setData({ showUnreadOnly: e.detail }, () => { this.resetAndFetch(); }); },
  onSearchInput(e) { this.setData({ keyword: e.detail }, () => { this.resetAndFetch(); }); },
  
  showFilterPopup() { this.setData({ isFilterShow: true }); },
  hideFilterPopup() { this.setData({ isFilterShow: false }); },
  
  toggleType(e) {
    const index = e.currentTarget.dataset.index;
    const key = `dynamicTypes[${index}].selected`;
    this.setData({ [key]: !this.data.dynamicTypes[index].selected });
  },
  selectTime(e) {
    const val = e.currentTarget.dataset.val;
    if (this.data.timeRange === val) {
      this.setData({ timeRange: '' }); 
    } else {
      this.setData({ timeRange: val, customDate: '' });
    }
  },
  onUserInput(e) { this.setData({ selectedUser: e.detail.value }); },
  
  resetFilter() {
    const resetTypes = this.data.dynamicTypes.map(item => { item.selected = false; return item; });
    this.setData({ dynamicTypes: resetTypes, timeRange: 'all', customDate: '', selectedUser: '' });
    this.resetAndFetch();
  },
  confirmFilter() {
    this.hideFilterPopup();
    this.resetAndFetch(); 
  },
  
  // 日历逻辑
  openCalendar() { 
    this.setData({ show_date_choose: true, clickStartTemp: '' }); 
    wx.showToast({ title: '请点击选择开始时间', icon: 'none' });
    setTimeout(() => { this.setData({ renderCalendar: true }); }, 300);
  },
  hidePop() { this.setData({ show_date_choose: false, renderCalendar: false }); },
  afterTapDate(e) {
    let year = e.detail.year;
    let month = e.detail.month.toString().padStart(2, '0');
    let day = e.detail.date.toString().padStart(2, '0');
    let selectedDate = `${year}-${month}-${day}`;

    if (!this.data.clickStartTemp) {
      this.setData({ clickStartTemp: selectedDate });
      wx.showToast({ title: '请再次点击选择结束时间', icon: 'none' });
    } else {
      let start = this.data.clickStartTemp;
      let end = selectedDate;
      if (new Date(start) > new Date(end)) { end = start; start = selectedDate; }
      
      const dateStr = `${start} ~ ${end}`;
      this.setData({
        show_date_choose: false,
        renderCalendar: false,
        customDate: dateStr,
        timeRange: '', 
        clickStartTemp: ''
      });
    }
  },

  goToDetail(e) {
    const id = e.currentTarget.dataset.id;
    wx.navigateTo({ url: `/pages/my/report/detail/detail?id=${id}` });
  },
  goToEdit(e) {
    const recordId = e.currentTarget.dataset.id;
    const templateId = e.currentTarget.dataset.tid;
    wx.navigateTo({ url: `/pages/my/report/form/form?templateId=${templateId}&recordId=${recordId}&title=修改汇报` });
  },
  
  markAllRead() {
    wx.showModal({
      title: '提示',
      content: '确定将所有收到的未读汇报标记为已读？',
      confirmColor: '#ff4d4d',
      success: (res) => {
        if (res.confirm) {
          wx.showLoading({ title: '处理中...', mask: true });
          util.request(api.markAllRead, {}, 'POST').then(res => {
            wx.hideLoading();
            if (res.code === 1) {
              wx.showToast({ title: '已全部标为已读', icon: 'success' });
              this.resetAndFetch(); 
            } else { wx.showToast({ title: res.msg || '操作失败', icon: 'none' }); }
          }).catch(err => { wx.hideLoading(); wx.showToast({ title: '网络异常', icon: 'none' }); });
        }
      }
    });
  },

  exportListContent() {
    wx.showLoading({ title: '提取数据中...', mask: true });
    const selectedTemplateIds = this.data.dynamicTypes.filter(i => i.selected).map(i => i.id).join(',');
    const payload = {
      tab: this.data.currentTab,
      keyword: this.data.selectedUser || this.data.keyword,
      template_ids: selectedTemplateIds,
      time_range: this.data.timeRange,
      custom_date: this.data.customDate,
      unread_only: this.data.showUnreadOnly ? 'true' : 'false'
    };

    let targetApi = (this.data.currentTab === 'all_staff') ? api.exportAllReportList : api.exportReportList;

    util.request(targetApi || api.exportReportList, payload, 'GET').then(res => {
      if (res.code === 1 && res.data.url) {
        wx.showLoading({ title: '下载中...', mask: true });
        wx.downloadFile({
          url: res.data.url,
          success: (downloadRes) => {
            wx.hideLoading();
            if (downloadRes.statusCode === 200) {
              wx.openDocument({ filePath: downloadRes.tempFilePath, showMenu: true, fileType: 'csv' });
            } else { wx.showToast({ title: '文件下载失败', icon: 'none' }); }
          },
          fail: () => { wx.hideLoading(); wx.showToast({ title: '网络异常', icon: 'none' }); }
        });
      } else { 
        wx.hideLoading(); 
        wx.showToast({ title: res.msg || '暂无数据可导出', icon: 'none' }); 
      }
    });
  }
});