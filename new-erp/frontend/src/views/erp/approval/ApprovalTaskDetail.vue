<template>
  <div class="task-detail" v-loading="loading">
    <header class="task-title">
      <div>
        <div class="crumb">
          审核中心 / {{ backLabel }} / {{ task.task_no || "-" }}
        </div>
        <h1>审核任务详情</h1>
      </div>
      <div>
        <el-button size="small" icon="el-icon-back" @click="$router.back()"
          >返回待审</el-button
        ><el-button
          v-if="task.source_route"
          size="small"
          icon="el-icon-s-order"
          @click="openSource"
          >查看原业务单</el-button
        >
      </div>
    </header>

    <section class="task-meta card">
      <div>
        <span>任务编号</span><b>{{ task.task_no || "-" }}</b>
      </div>
      <div>
        <span>业务类型</span><b>{{ businessText(task.business_type) }}</b>
      </div>
      <div>
        <span>业务单号</span><b>{{ task.business_no || "-" }}</b>
      </div>
      <div>
        <span>审核主题</span><b>{{ task.subject || "-" }}</b>
      </div>
      <div>
        <span>风险等级</span
        ><el-tag size="mini" :type="riskTag(task.risk_level)">{{
          riskText(task.risk_level)
        }}</el-tag>
      </div>
      <div>
        <span>状态</span
        ><el-tag size="mini" :type="statusTag(task.task_status)">{{
          statusText(task.task_status)
        }}</el-tag>
      </div>
      <div>
        <span>提交时间</span><b>{{ time(task.submitted_at) }}</b>
      </div>
    </section>

    <section class="progress-card card">
      <div
        v-for="(step, index) in progressSteps"
        :key="step.key"
        class="progress-step"
        :class="step.state"
      >
        <div class="step-line" v-if="index"><i /></div>
        <div class="step-dot">
          <i :class="step.state === 'done' ? 'el-icon-check' : ''">{{
            step.state === "done" ? "" : index + 1
          }}</i>
        </div>
        <b>{{ step.name }}</b
        ><small>{{ step.caption }}</small>
      </div>
    </section>

    <div class="content-grid">
      <main>
        <section class="card diff-card">
          <h2>{{ diffRows.length ? "变更内容与业务影响" : "申请业务数据" }}</h2>
          <el-table v-if="diffRows.length" :data="diffRows" border size="mini"
            ><el-table-column
              type="index"
              label="序号"
              width="58"
              align="center"
            /><el-table-column
              prop="label"
              label="变更字段"
              min-width="130"
            /><el-table-column label="原值" min-width="150"
              ><template slot-scope="{ row }">{{
                display(row.before)
              }}</template></el-table-column
            ><el-table-column label="新值" min-width="150"
              ><template slot-scope="{ row }">{{
                display(row.after)
              }}</template></el-table-column
            ><el-table-column
              prop="business_impact_text"
              label="业务影响"
              min-width="220"
            /><el-table-column label="审核要求" min-width="150"
              ><template slot-scope="{ row }"
                ><el-tag size="mini" :type="requirementTag(row)">{{
                  requirementText(row)
                }}</el-tag></template
              ></el-table-column
            ></el-table
          >
          <div v-else class="snapshot-grid">
            <div v-for="row in summaryRows" :key="row.key">
              <span>{{ row.label }}</span
              ><b>{{ row.value }}</b>
            </div>
            <el-empty
              v-if="!summaryRows.length"
              description="当前任务没有可展示的业务快照"
              :image-size="72"
            />
          </div>
        </section>

        <section v-if="task.can_decide" class="card opinion-card">
          <h2>审核意见</h2>
          <el-form label-width="100px"
            ><el-form-item label="快速操作" required
              ><el-radio v-model="decision" label="approve">同意</el-radio
              ><el-radio
                v-if="task.can_reject"
                v-model="decision"
                label="reject"
                >驳回</el-radio
              ></el-form-item
            ><el-form-item label="审核意见" required
              ><el-input
                v-model.trim="comment"
                type="textarea"
                :rows="4"
                maxlength="500"
                show-word-limit
                placeholder="请输入审核意见，说明审核结论及理由（必填）" /></el-form-item
            ><el-form-item label="附件（可选）"
              ><el-upload
                action="#"
                :show-file-list="false"
                :http-request="upload"
                ><el-button size="small" icon="el-icon-upload2"
                  >上传附件</el-button
                ></el-upload
              ><span class="upload-tip"
                >支持 pdf、jpg、png、xlsx、docx，单个文件不超过 20MB</span
              >
              <div v-if="attachments.length" class="attachment-list">
                <span v-for="file in attachments" :key="file.id"
                  ><a @click="preview(file)">{{ file.original_name }}</a
                  ><i class="el-icon-close" @click="removeAttachment(file)"
                /></span></div></el-form-item></el-form
          ><el-alert
            :title="
              task.can_reject
                ? '注意：审核决策一旦提交不可撤销，请谨慎操作。'
                : '当前节点只允许通过，不允许驳回。'
            "
            type="warning"
            show-icon
            :closable="false"
          />
        </section>
        <section v-else class="card opinion-card readonly-result">
          <h2>审核结果</h2>
          <div class="readonly-result__body">
            <el-result
              :icon="
                task.task_status === 'APPROVED'
                  ? 'success'
                  : task.task_status === 'REJECTED'
                  ? 'error'
                  : 'info'
              "
              :title="statusText(task.task_status)"
              :sub-title="readonlyResultSubtitle"
            />
          </div>
        </section>
      </main>

      <aside>
        <section class="card side-card">
          <h2>业务对象摘要</h2>
          <dl>
            <dt>流程名称</dt>
            <dd>{{ flowName }}</dd>
            <dt>业务类型</dt>
            <dd>{{ businessText(task.business_type) }}</dd>
            <dt>业务单号</dt>
            <dd>{{ task.business_no || "-" }}</dd>
            <dt>数据来源</dt>
            <dd>{{ sourceTable }}</dd>
          </dl>
          <el-button v-if="task.source_route" type="text" @click="openSource"
            >查看原业务记录 <i class="el-icon-top-right"
          /></el-button>
        </section>
        <section class="card side-card">
          <h2>当前节点与权限</h2>
          <dl>
            <dt>当前节点</dt>
            <dd>{{ currentNode.node_name || "流程已结束" }}</dd>
            <dt>审核角色</dt>
            <dd>
              {{
                (currentNode.approver_rule &&
                  currentNode.approver_rule.label) ||
                permissionText(currentNode.permission_code)
              }}
            </dd>
            <dt>SLA 剩余时间</dt>
            <dd class="orange">{{ dueText(currentNode.due_at) }}</dd>
            <dt>是否可驳回</dt>
            <dd>{{ task.can_reject ? "是" : "否" }}</dd>
            <dt>是否可转交</dt>
            <dd>{{ task.can_transfer ? "是" : "否" }}</dd>
            <dt>是否可自审</dt>
            <dd>{{ allowSelf ? "是" : "否" }}</dd>
            <dt>是否允许重入决策</dt>
            <dd>否</dd>
          </dl>
        </section>
        <section class="card side-card history">
          <h2>审核记录</h2>
          <div
            v-for="record in decisionHistory"
            :key="`decision-${record.id}`"
            class="history-row"
          >
            <i :class="record.decision === 'APPROVE' ? 'el-icon-success' : 'el-icon-error'" />
            <div>
              <b>{{ record.node_name }} · 第{{ record.round_no }}轮 · {{ record.decision === "APPROVE" ? "通过" : "驳回" }}</b>
              <span>{{ record.comment || "未填写审核意见" }}</span>
              <span>{{ record.approver_name || "系统" }}　{{ time(record.decided_at) }}</span>
            </div>
          </div>
          <div v-for="log in task.logs || []" :key="log.id" class="history-row">
            <i :class="logIcon(log.action)" />
            <div>
              <b>{{ log.content || actionText(log.action) }}</b
              ><span
                >{{ log.operator_name || "系统" }}　{{
                  time(log.operated_at)
                }}</span
              >
            </div>
          </div>
          <div v-if="!decisionHistory.length && !(task.logs || []).length" class="empty">暂无审核记录</div>
        </section>
      </aside>
    </div>

    <footer v-if="task.can_decide || task.action_status === 'FAILED'" class="fixed-actions">
      <p>
        <i class="el-icon-info" />
        通过全部必需节点后执行流程中配置的业务动作；审核任务始终保留版本快照和操作记录。
      </p>
      <div>
        <el-button v-if="task.action_status === 'FAILED' && !task.can_decide" size="medium" type="danger" @click="retryAction">重试失败动作</el-button>
        <template v-else-if="task.can_decide">
        <el-button v-if="task.can_transfer" size="medium" @click="transferDialog.visible = true">转交</el-button>
        <el-button
          v-if="task.can_reject"
          size="medium"
          @click="submit('reject')"
          >驳回</el-button
        ><el-button size="medium" type="success" @click="submit('approve')"
          >通过当前节点</el-button
        >
        </template>
      </div>
    </footer>
    <el-dialog title="转交审核任务" :visible.sync="transferDialog.visible" width="520px" append-to-body>
      <el-form label-width="90px" size="small">
        <el-form-item label="原处理人" required><el-select v-model="transferDialog.source_assignee_id" placeholder="选择被替换的当前待处理人"><el-option v-for="row in pendingAssignees" :key="row.id" :label="row.user_name" :value="row.id" /></el-select></el-form-item>
        <el-form-item label="转交给" required><el-select v-model="transferDialog.target_user_id" filterable placeholder="选择启用账号"><el-option v-for="user in users" :key="user.value" :label="user.label" :value="user.value" /></el-select></el-form-item>
        <el-form-item label="转交原因" required><el-input v-model.trim="transferDialog.reason" type="textarea" :rows="4" maxlength="500" show-word-limit /></el-form-item>
      </el-form>
      <span slot="footer"><el-button @click="transferDialog.visible = false">取消</el-button><el-button type="success" @click="confirmTransfer">确认转交</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import {
  deleteApprovalAttachment,
  decideApprovalTask,
  getApprovalTask,
  getApprovalFlowConfigOptions,
  previewApprovalAttachment,
  uploadApprovalAttachment,
  transferApprovalTask,
  retryApprovalTaskAction,
} from "@/api/erp/approval";
export default {
  name: "ApprovalTaskDetail",
  data: () => ({ loading: false, task: {}, decision: "approve", comment: "", users: [], transferDialog: { visible: false, source_assignee_id: null, target_user_id: null, reason: "" } }),
  computed: {
    pendingAssignees() { return (((this.task || {}).current_node || {}).assignees || []).filter(row => row.status === "PENDING"); },
    decisionHistory() {
      return (this.task.nodes || [])
        .flatMap((node) => (node.decisions || []).map((decision) => ({
          ...decision,
          node_name: node.node_name || node.node_key || "审核节点",
          round_no: decision.round_no || 1,
        })))
        .sort((left, right) => new Date(right.decided_at || 0) - new Date(left.decided_at || 0));
    },
    currentNode() {
      return this.task.current_node || {};
    },
    attachments() {
      return this.task.attachments || [];
    },
    diffRows() {
      return Array.isArray(this.task.diff_snapshot)
        ? this.task.diff_snapshot
        : [];
    },
    allowSelf() {
      return Boolean(
        this.task.flow_snapshot &&
          this.task.flow_snapshot.definition &&
          this.task.flow_snapshot.definition.allow_self_approval
      );
    },
    readonlyResultSubtitle() {
      return this.task.task_status === "PENDING"
        ? "当前账号不可处理本节点，请由具备对应审核权限且非发起人的审核人处理。"
        : "该任务已完成决策，仅保留审核结果、进度与操作记录供追溯。";
    },
    backLabel() {
      return this.task.task_status === "PENDING" ? "我的待审" : "审核记录";
    },
    flowName() {
      return (
        (this.task.flow_snapshot && this.task.flow_snapshot.flow_name) || "-"
      );
    },
    sourceTable() {
      return (this.task.metadata && this.task.metadata.source_table) || "-";
    },
    summaryRows() {
      const snapshot = this.task.business_snapshot || {};
      const formFieldLabels = snapshot.form_field_labels || {};
      const skipped = [
        "id",
        "created_at",
        "updated_at",
        "deleted_at",
        "delete_time",
        "legacy_payload",
        "form_template_id",
        "form_code",
        "form_field_labels",
      ];
      const labels = {
        supplier_code: "供应商编码",
        supplier_name: "供应商名称",
        short_name: "简称",
        status: "状态",
        customer_name: "客户",
        sales_owner: "销售负责人",
        order_amount: "订单金额",
        required_delivery_date: "计划交货日期",
        submission_no: "申请单号",
        form_name: "表单名称",
      };
      return Object.entries(snapshot)
        .filter(
          ([key, value]) =>
            !skipped.includes(key) &&
            value !== null &&
            value !== "" &&
            typeof value !== "object"
        )
        .slice(0, 12)
        .map(([key, value]) => ({
          key,
          label: formFieldLabels[key] || labels[key] || key.replace(/_/g, " "),
          value: this.display(value),
        }));
    },
    progressSteps() {
      const nodes = this.task.nodes || [];
      const steps = [
        { key: "submit", name: "发起提交", state: "done", caption: "已完成" },
        ...nodes.map((n) => ({
          key: n.node_key,
          name: n.node_name,
          state:
            n.node_status === "APPROVED"
              ? "done"
              : n.node_status === "PENDING"
              ? "active"
              : ["REJECTED", "SKIPPED"].includes(n.node_status)
              ? "failed"
              : "wait",
          caption:
            {
              APPROVED: "已通过",
              PENDING: "当前节点",
              REJECTED: "已驳回",
              SKIPPED: "已跳过",
              WAITING: "等待中",
            }[n.node_status] || n.node_status,
        })),
      ];
      steps.push({
        key: "finish",
        name: "审核完成",
        state:
          this.task.task_status === "APPROVED"
            ? "done"
            : this.task.task_status === "REJECTED"
            ? "failed"
            : "wait",
        caption:
          this.task.task_status === "APPROVED"
            ? "已完成"
            : this.task.task_status === "REJECTED"
            ? "已终止"
            : "等待中",
      });
      return steps;
    },
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      this.loading = true;
      try {
        const response = await getApprovalTask(this.$route.params.id);
        this.task = (response && response.data && response.data.data) ||
          (response && response.data) || {};
        if (!this.users.length) {
          const options = await getApprovalFlowConfigOptions();
          const optionPayload = (options && options.data && options.data.data) ||
            (options && options.data) || {};
          this.users = optionPayload.users || [];
        }
      } catch (error) {
        this.task = {};
        this.$message.error(error.userMessage || "审核任务加载失败，请刷新后重试");
      } finally {
        this.loading = false;
      }
    },
    async submit(decision) {
      if (!this.comment.trim()) return this.$message.warning("请填写审核意见");
      try {
        await this.$confirm(
          decision === "approve"
            ? "确认通过当前审核节点？"
            : "确认驳回该审核任务？审核决策不可撤销。",
          "提交审核决策",
          { type: decision === "approve" ? "warning" : "error" }
        );
        await decideApprovalTask(this.task.id, {
          decision,
          comment: this.comment,
        });
        this.$message.success(
          decision === "approve" ? "当前节点已通过" : "审核任务已驳回"
        );
        this.comment = "";
        await this.load();
      } catch (e) {
        if (e !== "cancel" && e !== "close" && e.userMessage)
          this.$message.error(e.userMessage);
      }
    },
    async confirmTransfer() {
      if (!this.transferDialog.source_assignee_id || !this.transferDialog.target_user_id || !this.transferDialog.reason) return this.$message.warning("请选择原处理人、目标处理人并填写转交原因");
      await transferApprovalTask(this.task.id, { source_assignee_id: this.transferDialog.source_assignee_id, target_user_id: this.transferDialog.target_user_id, reason: this.transferDialog.reason });
      this.$message.success("审核任务已转交");
      this.transferDialog = { visible: false, source_assignee_id: null, target_user_id: null, reason: "" };
      await this.load();
    },
    async retryAction() {
      await this.$confirm("确认重新执行失败的业务动作？", "重试业务动作", { type: "warning" });
      await retryApprovalTaskAction(this.task.id);
      this.$message.success("业务动作已重新执行");
      await this.load();
    },
    async upload({ file }) {
      const form = new FormData();
      form.append("file", file);
      await uploadApprovalAttachment(this.task.id, form);
      this.$message.success("附件已上传");
      await this.load();
    },
    async preview(file) {
      const { data } = await previewApprovalAttachment(file.id);
      window.open(URL.createObjectURL(data), "_blank");
    },
    async removeAttachment(file) {
      await this.$confirm(`确认删除附件“${file.original_name}”？`, "删除附件", {
        type: "warning",
      });
      await deleteApprovalAttachment(file.id);
      await this.load();
    },
    openSource() {
      if (this.task.source_route) this.$router.push(this.task.source_route);
    },
    businessText(v) {
      return this.flowName !== "-"
        ? this.flowName
        : { SALES_ORDER_CHANGE: "销售订单变更" }[v] || v || "-";
    },
    riskText(v) {
      return { high: "高", medium: "中", low: "低" }[v] || v || "-";
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
        }[v] ||
        v ||
        "-"
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
    display(v) {
      if (v == null || v === "") return "—";
      return typeof v === "object" ? JSON.stringify(v) : String(v);
    },
    requirementText(row) {
      const req = row.approval_requirements || [];
      if (req.includes("finance")) return "高风险，重点审核";
      if (req.includes("fulfillment")) return "履约复核";
      return "业务确认";
    },
    requirementTag(row) {
      return (row.approval_requirements || []).includes("finance")
        ? "danger"
        : (row.approval_requirements || []).includes("fulfillment")
        ? "warning"
        : "success";
    },
    permissionText(v) {
      return (
        {
          "sales_order.change.approve_business": "销售负责人 / 管理员",
          "sales_order.change.approve_finance": "财务负责人",
          "sales_order.change.approve_fulfillment": "履约负责人",
        }[v] ||
        v ||
        "-"
      );
    },
    time(v) {
      return v
        ? String(v)
            .replace("T", " ")
            .replace(/\.\d+Z$/, "")
            .slice(0, 16)
        : "-";
    },
    dueText(v) {
      if (!v) return "-";
      const ms = new Date(v).getTime() - Date.now();
      if (ms <= 0) return "已超时";
      const m = Math.floor(ms / 60000);
      return `${Math.floor(m / 60)}小时${m % 60}分`;
    },
    actionText(v) {
      return (
        {
          submitted: "发起提交",
          node_started: "进入审核节点",
          node_approved: "节点通过",
          node_rejected: "节点驳回",
          task_completed: "审核完成",
        }[v] || v
      );
    },
    logIcon(v) {
      return v.includes("reject")
        ? "el-icon-circle-close danger"
        : v.includes("approve") || v === "task_completed"
        ? "el-icon-circle-check success"
        : "el-icon-time info";
    },
  },
};
</script>

