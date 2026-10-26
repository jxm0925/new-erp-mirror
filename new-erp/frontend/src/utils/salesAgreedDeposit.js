// 销售按客户约定修改开工定金：留空恢复付款条件默认值；草稿与已确认订单均立即生效并留痕。
import { updateSalesOrderAgreedDeposit } from '@/api/erp/sales'

export async function promptAgreedDeposit (vm, orderId, funding) {
  const current = funding && funding.production_required_amount !== undefined ? Number(funding.production_required_amount) : ''
  const policyDefault = funding && funding.policy_deposit_amount !== undefined && funding.policy_deposit_amount !== null
    ? `付款条件默认 ¥${Number(funding.policy_deposit_amount).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}，` : ''
  const { value } = await vm.$prompt(`填写与客户约定的开工定金（元）。${policyDefault}留空则恢复付款条件默认值。`, '修改约定定金', {
    inputValue: current === '' ? '' : String(current),
    confirmButtonText: '保存',
    cancelButtonText: '取消',
    inputValidator: input => {
      const text = String(input === undefined || input === null ? '' : input).trim()
      if (text === '') return true
      return (/^\d+(\.\d{1,2})?$/.test(text)) || '请输入不小于 0 的金额，最多两位小数'
    }
  })
  const text = String(value === undefined || value === null ? '' : value).trim()
  const { data } = await updateSalesOrderAgreedDeposit(orderId, { agreed_deposit_amount: text === '' ? null : Number(text) })
  vm.$message.success(data.message || '约定定金已更新')
  return data.data
}
