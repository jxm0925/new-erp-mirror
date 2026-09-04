<template>
  <main class="invoice-form-page" v-loading="loading">
    <!-- 顶部操作栏 -->
    <header class="form-heading">
      <h1>登记进项发票</h1>
      <div class="heading-actions">
        <el-button class="btn-default" @click="back">返回列表</el-button>
        <el-button v-if="editable" class="btn-default" :loading="saving" @click="saveDraft">保存草稿</el-button>
        <el-button v-if="editable" type="primary" class="btn-green" :loading="saving" @click="saveAndMatch">
          保存并进入匹配
        </el-button>
      </div>
    </header>

    <!-- 顶部提示条 -->
    <div class="business-alert">
      <svg class="alert-info-icon" viewBox="0 0 20 20" fill="currentColor" width="16" height="16">
        <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .247.25v3.25a.75.75 0 0 0 1.5 0v-3.5A1.75 1.75 0 0 0 9.25 9H9Z" clip-rule="evenodd" />
      </svg>
      <span>发票登记仅用于记录发票信息，后续需在【发票匹配】中与采购订单、入库单等单据进行匹配后，才能进行付款分配。</span>
    </div>

    <!-- 主表单 -->
    <el-form ref="form" :model="form" :rules="rules" label-width="112px" class="invoice-form" size="small">
      <section class="form-grid">
        <!-- 左侧卡片：发票基本信息 -->
        <article class="form-card">
          <h2>发票基本信息</h2>

          <el-form-item label="系统发票记录号">
            <el-input :value="form.document_no ? `${form.document_no}（系统预生成）` : 'INV202508180001 (系统预生成)'" disabled class="disabled-input" />
          </el-form-item>

          <el-form-item label="发票号码" prop="invoice_no" required>
            <el-input v-model.trim="form.invoice_no" :disabled="!editable" placeholder="请输入发票号码" />
          </el-form-item>

          <el-form-item label="发票代码">
            <el-input v-model.trim="form.invoice_code" :disabled="!editable" placeholder="请输入发票代码" />
          </el-form-item>

          <el-form-item label="发票类型" prop="invoice_type" required>
            <el-select v-model="form.invoice_type" :disabled="!editable" placeholder="请选择发票类型" style="width: 100%;">
              <el-option label="增值税专用发票" value="vat_special" />
              <el-option label="增值税普通发票" value="vat_normal" />
              <el-option label="其他" value="other" />
            </el-select>
          </el-form-item>

          <el-form-item label="供应商" prop="party_id" required>
            <div class="supplier-control">
              <el-input
                :value="form.party_name_snapshot"
                readonly
                placeholder="请选择供应商"
                class="supplier-input"
                @click.native="editable && (pickerVisible = true)"
              />
              <el-button v-if="editable" class="btn-default select-supplier-btn" @click="pickerVisible = true">
                选择供应商
              </el-button>
            </div>
          </el-form-item>

          <el-form-item label="开票日期" prop="invoice_date" required>
            <el-date-picker
              v-model="form.invoice_date"
              :disabled="!editable"
              type="date"
              value-format="yyyy-MM-dd"
              placeholder="选择开票日期"
              style="width: 100%;"
            />
          </el-form-item>

          <el-form-item label="收票日期" prop="received_date" required>
            <el-date-picker
              v-model="form.received_date"
              :disabled="!editable"
              type="date"
              value-format="yyyy-MM-dd"
              placeholder="选择收票日期"
              style="width: 100%;"
            />
          </el-form-item>

          <el-form-item label="币种">
            <el-input value="人民币 CNY" disabled class="disabled-input" />
          </el-form-item>
        </article>

        <!-- 右侧卡片：金额与税额 -->
        <article class="form-card amount-card">
          <h2>金额与税额</h2>

          <el-form-item label="未税金额" prop="amount_excl_tax" required>
            <el-input :value="form.amount_excl_tax" disabled placeholder="由税率明细自动汇总">
              <template slot="append">CNY</template>
            </el-input>
          </el-form-item>

          <el-form-item label="税额" prop="tax_amount" required>
            <el-input :value="form.tax_amount" disabled placeholder="由税率明细自动计算">
              <template slot="append">CNY</template>
            </el-input>
          </el-form-item>

          <el-form-item label="价税合计" required>
            <el-input :value="form.amount_incl_tax ? money(form.amount_incl_tax) : '0.00'" disabled class="disabled-input">
              <template slot="append">CNY</template>
            </el-input>
          </el-form-item>

          <el-form-item label="税率/明细" class="tax-detail-item" required>
            <div class="tax-table-wrapper">
              <table class="tax-table">
                <thead>
                  <tr>
                    <th class="th-index">序号</th>
                    <th class="th-rate">税率(%)</th>
                    <th class="th-amount">未税金额（CNY）</th>
                    <th class="th-tax">税额（CNY）</th>
                    <th class="th-op">操作</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="(row, index) in taxRows" :key="index">
                    <td class="col-center">{{ index + 1 }}</td>
                    <td class="col-center">
                      <el-select v-model="row.tax_rate" :disabled="!editable" size="small" placeholder="选择税率" class="cell-select-rate" @change="syncTaxRow(row)">
                        <el-option label="0%" :value="0" />
                        <el-option label="1%" :value="1" />
                        <el-option label="3%" :value="3" />
                        <el-option label="6%" :value="6" />
                        <el-option label="9%" :value="9" />
                        <el-option label="13%" :value="13" />
                      </el-select>
                    </td>
                    <td class="col-center">
                      <el-input v-model.trim="row.amount_excl_tax" :disabled="!editable" size="small" placeholder="请输入未税金额" class="cell-input-excl" @input="syncTaxRow(row)" />
                    </td>
                    <td class="col-center">
                      <el-input :value="row.tax_amount" disabled size="small" placeholder="按税率自动计算" class="cell-input-tax" />
                    </td>
                    <td class="col-center">
                      <el-button v-if="editable" type="text" class="btn-action-blue" @click="removeTaxRow(index)">
                        删除
                      </el-button>
                    </td>
                  </tr>
                </tbody>
              </table>
              <button v-if="editable" type="button" class="btn-add-tax" @click="taxRows.push({ tax_rate: '', amount_excl_tax: '', tax_amount: '' })">
                <i class="el-icon-plus" /> 添加税率明细
              </button>
            </div>
          </el-form-item>

          <el-form-item label="备注" class="remark-form-item">
            <el-input
              v-model.trim="form.remark"
              :disabled="!editable"
              type="textarea"
              :rows="3"
              maxlength="200"
              show-word-limit
              placeholder="请输入备注（选填）"
            />
          </el-form-item>
        </article>
      </section>
    </el-form>

    <!-- 下方区域：发票附件与保存前校验 -->
    <section class="bottom-grid">
      <!-- 左侧：发票附件 -->
      <article class="attachments-card">
        <h2>发票附件</h2>
        <div class="attachment-grid">
          <!-- 1. 发票扫描件 -->
          <div class="attachment-column">
            <div class="attachment-col-title">发票扫描件（必传）</div>
            <el-upload
              v-if="editable"
              action="#"
              :show-file-list="false"
              :http-request="req => uploadAttachment('invoice_scan', req)"
              class="upload-box"
              drag
            >
              <svg class="upload-cloud-icon" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#1890ff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
                <path d="m12 13 3-3 3 3"/>
                <path d="M15 10v7"/>
              </svg>
              <div class="upload-primary-text">点击或拖拽文件上传</div>
              <div class="upload-secondary-text">支持 PDF、JPG、PNG，单个文件不超过 10MB</div>
            </el-upload>
            <div v-else class="upload-box muted">
              <svg class="upload-cloud-icon" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#9ca3af" stroke-width="1.8">
                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
              </svg>
              <div class="upload-primary-text">{{ attachmentsBy('invoice_scan').length ? '已上传附件' : '保存草稿后可上传附件' }}</div>
            </div>

            <!-- 文件列表 -->
            <div v-if="attachmentsBy('invoice_scan').length" class="attachment-file-list">
              <div v-for="item in attachmentsBy('invoice_scan')" :key="item.id || item.original_name" class="file-item-row">
                <div class="badge-tag badge-red">Pdf</div>
                <div class="file-info-block">
                  <span class="file-title" :title="item.original_name">{{ item.original_name }}</span>
                  <span class="file-size-text">{{ formatSize(item.file_size || item.size) }}</span>
                </div>
                <div class="file-action-btns">
                  <button type="button" class="btn-tool" title="预览" @click="previewAttachment(item)">
                    <i class="el-icon-view" />
                  </button>
                  <button v-if="editable" type="button" class="btn-tool danger" title="删除" @click="removeAttachment(item)">
                    <i class="el-icon-delete" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- 2. 合同/对账凭证 -->
          <div class="attachment-column">
            <div class="attachment-col-title">合同/对账凭证（选传）</div>
            <el-upload
              v-if="editable"
              action="#"
              :show-file-list="false"
              :http-request="req => uploadAttachment('reconciliation_voucher', req)"
              class="upload-box"
              drag
            >
              <svg class="upload-cloud-icon" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#1890ff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
                <path d="m12 13 3-3 3 3"/>
                <path d="M15 10v7"/>
              </svg>
              <div class="upload-primary-text">点击或拖拽文件上传</div>
              <div class="upload-secondary-text">支持 PDF、JPG、PNG，单个文件不超过 10MB</div>
            </el-upload>
            <div v-else class="upload-box muted">
              <svg class="upload-cloud-icon" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#9ca3af" stroke-width="1.8">
                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
              </svg>
              <div class="upload-primary-text">{{ attachmentsBy('reconciliation_voucher').length ? '已上传附件' : '保存草稿后可上传附件' }}</div>
            </div>

            <!-- 文件列表 -->
            <div v-if="attachmentsBy('reconciliation_voucher').length" class="attachment-file-list">
              <div v-for="item in attachmentsBy('reconciliation_voucher')" :key="item.id || item.original_name" class="file-item-row">
                <div class="badge-tag badge-blue">Pdf</div>
                <div class="file-info-block">
                  <span class="file-title" :title="item.original_name">{{ item.original_name }}</span>
                  <span class="file-size-text">{{ formatSize(item.file_size || item.size) }}</span>
                </div>
                <div class="file-action-btns">
                  <button type="button" class="btn-tool" title="预览" @click="previewAttachment(item)">
                    <i class="el-icon-view" />
                  </button>
                  <button v-if="editable" type="button" class="btn-tool danger" title="删除" @click="removeAttachment(item)">
                    <i class="el-icon-delete" />
                  </button>
                </div>
              </div>
            </div>
          </div>

          <!-- 3. 其他附件 -->
          <div class="attachment-column">
            <div class="attachment-col-title">其他附件（选传）</div>
            <el-upload
              v-if="editable"
              action="#"
              :show-file-list="false"
              :http-request="req => uploadAttachment('other', req)"
              class="upload-box"
              drag
            >
              <svg class="upload-cloud-icon" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#1890ff" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">
                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
                <path d="m12 13 3-3 3 3"/>
                <path d="M15 10v7"/>
              </svg>
              <div class="upload-primary-text">点击或拖拽文件上传</div>
              <div class="upload-secondary-text">支持 PDF、JPG、PNG，单个文件不超过 10MB</div>
            </el-upload>
            <div v-else class="upload-box muted">
              <svg class="upload-cloud-icon" viewBox="0 0 24 24" width="26" height="26" fill="none" stroke="#9ca3af" stroke-width="1.8">
                <path d="M17.5 19H9a7 7 0 1 1 6.71-9h1.79a4.5 4.5 0 1 1 0 9Z"/>
              </svg>
              <div class="upload-primary-text">{{ attachmentsBy('other').length ? '已上传附件' : '保存草稿后可上传附件' }}</div>
            </div>

            <!-- 文件列表 -->
            <div v-if="attachmentsBy('other').length" class="attachment-file-list">
              <div v-for="item in attachmentsBy('other')" :key="item.id || item.original_name" class="file-item-row">
                <div class="badge-tag badge-green">Xl</div>
                <div class="file-info-block">
                  <span class="file-title" :title="item.original_name">{{ item.original_name }}</span>
                  <span class="file-size-text">{{ formatSize(item.file_size || item.size) }}</span>
                </div>
                <div class="file-action-btns">
                  <button type="button" class="btn-tool" title="预览" @click="previewAttachment(item)">
                    <i class="el-icon-view" />
                  </button>
                  <button v-if="editable" type="button" class="btn-tool danger" title="删除" @click="removeAttachment(item)">
                    <i class="el-icon-delete" />
                  </button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </article>

      <!-- 右侧：保存前校验 -->
      <article class="validation-card">
        <h2>保存前校验</h2>
        <div class="validation-checklist">
          <div class="check-item">
            <svg v-if="form.party_id && form.party_name_snapshot" class="check-svg pass" viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
            </svg>
            <svg v-else class="check-svg fail" viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd" />
            </svg>
            <span class="chk-label">供应商</span>
            <span class="chk-val">{{ form.party_id && form.party_name_snapshot ? `已选择：${form.party_name_snapshot}` : '未选择' }}</span>
          </div>

          <div class="check-item">
            <svg class="check-svg pass" viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
            </svg>
            <span class="chk-label">币种</span>
            <span class="chk-val">人民币 (CNY)</span>
          </div>

          <div class="check-item">
            <svg v-if="amountValid" class="check-svg pass" viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
            </svg>
            <svg v-else class="check-svg fail" viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd" />
            </svg>
            <span class="chk-label">金额校验</span>
            <span class="chk-val">价税合计 = 未税金额 + 税额</span>
          </div>

          <div class="check-item">
            <svg v-if="amountUnderLimit" class="check-svg pass" viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16Zm3.857-9.809a.75.75 0 0 0-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 1 0-1.06 1.061l2.5 2.5a.75.75 0 0 0 1.137-.089l4-5.5Z" clip-rule="evenodd" />
            </svg>
            <svg v-else class="check-svg fail" viewBox="0 0 20 20" width="16" height="16" fill="currentColor">
              <path fill-rule="evenodd" d="M10 18a8 8 0 1 0 0-16 8 8 0 0 0 0 16ZM8.28 7.22a.75.75 0 0 0-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 1 0 1.06 1.06L10 11.06l1.72 1.72a.75.75 0 1 0 1.06-1.06L11.06 10l1.72-1.72a.75.75 0 0 0-1.06-1.06L10 8.94 8.28 7.22Z" clip-rule="evenodd" />
            </svg>
            <span class="chk-label">金额限制</span>
            <span class="chk-val">单张发票价税合计不超过 1,000,000.00 CNY</span>
          </div>
        </div>

        <div class="validation-note-box">
          <svg class="alert-info-icon" viewBox="0 0 20 20" width="15" height="15" fill="currentColor">
            <path fill-rule="evenodd" d="M18 10a8 8 0 1 1-16 0 8 8 0 0 1 16 0Zm-7-4a1 1 0 1 1-2 0 1 1 0 0 1 2 0ZM9 9a.75.75 0 0 0 0 1.5h.253a.25.25 0 0 1 .247.25v3.25a.75.75 0 0 0 1.5 0v-3.5A1.75 1.75 0 0 0 9.25 9H9Z" clip-rule="evenodd" />
          </svg>
          <span>业务提示：请确保发票信息准确完整，以便后续匹配和入账。</span>
        </div>
      </article>
    </section>

    <!-- 选择供应商弹窗 -->
    <el-dialog title="选择供应商" :visible.sync="pickerVisible" width="884px" top="18vh" :close-on-click-modal="false" class="supplier-picker-dialog">
      <div class="picker-filters">
        <label>
          <span>供应商编码 / 名称</span>
          <el-input v-model.trim="supplierKeyword" size="small" placeholder="请输入供应商编码 / 名称" clearable @keyup.enter.native="searchSuppliers" />
        </label>
        <label>
          <span>供应商状态</span>
          <el-select v-model="supplierStatus" size="small" placeholder="请选择状态">
            <el-option label="全部" value="" />
            <el-option label="启用" value="enabled" />
            <el-option label="停用" value="disabled" />
          </el-select>
        </label>
        <el-button size="small" class="btn-default" @click="resetSuppliers">重置</el-button>
        <el-button size="small" class="btn-green" @click="searchSuppliers">查询</el-button>
      </div>

      <el-table v-loading="supplierLoading" :data="supplierRows" border height="246" class="picker-table" @selection-change="rows => supplierSelection = rows">
        <el-table-column type="selection" label="选择" width="55" align="center" />
        <el-table-column prop="supplier_code" label="供应商编码" min-width="140" />
        <el-table-column prop="supplier_name" label="供应商名称" min-width="200" />
        <el-table-column prop="contact_name" label="联系人" min-width="120" />
        <el-table-column prop="contact_phone" label="联系电话" min-width="130" />
        <el-table-column label="状态" width="90" align="center">
          <template slot-scope="{row}">
            <span :class="row.status === 'enabled' ? 'status-enabled' : 'status-disabled'">
              {{ row.status === 'enabled' ? '启用' : '停用' }}
            </span>
          </template>
        </el-table-column>
      </el-table>

      <div class="picker-footer">
        <span class="total-text">共 {{ supplierTotal }} 条</span>
        <el-pagination
          background
          layout="prev, pager, next, sizes, jumper"
          :current-page="supplierPage"
          :page-size="supplierPerPage"
          :page-sizes="[5, 10, 20]"
          :total="supplierTotal"
          @current-change="p => { supplierPage = p; loadSuppliers() }"
          @size-change="s => { supplierPerPage = s; supplierPage = 1; loadSuppliers() }"
        />
        <div class="dialog-actions">
          <el-button size="small" class="btn-default" @click="pickerVisible = false">取消</el-button>
          <el-button size="small" class="btn-green" @click="confirmSupplier">确认选择</el-button>
        </div>
      </div>
    </el-dialog>
  </main>
