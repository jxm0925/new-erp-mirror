const formatTime = date => {
  const year = date.getFullYear()
  const month = date.getMonth() + 1
  const day = date.getDate()
  const hour = date.getHours()
  const minute = date.getMinutes()
  const second = date.getSeconds()

  return `${[year, month, day].map(formatNumber).join('/')} ${[hour, minute, second].map(formatNumber).join(':')}`
}

const formatNumber = n => {
  n = n.toString()
  return n[1] ? n : `0${n}`
}

/**
 * 微信的的request
 */
function request(url, data = {}, method = "POST", header = "application/x-www-form-urlencoded",origin=0) {
  if(!origin){
    var token = wx.getStorageSync('token');
  }else{
    token='';
  }
  wx.showLoading({
      title: '加载中...',
  });
  return new Promise(function (resolve, reject) {
      wx.request({
          url: url,
          data: data,
          method: method,
          header: {
              'Content-Type': header,
              'token': token,
              'cookie': 'XDEBUG_SESSION=XIAONIU'
          },
          success: function (res) {
              wx.hideLoading();
              if (res.statusCode == 200) {
                  resolve(res.data);
              } else {
                  reject(res.msg);
              }

          },
          fail: function (err) {
              reject(err)
          }
      })
  });
}
const SplitArray= function (list, sp) {
  if (typeof list != 'object') return [];
  if (sp === undefined) sp = [];
  for (var i = 0; i < list.length; i++) {
    sp.push(list[i]);
  }
  return sp;
}
// 获取当前页面实例
function getContext() {
  const pages = getCurrentPages();
  return pages[pages.length - 1];
}
// 控制弹窗显隐方法
function BadgePopup() {
  const options = {
    show: true,
    dom: '.LoginPopup'
  };
  const page = getContext();
  const c= page .selectComponent(options.dom);
  if (!c) {
    console.warn(`未找到 ${options.dom} 节点，请确认 dom 是否正确`);
    return;
  }
  c.setData(options);
}
// 刷新当前页面
function refreshPage() {
  // getContext() 和第四步使用的同一个方法
  const perpage = getContext()

  const keyList = Object.keys(perpage.options)
  if (keyList.length > 0) {//页面携带参数
    let keys = '?'
    keyList.forEach((item, index) => {
      index === 0 ? keys = keys + item + '=' + perpage.options[item] : keys + '&' + item + '=' + perpage.options[keys]
    })
    wx.reLaunch({
      url: '/' + perpage.route + keys
    })
  } else {
    //页面没有携带参数
    perpage.onLoad()
    perpage.onShow()
    // wx.reLaunch({
    //   url: '/' + perpage.route
    // })
    // 也可以使用wx.reLaunch
  }
}
//校检
function isPhone(value) {
  let reg_phone = /^(\+)?(0|86|17951)?1(3\d|4[579]|5\d|6\d|7\d|8\d|9\d)\d{8}$/;
  if (reg_phone.test(value)) {
    return true;
  } else {
    return false;
  }
}

//验证码六位数校验
function isSixNum(value) {
  if (!/^\d{6}$/.test(value)) {
    return false
  } else {
    return true
  }
}

//身份证号不严格校验
function isCard(value) {
  if (!/(^\d{15}$)|(^\d{18}$)|(^\d{17}(\d|X|x)$)/.test(value)) {
    return false
  } else {
    return true
  }
}

