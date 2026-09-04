// pages/suggests/sub/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Notify from '@vant/weapp/notify/notify';
const app = getApp();
let richText = null;  //富文本编辑器实例
Page({

  /**
   * 页面的初始数据
   */
  data: {
    autosize: {
      minHeight: 80
    },
    typeShow:false,
    departmentShow:false,
    department_name:'请选择您的能量团',
    type_name:'请选择分类',
    typeArray:[],
    cascaderValue: '',
    picsList:[],
    content:'',
    name:'',
    readOnly: false, //编辑器是否只读
    placeholder: '开始编辑吧...',
    storyPicList:[],
    storyTitle:'',
    personShow:false,
    // 原始完整数据（从后端 TP5 拿到的）
    allUserList: {},
    
    // 用于展示的数据（搜索过滤后）
    displayList: [],
    
    // 选中的 ID 数组
    result: [],
    
    // 选中的名字字符串（用于页面展示）
    selectNames: ''
  },
  onChange(event) {
    const { picker, value, index } = event.detail;
    Toast(`当前值：${value}, 当前索引：${index}`);
  },
  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    this.getOptions();
    if(!wx.getStorageSync('token')){
      wx.login({
        success: (res) => {
          if(res.code){
            util.request(api.WechatLogin,{code:res.code}).then(function(res){
                console.log(res);
                var userInfo = res.data.staff_info;
                userInfo.is_login = 1;
                try{
                  wx.setStorageSync('userInfo', userInfo)
                  wx.setStorageSync('token', res.data.token)
                }catch(e){
                   Notify({ type: 'danger', message: '保存用户信息失败' });
                }
                util.refreshPage()
            })
          }
        },
      })
    }
  },
  /**
   * 生命周期函数--监听页面初次渲染完成
   */
  onReady() {

  },

  /**
   * 生命周期函数--监听页面显示
   */
  onShow() {

  },
  changePopup(e){
    var that = this;
    var data = {};
    console.log(e);
    const type = e.currentTarget.dataset.type;
    if(type=="department"){
      data.departmentShow=!that.data.departmentShow;
    }else if(type=="person"){
      data.personShow = !that.data.personShow;
    }else{
      data.typeShow=!that.data.typeShow;
    }
    this.setData(data)
  },
  onFinish(e) {
    const { selectedOptions, value } = e.detail;
    const fieldValue = selectedOptions
        .map((option) => option.text || option.name)
        .join('/');
    this.setData({
      type_name:fieldValue,
      cascaderValue: value,
      typeShow:false
    })
  },
  onConfirm(event) {
    var that = this;
    console.log(event)
    that.setData({
      department_id:event.detail.value.id,
      department_name:event.detail.value.name,
      departmentShow:false
    })
  },
  //文件上传
  afterPicRead(e){
    let picsList = this.data.picsList
    if (!picsList) {
      picsList = []
    }
    picsList = picsList.concat(e.detail.file)
    this.setData({
      picsList
    })
  },
  afterPicDel(e){
    let picsList = this.data.picsList
    picsList.splice(e.detail.index, 1)
    this.setData({
      picsList
    })
  },
  getOptions(){
    var that = this;
    var data = {};
    util.request(api.FeedbackOptions).then(function (res) {
      if(res.code){
        that.setData({
          typeArray:res.data
        })
      }
    })
    util.request(api.DepartmentOptions).then(function(res){
      console.log(res.data);
      if(res.code){
        that.setData({
          departmentArray:res.data
        })
      }
    })
    util.request(api.AdminList).then(function(res){
      console.log(res.data);
      if(res.code){
        that.setData({
          allUserList:res.data,
          displayList:res.data
        })
      }
    })
    
  },
  async bindSave(){
    var that = this;
    if(!wx.getStorageSync('token')){
      util.BadgePopup();
      return;
    }
    if(!that.data.cascaderValue){
      Notify({ type: 'warning', message: '请选择分类' });
      return
    }
    if (!that.data.content) {
      Notify({ type: 'warning', message: '请填写反馈信息' });
      return
    }
    if (!that.data.department_id) {
      Notify({ type: 'warning', message: '请选择能量团' });
      return
    }
    wx.showLoading({
      title: '提交中',
    })
    const extJsonStr = {}
    extJsonStr['name'] = that.data.name
    extJsonStr['content'] = that.data.content
    extJsonStr['type_id'] = that.data.cascaderValue
    extJsonStr['group_id'] = that.data.department_id
    extJsonStr['suggester_id'] = that.data.result
    extJsonStr['name'] = that.data.selectNames
    // 批量上传附件
    if (that.data.picsList) {
      for (let index = 0; index < that.data.picsList.length; index++) {
        const pic = that.data.picsList[index];
        const res = await util.uploadFile(pic.url)
        if (res.code == 1) {
          extJsonStr['file[' + index+']'] = res.data.url
        }else{
          Notify({ type: 'warning', message: '上传文件失败' });
          return;
        }
      }
    }
    util.request(api.FeedbackAdd,extJsonStr).then(function(res){
      wx.hideLoading();
      if(res.code){
        Notify({ type: 'success', message: '提交成功' });
        that.setData({
          name:'',
          content:'',
          type_name:'请选择分类',
          type:'',
          picsList:[],
          department_id:'',
          selectNames:'',
          result:'',
          department_name:'请选择能量团'
        })
      }else{
        Notify({ type: 'warning', message: res.msg });
      }
    })
  },
  
  //文件上传
  afterStoryRead(e){
    let storyPicList = this.data.storyPicList
    if (!storyPicList) {
      storyPicList = []
    }
    storyPicList = storyPicList.concat(e.detail.file)
    this.setData({
      storyPicList
    })
  },
  afterStoryDel(e){
    let storyPicList = this.data.storyPicList
    storyPicList.splice(e.detail.index, 1)
    this.setData({
      storyPicList
    })
  },
  async onClickButton(){
    var that = this;
    console.log(wx.getStorageSync('userInfo'));
    if(!wx.getStorageSync('userInfo').user_id){
      util.BadgePopup();
      return;
    }
    if(!that.data.storyTitle){
      Notify({ type: 'warning', message: '请填写故事主题' });
      return
    }
    console.log(that.data.storyPicList)
    if(that.data.storyPicList.length<1){
      Notify({ type: 'warning', message: '请上传视频' });
      return
    }
    wx.showLoading({
      title: '提交中',
    })
    const extJsonStr = {}
    extJsonStr['title'] = that.data.storyTitle
    extJsonStr['type'] = 'story'
    // 批量上传附件
    for (let index = 0; index < that.data.storyPicList.length; index++) {
      const pic = that.data.storyPicList[index];
      const res = await util.uploadFile(pic.url)
      if (res.code == 1) {
        extJsonStr['file[' + index+']'] = res.data.url
      }else{
        Notify({ type: 'warning', message: '上传文件失败' });
        return;
      }
    }
    util.request(api.StoryAdd,extJsonStr).then(function(res){
      wx.hideLoading();
      if(res.code){
        Notify({ type: 'success', message: '提交成功' });
        that.setData({
          storyTitle:'',
          storyPicList:[],
        })
      }else{
        Notify({ type: 'warning', message: res.msg });
      }
    })
  },
  async onClickTrain(){
    var that = this;
    console.log(wx.getStorageSync('userInfo'));
    if(!wx.getStorageSync('userInfo').user_id){
      util.BadgePopup();
      return;
    }
    if(!that.data.trainTitle){
      Notify({ type: 'warning', message: '请填写培训主题' });
      return
    }
    console.log(that.data.myHarvest.length)
    if(!that.data.myHarvest){
      Notify({ type: 'warning', message: '请填写我的收获' });
      return
    }
    if(that.data.myHarvest.length<100){
      Notify({ type: 'warning', message: '字数不应小于100字' });
      return
    }
    wx.showLoading({
      title: '提交中',
    })
    const extJsonStr = {}
    extJsonStr['title'] = that.data.trainTitle
    extJsonStr['harvest'] = that.data.myHarvest
    extJsonStr['content'] = that.data.trainSuggest
    extJsonStr['type'] = 'train'
    util.request(api.StoryAdd,extJsonStr).then(function(res){
      wx.hideLoading();
      if(res.code){
        Notify({ type: 'success', message: '提交成功' });
        that.setData({
          storyTitle:'',
          storyPicList:[],
        })
      }else{
        Notify({ type: 'warning', message: res.msg });
      }
    })
  },
  // 搜索框输入监听
  onSearchChange(e) {
    const keyword = e.detail;
    this.setData({ searchValue: keyword });

    if (!keyword) {
      // 搜索为空，显示全部
      this.setData({ displayList: this.data.allUserList });
    } else {
      // 前端过滤：只显示包含关键词的人
      const filtered = this.data.allUserList.filter(item => 
        item.name.indexOf(keyword) > -1
      );
      this.setData({ displayList: filtered });
    }
  },

  // 复选框变化监听
  onCheckboxChange(event) {
    this.setData({
      result: event.detail,
    });
  },

  // 点击单元格也能触发勾选
  toggle(event) {
    const { index } = event.currentTarget.dataset;
    const checkbox = this.selectComponent(`.checkboxes-${index}`);
    checkbox.toggle();
  },

  // 点击确定按钮
  onConfirmPeople() {
    const { result, displayList } = this.data;
    // 记得用 allUserList (如果存了全量数据)
    const list = this.data.allUserList || displayList; 

    // 1. 过滤选中项
    const selectedUsers = list.filter(item => 
      result.map(String).includes(String(item.id))
    );

    // 2. 商务风格式化逻辑
    let displayText = '';
    if (selectedUsers.length > 2) {
      // 如果超过2人，显示前两个名字 + 总人数
      // 效果：张三, 李四 等 5 人
      displayText = `${selectedUsers[0].name}, ${selectedUsers[1].name} 等 ${selectedUsers.length} 人`;
    } else {
      // 如果少于等于2人，直接显示名字
      displayText = selectedUsers.map(u => u.name).join(', ');
    }
    // 3. 更新
    this.setData({
      selectNames: displayText,
      personShow: false
    });
  }
})