<template>
  <section class="detail-page" v-loading="loading">
    <header>
      <div class="crumb">主数据中心　/　SKU–Item默认关系　/　<b>关系详情</b></div>
      <div>
        <el-button @click="back">← 返回列表</el-button>
        <el-button
          v-if="physical && $can(current ? 'sku_item_relation.change' : 'sku_item_relation.set')"
          type="success"
          @click="$router.push(`/master/sku-item-relations/${sku.id}/set-primary`)"
        >⟳ {{ actionLabel }}</el-button>
      </div>
    </header>

    <main v-if="sku">
      <div class="top">
        <section class="info-card">
          <h3>SKU基础信息</h3>
          <p><span>Product</span><b>{{ product }}</b></p>
          <p><span>SKU编码</span><b>{{ sku.sku_code }}</b></p>
          <p><span>SKU名称</span><b>{{ sku.sku_name }}</b></p>
          <p><span>规格型号</span><b>{{ sku.spec_text || '—' }}</b></p>
          <p><span>销售单位</span><b>{{ sku.sales_unit && sku.sales_unit.unit_name || '—' }}</b></p>
          <p><span>订单行类型</span><b>{{ lineType }}</b></p>
        </section>

        <section class="info-card">
          <h3>当前默认Item</h3>
          <template v-if="current">
            <p><span>Item编码</span><b>{{ current.item_code }}</b></p>
            <p><span>Item名称</span><b>{{ current.item_name }}</b></p>
            <p><span>规格型号</span><b>{{ current.spec_text || '—' }}</b></p>
            <p><span>Item状态</span><b :class="['tag', { bad: current.status !== 'enabled' }]">{{ current.status === 'enabled' ? '启用' : '停用' }}</b></p>
            <p><span>关系状态</span><b :class="['tag', { bad: audit.check_status !== 'normal' }]">{{ audit.check_status === 'normal' ? '正常' : '异常' }}</b></p>
            <p><span>生效时间</span><b>{{ currentRelation && currentRelation.effective_at || '—' }}</b></p>
            <p v-if="audit.check_status !== 'normal'"><span>异常原因</span><b class="danger">{{ audit.reason || '关系完整性检查未通过' }}</b></p>
            <el-button v-if="audit.check_status === 'item_disabled' && $can('sku_item_relation.change')" type="success" @click="$router.push(`/master/sku-item-relations/${sku.id}/set-primary`)">⟳ 更换默认Item</el-button>
          </template>
          <p v-else>当前未设置默认Item</p>
        </section>
      </div>

      <!-- 只在重复关系异常时出现，正常状态与已通过基准稿保持不变。 -->
      <section v-if="audit.check_status === 'duplicate'" class="block repair-block">
        <div class="repair-heading">
          <div><h3>关系异常：存在多个默认Item</h3><p>请选择要保留的唯一默认Item，其他关系将失效并写入操作日志。</p></div>
          <el-tag type="warning">待修复</el-tag>
        </div>
        <el-alert title="处理后立即生效；历史关系和历史订单快照不会被删除。" type="warning" :closable="false" show-icon />
        <div class="repair-form">
          <div class="field wide">
            <label>保留默认Item <em>*</em></label>
            <el-select v-model="repairForm.keep_relation_id" placeholder="请选择要保留的关系" filterable>
              <el-option v-for="relation in duplicateRelations" :key="relation.id" :label="relationLabel(relation)" :value="relation.id" />
            </el-select>
          </div>
          <div class="field">
            <label>变更原因 <em>*</em></label>
            <el-select v-model="repairForm.change_reason" placeholder="请选择变更原因">
              <el-option v-for="reason in reasons" :key="reason" :label="reason" :value="reason" />
            </el-select>
          </div>
          <div class="field remark">
            <label>备注</label>
            <el-input v-model="repairForm.remark" maxlength="200" show-word-limit placeholder="请输入处理说明（可选）" />
          </div>
          <div class="repair-actions">
            <el-button :disabled="!$can('sku_item_relation.repair')" type="success" :loading="repairing" @click="resolveDuplicate">保存并立即生效</el-button>
          </div>
        </div>
      </section>

      <section class="block">
        <h3>默认Item关系历史</h3>
        <el-table :data="history.relations" border size="small">
          <el-table-column label="Item编码" prop="item.item_code" />
          <el-table-column label="Item名称" prop="item.item_name" />
          <el-table-column label="规格型号"><template slot-scope="{ row }">{{ row.item && row.item.spec_text || '—' }}</template></el-table-column>
          <el-table-column label="生效时间" prop="effective_at" />
          <el-table-column label="失效时间" prop="expired_at" />
          <el-table-column label="状态"><template slot-scope="{ row }"><span :class="row.status === 'active' ? 'active' : 'inactive'">{{ row.status === 'active' ? '生效中' : '已失效' }}</span></template></el-table-column>
          <el-table-column label="操作人" prop="operator_name" />
          <el-table-column label="变更原因" prop="change_reason" />
        </el-table>
        <p class="tip">ⓘ 说明：历史记录反映每一段默认Item关系在业务上的有效状态；历史销售订单将继续使用下单时保存的Item快照，不受后续关系变更影响。</p>
      </section>

      <section class="block">
        <h3>操作日志</h3>
        <el-table :data="history.logs" border size="small">
          <el-table-column label="操作时间" prop="created_at" />
          <el-table-column label="操作人" prop="operator_name" />
          <el-table-column label="操作类型" prop="action" />
          <el-table-column label="旧Item"><template slot-scope="{ row }">{{ row.old_item && row.old_item.item_code || '—' }}</template></el-table-column>
          <el-table-column label="新Item"><template slot-scope="{ row }">{{ row.new_item && row.new_item.item_code || '—' }}</template></el-table-column>
          <el-table-column label="变更原因" prop="change_reason" />
        </el-table>
      </section>
    </main>
  </section>
