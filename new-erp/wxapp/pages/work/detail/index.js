// pages/work/detail/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
import Notify from '@vant/weapp/notify/notify';
import Dialog from '@vant/weapp/dialog/dialog';
Page({

  /**
   * 页面的初始数据
   */
  data: {
    stage_result:{
      time:'',
      staff_id:''
    },
    use_time:'',//工时
    tab_index:0,
    stage_name:'',
    check_user:'',
    need_stage:true,
    show_result:false,
    default_worker:0,
    show_staff:false,
    show_check_staff:false,
    show_stage:false,
    message:'',
    checked:true,
    active:0,
    choose_staff:'请选择负责人',
    choose_check_staff:'请选择检视人',
    workers: [],
    fileList: [],
    admin_id:'',
    images:[],
    button_stage:false,
    show_goods:false,
    works_other:[],
    is_inStock_secord:0,
    time_count_down:0,
    record_data:{}
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    let userInfo = wx.getStorageSync('userInfo')
    let deviceHeight = wx.getWindowInfo().windowHeight;
    this.setData({
      id:options.id,
      scrollHeight:deviceHeight-320
    })
    this.getInfo();
  },

  previewFiles(e) {
    const file_info = e.currentTarget.dataset;
    console.log(file_info)
    if(file_info.type=='image'){
      wx.previewImage({
        current:file_info.url,
        urls:[file_info.url]
      });
    }else{
      console.log(file_info.url)
      wx.downloadFile({
        url: file_info.url,
        header: {
          'access-token': wx.getStorageSync('access_token')
        },
        success (res) {
          if (res.statusCode === 200) {
            wx.openDocument({
              filePath: res.tempFilePath,
              success: function (res) {
                console.log(' ')
              }
            })
          }
        }
      })
    }
  },
  toggle(e) {
    var that = this;
    let index = e.currentTarget.dataset.index;

    var subdata = that.data.workers;
    subdata[index].checked = !subdata[index].checked;
    that.setData({
      workers:subdata
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
    data.choose_staff = subValueArr;
    data.default_worker  = subCodeArr;
    if(subCodeArr.length==0){
      data.choose_staff = '请选择负责人';
    }
    that.setData(data)
    
  },
  /**
   * 生命周期函数--监听页面显示
   */
  // updateTime() {
  //   const hours = String(Math.floor(this.data.secondsElapsed / 3600)).padStart(2, '0');
  //   const minutes = String(Math.floor((this.data.secondsElapsed % 3600) / 60)).padStart(2, '0');
  //   const seconds = String(this.data.secondsElapsed % 60).padStart(2, '0');
    
  //   this.setData({ hours, minutes, seconds });
  // },
  getInfo(){
    var that = this;
    util.request(api.WorksOrderInfo,{id:that.data.id}).then(function(res){
      if(res.code){
        var data = {};
        data.info = res.data.info;
        data.logs = res.data.logs;
        data.workers = res.data.workers;
        data.check_workers = res.data.workers
        for(var i=0;i<data.workers.length;i++){
          data.workers[i].checked=false;
          if(data.info.step_status=='accept' && data.workers[i].id==data.info.step_admin){
            data.default_worker = data.workers[i].id;
            data.choose_staff = data.workers[i].nickname
            data.workers[i].checked=!data.workers[i].checked;
          }
        }
        //处理数据，已完成入库剔除
        var temp_instock_arr = [];
        for(var k=0;k<res.data.info.products.length;k++){
          if(res.data.info.products[k].amount==res.data.info.products[k].instock_nums){
            continue;
          }
          res.data.info.products[k].nums=1;//数量默认为1
          temp_instock_arr.push(res.data.info.products[k]);
        }
        
        data.instock_arr = temp_instock_arr;
        data.works_other=res.data.info.works_orher;
        data.workers=data.workers;
        //工时相关
        data.time_count_down = res.data.time_record.time_count_down;
        data.total_time_ms = res.data.time_record.all_work_time * 1000; // 总工时转换为毫秒
        data.alertPoints={ '50': false, '25': false, '10': false, '5': false };
        data.count_down_status = res.data.time_record.status;

        console.log(data)
        that.setData(data);

        that.setData(data, () => {
          const countdown = that.selectComponent('.control-count-down');
        
          // 更新剩余时间（必须）
          that.setData({
            time_count_down: res.data.time_record.time_count_down
          });
        
          if (res.data.time_record.status == 1 || res.data.time_record.status==0) {
            // 状态 1 = 暂停
            countdown.pause();
          } else {
            // 状态 0 = 继续
            countdown.start();
          }
        });
      }
    });
  },
  showPop(e){
    var that=this;
    var data ={};
    const type = e.currentTarget.dataset.type;
    if(type=='staff'){
      data.show_staff=true;
    }else if(type=='check_staff'){
      data.show_check_staff=true;
    }else if(type=='stage'){
      data.show_stage=true;
      //暂停工序时间
      
      that.getInfo();
      //获取当前时间戳  
      // var timestamp = Date.parse(new Date());
      // timestamp = timestamp / 1000;
      
      // util.request(api.PausedWorkTime,{id:that.data.info.id,paused_time:timestamp,time_count_down:that.data.time_count_down}).then(function(res){
      //   if(res.code){
      //     that.getInfo();
      //   }else{
      //     Notify({ type: 'warning', message: res.msg });
      //   }
      // })
    }else if(type=='inStock'){
      data.show_goods=true;
      data.is_inStock=true;
      this.getInfo();
    }else if(type=='sendProduct'){
      wx.navigateTo({
        url: '/pages/work/send/index?order_id='+e.currentTarget.dataset.id,
      })
    }else if(type=='inStockRecord'){
      //获取入库记录
      util.request(api.InStockWorksRecord,{id:that.data.info.id}).then(function(res){
        if(res.code){
          that.setData({
            record_data:res.data
          });
        }
      })
      data.is_inStock_secord=true;
    }else{
      data.show_more=true;
    }
    that.setData(data);
  },
  onCancel(e){
    const type = e.currentTarget.dataset.type;
    var that=this;
    var data ={};
    if(type=="staff"){
      data.show_staff=false;
    }else if(type=='check_staff'){
      data.show_check_staff=false;
    }else if(type=='stage'){
      data.show_stage=false;
      //继续计时
      that.getInfo();
      //获取当前时间戳  
      // var timestamp = Date.parse(new Date());
      // timestamp = timestamp / 1000;
      // util.request(api.ContinueWorkTime,{id:that.data.info.id,continue_time:timestamp}).then(function(res){
      //   console.log(res);
      //   if(res.code){
      //     that.getInfo();
      //   }else{
      //     Notify({ type: 'warning', message: res.msg });
      //   }
      // })
    }else if(type=='result'){
      data.show_result=false;
    }else if(type=='goods'){
      data.show_goods=false;
    }else if(type=='inStockRecord'){
      data.is_inStock_secord=false;
    }else{
      data.show_more=false;
    }
    that.setData(data);
  },
  confirmCheckWorks(e){
    console.log(e)
    var that = this;
    that.setData({
      show_check_staff:false,
      choose_check_staff:e.detail.value.nickname,
      check_user:e.detail.value.id
    })
  },
  async subStage(){
    var that = this;
    that.setData({
      button_stage:true
    })
    var images = [];
    var id = that.data.id;
    const extJsonStr = {}
    if(!that.data.default_worker){
      Notify({ type: 'warning', message: '请选择工序负责人' });
      that.setData({
        button_stage:false
      })
      return;
    }
    if(!that.data.check_user && that.data.info.work_status=='testing'){
      Notify({ type: 'warning', message: '请选择工序检视人' });
      that.setData({
        button_stage:false
      })
      return;
    }
    wx.showLoading({
      title: '媒体文件上传中',
    });
    extJsonStr['id'] = id
    extJsonStr['use_time'] = that.data.use_time
    extJsonStr['check_user'] = that.data.check_user
    extJsonStr['message'] = that.data.message
    extJsonStr['need_stage'] = that.data.need_stage
    extJsonStr['default_worker'] = that.data.default_worker
    extJsonStr['system_worktime'] = that.data.system_worktime
    if (that.data.picsList) {
      for (let index = 0; index < that.data.picsList.length; index++) {
        const pic = that.data.picsList[index];
        const res = await util.uploadFile(pic.url)
        if (res.code == 1) {
          images.push('/'+res.data.key)
        }else{
          Notify({ type: 'warning', message: '上传文件失败' });
          that.setData({
            button_stage:false
          })
          return;
        }
      }
    }
      
    extJsonStr['images'] = images;
    util.request(api.WorkSubStep,extJsonStr).then(function(res){
      if(res.code){
        that.setData({
          button_stage:false
        })
        Notify({ type: 'success', message: '操作成功' });
        
        that.setData({
          show_stage:false,
          default_worker:0,
          choose_staff:'请选择',
          picsList:[],
          message:'',
          use_time:'',
          check_user:''
        })
        that.onLoad({id:id});
      }else{
        Notify({ type: 'warning', message: res.msg });
      }
      wx.hideLoading();
    })
  },
  selectStageUser(e){
    var that = this;
    that.setData({
      choose_staff:e.detail.value.nickname,
      default_worker:e.detail.value.id,
      show_staff:false
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
  skipSteps(e){
    var that = this;
    const id = e.currentTarget.dataset.id;
    Dialog.confirm({
      title: '跳过工序后此工序将立即结束，是否确认？',
    }).then(() => {
      util.request(api.WorkSkip,{id:id}).then(function(res){
        if(res.code){
          Notify({ type: 'success', message: '操作成功' });
          that.onLoad({id:id});
        }else{
          Notify({ type: 'warning', message: res.msg });
        }
      })
    
    }).catch(() => {
      // on cancel
    });
  },
  acceptSteps(e){
    var that = this;
    var id = that.data.id;
    Dialog.confirm({
      title: '是否接单？',
    }).then(() => {
      util.request(api.WorkAcceptSteps,{id:id}).then(function(res){
        if(res.code){
          Notify({ type: 'success', message: '接受工序成功' });
          that.onLoad({id:id});
        }else{
          Notify({ type: 'warning', message: res.msg });
        }
      })
    }).catch(() => {
      // on cancel
    });
  },
  accept(e){
    var that = this;
    var id = that.data.id;
    if(!id){
      Notify({ type: 'warning', message: '上传文件失败' });
      return;
    }
    Dialog.confirm({
      title: '接单后将会开始计算订单工时，是否确认？',
    }).then(() => {
      util.request(api.WorkAccept,{id:id}).then(function(res){
        if(res.code){
          Notify({ type: 'success', message: '接单成功' });
          that.onLoad({id:id});
        }else{
          Notify({ type: 'warning', message: res.msg });
        }
      })
    
    }).catch(() => {
      // on cancel
    });
  },
  // back2list(){
  //   wx.navigateBack({
  //     delta: 1,
  //     success: function (e) {
  //       var page = getCurrentPages().pop();
  //       if (page == undefined || page == null) return;
  //       page.onLoad(); // 刷新数据
  //     }
  //   });
  // },
  printOrder(e){
    console.log(e);
    var id = e.target.dataset.id;
    var type = e.target.dataset.type;
    if(!id){
      Notify({ type: 'warning', message:'id信息为空' });
      return;
    }
    util.request(api.WorkOrderPrint,{id:id,type:type}).then(function(res){
      if(res.code){
        Notify({ type: 'success', message: '重新打印成功' });
        that.onLoad({id:id});
      }else{
        Notify({ type: 'warning', message: res.msg });
      }
    })
  },
  onChangeCountDown(e){
    if(e.detail.days){
      e.detail.hours+=e.detail.days*24;
    }
    this.setData({
      timeData: e.detail,
    });
  },
  subInStock(e){
    var that = this;
    var instock = JSON.stringify(that.data.instock_arr);
    util.request(api.WorkInStock,{id:that.data.id,instock_arr:instock}).then(function(res){
      console.log(res);
      if(res.code){
        Notify({ type: 'success', message: '入库成功' });
        
        that.setData({show_goods:false});
        that.onLoad({id:that.data.id});
      }else{
        Notify({ type: 'warning', message: res.msg });
      }
    })
  },
  cartStepChange(e){
    const index = e.currentTarget.dataset.idx;
    let carts = this.data.info.products;
    carts[index].nums = e.detail;
    this.setData({
      instock_arr:carts
    });
  },
  deleteCart(e){
    let carts = this.data.instock_arr;
    carts.splice(e.currentTarget.dataset.idx,1);
    this.setData({
      instock_arr:carts
    })
  },
  switchTimeButton(e){
    console.log(e);
    const data = e.currentTarget.dataset
    var that = this;
    //获取当前时间戳  
    var timestamp = Date.parse(new Date());
    timestamp = timestamp / 1000;
    console.log(that.data.info);
    if(data.type=='paused'){
      util.request(api.PausedWorkTime,{id:that.data.info.id,paused_time:timestamp}).then(function(res){
        console.log(res);
        if(res.code){
          that.getInfo();
        }else{
          Notify({ type: 'warning', message: res.msg });
        }
      })
    }else{
      util.request(api.ContinueWorkTime,{id:that.data.info.id,continue_time:timestamp}).then(function(res){
        console.log(res);
        if(res.code){
          that.getInfo();
        }else{
          Notify({ type: 'warning', message: res.msg });
        }
      })
    }
  },
  copyNo(e){
    wx.setClipboardData({
      data: e.currentTarget.dataset.no,
      success (res) {
        wx.getClipboardData({
          success (res) {
            console.log(res.data) // data
          }
        })
      }
    })
  }
})