// pages/work/index/index.js

var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Notify from '@vant/weapp/notify/notify';
import todo from '../../../components/calendar/plugins/todo'
import selectable from '../../../components/calendar/plugins/selectable'
import solarLunar from '../../../components/calendar/plugins/solarLunar/index'
import timeRange from '../../../components/calendar/plugins/time-range'
import week from '../../../components/calendar/plugins/week'
import holidays from '../../../components/calendar/plugins/holidays/index'
import plugin from '../../../components/calendar/plugins/index'
Page({

  /**
   * 页面的初始数据
   */
  data: {
    typeOptions: [
      { text: '全部工单', value: 'all' },
      { text: '计划工单', value: 'produce' },
      { text: '工装工单', value: 'assemble' },
      { text: '试水工单', value: 'testing' },
    ],
    default_type:'all',
    menu_name:'搜索',
    option2: [
      { text: '全部工序', value: 'all' },
      { text: '下料', value: 'cutting' },
      { text: '焊接', value: 'welding' },
      { text: '打磨', value: 'polish' },
      { text: '组装', value: 'assemble' },
      { text: '试水', value: 'testsing' },
      { text: '打包', value: 'packaging' },
      { text: '发货', value: 'sending' },
    ],
    value1: 'all',
    value2: 'all',
    status:'0',
    list:[],
    keys:'',
    page:1,
    totalPages:1,
    loadmoreText: '正在加载更多数据',
    nomoreText: '全部加载完成',
    nomore: false,
    limit:2,
    calendarConfig: {
      multi: true, // 是否开启多选,
      showLunar: true, // 是否显示农历，此配置会导致 setTodoLabels 中 showLabelAlways 配置失效
      chooseAreaMode:true,
      theme: 'elegant',
    },
    show_search_box:false,
    show_date_choose:false,
    show_type_choose:false,
    show_saler_choose:false,
    saler_text:'请选择销售',
    product_name:'',
    saler:'',
    date:[]
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    plugin
    .use(todo)
    .use(solarLunar)
    .use(selectable)
    .use(week)
    .use(timeRange)
    .use(holidays)
    this.setData({
      page:1,
      totalPages:1,
      list:[]
    });
    this.getWorkList()
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
    this.getTypeOptions();
  },
  onSearch(e){
    console.log(e)
    var that = this;
    that.setData({
      keys:e.detail,
      page:1,
      totalPages:1,
    })
    that.getWorkList();
  },
  searchCancle(){
    var that = this;
    that.setData({
      keys:'',
      page:1,
      totalPages:1,
    })
    that.getWorkList();
  },
  /**
   * 页面相关事件处理函数--监听用户下拉动作
   */
  onPullDownRefresh() {
    console.log('1111')
  },

  /**
   * 页面上拉触底事件的处理函数
   */
  onReachBottom() {
    this.getWorkList();
  },
  getTypeOptions(){
    var that=this;
    util.request(api.getWorksType).then(function(res){
      if(res.code){
        that.setData({
          typeOptions:res.data
        });
      }
    });
    util.request(api.getSales).then(function(res){
      if(res.code){
        that.setData({
          salerOptions:res.data
        });
      }
    });
  },
  searchType(e){
    console.log(e);
    var that = this;
    that.setData({
      default_type:e.detail,
      page:1,
      totalPages:1,
      list:[]
    });
    that.getWorkList();
  },
  getWorkList(){
    var that = this;
    if (that.data.totalPages <= that.data.page-1) {
      that.setData({
        nomore: true
      })
      return;
    }
    const status = that.data.status;
    const type = that.data.default_type;
    const keys = that.data.keys;
    const date = that.data.date;
    const page = that.data.page;
    const limit = that.data.limit;
    const product_name = that.data.product_name;
    const saler = that.data.saler;

    util.request(api.GetWorkList,{status:status,keys:keys,type:type,date:date,product_name:product_name,page:page,limit:limit,saler:saler}).then(function(res){
      var workList=res.data.data
      var loadend=workList.length < that.data.limit;
      if(that.data.keys){
        that.data.list = res.data.data;
      }else{
        that.data.list = that.data.list.concat(res.data.data);
      }
      that.setData({
        list: that.data.list,
        page: res.data.current_page+1,
        totalPages: res.data.last_page
      });
    })
  },
  changeStatus(e){
    console.log(e);
    var that=this;
    that.setData({
      status:e.detail.index,
      page:1,
      totalPages:1,
      list:[]
    });
    that.getWorkList();
  },
  goDetail(e){
    wx.navigateTo({
      url: '/pages/work/detail/index?id='+e.currentTarget.dataset.id,
    })
  },
  newOrder(e){
    console.log(e);
    wx.navigateTo({
      url: '/pages/work/products/index?id='+e.currentTarget.dataset.id,
    })
  },
  accept(e){
    var that = this;
    console.log(e)
    const id = e.currentTarget.dataset.id;
    if(!id){
      Notify({ type: 'warning', message: '上传文件失败' });
      return;
    }
    util.request(api.WorkAccept,{id:id}).then(function(res){
      console.log(res);
      if(res.code){
        that.setData({
            page:1,
            list:[]
        })
        that.getWorkList();
      }
    })
  },
  onFloatingButtonTap(){
    wx.navigateTo({
      url:'/pages/work/my/index'
    })
  },
  showPop(e){
    var that =this;
    const type = e.currentTarget.dataset.type;
    var data = {};
    if(type=='search-box'){
      data.show_search_box = true;
    }else if(type=='search-date'){
      data.show_date_choose = true;
    }else if(type=='search-type'){
      data.show_type_choose = true;
    }else if(type=='search-saler'){
      data.show_saler_choose = true;
    }
    that.setData(data);
  },
  hidePop(e){
    var that=this;
    const type = e.currentTarget.dataset.type;
    var data = {};
    if(type=='search-date'){
      data.show_date_choose = false;
    }else if(type=='search-box'){
      data.show_search_box = false;
    }else if(type=='search-type'){
      data.show_type_choose = false;
    }else if(type=='search-saler'){
      data.show_saler_choose = false;
    }
    that.setData(data);
  },
  afterTapDate(e){
    console.log(e);
    var that = this;
    var date = that.data.date;
    const choose_date = e.detail.year+'-'+e.detail.month+'-'+e.detail.date;
    if(date.length==2){
      date.length=0;
    }else if(date.length==1){
      that.setData({
        show_date_choose:false
      })
    }
    date.push(choose_date);
    that.setData({
      date:date
    })
  },
  onConfirm(e){
    var that = this;
    console.log(e)
    if(e.currentTarget.dataset.type=='search-saler'){
      that.setData({
        saler_text:e.detail.value.text,
        saler:e.detail.value.value,
        show_saler_choose:false
      });

    }else{
      that.setData({
        type_text:e.detail.value.text,
        type:e.detail.value.value,
        default_type:e.detail.value.value,
        show_type_choose:false
      });
    }
  },
  resetSearch(e){
    this.setData({
      type_text:'',
      type:'all',
      default_type:'all',
      product_name:'',
      saler_text:'',
      saler:'',
      date:[]
    });
  },
  confirmSearch(){
    this.setData({
      page:1,
      totalPages:1,
      list:[],
      show_search_box:false
    })
    this.getWorkList();
  }
})