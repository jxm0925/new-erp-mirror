// 引入全局函数
const app = getApp()
Component({
	data: {
    active:0,
    list: [{
      "pagePath": "pages/index/index",
      "icon":"home-o",
      "value":"home",
      "text": "首页"
    },{
      "pagePath": "scan",
      "icon": "scan",
      "value":"scan",
      "text": "扫一扫"
    },{
      "pagePath": "pages/my/index/index",
      "icon":"user-o",
      "value":"my",
      "text": "个人中心"
    }]
	},
  /**
   * 组件的方法列表
   */
  methods: {
    onChange(event) {
      if(this.data.list[event.detail].pagePath == 'scan'){
        wx.scanCode({
            success: (res) => {
                //处理扫码结果
                wx.navigateTo({url: '/pages/orders/detail/index?order_no=' + res.result})
            }
        })
        console.log('开启扫一扫');
      }else{
        wx.switchTab({
          url: '/'+this.data.list[event.detail].pagePath,
        })
      }
    },
  }
})