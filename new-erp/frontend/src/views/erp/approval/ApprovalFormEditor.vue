<template>
  <div class="form-editor" v-loading="loading">
    <header class="page-head">
      <h1>{{ isNew ? "新建" : "编辑" }}自定义表单</h1>
      <div>
        <el-button size="small" @click="$router.push('/approvals/forms')"
          >返回列表</el-button
        ><el-button size="small" :disabled="readonly" @click="save"
          >保存草稿</el-button
        ><el-button size="small" @click="previewing = !previewing">{{
          previewing ? "退出预览" : "预览表单"
        }}</el-button
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
      title="发布后生成不可变版本；已运行申请保留原表单快照，不受后续新版本影响。"
      type="info"
      show-icon
      :closable="false"
    />
    <section class="basic card">
      <h2>表单基本信息</h2>
      <div class="basic-grid">
        <label
          >表单编码<el-input
            v-model.trim="form.form_code"
            size="small"
            :disabled="readonly || !isNew"
            placeholder="例如 DAILY_REPORT" /></label
        ><label
          >表单名称<el-input
            v-model.trim="form.form_name"
            size="small"
            :disabled="readonly"
            placeholder="请输入表单名称" /></label
        ><label
          >业务模块<el-select
            v-model="form.business_module"
            size="small"
            :disabled="readonly"
            ><el-option
              v-for="item in modules"
              :key="item"
              :label="item"
              :value="item" /></el-select></label
        ><label
          >描述<el-input
            v-model.trim="form.description"
            size="small"
            :disabled="readonly"
            placeholder="请输入表单用途说明" /></label
        ><label class="status-label"
          >状态<el-tag
            size="mini"
            :type="
              template.status === 'enabled'
                ? 'success'
                : template.status === 'disabled'
                ? 'info'
                : 'warning'
            "
            >{{ statusText(template.status) }}</el-tag
          ></label
        >
      </div>
    </section>

    <div class="designer-grid">
      <aside class="toolbox card">
        <h2>字段组件</h2>
        <el-input
          v-model.trim="fieldKeyword"
          size="small"
          prefix-icon="el-icon-search"
          placeholder="搜索字段组件"
        /><template v-for="group in paletteGroups"
          ><h3 :key="group.name">{{ group.name }}</h3>
          <div :key="group.name + 'items'" class="palette">
            <button
              v-for="item in group.items.filter(
                (row) => !fieldKeyword || row.label.includes(fieldKeyword)
              )"
              :key="item.type"
              :disabled="readonly || previewing"
              @click="addField(item)"
            >
              <i :class="item.icon" />{{ item.label }}
            </button>
          </div></template
        ><button class="new-group" disabled>
          <i class="el-icon-plus" /> 新增分组
        </button>
      </aside>

      <main class="canvas card">
        <header>
          <h2>表单设计区</h2>
          <div>
            <el-radio-group v-model="previewDevice" size="mini"
              ><el-radio-button label="desktop"
                ><i class="el-icon-monitor" /> 桌面预览</el-radio-button
              ><el-radio-button label="mobile"
                ><i class="el-icon-mobile-phone" /> 移动预览</el-radio-button
              ></el-radio-group
            ><el-button
              type="text"
              size="mini"
              :disabled="readonly || previewing"
              @click="clearFields"
              ><i class="el-icon-delete" /> 清空</el-button
            >
          </div>
        </header>
        <section
          ref="canvas"
          :class="['form-preview', previewDevice, { previewing }]"
        >
          <h1>{{ form.form_name || "未命名表单" }}申请</h1>
          <article
            v-for="(field, index) in schema.fields"
            :key="field.uid"
            :class="{ selected: selectedIndex === index && !previewing }"
            draggable="true"
            @dragstart="dragIndex = index"
            @dragover.prevent
            @drop="dropField(index)"
            @click="selectField(index)"
          >
            <i v-if="!previewing" class="drag el-icon-rank" /><label
              ><span
                >{{ field.label || "未命名字段"
                }}<em v-if="field.required">*</em></span
              >
              <div class="control">
                <el-input
                  v-if="['text', 'textarea'].includes(field.type)"
                  :type="field.type === 'textarea' ? 'textarea' : 'text'"
                  :rows="field.type === 'textarea' ? 2 : 1"
                  :placeholder="field.placeholder"
                  disabled
                /><el-input-number
                  v-else-if="field.type === 'number'"
                  :controls="false"
                  disabled
                /><el-date-picker
                  v-else-if="field.type === 'date'"
                  type="date"
                  placeholder="请选择日期"
                  disabled
                /><el-select
                  v-else-if="
                    [
                      'select',
                      'multi_select',
                      'user',
                      'department',
                      'business_link',
                    ].includes(field.type)
                  "
                  :placeholder="field.placeholder || '请选择'"
                  disabled
                  ><el-option label="示例选项" value="sample"
                /></el-select>
                <div v-else-if="field.type === 'attachment'" class="upload">
                  <i class="el-icon-upload2" /> 点击或拖拽上传文件
                </div>
              </div></label
            >
            <div v-if="!previewing" class="field-actions">
              <el-button
                type="text"
                icon="el-icon-copy-document"
                @click.stop="copyField(index)"
              /><el-button
                type="text"
                class="danger"
                icon="el-icon-delete"
                @click.stop="removeField(index)"
              />
            </div>
            <button
              v-if="!previewing"
              class="insert"
              @click.stop="insertAfter(index)"
            >
              <i class="el-icon-plus" /> 添加字段
            </button>
          </article>
          <el-empty
            v-if="!schema.fields.length"
            :image-size="58"
            description="从左侧选择字段组件开始设计表单"
          />
          <footer>
            <b>系统自动字段（不可删除）</b>
            <div>
              <span>申请人 <i class="el-icon-lock" /></span
              ><span>申请部门 <i class="el-icon-lock" /></span
              ><span>提交时间 <i class="el-icon-lock" /></span
              ><small>（系统自动填充）</small>
            </div>
          </footer>
        </section>
      </main>

      <aside class="properties card">
        <h2>字段属性</h2>
        <template v-if="selected"
          ><el-tabs v-model="propertyTab"
            ><el-tab-pane label="基础" name="base"
              ><label
                >字段名称<el-input
                  v-model.trim="selected.label"
                  size="small"
                  :disabled="readonly" /></label
              ><label
                >字段标识<el-input
                  v-model.trim="selected.key"
                  size="small"
                  :disabled="readonly || publishedOnce"
                /><small v-if="publishedOnce">发布后不可修改</small></label
              ><label
                >字段类型<el-select
                  v-model="selected.type"
                  size="small"
                  disabled
                  ><el-option
                    :label="typeText(selected.type)"
                    :value="selected.type" /></el-select></label
              ><label
                >占位提示<el-input
                  v-model.trim="selected.placeholder"
                  size="small"
                  :disabled="readonly" /></label
              ><label
                >是否必填<el-radio-group
                  v-model="selected.required"
                  size="mini"
                  :disabled="readonly"
                  ><el-radio-button :label="true">是</el-radio-button
                  ><el-radio-button :label="false"
                    >否</el-radio-button
                  ></el-radio-group
                ></label
              ><label
                >默认值<el-input
                  v-model="selected.default_value"
                  size="small"
                  clearable
                  :disabled="readonly"
                  placeholder="选填，不自动预置" /></label
              ><label v-if="['select', 'multi_select'].includes(selected.type)"
                >选项（每行一个）<el-input
                  v-model="optionText"
                  type="textarea"
                  :rows="3"
                  :disabled="readonly"
                  @input="syncOptions" /></label
              ><label v-if="['text', 'textarea'].includes(selected.type)"
                >字符限制
                <div class="limit">
                  <el-input-number
                    v-model="selected.min_length"
                    size="small"
                    :min="0"
                    :max="selected.max_length || 2000"
                    :controls="false"
                    :disabled="readonly"
                  /><span>至</span
                  ><el-input-number
                    v-model="selected.max_length"
                    size="small"
                    :min="1"
                    :max="10000"
                    :controls="false"
                    :disabled="readonly"
                  /></div></label
              ><label
                >宽度<el-select
                  v-model="selected.width"
                  size="small"
                  :disabled="readonly"
                  ><el-option label="100%" value="100%" /><el-option
                    label="50%"
                    value="50%" /></el-select></label
              ><el-button
                type="success"
                size="small"
                :disabled="readonly"
                @click="applyField"
                >应用设置</el-button
              ></el-tab-pane
            ><el-tab-pane label="校验" name="validation"
              ><label
                >校验提示<el-input
                  v-model.trim="selected.validation_message"
                  type="textarea"
                  :rows="3"
                  :disabled="readonly"
                  placeholder="校验失败时显示的提示" /></label></el-tab-pane
            ><el-tab-pane label="显示条件" name="display"
              ><el-alert
                title="V1 暂不配置跨字段显示条件；字段始终显示。"
                type="info"
                :closable="false" /></el-tab-pane></el-tabs></template
        ><el-empty v-else :image-size="50" description="请选择表单字段" />
      </aside>
    </div>

    <div class="bottom-grid">
      <section class="card rules">
        <h2>表单版本规则</h2>
        <ul>
          <li>发布新版本后，生成不可变版本</li>
          <li>已运行中的申请将保留原表单快照</li>
          <li>新申请使用最新启用版本</li>
        </ul>
      </section>
      <section class="card usage">
        <h2>使用情况</h2>
        <div>
          <article>
            <i class="el-icon-connection" /><span
              >关联流程<b>{{ linkedFlowCount }}</b> 个</span
            >
          </article>
          <article>
            <i class="el-icon-document" /><span
              >申请记录<b>{{ template.submissions_count || 0 }}</b> 条</span
            >
          </article>
        </div>
      </section>
      <section class="card validation">
        <h2>发布前校验</h2>
        <div>
          <p v-for="item in validationItems" :key="item.text">
            <i
              :class="
                item.ok
                  ? 'el-icon-circle-check green'
                  : 'el-icon-warning orange'
              "
            />{{ item.text }}
          </p>
        </div>
      </section>
    </div>
  </div>
