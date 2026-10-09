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

/** 抄送任务已读确认（仅 CC 类型待处理任务可操作） */
export function fetchWorkflowAckCc(taskId: number) {
  return request({
    url: '/workflow/ackCc',
    method: 'post',
    data: { taskId }
  });
}

/** 加签：当前审批人增加其他审批人共同审批 */
export function fetchWorkflowAddSign(data: { taskId: number; signWorkId: string; signName: string }) {
  return request({
    url: '/workflow/addSign',
    method: 'post',
    data
  });
}

/** 转签：当前审批人将任务转给他人处理 */
export function fetchWorkflowTransfer(data: { taskId: number; targetWorkId: string; targetName: string }) {
  return request({
    url: '/workflow/transfer',
    method: 'post',
    data
  });
}

// ============ 节点(Node)CRUD ============

export function fetchWorkflowNodeList(defId: number) {
  return request({
    url: '/workflow/node/list',
    method: 'post',
    data: { defId }
  });
}

export function fetchWorkflowNodeCreate(data: {
  流程定义ID: number;
  节点编码: string;
  节点名称: string;
  节点类型: string;
  审批人类型?: string | null;
  审批人配置?: string | string[] | null;
  会签或签?: string;
  超时天数?: number;
  超时处理?: string;
  排序?: number;
}) {
  return request({
    url: '/workflow/node/create',
    method: 'post',
    data
  });
}

export function fetchWorkflowNodeUpdate(data: {
  nodeId: number;
  节点编码?: string;
  节点名称?: string;
  节点类型?: string;
  审批人类型?: string | null;
  审批人配置?: string | string[] | null;
  会签或签?: string;
  超时天数?: number;
  超时处理?: string;
  排序?: number;
}) {
  return request({
    url: '/workflow/node/update',
    method: 'post',
    data
  });
}

export function fetchWorkflowNodeDelete(nodeId: number) {
  return request({
    url: '/workflow/node/delete',
    method: 'post',
    data: { nodeId }
  });
}

export function fetchWorkflowNodeSort(nodeIds: number[]) {
  return request({
    url: '/workflow/node/sort',
    method: 'post',
    data: { nodeIds }
  });
}

// ============ 连线(Edge)CRUD ============

export function fetchWorkflowEdgeList(defId: number) {
  return request({
    url: '/workflow/edge/list',
    method: 'post',
    data: { defId }
  });
}

export function fetchWorkflowEdgeCreate(data: {
  流程定义ID: number;
  源节点编码: string;
  目标节点编码: string;
  匹配条件?: Record<string, any> | string | null;
  条件描述?: string | null;
  排序?: number;
}) {
  return request({
    url: '/workflow/edge/create',
    method: 'post',
    data
  });
}

export function fetchWorkflowEdgeUpdate(data: {
  edgeId: number;
  源节点编码?: string;
  目标节点编码?: string;
  匹配条件?: Record<string, any> | string | null;
  条件描述?: string | null;
  排序?: number;
}) {
  return request({
    url: '/workflow/edge/update',
    method: 'post',
    data
  });
}

export function fetchWorkflowEdgeDelete(edgeId: number) {
  return request({
    url: '/workflow/edge/delete',
    method: 'post',
    data: { edgeId }
  });
}

// ============ 节点模板(NodeTemplate)CRUD ============

export function fetchWorkflowTemplateList(params?: {
  businessType?: string;
  keyword?: string;
}) {
  return request({
    url: '/workflow/template/list',
    method: 'post',
    data: params || {}
  });
}

export function fetchWorkflowTemplateCreate(data: {
  模板编码: string;
  模板名称: string;
  节点类型: string;
  审批人类型?: string | null;
  审批人配置?: string | string[] | null;
  会签或签?: string;
  超时天数?: number;
  超时处理?: string;
  适用业务类型?: string | null;
  模板说明?: string | null;
}) {
  return request({
    url: '/workflow/template/create',
    method: 'post',
    data
  });
}

export function fetchWorkflowTemplateUpdate(data: {
  templateId: number;
  模板编码?: string;
  模板名称?: string;
  节点类型?: string;
  审批人类型?: string | null;
  审批人配置?: string | string[] | null;
  会签或签?: string;
  超时天数?: number;
  超时处理?: string;
  适用业务类型?: string | null;
  模板说明?: string | null;
}) {
  return request({
    url: '/workflow/template/update',
    method: 'post',
    data
  });
}

export function fetchWorkflowTemplateDelete(templateId: number) {
  return request({
    url: '/workflow/template/delete',
    method: 'post',
    data: { templateId }
  });
}

/** 调试：获取流程定义列表 SQL + 耗时（需 debugSql 权限） */
export function fetchWorkflowDebugList(params?: Record<string, any>) {
  return request<any>({
    url: '/workflow/definition/debugList',
    method: 'get',
    params
  });
}