<style scoped>
.task-detail {
  min-height: calc(100vh - 52px);
  padding: 14px 18px 78px;
  background: #f7f8fa;
  color: #172535;
}
.task-title {
  height: 72px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.crumb {
  font-size: 13px;
  margin-bottom: 12px;
}
.task-title h1 {
  font-size: 22px;
  margin: 0;
}
.card {
  background: #fff;
  border: 1px solid #e2e7ec;
  border-radius: 6px;
}
.task-meta {
  display: grid;
  grid-template-columns: repeat(7, 1fr);
  padding: 17px 20px;
  margin-bottom: 12px;
}
.task-meta span {
  display: block;
  color: #788594;
  font-size: 12px;
  margin-bottom: 9px;
}
.task-meta b {
  font-size: 15px;
}
.progress-card {
  height: 116px;
  padding: 22px 38px;
  display: flex;
  margin-bottom: 12px;
}
.progress-step {
  position: relative;
  flex: 1;
  text-align: center;
}
.step-line {
  position: absolute;
  right: 50%;
  left: -50%;
  top: 13px;
  height: 3px;
  background: #dfe4e9;
}
.step-line i {
  display: block;
  width: 100%;
  height: 100%;
  background: transparent;
}
.progress-step.done .step-line,
.progress-step.active .step-line {
  background: #1cab65;
}
.step-dot {
  position: relative;
  z-index: 1;
  margin: auto;
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 1px solid #b9c3ce;
  background: #fff;
  display: grid;
  place-items: center;
}
.step-dot i {
  font-style: normal;
}
.done .step-dot {
  background: #11a760;
  color: #fff;
  border-color: #11a760;
}
.active .step-dot {
  color: #09964f;
  border: 2px solid #14ae67;
}
.failed .step-dot {
  color: #e55353;
  border-color: #e55353;
}
.progress-step b,
.progress-step small {
  display: block;
}
.progress-step b {
  font-size: 13px;
  margin-top: 10px;
}
.progress-step small {
  font-size: 12px;
  color: #7d8995;
  margin-top: 5px;
}
.content-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) 310px;
  gap: 12px;
}
.content-grid main,
.content-grid aside {
  min-width: 0;
}
.content-grid .card {
  margin-bottom: 12px;
}
.card h2 {
  font-size: 15px;
  margin: 0;
  padding: 13px 16px;
  border-bottom: 1px solid #e6eaee;
}
.diff-card {
  padding-bottom: 12px;
}
.diff-card .el-table {
  width: calc(100% - 24px);
  margin: 0 12px;
}
.diff-card ::v-deep th {
  background: #f7f9fb;
}
.opinion-card {
  padding-bottom: 14px;
}
.opinion-card .el-form {
  padding: 15px 18px 0;
}
.upload-tip {
  font-size: 12px;
  color: #8995a1;
  margin-left: 12px;
}
.attachment-list {
  margin-top: 9px;
}
.attachment-list span {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  margin: 0 9px 5px 0;
  padding: 4px 8px;
  background: #f4f7fa;
  border-radius: 3px;
}
.attachment-list a {
  color: #1681e8;
  cursor: pointer;
}
.attachment-list i {
  cursor: pointer;
  color: #e55353;
}
.side-card {
  padding-bottom: 12px;
}
.side-card dl {
  display: grid;
  grid-template-columns: 110px 1fr;
  gap: 10px;
  margin: 14px 16px;
  font-size: 12px;
}
.side-card dt {
  color: #758290;
}
.side-card dd {
  margin: 0;
  text-align: right;
}
.side-card > .el-button {
  margin-left: 16px;
}
.orange {
  color: #ec8d17 !important;
}
.history {
  padding-bottom: 13px;
}
.history-row {
  display: flex;
  gap: 9px;
  margin: 0 14px;
  padding: 9px 0;
  border-bottom: 1px solid #edf0f3;
}
.history-row > i {
  font-size: 17px;
}
.history-row b,
.history-row span {
  display: block;
}
.history-row b {
  font-size: 12px;
  font-weight: 500;
}
.history-row span {
  margin-top: 5px;
  color: #8a96a2;
  font-size: 11px;
}
.success {
  color: #12a35d;
}
.danger {
  color: #e44f4f;
}
.info {
  color: #498bd8;
}
.empty {
  padding: 16px;
  text-align: center;
  color: #9aa6b2;
}
.fixed-actions {
  position: fixed;
  z-index: 9;
  bottom: 0;
  right: 0;
  left: var(--erp-sidebar-width, 190px);
  height: 64px;
  padding: 0 24px;
  background: #fff;
  border-top: 1px solid #dfe5ea;
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.fixed-actions p {
  font-size: 12px;
  color: #627184;
}
.fixed-actions i {
  margin-right: 8px;
}
@media (max-width: 1500px) {
  .task-meta {
    grid-template-columns: repeat(4, 1fr);
    gap: 14px;
  }
  .progress-card {
    padding-inline: 18px;
  }
  .content-grid {
    grid-template-columns: minmax(0, 1fr) 280px;
  }
}
@media (max-width: 1100px) {
  .content-grid {
    grid-template-columns: 1fr;
  }
  .task-meta {
    grid-template-columns: repeat(2, 1fr);
  }
  .fixed-actions {
    left: 64px;
  }
  .progress-card {
    overflow-x: auto;
  }
  .progress-step {
    min-width: 130px;
  }
}
.readonly-result__body {
  min-height: 220px;
  display: grid;
  place-items: center;
}
.readonly-result ::v-deep .el-result {
  padding: 18px 30px;
}
.readonly-result ::v-deep .el-result__icon svg {
  width: 52px;
  height: 52px;
}
.readonly-result ::v-deep .el-result__title p {
  font-size: 18px;
  color: #172535;
}
.snapshot-grid {
  display: grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap: 0;
  margin: 0 12px 12px;
  border: 1px solid #e5e9ed;
  border-right: 0;
  border-bottom: 0;
}
.snapshot-grid > div {
  min-height: 66px;
  padding: 11px 13px;
  border-right: 1px solid #e5e9ed;
  border-bottom: 1px solid #e5e9ed;
}
.snapshot-grid span,
.snapshot-grid b {
  display: block;
}
.snapshot-grid span {
  margin-bottom: 7px;
  color: #788594;
  font-size: 12px;
}
.snapshot-grid b {
  font-size: 13px;
  line-height: 1.5;
  word-break: break-word;
}
.snapshot-grid .el-empty {
  grid-column: 1/-1;
  border-right: 1px solid #e5e9ed;
  border-bottom: 1px solid #e5e9ed;
}
@media (max-width: 1200px) {
  .snapshot-grid {
    grid-template-columns: repeat(2, minmax(0, 1fr));
  }
}
</style>