</template>

<script>
import {
  createApprovalForm,
  getApprovalForm,
  publishApprovalForm,
  updateApprovalForm,
  validateApprovalForm,
} from "@/api/erp/approval";
const uid = () =>
  `field_${Date.now()}_${Math.random().toString(36).slice(2, 7)}`;
const defaults = {
  text: { label: "单行文本", placeholder: "请输入内容", max_length: 200 },
  textarea: { label: "多行文本", placeholder: "请输入内容", max_length: 2000 },
  number: { label: "数字", placeholder: "请输入数字" },
  date: { label: "日期", placeholder: "请选择日期" },
  select: {
    label: "单选",
    placeholder: "请选择",
    options: [{ value: "option_1", label: "选项1" }],
  },
  multi_select: {
    label: "多选",
    placeholder: "请选择",
    options: [{ value: "option_1", label: "选项1" }],
  },
  user: { label: "人员", placeholder: "请选择人员" },
  department: { label: "部门", placeholder: "请选择部门" },
  attachment: { label: "附件", placeholder: "上传附件" },
  business_link: { label: "关联业务单据", placeholder: "请选择业务单据" },
};
const makeField = (type) => ({
  uid: uid(),
  key: `${type}_${Date.now().toString().slice(-6)}`,
  type,
  required: false,
  width: "100%",
  default_value: "",
  min_length: 0,
  validation_message: "",
  ...JSON.parse(JSON.stringify(defaults[type] || defaults.text)),
});
export default {
  name: "ApprovalFormEditor",
  data: () => ({
    loading: false,
    template: {},
    editingVersion: {},
    form: {
      form_code: "",
      form_name: "",
      business_module: "行政管理",
      description: "",
    },
    schema: { schema_version: 1, fields: [] },
    selectedIndex: -1,
    propertyTab: "base",
    fieldKeyword: "",
    previewDevice: "desktop",
    previewing: false,
    dragIndex: null,
    validation: { valid: false, errors: [] },
    modules: [
      "采购管理",
      "销售管理",
      "库存管理",
      "财务管理",
      "行政管理",
      "其他",
    ],
    paletteGroups: [
      {
        name: "基础字段",
        items: [
          { type: "text", label: "单行文本", icon: "el-icon-minus" },
          { type: "textarea", label: "多行文本", icon: "el-icon-edit-outline" },
          { type: "number", label: "数字", icon: "el-icon-s-operation" },
          { type: "date", label: "日期", icon: "el-icon-date" },
        ],
      },
      {
        name: "选择字段",
        items: [
          { type: "select", label: "单选", icon: "el-icon-view" },
          { type: "multi_select", label: "多选", icon: "el-icon-finished" },
          { type: "user", label: "人员", icon: "el-icon-user" },
          {
            type: "department",
            label: "部门",
            icon: "el-icon-office-building",
          },
        ],
      },
      {
        name: "其他",
        items: [
          { type: "attachment", label: "附件", icon: "el-icon-paperclip" },
          {
            type: "business_link",
            label: "关联业务单据",
            icon: "el-icon-document",
          },
        ],
      },
    ],
  }),
  computed: {
    isNew() {
      return !this.$route.params.id;
    },
    readonly() {
      return this.$route.query.mode === "view";
    },
    publishedOnce() {
      return Number(this.template.current_version || 0) > 0;
    },
    selected() {
      return this.schema.fields[this.selectedIndex] || null;
    },
    optionText: {
      get() {
        return ((this.selected && this.selected.options) || [])
          .map((row) => row.label)
          .join("\n");
      },
      set() {},
    },
    linkedFlowCount() {
      return Number(this.template.linked_flow_count || 0);
    },
    validationItems() {
      const keys = this.schema.fields.map((f) => f.key).filter(Boolean);
      return [
        {
          text: "表单字段标识唯一性",
          ok:
            keys.length === new Set(keys).size &&
            keys.length === this.schema.fields.length,
        },
        {
          text: "必填字段已设置标签",
          ok: this.schema.fields
            .filter((f) => f.required)
            .every((f) => f.label),
        },
        {
          text: "无非法默认值",
          ok: this.schema.fields.every((f) => this.defaultValid(f)),
        },
        {
          text: "表单布局校验通过",
          ok:
            this.schema.fields.length > 0 &&
            this.schema.fields.every((f) => f.label && f.type),
        },
      ];
    },
  },
  created() {
    this.load();
  },
  methods: {
    async load() {
      if (this.isNew) return;
      this.loading = true;
      try {
        const { data } = await getApprovalForm(this.$route.params.id);
        this.template = data.data || {};
        this.editingVersion = this.template.editing_version || {};
        ["form_code", "form_name", "business_module", "description"].forEach(
          (k) => {
            this.form[k] = this.template[k] || "";
          }
        );
        const saved = JSON.parse(
          JSON.stringify(this.editingVersion.schema_snapshot || {})
        );
        this.schema = {
          schema_version: 1,
          ...saved,
          fields: (saved.fields || []).map((row) => ({
            uid: uid(),
            width: "100%",
            required: false,
            default_value: "",
            min_length: 0,
            ...row,
          })),
        };
        this.selectedIndex = this.schema.fields.length ? 0 : -1;
        await this.validate(false);
      } finally {
        this.loading = false;
      }
    },
    addField(item) {
      const field = makeField(item.type);
      this.schema.fields.push(field);
      this.selectedIndex = this.schema.fields.length - 1;
    },
    insertAfter(index) {
      const field = makeField("text");
      this.schema.fields.splice(index + 1, 0, field);
      this.selectedIndex = index + 1;
    },
    copyField(index) {
      const field = JSON.parse(JSON.stringify(this.schema.fields[index]));
      field.uid = uid();
      field.key = `${field.key}_copy`;
      field.label += "（复制）";
      this.schema.fields.splice(index + 1, 0, field);
      this.selectedIndex = index + 1;
    },
    removeField(index) {
      this.schema.fields.splice(index, 1);
      this.selectedIndex = Math.min(index, this.schema.fields.length - 1);
    },
    clearFields() {
      this.$confirm("清空当前表单全部业务字段？", "清空确认", {
        type: "warning",
      })
        .then(() => {
          this.schema.fields = [];
          this.selectedIndex = -1;
        })
        .catch(() => {});
    },
    selectField(index) {
      if (!this.previewing) this.selectedIndex = index;
    },
    dropField(index) {
      if (this.dragIndex === null || this.dragIndex === index) return;
      const [field] = this.schema.fields.splice(this.dragIndex, 1);
      this.schema.fields.splice(index, 0, field);
      this.selectedIndex = index;
      this.dragIndex = null;
    },
    syncOptions(value) {
      if (!this.selected) return;
      this.selected.options = String(value || "")
        .split(/\r?\n/)
        .map((v) => v.trim())
        .filter(Boolean)
        .map((label, index) => ({ value: `option_${index + 1}`, label }));
    },
    applyField() {
      this.$message.success("字段属性已应用");
    },
    defaultValid(field) {
      if (
        field.default_value === "" ||
        field.default_value === null ||
        field.default_value === undefined
      )
        return true;
      if (["select", "multi_select"].includes(field.type))
        return (field.options || []).some(
          (row) =>
            row.value === field.default_value ||
            row.label === field.default_value
        );
      if (field.type === "number")
        return !Number.isNaN(Number(field.default_value));
      return true;
    },
    payload() {
      return {
        ...this.form,
        schema: {
          ...this.schema,
          fields: this.schema.fields.map(
            ({ uid: fieldUid, ...field }) => field
          ),
        },
      };
    },
    async save(silent = false) {
      const creating = this.isNew;
      const api = creating
        ? createApprovalForm
        : (v) => updateApprovalForm(this.template.id, v);
      const { data } = await api(this.payload());
      const saved = data.data || data;
      if (!silent) this.$message.success("自定义表单草稿已保存");
      if (creating) {
        this.template = saved;
        await this.$router.replace(`/approvals/forms/${saved.id}/edit`);
        await this.load();
        return saved;
      }
      await this.load();
      return saved;
    },
    async validate(notify = true) {
      const { data } = await validateApprovalForm(this.payload().schema);
      this.validation = data.data || {};
      if (notify)
        this.$message[this.validation.valid ? "success" : "warning"](
          this.validation.valid
            ? "表单完整性检查通过"
            : (this.validation.errors || [])[0] || "表单不完整"
        );
      return this.validation.valid;
    },
    async publish() {
      if (!(await this.validate(false)))
        return this.$message.error(
          (this.validation.errors || [])[0] || "表单不完整"
        );
      await this.$confirm(
        "发布后将生成不可变的新版本，确认发布？",
        "发布新版本",
        { type: "warning" }
      );
      if (this.isNew) {
        const saved = await this.save(true);
        await publishApprovalForm(saved.id, this.payload());
        this.$message.success("自定义表单新版本已发布");
        return;
      }
      await updateApprovalForm(this.template.id, this.payload());
      await publishApprovalForm(this.template.id, this.payload());
      this.$message.success("自定义表单新版本已发布");
      await this.load();
    },
    typeText(v) {
      return (
        {
          text: "单行文本",
          textarea: "多行文本",
          number: "数字",
          date: "日期",
          select: "单选",
          multi_select: "多选",
          user: "人员",
          department: "部门",
          attachment: "附件",
          business_link: "关联业务单据",
        }[v] || v
      );
    },
    statusText(v) {
      return (
        { enabled: "已启用", draft: "草稿", disabled: "已停用" }[v] || "草稿"
      );
    },
  },
};
</script>

