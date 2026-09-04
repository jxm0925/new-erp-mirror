
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Dialog from '@vant/weapp/dialog/dialog';
import Notify from '@vant/weapp/notify/notify';

// pages/suggests/manage/result.js
Page({

  /**
   * 页面的初始数据
   */
  data: {
    autosize: {
      minHeight: 100
    },
    type_name:'请选择落地级别',
    typeShow:false,
    sub_name:'请选择提案人',
    subShow:false,
    excute_name:'请选择落地人',
    excuteShow:false,
    sub_score:'',
    excute_score:'',
    sub_columns: {},
    excute_columns:[],
    type_columns:[],
    sub_ids: [],
    excute_ids: [],
    can_excute:true,
    result:''//落地级别
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    console.log(options)
    this.setData({
      id:options.id
    })
    this.getInfo()
    this.getAllAdmin()
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

  getInfo(){
    var that = this;
    util.request(api.FeedbackInfo,{id:that.data.id}).then(function(res){
      console.log(res);
      if(res.code){
        that.setData({
          info:res.data
        })
      }
    });
  },
  getAllAdmin(){
    var that = this;
    util.request(api.AllAdmin).then(function(res){
      console.log(res);
      if(res.code){
        that.setData({
          sub_columns:res.data.sub_arr,
          excute_columns:res.data.excute_arr,
          type_columns:res.data.type
        })
      }
    });
  },
  confirmType(e){
    console.log(e);
    var that = this;
    var data = {};
    data.result = e.detail.value.id;
    data.typeShow = !that.data.typeShow;
    data.type_name = e.detail.value.name;
    if(e.detail.value.id==5){
      data.can_excute = false;
    }else{
      data.can_excute = true;
    }
    that.setData(data)
  },
  showPopup(e){
    console.log(e)
    var that = this;
    const type = e.currentTarget.dataset.type;
    if(type=='sub'){
      that.setData({
        subShow:!that.data.subShow
      })
    }else if(type=='excute'){
      that.setData({
        excuteShow:!that.data.excuteShow
      })
    }else if(type=='type'){
      that.setData({
        typeShow:!that.data.typeShow
      })
    }
  },
  toggle(e) {
    var that = this;
    const type = e.currentTarget.dataset.type;
    let index = e.currentTarget.dataset.index;

    if(type=='sub'){
      console.log(1111);
      var subdata = that.data.sub_columns;
      console.log(that.data.sub_columns)
      console.log(that.data.excute_columns)
      subdata[index].checked = !subdata[index].checked;
      that.setData({
        sub_columns:subdata
      })
      var subValueArr = [];
      var subCodeArr = [];
      for (let i = 0; i < subdata.length; i++) {
        const v = subdata[i];
        if(v.checked) {
          subValueArr.push(v.nickname);
          subCodeArr.push(v.id);
        }
      }
      let data = {};
      data.sub_name = subValueArr;
      data.sub_ids  = subCodeArr;
      if(subCodeArr.length==0){
        data.sub_name = '请选择提案人';
      }
      that.setData(data)
    }else{
      console.log(2222)
      let excutedata = that.data.excute_columns;
      excutedata[index].checked = !excutedata[index].checked;
      that.setData({
        excute_columns:excutedata
      })
      let excuteValueArr = [];
      let excuteCodeArr = [];
      for (let i = 0; i < excutedata.length; i++) {
        const v = excutedata[i];
        if(v.checked) {
          excuteValueArr.push(v.nickname);
          excuteCodeArr.push(v.id);
        }
      }
      let data = {};
      data.excute_name = excuteValueArr;
      data.excute_ids  = excuteCodeArr;
      if(excuteCodeArr.length==0){
        data.excute_name = '请选择提案人';
      }
      that.setData(data)
    }
  },
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
  async submit(){
    var that = this;
    if(!wx.getStorageSync('token')){
      util.BadgePopup();
      return;
    }
    // wx.showLoading({
    //   title: '提交中',
    // })
    const extJsonStr = {}
    extJsonStr['id'] = that.data.id
    extJsonStr['suggester_id'] = that.data.sub_ids
    extJsonStr['score'] = that.data.sub_score
    extJsonStr['executor_score'] = that.data.excute_score
    extJsonStr['content'] = that.data.content
    extJsonStr['executor_ids'] = that.data.excute_ids
    extJsonStr['result'] = that.data.result
    // 批量上传附件
    if (that.data.picsList) {
      for (let index = 0; index < that.data.picsList.length; index++) {
        const pic = that.data.picsList[index];
        const res = await util.uploadFile(pic.url)
        if (res.code == 1) {
          extJsonStr['file[' + index+']'] = '/'+res.data.key
        }else{
          Notify({ type: 'warning', message: '上传文件失败' });
          return;
        }
      }
    }
    
    util.request(api.FeedbackManageAdd,extJsonStr).then(function(res){
      wx.hideLoading();
      console.log(res);
      if(res.code){
        Notify({ type: 'success', message: '操作成功' });
        
        that.onLoad({id:that.data.id});
      }else{
        Notify({ type: 'error', message: res.msg });

      }
    })
  },
})