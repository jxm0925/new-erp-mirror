<template>
  <section v-if="$can('production.task.view')" class="assignment-panel" v-loading="loading">
    <div class="panel-heading">
      <div><h3>生产接单与派单</h3><p>效率派单根据同产品、工艺和数量的独立合格历史用时提出，由本人接受后接单。</p></div>
      <el-button size="small" @click="load">刷新</el-button>
    </div>
    <div class="task-filter">
      <el-input v-model="keyword" class="task-search-input" size="small" clearable placeholder="搜索任务、工单或产品" @keyup.enter.native="search" @clear="search" />
      <el-select v-model="publicFilter" class="task-public-filter" size="small" aria-label="工序分类" @change="search">
        <el-option label="全部工序" value="" />
        <el-option label="公共工序" value="1" />
        <el-option label="非公共工序" value="0" />
      </el-select>
      <el-button size="small" @click="search">查询</el-button>
    </div>
    <el-table :data="tasks" border size="small">
      <el-table-column prop="task_no" label="任务号" min-width="155" />
      <el-table-column label="工序" min-width="150"><template slot-scope="scope"><div class="operation-label"><span>{{ scope.row.operation_name_snapshot }}</span><el-tag v-if="isPublicOperation(scope.row.is_public_snapshot)" size="mini" type="success">公共工序</el-tag></div></template></el-table-column>
      <el-table-column label="状态" width="115"><template slot-scope="scope">{{ taskStatus(scope.row.status) }}</template></el-table-column>
      <el-table-column label="接单人" min-width="105"><template slot-scope="scope">{{ scope.row.assignee_user && scope.row.assignee_user.display_name || '待接单' }}</template></el-table-column>
      <el-table-column label="操作" min-width="240">
        <template slot-scope="scope">
          <el-button v-if="allowed(scope.row,'claim')" type="text" :disabled="busy" @click="claim(scope.row)">本人接单</el-button>
          <el-button v-if="allowed(scope.row,'auto_assign')" type="text" :disabled="busy" @click="openEfficiency(scope.row)">效率派单</el-button>
          <el-button v-if="allowed(scope.row,'accept_assignment')" type="text" :disabled="busy" @click="accept(scope.row)">接受派单</el-button>
          <el-button v-if="allowed(scope.row,'reject_assignment')" type="text" :disabled="busy" @click="openReject(scope.row)">拒绝派单</el-button>
          <el-button v-if="allowed(scope.row,'add_collaborators')" type="text" :disabled="busy" @click="openCollaborators(scope.row)">选择协同</el-button>
          <el-button type="text" @click="openHistory(scope.row)">派单记录</el-button>
        </template>
      </el-table-column>
    </el-table>
    <el-pagination layout="prev, pager, next, total" :current-page="page" :page-size="20" :total="total" @current-change="changePage" />

    <el-dialog title="效率派单" :visible.sync="efficiencyOpen" width="680px" append-to-body custom-class="production-assignment-dialog" :close-on-click-modal="false" @closed="resetEfficiency">
      <p class="operation-label">{{ selectedTask.task_no }} · {{ selectedTask.operation_name_snapshot }}<el-tag v-if="isPublicOperation(selectedTask.is_public_snapshot)" size="mini" type="success">公共工序</el-tag></p>
      <el-table :data="candidates" size="small" border v-loading="candidateLoading">
        <el-table-column label="候选人"><template slot-scope="scope">{{ scope.row.employee && scope.row.employee.display_name || scope.row.employee_legacy_id }}</template></el-table-column>
        <el-table-column prop="fastest_qualified_minutes" label="最快合格用时（分钟）" min-width="170" />
        <el-table-column prop="qualified_sample_count" label="可比样本数" width="115" />
      </el-table>
      <p v-if="!candidateLoading && !candidateTotal">没有可比较的独立合格历史，请由员工手动接单。</p>
      <el-pagination layout="prev, pager, next, total" :current-page="candidatePage" :page-size="20" :total="candidateTotal" @current-change="loadCandidates" />
      <span slot="footer"><el-button @click="efficiencyOpen=false">关闭</el-button><el-button type="success" :loading="busy" :disabled="!candidateTotal || candidateLoading" @click="offer">向最快候选发出派单</el-button></span>
    </el-dialog>

    <el-dialog title="拒绝派单" :visible.sync="rejectOpen" width="500px" append-to-body custom-class="production-assignment-dialog" :close-on-click-modal="false" @closed="rejectReason=''">
      <el-tag v-if="isPublicOperation(selectedTask.is_public_snapshot)" size="mini" type="success">公共工序</el-tag>
      <p>拒绝后任务回到接单池，记录本人账号、时间和原因。该任务不会再次推荐给您。</p>
      <el-input v-model="rejectReason" type="textarea" :rows="3" :maxlength="500" show-word-limit placeholder="拒绝原因（可选）" />
      <span slot="footer"><el-button @click="rejectOpen=false">取消</el-button><el-button type="danger" :loading="busy" @click="reject">确认拒绝</el-button></span>
    </el-dialog>

    <el-dialog title="派单记录" :visible.sync="historyOpen" width="760px" append-to-body custom-class="production-assignment-dialog">
      <el-tag v-if="isPublicOperation(selectedTask.is_public_snapshot)" size="mini" type="success">公共工序</el-tag>
      <el-table :data="history" size="small" border v-loading="historyLoading">
        <el-table-column label="被派单人" min-width="110"><template slot-scope="scope">{{ scope.row.offered_to && scope.row.offered_to.display_name || scope.row.offered_to_legacy_id }}</template></el-table-column>
        <el-table-column label="结果" width="85"><template slot-scope="scope">{{ offerStatus(scope.row.status) }}</template></el-table-column>
        <el-table-column prop="offered_at" label="提出时间" min-width="160" />
        <el-table-column prop="decided_at" label="处理时间" min-width="160" />
        <el-table-column prop="rejection_reason" label="拒绝原因" min-width="160" />
      </el-table>
      <el-pagination layout="prev, pager, next, total" :current-page="historyPage" :page-size="20" :total="historyTotal" @current-change="loadHistory" />
    </el-dialog>

    <el-dialog title="选择协同人员" :visible.sync="collaboratorsOpen" width="650px" append-to-body custom-class="production-assignment-dialog" :close-on-click-modal="false" @closed="resetCollaborators">
      <el-tag v-if="isPublicOperation(selectedTask.is_public_snapshot)" size="mini" type="success">公共工序</el-tag>
      <div class="task-filter"><el-input v-model="collaboratorKeyword" clearable size="small" placeholder="搜索姓名、账号或人员ID" @keyup.enter.native="loadCollaborators(1)" @clear="loadCollaborators(1)" /><el-button size="small" @click="loadCollaborators(1)">查询</el-button></div>
      <el-checkbox-group v-model="collaboratorIds" class="collaborator-list" v-loading="collaboratorLoading">
        <el-checkbox v-for="person in collaboratorRows" :key="person.user_id" :label="person.user_id" :disabled="person.already_joined">{{ person.display_name }} · {{ person.department_name || '未配置部门' }} · {{ person.user_id }}{{ person.already_joined ? '（已加入）' : '' }}</el-checkbox>
      </el-checkbox-group>
      <el-pagination layout="prev, pager, next, total" :current-page="collaboratorPage" :page-size="20" :total="collaboratorTotal" @current-change="loadCollaborators" />
      <span slot="footer"><span>已选 {{ collaboratorIds.length }} 人，每次最多20人</span><el-button @click="collaboratorsOpen=false">取消</el-button><el-button type="success" :loading="busy" :disabled="!collaboratorIds.length || collaboratorIds.length > 20" @click="addCollaborators">确认添加</el-button></span>
    </el-dialog>
  </section>
