/**
 * 验证相关工具函数
 * Validation Utility Functions
 */

import type { WorkflowDefinition, BaseNodeConfig, ConnectionConfig } from '../types/workflow'
import type { LFNodeData, LFEdgeData } from '../types/logicflow'

/**
 * 验证结果接口
 */
export interface ValidationResult {
  isValid: boolean
  errors: ValidationError[]
  warnings: ValidationWarning[]
}

export interface ValidationError {
  type: 'error'
  code: string
  message: string
  nodeId?: string
  edgeId?: string
  field?: string
}

export interface ValidationWarning {
  type: 'warning'
  code: string
  message: string
  nodeId?: string
  edgeId?: string
  field?: string
}

/**
 * 验证工作流定义
 */
export function validateWorkflow(definition: WorkflowDefinition): ValidationResult {
  const errors: ValidationError[] = []
  const warnings: ValidationWarning[] = []

  // 基础验证
  if (!definition.name?.trim()) {
    errors.push({
      type: 'error',
      code: 'WORKFLOW_NAME_REQUIRED',
      message: '工作流名称不能为空'
    })
  }

  if (!definition.displayName?.trim()) {
    errors.push({
      type: 'error',
      code: 'WORKFLOW_DISPLAY_NAME_REQUIRED',
      message: '工作流显示名称不能为空'
    })
  }

  // 验证节点
  if (!definition.nodes || definition.nodes.length === 0) {
    errors.push({
      type: 'error',
      code: 'NO_NODES',
      message: '工作流必须包含至少一个节点'
    })
  } else {
    definition.nodes.forEach((node, index) => {
      const nodeErrors = validateNode(node, index)
      errors.push(...nodeErrors.errors)
      warnings.push(...nodeErrors.warnings)
    })

    // 检查节点唯一性
    const nodeIds = new Set<string>()
    const duplicateIds: string[] = []

    definition.nodes.forEach(node => {
      if (nodeIds.has(node.id)) {
        duplicateIds.push(node.id)
      } else {
        nodeIds.add(node.id)
      }
    })

    duplicateIds.forEach(id => {
      errors.push({
        type: 'error',
        code: 'DUPLICATE_NODE_ID',
        message: `节点ID重复: ${id}`,
        nodeId: id
      })
    })
  }

  // 验证连接
  if (!definition.connections || definition.connections.length === 0) {
    errors.push({
      type: 'error',
      code: 'NO_CONNECTIONS',
      message: '工作流必须包含至少一个连接'
    })
  } else {
    definition.connections.forEach((connection, index) => {
      const connErrors = validateConnection(connection, index, definition.nodes)
      errors.push(...connErrors.errors)
      warnings.push(...connErrors.warnings)
    })

    // 检查连接唯一性
    const connectionIds = new Set<string>()
    const duplicateConnIds: string[] = []

    definition.connections.forEach(conn => {
      if (connectionIds.has(conn.id)) {
        duplicateConnIds.push(conn.id)
      } else {
        connectionIds.add(conn.id)
      }
    })

    duplicateConnIds.forEach(id => {
      errors.push({
        type: 'error',
        code: 'DUPLICATE_CONNECTION_ID',
        message: `连接ID重复: ${id}`,
        edgeId: id
      })
    })
  }

  // 验证工作流结构
  const structureErrors = validateWorkflowStructure(definition)
  errors.push(...structureErrors.errors)
  warnings.push(...structureErrors.warnings)

  // 业务规则验证
  const businessErrors = validateBusinessRules(definition)
  errors.push(...businessErrors.errors)
  warnings.push(...businessErrors.warnings)

  return {
    isValid: errors.length === 0,
    errors,
    warnings
  }
}

/**
 * 验证节点
 */
