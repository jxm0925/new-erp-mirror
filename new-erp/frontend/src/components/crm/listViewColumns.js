export const customerColumnGroups = [
  { key: 'identity', label: '客户名称 · 背景', fields: ['customer_name', 'customer_code', 'company_background'] },
  { key: 'profile', label: '类型 · 等级 · 行业', fields: ['customer_kind', 'customer_grade', 'industry'] },
  { key: 'business', label: '年采购力 · 业务线', fields: ['purchasing_power', 'business_lines'] },
  { key: 'contact', label: '默认联系人', fields: ['default_contact'] },
  { key: 'owner', label: '负责销售', fields: ['owner_name'] },
  { key: 'open_opportunity_count', label: '进行中商机', fields: ['open_opportunity_count'] },
  { key: 'expected_amounts', label: '待成交金额', fields: ['expected_amounts'] },
  { key: 'deal', label: '成交金额', fields: ['deal_amount'] },
  { key: 'pools', label: '线索所在客户池', fields: ['customer_pools'] },
  { key: 'schedule', label: '最近联系 · 下次计划', fields: ['last_contact_at', 'next_contact_at'] },
  { key: 'status', label: '状态', fields: ['status'] },
  { key: 'region', label: '地区', fields: ['region'] },
  { key: 'credit_code', label: '统一社会信用代码', fields: ['credit_code'] },
  { key: 'first_source', label: '首次来源', fields: ['first_source'] },
  { key: 'customer_tags', label: '客户标签', fields: ['customer_tags'] },
  { key: 'remark', label: '备注', fields: ['remark'] }
]

export const leadColumnGroups = [
  { key: 'identity', label: '客户 / 线索编号', fields: ['customer_name', 'lead_no', 'customer_kind'] },
  { key: 'contact', label: '联系人 / 地区', fields: ['contact_name', 'mobile', 'region'] },
  { key: 'demand', label: '需求摘要 / 业务', fields: ['demand_summary', 'expected_amount', 'trade_type', 'business_lines'] },
  { key: 'source', label: '来源渠道 / 录入', fields: ['source_channel', 'entry_method', 'platform_name'] },
  { key: 'owner', label: '负责人 / 部门', fields: ['owner_name', 'department_name'] },
  { key: 'state', label: '状态 / 客户池', fields: ['status', 'customer_pool'] },
  { key: 'schedule', label: '最近联系 / 下次计划', fields: ['last_contact_at', 'next_contact_at', 'next_plan'] },
  { key: 'recycle', label: '回收时间 / 原因', fields: ['recycle_reason', 'recycled_at'] },
  { key: 'recycle_owner', label: '原负责人 / 回收人', fields: ['previous_owner_name', 'recycled_by_name'] }
]

export const opportunityColumnGroups = [
  { key: 'customer_name', label: '客户', fields: ['customer_name'] },
  { key: 'name', label: '商机名称', fields: ['name'] },
  { key: 'business_line', label: '业务线', fields: ['business_line'] },
  { key: 'owner_name', label: '负责人', fields: ['owner_name'] },
  { key: 'stage', label: '阶段', fields: ['stage'] },
  { key: 'expected_amount', label: '预计金额', fields: ['expected_amount'] },
  { key: 'expected_close_date', label: '预计成交日期', fields: ['expected_close_date'] },
  { key: 'last_contact_at', label: '最近跟进', fields: ['last_contact_at'] },
  { key: 'next_contact_at', label: '下次跟进', fields: ['next_contact_at'] }
]

export const orderedColumnGroups = (columns, groups) => {
  const order = []
  ;(columns || []).forEach(field => {
    const group = groups.find(item => item.fields.includes(field))
    if (group && !order.some(item => item.key === group.key)) order.push(group)
  })
  return order
}

export const listViewQuery = configuration => ({
  sort_field: (configuration.sort || {}).field || '',
  sort_direction: (configuration.sort || {}).direction || 'desc',
  group_by: configuration.group_by || ''
})

export function listViewGroupSpan({ rowIndex, column }) {
  if (column.property !== '__view_group') return [1, 1]
  // 展开行额外占一行，存在展开时保留逐行组名，避免合并单元格覆盖关联记录。
  if (this.expandedCustomerRows && this.expandedCustomerRows.length) return [1, 1]
  const value = this.groupValue(this.rows[rowIndex])
  if (rowIndex > 0 && this.groupValue(this.rows[rowIndex - 1]) === value) return [0, 0]
  let count = 1
  while (rowIndex + count < this.rows.length && this.groupValue(this.rows[rowIndex + count]) === value) count += 1
  return [count, 1]
}
