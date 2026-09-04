<template>
  <div class="approval-page" v-loading="loading">
    <section class="approval-main">
      <header class="page-heading">
        <div>
          <h1>审核工作台</h1>
          <p>
            统一发起、处理和追踪全部业务审核，不再为每种业务单独开发流程入口
          </p>
        </div>
        <div class="heading-actions">
          <el-button size="small" icon="el-icon-refresh" @click="load"
            >刷新</el-button
          >
          <el-button
            size="small"
            type="primary"
            icon="el-icon-plus"
            @click="openLaunch"
            >发起申请</el-button
          >
          <el-button
            size="small"
            type="success"
            :disabled="!selected.length || scope !== 'todo'"
            @click="batchApprove"
            >批量通过</el-button
          >
        </div>
      </header>

      <nav class="workbench-tabs" aria-label="审核工作台视图">
        <button
          v-for="tab in workbenchTabs"
          :key="tab.key"
          type="button"
          :class="{ active: scope === tab.key }"
          @click="switchScope(tab.key)"
        >
          <i :class="tab.icon" />
          <span>{{ tab.label }}</span>
          <em v-if="tab.count !== null">{{ tab.count }}</em>
        </button>
      </nav>

      <div class="summary-grid">
        <article
          v-for="card in summaryCards"
          :key="card.key"
          class="summary-card"
          :class="card.key"
        >
          <div>
            <small>{{ card.label }}</small
            ><strong>{{ summary[card.key] || 0 }}</strong>
          </div>
          <i :class="card.icon" />
        </article>
      </div>

      <section class="filter-card">
        <label
          >审核业务<el-select
            v-model="filters.business_type"
            size="small"
            clearable
            filterable
            placeholder="请选择审核业务"
            ><el-option
              v-for="flow in launchOptions"
              :key="flow.business_type"
              :label="flow.flow_name"
              :value="flow.business_type" /></el-select
        ></label>
        <label
          >审核类型<el-select
            v-model="filters.approval_type"
            size="small"
            clearable
            placeholder="请选择审核类型"
            ><el-option label="业务审核" value="business" /><el-option
              label="财务审核"
              value="finance" /><el-option
              label="履约复核"
              value="fulfillment" /></el-select
        ></label>
        <label
          >风险等级<el-select
            v-model="filters.risk_level"
            size="small"
            clearable
            placeholder="请选择风险等级"
            ><el-option label="高风险" value="high" /><el-option
              label="中风险"
              value="medium" /><el-option
              label="低风险"
              value="low" /></el-select
        ></label>
        <label
          >发起人<el-input
            v-model.trim="filters.initiator"
            size="small"
            placeholder="请输入发起人"
        /></label>
        <label
          >所属部门<el-input
            v-model.trim="filters.department"
            size="small"
            placeholder="请输入所属部门"
        /></label>
        <label class="date-filter"
          >提交时间<el-date-picker
            v-model="submittedRange"
            type="daterange"
            size="small"
            range-separator="~"
            start-placeholder="开始日期"
            end-placeholder="结束日期"
            value-format="yyyy-MM-dd"
        /></label>
        <label class="keyword-filter"
          >关键字<el-input
            v-model.trim="filters.keyword"
            size="small"
            placeholder="请输入任务号/单号/主题"
            @keyup.enter.native="search"
        /></label>
        <div class="filter-actions">
          <el-button size="small" type="success" @click="search">查询</el-button
          ><el-button size="small" @click="reset">重置</el-button>
        </div>
      </section>

      <section class="table-card">
        <el-table
          :data="rows"
          border
          size="mini"
          row-key="id"
          @selection-change="selected = $event"
          @row-click="openTask"
        >
          <el-table-column
            v-if="scope === 'todo'"
            type="selection"
            width="42"
            align="center"
            :selectable="(row) => row.can_decide"
          />
          <el-table-column label="优先级" width="68" align="center"
            ><template slot-scope="{ row }"
              ><el-tag size="mini" :type="riskTag(row.risk_level)">{{
                priorityText(row.risk_level)
              }}</el-tag></template
            ></el-table-column
          >
          <el-table-column prop="task_no" label="审核任务号" min-width="148" />
          <el-table-column label="审核业务" min-width="128"
            ><template slot-scope="{ row }">{{
              businessTypeText(row.business_type)
            }}</template></el-table-column
          >
          <el-table-column
            prop="business_no"
            label="业务单号"
            min-width="150"
          />
          <el-table-column prop="subject" label="审核主题" min-width="180" />
          <el-table-column label="当前节点" min-width="104"
            ><template slot-scope="{ row }">{{
              row.current_node ? row.current_node.node_name : "已结束"
            }}</template></el-table-column
          >
          <el-table-column prop="initiator_name" label="发起人" width="86" />
          <el-table-column label="提交时间" width="142"
            ><template slot-scope="{ row }">{{
              formatTime(row.submitted_at)
            }}</template></el-table-column
          >
          <el-table-column label="等待时长" width="92"
            ><template slot-scope="{ row }">{{
              waitingText(row.waiting_minutes)
            }}</template></el-table-column
          >
          <el-table-column label="风险等级" width="86"
            ><template slot-scope="{ row }"
              ><el-tag size="mini" :type="riskTag(row.risk_level)">{{
                riskText(row.risk_level)
              }}</el-tag></template
            ></el-table-column
          >
          <el-table-column label="状态" width="86"
            ><template slot-scope="{ row }"
              ><el-tag size="mini" :type="statusTag(row.task_status)">{{
                statusText(row.task_status)
              }}</el-tag></template
            ></el-table-column
          >
          <el-table-column label="操作" width="178" fixed="right"
            ><template slot-scope="{ row }"
              ><el-button type="text" size="mini" @click.stop="openTask(row)"
                >查看详情</el-button
              ><el-button
                v-if="row.can_decide"
                type="text"
                size="mini"
                @click.stop="quickDecision(row, 'approve')"
                >通过</el-button
              ><el-button
                v-if="row.can_decide && row.can_reject"
                type="text"
                size="mini"
                class="danger-link"
                @click.stop="quickDecision(row, 'reject')"
                >驳回</el-button
              ></template
            ></el-table-column
          >
        </el-table>
        <footer class="pagination">
          <span>共 {{ total }} 条</span
          ><el-pagination
            background
            :current-page.sync="page"
            :page-size="perPage"
            :page-sizes="[10, 20, 50, 100]"
            layout="sizes, prev, pager, next, jumper"
            :total="total"
            @current-change="loadRows"
            @size-change="sizeChange"
          />
        </footer>
      </section>
    </section>

    <aside class="approval-aside">
      <section class="aside-card rules">
        <h3><i class="el-icon-info" /> 审核规则说明</h3>
        <p>已有ERP单据：选择流程和业务记录即可发起。</p>
        <p>
          自定义申请：先在表单管理搭建表单，再关联流程；发起时系统自动渲染字段。
        </p>
        <p>所有节点、条件、处理人和完成动作均来自已发布流程版本。</p>
      </section>
      <section class="aside-card reminders">
        <h3><i class="el-icon-bell" /> 待办提醒</h3>
        <button @click="applyReminder('high')">
          <b class="red">高风险任务 {{ summary.high_risk || 0 }} 条</b
          ><small>存在高风险的任务请及时处理。</small
          ><i class="el-icon-arrow-right" /></button
        ><button @click="applyReminder('timeout')">
          <b class="orange">即将超时 {{ summary.near_timeout || 0 }} 条</b
          ><small>请尽快处理临近 SLA 的任务。</small
          ><i class="el-icon-arrow-right" /></button
        ><button @click="applyReminder('today')">
          <b class="blue">今日新增 {{ summary.today_new || 0 }} 条</b
          ><small>查看今天提交的审核任务。</small
          ><i class="el-icon-arrow-right" /></button
        ><button @click="$router.push('/approvals/flows')">
          <b class="purple">流程配置</b
          ><small>新增、发布或停用可运行流程。</small
          ><i class="el-icon-arrow-right" />
        </button>
      </section>
    </aside>

    <el-dialog
      title="发起申请"
      :visible.sync="launchVisible"
      width="920px"
      custom-class="approval-launch-dialog"
      :close-on-click-modal="false"
    >
      <div class="launch-tip">
        <i class="el-icon-info" />
        选择一个已发布流程即可发起；这里不依赖采购、日报或其他业务的专用代码。
      </div>
      <el-form label-width="108px" size="small" class="launch-form">
        <el-form-item label="审核流程" required>
          <el-select
            v-model="launch.flow_id"
            filterable
            placeholder="请选择已启用流程"
            style="width: 100%"
            @change="launchFlowChanged"
          >
            <el-option-group
              v-for="group in launchGroups"
              :key="group.label"
              :label="group.label"
              ><el-option
                v-for="flow in group.options"
                :key="flow.id"
                :label="flow.flow_name"
                :value="flow.id"
                ><span>{{ flow.flow_name }}</span
                ><small class="flow-option-code">{{
                  flow.flow_code
                }}</small></el-option
              ></el-option-group
            >
          </el-select>
        </el-form-item>
        <template v-if="selectedLaunchFlow">
          <el-form-item label="申请来源"
            ><el-tag
              size="small"
              :type="
                selectedLaunchFlow.source_mode === 'custom_form'
                  ? 'warning'
                  : 'success'
              "
              >{{
                selectedLaunchFlow.source_mode === "custom_form"
                  ? "自定义表单"
                  : "已有ERP单据"
              }}</el-tag
            ><span class="source-caption">{{
              launchSourceCaption
            }}</span></el-form-item
          >
          <el-form-item label="申请主题" required
            ><el-input
              v-model.trim="launch.subject"
              maxlength="255"
              show-word-limit
              placeholder="请输入本次申请主题"
          /></el-form-item>
          <el-form-item label="风险等级"
            ><el-radio-group v-model="launch.risk_level"
              ><el-radio-button label="low">低</el-radio-button
              ><el-radio-button label="medium">中</el-radio-button
              ><el-radio-button label="high"
                >高</el-radio-button
              ></el-radio-group
            ></el-form-item
          >
        </template>
      </el-form>

      <section
        v-if="
          selectedLaunchFlow && selectedLaunchFlow.source_mode === 'existing'
        "
        class="record-picker"
        v-loading="recordLoading"
      >
        <header>
          <div>
            <h3>选择业务记录</h3>
            <p>
              数据直接读取
              {{
                selectedLaunchFlow.business_object.table
              }}，不需要为该流程新增接口。
            </p>
          </div>
          <el-input
            v-model.trim="recordKeyword"
            size="small"
            clearable
            placeholder="按编号或名称搜索"
            @keyup.enter.native="loadRecords"
            ><el-button
              slot="append"
              icon="el-icon-search"
              @click="loadRecords"
          /></el-input>
        </header>
        <el-table
          :data="launchRecords"
          border
          size="mini"
          highlight-current-row
          @current-change="selectLaunchRecord"
        >
          <el-table-column width="54" align="center"
            ><template slot-scope="{ row }"
              ><el-radio v-model="launch.business_id" :label="row.id"
                ><span /></el-radio></template
          ></el-table-column>
          <el-table-column
            prop="business_no"
            label="业务编号"
            min-width="150"
          />
          <el-table-column prop="title" label="业务名称/主题" min-width="170" />
          <el-table-column prop="status" label="当前状态" width="110"
            ><template slot-scope="{ row }">{{
              row.status || "-"
            }}</template></el-table-column
          >
          <el-table-column label="关键字段" min-width="270"
            ><template slot-scope="{ row }"
              ><span
                v-for="item in row.summary.slice(0, 3)"
                :key="item.key"
                class="summary-value"
                >{{ item.label }}：{{ item.value }}</span
              ></template
            ></el-table-column
          >
        </el-table>
        <footer>
          <span>共 {{ recordTotal }} 条</span
          ><el-pagination
            small
            background
            layout="prev, pager, next"
            :current-page.sync="recordPage"
            :page-size="10"
            :total="recordTotal"
            @current-change="loadRecords"
          />
        </footer>
      </section>

      <section
        v-if="
          selectedLaunchFlow && selectedLaunchFlow.source_mode === 'custom_form'
        "
        class="custom-form-panel"
      >
        <h3>
          {{
            selectedLaunchFlow.custom_form &&
            selectedLaunchFlow.custom_form.label
          }}
        </h3>
        <el-form label-width="118px" size="small">
          <el-form-item
            v-for="field in launchFormFields"
            :key="field.value"
            :label="field.label"
            :required="field.required"
          >
            <el-input
              v-if="field.type === 'text' || field.type === 'business_link'"
              v-model="launch.form_data[field.value]"
              :placeholder="field.placeholder || `请输入${field.label}`"
            />
            <el-input
              v-else-if="field.type === 'textarea'"
              v-model="launch.form_data[field.value]"
              type="textarea"
              :rows="3"
              :placeholder="field.placeholder || `请输入${field.label}`"
            />
            <el-input-number
              v-else-if="field.type === 'number'"
              v-model="launch.form_data[field.value]"
              :precision="2"
              :controls="false"
              style="width: 100%"
            />
            <el-date-picker
              v-else-if="field.type === 'date'"
              v-model="launch.form_data[field.value]"
              type="date"
              value-format="yyyy-MM-dd"
              placeholder="请选择日期"
              style="width: 100%"
            />
            <el-select
              v-else-if="['select', 'user', 'department'].includes(field.type)"
              v-model="launch.form_data[field.value]"
              filterable
              clearable
              style="width: 100%"
              :placeholder="`请选择${field.label}`"
              ><el-option
                v-for="option in field.options"
                :key="option.value"
                :label="option.label"
                :value="String(option.value)"
            /></el-select>
            <el-select
              v-else-if="field.type === 'multi_select'"
              v-model="launch.form_data[field.value]"
              multiple
              filterable
              clearable
              style="width: 100%"
              :placeholder="`请选择${field.label}`"
              ><el-option
                v-for="option in field.options"
                :key="option.value"
                :label="option.label"
                :value="option.value"
            /></el-select>
            <el-input
              v-else-if="field.type === 'attachment'"
              v-model="launch.form_data[field.value]"
              placeholder="请输入附件地址，多个附件用逗号分隔"
              @change="normalizeAttachment(field)"
            />
            <el-input v-else v-model="launch.form_data[field.value]" />
            <small v-if="field.help" class="field-help">{{ field.help }}</small>
          </el-form-item>
        </el-form>
      </section>

      <span slot="footer"
        ><el-button size="small" @click="launchVisible = false">取消</el-button
        ><el-button
          size="small"
          type="success"
          :loading="launching"
          :disabled="!selectedLaunchFlow"
          @click="submitLaunch"
          >提交申请</el-button
        ></span
      >
    </el-dialog>
  </div>