</template>

<script>
import { listEntity } from '../../../api/erp/master'
import { reserveForCreatePage, clearCreatePageReservation } from '../../../utils/documentNumberReservation'
import {
  getFinanceInvoice,
  createFinanceInvoice,
  updateFinanceInvoice,
  uploadFinanceInvoiceAttachment,
  deleteFinanceInvoiceAttachment,
  previewFinanceAttachment
} from '../../../api/erp/finance'

const empty = () => ({
  id: null,
  document_no: '',
  invoice_no: '',
  invoice_code: '',
  invoice_type: '',
  party_type: 'supplier',
  party_id: null,
  party_name_snapshot: '',
  invoice_date: '',
  received_date: '',
  currency: 'CNY',
  amount_excl_tax: '',
  tax_amount: '',
  amount_incl_tax: '',
  remark: '',
  attachments: []
})

export default {
  name: 'FinanceInvoiceForm',
  data: () => ({
    loading: false,
    saving: false,
    reservation: null,
    form: empty(),
    taxRows: [{ tax_rate: '', amount_excl_tax: '', tax_amount: '' }],
    pickerVisible: false,
    supplierLoading: false,
    supplierRows: [],
    supplierSelection: [],
    supplierKeyword: '',
    supplierStatus: 'enabled',
    supplierPage: 1,
    supplierPerPage: 5,
    supplierTotal: 0,
    rules: {
      invoice_no: [{ required: true, message: '请输入发票号码', trigger: 'blur' }],
      invoice_type: [{ required: true, message: '请选择发票类型', trigger: 'change' }],
      party_id: [{ required: true, message: '请选择供应商', trigger: 'change' }],
      invoice_date: [{ required: true, message: '请选择开票日期', trigger: 'change' }],
      received_date: [{ required: true, message: '请选择收票日期', trigger: 'change' }],
      amount_excl_tax: [{ required: true, message: '请通过税率明细填写未税金额', trigger: 'change' }],
      tax_amount: [{ required: true, message: '请通过税率明细计算税额', trigger: 'change' }]
    }
  }),
  computed: {
    id() {
      return Number(this.$route.params.id || 0)
    },
    editable() {
      return this.$route.query.mode !== 'view' && (!this.id || this.form.status === 'draft')
    },
    amountValid() {
      const excl = Number(this.form.amount_excl_tax || 0)
      const tax = Number(this.form.tax_amount || 0)
      const incl = Number(this.form.amount_incl_tax || 0)
      if (incl <= 0 || this.form.amount_excl_tax === '' || this.form.tax_amount === '') return false
      return Math.abs(excl + tax - incl) < 0.0001
    },
    amountUnderLimit() {
      const incl = Number(this.form.amount_incl_tax || 0)
      return incl <= 1000000
    },
    taxDetailValid() {
      const rows = this.taxRows || []
      if (!rows.length || rows.some(row => row.tax_rate === '' || row.amount_excl_tax === '' || row.tax_amount === '')) return false
      const excl = rows.reduce((sum, row) => sum + Number(row.amount_excl_tax || 0), 0)
      const tax = rows.reduce((sum, row) => sum + Number(row.tax_amount || 0), 0)
      return Math.abs(excl - Number(this.form.amount_excl_tax || 0)) < 0.0001 && Math.abs(tax - Number(this.form.tax_amount || 0)) < 0.0001
    }
  },
  created() {
    this.init()
  },
  watch: {
    '$route.fullPath'() {
      this.init()
    }
  },
  methods: {
    async init() {
      this.loading = true
      try {
        if (this.id) {
          const r = await getFinanceInvoice(this.id)
          this.form = { ...empty(), ...r.data.data }
          this.taxRows = this.hydrateTaxRows(this.form.tax_detail)
        } else {
          this.form = empty()
          this.taxRows = [{ tax_rate: '', amount_excl_tax: '', tax_amount: '' }]
          this.reservation = await reserveForCreatePage('finance_invoice', '/finance/invoices/create')
          this.form.document_no = this.reservation.document_no
        }
        await this.loadSuppliers()
      } catch (e) {
        this.$message.error(e.userMessage || '发票页面加载失败')
      } finally {
        this.loading = false
      }
    },
    money(v) {
      return Number(v || 0).toLocaleString('zh-CN', { minimumFractionDigits: 2, maximumFractionDigits: 2 })
    },
    hydrateTaxRows(rows) {
      const normalized = Array.isArray(rows) ? rows.map(x => ({ ...x })) : []
      const detailExcl = normalized.reduce((sum, row) => sum + Number(row.amount_excl_tax || 0), 0)
      const detailTax = normalized.reduce((sum, row) => sum + Number(row.tax_amount || 0), 0)
      const formExcl = Number(this.form.amount_excl_tax || 0)
      const formTax = Number(this.form.tax_amount || 0)
      const complete = normalized.length > 0 && normalized.every(row => row.tax_rate !== '' && row.amount_excl_tax !== '' && row.tax_amount !== '')
      if (complete && Math.abs(detailExcl - formExcl) < 0.0001 && Math.abs(detailTax - formTax) < 0.0001) return normalized

      // Historical drafts without usable tax-detail data remain editable:
      // recover a single derived line from their stored header amounts, then
      // require the user to verify it before saving.
      const rate = formExcl > 0 ? Number((formTax * 100 / formExcl).toFixed(4)) : 0
      return [{ tax_rate: rate, amount_excl_tax: formExcl ? formExcl.toFixed(4) : '', tax_amount: formTax.toFixed(4) }]
    },
    formatSize(bytes) {
      if (!bytes) return ''
      if (typeof bytes === 'string' && bytes.includes('B')) return bytes
      const num = Number(bytes)
      if (isNaN(num) || num <= 0) return ''
      if (num < 1024) return num + ' B'
      if (num < 1024 * 1024) return (num / 1024).toFixed(2) + ' KB'
      return (num / (1024 * 1024)).toFixed(2) + ' MB'
    },
    syncTaxRows() {
      const excl = this.taxRows.reduce((s, x) => s + Number(x.amount_excl_tax || 0), 0)
      const tax = this.taxRows.reduce((s, x) => s + Number(x.tax_amount || 0), 0)
      this.form.amount_excl_tax = excl.toFixed(4)
      this.form.tax_amount = tax.toFixed(4)
      this.form.amount_incl_tax = (excl + tax).toFixed(4)
    },
    syncTaxRow(row) {
      const rate = Number(row.tax_rate || 0)
      const excl = Number(row.amount_excl_tax || 0)
      row.tax_amount = (excl * rate / 100).toFixed(4)
      this.syncTaxRows()
    },
    removeTaxRow(i) {
      if (this.taxRows.length === 1) return this.$message.warning('至少保留一条税率明细')
      this.taxRows.splice(i, 1)
      this.syncTaxRows()
    },
    async loadSuppliers() {
      this.supplierLoading = true
      try {
        const r = await listEntity('suppliers', {
          keyword: this.supplierKeyword,
          status: this.supplierStatus,
          page: this.supplierPage,
          per_page: this.supplierPerPage
        })
        this.supplierRows = r.data.data || []
        this.supplierTotal = Number(r.data.total || 0)
      } catch (e) {
        this.$message.error(e.userMessage || '供应商加载失败')
      } finally {
        this.supplierLoading = false
      }
    },
    searchSuppliers() {
      this.supplierPage = 1
      this.loadSuppliers()
    },
    resetSuppliers() {
      this.supplierKeyword = ''
      this.supplierStatus = 'enabled'
      this.searchSuppliers()
    },
    confirmSupplier() {
      if (this.supplierSelection.length !== 1) return this.$message.warning('请选择一名供应商')
      const row = this.supplierSelection[0]
      this.form.party_id = row.id
      this.form.party_name_snapshot = row.supplier_name
      this.pickerVisible = false
      this.$refs.form && this.$refs.form.validateField('party_id', () => {})
    },
    payload() {
      return {
        ...this.form,
        invoice_direction: 'purchase',
        party_type: 'supplier',
        currency: 'CNY',
        tax_detail: this.taxRows.map(x => ({
          tax_rate: Number(x.tax_rate || 0),
          amount_excl_tax: Number(x.amount_excl_tax || 0),
          tax_amount: Number(x.tax_amount || 0)
        }))
      }
    },
    saveDraft() {
      return new Promise(resolve =>
        this.$refs.form.validate(async valid => {
          if (!valid || !this.amountValid || !this.amountUnderLimit || !this.taxDetailValid) {
            if (!this.amountValid) this.$message.error('请核对未税金额、税额与价税合计')
            if (!this.taxDetailValid) this.$message.error('请完整填写税率明细，并使明细金额与发票金额一致')
            if (!this.amountUnderLimit) this.$message.error('单张发票价税合计不得超过 1,000,000.00 CNY')
            return resolve(false)
          }
          this.saving = true
          try {
            const creating = !this.form.id
            const p = this.payload()
            const r = creating
              ? await createFinanceInvoice({
                  ...p,
                  reservation_token: this.reservation.reservation_token,
                  creation_session_id: this.reservation.creation_session_id
                })
              : await updateFinanceInvoice(this.form.id, p)
            this.form = { ...this.form, ...r.data.data }
            if (creating) {
              clearCreatePageReservation(this.reservation)
              await this.$router.replace(`/finance/invoices/${this.form.id}/edit`)
            }
            this.$message.success('草稿已保存')
            resolve(true)
          } catch (e) {
            this.$message.error(e.userMessage || '保存失败')
            resolve(false)
          } finally {
            this.saving = false
          }
        })
      )
    },
    async saveAndMatch() {
      if (await this.saveDraft()) this.$router.push(`/finance/invoices/${this.form.id}/match`)
    },
    back() {
      this.$router.push('/finance/invoices')
    },
    attachmentsBy(type) {
      return (this.form.attachments || []).filter(x => x.status === 'active' && x.attachment_type === type)
    },
    async uploadAttachment(type, req) {
      if (!this.form.id) {
        this.$message.warning('请先保存草稿，生成发票记录后再上传附件')
        return
      }
      const fd = new FormData()
      fd.append('file', req.file)
      fd.append('attachment_type', type)
      try {
        await uploadFinanceInvoiceAttachment(this.form.id, fd)
        await this.reload()
        this.$message.success('附件已上传')
      } catch (e) {
        this.$message.error(e.userMessage || '附件上传失败')
      }
    },
    async previewAttachment(item) {
      try {
        const r = await previewFinanceAttachment(item.id)
        const url = URL.createObjectURL(r.data)
        window.open(url, '_blank', 'noopener')
        setTimeout(() => URL.revokeObjectURL(url), 60000)
      } catch (e) {
        this.$message.error(e.userMessage || '附件预览失败')
      }
    },
    async removeAttachment(item) {
      try {
        await this.$confirm(`删除附件“${item.original_name}”？`, '删除确认', { type: 'warning' })
        await deleteFinanceInvoiceAttachment(item.id)
        await this.reload()
        this.$message.success('附件已删除')
      } catch (e) {
        if (e !== 'cancel') this.$message.error(e.userMessage || '删除失败')
      }
    },
    async reload() {
      const r = await getFinanceInvoice(this.form.id)
      this.form = { ...empty(), ...r.data.data }
    }
  }
}
</script>

