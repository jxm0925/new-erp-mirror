import api from "./master";

export const listApprovalTasks = (params) =>
  api.get("/v1/erp/approvals/tasks", { params });
export const getApprovalSummary = () =>
  api.get("/v1/erp/approvals/tasks/summary");
export const getApprovalTask = (id) => api.get(`/v1/erp/approvals/tasks/${id}`);
export const decideApprovalTask = (id, data) =>
  api.post(`/v1/erp/approvals/tasks/${id}/decision`, data);
export const transferApprovalTask = (id, data) =>
  api.post(`/v1/erp/approvals/tasks/${id}/transfer`, data);
export const retryApprovalTaskAction = (id) =>
  api.post(`/v1/erp/approvals/tasks/${id}/retry-action`);
export const listApprovalNotifications = (params) =>
  api.get("/v1/erp/approvals/notifications", { params });
export const readApprovalNotification = (id) =>
  api.post(`/v1/erp/approvals/notifications/${id}/read`);
export const batchDecideApprovalTasks = (data) =>
  api.post("/v1/erp/approvals/tasks/batch-decision", data);
export const getApprovalLaunchOptions = () =>
  api.get("/v1/erp/approvals/launch-options");
export const listApprovalLaunchRecords = (id, params) =>
  api.get(`/v1/erp/approvals/flows/${id}/source-records`, { params });
export const launchApprovalFlow = (id, data) =>
  api.post(`/v1/erp/approvals/flows/${id}/launch`, data);
export const uploadApprovalAttachment = (id, form) =>
  api.post(`/v1/erp/approvals/tasks/${id}/attachments`, form, {
    headers: { "Content-Type": "multipart/form-data" },
  });
export const previewApprovalAttachment = (id) =>
  api.get(`/v1/erp/approvals/attachments/${id}/preview`, {
    responseType: "blob",
  });
export const deleteApprovalAttachment = (id) =>
  api.delete(`/v1/erp/approvals/attachments/${id}`);

export const listApprovalFlows = (params) =>
  api.get("/v1/erp/approvals/flows", { params });
export const getApprovalFlowSummary = () =>
  api.get("/v1/erp/approvals/flows/summary");
export const getApprovalFlowConfigOptions = () =>
  api.get("/v1/erp/approvals/flows/config-options");
export const listApprovalRegistryCandidates = (params) =>
  api.get("/v1/erp/approvals/registry/candidates", { params });
export const getApprovalRegistryCandidate = (table) =>
  api.get("/v1/erp/approvals/registry/candidate", { params: { table } });
export const registerApprovalBusinessObject = (data) =>
  api.post("/v1/erp/approvals/registry/business-objects", data);
export const getApprovalFlow = (id) => api.get(`/v1/erp/approvals/flows/${id}`);
export const createApprovalFlow = (data) =>
  api.post("/v1/erp/approvals/flows", data);
export const updateApprovalFlow = (id, data) =>
  api.put(`/v1/erp/approvals/flows/${id}`, data);
export const validateApprovalFlow = (definition) =>
  api.post("/v1/erp/approvals/flows/validate", { definition });
export const publishApprovalFlow = (id, data) =>
  api.post(`/v1/erp/approvals/flows/${id}/publish`, data);
export const toggleApprovalFlow = (id, enabled) =>
  api.post(`/v1/erp/approvals/flows/${id}/toggle`, { enabled });
export const copyApprovalFlow = (id) =>
  api.post(`/v1/erp/approvals/flows/${id}/copy`);

export const listApprovalForms = (params) =>
  api.get("/v1/erp/approvals/forms", { params });
export const getApprovalFormSummary = () =>
  api.get("/v1/erp/approvals/forms/summary");
export const getApprovalForm = (id) => api.get(`/v1/erp/approvals/forms/${id}`);
export const createApprovalForm = (data) =>
  api.post("/v1/erp/approvals/forms", data);
export const updateApprovalForm = (id, data) =>
  api.put(`/v1/erp/approvals/forms/${id}`, data);
export const validateApprovalForm = (schema) =>
  api.post("/v1/erp/approvals/forms/validate", { schema });
export const publishApprovalForm = (id, data = {}) =>
  api.post(`/v1/erp/approvals/forms/${id}/publish`, data);
export const toggleApprovalForm = (id, enabled) =>
  api.post(`/v1/erp/approvals/forms/${id}/toggle`, { enabled });
export const copyApprovalForm = (id) =>
  api.post(`/v1/erp/approvals/forms/${id}/copy`);
export const deleteApprovalForm = (id) =>
  api.delete(`/v1/erp/approvals/forms/${id}`);
export const submitApprovalForm = (id, data) =>
  api.post(`/v1/erp/approvals/forms/${id}/submit`, data);
