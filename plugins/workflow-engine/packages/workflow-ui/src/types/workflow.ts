/**
 * 工作流相关类型定义
 * Workflow Type Definitions
 */

// 节点类型
export enum NodeType {
  START = 'start',
  END = 'end',
  TASK = 'task',
  DECISION = 'decision',
  FORK = 'fork',
  JOIN = 'join',
  CUSTOM = 'custom',
  SUB_PROCESS = 'sub_process'
}

// 任务类型
export enum TaskType {
  USER_TASK = 'user_task',
  SYSTEM_TASK = 'system_task',
  SERVICE_TASK = 'service_task'
}

// 任务子类型
export enum TaskSubType {
  APPROVAL = 'approval',
  COUNTERSIGN = 'countersign',
  NOTIFY = 'notify'
}

// 工作流状态
export enum WorkflowStatus {
  DRAFT = 'draft',
  PUBLISHED = 'published',
  ARCHIVED = 'archived'
}

// 实例状态
export enum InstanceStatus {
  PENDING = 'pending',
  RUNNING = 'running',
  COMPLETED = 'completed',
  FAILED = 'failed',
  CANCELLED = 'cancelled',
  SUSPENDED = 'suspended'
}

// 任务状态
export enum TaskStatus {
  PENDING = 'pending',
  CLAIMED = 'claimed',
  COMPLETED = 'completed',
  REJECTED = 'rejected',
  CANCELLED = 'cancelled',
  ESCALATED = 'escalated'
}

// 触发器类型
export enum TriggerType {
  MANUAL = 'manual',
  WEBHOOK = 'webhook',
  SCHEDULE = 'schedule',
  DATA_EVENT = 'data_event',
  MESSAGE_EVENT = 'message_event'
}

// 基础节点配置
export interface BaseNodeConfig {
  id: string
  name: string
  type: NodeType
  x: number
  y: number
  width?: number
  height?: number
  properties?: Record<string, any>
}

// 开始节点配置
export interface StartNodeConfig extends BaseNodeConfig {
  type: NodeType.START
  properties: {
    label?: string
    description?: string
  }
}

// 结束节点配置
export interface EndNodeConfig extends BaseNodeConfig {
  type: NodeType.END
  properties: {
    label?: string
    description?: string
    endType?: 'normal' | 'error' | 'cancel'
  }
}

// 任务节点配置
export interface TaskNodeConfig extends BaseNodeConfig {
  type: NodeType.TASK
  taskType: TaskType
  taskSubType?: TaskSubType
  properties: {
    label: string
    description?: string
    assignee?: number | number[]
    assigneeType?: 'user' | 'role' | 'department'
    candidateUsers?: number[]
    candidateGroups?: number[]
    formKey?: string
    dueDate?: string
    priority?: number
    timeout?: number
    variables?: Record<string, any>
  }
}

// 决策节点配置
export interface DecisionNodeConfig extends BaseNodeConfig {
  type: NodeType.DECISION
  properties: {
    label: string
    description?: string
    condition: string
    defaultCondition?: string
  }
}

// 分支节点配置
export interface ForkNodeConfig extends BaseNodeConfig {
  type: NodeType.FORK
  properties: {
    label: string
    description?: string
    branches: Array<{
      id: string
      name: string
      condition?: string
    }>
  }
}

// 汇聚节点配置
export interface JoinNodeConfig extends BaseNodeConfig {
  type: NodeType.JOIN
  properties: {
    label: string
    description?: string
    joinType?: 'all' | 'any'
  }
}

// 自定义节点配置
export interface CustomNodeConfig extends BaseNodeConfig {
  type: NodeType.CUSTOM
  properties: {
    label: string
    description?: string
    componentName: string
    config: Record<string, any>
  }
}

// 子流程节点配置
export interface SubProcessNodeConfig extends BaseNodeConfig {
  type: NodeType.SUB_PROCESS
  properties: {
    label: string
    description?: string
    subProcessId: string
    version?: number
    parameters?: Record<string, any>
    inheritVariables?: boolean
  }
}

