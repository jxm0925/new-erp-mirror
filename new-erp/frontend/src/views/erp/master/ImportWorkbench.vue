<template>
  <section class="master-page import-page">
    <div class="import-steps"><div v-for="(step,i) in steps" :key="step" :class="{active:stage===i,done:stage>i}"><b>{{stage>i?'✓':i+1}}</b><span>{{step}}<small>{{stage===i?'当前步骤':stage>i?'已完成':'待处理'}}</small></span></div></div>
    <div v-if="!batch" class="upload-surface">
      <h1>导入工作台</h1><p>上传 Excel，先预检并修正错误，再确认导入正确数据。</p>
      <el-form label-position="top" class="upload-form"><el-form-item label="导入类型"><el-select v-model="importType" style="width:100%"><el-option v-for="type in types" :key="type" :label="type" :value="type" /></el-select></el-form-item>
      <el-form-item label="选择文件"><el-upload drag action="#" :auto-upload="false" :limit="1" :on-change="fileChanged" :on-remove="()=>file=null" accept=".xlsx,.xls,.csv"><i class="el-icon-upload" /><div class="el-upload__text">将文件拖到此处，或<em>点击选择</em></div><div slot="tip" class="el-upload__tip">支持 xlsx、xls、csv，单个文件不超过 10MB</div></el-upload></el-form-item>
      <el-button type="success" :loading="busy" :disabled="!file" @click="upload">上传并开始预检</el-button></el-form>
    </div>
    <template v-else>
      <div class="batch-strip"><div><span>文件名称</span><strong>{{batch.file_name}}</strong></div><div><span>导入类型</span><strong>{{batch.import_type}}</strong></div><div><span>批次号</span><strong>{{batch.batch_no}}</strong></div><el-button size="small" @click="reset">重新上传</el-button><el-button v-if="batch.error_rows" size="small" icon="el-icon-download" @click="exportErrors">下载错误明细</el-button></div>
      <div class="import-summary"><span>总行数 <b>{{batch.total_rows}}</b></span><span>可导入 <b class="ok">{{batch.valid_rows+batch.warning_rows}}</b></span><span>警告 <b class="warn">{{batch.warning_rows}}</b></span><span>错误 <b class="error">{{batch.error_rows}}</b></span></div>
      <div class="table-panel import-table">
        <div class="table-tabs"><button v-for="tab in tabs" :key="tab.value" :class="{active:filter===tab.value}" @click="filter=tab.value;loadRows()">{{tab.label}}</button></div>
        <el-table v-loading="busy" :data="rows" height="calc(100vh - 340px)" :row-class-name="rowClass">
          <el-table-column prop="row_no" label="行号" width="70" /><el-table-column label="导入数据" min-width="360"><template slot-scope="{row}"><div class="raw-values"><span v-for="(value,key) in row.raw_data" :key="key"><small>{{key}}</small>{{value}}</span></div></template></el-table-column>
          <el-table-column label="校验状态" width="100"><template slot-scope="{row}"><el-tag size="mini" :type="row.validation_status==='error'?'danger':row.validation_status==='warning'?'warning':'success'">{{row.validation_status==='error'?'错误':row.validation_status==='warning'?'警告':'可导入'}}</el-tag></template></el-table-column>
          <el-table-column prop="error_field" label="字段" width="120" /><el-table-column prop="error_reason" label="校验信息" min-width="180" /><el-table-column prop="suggestion" label="建议处理" min-width="180" />
        </el-table>
      </div>
      <div class="import-actions"><span><i class="el-icon-success" /> <b>{{batch.valid_rows+batch.warning_rows}}</b> 条正确数据可导入<small>导入只写入新系统主数据，历史来源信息不会保留。</small></span><el-button @click="reset">返回</el-button><el-button type="success" :loading="busy" :disabled="stage>1" @click="confirm">确认导入 {{batch.valid_rows+batch.warning_rows}} 条</el-button></div>
    </template>
  </section>
</template>
<script>
import { uploadImport, previewImport, importRows, confirmImport, errorExportUrl } from '../../../api/erp/master'
export default {
  data:()=>({steps:['上传文件','数据预检','确认导入','完成'],stage:0,types:['Product','SKU','Item','Supplier','Warehouse','Location','SKU-Item Relation','Legacy Mapping'],importType:'Product',file:null,batch:null,rows:[],busy:false,filter:'',tabs:[{label:'全部',value:''},{label:'仅错误',value:'error'},{label:'仅警告',value:'warning'},{label:'可导入',value:'valid'}]}),
  methods:{
    fileChanged(file){this.file=file.raw},
    async upload(){this.busy=true;try{const form=new FormData();form.append('file',this.file);form.append('import_type',this.importType);let{data}=await uploadImport(form);this.batch=data.data;this.stage=1;({data}=await previewImport(this.batch.id));this.batch=data.data;this.rows=data.rows.data;this.$message.success('预检完成')}catch(e){this.$message.error(e.userMessage)}finally{this.busy=false}},
    async loadRows(){if(!this.batch)return;this.busy=true;try{const{data}=await importRows(this.batch.id,{status:this.filter,per_page:100});this.rows=data.data}catch(e){this.$message.error(e.userMessage)}finally{this.busy=false}},
    async confirm(){try{await this.$confirm(`将导入 ${this.batch.valid_rows+this.batch.warning_rows} 条正确数据，错误行会跳过。确认继续？`,'确认导入',{type:'warning'});this.busy=true;const{data}=await confirmImport(this.batch.id);this.batch=data.data;this.stage=3;this.$message.success(data.message)}catch(e){if(e!=='cancel')this.$message.error(e.userMessage||'导入失败')}finally{this.busy=false}},
    exportErrors(){window.open(errorExportUrl(this.batch.id),'_blank')},
    reset(){this.stage=0;this.file=null;this.batch=null;this.rows=[]},
    rowClass({row}){return row.validation_status==='error'?'error-row':row.validation_status==='warning'?'warning-row':''}
  }
}
</script>
