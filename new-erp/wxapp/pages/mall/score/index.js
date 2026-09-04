// pages/score/logs.js
var util = require('../../../utils/util.js');
var api = require('../../../config/api.js');
Page({
  data: {
    totalScore: 1280,
    level: 3,
    activeTab: 0, // 0 全部 1 增加 2 扣减
    page: 1,
    loading: false,
    finished: false,
    recordList: []
  },

  onLoad() {
    this.loadData()
  },

  onTabChange(e) {
    this.setData({
      activeTab: e.detail.index,
      page: 1,
      recordList: [],
      finished: false
    })
    this.loadData()
  },

  onLoadMore() {
    this.loadData()
  },

  loadData() {
    var that = this;
    if (that.data.loading || that.data.finished) return
    this.setData({ loading: true })

    util.request(api.getAdminInfo).then(function(res){
      console.log(res);
      if(res.code){
        const allData = res.data.list;
        let list = allData

        if (that.data.activeTab === 1) {
          list = allData.filter(item => item.operate === 'add')
        } else if (that.data.activeTab === 2) {
          list = allData.filter(item => item.operate === 'sub')
        }
  
        that.setData({
          recordList: that.data.recordList.concat(list),
          loading: false,
          score:res.data.score
        })
      }
    });
  }
})