</template>

<script>
import { listAssignmentTasks, listTaskAssignments, listAssignmentCandidates, offerFastestAssignment, acceptTaskAssignment, rejectTaskAssignment,
  claimAssignmentTask, listTaskCollaboratorCandidates, addTaskCollaborators, executeProductionDecision } from '../../../api/erp/production-assignments'

export default {
  name: 'ProductionAssignmentPanel',
  props: { workOrder: { type: Object, default: () => ({}) } },
  data: () => ({ loading: false, busy: false, tasks: [], page: 1, total: 0, keyword: '', publicFilter: '', selectedTask: {},
    efficiencyOpen: false, candidates: [], candidatePage: 1, candidateTotal: 0, candidateLoading: false,
    rejectOpen: false, rejectReason: '', historyOpen: false, history: [], historyPage: 1, historyTotal: 0, historyLoading: false,
    collaboratorsOpen: false, collaboratorIds: [], collaboratorRows: [], collaboratorKeyword: '', collaboratorPage: 1, collaboratorTotal: 0, collaboratorLoading: false }),
  mounted() { this.load() },
  watch: { 'workOrder.id'() { this.page = 1; this.load() } },
  methods: {
    allowed(task, action) { return Boolean(task.allowed_actions && task.allowed_actions[action]) },
    isPublicOperation(value) { return value === true || value === 1 || value === '1' },
    taskStatus(status) { return ({ WAIT_PREVIOUS: '待前工序', WAIT_PREDECESSOR: '待前工序', WAIT_CLAIM: '待接单', WAIT_ACCEPT: '待接受派单', CLAIMED: '已接单', WAIT_MATERIAL: '待齐套', WAIT_HANDOVER: '待交接', READY: '待开工', IN_PROGRESS: '加工中', PAUSED: '暂停', WAIT_QUALITY: '待质检', WAIT_WAREHOUSE: '待入库', REWORK: '返工', COMPLETED: '已完成', CANCELLED: '已取消' })[status] || status },
    offerStatus(status) { return ({ PENDING: '待接受', ACCEPTED: '已接受', REJECTED: '已拒绝', CANCELLED: '已取消' })[status] || status },
    async load() {
      if (!this.$can('production.task.view')) return
      const sequence = this.taskSequence = (this.taskSequence || 0) + 1
      this.loading = true
      try {
        const response = await listAssignmentTasks({ view: this.workOrder.id || this.$can('production.assignment.auto') ? 'all' : 'owned',
          work_order_id: this.workOrder.id || undefined, keyword: this.keyword.trim(), is_public: this.publicFilter, page: this.page, per_page: 20 })
        if (sequence !== this.taskSequence) return
        this.tasks = response.data.data || []; this.total = Number(response.data.total || 0)
      } catch (error) { this.$message.error(error.userMessage || '加载任务失败') }
      finally { if (sequence === this.taskSequence) this.loading = false }
    },
    search() { this.page = 1; return this.load() },
    changePage(page) { this.page = page; this.load() },
    async mutate(key, payload, send) {
      if (this.busy) return false
      this.busy = true
      try { const result = await executeProductionDecision(key, payload, send); this.$message.success(result.data.message || '操作成功'); await this.load(); this.$emit('updated'); return true }
      catch (error) { this.$message.error(error.userMessage || '操作失败，请重试原操作'); await this.load(); return false }
      finally { this.busy = false }
    },
    claim(task) { return this.mutate('claim_' + task.id, { expected_version: task.business_version }, payload => claimAssignmentTask(task.id, payload)) },
    openEfficiency(task) { this.selectedTask = task; this.efficiencyOpen = true; this.loadCandidates(1) },
    async loadCandidates(page) {
      this.candidatePage = page; this.candidateLoading = true
      try { const response = await listAssignmentCandidates(this.selectedTask.id, { page, per_page: 20 }); this.candidates = response.data.data || []; this.candidateTotal = Number(response.data.total || 0) }
      catch (error) { this.$message.error(error.userMessage) } finally { this.candidateLoading = false }
    },
    resetEfficiency() { this.candidates = []; this.candidateTotal = 0; this.candidatePage = 1 },
    async offer() { const task = this.selectedTask; if (await this.mutate('offer_' + task.id, { expected_version: task.business_version }, payload => offerFastestAssignment(task.id, payload))) this.efficiencyOpen = false },
    decisionPayload(task) { return { expected_version: task.pending_assignment.business_version, expected_task_version: task.business_version } },
    accept(task) { return this.mutate('accept_' + task.pending_assignment.id, this.decisionPayload(task), payload => acceptTaskAssignment(task.pending_assignment.id, payload)) },
    openReject(task) { this.selectedTask = task; this.rejectReason = ''; this.rejectOpen = true },
    async reject() { const task = this.selectedTask; if (await this.mutate('reject_' + task.pending_assignment.id, { ...this.decisionPayload(task), reason: this.rejectReason.trim() }, payload => rejectTaskAssignment(task.pending_assignment.id, payload))) this.rejectOpen = false },
    openHistory(task) { this.selectedTask = task; this.historyOpen = true; this.loadHistory(1) },
    async loadHistory(page) {
      this.historyPage = page; this.historyLoading = true
      try { const response = await listTaskAssignments({ view: this.$can('production.assignment.auto') ? 'all' : 'mine', task_id: this.selectedTask.id, page, per_page: 20 }); this.history = response.data.data || []; this.historyTotal = Number(response.data.total || 0) }
      catch (error) { this.$message.error(error.userMessage) } finally { this.historyLoading = false }
    },
    openCollaborators(task) { this.selectedTask = task; this.resetCollaborators(); this.collaboratorsOpen = true; this.loadCollaborators(1) },
    async loadCollaborators(page) {
      const sequence = this.collaboratorSequence = (this.collaboratorSequence || 0) + 1
      this.collaboratorPage = page; this.collaboratorLoading = true
      try {
        const response = await listTaskCollaboratorCandidates(this.selectedTask.id, { page, per_page: 20, keyword: this.collaboratorKeyword.trim() })
        if (sequence !== this.collaboratorSequence || !this.collaboratorsOpen) return
        this.collaboratorRows = response.data.data || []; this.collaboratorTotal = Number(response.data.total || 0)
      } catch (error) { this.$message.error(error.userMessage) } finally { if (sequence === this.collaboratorSequence) this.collaboratorLoading = false }
    },
    resetCollaborators() { this.collaboratorSequence = (this.collaboratorSequence || 0) + 1; this.collaboratorIds = []; this.collaboratorRows = []; this.collaboratorKeyword = ''; this.collaboratorPage = 1; this.collaboratorTotal = 0; this.collaboratorLoading = false },
    async addCollaborators() { const task = this.selectedTask; if (await this.mutate('collaborators_' + task.id, { expected_version: task.business_version, employee_legacy_ids: this.collaboratorIds }, payload => addTaskCollaborators(task.id, payload))) this.collaboratorsOpen = false }
  }
}
</script>

