<template>
  <div class="flow-form" v-loading="loading">
    <header>
      <div class="title">
        <h1>{{ isNew ? "新增" : "编辑" }}审核流程</h1>
      </div>
      <div>
        <el-button
          size="small"
          icon="el-icon-back"
          @click="$router.push('/approvals/flows')"
          >返回列表</el-button
        ><el-button size="small" :disabled="readonly" @click="save"
          >保存草稿</el-button
        ><el-button size="small" @click="validate">校验流程</el-button
        ><el-button
          size="small"
          type="success"
          :disabled="readonly"
          @click="publish"
          >发布新版本</el-button
        >
      </div>
    </header>
    <el-alert
      title="发布新版本将创建不可变更的新版本；正在运行的任务继续使用原版本快照，不受新版本影响。"
      type="info"
      show-icon
      :closable="false"
    />

    <div class="editor-grid">
      <aside class="base card">
        <h2>流程基本信息</h2>
        <el-form label-position="top" size="small">
          <el-form-item label="流程编码"
            ><el-input
              v-model.trim="form.flow_code"
              :disabled="!isNew || readonly"
              placeholder="例如 PURCHASE_ORDER_APPROVAL"
          /></el-form-item>
          <el-form-item label="流程名称"
            ><el-input
              v-model.trim="form.flow_name"
              :disabled="readonly"
              placeholder="请输入流程名称"
          /></el-form-item>
          <el-form-item label="业务模块"
            ><el-select
              v-model="form.business_module"
              :disabled="readonly"
              placeholder="请选择业务模块"
              ><el-option
                v-for="item in options.flow_categories"
                :key="item.value"
                :label="item.label"
                :value="item.value" /></el-select
          ></el-form-item>
          <el-form-item label="审核对象来源"
            ><el-radio-group
              v-model="definition.source_mode"
              size="mini"
              :disabled="readonly"
              @change="sourceModeChanged"
              ><el-radio-button label="existing">现有ERP单据</el-radio-button
              ><el-radio-button label="custom_form"
                >自定义表单</el-radio-button
              ></el-radio-group
            ></el-form-item
          >
          <template v-if="definition.source_mode === 'existing'">
            <el-form-item label="审核业务对象"
              ><div class="object-select-row"><el-select
                  v-model="definition.business_object_code"
                  filterable
                  :disabled="readonly"
                  placeholder="请选择业务单据"
                  @change="businessObjectChanged"
                  ><el-option
                    v-for="item in options.business_objects"
                    :key="item.code"
                    :label="item.label"
                    :value="item.code" /></el-select
                ><el-button v-if="!readonly" size="mini" icon="el-icon-plus" @click="openRegistryDialog">登记</el-button></div
              ><div class="object-links"><el-button type="text" size="mini" @click="showObjectFields"
                  >查看对象字段</el-button
                ><span v-if="!options.business_objects.length">当前没有已登记对象，请先从真实数据表登记</span></div
              ></el-form-item
            >
            <el-form-item label="触发动作">
              <el-input :value="currentTriggerActionLabel" disabled />
              <div class="field-help">由所选审核业务对象自动提供，无需在流程中重复配置。</div>
            </el-form-item>
          </template>
          <template v-else>
            <el-form-item label="审核申请表单"
              ><el-select
                v-model="definition.form_template_id"
                filterable
                :disabled="readonly"
                placeholder="请先选择已建表单"
                ><el-option
                  v-for="item in options.custom_forms"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value" /></el-select
              ><el-button type="text" size="mini" @click="openFormDesigner"
                >+ 新建表单</el-button
              ></el-form-item
            >
          </template>
          <div class="two compact-two">
            <el-form-item label="触发模式"><el-select v-model="definition.trigger_mode" :disabled="readonly"><el-option v-for="item in options.trigger_modes" :key="item.value" :label="item.label" :value="item.value" /></el-select></el-form-item>
            <el-form-item label="业务阻断模式"><el-select v-model="definition.execution_mode" :disabled="readonly"><el-option v-for="item in options.execution_modes" :key="item.value" :label="item.label" :value="item.value" /></el-select></el-form-item>
          </div>
          <div class="two compact-two">
            <el-form-item label="流程优先级"><el-input-number v-model="definition.priority" :min="0" :max="9999" controls-position="right" :disabled="readonly" /></el-form-item>
            <el-form-item label="匹配策略"><el-select v-model="definition.match_strategy" disabled><el-option label="命中首个流程" value="FIRST_MATCH" /></el-select></el-form-item>
          </div>
          <el-form-item label="适用组织"
            ><el-select v-model="scope.type" :disabled="readonly"
              ><el-option
                v-for="item in options.scope_types"
                :key="item.value"
                :label="item.label"
                :value="item.value" /></el-select
          ></el-form-item>
          <el-form-item v-if="scope.type === 'departments'" label="指定部门"
            ><el-select
              v-model="scope.department_ids"
              multiple
              filterable
              collapse-tags
              :disabled="readonly"
              ><el-option
                v-for="item in options.departments"
                :key="item.value"
                :label="item.label"
                :value="item.value" /></el-select
          ></el-form-item>
          <el-form-item label="启用状态"
            ><el-tag
              size="mini"
              :type="flow.status === 'enabled' ? 'success' : 'warning'"
              >{{ flow.status === "enabled" ? "启用中" : "草稿/停用" }}</el-tag
            ></el-form-item
          >
        </el-form>
      </aside>

      <main class="canvas card">
        <h2>条件与节点编排</h2>
        <div class="start"><i class="el-icon-video-play" /> 发起提交</div>
        <section class="start-condition-box">
          <div class="node-head"><b><em>0</em>流程启动条件</b><span>不满足时 BYPASS，不创建审核任务</span></div>
          <approval-condition-builder v-model="definition.start_conditions" :fields="conditionFields" :operators="options.operators" :disabled="readonly" />
        </section>
        <template v-for="(node, index) in definition.nodes"
          ><div :key="node.key + 'line'" class="arrow">↓</div>
          <article
            :key="node.key"
            :class="['node', { selected: selectedIndex === index }]"
            @click="selectedIndex = index"
          >
            <div class="node-head">
              <b
                ><em>{{ index + 1 }}</em
                >{{ node.name || "未命名节点" }}</b
              ><span v-if="!readonly"
                ><a @click.stop="selectedIndex = index">编辑节点</a
                ><a @click.stop="copyNode(index)">复制</a
                ><a class="danger" @click.stop="removeNode(index)"
                  >删除</a
                ></span
              >
            </div>
            <div class="node-info">
              <span><b>条件：</b>{{ conditionText(node) }}</span
              ><span
                ><b>处理策略：</b
                >{{
                  node.processing_strategy === "parallel"
                    ? "全部通过"
                    : "任一通过"
                }}</span
              ><span><b>处理人：</b>{{ approverText(node) }}</span
              ><span><b>SLA：</b>{{ node.sla_hours || "-" }}小时</span>
            </div>
            <div class="node-info sub">
              <span
                >允许驳回：{{ node.allow_reject === false ? "否" : "是" }}</span
              ><span
                >允许转交：{{
                  node.allow_transfer === false ? "否" : "是"
                }}</span
              ><span
                >审核意见必填：{{
                  node.comment_required === false ? "否" : "是"
                }}</span
              >
            </div>
          </article>
          <div
            :key="node.key + 'actions'"
            class="insert-actions"
            v-if="!readonly"
          >
            <el-button
              size="mini"
              icon="el-icon-plus"
              @click="addCondition(index)"
              >添加条件分支</el-button
            ><el-button
              size="mini"
              icon="el-icon-plus"
              @click="addNode(index + 1)"
              >添加审核节点</el-button
            >
          </div></template
        >
        <div class="arrow">↓</div>
        <div class="finish"><i class="el-icon-success" /> 审核完成</div>
      </main>

      <aside class="config card">
        <h2>当前节点配置</h2>
        <template v-if="selected"
          ><el-form label-position="top" size="small"
            ><el-form-item label="节点名称"
              ><el-input v-model.trim="selected.name" :disabled="readonly"
            /></el-form-item>
            <el-form-item label="节点类型"><el-select v-model="selected.node_type" :disabled="readonly"><el-option v-for="item in options.node_types" :key="item.value" :label="item.label" :value="item.value" /></el-select></el-form-item>
            <h3>节点进入条件</h3>
            <p class="section-help">
              条件不满足时该节点记为 SKIPPED，不影响流程启动。
            </p>
            <approval-condition-builder v-model="selected.entry_conditions" :fields="conditionFields" :operators="options.operators" :disabled="readonly" />
            <template v-if="selected.node_type === 'ACTION'">
              <h3>节点业务动作</h3>
              <el-form-item label="已注册动作"><el-select v-model="selected.action_code" filterable :disabled="readonly" placeholder="请选择该业务对象允许的节点动作"><el-option v-for="item in nodeActionOptions" :key="item.key" :label="item.label" :value="item.key" /></el-select></el-form-item>
              <el-alert :closable="false" type="warning" title="动作只能来自业务对象 Registry；页面不能填写 PHP 类、URL、SQL 或脚本。" />
            </template>
            <template v-if="['APPROVAL','CC'].includes(selected.node_type)">
            <h3>处理人来源</h3>
            <el-radio-group v-model="approverRuleMode" class="approver-rule-tabs" size="small" :disabled="readonly">
              <el-radio-button label="role">角色</el-radio-button>
              <el-radio-button label="department_principal">部门负责人</el-radio-button>
              <el-radio-button label="specified_users">指定人</el-radio-button>
              <el-radio-button label="expression">表达式</el-radio-button>
            </el-radio-group>
            <el-form-item
              v-if="selected.approver_rule.type === 'role'"
              label="角色"
              ><el-select
                v-model="selected.approver_rule.value"
                filterable
                :disabled="readonly"
                @change="syncApproverLabel"
                ><el-option
                  v-for="item in options.roles"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value" /></el-select
            ></el-form-item>
            <el-form-item
              v-else-if="selected.approver_rule.type === 'department_principal'"
              label="部门负责人"
              ><el-select
                v-model="selected.approver_rule.value"
                filterable
                :disabled="readonly"
                @change="syncApproverLabel"
                ><el-option
                  label="发起人所属部门负责人"
                  value="task_department" /><el-option
                  v-for="item in options.departments"
                  :key="item.value"
                  :label="item.label + '负责人'"
                  :value="String(item.value)" /></el-select
            ></el-form-item>
            <el-form-item v-else-if="selected.approver_rule.type === 'specified_users'" label="指定人员"
              ><el-select
                v-model="selected.approver_rule.value"
                multiple
                filterable
                collapse-tags
                :disabled="readonly"
                @change="syncApproverLabel"
                ><el-option
                  v-for="item in options.users"
                  :key="item.value"
                  :label="item.label"
                  :value="item.value" /></el-select
            ></el-form-item>
            <template v-else>
              <el-form-item label="动态处理人规则">
                <el-select v-model="selected.approver_rule.type" :disabled="readonly" @change="dynamicApproverChanged">
                  <el-option label="发起人的直属负责人" value="initiator_manager" />
                  <el-option label="发起人所属部门负责人" value="initiator_department_manager" />
                  <el-option label="业务单据负责人" value="business_record_owner" />
                  <el-option label="业务单据负责人所属部门负责人" value="business_record_department_manager" />
                  <el-option label="从业务字段读取指定人员" value="field_user" />
                  <el-option label="从业务字段读取人员的负责人" value="field_user_manager" />
                  <el-option label="从业务字段读取部门负责人" value="field_department_manager" />
                </el-select>
              </el-form-item>
              <el-form-item v-if="dynamicApproverNeedsField" label="来源字段">
                <el-select v-model="selected.approver_rule.field" filterable :disabled="readonly" @change="syncApproverLabel"><el-option v-for="item in assigneeFields" :key="item.value" :label="item.label" :value="item.value" /></el-select>
              </el-form-item>
              <el-alert :closable="false" type="info" title="表达式仅从已登记的业务字段和组织关系中解析处理人，不支持手填代码或脚本。" />
            </template>
            <div v-if="selected.node_type === 'APPROVAL'" class="two">
              <el-form-item label="会签策略"
                ><el-select
                  v-model="selected.completion_strategy"
                  :disabled="readonly"
                  ><el-option
                    label="任一指定人员通过"
                    value="ANY" /><el-option
                    label="全部指定人员通过"
                    value="ALL" /><el-option label="指定人数通过" value="COUNT" /><el-option label="指定比例通过" value="RATIO" /></el-select></el-form-item
              ><el-form-item label="SLA（小时）"
                ><el-input-number
                  v-model="selected.sla_hours"
                  :min="1"
                  :max="720"
                  controls-position="right"
                  :disabled="readonly"
              /></el-form-item>
            </div>
            <div v-if="selected.node_type === 'APPROVAL' && selected.completion_strategy === 'COUNT'" class="two"><el-form-item label="需通过人数"><el-input-number v-model="selected.required_approver_count" :min="1" :max="999" :disabled="readonly" /></el-form-item></div>
            <div v-if="selected.node_type === 'APPROVAL' && selected.completion_strategy === 'RATIO'" class="two"><el-form-item label="需通过比例"><el-input-number v-model="selected.required_approver_ratio" :min="0.01" :max="1" :step="0.01" :precision="2" :disabled="readonly" /></el-form-item></div>
            <div v-if="selected.node_type === 'APPROVAL'" class="two">
              <el-form-item label="超时提醒"
                ><el-checkbox v-model="selected.reminder_enabled" :disabled="readonly">启用催办</el-checkbox><el-input-number v-if="selected.reminder_enabled" v-model="selected.reminder_hours" :min="1" :max="168" :disabled="readonly" /></el-form-item
              ><el-form-item label="驳回策略"
                ><el-select
                  v-model="selected.reject_strategy"
                  :disabled="readonly || !selected.allow_reject"
                  ><el-option label="退回上一节点" value="RETURN_PREVIOUS" /><el-option
                    label="终止流程"
                    value="TERMINATE" /></el-select
              ></el-form-item>
            </div>
            <div v-if="selected.node_type === 'APPROVAL'" class="node-switches">
              <label
                ><span>允许驳回</span
                ><el-radio-group
                  v-model="selected.allow_reject"
                  size="mini"
                  :disabled="readonly"
                  ><el-radio-button :label="true">是</el-radio-button
                  ><el-radio-button :label="false"
                    >否</el-radio-button
                  ></el-radio-group
                ></label
              ><label
                ><span>允许转交</span
                ><el-radio-group
                  v-model="selected.allow_transfer"
                  size="mini"
                  :disabled="readonly"
                  ><el-radio-button :label="true">是</el-radio-button
                  ><el-radio-button :label="false"
                    >否</el-radio-button
                  ></el-radio-group
                ></label
              ><el-checkbox
                v-model="selected.comment_required"
                :disabled="readonly"
                >要求填写审核意见</el-checkbox
              >
            </div>
            </template>
          </el-form></template
        ><el-empty v-else :image-size="50" description="请选择审核节点" />
      </aside>
    </div>

    <div class="bottom-grid">
      <section class="card actions">
        <h2><i class="el-icon-finished" /> 业务处理动作</h2>
        <p>只可选择已注册的安全业务动作，不支持填写URL或Controller。</p>
        <el-table :data="definition.completion_actions" size="mini"
          ><el-table-column label="触发场景" width="95"
            ><template slot-scope="{ row }">{{
              eventText(row.event)
            }}</template></el-table-column
          ><el-table-column label="动作名称"
            ><template slot-scope="{ row }">{{
              actionText(row.action_key)
            }}</template></el-table-column
          ><el-table-column label="操作" width="62"
            ><template slot-scope="{ row }"
              ><el-button
                type="text"
                size="mini"
                :disabled="readonly"
                @click="configureAction(row)"
                >配置</el-button
              ></template
            ></el-table-column
          ></el-table
        ><el-button
          v-if="!readonly"
          size="mini"
          icon="el-icon-plus"
          @click="addCompletionAction"
          >添加处理动作</el-button
        >
      </section>
      <section class="card notifications">
        <h2><i class="el-icon-bell" /> 通知事件</h2>
        <el-checkbox
          v-model="definition.notifications.websocket"
          :disabled="readonly"
          >WebSocket 实时推送</el-checkbox
        >
        <p>通过 WebSocket 推送状态变更。</p>
        <el-checkbox
          v-model="definition.notifications.in_app"
          :disabled="readonly"
          >站内通知</el-checkbox
        >
        <p>发送站内消息通知相关人员。</p>
      </section>
      <section class="card check">
        <h2><i class="el-icon-circle-check" /> 发布前校验</h2>
        <p v-for="item in validationItems" :key="item.text">
          <i
            :class="
              item.ok
                ? 'el-icon-circle-check success'
                : 'el-icon-warning warning'
            "
          />{{ item.text }}<b>{{ item.ok ? "通过" : "待处理" }}</b>
        </p>
      </section>
    </div>

    <el-dialog
      title="配置业务处理动作"
      :visible.sync="actionDialog.visible"
      width="620px"
      :close-on-click-modal="false"
      ><el-form label-position="top" size="small"
        ><el-form-item label="触发场景"
          ><el-input
            :value="eventText(actionDialog.row.event)"
            disabled /></el-form-item
        ><el-form-item label="处理动作"
          ><el-select
            v-model="actionDialog.row.action_key"
            style="width: 100%"
            @change="actionChanged"
            ><el-option
              v-for="item in availableActions(actionDialog.row.event)"
              :key="item.key"
              :label="item.label"
              :value="item.key" /></el-select
        ></el-form-item>
        <template v-if="currentActionNeedsUpdates">
          <div class="action-rule-head">
            <b>业务字段更新规则</b>
            <el-button
              type="text"
              size="mini"
              icon="el-icon-plus"
              @click="addActionUpdate"
              >添加字段</el-button
            >
          </div>
          <div
            v-for="(update, index) in actionUpdates"
            :key="index"
            class="action-update-row"
          >
            <el-select
              v-model="update.field"
              filterable
              placeholder="选择要更新的数据库字段"
              @change="actionUpdateFieldChanged(update)"
            >
              <el-option
                v-for="field in actionUpdateFields"
                :key="field.value"
                :label="field.label + '（' + field.value + '）'"
                :value="field.value"
              />
            </el-select>
            <el-select
              v-if="
                actionUpdateMeta(update).options &&
                actionUpdateMeta(update).options.length
              "
              v-model="update.value"
              filterable
              allow-create
              placeholder="选择目标值"
            >
              <el-option
                v-for="option in actionUpdateMeta(update).options"
                :key="option.value"
                :label="option.label"
                :value="option.value"
              />
            </el-select>
            <el-input
              v-else
              v-model="update.value"
              placeholder="填写审核完成后写入的目标值"
            />
            <el-button
              type="text"
              class="danger"
              icon="el-icon-delete"
              @click="actionUpdates.splice(index, 1)"
            />
          </div>
          <el-alert
            title="字段名从当前业务单据数据库字段中选择；流程发布后由通用审批引擎执行，不需要再为此流程编写接口。"
            type="success"
            :closable="false"
          />
        </template>
        <el-alert
          v-else
          title="该动作只记录审核结果，不修改业务单据字段。"
          type="info"
          :closable="false" /></el-form
      ><span slot="footer"
        ><el-button size="small" @click="actionDialog.visible = false"
          >取消</el-button
        ><el-button size="small" type="success" @click="confirmAction"
          >确定</el-button
        ></span
      ></el-dialog
    >
    <footer>
      版本信息：v{{ flow.current_version || 0 }}　　当前编辑：v{{
        editingVersion.version_no || 1
      }}
      {{
        editingVersion.version_status === "draft" ? "草稿" : "已发布"
      }}　　最后保存：{{ time(editingVersion.updated_at) }}
    </footer>

    <el-dialog
      title="登记审核业务对象"
      :visible.sync="registryDialog.visible"
      width="1040px"
      top="5vh"
      custom-class="approval-registry-dialog"
      :close-on-click-modal="false"
      @closed="resetRegistryDialog"
    >
      <p class="registry-subtitle">从当前系统数据表登记可配置审核对象；登记后即可在本流程中使用</p>
      <section class="registry-table-picker">
        <label>候选数据表</label>
        <el-select
          v-model="registryDraft.source_table"
          filterable
          remote
          clearable
          reserve-keyword
          :remote-method="searchRegistryCandidates"
          :loading="registryDialog.candidatesLoading"
          placeholder="搜索当前 MySQL 中尚未登记的数据表"
          @change="loadRegistryCandidate"
        >
          <el-option
            v-for="item in registryDialog.candidates"
            :key="item.table"
            :label="`${item.table}｜${item.label}`"
            :value="item.table"
          />
        </el-select>
        <div v-if="registryDraft.adapter" class="adapter-badge">
          <i class="el-icon-circle-check" />
          <span>已安装适配器</span>
          <el-tag size="mini" type="success">{{ registryDraft.adapter.label }} {{ registryDraft.adapter.version }}</el-tag>
        </div>
      </section>

      <section class="registry-section">
        <h3>对象基本信息</h3>
        <div class="registry-base-grid">
          <label><span>对象编码 <em>*</em></span><el-input v-model.trim="registryDraft.object_code" size="small" placeholder="例如 SALES_ORDER_CHANGE" /></label>
          <label><span>对象名称 <em>*</em></span><el-input v-model.trim="registryDraft.object_name" size="small" placeholder="请输入业务对象名称" /></label>
          <label><span>业务模块 <em>*</em></span><el-input v-model.trim="registryDraft.business_module" size="small" placeholder="例如 销售管理" /></label>
          <label><span>主键 <em>*</em></span><el-select v-model="registryDraft.primary_key" size="small" placeholder="请选择主键"><el-option v-for="field in registryDraft.fields" :key="field.field_code" :label="field.field_code" :value="field.field_code" /></el-select></label>
          <label class="route-field"><span>详情路由</span><el-input v-model.trim="registryDraft.route_pattern" size="small" placeholder="例如 /sales/orders/{sales_order_id}/detail" /></label>
        </div>
      </section>

      <section class="registry-section field-section">
        <div class="registry-heading"><h3>字段白名单</h3><span>已选择 {{ selectedRegistryFieldCount }}/{{ registryDraft.fields.length }} 个字段</span></div>
        <el-table :data="pagedRegistryFields" border size="mini" height="244">
          <el-table-column label="启用" width="54" align="center"><template slot-scope="{row}"><el-checkbox v-model="row.selected" /></template></el-table-column>
          <el-table-column prop="field_code" label="字段编码" min-width="145" show-overflow-tooltip />
          <el-table-column label="字段名称" min-width="130"><template slot-scope="{row}"><el-input v-model.trim="row.field_name" size="mini" :disabled="!row.selected" /></template></el-table-column>
          <el-table-column prop="field_type" label="数据类型" width="94" />
          <el-table-column label="条件" width="60" align="center"><template slot-scope="{row}"><el-checkbox v-model="row.condition_enabled" :disabled="!row.selected" /></template></el-table-column>
          <el-table-column label="展示" width="60" align="center"><template slot-scope="{row}"><el-checkbox v-model="row.display_enabled" :disabled="!row.selected" /></template></el-table-column>
          <el-table-column label="检索" width="60" align="center"><template slot-scope="{row}"><el-checkbox v-model="row.search_enabled" :disabled="!row.selected" /></template></el-table-column>
          <el-table-column label="引用" width="60" align="center"><template slot-scope="{row}"><el-checkbox v-model="row.reference_enabled" :disabled="!row.selected" /></template></el-table-column>
        </el-table>
        <div class="registry-pager"><span>共 {{ registryDraft.fields.length }} 个字段</span><el-pagination small background layout="prev, pager, next" :page-size="registryDialog.fieldPageSize" :current-page.sync="registryDialog.fieldPage" :total="registryDraft.fields.length" /></div>
      </section>

      <div class="registry-bottom-grid">
        <section class="registry-section event-card">
          <h3>触发动作 <small>由业务对象提供</small></h3>
          <div class="event-grid">
            <label><span>动作名称</span><el-input v-model.trim="registryDraft.event.event_name" size="small" disabled /></label>
            <label><span>触发来源</span><el-input :value="registryDraft.adapter ? '已安装业务适配器' : '页面手动发起'" size="small" disabled /></label>
          </div>
          <div class="event-switches"><span>业务动作触发 <el-switch v-model="registryDraft.event.event_trigger_allowed" disabled /></span><span>允许手动发起 <el-switch v-model="registryDraft.event.manual_start_allowed" disabled /></span></div>
        </section>
        <section class="registry-section action-card">
          <h3>已安装业务动作 <small>只读</small></h3>
          <p v-for="action in registryDraft.installed_actions" :key="action.action_code"><i class="el-icon-circle-check" /><span>{{ action.action_name }}</span><code>{{ action.action_code }}</code></p>
          <el-empty v-if="!registryDraft.installed_actions.length" :image-size="38" description="当前表未安装专用动作，将使用通用完成/驳回动作" />
        </section>
      </div>
      <el-alert title="只登记真实数据表字段和已安装动作；不会生成业务代码，也不会自动修改业务数据。" type="info" show-icon :closable="false" />
      <span slot="footer" class="dialog-footer"><el-button size="small" @click="registryDialog.visible=false">取消</el-button><el-button size="small" @click="validateRegistration">校验登记</el-button><el-button size="small" type="success" :loading="registryDialog.saving" @click="registerBusinessObject">登记并使用</el-button></span>
    </el-dialog>
  </div>