<style scoped>
/* 容器 */
.invoice-form-page {
  min-height: calc(100vh - 58px);
  padding: 16px 20px 24px;
  background: #f5f7fa;
  color: #1f2937;
  font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
  box-sizing: border-box;
}

/* 顶部操作 */
.form-heading {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: 14px;
}
.form-heading h1 {
  margin: 0;
  font-size: 20px;
  font-weight: 700;
  color: #111827;
  line-height: 28px;
}
.heading-actions {
  display: flex;
  gap: 10px;
}

/* 按钮通用 */
.btn-default {
  height: 32px !important;
  line-height: 30px !important;
  padding: 0 16px !important;
  background: #ffffff !important;
  border: 1px solid #d9d9d9 !important;
  border-radius: 4px !important;
  color: #1f2937 !important;
  font-size: 13px !important;
  font-weight: 400 !important;
  transition: all 0.2s ease;
}
.btn-default:hover {
  color: #008b4b !important;
  border-color: #008b4b !important;
}
.btn-green {
  height: 32px !important;
  line-height: 30px !important;
  padding: 0 16px !important;
  background: #008b4b !important;
  border-color: #008b4b !important;
  border-radius: 4px !important;
  color: #ffffff !important;
  font-size: 13px !important;
  font-weight: 500 !important;
  transition: all 0.2s ease;
}
.btn-green:hover {
  background: #007840 !important;
  border-color: #007840 !important;
}

