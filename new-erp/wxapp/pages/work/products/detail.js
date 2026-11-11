// pages/work/detail/index.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
const legacyWorkTimer = require('../../../utils/legacy-work-timer.js');
import Notify from '@vant/weapp/notify/notify';
import Dialog from '@vant/weapp/dialog/dialog';
Page({
  ...legacyWorkTimer({
    infoUrl: api.ProductsInfo,
    pauseUrl: api.ProductsPausedWorkTime,
    continueUrl: api.ProductsContinueWorkTime,
    procedureKey: 'work_step',
  }, Notify),

  /**
   * 页面的初始数据
   */
  data: {
    check_user:0
  },

  /**
   * 生命周期函数--监听页面加载
   */
  onLoad(options) {
    let deviceHeight = wx.getWindowInfo().windowHeight;
    this.setData({
      id:options.id,
      scrollHeight:deviceHeight-200
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
  applyWorkInfo({ info, logs, workers }, keepForm) {
    const data = { info, logs };
    if (!keepForm) {
      data.workers = workers;
      data.check_workers = workers;
      const owner = workers.find(worker => info.step_status === 'primary' && worker.id == info.step_admin);
      workers.forEach(worker => { worker.checked = worker === owner; });
      data.default_worker = owner ? owner.id : 0;
      data.choose_staff = owner ? owner.nickname : '请选择负责人';
    }
    this.setData(data);
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
      this.workReportIdentity = this.workProcedureIdentity();
    }else if(type=='sendProduct'){
      wx.navigateTo({
        url: '/pages/work/products/send?order_id='+e.currentTarget.dataset.id,
      })
    }else if(type=='inStock'){
      data.show_goods=true;
      data.is_inStock=true;
      this.getInfo();
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
    if (that.data.button_stage) return;
    that.setData({
      button_stage:true
    })
    var images = [];
    var id = that.data.id;
    const extJsonStr = { ...this.workReportIdentity };
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
    if(!that.data.use_time){
      Notify({ type: 'warning', message: '请填写工时' });
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
    try {
      for (const pic of that.data.picsList || []) {
        const upload = await util.uploadFile(pic.url);
        if (upload.code != 1) throw new Error('上传文件失败');
        images.push('/' + upload.data.key);
      }
      extJsonStr['images'] = images;
      const res = await util.request(api.ProductsSubStep, extJsonStr);
      if (!res.code) throw new Error(res.msg || '工序提交失败，请重试');
      Notify({ type: 'success', message: '操作成功' });
      that.setData({
        show_stage:false,
        default_worker:0,
        choose_staff:'请选择',
        picsList:[],
        message:'',
        use_time:'',
        check_user:''
      });
    } catch (error) {
      Notify({ type: 'warning', message: error.message || '工序提交失败，请重试' });
      return;
    } finally {
      that.setData({ button_stage: false });
      wx.hideLoading();
    }
    that.getInfo();
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
  accept(e){
    var that = this;
    var id = that.data.id;
    const { procedure } = this.workProcedureIdentity();
    if(!id){
      Notify({ type: 'warning', message: '参数错误' });
      return;
    }
    Dialog.confirm({
      title: '接单后将会开始计算订单工时，是否确认？',
    }).then(() => {
      util.request(api.ProductsOrderAccept,{id, procedure}).then(function(res){
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
