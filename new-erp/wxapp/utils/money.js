// 数据库以十进制字符串返回金额，直接按第三位进位，避免浮点数在分位边界舍错。
function moneyText(value) {
  if (typeof value === 'number' && Math.abs(value) < 0.005) return '0.00';
  const match = /^([+-]?)(\d+)(?:\.(\d*))?$/.exec(String(value == null ? '' : value).trim());
  if (!match) return '';
  const fraction = (match[3] || '').padEnd(3, '0');
  const cents = (match[2].replace(/^0+(?=\d)/, '') + fraction.slice(0, 2)).split('');
  if (fraction[2] >= '5') {
    let index = cents.length - 1;
    while (index >= 0 && cents[index] === '9') cents[index--] = '0';
    if (index < 0) cents.unshift('1');
    else cents[index] = String(Number(cents[index]) + 1);
  }
  const digits = cents.join('');
  const sign = match[1] === '-' && /[1-9]/.test(digits) ? '-' : '';
  return sign + digits.slice(0, -2) + '.' + digits.slice(-2);
}

module.exports = moneyText;