/* 顶部业务提示条 */
.business-alert {
  background: #e6f4ff;
  border: 1px solid #91caff;
  border-radius: 4px;
  padding: 8px 14px;
  margin-bottom: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
  color: #374151;
  font-size: 13px;
  line-height: 20px;
}
.alert-info-icon {
  width: 16px;
  height: 16px;
  color: #1677ff;
  flex-shrink: 0;
}

/* 左右分栏布局 */
/* 左右分栏布局 */
.form-grid {
  display: grid;
  grid-template-columns: minmax(0, 1fr) minmax(0, 1.08fr);
  gap: 14px;
}
.form-card,
.attachments-card,
.validation-card {
  min-width: 0;
  background: #ffffff;
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  padding: 16px 20px;
  box-sizing: border-box;
}
.form-card h2,
.attachments-card h2,
.validation-card h2 {
  margin: 0 0 14px 0;
  font-size: 15px;
  font-weight: 700;
  color: #111827;
  line-height: 20px;
}

/* 表单项与错误提示 */
.invoice-form ::v-deep .el-form-item {
  margin-bottom: 14px;
  position: relative;
  transition: margin-bottom 0.18s ease-out;
}
.invoice-form ::v-deep .el-form-item.is-error {
  margin-bottom: 22px !important; /* 校验报错时动态扩展下边距，保证错误提示独立成行，绝不遮挡下一行控件 */
}
.invoice-form ::v-deep .el-form-item__error {
  position: absolute;
  top: 100%;
  left: 0;
  padding-top: 3px;
  font-size: 12px;
  line-height: 14px;
  color: #ff4d4f;
  white-space: nowrap;
  z-index: 5;
}
.invoice-form ::v-deep .el-form-item.is-error .el-input__inner,
.invoice-form ::v-deep .el-form-item.is-error .el-textarea__inner {
  border-color: #ff4d4f;
}
.invoice-form ::v-deep .el-form-item__label {
  color: #374151;
  font-size: 13px;
  font-weight: 400;
  line-height: 32px;
  padding-right: 10px;
}
.invoice-form ::v-deep .el-form-item__label:before {
  color: #ff4d4f;
  margin-right: 3px;
}
.invoice-form ::v-deep .el-input__inner {
  height: 32px;
  line-height: 32px;
  border: 1px solid #d9d9d9;
  border-radius: 4px;
  font-size: 13px;
  color: #1f2937;
  padding: 0 10px;
}
.invoice-form ::v-deep .el-input__inner:focus {
  border-color: #008b4b;
}
.invoice-form ::v-deep .el-input__inner::placeholder {
  color: #9ca3af;
}

