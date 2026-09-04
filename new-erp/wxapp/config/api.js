//const API_BASE_URL = 'http://localhost:8002/api/erp/';
const API_BASE_URL = 'https://erp.sdjiantan.com/api/erp/';
const ORIGIN_URL = 'https://api.sdjiantan.com/';
module.exports = {
    AdminList:API_BASE_URL+'index/userList',
    ConfigApp:API_BASE_URL+'appinfo',
    UserLogin:API_BASE_URL+'index/login',
    WechatLogin:API_BASE_URL+'index/loginWechat',
    IndexUrlHome:API_BASE_URL + 'index/home',
    AllAdmin:API_BASE_URL + 'index/adminCheckOptions',
    DeliveryList:API_BASE_URL + 'index/deliveryList',

    //个人中心
    ChangeUserProfile:API_BASE_URL+'user/changeInfo',
    UploadFile:API_BASE_URL+'files/uploadImg',
    MyFeedback: API_BASE_URL + 'feedback/my', //我的反馈
    getAdminInfo: API_BASE_URL + 'points/myScore',

    //反馈
    FeedbackAdd: API_BASE_URL + 'feedback/index/add', //添加反馈
    FeedbackList:API_BASE_URL + 'feedback/index/index',//反馈列表
    FeedbackDel:API_BASE_URL + 'feedback/index/del',
    FeedbackInfo:API_BASE_URL + 'feedback/index/info',
    FeedbackOptions:API_BASE_URL+ 'index/suggestOptions',
    DepartmentOptions:API_BASE_URL+ 'index/departmentOptions',

    //感动故事
    StoryAdd:API_BASE_URL + 'story/add',

    //改良委后台
    CheckAccess:API_BASE_URL + 'feedback/index/checkAuth', //改良委后台权限
    FeedbackManageList:API_BASE_URL+ 'feedback/manage/list',
    AcceptFeedback:API_BASE_URL+ 'feedback/manage/accept',
    FeedbackManageAdd:API_BASE_URL+ 'feedback/manage/sub',

    //订单https://api.sdjiantan.com/v1/order/get_user_order_list?type=20&page=1&limit=10
    OrderList:ORIGIN_URL + 'v1/order/get_user_order_list',
    OrderInfo:ORIGIN_URL + 'v1/order/get_order',
    OrderSend:ORIGIN_URL + 'v1/order/send',

    //新订单
    NewOrderInfo:API_BASE_URL +'orders/info',
    NewOrderSend:API_BASE_URL +'orders/send',

    //工单
    getWorksType:API_BASE_URL +'index/getWorksOrderType',
    getSales:API_BASE_URL +'index/getSales',
    GetWorkList:API_BASE_URL +'work/index/index',
    WorkAccept:API_BASE_URL +'work/order/accept',
    WorksOrderInfo:API_BASE_URL + 'work/order/info',
    WorkAcceptSteps:API_BASE_URL + 'work/order/acceptStep',
    WorkSkip:API_BASE_URL + 'work/order/skip',
    WorkSubStep:API_BASE_URL + 'work/order/subStep',
    GetMyWorkList:API_BASE_URL + 'work/order/my',
    WorkOrderPrint:API_BASE_URL + 'work/order/print',
    WorkInStock:API_BASE_URL + 'work/order/inStock',
    PausedWorkTime:API_BASE_URL + 'work/order/pausedTime',//暂停工时
    ContinueWorkTime:API_BASE_URL + 'work/order/continueTime',//暂停工时
    InStockWorksRecord:API_BASE_URL + 'work/order/instockRecord',//暂停工时

    //工单流程
    ProductsPre:API_BASE_URL + 'work/products/pre',
    ProductsIndex:API_BASE_URL + 'work/products/index',
    ProductsInfo:API_BASE_URL + 'work/products/info',
    ProductsOrderAccept:API_BASE_URL + 'work/products/accept',
    ProductsSubStep:API_BASE_URL + 'work/products/subStep',
    NewOrderInfo2:API_BASE_URL +'orders/info2',
    NewOrderSend2:API_BASE_URL +'orders/send2',
    
    //积分商城
    GetGoodsList:API_BASE_URL+'points/goodsList',
    GetGoodsInfo:API_BASE_URL+'points/info',
    GoodsExchange:API_BASE_URL+'points/exchange',

    //配件出入库
    AccessoryCart:API_BASE_URL+'stock/cartList',
    AddAccessoryCart:API_BASE_URL+'stock/addToCart',
    RemoveAccessoryCart:API_BASE_URL+'stock/removeCart',
    changeAccessoryCart:API_BASE_URL+'stock/changeCart',
    AccessoryFirst:API_BASE_URL+'index/firstList',
    AccessoryStockIn:API_BASE_URL+'stock/addStock',
    AccessoryStockOut:API_BASE_URL+'stock/outStock',
    EmptyStockList:API_BASE_URL+'stock/emptyStock',
    ChangeImage:API_BASE_URL+'stock/changeImg',
    ChangePrice:API_BASE_URL+'stock/changePrice',
    QuickAccessoryList:API_BASE_URL+'stock/quickList',
    QuickAddAccessoryCart:API_BASE_URL+'stock/quickAddCart',
    SearchFields:API_BASE_URL+'stock/searchFields',
    ScanInfo:API_BASE_URL+'stock/scanInfo',
    ScanAddAccessoryCart:API_BASE_URL+'stock/scanAddCart',
    //订单核价
    AccessoryCostLog:API_BASE_URL+'cost/costList',
    RemoveAccessoryCost:API_BASE_URL+'cost/removeCost',
    changeAccessoryCost:API_BASE_URL+'cost/changeCost',
    AddAccessoryCost:API_BASE_URL+'cost/addToCost',
    EmptyAccessoryCost:API_BASE_URL+'cost/emptyCost',
    SubCostList:API_BASE_URL+'cost/subList',

    AccessoryCategory:API_BASE_URL+'staff/accessory/categoryList',
    AccessoryList:API_BASE_URL+'staff/accessory/list',
    AccessoryInfo:API_BASE_URL+'staff/accessory/info',

    //日报流程
    getTemplateDetail:API_BASE_URL+'report/get_template_detail', 
    saveTemplate:API_BASE_URL+'report/save_template',
    getUserDict:'https://erp.sdjiantan.com/api/user/get_user_dict',
    getTemplateList: API_BASE_URL+'report/get_template_list',
    delTemplate: API_BASE_URL+'report/del_template',
    changeTemplateStatus: API_BASE_URL+'report/change_status',
    getAvailableTemplates: API_BASE_URL+'report/get_available_templates',
    submitReport: API_BASE_URL+'report/submit_report',
    getOssSign:'https://erp.sdjiantan.com/api/index/get_oss_sign',
    getReportBanners: API_BASE_URL+'report/get_banners',
    // 检查今日记录或获取单条详情
    checkTodayRecord: API_BASE_URL+'report/check_today_record',
    getReportStats: API_BASE_URL+'report/get_report_stats',
    getReportList: API_BASE_URL+'report/get_report_list',
    getReportDetail: API_BASE_URL+'report/get_report_detail',
    markAllRead: API_BASE_URL+'report/mark_all_read',
    getSubmissionStats: API_BASE_URL+'report/get_submission_stats',
    exportReportList: API_BASE_URL+'report/export_report_list',
    getReviews: API_BASE_URL+'report/get_reviews',
    addReview: API_BASE_URL+'report/add_review',
    getYesterdayRecord:API_BASE_URL+'report/get_yesterday_record',
    getHistoryRecord: API_BASE_URL + 'report/get_history_record',
    getDeptList: API_BASE_URL + 'report/get_dept_list',
    CheckCustomerNeedsHistory:API_BASE_URL + 'customer_needs/check_history',
    SubmitCustomerNeeds:API_BASE_URL + 'customer_needs/submit_needs',
    SearchMyCustomers:API_BASE_URL + 'customer_needs/search_my_customers',
    GetNeedsList:API_BASE_URL + 'customer_needs/get_needs_list',
    GetCustomerHistory: API_BASE_URL + 'customer_needs/get_customer_history',
    addReportPoints: API_BASE_URL+'report/add_points',
};