<style scoped>
.assignment-panel{padding:18px 20px;background:#fff;border:1px solid #e6ebf0;border-radius:5px;margin:14px 0;color:#33455d}
.panel-heading{display:flex;justify-content:space-between;align-items:center;gap:16px}.panel-heading h3{font-size:14px;margin:0 0 8px}.panel-heading p{font-size:12px;color:#7f8da0;margin:0 0 14px;line-height:1.6}
.task-filter{display:flex;gap:8px;align-items:center;max-width:640px;margin:12px 0}.el-pagination{margin-top:12px;text-align:right}.collaborator-list{min-height:160px;max-height:45vh;overflow:auto;display:flex;flex-direction:column;gap:12px}.collaborator-list .el-checkbox{margin-left:0}
.task-filter {
  flex-wrap: wrap;
  min-width: 0;
}
.task-search-input {
  flex: 1 1 230px;
  min-width: 0;
}
.task-public-filter {
  flex: 0 1 150px;
  min-width: 0;
}
.operation-label {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: 6px;
  min-width: 0;
}
.operation-label .el-tag {
  flex-shrink: 0;
}
@media(max-width:600px){.assignment-panel{padding:12px}.panel-heading{align-items:flex-start}.task-filter{max-width:100%}}
</style>
<style>
.production-assignment-dialog{max-width:calc(100vw - 28px)!important;margin:15vh auto 0!important}.production-assignment-dialog .el-dialog__body{max-height:65vh;overflow:auto}.production-assignment-dialog .el-dialog__footer{display:flex;justify-content:flex-end;gap:12px;align-items:center}
</style>