<style scoped>
.form-editor {
  min-height: calc(100vh - 52px);
  padding: 12px 16px;
  background: #f7f8fa;
  color: #172535;
}
.page-head {
  height: 54px;
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
}
.page-head h1 {
  margin: 3px 0;
  font-size: 22px;
}
.form-editor > .el-alert {
  margin-bottom: 10px;
}
.card {
  min-width: 0;
  background: #fff;
  border: 1px solid #e1e6eb;
  border-radius: 6px;
}
.card h2 {
  margin: 0;
  font-size: 15px;
}
.basic {
  margin-bottom: 10px;
}
.basic > h2 {
  padding: 11px 14px 0;
}
.basic-grid {
  display: grid;
  grid-template-columns: 1fr 1fr 1fr 1.35fr 100px;
  gap: 22px;
  padding: 8px 14px 12px;
}
.basic-grid label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  font-size: 11px;
}
.basic-grid .el-select {
  width: 100%;
}
.status-label {
  justify-content: start;
}
.status-label .el-tag {
  align-self: flex-start;
  margin-top: 7px;
}
.designer-grid {
  display: grid;
  grid-template-columns: 260px minmax(520px, 1fr) 350px;
  gap: 10px;
}
.toolbox,
.properties {
  padding: 0 12px 14px;
}
.toolbox h2,
.properties > h2 {
  margin: 0 -12px 10px;
  padding: 12px;
  border-bottom: 1px solid #e5eaee;
}
.toolbox h3 {
  margin: 14px 0 8px;
  font-size: 12px;
}
.palette {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 8px;
}
.palette button,
.new-group {
  height: 42px;
  text-align: left;
  padding: 0 14px;
  border: 1px solid #dce3e9;
  border-radius: 5px;
  background: #fff;
  color: #26374a;
  cursor: pointer;
}
.palette button:hover {
  border-color: #0aa05a;
  color: #0aa05a;
}
.palette i {
  margin-right: 9px;
  font-size: 16px;
}
.new-group {
  width: 100%;
  margin-top: 16px;
  text-align: center;
  border-style: dashed;
}
.canvas > header {
  height: 48px;
  padding: 0 12px;
  display: flex;
  align-items: center;
  justify-content: space-between;
  border-bottom: 1px solid #e5eaee;
}
.canvas > header > div {
  display: flex;
  align-items: center;
  gap: 14px;
}
.form-preview {
  width: calc(100% - 24px);
  min-height: 470px;
  margin: 10px 12px;
  padding: 14px 12px 0;
  border: 1px solid #dde4ea;
  border-radius: 5px;
  background: #fff;
}
.form-preview.mobile {
  max-width: 430px;
  margin-left: auto;
  margin-right: auto;
}
.form-preview > h1 {
  text-align: center;
  margin: 0 0 12px;
  font-size: 19px;
}
.form-preview article {
  position: relative;
  padding: 8px 18px 8px 24px;
  margin-bottom: 6px;
  border: 1px solid transparent;
  border-radius: 5px;
}
.form-preview article.selected {
  border-color: #0aa05a;
  background: #f9fffb;
}
.form-preview article > .drag {
  position: absolute;
  left: 6px;
  top: 50%;
  transform: translateY(-50%);
  color: #0aa05a;
}
.form-preview article > label {
  display: grid;
  grid-template-columns: 95px 1fr;
  gap: 10px;
  align-items: start;
  font-size: 12px;
}
.form-preview article > label > span {
  padding-top: 9px;
}
.form-preview em {
  color: #e55353;
  font-style: normal;
}
.control .el-select,
.control .el-date-editor,
.control .el-input-number {
  width: 100%;
}
.upload {
  height: 54px;
  display: grid;
  place-items: center;
  border: 1px dashed #ccd6df;
  color: #637386;
}
.field-actions {
  position: absolute;
  right: 4px;
  top: 0;
}
.field-actions .el-button {
  padding: 5px;
}
.insert {
  position: absolute;
  left: 50%;
  bottom: -14px;
  z-index: 2;
  transform: translateX(-50%);
  height: 24px;
  padding: 0 10px;
  border: 1px solid #b9e3ca;
  border-radius: 4px;
  background: #ecfaf2;
  color: #18824d;
  font-size: 11px;
}
.form-preview footer {
  margin-top: 10px;
  padding: 10px;
  border: 1px solid #e0e6eb;
  background: #fafbfc;
}
.form-preview footer b {
  display: block;
  margin-bottom: 8px;
  font-size: 12px;
}
.form-preview footer div {
  display: flex;
  align-items: center;
  gap: 8px;
}
.form-preview footer span {
  padding: 6px 12px;
  border: 1px solid #dce3e9;
  border-radius: 4px;
  font-size: 11px;
}
.form-preview footer small {
  color: #8b98a5;
}
.previewing article {
  cursor: default;
}
.properties ::v-deep .el-tabs__header {
  margin: 0 0 12px;
}
.properties label {
  display: flex;
  flex-direction: column;
  gap: 6px;
  margin-bottom: 11px;
  font-size: 11px;
}
.properties label small {
  color: #8a96a3;
}
.properties .el-select {
  width: 100%;
}
.properties .limit {
  display: grid;
  grid-template-columns: 1fr auto 1fr;
  gap: 8px;
  align-items: center;
}
.properties .limit .el-input-number {
  width: 100%;
}
.properties .el-button--success {
  display: block;
  width: 126px;
  margin: 16px auto 0;
}
.bottom-grid {
  display: grid;
  grid-template-columns: 0.9fr 1.1fr 1.25fr;
  gap: 10px;
  margin-top: 10px;
}
.bottom-grid section {
  min-height: 112px;
  padding: 12px 14px;
}
.bottom-grid h2 {
  margin-bottom: 10px;
}
.rules ul {
  margin: 0;
  padding-left: 18px;
  font-size: 11px;
  line-height: 1.9;
}
.usage > div {
  display: flex;
  justify-content: space-around;
}
.usage article {
  display: flex;
  align-items: center;
  gap: 12px;
}
.usage article > i {
  font-size: 30px;
}
.usage span {
  font-size: 12px;
}
.usage b {
  display: inline-block;
  margin: 0 5px;
  font-size: 24px;
}
.validation > div {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: 10px;
}
.validation p {
  margin: 0;
  font-size: 11px;
}
.validation p i {
  margin-right: 7px;
}
.green {
  color: #0aa05a;
}
.orange {
  color: #ee8b14;
}
.danger {
  color: #e55353 !important;
}
@media (max-width: 1500px) {
  .designer-grid {
    grid-template-columns: 220px minmax(450px, 1fr) 310px;
  }
  .basic-grid {
    grid-template-columns: 1fr 1fr 1fr 1.2fr 80px;
    gap: 10px;
  }
  .form-preview article > label {
    grid-template-columns: 80px 1fr;
  }
  .palette button {
    padding: 0 9px;
  }
}
@media (max-width: 1180px) {
  .designer-grid {
    grid-template-columns: 1fr;
  }
  .toolbox .palette {
    grid-template-columns: repeat(5, 1fr);
  }
  .properties {
    min-height: 240px;
  }
  .basic-grid {
    grid-template-columns: repeat(2, 1fr);
  }
  .bottom-grid {
    grid-template-columns: 1fr;
  }
}
@media (max-width: 720px) {
  .page-head {
    height: auto;
    flex-direction: column;
    gap: 10px;
    margin-bottom: 10px;
  }
  .basic-grid,
  .toolbox .palette {
    grid-template-columns: 1fr;
  }
  .form-preview article > label {
    grid-template-columns: 1fr;
  }
  .validation > div {
    grid-template-columns: 1fr;
  }
}
</style>
