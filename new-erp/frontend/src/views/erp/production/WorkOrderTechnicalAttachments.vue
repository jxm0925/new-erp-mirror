<template>
  <section class="technical-attachments">
    <h3>技术附件</h3>
    <div v-if="editable" class="attachment-upload">
      <el-upload action="#" :show-file-list="false" :http-request="upload" :disabled="uploading" accept=".pdf,.png,.jpg,.jpeg,.gif,.webp,.dwg,.dxf,.step,.stp,.igs,.iges">
        <el-button type="success" plain size="small" :loading="uploading">上传附件</el-button>
      </el-upload>
      <span>图片、PDF、CAD文件，单个不超过20MB。</span>
    </div>
    <div class="attachment-list">
      <div v-for="file in value" :key="file.id" class="attachment-row">
        <i :class="file.mime_type==='application/pdf' ? 'el-icon-document pdf-icon' : 'el-icon-document'" />
        <span class="attachment-name">{{ file.original_name }}</span>
        <span class="attachment-size">{{ fileSize(file.file_size) }}</span>
        <div class="attachment-actions">
          <el-button v-if="file.previewable" type="text" size="small" :disabled="reading===file.id" @click="openFile(file, false)">查看</el-button>
          <el-button type="text" size="small" :disabled="reading===file.id" @click="openFile(file, true)">下载</el-button>
          <el-button v-if="editable" type="text" size="small" :disabled="uploading" @click="$emit('input',value.filter(row=>row.id!==file.id))">移除</el-button>
        </div>
      </div>
      <div v-if="!value.length" class="attachment-empty">暂无技术附件</div>
    </div>
    <p v-if="editable" class="attachment-tip"><i class="el-icon-info" /> 附件随资料版本保存，历史版本可查看原附件。</p>
  </section>
</template>

<script>
import { uploadWorkOrderTechnicalAttachment, readWorkOrderTechnicalAttachment } from '../../../api/erp/production'
export default {
  name: 'WorkOrderTechnicalAttachments',
  props: { workOrderId: { type:Number,required:true }, version: { type:Number,default:1 }, value: { type:Array,default:()=>[] }, editable: { type:Boolean,default:false } },
  data: () => ({ uploading:false, reading:null }),
  created() { this.uploadRequests = new Map(); this.blobUrls = [] },
  beforeDestroy() { this.blobUrls.forEach(url=>URL.revokeObjectURL(url)) },
  methods: {
    fileSize(size) { return size >= 1048576 ? `${(size/1048576).toFixed(2)} MB` : `${Math.max(1,Math.ceil(size/1024))} KB` },
    async upload(request) {
      if(!this.editable || this.uploading) return;
      if(this.value.length>=20) return this.$message.error('每个资料版本最多保留20个附件');
      if(request.file.size>20*1024*1024) return this.$message.error('单个附件不能超过20MB');
      this.uploading=true; this.$emit('uploading',true);
      try {
        const bytes=await request.file.arrayBuffer();
        const hash=Array.from(new Uint8Array(await crypto.subtle.digest('SHA-256',bytes))).map(n=>n.toString(16).padStart(2,'0')).join('');
        const key=`${request.file.name}:${hash}`;
        // 返回丢失时，重新选择同一文件仍重放原命令与版本，不创建第二条附件。
        let command=this.uploadRequests.get(key);
        if(!command) { command={id:`technical-file-${Date.now()}-${Math.random().toString(16).slice(2)}`,version:this.version}; this.uploadRequests.set(key,command) }
        const form=new FormData(); form.append('file',request.file); form.append('client_command_id',command.id); form.append('expected_version',command.version);
        try {
          const response=await uploadWorkOrderTechnicalAttachment(this.workOrderId,form);
          const row=response.data.data;
          if(!this.value.some(file=>file.id===row.id)) this.$emit('input',this.value.concat(row));
          request.onSuccess(response.data); this.$message.success('附件已上传，请确认生产资料保存版本');
        } catch(error) {
          if(error.response && error.response.status>=400 && error.response.status<500) this.uploadRequests.delete(key);
          throw error;
        }
      } catch(error) { request.onError(error); this.$message.error(error.userMessage||'附件上传失败，请重新选择原文件重试') }
      finally { this.uploading=false; this.$emit('uploading',false) }
    },
    async openFile(file,download) {
      if(this.reading!==null) return;
      // 在点击时打开预览页，避免异步读取后被浏览器当成弹窗拦截。
      const preview=download ? null : window.open('', '_blank');
      if(preview) preview.opener=null;
      this.reading=file.id;
      try {
        const response=await readWorkOrderTechnicalAttachment(this.workOrderId,file.id,download);
        const url=URL.createObjectURL(response.data); this.blobUrls.push(url);
        if(preview) preview.location.replace(url);
        else { const link=document.createElement('a'); link.href=url; link.download=file.original_name; document.body.appendChild(link); link.click(); link.remove() }
      } catch(error) { if(preview) preview.close(); this.$message.error(error.userMessage||'附件读取失败') }
      finally { this.reading=null }
    }
  }
}
</script>

<style scoped>
.technical-attachments{min-width:0;margin-bottom:34px;color:#243b57}.technical-attachments h3{font-size:17px;margin:5px 0 14px;color:#122c44}.attachment-upload{display:flex;align-items:center;gap:16px;margin-bottom:10px;flex-wrap:wrap}.attachment-upload>span,.attachment-tip{font-size:13px;color:#8390a2}.attachment-upload .el-button--success.is-plain{color:#07883f;background:#fff;border-color:#07883f}.attachment-list{border:1px solid #e1e7ef;border-radius:3px}.attachment-row{display:grid;grid-template-columns:24px minmax(0,1fr) 100px 220px;align-items:center;gap:14px;padding:10px 18px;font-size:14px}.attachment-row+.attachment-row{border-top:1px solid #e1e7ef}.attachment-row>i{font-size:23px;color:#1683df}.attachment-row>i.pdf-icon{color:#ec5555}.attachment-name{overflow-wrap:anywhere;min-width:0}.attachment-actions{display:flex;justify-content:flex-end;gap:18px;white-space:nowrap}.attachment-actions .el-button+.el-button{margin-left:0}.attachment-empty{padding:15px 18px;font-size:13px;color:#8390a2}.attachment-tip{margin:12px 0 0}.attachment-tip i{margin-right:5px}@media(max-width:767px){.attachment-row{grid-template-columns:22px minmax(0,1fr);gap:7px;padding:12px}.attachment-size{grid-column:2;font-size:12px;color:#8390a2}.attachment-actions{grid-column:1/-1;gap:20px}.attachment-upload{gap:9px}.attachment-tip{line-height:1.6}}
</style>
