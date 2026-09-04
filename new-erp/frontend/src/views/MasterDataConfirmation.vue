<template>
  <section class="confirmation-page">
    <div class="confirmation-head">
      <div>
        <div class="eyebrow">PHASE 1 · MODEL FREEZE GATE</div>
        <h1>主数据业务确认工作台</h1>
        <p>先解决 Product / SKU / Item 的业务边界，再申请模型冻结。</p>
      </div>
      <div class="gate-state"><i class="el-icon-lock" /><span>冻结状态</span><strong>已阻塞</strong></div>
    </div>

    <div class="gate-strip">
      <div><span>P0 问题</span><strong>{{ gateQuestions.length }}</strong></div>
      <div><span>已解决</span><strong class="success">{{ resolvedCount }}</strong></div>
      <div><span>确认中</span><strong class="warning">{{ checkingCount }}</strong></div>
      <div><span>未解决</span><strong class="danger">{{ openCount }}</strong></div>
      <div class="gate-message"><i class="el-icon-warning-outline" /> 7 个门禁问题未全部解决，不允许创建正式业务表或开放主数据编辑。</div>
    </div>

    <div class="prototype-nav">
      <button v-for="item in pages" :key="item" :class="{active: activePage === item}" @click="activePage = item">{{ item }}</button>
    </div>

    <div class="decision-layout">
      <aside class="issue-list">
        <div class="panel-title"><strong>门禁问题</strong><span>{{ resolvedCount }}/{{ gateQuestions.length }}</span></div>
        <button v-for="row in gateQuestions" :key="row.question_no" :class="{active: selected && selected.question_no === row.question_no}" @click="select(row)">
          <span :class="['issue-status', row.status]">{{ row.status === 'resolved' ? '✓' : row.status === 'checking' ? '…' : '!' }}</span>
          <div><strong>{{ row.question_no }} · {{ shortCategory(row.category) }}</strong><p>{{ row.question }}</p></div>
          <i class="el-icon-arrow-right" />
        </button>
      </aside>

      <main v-if="selected" class="decision-main">
        <div class="issue-heading">
          <div><span>{{ selected.question_no }} · {{ selected.impact_stage }}</span><h2>{{ selected.question }}</h2></div>
          <el-tag :type="selected.status === 'resolved' ? 'success' : selected.status === 'checking' ? 'warning' : 'danger'" size="small">{{ selected.status }}</el-tag>
        </div>

        <div class="evidence-box">
          <div class="section-label"><i class="el-icon-data-line" /> 当前技术审计事实</div>
          <p>{{ factFor(selected.question_no) }}</p>
          <button class="text-action">查看原库证据 <i class="el-icon-right" /></button>
        </div>

        <div class="section-label compare-label"><i class="el-icon-files" /> 方案比较</div>
        <div class="option-grid">
          <button v-for="option in optionsFor(selected.question_no)" :key="option.key" :class="{selected: draftChoice === option.key, recommended: option.recommended}" @click="draftChoice = option.key">
            <div><span>方案 {{ option.key }}</span><em v-if="option.recommended">推荐</em></div>
            <strong>{{ option.title }}</strong>
            <p>{{ option.description }}</p>
            <ul><li class="pro">{{ option.pro }}</li><li class="con">{{ option.con }}</li></ul>
          </button>
        </div>

        <div class="candidate-preview">
          <div class="section-label"><i class="el-icon-view" /> 候选结果预览</div>
          <el-table :data="previewRows" size="mini">
            <el-table-column prop="legacy" label="旧对象" min-width="150" />
            <el-table-column prop="references" label="引用证据" min-width="180" />
            <el-table-column prop="recommendation" label="推荐结果" min-width="160" />
            <el-table-column prop="confidence" label="置信度" width="80" />
            <el-table-column prop="status" label="状态" width="90" />
          </el-table>
        </div>
      </main>

      <aside v-if="selected" class="impact-panel">
        <div class="panel-title"><strong>确认影响</strong><i class="el-icon-document-checked" /></div>
        <section><span>建议确认人</span><strong>{{ selected.owner }}</strong></section>
        <section><span>影响数据模型</span><div class="token-list"><em v-for="v in impacts.tables" :key="v">{{ v }}</em></div></section>
        <section><span>影响页面</span><p>{{ impacts.pages }}</p></section>
        <section><span>影响迁移</span><p>{{ impacts.migration }}</p></section>
        <section class="permission"><span>当前允许</span><p>只读分析、候选映射、异常预检、页面原型</p></section>
        <section class="forbidden"><span>当前禁止</span><p>冻结模型、建正式表、正式迁移、开放编辑</p></section>
        <el-button type="primary" size="small" :disabled="!draftChoice" @click="showConfirm = true">预览确认记录</el-button>
        <el-button size="small">导出会议材料</el-button>
        <small>原型不会直接修改问题文档。</small>
      </aside>
    </div>

    <el-dialog title="确认记录预览" :visible.sync="showConfirm" width="560px">
      <el-alert title="当前仅为页面原型，不会写入 phase0_open_questions.md。" type="warning" :closable="false" show-icon />
      <el-form label-width="90px">
        <el-form-item label="选择方案"><el-input :value="`方案 ${draftChoice}`" disabled /></el-form-item>
        <el-form-item label="确认结论"><el-input type="textarea" :rows="3" placeholder="说明选择理由和适用范围" /></el-form-item>
        <el-form-item label="确认人"><el-input placeholder="姓名 / 角色" /></el-form-item>
        <el-form-item label="证据"><el-input placeholder="会议纪要或制度文件" /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="showConfirm=false">关闭</el-button><el-button type="primary" disabled>正式回写（门禁未开放）</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { getQuestions } from '../api/scan'

