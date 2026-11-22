import Vue from 'vue'

export function aiBlank(value) {
  return value == null || (typeof value === 'string' && value.trim() === '')
}

export function fillAiBlanks(target, suggestions) {
  let count = 0
  for (const [field, value] of Object.entries(suggestions || {})) {
    if (aiBlank(target[field]) && !aiBlank(value)) {
      Vue.set(target, field, value)
      count++
    }
  }
  return count
}

// Sales V2 uses the same choices in the progress table and the next-day plan.
export const pools = ["本周推进池", "今日成交池", "新线索4小时快转池", "补位客户池", "老客户唤醒池", "净水空间站", "西北B端", "经销商/渠道", "售后转机会", "其他"]
export const probabilities = ["10%｜刚建立联系", "30%｜需求初步明确", "50%｜方案匹配中", "70%｜主要异议已识别", "80%｜明确意向", "85%｜已要求承诺", "90%｜成交条件基本具备", "95%｜待付款/签约"]
export const steps = ["第1步｜营销自己", "第2步｜发现需求", "第3步｜确认需求", "第4步｜产品方案", "第5步｜营销产品", "第6步｜达成共识", "第7步｜要求承诺", "第8步｜确认成交", "第9步｜赢销企业"]
export const actions = ["电话", "微信", "视频沟通", "上门/到店", "报价", "出方案", "发案例/证明", "样品/试用", "邀约参观", "售后回访", "内部协同", "其他"]
export const blockers = ["客户未回复", "需求不清", "关键人未接触", "预算/价格", "产品/方案匹配", "安装/技术条件", "交期/库存", "决策人不同意", "竞品/已有供应商", "不愿承诺/不交定金", "暂无真实需求", "内部支持未到位", "其他"]
export const reasons = ["客户池结构不足", "关键问题没问透", "动作没推进到下一步", "沟通价值不足", "方案/报价不匹配", "没有拿到客户承诺", "跟进节奏不对", "内部协同延迟", "时间被非销售事务占用", "客观条件暂不具备", "能力或话术不熟练", "其他"]
export const priorities = ["P1｜明天必须推进", "P2｜重要推进", "P3｜正常跟进"]
export const statuses = ["待执行", "执行中", "已完成", "有风险", "取消（说明原因）"]

const field = (key, label, extra = {}) => ({ key, label, ...extra })

export const progressFields = [
  field('customer_name', '客户名称', { fixed: true }), field('pool', '客户池', { options: pools }),
  field('priority', '重点定位', { options: ['primary', 'backup', 'review'], labels: { primary: '主攻', backup: '补位', review: '仅复盘' } }),
  field('expected_amount', '预计金额', { money: true }), field('stage_label', '当前阶段'),
  field('probability', '成交概率', { options: probabilities }), field('nine_step', '当前九步阶段', { options: steps }),
  field('action_type', '动作类型', { options: actions }), field('today_action', '今日具体动作'),
  field('today_result', '今日推进结果'), field('promise', '客户承诺 / 证据'),
  field('max_blocker', '当前最大卡点', { options: blockers }), field('reason', '原因分析', { options: reasons }),
  field('next_step', '明日目标九步阶段', { options: steps }), field('tmr_must_action', '明日最小下一动作'),
  field('deadline', '截止时间'), field('contact_time', '明日联系时间')
]

export const planFields = [
  field('customer_name', '客户名称', { fixed: true }), field('plan_priority', '优先级', { options: priorities, edit: true }),
  field('pool', '客户池'), field('expected_amount', '预计金额', { money: true }), field('probability', '成交概率'),
  field('nine_step', '当前阶段'), field('next_step', '明日目标阶段'), field('contact_time', '联系时间'),
  field('action_type', '动作类型'), field('tmr_must_action', '具体动作'), field('tmr_advance_to', '预期结果', { edit: true }),
  field('anticipated_blocker', '预判卡点', { options: blockers, edit: true }), field('response_action', '应对动作', { edit: true }),
  field('materials', '所需资料', { edit: true }), field('expected_pay_time', '预计打款时间', { edit: true }),
  field('status', '完成状态', { options: statuses, edit: true }), field('remark', '备注', { edit: true })
]

export const reviewFields = [
  field('today_goal', '今日目标'), field('facts', '关键事实'), field('deviation', '主要偏差'),
  field('reason_category', '原因分类', { options: reasons }), field('best_action', '最有效动作'),
  field('worst_action', '最无效动作'), field('q1', '第一问题'), field('adjust', '明日改进动作'), field('support_result', '支持预期结果')
]

export const conclusions = [
  field('month_judgment', '本月目标判断'), field('breakpoint', '今天最大业绩断点'), field('best_practice', '今天最值得复制'),
  field('manager_action', '主管今天处理'), field('opportunities', '今日新增成交机会'), field('breakthrough', '进入48小时突破池'),
  field('highest_amount', '预计成交金额最高', { sensitive: true }), field('help_blockers', '需主管帮助的卡点'), field('tomorrow_result', '明日必须产生结果')
]

export const coachingFields = [
  field('priority', '优先级', { fixed: true }), field('sales_id', '销售'), field('customer', '客户'),
  field('expected_amount', '预计金额', { money: true }), field('fact', '问题事实'), field('goal', '今天要拿到的客户结果'),
  field('seller_action', '员工具体动作'), field('support', '主管辅导 / 公司支持'), field('deadline', '截止时间'),
  field('acceptance', '验收标准'), field('result', '实际结果 / 是否前进'), field('status', '状态', { options: ['待执行', '已完成', '有风险'] })
]

export function tableValues(columns, rows, showMoney) {
  return [columns.map(c => c.label), ...rows.map(row => columns.map(c => {
    if (!showMoney && (c.money || c.sensitive)) return '未提供'
    const value = row[c.key]
    return c.labels?.[value] || value
  }))]
}