/* 禁用输入框 */
.invoice-form ::v-deep .disabled-input .el-input__inner,
.invoice-form ::v-deep .el-input.is-disabled .el-input__inner {
  background-color: #f9fafb !important;
  color: #6b7280 !important;
  border-color: #e5e7eb !important;
}

/* 日历图标靠右 */
.invoice-form ::v-deep .el-date-editor--date {
  width: 100%;
}
.invoice-form ::v-deep .el-date-editor--date .el-input__prefix {
  left: auto;
  right: 10px;
  color: #9ca3af;
}
.invoice-form ::v-deep .el-date-editor--date .el-input__inner {
  padding-left: 10px;
  padding-right: 30px;
}

/* 输入框后缀挂载 CNY */
.invoice-form ::v-deep .el-input-group__append {
  background-color: #f9fafb;
  color: #6b7280;
  border-color: #d9d9d9;
  border-left: 1px solid #d9d9d9;
  font-size: 13px;
  padding: 0 12px;
}

/* 供应商选择 */
.supplier-control {
  display: flex;
  gap: 8px;
}
.supplier-control .supplier-input {
  flex: 1;
}
.supplier-control .supplier-input ::v-deep .el-input__inner {
  cursor: pointer;
}
.select-supplier-btn {
  flex-shrink: 0;
  height: 32px !important;
  padding: 0 12px !important;
  line-height: 30px !important;
}