const gateIds = ['Q-003', 'Q-011', 'Q-012', 'Q-015', 'Q-017', 'Q-034', 'Q-061']
export default {
  data() {
    return {
      questions: [], selected: null, draftChoice: '', showConfirm: false, activePage: '门禁总览',
      pages: ['门禁总览', '三层拆分', '标准/定制', 'BOM完整性', 'SKU→Item', '编码规则', '供应商主档', '角色权限', '映射预览', '异常清单'],
      previewRows: [
        { legacy: '商品 3 / SKU 184', references: '订单 126 · 工单 98 · BOM 1', recommendation: 'Product + SKU + 产成品Item', confidence: '高', status: '待确认' },
        { legacy: '商品 66 / SKU 4309', references: '订单 61 · 工单 48', recommendation: '服务SKU，不生成Item', confidence: '中', status: '异常' },
        { legacy: '商品 34 / SKU 4829', references: '订单 2 · 工单 2 · SKU已删除', recommendation: '进入异常工作台', confidence: '低', status: '阻塞' }
      ]
    }
  },
  computed: {
    gateQuestions() { return gateIds.map(id => this.questions.find(q => q.question_no === id)).filter(Boolean) },
    resolvedCount() { return this.gateQuestions.filter(q => q.status === 'resolved').length },
    checkingCount() { return this.gateQuestions.filter(q => q.status === 'checking').length },
    openCount() { return this.gateQuestions.length - this.resolvedCount - this.checkingCount },
    impacts() {
      const id = this.selected ? this.selected.question_no : ''
      if (id === 'Q-061') return { tables: ['roles', 'permissions', 'approval_tasks'], pages: '全部主数据页面、审批待办、操作日志', migration: '历史创建人映射与迁移账号规则' }
      if (id === 'Q-034') return { tables: ['suppliers', 'supplier_aliases'], pages: '供应商、合并确认、采购历史', migration: '两套供应商来源归并后写入统一供应商主档' }
      return { tables: ['products', 'skus', 'items', 'sku_item_relations'], pages: '商品、SKU、物料、关系维护、导入预检', migration: '旧商品/SKU 到 Product/SKU/Item 的多角色映射' }
    }
  },
  async created() {
    const data = await getQuestions()
    this.questions = data.rows || []
    this.selected = this.gateQuestions[0] || null
  },
  methods: {
    select(row) { this.selected = row; this.draftChoice = '' },
    shortCategory(value) { return value.replace('商品 / SKU / 物料', '三层主数据').replace('权限 / 页面', '角色权限') },
    factFor(id) {
      const facts = {
        'Q-003': '旧库包含 77 个商品和 1,631 个 SKU；订单与工单引用 SKU，BOM 主表及明细也引用旧 SKU，但新 BOM 必须落到 Item。',
        'Q-011': 'SKU type 绝大多数为空；工单存在 customized 与 special_customized，必须结合历史履约判断。',
        'Q-012': '旧库有 25 个组装成品、21 个 BOM；BOM 不覆盖全部组装成品，且没有版本字段。',
        'Q-015': '订单、工单、BOM、采购、库存对 SKU 的角色不同；服务、运费和差价不应污染 Item。',
        'Q-017': '非空 SKU 编码无重复，但有 100 条空编码；迁移幂等不能只依赖新编码。',
        'Q-034': 'product_suppliers 29 行、stock_supplier 11 行；两表无同编码但 11 个同名，采购全部引用前者。',
        'Q-061': '旧 FastAdmin 权限节点不能直接代表新 ERP 职责分离；编码、合并和禁用属于高风险操作。'
      }
      return facts[id] || '请查看审计文档。'
    },
    optionsFor(id) {
      if (id === 'Q-061') return [
        { key: 'A', title: '单一管理员', description: '一人维护全部主数据。', pro: '配置简单', con: '权限过大' },
        { key: 'B', title: '按领域分权', description: '产品、供应链、采购、仓库分权，关键变更审核。', pro: '职责清晰', con: '配置较多', recommended: true },
        { key: 'C', title: '业务人员直改', description: '相关负责人均可直接修改。', pro: '灵活', con: '冲突与审计风险高' }
      ]
      return [
        { key: 'A', title: '一 SKU 一 Item', description: '所有销售 SKU 强制创建 Item。', pro: '迁移规则简单', con: '服务和虚拟项污染物料库' },
        { key: 'B', title: '按业务角色生成', description: '参与采购、库存、BOM、生产或成本的 SKU 才生成 Item。', pro: '业务边界清晰', con: '需要处理边界记录', recommended: true },
        { key: 'C', title: '全部人工维护', description: 'SKU 先迁移，Item 后续逐条建立。', pro: '最保守', con: '后续流程无法承接' }
      ]
    }
  }
}
</script>
