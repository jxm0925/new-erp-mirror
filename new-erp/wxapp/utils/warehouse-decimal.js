// Display totals use decimal digits, not IEEE floating point or an engine-specific BigInt.
// The server remains authoritative for every inventory quantity and amount.
function addDigits(left, right) {
  let i = left.length - 1; let j = right.length - 1; let carry = 0; let result = '';
  while (i >= 0 || j >= 0 || carry) {
    const value = Number(i >= 0 ? left[i--] : 0) + Number(j >= 0 ? right[j--] : 0) + carry;
    result = String(value % 10) + result; carry = Math.floor(value / 10);
  }
  return result.replace(/^0+(?=\d)/, '');
}
function sumAmounts(values) {
  let digits = '0';
  for (const value of values) {
    const text = String(value == null ? '' : value);
    if (!/^\d+(\.\d{1,4})?$/.test(text)) return null;
    const parts = text.split('.'); digits = addDigits(digits, parts[0] + (parts[1] || '').padEnd(4, '0'));
  }
  digits = digits.padStart(5, '0'); return `${digits.slice(0, -4)}.${digits.slice(-4)}`;
}
module.exports = { sumAmounts };