/* 税率明细表格 */
.tax-detail-item ::v-deep .el-form-item__content {
  line-height: normal;
  min-width: 0;
}
.tax-table-wrapper {
  border: 1px solid #e5e7eb;
  border-radius: 4px;
  overflow-x: auto;
  max-width: 100%;
  background: #ffffff;
}
.tax-table {
  width: 100%;
  min-width: 440px;
  border-collapse: collapse;
  font-size: 13px;
}
.tax-table th {
  background: #f8fafc;
  font-weight: 600;
  color: #1f2937;
  height: 34px;
  padding: 0 4px;
  border-bottom: 1px solid #e5e7eb;
  border-right: 1px solid #e5e7eb;
  text-align: center;
  white-space: nowrap;
}
.tax-table .th-index {
  width: 42px;
}
.tax-table .th-rate {
  width: 92px;
}
.tax-table .th-amount {
  min-width: 120px;
}
.tax-table .th-tax {
  min-width: 100px;
}
.tax-table .th-op {
  width: 48px;
}
.tax-table th:last-child {
  border-right: none;
}
.tax-table td {
  height: 38px;
  padding: 3px 4px;
  border-bottom: 1px solid #e5e7eb;
  border-right: 1px solid #e5e7eb;
}
.tax-table td:last-child {
  border-right: none;
}
.tax-table .col-center {
  text-align: center;
  color: #6b7280;
}

