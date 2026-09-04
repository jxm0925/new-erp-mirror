Component({
  properties: {
    current: {
      type: String,
      value: 'index'
    }
  },

  data: {
    // 💥 核心修改：引入自定义图片的双状态路径 💥
    allTabs: [
      { 
        id: 'index', 
        name: '汇报中心', 
        // 👇 这里换成你自己的本地图片路径 (比如 /assets/icons/home.png)
        iconPath: '/static/images/icon_center.png', 
        selectedIconPath: '/static/images/icon_center_sel.png', 
        path: '/pages/my/report/index/index' 
      },
      { 
        id: 'list', 
        name: '汇报列表', 
        iconPath: '/static/images/icon_list.png', 
        selectedIconPath: '/static/images/icon_list_sel.png', 
        path: '/pages/my/report/list/list' 
      },
      { 
        id: 'manage', 
        name: '汇报管理', 
        iconPath: '/static/images/icon_manage.png', 
        selectedIconPath: '/static/images/icon_manage_sel.png', 
        path: '/pages/my/report/manage/manage', 
        requireAuth: true 
      }
    ],
    renderTabs: [] 
  },

  lifetimes: {
    attached() {
      this.initAuthTabs();
    }
  },

  pageLifetimes: {
    show() {
      this.initAuthTabs();
    }
  },

  methods: {
    initAuthTabs() {
      const staffInfo = wx.getStorageSync('userInfo') || {};
      const isPrincipal = staffInfo.is_principal === 1;
      const isSuperAdmin = staffInfo.is_super_admin === 1;

      const hasAuth = isPrincipal || isSuperAdmin;

      const finalTabs = this.data.allTabs.filter(tab => {
        if (tab.requireAuth) return hasAuth;
        return true; 
      });

      this.setData({ renderTabs: finalTabs });
    },

    switchTab(e) {
      console.log(e)
      const targetPath = e.currentTarget.dataset.path;
      // 拿到当前页面的路径，如果点击的就是当前页面，直接 return 不做跳转
      const pages = getCurrentPages();
      const currentPage = pages[pages.length - 1];
      if ('/' + currentPage.route === targetPath) return;

      // 必须用 redirectTo 替换当前页面，制造出 tabBar 平滑切换的感觉
      wx.redirectTo({
        url: targetPath
      });
    }
  }
})