</template>

<script>
import { getDefaultSkuItemRelation, getSkuItemRelationHistory, resolveDuplicateSkuItemRelation } from '../../../api/erp/master'

export default {
  name: 'SkuItemRelationDetail',
  data () {
    return {
      loading: false,
      repairing: false,
      sku: null,
      audit: {},
      history: { relations: [], logs: [] },
      repairForm: { keep_relation_id: null, change_reason: '', remark: '' },
      reasons: ['首次设置', '产品升级', '规格调整', '主数据修正', '原Item停用', '历史数据补全', '其他']
    }
  },
  computed: {
    currentRelation () { return (this.audit.relations || []).find(x => x.status === 'active') || (this.audit.relations || [])[0] },
    current () { return this.currentRelation && this.currentRelation.item },
    duplicateRelations () { return (this.audit.relations || []).filter(x => x.status === 'active' && x.is_primary) },
    physical () { return this.sku && this.sku.line_type === 'physical' },
    actionLabel () { return this.current ? '更换默认Item' : '设置默认Item' },
    product () { return this.sku && this.sku.product ? `${this.sku.product.product_code || '-'}｜${this.sku.product.product_name}` : '—' },
    lineType () { return ({ physical: '实物', service: '服务', no_delivery: '无需发货' })[this.sku && this.sku.line_type] || '—' }
  },
  created () { this.load() },
  methods: {
    relationLabel (relation) {
      const item = relation.item || {}
      return `${item.item_code || '—'}｜${item.item_name || '—'}｜${item.spec_text || '—'}`
    },
    async load () {
      this.loading = true
      try {
        const [detail, history] = await Promise.all([getDefaultSkuItemRelation(this.$route.params.skuId), getSkuItemRelationHistory(this.$route.params.skuId)])
        this.sku = detail.data.data.sku
        this.audit = detail.data.data.audit
        this.history = history.data.data
        if (this.audit.check_status !== 'duplicate') this.repairForm = { keep_relation_id: null, change_reason: '', remark: '' }
      } catch (e) {
        this.$message.error(e.userMessage || '详情加载失败')
      } finally { this.loading = false }
    },
    async resolveDuplicate () {
      if (!this.repairForm.keep_relation_id || !this.repairForm.change_reason) return this.$message.warning('请选择保留的默认Item和变更原因')
      if (this.repairForm.change_reason === '其他' && !this.repairForm.remark.trim()) return this.$message.warning('变更原因选择“其他”时必须填写备注')
      this.repairing = true
      try {
        await resolveDuplicateSkuItemRelation(this.$route.params.skuId, this.repairForm)
        this.$message.success('重复关系已处理，已保留唯一默认Item')
        await this.load()
      } catch (e) { this.$message.error(e.userMessage || '重复关系处理失败') } finally { this.repairing = false }
    },
    back () { this.$router.push('/master/sku-item-relations') }
  }
}
</script>

<style scoped>
.detail-page{padding:20px 22px;background:#f8fafc;min-height:calc(100vh - 56px)}
header{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px}.crumb{font-size:16px;color:#626d7e}
.top{display:grid;grid-template-columns:1fr 1fr;gap:20px}.info-card,.block{background:#fff;border:1px solid #e0e6ed;border-radius:7px;padding:18px}.info-card h3,.block h3{font-size:18px;margin:0 0 17px}.info-card p{display:grid;grid-template-columns:110px 1fr;margin:0 0 15px;font-size:14px}.info-card p span{color:#657083}.info-card p b{font-weight:400}.tag{color:#159447!important;border:1px solid #a7e0bd;background:#effbf3;border-radius:4px;padding:2px 6px;width:max-content}.tag.bad{color:#d33!important;border-color:#ffc7c1;background:#fff0ef}.danger{color:#d33!important}.block{margin-top:16px}.active{color:#159447}.inactive{color:#687486}.tip{color:#2878d6;background:#f1f7ff;border:1px solid #a9cdfd;border-radius:4px;padding:12px;margin:10px 0 0}
.repair-block{border-color:#f0c36d;background:#fffdf7}.repair-heading{display:flex;justify-content:space-between;align-items:flex-start}.repair-heading h3{margin-bottom:6px}.repair-heading p{margin:0 0 14px;color:#687486;font-size:13px}.repair-form{display:grid;grid-template-columns:1.3fr 1fr 1.3fr auto;gap:14px;align-items:end;margin-top:16px}.field{min-width:0}.field label{display:block;color:#4c596b;font-size:13px;margin-bottom:7px}.field em{color:#f56c6c;font-style:normal}.field .el-select,.field .el-input{width:100%}.repair-actions{white-space:nowrap}.repair-actions .el-button{min-width:148px}.remark{min-width:180px}
@media(max-width:1200px){main{min-width:1040px}.repair-form{grid-template-columns:1fr 1fr}.repair-actions{grid-column:2;text-align:right}}
</style>