/* 紧凑比例的单元格输入框与下拉选择框 */
.cell-select-rate {
  width: 100% !important;
  max-width: 86px;
  display: inline-block;
}
.cell-input-excl {
  width: 100% !important;
  display: inline-block;
}
.cell-input-tax {
  width: 100% !important;
  display: inline-block;
}
.tax-table ::v-deep .el-input__inner {
  height: 28px;
  line-height: 28px;
  padding: 0 8px;
}
.btn-action-blue {
  color: #1890ff !important;
  font-size: 13px !important;
  padding: 0 !important;
  font-weight: 400 !important;
}
.btn-action-blue:hover {
  color: #40a9ff !important;
}
.btn-add-tax {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  margin: 6px 10px;
  background: transparent;
  border: none;
  color: #008b4b;
  font-size: 13px;
  font-weight: 500;
  cursor: pointer;
  padding: 2px 0;
}
.btn-add-tax:hover {
  color: #007840;
}

/* 备注 */
.remark-form-item {
  margin-top: 10px !important;
}
.remark-form-item ::v-deep .el-textarea__inner {
  font-family: inherit;
  border-radius: 4px;
  border: 1px solid #d9d9d9;
  padding: 8px 10px;
  font-size: 13px;
  color: #1f2937;
  resize: vertical;
}
.remark-form-item ::v-deep .el-textarea__inner:focus {
  border-color: #008b4b;
}
.remark-form-item ::v-deep .el-input__count {
  bottom: 4px;
  right: 10px;
  font-size: 12px;
  color: #9ca3af;
  background: transparent;
}

/* 下方栅格 */
.bottom-grid {
  display: grid;
  grid-template-columns: minmax(0, 1.35fr) minmax(0, 1fr);
  gap: 14px;
  margin-top: 14px;
}