export function validateNode(
  node: BaseNodeConfig,
  index: number
): { errors: ValidationError[]; warnings: ValidationWarning[] } {
  const errors: ValidationError[] = []
  const warnings: ValidationWarning[] = []

  if (!node.id?.trim()) {
    errors.push({
      type: 'error',
      code: 'NODE_ID_REQUIRED',
      message: `节点 ${index} 缺少ID`,
      nodeId: node.id
    })
  }

  if (!node.name?.trim()) {
    errors.push({
      type: 'error',
      code: 'NODE_NAME_REQUIRED',
      message: `节点 ${node.id} 缺少名称`,
      nodeId: node.id
    })
  }

  if (!node.type) {
    errors.push({
      type: 'error',
      code: 'NODE_TYPE_REQUIRED',
      message: `节点 ${node.id} 缺少类型`,
      nodeId: node.id
    })
  }

  if (typeof node.x !== 'number') {
    errors.push({
      type: 'error',
      code: 'NODE_X_INVALID',
      message: `节点 ${node.id} X坐标必须是数字`,
      nodeId: node.id,
      field: 'x'
    })
  }

  if (typeof node.y !== 'number') {
    errors.push({
      type: 'error',
      code: 'NODE_Y_INVALID',
      message: `节点 ${node.id} Y坐标必须是数字`,
      nodeId: node.id,
      field: 'y'
    })
  }

  // 根据节点类型进行特定验证
  switch (node.type) {
    case 'start':
      if (node.properties && Object.keys(node.properties).length > 0) {
        warnings.push({
          type: 'warning',
          code: 'START_NODE_PROPERTIES',
          message: `开始节点 ${node.id} 通常不需要属性`,
          nodeId: node.id
        })
      }
      break

    case 'end':
      if (!(node as any).properties?.endType) {
        warnings.push({
          type: 'warning',
          code: 'END_NODE_ENDTYPE',
          message: `建议为结束节点 ${node.id} 指定结束类型`,
          nodeId: node.id
        })
      }
      break

    case 'task':
      if (!(node as any).taskType) {
        errors.push({
          type: 'error',
          code: 'TASK_NODE_TYPE_REQUIRED',
          message: `任务节点 ${node.id} 必须指定任务类型`,
          nodeId: node.id,
          field: 'taskType'
        })
      }

      if (!(node as any).properties?.label) {
        warnings.push({
          type: 'warning',
          code: 'TASK_NODE_LABEL',
          message: `建议为任务节点 ${node.id} 指定标签`,
          nodeId: node.id
        })
      }
      break

    case 'decision':
      if (!(node as any).properties?.condition) {
        errors.push({
          type: 'error',
          code: 'DECISION_NODE_CONDITION',
          message: `决策节点 ${node.id} 必须指定条件`,
          nodeId: node.id,
          field: 'condition'
        })
      }
      break

    case 'fork':
      if (!(node as any).properties?.branches || (node as any).properties.branches.length === 0) {
        errors.push({
          type: 'error',
          code: 'FORK_NODE_BRANCHES',
          message: `分支节点 ${node.id} 必须指定分支`,
          nodeId: node.id,
          field: 'branches'
        })
      }
      break

    case 'join':
      if (!(node as any).properties?.joinType) {
        warnings.push({
          type: 'warning',
          code: 'JOIN_NODE_JOINTYPE',
          message: `建议为汇聚节点 ${node.id} 指定汇聚类型`,
          nodeId: node.id
        })
      }
      break
  }

  return { errors, warnings }
}

/**
 * 验证连接
 */
export function validateConnection(
  connection: ConnectionConfig,
  index: number,
  nodes?: BaseNodeConfig[]
): { errors: ValidationError[]; warnings: ValidationWarning[] } {
  const errors: ValidationError[] = []
  const warnings: ValidationWarning[] = []

  if (!connection.id?.trim()) {
    errors.push({
      type: 'error',
      code: 'CONNECTION_ID_REQUIRED',
      message: `连接 ${index} 缺少ID`,
      edgeId: connection.id
    })
  }

  if (!connection.source?.trim()) {
    errors.push({
      type: 'error',
      code: 'CONNECTION_SOURCE_REQUIRED',
      message: `连接 ${connection.id} 缺少源节点`,
      edgeId: connection.id,
      field: 'source'
    })
  }

  if (!connection.target?.trim()) {
    errors.push({
      type: 'error',
      code: 'CONNECTION_TARGET_REQUIRED',
      message: `连接 ${connection.id} 缺少目标节点`,
      edgeId: connection.id,
      field: 'target'
    })
  }

  if (connection.source === connection.target) {
    errors.push({
      type: 'error',
      code: 'CONNECTION_SELF_LOOP',
      message: `连接 ${connection.id} 不能自己连接自己`,
      edgeId: connection.id
    })
  }

  // 如果提供了节点列表，验证节点是否存在
  if (nodes && connection.source && connection.target) {
    const sourceExists = nodes.some(node => node.id === connection.source)
    const targetExists = nodes.some(node => node.id === connection.target)

    if (!sourceExists) {
      errors.push({
        type: 'error',
        code: 'CONNECTION_SOURCE_NOT_FOUND',
        message: `连接 ${connection.id} 的源节点不存在: ${connection.source}`,
        edgeId: connection.id,
        field: 'source'
      })
    }

    if (!targetExists) {
      errors.push({
        type: 'error',
        code: 'CONNECTION_TARGET_NOT_FOUND',
        message: `连接 ${connection.id} 的目标节点不存在: ${connection.target}`,
        edgeId: connection.id,
        field: 'target'
      })
    }
  }

  return { errors, warnings }
}

