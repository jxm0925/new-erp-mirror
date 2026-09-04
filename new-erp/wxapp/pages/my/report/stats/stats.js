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
    templateId: 0, 
    templateOptions: [], 
    
    currentMode: 'day', // 'day' 或 'month'
    currentDate: '',    // YYYY-MM-DD
    currentMonth: '',   // YYYY-MM
    
    show_date_choose: false,
    calendarConfig: {
      multi: false,
      showLunar: true, 
      chooseAreaMode:true,
      theme: 'elegant',
    },
    
    activeTab: 'unsubmitted', 
    
    // 数据源
    submitRate: 0, // 图表百分比
    stats: { submitted: [], unsubmitted: [], total: 0, submitted_count: 0, unsubmitted_count: 0 },
    monthList: [], // 月度模式下的员工统计列表
    showUserDetail: false,
    selectedUserName: '',
    selectedUserDays: []
  },

  onLoad(options) {
    plugin.use(todo).use(solarLunar).use(selectable).use(week).use(timeRange).use(holidays);
    
    const now = new Date();
    const today = this.formatDate(now);
    const thisMonth = this.formatMonth(now);
    
    let passId = options.templateId ? Number(options.templateId) : 0;
    
    this.setData({ 
      templateId: passId,
      currentDate: today,
      currentMonth: thisMonth
    });
    this.fetchTemplateOptions(); 
  },

  fetchTemplateOptions() {
    util.request(api.getAvailableTemplates, {}, 'GET').then(res => {
      if (res.code === 1 && res.data.length > 0) {
        let options = res.data.map(item => ({ text: item.name, value: Number(item.id) }));
        let currentId = this.data.templateId;
        if (!currentId || !options.find(opt => opt.value === currentId)) {
          currentId = options[0].value;
        }
        this.setData({ templateOptions: options, templateId: currentId }, () => {
          this.fetchStats(); 
        });
      }
    });
  },

  onTemplateChange(e) { 
    this.setData({ templateId: Number(e.detail) }); 
    this.fetchStats(); 
  },

  // 💥 模式切换 💥
  onModeChange(e) {
    this.setData({ currentMode: e.detail.name });
    this.fetchStats();
  },

  // 💥 时间步进控制 (智能区分日/月) 💥
  prevTime() { this.changeTime(-1); },
  nextTime() { this.changeTime(1); },
  changeTime(step) {
    if (this.data.currentMode === 'day') {
      let d = new Date(this.data.currentDate);
      d.setDate(d.getDate() + step);
      this.setData({ currentDate: this.formatDate(d) });
    } else {
      let [year, month] = this.data.currentMonth.split('-');
      let d = new Date(year, parseInt(month) - 1 + step, 1);
      this.setData({ currentMonth: this.formatMonth(d) });
    }
    this.fetchStats(); 
  },

  // 日期选择回调
  showCalendar() { this.setData({ show_date_choose: true }); },
  hidePop() { this.setData({ show_date_choose: false }); },
  afterTapDate(e) {
    let year = e.detail.year;
    let month = e.detail.month.toString().padStart(2, '0');
    let day = e.detail.date.toString().padStart(2, '0');
    this.setData({ show_date_choose: false, currentDate: `${year}-${month}-${day}` });
    this.fetchStats();
  },

  // 月份选择回调
  onMonthChange(e) {
    this.setData({ currentMonth: e.detail.value });
    this.fetchStats();
  },

  formatDate(date) { return `${date.getFullYear()}-${(date.getMonth() + 1).toString().padStart(2, '0')}-${date.getDate().toString().padStart(2, '0')}`; },
  formatMonth(date) { return `${date.getFullYear()}-${(date.getMonth() + 1).toString().padStart(2, '0')}`; },

  // 💥 核心：请求并计算统计数据 💥
  fetchStats() {
    if (!this.data.templateId) return;
    wx.showLoading({ title: '计算中...' });

    // 构建参数，告诉后端当前是查 "日" 还是查 "月"
    const payload = { 
      template_id: this.data.templateId,
      mode: this.data.currentMode,
      date: this.data.currentMode === 'day' ? this.data.currentDate : this.data.currentMonth 
    };

    util.request(api.getSubmissionStats, payload, 'GET').then(res => {
      wx.hideLoading();
      if (res.code === 1) { 
        const data = res.data;
        
        // 计算图表百分比
        let total = data.total || 0;
        let submitted = data.submitted_count || 0;
        let rate = total === 0 ? 0 : Math.round((submitted / total) * 100);

        this.setData({ 
          stats: data,
          submitRate: rate,
          // 如果后端返回了月度汇总数组，就存在 monthList 里
          monthList: data.month_list || [] 
        }); 
      }
    });
  },
  openUserDetail(e) {
    const user = e.currentTarget.dataset.user;
    const submittedDays = user.submitted_days || [];
    const [year, month] = this.data.currentMonth.split('-'); // 当前查看的年月

    // 智能判断算到哪一天
    const now = new Date();
    let daysInMonth;
    // 如果看的是这个月，就算到今天；如果是过去的月，就算整月的天数
    if (now.getFullYear() == year && (now.getMonth() + 1) == month) {
      daysInMonth = now.getDate(); 
    } else {
      daysInMonth = new Date(year, month, 0).getDate(); 
    }

    let daysArray = [];
    for (let i = 1; i <= daysInMonth; i++) {
      let dayStr = i.toString().padStart(2, '0');
      let fullDateStr = `${year}-${month}-${dayStr}`;
      
      // 判断这一天在不在后端的数组里
      daysArray.push({
        day: dayStr,
        date: fullDateStr,
        status: submittedDays.includes(fullDateStr) ? 'submitted' : 'missed'
      });
    }

    this.setData({
      showUserDetail: true,
      selectedUserName: user.nickname,
      selectedUserDays: daysArray
    });
  },

  closeUserDetail() {
    this.setData({ showUserDetail: false });
  },
});