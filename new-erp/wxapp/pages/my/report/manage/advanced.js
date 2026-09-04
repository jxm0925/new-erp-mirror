const util = require('../../../../utils/util.js');
const api = require('../../../../config/api.js'); 

Page({
  data: {
    activeTab: 'rules',
    
    config: {
      rules: {
        isAllCompany: true,     // 💥 默认全公司通行
        allowedGroupIds: [],    // 💥 仅限填写的部门 ID 集合
        allowedGroupNames: '',  // 前端用来显示的部门名称

        reporterIds: [],           
        reporterNames: '所有成员', 
        adminIds: [],
        adminNames: '全部汇报管理员',
        periodValue: '',
        periodName: '',
        deadline: '',
        allowMultiple: true,
        allowModify: true,
        targetValue: 'dept_head',
        targetName: '部门负责人',
        targetUserIds: [],         
        targetUserNames: '',
        allowManualTarget: true
      },
      reminders: { system: true, email: false, sms: false },
      permissions: [
        { groupName: '默认权限组', memberIds: [], memberNames: '全部汇报管理员', scope: '全公司', actions: '查看、导出、删除' }
      ]
    },

    showPicker: false,
    pickerType: '', 
    pickerTitle: '',
    pickerColumns: [],
    
    showDeadlinePicker: false,
    deadlineColumns: [],
    
    showUserPicker: false,
    userList: [],         
    tempSelectedUsers: [],
    pickingKey: '',       
    pickingIndex: -1,     

    // 💥 部门选择器变量 💥
    showDeptPicker: false,
    departmentList: [],    
    tempSelectedDeptIds: [],

    dict: {
      period: [
        { text: '按天', value: 'day' },
        { text: '按周', value: 'week' },
        { text: '按月', value: 'month' }
      ],
      target: [
        { text: '直属主管', value: 'leader' },
        { text: '部门负责人', value: 'dept_head' },
        { text: '指定成员', value: 'specific' }
      ],
      hours: Array.from({length: 24}, (v, i) => (i < 10 ? '0' + i : '' + i)),
      minutes: ['00', '10', '20', '30', '40', '50'],
      weekDays: ['周一', '周二', '周三', '周四', '周五', '周六', '周日'],
      monthDays: [...Array.from({length: 31}, (v, i) => (i + 1) + '日'), '月底']
    }
  },

  onLoad(options) {
    this.fetchUserList();
    this.fetchDepartmentList(); // 💥 获取部门字典
    
    let pages = getCurrentPages();
    let prevPage = pages[pages.length - 2]; 
    if (prevPage && prevPage.data.advancedConfig && Object.keys(prevPage.data.advancedConfig).length > 0) {
      // 合并逻辑，防止旧模板没这几个新字段报错
      let loadedConfig = prevPage.data.advancedConfig;
      if (loadedConfig.rules.isAllCompany === undefined) loadedConfig.rules.isAllCompany = true;
      this.setData({ config: loadedConfig });
    } else {
      this.fetchCurrentUser();
    }
  },

  fetchCurrentUser() {
    try {
      const userInfo = wx.getStorageSync('userInfo');
      if (userInfo && userInfo.user_id) {
        const currentUserId = String(userInfo.user_id);
        const currentUserName = userInfo.name || '当前用户';
        this.setData({
          'config.rules.adminIds': [currentUserId],
          'config.rules.adminNames': currentUserName
        });
      }
    } catch (e) {
      console.error('读取缓存失败:', e);
    }
  },

  onTabChange(e) { this.setData({ activeTab: e.detail.name }); },
  
  onSwitchChange(e) {
    const datasetKey = e.currentTarget.dataset.key;
    const key = `config.${datasetKey}`;
    this.setData({ [key]: e.detail });

    // 💥 核心拦截：如果切回全公司可见，立刻清空部门限制 💥
    if (datasetKey === 'rules.isAllCompany' && e.detail === true) {
      this.setData({
        'config.rules.allowedGroupIds': [],
        'config.rules.allowedGroupNames': ''
      });
    }
  },

  // ===== 拉取公司花名册 =====
  fetchUserList() {
    if (api.getUserDict) {
      util.request(api.getUserDict, {}, 'GET').then(res => {
        if (res.code === 1) { this.setData({ userList: res.data }); }
      });
    }
  },

  fetchDepartmentList() {
    if (api.getDeptList) {
      util.request(api.getDeptList, {}, 'GET').then(res => {
        if (res.code === 1) {
          // 第一步：彻底清洗数据，把所有 id 和 parent_id 都强转成字符串，防止数字填坑！
          let rawList = res.data.map(item => ({
            ...item,
            id: String(item.id),
            parent_id: String(item.parent_id || '0')
          }));
          
          // 第二步：自动寻找顶级部门（防止后台顶级不是0）
          let allIds = rawList.map(i => i.id);
          let topLevelNodes = rawList.filter(i => !allIds.includes(i.parent_id));
          
          // 第三步：递归组装扁平树，计算缩进层级
          let flatTree = [];
          const buildTree = (nodes, level) => {
            nodes.forEach(node => {
              node.level = level;
              flatTree.push(node);
              // 找这个节点的孩子，继续递归
              let children = rawList.filter(child => child.parent_id === node.id);
              buildTree(children, level + 1);
            });
          };
          
          buildTree(topLevelNodes, 0);

          this.setData({ departmentList: flatTree });
        }
      });
    }
  },

  // 💥 2. 终极级联勾选引擎 (绝对精准找子孙) 💥
  toggleDept(e) {
    console.log(e)
    const id = String(e.currentTarget.dataset.id);
    let list = [...this.data.tempSelectedDeptIds];
    let isSelected = list.includes(id);

    // 递归找子集：把当前部门，以及它下面的子孙 ID 全揪出来！
    let allRelatedIds = [id];
    const getChildrenIds = (parentId) => {
      // 在已经清洗好的 departmentList 里强类型对比
      let children = this.data.departmentList.filter(item => item.parent_id === parentId);
      children.forEach(c => {
        allRelatedIds.push(c.id);
        getChildrenIds(c.id); // 继续递归找孙子
      });
    };
    getChildrenIds(id);

    // 执行勾选/取消逻辑
    if (isSelected) {
      // 动作A：取消勾选 -> 把自己和所有子孙，全踢出去
      list = list.filter(i => !allRelatedIds.includes(i));
    } else {
      // 动作B：打勾选中 -> 把自己和所有子孙，全加进来并去重
      allRelatedIds.forEach(rid => {
        if (!list.includes(rid)) list.push(rid);
      });
    }
    
    this.setData({ tempSelectedDeptIds: list });
  },

  // 💥 3. 拦截器，防 Vant 捣乱 💥
  noop() {},
  // ===== 💥 部门选择器核心逻辑 💥 =====
  openDeptPicker() {
    let ids = (this.data.config.rules.allowedGroupIds || []).map(String);
    this.setData({ showDeptPicker: true, tempSelectedDeptIds: ids });
  },
  closeDeptPicker() { this.setData({ showDeptPicker: false }); },
  onDeptChange(e) { this.setData({ tempSelectedDeptIds: e.detail.map(String) }); },
  confirmDeptPicker() {
    let selectedIds = this.data.tempSelectedDeptIds;
    let selectedNames = this.data.departmentList
      .filter(item => selectedIds.includes(String(item.id)))
      .map(item => item.name)
      .join('、');

    this.setData({ 
      'config.rules.allowedGroupIds': selectedIds,
      'config.rules.allowedGroupNames': selectedNames,
      showDeptPicker: false 
    });
  },

  // ===== 1. 单列选择器逻辑 =====
  openPicker(e) {
    const type = e.currentTarget.dataset.type;
    this.setData({
      pickerType: type,
      pickerTitle: type === 'period' ? '汇报周期' : '汇报对象',
      pickerColumns: this.data.dict[type],
      showPicker: true
    });
  },
  closePicker() { this.setData({ showPicker: false }); },
  onPickerConfirm(e) {
    const { value } = e.detail;
    const type = this.data.pickerType;
    if (type === 'period') {
      let defaultDeadline = '18:00';
      if (value.value === 'week') defaultDeadline = '周五 18:00';
      if (value.value === 'month') defaultDeadline = '月底 18:00';
      this.setData({
        'config.rules.periodName': value.text,
        'config.rules.periodValue': value.value,
        'config.rules.deadline': defaultDeadline
      });
    } else if (type === 'target') {
      this.setData({
        'config.rules.targetName': value.text,
        'config.rules.targetValue': value.value
      });
    }
    this.closePicker();
  },

  // ===== 2. 动态截止时间选择器逻辑 =====
  openDeadlinePicker() {
    const period = this.data.config.rules.periodValue;
    const { hours, minutes, weekDays, monthDays } = this.data.dict;
    let columns = [];
    if (period === 'day') columns = [ { values: hours }, { values: minutes } ];
    else if (period === 'week') columns = [ { values: weekDays }, { values: hours }, { values: minutes } ];
    else if (period === 'month') columns = [ { values: monthDays }, { values: hours }, { values: minutes } ];

    this.setData({ deadlineColumns: columns, showDeadlinePicker: true });
  },
  closeDeadlinePicker() { this.setData({ showDeadlinePicker: false }); },
  onDeadlineConfirm(e) {
    const { value } = e.detail;
    const period = this.data.config.rules.periodValue;
    let formattedTime = period === 'day' ? `${value[0]}:${value[1]}` : `${value[0]} ${value[1]}:${value[2]}`;
    this.setData({ 'config.rules.deadline': formattedTime, showDeadlinePicker: false });
  },

  // ===== 3. 多选人员面板逻辑 =====
  openUserPicker(e) {
    const key = e.currentTarget.dataset.key;
    const index = e.currentTarget.dataset.index;
    let currentSelected = [];
    if (key === 'reporters') currentSelected = this.data.config.rules.reporterIds;
    else if (key === 'admins') currentSelected = this.data.config.rules.adminIds;
    else if (key === 'specificTarget') currentSelected = this.data.config.rules.targetUserIds;
    else if (key === 'permissions') currentSelected = this.data.config.permissions[index].memberIds;

    this.setData({
      showUserPicker: true,
      pickingKey: key,
      pickingIndex: index !== undefined ? index : -1,
      tempSelectedUsers: currentSelected || []
    });
  },
  closeUserPicker() { this.setData({ showUserPicker: false }); },
  onUserCheck(e) { this.setData({ tempSelectedUsers: e.detail }); },
  toggleCheckbox(e) {
    const id = String(e.currentTarget.dataset.name); 
    const { tempSelectedUsers } = this.data;
    const stringifiedUsers = tempSelectedUsers.map(String);
    const index = stringifiedUsers.indexOf(id);
    if (index > -1) tempSelectedUsers.splice(index, 1); 
    else tempSelectedUsers.push(id); 
    this.setData({ tempSelectedUsers });
  },
  confirmUserSelection() {
    const { pickingKey, pickingIndex, tempSelectedUsers, userList } = this.data;
    let names = tempSelectedUsers.map(id => {
      let u = userList.find(item => String(item.id) === String(id));
      return u ? u.nickname : '';
    }).filter(v => v).join('、');

    if (tempSelectedUsers.length === 0) {
      if (pickingKey === 'reporters') names = '所有成员';
      else if (pickingKey === 'admins') names = '全部汇报管理员';
      else names = '请选择';
    }

    if (pickingKey === 'reporters') {
      this.setData({ 'config.rules.reporterIds': tempSelectedUsers, 'config.rules.reporterNames': names });
    } else if (pickingKey === 'admins') {
      this.setData({ 'config.rules.adminIds': tempSelectedUsers, 'config.rules.adminNames': names });
    } else if (pickingKey === 'specificTarget') {
      this.setData({ 'config.rules.targetUserIds': tempSelectedUsers, 'config.rules.targetUserNames': names });
    } else if (pickingKey === 'permissions') {
      this.setData({ 
        [`config.permissions[${pickingIndex}].memberIds`]: tempSelectedUsers,
        [`config.permissions[${pickingIndex}].memberNames`]: names
      });
    }
    this.closeUserPicker();
  },

  // ===== 权限组动态增删 =====
  addPermGroup() {
    const newList = [...this.data.config.permissions, {
      groupName: '自定义权限组', memberIds: [], memberNames: '请选择', scope: '下属的', actions: '查看、导出'
    }];
    this.setData({ 'config.permissions': newList });
  },
  deletePerm(e) {
    const idx = e.currentTarget.dataset.index;
    const newList = [...this.data.config.permissions];
    newList.splice(idx, 1);
    this.setData({ 'config.permissions': newList });
  },

  // ===== 核心保存并退回 =====
  saveAdvanced() {
    const finalConfig = this.data.config;
    let pages = getCurrentPages();
    let prevPage = pages[pages.length - 2]; 
    if (prevPage) {
      prevPage.setData({ advancedConfig: finalConfig });
    }
    wx.showToast({ title: '保存成功', icon: 'success' });
    setTimeout(() => { wx.navigateBack(); }, 1000);
  }
})