</template>

<script>
import {
  batchDecideApprovalTasks,
  decideApprovalTask,
  getApprovalLaunchOptions,
  getApprovalSummary,
  launchApprovalFlow,
  listApprovalLaunchRecords,
  listApprovalTasks,
} from "@/api/erp/approval";

const blankFilters = () => ({
  business_type: "",
  approval_type: "",
  risk_level: "",
  initiator: "",
  department: "",
  keyword: "",
});
const blankLaunch = () => ({
  flow_id: null,
  business_id: null,
  subject: "",
  risk_level: "medium",
  form_data: {},
});
export default {
  name: "ApprovalWorkbench",
  data: () => ({
    loading: false,
    rows: [],
    selected: [],
    page: 1,
    perPage: 20,
    total: 0,
    summary: {},
    filters: blankFilters(),
    submittedRange: [],
    launchOptions: [],
    launchVisible: false,
    launching: false,
    launch: blankLaunch(),
    launchRecords: [],
    recordKeyword: "",
    recordPage: 1,
    recordTotal: 0,
    recordLoading: false,
    activeScope: "todo",
  }),
  computed: {
    scope() {
      return this.activeScope;
    },
    canViewAll() {
      return this.$can("approval.all");
    },
    workbenchTabs() {
      return [
        { key: "todo", label: "我的待审", icon: "el-icon-circle-check", count: Number(this.summary.todo || 0) },
        { key: "processed", label: "我已处理", icon: "el-icon-finished", count: Number(this.summary.processed || 0) },
        { key: "all", label: "全部审核", icon: "el-icon-tickets", count: this.summary.all === null || this.summary.all === undefined ? null : Number(this.summary.all || 0), visible: this.canViewAll },
        { key: "initiated", label: "我的申请", icon: "el-icon-document-add", count: Number(this.summary.initiated || 0) },
      ].filter((tab) => tab.visible !== false);
    },
    summaryCards() {
      return [
        { key: "todo", label: "我的待审", icon: "el-icon-document" },
        { key: "high_risk", label: "高风险", icon: "el-icon-warning-outline" },
        { key: "today_new", label: "今日新增", icon: "el-icon-document-add" },
        { key: "near_timeout", label: "即将超时", icon: "el-icon-time" },
        { key: "processed", label: "我已处理", icon: "el-icon-finished" },
        { key: "rejected", label: "已驳回", icon: "el-icon-circle-close" },
      ];
    },
    selectedLaunchFlow() {
      return (
        this.launchOptions.find(
          (row) => Number(row.id) === Number(this.launch.flow_id)
        ) || null
      );
    },
    launchGroups() {
      const groups = {};
      this.launchOptions.forEach((flow) => {
        (
          groups[flow.business_module] || (groups[flow.business_module] = [])
        ).push(flow);
      });
      return Object.keys(groups).map((label) => ({
        label,
        options: groups[label],
      }));
    },
    launchFormFields() {
      return (
        (this.selectedLaunchFlow &&
          this.selectedLaunchFlow.custom_form &&
          this.selectedLaunchFlow.custom_form.fields) ||
        []
      );
    },
    launchSourceCaption() {
      if (!this.selectedLaunchFlow) return "";
      return this.selectedLaunchFlow.source_mode === "custom_form"
        ? ` · ${
            this.selectedLaunchFlow.custom_form
              ? this.selectedLaunchFlow.custom_form.label
              : ""
          }`
        : ` · ${
            this.selectedLaunchFlow.business_object
              ? this.selectedLaunchFlow.business_object.label
              : ""
          }`;
    },
  },
  watch: {
    "$route.query.scope"() {
      const next = this.routeScope();
      if (next === this.activeScope) return;
      this.activeScope = next;
      this.page = 1;
      this.selected = [];
      this.loadRows();
    },
  },
  created() {
    this.activeScope = this.routeScope();
    this.load();
  },
  methods: {
    routeScope() {
      const legacyScope = this.$route.path === "/approvals/processed"
        ? "processed"
        : this.$route.path === "/approvals/all"
        ? "all"
        : "";
      const requested = String(this.$route.query.scope || legacyScope || "todo");
      if (!["todo", "processed", "initiated", "all"].includes(requested)) return "todo";
      return requested === "all" && !this.$can("approval.all") ? "todo" : requested;
    },
    async switchScope(scope) {
      if (scope === this.activeScope) return;
      if (scope === "all" && !this.canViewAll) return;
      this.activeScope = scope;
      this.page = 1;
      this.selected = [];
      const query = { ...this.$route.query };
      if (scope === "todo") delete query.scope;
      else query.scope = scope;
      await this.$router.replace({ path: "/approvals/tasks", query });
      await this.loadRows();
    },
    async load() {
      this.loading = true;
      try {
        await Promise.all([
          this.loadRows(),
          this.loadSummary(),
          this.loadLaunchOptions(),
        ]);
      } finally {
        this.loading = false;
      }
    },
    async loadRows() {
      const params = {
        ...this.filters,
        scope: this.scope,
        page: this.page,
        per_page: this.perPage,
      };
      if (this.submittedRange.length === 2)
        [params.submitted_from, params.submitted_to] = this.submittedRange;
      const { data } = await listApprovalTasks(params);
      this.rows = data.data || [];
      this.total = Number(data.total || 0);
    },
    async loadSummary() {
      const { data } = await getApprovalSummary();
      this.summary = data.data || {};
    },
    async loadLaunchOptions() {
      const { data } = await getApprovalLaunchOptions();
      this.launchOptions = data.data || [];
    },
    search() {
      this.page = 1;
      this.loadRows();
    },
    reset() {
      this.filters = blankFilters();
      this.submittedRange = [];
      this.search();
    },
    sizeChange(value) {
      this.perPage = value;
      this.page = 1;
      this.loadRows();
    },
    openTask(row) {
      this.$router.push(`/approvals/tasks/${row.id}`);
    },
    openLaunch() {
      this.launch = blankLaunch();
      this.launchRecords = [];
      this.recordKeyword = "";
      this.recordPage = 1;
      this.launchVisible = true;
    },
    async launchFlowChanged() {
      const flow = this.selectedLaunchFlow;
      this.launch.business_id = null;
      this.launch.form_data = {};
      this.launch.subject = flow ? flow.flow_name : "";
      this.launchRecords = [];
      this.recordPage = 1;
      if (flow && flow.source_mode === "existing") await this.loadRecords();
    },
    async loadRecords() {
      if (!this.selectedLaunchFlow) return;
      this.recordLoading = true;
      try {
        const { data } = await listApprovalLaunchRecords(
          this.selectedLaunchFlow.id,
          { keyword: this.recordKeyword, page: this.recordPage, per_page: 10 }
        );
        this.launchRecords = data.data || [];
        this.recordTotal = Number(data.total || 0);
      } finally {
        this.recordLoading = false;
      }
    },
    selectLaunchRecord(row) {
      if (row) this.launch.business_id = row.id;
    },
    normalizeAttachment(field) {
      const value = this.launch.form_data[field.value];
      this.$set(
        this.launch.form_data,
        field.value,
        String(value || "")
          .split(",")
          .map((v) => v.trim())
          .filter(Boolean)
      );
    },
    async submitLaunch() {
      const flow = this.selectedLaunchFlow;
      if (!flow) return this.$message.warning("请选择审核流程");
      if (!this.launch.subject) return this.$message.warning("请填写申请主题");
      if (flow.source_mode === "existing" && !this.launch.business_id)
        return this.$message.warning("请选择业务记录");
      for (const field of this.launchFormFields) {
        if (
          field.required &&
          (this.launch.form_data[field.value] === undefined ||
            this.launch.form_data[field.value] === "" ||
            this.launch.form_data[field.value] === null)
        )
          return this.$message.warning(`请填写${field.label}`);
      }
      this.launching = true;
      try {
        const { data } = await launchApprovalFlow(flow.id, {
          business_id: this.launch.business_id,
          subject: this.launch.subject,
          risk_level: this.launch.risk_level,
          form_data: this.launch.form_data,
        });
        this.launchVisible = false;
        this.$message.success(data.message || "申请已发起");
        if (data.data && data.data.id) {
          await this.$router.push(`/approvals/tasks/${data.data.id}`);
          return;
        }
        await this.load();
      } finally {
        this.launching = false;
      }
    },
    async quickDecision(row, decision) {
      try {
        const result = await this.$prompt(
          decision === "approve" ? "请填写审核意见" : "请填写驳回原因",
          decision === "approve" ? "通过当前节点" : "驳回审核任务",
          {
            confirmButtonText: decision === "approve" ? "确认通过" : "确认驳回",
            inputValidator: (v) =>
              String(v || "").trim() ? true : "审核意见不能为空",
          }
        );
        await decideApprovalTask(row.id, { decision, comment: result.value });
        this.$message.success(
          decision === "approve" ? "当前节点已通过" : "审核任务已驳回"
        );
        await this.load();
      } catch (e) {
        if (e !== "cancel" && e !== "close" && e.userMessage)
          this.$message.error(e.userMessage);
      }
    },
    async batchApprove() {
      try {
        const result = await this.$prompt(
          "请填写批量审核意见",
          `批量通过 ${this.selected.length} 条任务`,
          {
            inputValidator: (v) =>
              String(v || "").trim() ? true : "审核意见不能为空",
          }
        );
        const response = await batchDecideApprovalTasks({
          task_ids: this.selected.map((row) => row.id),
          comment: result.value,
        });
        const data = response.data.data || {};
        const failed = (data.results || []).filter((row) => !row.success);
        if (failed.length) {
          const detail = failed.map((row) => `任务 ${row.task_no || row.task_id}：${row.message}`).join("\n");
          await this.$alert(`${data.summary || "批量审核已完成"}\n\n${detail}`, "批量审核结果", { type: data.success_count ? "warning" : "error" });
        } else this.$message.success(data.summary || "已批量通过当前节点");
        await this.load();
      } catch (e) {
        if (e !== "cancel" && e !== "close" && e.userMessage)
          this.$message.error(e.userMessage);
      }
    },
    applyReminder(type) {
      if (type === "high") this.filters.risk_level = "high";
      if (type === "today") this.submittedRange = [this.today(), this.today()];
      this.search();
    },
    today() {
      const d = new Date();
      return [
        d.getFullYear(),
        String(d.getMonth() + 1).padStart(2, "0"),
        String(d.getDate()).padStart(2, "0"),
      ].join("-");
    },
    businessTypeText(v) {
      const flow = this.launchOptions.find((row) => row.business_type === v);
      return flow ? flow.flow_name : v;
    },
    riskText(v) {
      return { high: "高风险", medium: "中风险", low: "低风险" }[v] || v;
    },
    priorityText(v) {
      return { high: "高", medium: "中", low: "低" }[v] || v;
    },
    riskTag(v) {
      return { high: "danger", medium: "warning", low: "success" }[v] || "info";
    },
    statusText(v) {
      return (
        {
          PENDING: "待审核",
          APPROVED: "已通过",
          REJECTED: "已驳回",
          CONFLICTED: "版本冲突",
          CANCELLED: "已取消",
        }[v] || v
      );
    },
    statusTag(v) {
      return (
        {
          PENDING: "",
          APPROVED: "success",
          REJECTED: "danger",
          CONFLICTED: "warning",
          CANCELLED: "info",
        }[v] || "info"
      );
    },
    formatTime(v) {
      return v
        ? String(v)
            .replace("T", " ")
            .replace(/\.\d+Z$/, "")
            .slice(0, 16)
        : "-";
    },
    waitingText(minutes) {
      const n = Number(minutes || 0);
      return n >= 1440
        ? `${Math.floor(n / 1440)}天${Math.floor((n % 1440) / 60)}小时`
        : n >= 60
        ? `${Math.floor(n / 60)}小时${n % 60}分`
        : `${n}分`;
    },
  },
};
</script>