</template>

<script>
import {
  createApprovalFlow,
  getApprovalFlow,
  getApprovalFlowConfigOptions,
  getApprovalRegistryCandidate,
  listApprovalRegistryCandidates,
  publishApprovalFlow,
  registerApprovalBusinessObject,
  updateApprovalFlow,
  validateApprovalFlow,
} from "@/api/erp/approval";
import ApprovalConditionBuilder from "./ApprovalConditionBuilder.vue";
const uid = () =>
  `node_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
const newNode = () => ({
  key: uid(),
  name: "新审核节点",
  approval_type: "business",
  node_type: "APPROVAL",
  permission_code: "approval.task.decide",
  processing_strategy: "sequential",
  completion_strategy: "ANY",
  required_approver_count: 1,
  required_approver_ratio: 1,
  reject_on_any: true,
  action_code: "",
  action_config: {},
  approver_rule: { type: "role", label: "", value: "" },
  entry_conditions: { logic: "AND", children: [] },
  sla_hours: 4,
  reminder_enabled: false,
  reminder_hours: 1,
  reject_strategy: "TERMINATE",
  allow_reject: true,
  allow_transfer: true,
  comment_required: true,
});
const freshDefinition = () => ({
  schema_version: 2,
  source_mode: "existing",
  business_object_code: "",
  trigger_action: "",
  event_code: "",
  trigger_mode: "MANUAL_START",
  execution_mode: "BEFORE_ACTION",
  priority: 100,
  match_strategy: "FIRST_MATCH",
  start_conditions: { logic: "AND", children: [] },
  form_template_id: null,
  source_table: null,
  processing_mode: "sequential",
  allow_self_approval: false,
  applicable_scope: { type: "all", department_ids: [] },
  nodes: [newNode()],
  completion_actions: [
    { event: "approved", action_key: "approval.complete", config: {} },
    { event: "rejected", action_key: "approval.reject", config: {} },
  ],
  notifications: {
    websocket: true,
    internal: true,
    in_app: true,
    email: false,
  },
});
const freshRegistryDraft = () => ({
  source_table: "",
  adapter_key: null,
  adapter: null,
  object_code: "",
  object_name: "",
  business_module: "",
  primary_key: "id",
  route_pattern: "",
  view_permission_code: "",
  fields: [],
  event: {
    event_code: "",
    event_name: "",
    manual_start_allowed: false,
    event_trigger_allowed: true,
  },
  installed_actions: [],
});
export default {
  name: "ApprovalFlowForm",
  components: { ApprovalConditionBuilder },
  data: () => ({
    loading: false,
    flow: {},
    editingVersion: {},
    validation: { valid: false, errors: [], warnings: [] },
    options: {
      flow_categories: [],
      business_objects: [],
      custom_forms: [],
      completion_actions: [],
      scope_types: [],
      departments: [],
      roles: [],
      users: [],
      approver_sources: [],
      operators: [],
      trigger_modes: [],
      execution_modes: [],
      node_types: [],
    },
    scope: { type: "all", department_ids: [] },
    form: {
      flow_code: "",
      flow_name: "",
      business_module: "",
      business_type: "",
      business_scene: "",
      applicable_scope: "全部组织",
      description: "",
    },
    definition: freshDefinition(),
    selectedIndex: 0,
    actionDialog: {
      visible: false,
      row: { event: "approved", action_key: "", config: {} },
    },
    registryDialog: {
      visible: false,
      candidates: [],
      candidatesLoading: false,
      detailLoading: false,
      saving: false,
      fieldPage: 1,
      fieldPageSize: 10,
    },
    registryDraft: freshRegistryDraft(),
  }),
  computed: {
    isNew() {
      return !this.$route.params.id;
    },
    readonly() {
      return this.$route.query.mode === "view";
    },
    selected() {
      return this.definition.nodes[this.selectedIndex];
    },
    currentObject() {
      return (
        this.options.business_objects.find(
          (row) => row.code === this.definition.business_object_code
        ) || null
      );
    },
    currentObjectEvents() {
      return this.currentObject ? this.currentObject.events || this.currentObject.triggers || [] : [];
    },
    currentTriggerActionLabel() {
      const current = this.currentObjectEvents.find((row) => row.key === this.definition.event_code);
      return current ? current.label : "选择业务对象后自动带出";
    },
    approverRuleMode: {
      get() {
        const type = this.selected && this.selected.approver_rule ? this.selected.approver_rule.type : "role";
        if (type === "role") return "role";
        if (type === "department_principal") return "department_principal";
        if (["user", "specified_users"].includes(type)) return "specified_users";
        return "expression";
      },
      set(mode) {
        const type = mode === "expression" ? "initiator_manager" : mode;
        this.approverTypeChanged(type);
        if (type === "department_principal") this.selected.approver_rule.value = "task_department";
        this.syncApproverLabel();
      },
    },
    dynamicApproverNeedsField() {
      return Boolean(this.selected && ["business_record_owner", "business_record_department_manager", "field_user", "field_user_manager", "field_department_manager"].includes(this.selected.approver_rule.type));
    },
    conditionFields() {
      if (this.definition.source_mode === "existing")
        return this.currentObject ? (this.currentObject.fields || []).filter((field) => field.condition_enabled === true) : [];
      const form = this.options.custom_forms.find(
        (row) => Number(row.value) === Number(this.definition.form_template_id)
      );
      return (form && form.fields) || [];
    },
    assigneeFields() {
      if (this.definition.source_mode === "existing") return this.currentObject ? (this.currentObject.fields || []).filter((field) => field.reference_enabled === true || ["user", "department"].includes(field.type)) : [];
      return this.conditionFields.filter((field) => ["user", "department"].includes(field.type));
    },
    isMultiUserApprover() {
      return Boolean(
        this.selected &&
        ["user", "specified_users"].includes(this.selected.approver_rule.type) &&
          Array.isArray(this.selected.approver_rule.value) &&
          this.selected.approver_rule.value.length > 1
      );
    },
    currentAction() {
      return (
        this.options.completion_actions.find(
          (row) => row.key === this.actionDialog.row.action_key
        ) || null
      );
    },
    currentActionNeedsUpdates() {
      return Boolean(this.currentAction && this.currentAction.requires_updates);
    },
    actionUpdates() {
      const row = this.actionDialog.row;
      if (!row.config) this.$set(row, "config", {});
      if (!row.config.updates) this.$set(row.config, "updates", []);
      return row.config.updates;
    },
    actionUpdateFields() {
      return ((this.currentObject && this.currentObject.fields) || []).filter(
        (field) => field.approval_writable === true
      );
    },
    nodeActionOptions() {
      return (this.options.completion_actions || []).filter((item) => item.event === "node_action" && (!item.object_code || (this.currentObject && item.object_code === this.currentObject.code)));
    },
    pagedRegistryFields() {
      const start = (this.registryDialog.fieldPage - 1) * this.registryDialog.fieldPageSize;
      return this.registryDraft.fields.slice(start, start + this.registryDialog.fieldPageSize);
    },
    selectedRegistryFieldCount() {
      return this.registryDraft.fields.filter((row) => row.selected).length;
    },
    validationItems() {
      const errors = this.validation.errors || [];
      return [
        {
          text: "流程结构校验",
          ok:
            this.definition.nodes.length > 0 &&
            !errors.some((e) => e.includes("节点")),
        },
        {
          text: "节点配置完整性",
          ok: this.definition.nodes.every((n) => {
            if (!n.name) return false;
            if (n.node_type === "ACTION") return Boolean(n.action_code);
            if (n.node_type === "CONDITION") return Boolean(n.entry_conditions && n.entry_conditions.children && n.entry_conditions.children.length);
            return n.approver_rule && (n.approver_rule.value || n.approver_rule.field || ["initiator_manager","initiator_department_manager"].includes(n.approver_rule.type));
          }),
        },
        {
          text: "条件表达式校验",
          ok: !errors.some((e) => e.includes("触发条件")),
        },
        {
          text: "业务处理动作",
          ok: this.definition.completion_actions.every((row) => row.action_key),
        },
      ];
    },
  },
  created() {
    this.load();
  },
  methods: {
    async openRegistryDialog() {
      this.registryDialog.visible = true;
      await this.searchRegistryCandidates("");
    },
    resetRegistryDialog() {
      this.registryDraft = freshRegistryDraft();
      this.registryDialog.candidates = [];
      this.registryDialog.fieldPage = 1;
      this.registryDialog.saving = false;
    },
    async searchRegistryCandidates(keyword) {
      this.registryDialog.candidatesLoading = true;
      try {
        const { data } = await listApprovalRegistryCandidates({ keyword, page: 1, per_page: 20 });
        this.registryDialog.candidates = data.data || [];
      } finally {
        this.registryDialog.candidatesLoading = false;
      }
    },
    async loadRegistryCandidate(table) {
      if (!table) {
        this.registryDraft = freshRegistryDraft();
        return;
      }
      this.registryDialog.detailLoading = true;
      try {
        const { data } = await getApprovalRegistryCandidate(table);
        const detail = data.data || {};
        const defaults = detail.defaults || {};
        this.registryDraft = {
          ...freshRegistryDraft(),
          source_table: table,
          adapter_key: detail.adapter ? detail.adapter.key : null,
          adapter: detail.adapter || null,
          object_code: defaults.object_code || "",
          object_name: defaults.object_name || "",
          business_module: defaults.business_module || "",
          primary_key: defaults.primary_key || "id",
          route_pattern: defaults.route_pattern || "",
          fields: detail.fields || [],
          event: {
            event_code: defaults.event_code || "manual_submit",
            event_name: defaults.event_name || "提交审核",
            manual_start_allowed: defaults.manual_start_allowed !== undefined ? defaults.manual_start_allowed : true,
            event_trigger_allowed: defaults.event_trigger_allowed !== undefined ? defaults.event_trigger_allowed : false,
          },
          installed_actions: detail.installed_actions || [],
        };
        this.registryDialog.fieldPage = 1;
      } finally {
        this.registryDialog.detailLoading = false;
      }
    },
    registrationError() {
      if (!this.registryDraft.source_table) return "请选择候选数据表";
      if (!/^[A-Z][A-Z0-9_]*$/.test(this.registryDraft.object_code)) return "对象编码必须为大写字母、数字和下划线";
      if (!this.registryDraft.object_name || !this.registryDraft.business_module) return "对象名称和业务模块必须填写完整";
      if (!this.selectedRegistryFieldCount) return "请至少选择一个真实字段";
      if (!this.registryDraft.event.event_code || !this.registryDraft.event.event_name) return "当前业务对象没有提供可用触发动作";
      if (!this.registryDraft.event.manual_start_allowed && !this.registryDraft.event.event_trigger_allowed) return "当前业务对象没有提供可用发起方式";
      return "";
    },
    validateRegistration() {
      const error = this.registrationError();
      if (error) return this.$message.warning(error);
      this.$message.success("登记配置校验通过，可以登记并用于当前流程");
    },
    async registerBusinessObject() {
      const error = this.registrationError();
      if (error) return this.$message.warning(error);
      this.registryDialog.saving = true;
      try {
        const payload = JSON.parse(JSON.stringify(this.registryDraft));
        delete payload.adapter;
        delete payload.installed_actions;
        const { data } = await registerApprovalBusinessObject(payload);
        const registered = data.data && data.data.object;
        const { data: optionResponse } = await getApprovalFlowConfigOptions();
        this.options = { ...this.options, ...(optionResponse.data || {}) };
        this.registryDialog.visible = false;
        if (registered) {
          this.definition.business_object_code = registered.code;
          this.businessObjectChanged(registered.code);
        }
        this.$message.success("审核业务对象已登记并用于当前流程");
      } finally {
        this.registryDialog.saving = false;
      }
    },
    async load() {
      this.loading = true;
      try {
        const { data: od } = await getApprovalFlowConfigOptions();
        this.options = { ...this.options, ...(od.data || {}) };
        if (this.isNew) return;
        const { data } = await getApprovalFlow(this.$route.params.id);
        this.flow = data.data || {};
        this.editingVersion = this.flow.editing_version || {};
        [
          "flow_code",
          "flow_name",
          "business_module",
          "business_type",
          "business_scene",
          "applicable_scope",
          "description",
        ].forEach((k) => {
          this.form[k] = this.flow[k] || "";
        });
        const saved = JSON.parse(
          JSON.stringify(this.editingVersion.definition_snapshot || {})
        );
        const matchedObject = this.options.business_objects.find(
          (row) => row.table === saved.source_table
        );
        this.definition = {
          ...freshDefinition(),
          ...saved,
          schema_version: saved.schema_version || 1,
          source_mode:
            saved.source_mode ||
            (saved.source_table ? "existing" : "custom_form"),
          business_object_code:
            saved.business_object_code ||
            (matchedObject ? matchedObject.code : ""),
          event_code: saved.event_code || saved.trigger_action || "",
          start_conditions: this.normalizeConditionGroup(saved.start_conditions),
          completion_actions: (
            saved.completion_actions || this.legacyActions(saved)
          ).map((row) => ({ ...row, config: row.config || {} })),
          notifications: {
            websocket: true,
            internal: true,
            in_app: true,
            email: false,
            ...(saved.notifications || {}),
          },
        };
        this.definition.nodes = (saved.nodes || []).map((n) => ({
          ...newNode(),
          ...n,
          approver_rule: {
            type: "role",
            label: "",
            value: "",
            ...(n.approver_rule || {}),
          },
          node_type: n.node_type || "APPROVAL",
          completion_strategy: n.completion_strategy || (n.processing_strategy === "parallel" ? "ALL" : "ANY"),
          entry_conditions: this.normalizeConditionGroup(n.entry_conditions || n.conditions || n.condition),
          reject_strategy: ["previous", "RETURN_PREVIOUS"].includes(n.reject_strategy) ? "RETURN_PREVIOUS" : "TERMINATE",
        }));
        const sc = this.definition.applicable_scope || {
          type: "all",
          department_ids: [],
        };
        this.scope = {
          type: sc.type || "all",
          department_ids: (sc.department_ids || []).map(Number),
        };
        await this.validate(false);
      } finally {
        this.loading = false;
      }
    },
    legacyActions(d) {
      return [
        {
          event: "approved",
          action_key:
            d.callbacks && d.callbacks.approved ? "approval.complete" : "",
          config: {},
        },
        {
          event: "rejected",
          action_key:
            d.callbacks && d.callbacks.rejected ? "approval.reject" : "",
          config: {},
        },
      ];
    },
    conditionsFromLegacy(v) {
      if (!v) return [];
      if (Array.isArray(v)) return v;
      return Object.entries(v).map(([field, value]) => ({
        field,
        operator: "contains",
        value,
      }));
    },
    normalizeConditionGroup(value) {
      if (!value) return { logic: "AND", children: [] };
      if (value.children) return value;
      return { logic: "AND", children: this.conditionsFromLegacy(value) };
    },
    payload() {
      this.definition.schema_version = 2;
      this.definition.applicable_scope = {
        type: this.scope.type,
        department_ids:
          this.scope.type === "departments"
            ? this.scope.department_ids.map(Number)
            : [],
      };
      this.definition.source_table = this.currentObject
        ? this.currentObject.table
        : null;
      if (this.definition.source_mode === "custom_form") {
        this.definition.business_object_code = "CUSTOM_FORM_SUBMISSION";
        this.definition.event_code = "submit_form";
      }
      this.definition.trigger_action = this.definition.event_code;
      return {
        ...this.form,
        business_type:
          this.form.business_type ||
          String(this.form.flow_code || "")
            .trim()
            .toUpperCase(),
        business_scene: this.form.business_scene || this.form.flow_name,
        applicable_scope:
          this.scope.type === "all" ? "全部组织" : this.scope.type,
        definition: this.definition,
      };
    },
    async save() {
      const api = this.isNew
        ? createApprovalFlow
        : (v) => updateApprovalFlow(this.flow.id, v);
      const { data } = await api(this.payload());
      this.$message.success("审核流程草稿已保存");
      if (this.isNew) {
        this.flow = data.data || {};
        await this.$router.replace(`/approvals/flows/${data.data.id}/edit`);
      }
      await this.load();
    },
    async validate(notify = true) {
      const { data } = await validateApprovalFlow(this.definition);
      this.validation = data.data || {};
      if (notify)
        this.$message[this.validation.valid ? "success" : "warning"](
          this.validation.valid
            ? "流程校验通过"
            : (this.validation.errors || [])[0] || "流程配置不完整"
        );
    },
    async publish() {
      await this.validate(false);
      if (!this.validation.valid)
        return this.$message.error(
          (this.validation.errors || [])[0] || "流程校验未通过"
        );
      await this.$confirm(
        "发布后将生成不可变更的新版本，确认发布？",
        "发布新版本",
        { type: "warning" }
      );
      if (this.isNew) {
        const { data } = await createApprovalFlow(this.payload());
        await publishApprovalFlow(data.data.id, this.payload());
        this.$message.success("审核流程新版本已发布");
        await this.$router.replace(`/approvals/flows/${data.data.id}/edit`);
        return this.load();
      }
      await publishApprovalFlow(this.flow.id, this.payload());
      this.$message.success("审核流程新版本已发布");
      await this.load();
    },
    sourceModeChanged(mode) {
      if (mode === "existing") {
        this.definition.form_template_id = null;
      }
      this.definition.business_object_code = "";
      this.definition.trigger_action = "";
      this.definition.event_code = "";
      this.definition.source_table = null;
      this.syncActions();
    },
    businessObjectChanged(code) {
      const object = this.options.business_objects.find(
        (row) => row.code === code
      );
      if (!object) return;
      this.definition.business_object_code = object.code;
      this.definition.event_code =
        (((object.events || object.triggers) && (object.events || object.triggers)[0]) || {}).key || "";
      this.definition.trigger_action = this.definition.event_code;
      this.definition.source_table = object.table;
      this.form.business_module = object.module || this.form.business_module;
      this.selected.entry_conditions = { logic: "AND", children: [] };
      this.syncActions();
    },
    syncActions() {
      const approved = this.definition.completion_actions.find(
          (r) => r.event === "approved"
        ),
        rejected = this.definition.completion_actions.find(
          (r) => r.event === "rejected"
        );
      const objectCode = this.currentObject && this.currentObject.code;
      const available = (this.options.completion_actions || []).filter((action) => !action.object_code || action.object_code === objectCode);
      approved.action_key = ((available.find((action) => action.event === "approved") || {}).key || "");
      approved.config = {};
      rejected.action_key = ((available.find((action) => action.event === "rejected") || {}).key || "");
      rejected.config = {};
    },
    showObjectFields() {
      if (!this.currentObject)
        return this.$message.warning("请先选择审核业务对象");
      this.$alert(
        this.currentObject.fields
          .map((row) => `${row.label}（${row.value}）`)
          .join("\n"),
        "可用于条件和处理动作的业务字段",
        { confirmButtonText: "关闭", customClass: "approval-field-dialog" }
      );
    },
    openFormDesigner() {
      this.$router.push("/approvals/forms/create");
    },
    addNode(i) {
      this.definition.nodes.splice(i, 0, newNode());
      this.selectedIndex = i;
    },
    copyNode(i) {
      const c = JSON.parse(JSON.stringify(this.definition.nodes[i]));
      c.key = uid();
      c.name += "（复制）";
      this.definition.nodes.splice(i + 1, 0, c);
      this.selectedIndex = i + 1;
    },
    removeNode(i) {
      this.definition.nodes.splice(i, 1);
      this.selectedIndex = Math.max(
        0,
        Math.min(i, this.definition.nodes.length - 1)
      );
    },
    addCondition(i) {
      const n = this.definition.nodes[i];
      const first = this.conditionFields[0];
      if (!n.entry_conditions || !n.entry_conditions.children) this.$set(n, "entry_conditions", { logic: "AND", children: [] });
      n.entry_conditions.children.push({
        field: first ? first.value : "",
        type: first ? first.type : "string",
        operator: "=",
        value: "",
      });
      this.selectedIndex = i;
    },
    conditionMeta(c) {
      return (
        this.conditionFields.find((row) => row.value === c.field) || {
          type: "text",
        }
      );
    },
    conditionFieldChanged(c) {
      c.operator = ["number", "date"].includes(this.conditionMeta(c).type)
        ? "gte"
        : "eq";
      c.value = "";
    },
    operatorsFor(c) {
      const allow = ["number", "date"].includes(this.conditionMeta(c).type)
        ? ["eq", "neq", "gt", "gte", "lt", "lte"]
        : ["eq", "neq", "contains"];
      return this.options.operators.filter((row) => allow.includes(row.value));
    },
    conditionText(n) {
      const children = (n.entry_conditions && n.entry_conditions.children) || [];
      return !children.length
        ? "无"
        : children
            .filter((c) => !c.children)
            .map(
              (c) =>
                `${
                  (this.conditionFields.find((f) => f.value === c.field) || {})
                    .label || c.field
                } ${
                  (
                    this.options.operators.find(
                      (o) => o.value === c.operator
                    ) || {}
                  ).label || c.operator
                } ${c.value}`
            )
            .join(n.entry_conditions.logic === "OR" ? " 或 " : " 且 ");
    },
    approverText(n) {
      const r = n.approver_rule || {};
      const dynamicLabels = {
        initiator_manager: "发起人的直属负责人",
        initiator_department_manager: "发起人所属部门负责人",
        business_record_owner: "业务单据负责人",
        business_record_department_manager: "业务单据负责人所属部门负责人",
        field_user: "业务字段指定人员",
        field_user_manager: "业务字段人员的负责人",
        field_department_manager: "业务字段部门负责人",
      };
      return (
        r.label ||
        (r.type === "department_principal"
          ? "部门负责人"
          : ["user", "specified_users"].includes(r.type)
          ? "指定人员"
          : dynamicLabels[r.type] || "未配置")
      );
    },
    approverTypeChanged(t) {
      this.selected.approver_rule.value = ["user", "specified_users"].includes(t) ? [] : "";
      this.selected.approver_rule.field = "";
      this.selected.approver_rule.label = "";
      this.selected.processing_strategy = "sequential";
    },
    dynamicApproverChanged() {
      this.selected.approver_rule.value = "";
      this.selected.approver_rule.field = "";
      this.syncApproverLabel();
    },
    syncApproverLabel() {
      const r = this.selected.approver_rule;
      if (r.type === "role")
        r.label =
          (this.options.roles.find((x) => x.value === r.value) || {}).label ||
          "";
      else if (r.type === "department_principal")
        r.label =
          r.value === "task_department"
            ? "业务发起人所属部门负责人"
            : ((
                this.options.departments.find(
                  (x) => String(x.value) === String(r.value)
                ) || {}
              ).label || "") + "负责人";
      else if (["user", "specified_users"].includes(r.type))
        r.label = (r.value || [])
          .map(
            (id) =>
              (
                this.options.users.find(
                  (x) => Number(x.value) === Number(id)
                ) || {}
              ).label
          )
          .filter(Boolean)
          .join("、");
      else {
        const labels = {
          initiator_manager: "发起人的直属负责人",
          initiator_department_manager: "发起人所属部门负责人",
          business_record_owner: "业务单据负责人",
          business_record_department_manager: "业务单据负责人所属部门负责人",
          field_user: "从业务字段读取指定人员",
          field_user_manager: "从业务字段读取人员的负责人",
          field_department_manager: "从业务字段读取部门负责人",
        };
        const field = this.assigneeFields.find((item) => item.value === r.field);
        r.label = `${labels[r.type] || "动态处理人"}${field ? `：${field.label}` : ""}`;
      }
    },
    configureAction(row) {
      this.actionDialog = { visible: true, row };
      if (!row.config) this.$set(row, "config", {});
    },
    availableActions(event) {
      return this.options.completion_actions.filter(
        (row) =>
          row.event === event &&
          (!row.object_code ||
            row.object_code === this.definition.business_object_code)
      );
    },
    actionChanged() {
      this.$set(this.actionDialog.row, "config", {});
      if (this.currentActionNeedsUpdates)
        this.$set(this.actionDialog.row.config, "updates", [
          { field: "", value: "" },
        ]);
    },
    addActionUpdate() {
      this.actionUpdates.push({ field: "", value: "" });
    },
    actionUpdateMeta(update) {
      return (
        this.actionUpdateFields.find((field) => field.value === update.field) ||
        {}
      );
    },
    actionUpdateFieldChanged(update) {
      update.value = "";
    },
    confirmAction() {
      if (this.currentActionNeedsUpdates && !this.actionUpdates.length)
        return this.$message.warning("请至少添加一个业务字段更新规则");
      this.actionDialog.visible = false;
    },
    actionText(key) {
      return (
        (this.options.completion_actions.find((row) => row.key === key) || {})
          .label || "未配置"
      );
    },
    eventText(e) {
      return (
        { approved: "审核通过", rejected: "审核驳回", cancelled: "审核取消" }[
          e
        ] || e
      );
    },
    addCompletionAction() {
      this.$message.info("当前通过与驳回动作已完整配置");
    },
    time(v) {
      return v
        ? String(v)
            .replace("T", " ")
            .replace(/\.\d+Z$/, "")
            .slice(0, 16)
        : "-";
    },
  },
};
</script>

<style scoped>
.flow-form {
  min-height: calc(100vh - 52px);
  padding: 12px 16px;
  background: #f7f8fa;
  color: #172535;
}
.start-condition-box { margin: 10px 0 4px; padding: 10px; border: 1px solid #d9e8ff; border-radius: 4px; background: #f7fbff; }
.start-condition-box .node-head { margin-bottom: 8px; }
.start-condition-box .node-head span { color: #7d8998; font-size: 12px; }
.compact-two { align-items: flex-start; }
.flow-form > header {
  height: 48px;
  display: flex;
  justify-content: space-between;
  align-items: flex-start;
}
.title h1 {
  font-size: 21px;
  margin: 3px 0 0;
}
.flow-form > .el-alert {
  margin-bottom: 10px;
}
.editor-grid {
  display: grid;
  grid-template-columns: minmax(210px, 240px) minmax(500px, 1fr) minmax(
      350px,
      430px
    );
  gap: 12px;
}
.card {
  min-width: 0;
  background: #fff;
  border: 1px solid #e1e6eb;
  border-radius: 6px;
}
.card h2 {
  font-size: 15px;
  margin: 0;
  padding: 12px 14px;
  border-bottom: 1px solid #e5eaee;
}
.base {
  padding-bottom: 10px;
}
.base .el-form {
  padding: 10px 14px;
}
.base ::v-deep .el-form-item {
  margin-bottom: 11px;
}
.base ::v-deep .el-select {
  width: 100%;
}
.object-select-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) auto;
  gap: 6px;
}
.object-links {
  min-height: 22px;
  display: flex;
  align-items: center;
  gap: 7px;
}
.object-links span {
  color: #e68912;
  font-size: 11px;
}
.field-help {
  margin-top: 4px;
  color: #7d8998;
  font-size: 11px;
  line-height: 1.45;
}
.base ::v-deep .el-radio-group {
  display: flex;
}
.base ::v-deep .el-radio-button {
  flex: 1;
}
.base ::v-deep .el-radio-button__inner {
  width: 100%;
  padding: 8px 5px;
}
.canvas {
  padding-bottom: 13px;
}
.start,
.finish {
  width: 132px;
  height: 28px;
  margin: 12px auto 0;
  border: 1px solid #cad4de;
  border-radius: 18px;
  display: grid;
  place-items: center;
  font-size: 12px;
}
.start i,
.finish i {
  color: #10a65e;
}
.arrow {
  text-align: center;
  color: #a9b4bf;
  height: 22px;
  line-height: 22px;
}
.node {
  margin: 0 12px;
  padding: 11px 13px;
  border: 1px solid #cfd8e1;
  border-radius: 5px;
  cursor: pointer;
}
.node.selected {
  border: 2px solid #318cf5;
  background: #f8fbff;
}
.node-head {
  display: flex;
  justify-content: space-between;
}
.node-head em {
  display: inline-grid;
  place-items: center;
  width: 20px;
  height: 20px;
  margin-right: 7px;
  background: #2484ed;
  color: #fff;
  border-radius: 3px;
  font-style: normal;
}
.node-head a {
  font-size: 12px;
  color: #2582e8;
  margin-left: 9px;
  white-space: nowrap;
}
.node-info {
  display: grid;
  grid-template-columns: 1.4fr 1fr 1.5fr 0.6fr;
  gap: 8px;
  margin-top: 11px;
  font-size: 11px;
}
.node-info.sub {
  grid-template-columns: repeat(3, 1fr);
  color: #667585;
}
.insert-actions {
  text-align: center;
  margin: 7px 0 0;
}
.config {
  padding-bottom: 12px;
}
.config .el-form {
  padding: 10px 14px;
}
.config h3 {
  font-size: 13px;
  margin: 13px 0 8px;
}
.section-help {
  color: #7f8d99;
  font-size: 11px;
}
.condition {
  display: grid;
  grid-template-columns: minmax(0, 1.2fr) 86px minmax(0, 0.9fr) 28px;
  gap: 5px;
  margin-bottom: 6px;
}
.config .two {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.config ::v-deep .el-form-item {
  margin-bottom: 11px;
}
.config ::v-deep .el-select,
.config ::v-deep .el-input-number,
.config ::v-deep .el-date-editor {
  width: 100%;
}
.approver-rule-tabs {
  display: flex;
  width: 100%;
  margin-bottom: 11px;
}
.approver-rule-tabs ::v-deep .el-radio-button {
  flex: 1;
}
.approver-rule-tabs ::v-deep .el-radio-button__inner {
  width: 100%;
  padding: 8px 5px;
  border-color: #d7dee6;
  box-shadow: none;
}
.approver-rule-tabs ::v-deep .el-radio-button__orig-radio:checked + .el-radio-button__inner {
  color: #078d4b;
  background: #f2fbf6;
  border-color: #18a660;
  box-shadow: -1px 0 0 0 #18a660;
}
.node-switches {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px 12px;
  padding: 10px;
  border: 1px solid #e4e9ee;
  border-radius: 4px;
  background: #f8fafb;
}
.node-switches label {
  display: grid;
  grid-template-columns: auto 1fr;
  align-items: center;
  gap: 7px;
  font-size: 12px;
}
.node-switches label > span {
  white-space: nowrap;
}
.node-switches ::v-deep .el-radio-group {
  display: flex;
  min-width: 0;
}
.node-switches ::v-deep .el-radio-button {
  flex: 1;
}
.node-switches ::v-deep .el-radio-button__inner {
  width: 100%;
  padding: 6px 10px;
}
.node-switches > .el-checkbox {
  grid-column: 1/-1;
}
.bottom-grid {
  display: grid;
  grid-template-columns: 1.15fr 0.75fr 1fr;
  gap: 10px;
  margin-top: 10px;
}
.bottom-grid .card {
  padding-bottom: 10px;
}
.bottom-grid .card > p,
.notifications label {
  margin: 9px 13px;
  font-size: 12px;
}
.actions .el-table {
  width: calc(100% - 24px);
  margin: 0 12px;
}
.actions > .el-button {
  margin: 8px 12px;
}
.notifications p {
  color: #788594;
  margin-left: 34px !important;
}
.check p {
  display: flex;
  gap: 8px;
  margin: 10px 14px;
  font-size: 12px;
}
.check p b {
  margin-left: auto;
}
.flow-form > footer {
  height: 32px;
  padding-top: 10px;
  font-size: 11px;
  color: #697786;
}
.success {
  color: #12a45e;
}
.warning {
  color: #ec8c15;
}
.danger {
  color: #e55353 !important;
}
.action-rule-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin: 4px 0 8px;
}
.action-update-row {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1fr) 34px;
  gap: 8px;
  margin-bottom: 8px;
}
.action-update-row .el-select {
  width: 100%;
}
::v-deep .approval-registry-dialog {
  max-width: calc(100vw - 36px);
  border-radius: 5px;
  overflow: hidden;
}
::v-deep .approval-registry-dialog .el-dialog__header {
  padding: 17px 22px 10px;
  border-bottom: 1px solid #e5e9ee;
}
::v-deep .approval-registry-dialog .el-dialog__title {
  color: #172535;
  font-size: 19px;
  font-weight: 700;
}
::v-deep .approval-registry-dialog .el-dialog__body {
  padding: 10px 22px 12px;
  color: #263648;
}
::v-deep .approval-registry-dialog .el-dialog__footer {
  padding: 10px 22px 14px;
  border-top: 1px solid #e5e9ee;
}
.registry-subtitle {
  margin: 0 0 10px;
  color: #7b8896;
  font-size: 12px;
}
.registry-table-picker {
  display: grid;
  grid-template-columns: 88px minmax(0, 1fr) minmax(280px, 0.7fr);
  align-items: center;
  gap: 8px;
  padding: 10px 12px;
  border: 1px solid #dce3ea;
  border-radius: 4px;
  background: #fbfcfd;
  font-size: 12px;
}
.registry-table-picker > label {
  font-weight: 600;
}
.adapter-badge {
  height: 32px;
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 0 10px;
  border: 1px solid #b9e6cb;
  border-radius: 4px;
  color: #3e5568;
  background: #f2fbf6;
}
.adapter-badge > i { color: #0aa45b; }
.registry-section {
  margin-top: 10px;
  padding: 10px 12px;
  border: 1px solid #dce3ea;
  border-radius: 4px;
  background: #fff;
}
.registry-section h3 {
  margin: 0 0 9px;
  color: #243447;
  font-size: 13px;
}
.registry-section h3 small {
  margin-left: 5px;
  color: #8995a2;
  font-weight: 400;
}
.registry-base-grid {
  display: grid;
  grid-template-columns: repeat(4, minmax(0, 1fr));
  gap: 9px 12px;
}
.registry-base-grid label,
.event-grid label {
  min-width: 0;
}
.registry-base-grid label > span,
.event-grid label > span {
  display: block;
  margin-bottom: 5px;
  font-size: 12px;
}
.registry-base-grid em,
.event-grid em {
  color: #e55353;
  font-style: normal;
}
.registry-base-grid .route-field {
  grid-column: span 2;
}
.registry-base-grid .el-select { width: 100%; }
.registry-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
}
.registry-heading span {
  color: #728191;
  font-size: 12px;
}
.registry-pager {
  height: 30px;
  display: flex;
  justify-content: flex-end;
  align-items: flex-end;
  gap: 12px;
  color: #7b8896;
  font-size: 11px;
}
.registry-bottom-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.event-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.event-switches {
  display: flex;
  gap: 28px;
  margin-top: 10px;
  font-size: 12px;
}
.event-switches > span {
  display: inline-flex;
  align-items: center;
  gap: 7px;
}
.action-card p {
  display: grid;
  grid-template-columns: 18px minmax(0, 1fr) auto;
  align-items: center;
  gap: 6px;
  margin: 7px 0;
  font-size: 12px;
}
.action-card p i { color: #0aa45b; }
.action-card code {
  color: #688097;
  font-size: 11px;
}
.approval-registry-dialog .el-alert { margin-top: 10px; }
@media (max-width: 1500px) {
  .editor-grid {
    grid-template-columns: minmax(190px, 210px) minmax(420px, 1fr) minmax(
        315px,
        350px
      );
  }
  .node-info {
    grid-template-columns: repeat(2, 1fr);
  }
  .condition {
    grid-template-columns: 1fr 78px 1fr 24px;
  }
  .node-switches {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 1180px) {
  .editor-grid {
    grid-template-columns: 1fr;
  }
  .bottom-grid {
    grid-template-columns: 1fr;
  }
  .base .el-form {
    display: grid;
    grid-template-columns: repeat(3, minmax(0, 1fr));
    gap: 8px;
  }
  .node-switches {
    grid-template-columns: 1fr 1fr;
  }
  .registry-base-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
}
@media (max-width: 720px) {
  .flow-form > header {
    height: auto;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 10px;
  }
  .base .el-form {
    grid-template-columns: 1fr;
  }
  .condition {
    grid-template-columns: 1fr;
  }
  .node-info {
    grid-template-columns: 1fr;
  }
  .node-switches {
    grid-template-columns: 1fr;
  }
  .registry-table-picker,
  .registry-base-grid,
  .registry-bottom-grid,
  .event-grid {
    grid-template-columns: 1fr;
  }
  .registry-base-grid .route-field { grid-column: auto; }
  .adapter-badge { min-width: 0; }
  .action-card code { display: none; }
}
</style>
