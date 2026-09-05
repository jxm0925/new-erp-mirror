// 引入全局函数
const app = getApp()
Component({
	data: {
    active:0,
    list: [{
      "pagePath": "pages/index/index",
      "icon":"home-o",
      "value":"home",
      "text": "工作台"
    },{
      "pagePath": "pages/production/tasks/index",
      "icon":"orders-o",
      "value":"tasks",
      "text": "任务"
    },{
      "pagePath": "pages/production/todos/index",
      "icon": "todo-list-o",
      "value":"todos",
      "text": "待办"
    },{
      "pagePath": "pages/my/index/index",
      "icon":"user-o",
      "value":"my",
      "text": "我的"
    }]
	},
  /**
   * 组件的方法列表
   */
  methods: {
    select(event) {
      const index = Number(event.currentTarget.dataset.index)
      wx.switchTab({ url: '/'+this.data.list[index].pagePath })
    },
  }
})