/* 附件区域 */
.attachment-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
  gap: 12px;
}
.attachment-column {
  display: flex;
  flex-direction: column;
}
.attachment-col-title {
  font-size: 13px;
  font-weight: 600;
  color: #1f2937;
  margin-bottom: 8px;
}
.upload-box {
  width: 100%;
}
.upload-box ::v-deep .el-upload {
  width: 100%;
}
.upload-box ::v-deep .el-upload-dragger {
  width: 100%;
  height: 84px !important;
  min-height: 84px !important;
  border: 1px dashed #d1d5db !important;
  border-radius: 4px !important;
  background: #fafbfc !important;
  padding: 8px 6px !important;
  display: flex !important;
  flex-direction: column !important;
  align-items: center !important;
  justify-content: center !important;
  box-sizing: border-box !important;
  cursor: pointer;
  transition: all 0.2s ease;
}
.upload-box ::v-deep .el-upload-dragger:hover {
  border-color: #1890ff !important;
  background: #f0f7ff !important;
}
.upload-box.muted {
  height: 84px;
  border: 1px dashed #e5e7eb;
  border-radius: 4px;
  background: #f9fafb;
  padding: 8px 6px;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;
}
.upload-cloud-icon {
  width: 24px !important;
  height: 24px !important;
  max-width: 24px !important;
  max-height: 24px !important;
  margin-bottom: 3px;
  flex-shrink: 0;
}
.upload-primary-text {
  font-size: 12px;
  color: #374151;
  line-height: 16px;
}
.upload-secondary-text {
  font-size: 10px;
  color: #9ca3af;
  line-height: 14px;
  margin-top: 1px;
}

/* 附件文件项 */
.attachment-file-list {
  margin-top: 8px;
  display: flex;
  flex-direction: column;
  gap: 6px;
}
.file-item-row {
  display: flex;
  align-items: center;
  gap: 8px;
  padding: 5px 8px;
  background: #ffffff;
  border: 1px solid #edf0f3;
  border-radius: 4px;
}
.badge-tag {
  width: 22px;
  height: 22px;
  border-radius: 3px;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  color: #ffffff;
  font-size: 10px;
  font-weight: 700;
  flex-shrink: 0;
}
.badge-red {
  background: #ff4d4f;
}
.badge-blue {
  background: #1890ff;
}
.badge-green {
  background: #107c41;
}
.file-info-block {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
}
.file-title {
  font-size: 12px;
  color: #1f2937;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.file-size-text {
  font-size: 10px;
  color: #9ca3af;
}
.file-action-btns {
  display: flex;
  align-items: center;
  gap: 4px;
}
.btn-tool {
  background: none;
  border: none;
  padding: 2px;
  color: #9ca3af;
  cursor: pointer;
  font-size: 13px;
  line-height: 1;
}
.btn-tool:hover {
  color: #1890ff;
}
.btn-tool.danger:hover {
  color: #ff4d4f;
}

/* 保存前校验 */
.validation-checklist {
  display: flex;
  flex-direction: column;
  padding: 2px 0;
}
.check-item {
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 8px 0;
  font-size: 13px;
}
.check-svg {
  width: 16px !important;
  height: 16px !important;
  max-width: 16px !important;
  max-height: 16px !important;
  flex-shrink: 0;
}
.check-svg.pass {
  color: #00a854;
}
.check-svg.fail {
  color: #ff4d4f;
}
.chk-label {
  min-width: 65px;
  color: #1f2937;
  font-weight: 500;
}
.chk-val {
  color: #374151;
  font-weight: 400;
}
.validation-note-box {
  background: #e6f4ff;
  border: 1px solid #bae0ff;
  border-radius: 4px;
  padding: 8px 12px;
  margin-top: 14px;
  display: flex;
  align-items: center;
  gap: 8px;
  color: #374151;
  font-size: 12px;
  line-height: 18px;
}

/* 供应商弹窗 */
.supplier-picker-dialog ::v-deep .el-dialog {
  border-radius: 6px;
  overflow: hidden;
}
.supplier-picker-dialog ::v-deep .el-dialog__header {
  padding: 16px 20px;
  border-bottom: 1px solid #e5e7eb;
}
.supplier-picker-dialog ::v-deep .el-dialog__title {
  font-size: 16px;
  font-weight: 700;
  color: #111827;
}
.supplier-picker-dialog ::v-deep .el-dialog__body {
  padding: 16px 20px;
}
.picker-filters {
  display: flex;
  align-items: center;
  gap: 12px;
  margin-bottom: 14px;
}
.picker-filters label {
  display: flex;
  align-items: center;
  gap: 8px;
  font-size: 13px;
  color: #374151;
}
.picker-filters .el-input {
  width: 200px;
}
.picker-filters .el-select {
  width: 140px;
}
.picker-table ::v-deep th {
  background: #f8fafc;
  color: #1f2937;
  font-size: 13px;
  font-weight: 600;
}
.picker-table ::v-deep td {
  font-size: 13px;
  color: #374151;
}
.status-enabled {
  color: #008b4b;
  font-weight: 500;
}
.status-disabled {
  color: #9ca3af;
}
.picker-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-top: 14px;
}
.total-text {
  font-size: 13px;
  color: #6b7280;
}
.dialog-actions {
  display: flex;
  gap: 10px;
}

/* 响应式 */
@media (max-width: 1320px) {
  .form-grid,
  .bottom-grid {
    grid-template-columns: 1fr;
  }
}
</style>
