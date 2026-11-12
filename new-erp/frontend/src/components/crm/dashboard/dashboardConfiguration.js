export const dashboardPages = [
  { key: 'overview', label: '经营总览', icon: 'el-icon-data-analysis' },
  { key: 'sales', label: '销售分析', icon: 'el-icon-user' },
  { key: 'dimensions', label: '行业与客户', icon: 'el-icon-collection' },
  { key: 'pipeline', label: '商机进度', icon: 'el-icon-s-operation' },
  { key: 'sources', label: '来源与跟进', icon: 'el-icon-phone-outline' },
  { key: 'income', label: '收入统计', icon: 'el-icon-coin' }
]

const metrics = {
  customers: ['客户总数', '当前归属的去重客户数'],
  new_customers: ['本期新增客户', '所选期间新增的去重客户数'],
  first_contacts: ['近7天首次联系', '今天及之前6个自然日的首次联系'],
  open_opportunities: ['进行中商机', '当前进行中商机数'],
  expected: ['待成交金额', '当前进行中商机的同币种预计金额'],
  deal_amount: ['成交金额', '所选下单期间有效订单的当前成交金额'],
  received_amount: ['已收金额', '同批有效订单的当前已收金额'],
  outstanding_amount: ['尾款金额', '同批有效订单的当前尾款金额'],
  active: ['当前进行中', '当前进行中商机数'],
  won: ['本期赢单', '所选期间实际赢单数'],
  lost: ['本期丢单', '所选期间实际丢单数'],
  due: ['本期应跟进', '所选期间计划跟进数'],
  completed: ['已完成', '所选期间实际完成跟进数'],
  overdue: ['逾期', '当前未完成的逾期跟进数']
}
const metricPages = {
  overview: ['customers', 'new_customers', 'first_contacts', 'open_opportunities', 'expected', 'deal_amount'],
  sales: ['customers', 'new_customers', 'first_contacts', 'open_opportunities', 'expected', 'deal_amount', 'received_amount', 'outstanding_amount'],
  pipeline: ['active', 'expected', 'won', 'lost'],
  sources: ['due', 'completed', 'overdue', 'first_contacts'],
  income: ['deal_amount', 'received_amount', 'outstanding_amount']
}
const panels = [
  ['overview_sales', '销售经营对比', 'overview', 'half', '柱状图', '成交金额、待成交金额', '原订单销售 / 当前商机销售', '所选下单期间 / 当前状态'],
  ['overview_stages', '当前商机阶段', 'overview', 'half', '柱状图', '进行中商机数', '当前商机阶段', '当前状态'],
  ['overview_industry', '客户行业分布', 'overview', 'third', '柱状图', '去重客户数', '客户行业', '当前归属'],
  ['overview_purchase', '客户年采购力分布', 'overview', 'third', '柱状图', '去重客户数', '年采购力', '当前归属'],
  ['overview_followups', '跟进提醒', 'overview', 'third', '指标与名单', '应跟进、已完成、逾期', '跟进状态', '所选期间 / 当前逾期'],
  ['sales_table', '销售经营明细', 'sales', 'full', '明细表', '客户、商机及订单金额', '销售人员', '当前归属 / 所选下单期间'],
  ['sales_funnels', '销售个人推进漏斗', 'sales', 'full', '漏斗图', '同批线索有据可查的阶段到达数', '销售人员、线索阶段', '同批录入期间与观察日'],
  ['dimension_distribution', '客户维度分布', 'dimensions', 'half', '柱状图', '去重客户数', '客户分析维度', '当前归属'],
  ['dimension_cross', '销售 × 客户维度', 'dimensions', 'half', '交叉表', '去重客户数', '销售人员 × 客户分析维度', '当前归属'],
  ['dimension_table', '客户维度经营对比', 'dimensions', 'full', '明细表', '去重客户、进行中商机及订单金额', '客户分析维度', '当前归属 / 所选下单期间'],
  ['pipeline_stages', '当前阶段分布', 'pipeline', 'half', '柱状图', '进行中商机数 / 同币种预计金额', '当前商机阶段', '当前状态'],
  ['pipeline_funnel', '同批线索推进漏斗', 'pipeline', 'half', '漏斗图', '同批线索有据可查的阶段到达数', '线索阶段', '同批录入期间与观察日'],
  ['pipeline_records', '商机推进明细', 'pipeline', 'full', '分页名单', '进行中商机', '超期、停留、预计成交日', '当前状态'],
  ['source_distribution', '客户来源分布', 'sources', 'half', '柱状图', '去重客户数', '客户首次来源 / 本次咨询来源 / 录入方式', '当前归属'],
  ['source_followups', '销售跟进计划与完成', 'sources', 'half', '柱状图', '应跟进数、已完成数', '销售人员', '所选计划 / 实际完成期间'],
  ['source_records', '跟进任务明细', 'sources', 'full', '分页名单', '需处理的跟进任务', '任务状态', '当前状态'],
  ['income_chart', '各销售成交、已收与尾款', 'income', 'half', '柱状图', '成交、已收、尾款金额', '订单原销售', '所选下单期间的当前事实'],
  ['income_table', '销售金额汇总', 'income', 'half', '汇总表', '成交、已收、尾款金额', '订单原销售', '所选下单期间的当前事实'],
  ['income_orders', '订单明细', 'income', 'full', '分页名单', '成交、已收、尾款金额', '订单原销售、尾款状态', '所选下单期间的当前事实']
]

export const dashboardComponentCatalog = [
  ...Object.entries(metricPages).flatMap(([position, keys]) => keys.map((metric, index) => ({
    key: `${position}_${metric}`, metric, title: position === 'overview' && metric === 'deal_amount' ? '本期成交金额' : metrics[metric][0],
    category: position, position, width: 'metric', order: index, chart: '指标卡', basis: metrics[metric][1], dimension: '固定统计口径', date: metrics[metric][1]
  }))),
  ...panels.map(([key, title, position, width, chart, basis, dimension, date], index) => ({key, title, category: position, position, width, order: 100 + index, chart, basis, dimension, date}))
]

export const defaultDashboardComponents = () => dashboardComponentCatalog.map(({key, title, position, width, order}) => ({key, title, position, width, order, enabled: true, drilldown: true}))

export function normalizeDashboardComponents(components) {
  if (!Array.isArray(components)) return defaultDashboardComponents()
  return dashboardComponentCatalog.map(definition => {
    const saved = components.find(item => item.key === definition.key)
    return saved ? {...definition, ...saved, width: definition.metric ? 'metric' : ['full','half','third'].includes(saved.width) ? saved.width : definition.width} : {...definition, enabled: false, drilldown: true}
  }).map(({key, title, position, width, order, enabled = true, drilldown = true}) => ({key, title, position, width, order, enabled, drilldown}))
}
