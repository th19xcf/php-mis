import { request } from '../request';

export function fetchWorkflowDefinitionList(params: {
  page?: number;
  pageSize?: number;
  businessType?: string;
  workflowCode?: string;
  workflowName?: string;
  status?: string;
}) {
  return request({ url: '/workflow/definition/list', params });
}

export function fetchWorkflowDefinitionDetail(defId: number) {
  return request({ url: '/workflow/definition/detail', params: { defId } });
}

export function fetchWorkflowDefinitionCreate(data: Record<string, any>) {
  return request({
    url: '/workflow/definition/create',
    method: 'post',
    data
  });
}

export function fetchWorkflowDefinitionUpdate(data: Record<string, any>) {
  return request({
    url: '/workflow/definition/update',
    method: 'post',
    data
  });
}

export function fetchWorkflowDefinitionDelete(defId: number) {
  return request({
    url: '/workflow/definition/delete',
    method: 'post',
    data: { defId }
  });
}

export function fetchWorkflowDefinitionActivate(defId: number) {
  return request({
    url: '/workflow/definition/activate',
    method: 'post',
    data: { defId }
  });
}

export function fetchWorkflowDefinitionDeactivate(defId: number) {
  return request({
    url: '/workflow/definition/deactivate',
    method: 'post',
    data: { defId }
  });
}

export function fetchWorkflowInstanceList(params: {
  page?: number;
  pageSize?: number;
  businessType?: string;
  businessId?: string;
  instanceStatus?: string;
  sponsor?: string;
  workflowCode?: string;
}) {
  return request({ url: '/workflow/instance/list', params });
}

export function fetchWorkflowInstanceDetail(instanceId: number) {
  return request({ url: '/workflow/instance/detail', params: { instanceId } });
}

export function fetchWorkflowPendingTasks(params?: { page?: number; pageSize?: number }) {
  return request({ url: '/workflow/pendingTasks', params });
}

export function fetchWorkflowDoneTasks(params?: { page?: number; pageSize?: number }) {
  return request({ url: '/workflow/doneTasks', params });
}

export function fetchWorkflowMyInstances(params?: { page?: number; pageSize?: number }) {
  return request({ url: '/workflow/myInstances', params });
}

export function fetchWorkflowWithdraw(instanceId: number) {
  return request({
    url: '/workflow/withdraw',
    method: 'post',
    data: { instanceId }
  });
}