/**
 * 验证工作流结构
 */
export function validateWorkflowStructure(
  definition: WorkflowDefinition
): { errors: ValidationError[]; warnings: ValidationWarning[] } {
  const errors: ValidationError[] = []
  const warnings: ValidationWarning[] = []

  if (!definition.nodes || definition.nodes.length === 0) {
    return { errors, warnings }
  }

  // 检查开始节点
  const startNodes = definition.nodes.filter(node => node.type === 'start')
  if (startNodes.length === 0) {
    errors.push({
      type: 'error',
      code: 'NO_START_NODE',
      message: '工作流必须包含开始节点'
    })
  } else if (startNodes.length > 1) {
    errors.push({
      type: 'error',
      code: 'MULTIPLE_START_NODES',
      message: '工作流只能包含一个开始节点'
    })
  }

  // 检查结束节点
  const endNodes = definition.nodes.filter(node => node.type === 'end')
  if (endNodes.length === 0) {
    errors.push({
      type: 'error',
      code: 'NO_END_NODE',
      message: '工作流必须包含至少一个结束节点'
    })
  }

  // 检查孤立节点
  if (definition.connections && definition.connections.length > 0) {
    const connectedNodeIds = new Set<string>()
    definition.connections.forEach(conn => {
      connectedNodeIds.add(conn.source)
      connectedNodeIds.add(conn.target)
    })

    const orphanNodes = definition.nodes.filter(
      node => !connectedNodeIds.has(node.id) && node.type !== 'start'
    )

    if (orphanNodes.length > 0) {
      warnings.push({
        type: 'warning',
        code: 'ORPHAN_NODES',
        message: `发现 ${orphanNodes.length} 个孤立节点`,
        nodeId: orphanNodes[0].id
      })
    }
  }

  // 检查循环依赖
  const hasCycle = checkCircularDependency(definition.nodes, definition.connections)
  if (hasCycle) {
    errors.push({
      type: 'error',
      code: 'CIRCULAR_DEPENDENCY',
      message: '工作流存在循环依赖'
    })
  }

  return { errors, warnings }
}

/**
 * 验证业务规则
 */
export function validateBusinessRules(
  definition: WorkflowDefinition
): { errors: ValidationError[]; warnings: ValidationWarning[] } {
  const errors: ValidationError[] = []
  const warnings: ValidationWarning[] = []

  if (!definition.nodes) {
    return { errors, warnings }
  }

  // 检查任务节点分配
  const taskNodes = definition.nodes.filter(node => node.type === 'task')
  taskNodes.forEach(task => {
    if (!(task as any).properties?.assignee && !(task as any).properties?.candidateUsers) {
      warnings.push({
        type: 'warning',
        code: 'TASK_NO_ASSIGNEE',
        message: `任务节点 ${task.id} 没有指定处理人`,
        nodeId: task.id
      })
    }
  })

  // 检查决策节点的条件
  const decisionNodes = definition.nodes.filter(node => node.type === 'decision')
  decisionNodes.forEach(decision => {
    const condition = (decision as any).properties?.condition
    if (condition && !isValidCondition(condition)) {
      warnings.push({
        type: 'warning',
        code: 'INVALID_CONDITION',
        message: `决策节点 ${decision.id} 的条件可能无效: ${condition}`,
        nodeId: decision.id
      })
    }
  })

  // 检查复杂度
  if (definition.nodes.length > 100) {
    warnings.push({
      type: 'warning',
      code: 'HIGH_COMPLEXITY',
      message: `工作流包含 ${definition.nodes.length} 个节点，复杂度较高`
    })
  }

  return { errors, warnings }
}