// 连接线配置
export interface ConnectionConfig {
  id: string
  source: string
  target: string
  sourceHandle?: string
  targetHandle?: string
  properties?: {
    label?: string
    condition?: string
    default?: boolean
  }
}

// 工作流定义
export interface WorkflowDefinition {
  id: string
  name: string
  displayName: string
  description?: string
  type: 'approval' | 'automation' | 'hybrid'
  category?: string
  version: number
  status: WorkflowStatus
  nodes: BaseNodeConfig[]
  connections: ConnectionConfig[]
  variables?: Record<string, any>
  createdAt: string
  updatedAt: string
  createdBy: number
}

// 工作流实例
export interface WorkflowInstance {
  id: string
  definitionId: string
  tenantId: number
  businessKey?: string
  title: string
  status: InstanceStatus
  currentNodeId?: string
  currentNodeName?: string
  variables?: Record<string, any>
  startedAt?: string
  completedAt?: string
  suspendedAt?: string
  duration?: number
  createdAt: string
  createdBy: number
  assignee?: number
}

// 工作流任务
export interface WorkflowTask {
  id: string
  instanceId: string
  tenantId: number
  nodeId: string
  nodeName: string
  taskType: TaskType
  taskSubType?: TaskSubType
  title: string
  description?: string
  status: TaskStatus
  priority: number
  assignee?: number
  assigneeName?: string
  candidateUsers?: number[]
  candidateGroups?: number[]
  formData?: Record<string, any>
  result?: Record<string, any>
  comment?: string
  dueDate?: string
  completedAt?: string
  createdAt: string
  createdBy: number
}

// 触发器配置
export interface WorkflowTrigger {
  id: string
  definitionId: string
  tenantId: number
  name: string
  type: TriggerType
  config: Record<string, any>
  isActive: boolean
  createdAt: string
  createdBy: number
}

// 事件日志
export interface WorkflowEventLog {
  id: string
  instanceId?: string
  taskId?: string
  tenantId: number
  eventType: string
  eventName: string
  eventData?: Record<string, any>
  userId?: number
  userName?: string
  ipAddress?: string
  userAgent?: string
  createdAt: string
}

// 设计器配置
export interface DesignerConfig {
  width?: number
  height?: number
  grid?: boolean
  gridSize?: number
  backgroundColor?: string
  nodeTypeConfig?: Record<NodeType, {
    label: string
    icon?: string
    color?: string
    width?: number
    height?: number
  }>
  readOnly?: boolean
  enableMiniMap?: boolean
  enableContextMenu?: boolean
  enableKeyboard?: boolean
  enableMultiSelect?: boolean
}

// 画布配置
export interface CanvasConfig {
  x: number
  y: number
  zoom: number
  width: number
  height: number
}

// 选择状态
export interface SelectionState {
  nodes: string[]
  edges: string[]
}

// 节点组件Props
export interface NodeComponentProps {
  id: string
  data: any
  selected?: boolean
  x: number
  y: number
}

// 边组件Props
export interface EdgeComponentProps {
  id: string
  data: any
  source: any
  target: any
  selected?: boolean
  sourceX: number
  sourceY: number
  targetX: number
  targetY: number
  sourcePosition: any
  targetPosition: any
}

// API响应类型
export interface ApiResponse<T = any> {
  code: number
  message: string
  data: T
  timestamp: string
}

// 分页查询参数
export interface PaginationParams {
  page: number
  pageSize: number
  sortBy?: string
  sortOrder?: 'asc' | 'desc'
}

// 分页响应
export interface PaginationResponse<T = any> {
  list: T[]
  total: number
  page: number
  pageSize: number
}

// 查询条件
export interface QueryCondition {
  tenantId?: number
  status?: string
  type?: string
  startDate?: string
  endDate?: string
  keyword?: string
  assignee?: number
  creator?: number
}