//身份证号严格校验
function IdentityIDCard (code) {
  //身份证号前两位代表区域
  var city = {
    11: "北京", 12: "天津", 13: "河北", 14: "山西", 15: "内蒙古",
    21: "辽宁", 22: "吉林", 23: "黑龙江 ",
    31: "上海", 32: "江苏", 33: "浙江", 34: "安徽", 35: "福建", 36: "江西", 37: "山东",
    41: "河南", 42: "湖北 ", 43: "湖南", 44: "广东", 45: "广西", 46: "海南",
    50: "重庆", 51: "四川", 52: "贵州", 53: "云南", 54: "西藏 ",
    61: "陕西", 62: "甘肃", 63: "青海", 64: "宁夏", 65: "新疆",
    71: "台湾",
    81: "香港", 82: "澳门",
    91: "国外 "
  };
  //身份证格式正则表达式
  var idCardReg = /^\d{6}(18|19|20)?\d{2}(0[1-9]|1[012])(0[1-9]|[12]\d|3[01])\d{3}(\d|X)$/i;
  var errorMess = "";//错误提示信息
  var isPass = true;//身份证验证是否通过（true通过、false未通过）

  //如果身份证不满足格式正则表达式
  if (!code || !idCardReg.test(code)) {
    errorMess = "您输入的身份证号格式有误！";
    isPass = false;
  }

  //区域数组中不包含需验证的身份证前两位
  else if (!city[code.substr(0, 2)]) {
    errorMess = "您输入的身份证地址编码有误！";
    isPass = false;
  }
  else {
    //18位身份证需要验证最后一位校验位
    if (code.length == 18) {
      code = code.split('');
      //∑(ai×Wi)(mod 11)
      //加权因子
      var factor = [7, 9, 10, 5, 8, 4, 2, 1, 6, 3, 7, 9, 10, 5, 8, 4, 2];
      //校验位
      var parity = [1, 0, 'X', 9, 8, 7, 6, 5, 4, 3, 2];
      var sum = 0;
      var ai = 0;
      var wi = 0;
      for (var i = 0; i < 17; i++) {
        ai = code[i];
        wi = factor[i];
        sum += ai * wi;
      }
      var last = parity[sum % 11];
      if (parity[sum % 11] != code[17]) {
        errorMess = "您输入的身份证号不存在！";
        isPass = false;
      }
    }
  }
  var returnParam = {
    'errorMess': errorMess,
    'isPass': isPass
  }
  return returnParam;
}
//上传图片
function UploadFileV2(tempFilePath){
  var expireHours = arguments.length > 2 && arguments[2] !== undefined ? arguments[2] : '';

  return new Promise(function (resolve, reject) {
    wx.uploadFile({
      url: 'https://waytop.sdjiantan.com/api/common/upload',
      filePath: tempFilePath,
      name: 'file',
      success: function success(res) {
        resolve(JSON.parse(res.data));
      },
      fail: function fail(error) {
        reject(error);
      },
      complete: function complete(aaa) {
        // 加载完成
      }
    });
  });
}
/** 获取上传文件扩展名 */
function getFilePathExtention(filePath) {
  return filePath.split('.').slice(-1)[0];
}

/** 上传到阿里云oss */
function uploadFileAsync(config, filePath) {
  return new Promise((resolve, reject) => {
      wx.uploadFile({
          url: config.host, // 开发者服务器的URL。
          filePath: filePath,
          name: 'file', // 必须填file。
          formData: {
              key: config.key,
              policy: config.policy,
              OSSAccessKeyId: config.accessKeyId,
              signature: config.signature,
          },
          success: (res) => {
            console.log(res);
            resolve(res)
          },
          fail: (err) => {
              console.log(err);
          },
      });
  });
}
async function uploadFile(filePath, dirname = 'image') {
  let ext = getFilePathExtention(filePath);
  // 改方法通过接口获取服务端生成的上传签名 
  const resParams = await request('https://erp.sdjiantan.com/api/alioss/getUploadParams',{
      ext,
      dirname,
  });
  
  await uploadFileAsync(resParams.data, filePath);
  console.log(resParams.data)
  return resParams;
}
function getType(url) {
  var ext = url.split('.').pop().split(/[?#]/)[0].toLowerCase();
  if (/(jpg|jpeg|png|gif)/.test(ext)) return 'image';
  if (/(mp4|mov|avi|mkv|webm)/.test(ext)) return 'video';
  return 'unknown';
}
/**
 * 获取cdn裁剪后链接
 *
 * @param {string} url 基础链接
 * @param {number} width 宽度，单位px
 * @param {number} [height] 可选，高度，不填时与width同值
 */
const cosThumb = (url, width, height = width) => {
  if (url.indexOf('?') > -1) {
    return url;
  }

  if (url.indexOf('http://') === 0) {
    url = url.replace('http://', 'https://');
  }

  return `${url}?imageMogr2/thumbnail/${~~width}x${~~height}`;
};
module.exports = {
  formatTime,
  request,
  refreshPage,
  BadgePopup,
  isPhone,
  isSixNum,
  isCard,
  IdentityIDCard,
  UploadFileV2,
  uploadFile,
  cosThumb,
  SplitArray,
  getType
}