/**
 * 检查循环依赖
 */
function checkCircularDependency(
  nodes: BaseNodeConfig[],
  connections: ConnectionConfig[]
): boolean {
  const visited = new Set<string>()
  const recursionStack = new Set<string>()

  const hasCycleInNode = (nodeId: string): boolean => {
    if (recursionStack.has(nodeId)) {
      return true
    }
    if (visited.has(nodeId)) {
      return false
    }

    visited.add(nodeId)
    recursionStack.add(nodeId)

    // 查找所有从当前节点出发的连接
    const outgoingConnections = connections.filter(conn => conn.source === nodeId)
    for (const conn of outgoingConnections) {
      if (hasCycleInNode(conn.target)) {
        return true
      }
    }

    recursionStack.delete(nodeId)
    return false
  }

  for (const node of nodes) {
    if (!visited.has(node.id) && hasCycleInNode(node.id)) {
      return true
    }
  }

  return false
}

/**
 * 验证条件表达式
 */
function isValidCondition(condition: string): boolean {
  // 简单的条件验证，实际项目中应该使用更复杂的表达式验证器
  if (!condition || typeof condition !== 'string') {
    return false
  }

  // 检查是否有基本的语法错误
  const invalidPatterns = [
    /\{\{.*\}\}/, // 检查未闭合的双大括号
    /\[\[.*\]\]/, // 检查未闭合的双方括号
    /\(.*[^)]$/, // 检查未闭合的圆括号
    /^.*\)[^)]*$/ // 检查未闭合的圆括号
  ]

  return !invalidPatterns.some(pattern => pattern.test(condition))
}

/**
 * 验证LogicFlow数据
 */
export function validateLogicFlowData(data: {
  nodes: LFNodeData[]
  edges: LFEdgeData[]
}): ValidationResult {
  const errors: ValidationError[] = []
  const warnings: ValidationWarning[] = []

  if (!data.nodes || !Array.isArray(data.nodes)) {
    errors.push({
      type: 'error',
      code: 'INVALID_NODES',
      message: '节点数据无效'
    })
  }

  if (!data.edges || !Array.isArray(data.edges)) {
    errors.push({
      type: 'error',
      code: 'INVALID_EDGES',
      message: '边数据无效'
    })
  }

  if (data.nodes) {
    data.nodes.forEach((node, index) => {
      if (!node.id) {
        errors.push({
          type: 'error',
          code: 'NODE_ID_REQUIRED',
          message: `节点 ${index} 缺少ID`
        })
      }
      if (!node.type) {
        errors.push({
          type: 'error',
          code: 'NODE_TYPE_REQUIRED',
          message: `节点 ${node.id} 缺少类型`
        })
      }
    })
  }

  if (data.edges) {
    data.edges.forEach((edge, index) => {
      if (!edge.id) {
        errors.push({
          type: 'error',
          code: 'EDGE_ID_REQUIRED',
          message: `边 ${index} 缺少ID`
        })
      }
    })
  }

  return {
    isValid: errors.length === 0,
    errors,
    warnings
  }
}

/**
 * 获取验证摘要
 */
export function getValidationSummary(result: ValidationResult): string {
  const errorCount = result.errors.length
  const warningCount = result.warnings.length

  if (errorCount === 0 && warningCount === 0) {
    return '✅ 验证通过，未发现问题'
  }

  let summary = `验证完成，发现 ${errorCount} 个错误`
  if (warningCount > 0) {
    summary += `，${warningCount} 个警告`
  }

  return summary
}

/**
 * 格式化验证错误
 */
export function formatValidationError(error: ValidationError): string {
  let message = `[${error.code}] ${error.message}`
  if (error.nodeId) {
    message += ` (节点: ${error.nodeId})`
  }
  if (error.edgeId) {
    message += ` (边: ${error.edgeId})`
  }
  if (error.field) {
    message += ` (字段: ${error.field})`
  }
  return message
}