<style scoped>
.approval-page {
  min-height: calc(100vh - 52px);
  padding: 16px 18px;
  display: grid;
  grid-template-columns: minmax(0, 1fr) 286px;
  gap: 14px;
  background: #f7f8fa;
  color: #1c2733;
}
.approval-main {
  min-width: 0;
}
.page-heading {
  height: 58px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.page-heading h1 {
  margin: 1px 0 4px;
  font-size: 22px;
  line-height: 1.2;
}
.page-heading p {
  margin: 0;
  color: #768291;
  font-size: 12px;
}
.heading-actions {
  display: flex;
  gap: 9px;
}
.workbench-tabs {
  height: 46px;
  display: flex;
  align-items: flex-end;
  gap: 4px;
  padding: 0 8px;
  margin-bottom: 12px;
  background: #fff;
  border: 1px solid #e6eaee;
  border-radius: 6px;
}
.workbench-tabs button {
  position: relative;
  height: 45px;
  padding: 0 18px;
  display: inline-flex;
  align-items: center;
  gap: 7px;
  color: #59697b;
  font-size: 13px;
  background: transparent;
  border: 0;
  cursor: pointer;
}
.workbench-tabs button::after {
  position: absolute;
  right: 14px;
  bottom: -1px;
  left: 14px;
  height: 2px;
  content: "";
  background: transparent;
  border-radius: 2px 2px 0 0;
}
.workbench-tabs button:hover {
  color: #07894a;
  background: #f6fbf8;
}
.workbench-tabs button.active {
  color: #07894a;
  font-weight: 600;
}
.workbench-tabs button.active::after {
  background: #07964f;
}
.workbench-tabs em {
  min-width: 20px;
  height: 19px;
  padding: 0 6px;
  color: #728091;
  font-size: 11px;
  font-style: normal;
  line-height: 19px;
  text-align: center;
  background: #f0f3f6;
  border-radius: 10px;
}
.workbench-tabs button.active em {
  color: #07894a;
  background: #e7f6ed;
}
.summary-grid {
  display: grid;
  grid-template-columns: repeat(6, minmax(118px, 1fr));
  gap: 10px;
  margin-bottom: 12px;
}
.summary-card {
  height: 94px;
  padding: 16px 18px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  background: #fff;
  border: 1px solid #e7ebef;
  border-radius: 6px;
}
.summary-card small {
  display: block;
  font-size: 13px;
  color: #4a6078;
}
.summary-card strong {
  display: block;
  margin-top: 9px;
  font-size: 27px;
  color: #122033;
}
.summary-card i {
  font-size: 29px;
  color: #2b82ee;
}
.summary-card.high_risk i,
.summary-card.high_risk small {
  color: #ef6d33;
}
.summary-card.today_new i,
.summary-card.today_new small {
  color: #11a866;
}
.summary-card.near_timeout i,
.summary-card.near_timeout small {
  color: #ed9613;
}
.summary-card.processed i,
.summary-card.processed small {
  color: #7855d9;
}
.summary-card.rejected i,
.summary-card.rejected small {
  color: #e64b4b;
}
.filter-card {
  display: grid;
  grid-template-columns: repeat(5, minmax(130px, 1fr));
  gap: 12px 14px;
  padding: 14px 16px;
  margin-bottom: 12px;
  background: #fff;
  border: 1px solid #e6eaee;
  border-radius: 6px;
}
.filter-card label {
  font-size: 12px;
  color: #344357;
  display: flex;
  flex-direction: column;
  gap: 7px;
}
.date-filter {
  grid-column: span 2;
}
.date-filter .el-date-editor {
  width: 100%;
}
.keyword-filter {
  grid-column: span 2;
}
.filter-actions {
  display: flex;
  align-items: end;
  gap: 8px;
  padding-bottom: 1px;
}
.table-card {
  background: #fff;
  border: 1px solid #e6eaee;
  border-radius: 6px;
  overflow: hidden;
}
.table-card ::v-deep .el-table th {
  background: #f7f9fb;
  color: #28394c;
  font-weight: 600;
  height: 42px;
}
.table-card ::v-deep .el-table td {
  height: 46px;
}
.table-card ::v-deep .el-table__row {
  cursor: pointer;
}
.table-card ::v-deep .el-table__row:hover > td {
  background: #f1faf5;
}
.danger-link {
  color: #e55353;
}
.pagination {
  min-height: 58px;
  padding: 0 14px;
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-size: 12px;
}
.approval-aside {
  padding-top: 116px;
}
.aside-card {
  padding: 16px;
  margin-bottom: 12px;
  background: #fff;
  border: 1px solid #e5e9ee;
  border-radius: 6px;
}
.aside-card h3 {
  margin: 0 0 18px;
  font-size: 16px;
}
.aside-card h3 i {
  color: #2788ef;
  margin-right: 7px;
}
.rules p {
  font-size: 13px;
  line-height: 1.8;
  color: #627184;
}
.reminders button {
  position: relative;
  width: 100%;
  padding: 14px 26px 14px 12px;
  margin-bottom: 9px;
  text-align: left;
  background: #fff;
  border: 1px solid #e7ebef;
  border-radius: 5px;
  cursor: pointer;
}
.reminders button:hover {
  background: #f6faf8;
}
.reminders b,
.reminders small {
  display: block;
}
.reminders b {
  font-size: 13px;
  margin-bottom: 6px;
}
.reminders small {
  color: #7a8795;
  line-height: 1.5;
}
.reminders button > i {
  position: absolute;
  right: 10px;
  top: 50%;
  transform: translateY(-50%);
  color: #9aa6b3;
}
.red {
  color: #e64b4b;
}
.orange {
  color: #eb8c13;
}
.blue {
  color: #2b82ee;
}
.purple {
  color: #7651cf;
}
.launch-tip {
  padding: 11px 14px;
  margin: -4px 0 16px;
  color: #2769b4;
  background: #eef6ff;
  border: 1px solid #b9d9ff;
  border-radius: 4px;
}
.launch-tip i {
  margin-right: 7px;
}
.source-caption {
  margin-left: 10px;
  color: #6e7c8c;
}
.flow-option-code {
  float: right;
  margin-left: 24px;
  color: #9ba6b2;
}
.record-picker,
.custom-form-panel {
  margin-top: 8px;
  border: 1px solid #e2e7ec;
  border-radius: 5px;
  overflow: hidden;
}
.record-picker header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 12px 14px;
  background: #f8fafb;
}
.record-picker h3,
.custom-form-panel h3 {
  margin: 0 0 4px;
  font-size: 15px;
}
.record-picker p {
  margin: 0;
  color: #7b8794;
  font-size: 12px;
}
.record-picker header .el-input {
  width: 310px;
}
.record-picker footer {
  height: 48px;
  padding: 0 12px;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.summary-value {
  display: inline-block;
  margin-right: 14px;
  color: #657386;
}
.custom-form-panel {
  padding: 16px 18px;
}
.custom-form-panel > h3 {
  padding-bottom: 12px;
  margin-bottom: 14px;
  border-bottom: 1px solid #edf0f2;
}
.field-help {
  display: block;
  margin-top: 4px;
  color: #8c98a5;
}
.approval-launch-dialog ::v-deep .el-dialog__body {
  padding: 16px 22px 12px;
}
.approval-launch-dialog ::v-deep .el-dialog__footer {
  padding: 12px 22px;
  border-top: 1px solid #e8ecf0;
}
@media (max-width: 1500px) {
  .approval-page {
    grid-template-columns: minmax(0, 1fr) 246px;
    padding: 14px;
  }
  .summary-grid {
    grid-template-columns: repeat(3, 1fr);
  }
  .filter-card {
    grid-template-columns: repeat(4, minmax(130px, 1fr));
  }
  .table-card {
    overflow-x: auto;
  }
  .table-card .el-table {
    min-width: 1320px;
  }
}
@media (max-width: 1100px) {
  .approval-page {
    grid-template-columns: 1fr;
  }
  .approval-aside {
    padding-top: 0;
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 12px;
  }
  .filter-card {
    grid-template-columns: repeat(2, minmax(150px, 1fr));
  }
}
@media (max-width: 960px) {
  .approval-launch-dialog {
    width: calc(100vw - 32px) !important;
  }
  .record-picker header {
    align-items: flex-start;
    gap: 12px;
  }
  .record-picker header .el-input {
    width: 240px;
  }
  .heading-actions {
    flex-wrap: wrap;
    justify-content: flex-end;
  }
  .workbench-tabs {
    overflow-x: auto;
    align-items: stretch;
  }
  .workbench-tabs button {
    flex: 0 0 auto;
  }
}
</style>
