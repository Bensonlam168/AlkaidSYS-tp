/**
 * 工作流相关工具函数
 * Workflow Utility Functions
 */

import type { WorkflowDefinition, WorkflowInstance, WorkflowTask, BaseNodeConfig, ConnectionConfig } from '../types/workflow'

/**
 * 验证工作流定义
 */
export function validateWorkflowDefinition(definition: WorkflowDefinition): {
  isValid: boolean
  errors: string[]
} {
  const errors: string[] = []

  // 检查必要字段
  if (!definition.name?.trim()) {
    errors.push('工作流名称不能为空')
  }

  if (!definition.displayName?.trim()) {
    errors.push('工作流显示名称不能为空')
  }

  if (!definition.nodes || definition.nodes.length === 0) {
    errors.push('工作流必须包含至少一个节点')
  }

  if (!definition.connections || definition.connections.length === 0) {
    errors.push('工作流必须包含至少一个连接')
  }

  // 检查节点
  if (definition.nodes) {
    const startNodes = definition.nodes.filter(node => node.type === 'start')
    if (startNodes.length === 0) {
      errors.push('工作流必须包含开始节点')
    } else if (startNodes.length > 1) {
      errors.push('工作流只能包含一个开始节点')
    }

    const endNodes = definition.nodes.filter(node => node.type === 'end')
    if (endNodes.length === 0) {
      errors.push('工作流必须包含结束节点')
    }

    // 检查节点ID唯一性
    const nodeIds = new Set<string>()
    definition.nodes.forEach(node => {
      if (nodeIds.has(node.id)) {
        errors.push(`节点ID重复: ${node.id}`)
      }
      nodeIds.add(node.id)
    })
  }

  // 检查连接
  if (definition.connections) {
    definition.connections.forEach(connection => {
      if (!connection.source) {
        errors.push(`连接 ${connection.id} 缺少源节点`)
      }
      if (!connection.target) {
        errors.push(`连接 ${connection.id} 缺少目标节点`)
      }

      // 检查源节点和目标节点是否存在
      const sourceExists = definition.nodes?.some(node => node.id === connection.source)
      const targetExists = definition.nodes?.some(node => node.id === connection.target)

      if (!sourceExists) {
        errors.push(`连接 ${connection.id} 的源节点不存在: ${connection.source}`)
      }
      if (!targetExists) {
        errors.push(`连接 ${connection.id} 的目标节点不存在: ${connection.target}`)
      }
    })

    // 检查连接ID唯一性
    const connectionIds = new Set<string>()
    definition.connections.forEach(conn => {
      if (connectionIds.has(conn.id)) {
        errors.push(`连接ID重复: ${conn.id}`)
      }
      connectionIds.add(conn.id)
    })
  }

  return {
    isValid: errors.length === 0,
    errors
  }
}

/**
 * 查找节点的下一个节点
 */
export function getNextNodes(
  nodeId: string,
  connections: ConnectionConfig[]
): ConnectionConfig[] {
  return connections.filter(conn => conn.source === nodeId)
}

/**
 * 查找节点的上一个节点
 */
export function getPreviousNodes(
  nodeId: string,
  connections: ConnectionConfig[]
): ConnectionConfig[] {
  return connections.filter(conn => conn.target === nodeId)
}

/**
 * 查找开始节点
 */
export function getStartNode(nodes: BaseNodeConfig[]): BaseNodeConfig | null {
  return nodes.find(node => node.type === 'start') || null
}

/**
 * 查找结束节点
 */
export function getEndNodes(nodes: BaseNodeConfig[]): BaseNodeConfig[] {
  return nodes.filter(node => node.type === 'end')
}

/**
 * 深度优先遍历工作流
 */
export function traverseWorkflowDFS(
  startNodeId: string,
  connections: ConnectionConfig[],
  callback: (nodeId: string, connections: ConnectionConfig[]) => void
): void {
  const visited = new Set<string>()
  const stack: string[] = [startNodeId]

  while (stack.length > 0) {
    const currentNodeId = stack.pop()!

    if (visited.has(currentNodeId)) {
      continue
    }

    visited.add(currentNodeId)

    const nextConnections = getNextNodes(currentNodeId, connections)
    callback(currentNodeId, nextConnections)

    // 添加未访问的下一个节点到栈
    nextConnections.forEach(conn => {
      if (!visited.has(conn.target)) {
        stack.push(conn.target)
      }
    })
  }
}

/**
 * 广度优先遍历工作流
 */
export function traverseWorkflowBFS(
  startNodeId: string,
  connections: ConnectionConfig[],
  callback: (nodeId: string, connections: ConnectionConfig[]) => void
): void {
  const visited = new Set<string>()
  const queue: string[] = [startNodeId]

  while (queue.length > 0) {
    const currentNodeId = queue.shift()!

    if (visited.has(currentNodeId)) {
      continue
    }

    visited.add(currentNodeId)

    const nextConnections = getNextNodes(currentNodeId, connections)
    callback(currentNodeId, nextConnections)

    // 添加未访问的下一个节点到队列
    nextConnections.forEach(conn => {
      if (!visited.has(conn.target)) {
        queue.push(conn.target)
      }
    })
  }
}

/**
 * 检查工作流是否有循环
 */
export function hasCircularDependency(
  nodes: BaseNodeConfig[],
  connections: ConnectionConfig[]
): boolean {
  const visited = new Set<string>()
  const recursionStack = new Set<string>()

  const startNode = getStartNode(nodes)
  if (!startNode) return true

  const checkNode = (nodeId: string): boolean => {
    if (recursionStack.has(nodeId)) {
      return true // 发现循环
    }
    if (visited.has(nodeId)) {
      return false // 已检查过，无循环
    }

    visited.add(nodeId)
    recursionStack.add(nodeId)

    const nextConnections = getNextNodes(nodeId, connections)
    for (const conn of nextConnections) {
      if (checkNode(conn.target)) {
        return true
      }
    }

    recursionStack.delete(nodeId)
    return false
  }

  return checkNode(startNode.id)
}

/**
 * 克隆工作流定义
 */
export function cloneWorkflowDefinition(definition: WorkflowDefinition): WorkflowDefinition {
  return JSON.parse(JSON.stringify(definition))
}

/**
 * 生成唯一ID
 */
export function generateUniqueId(prefix: string = 'wf'): string {
  return `${prefix}_${Date.now()}_${Math.random().toString(36).substr(2, 9)}`
}

/**
 * 生成节点ID
 */
export function generateNodeId(type: string, index: number): string {
  return `${type}_${index}_${Math.random().toString(36).substr(2, 5)}`
}

/**
 * 生成连接ID
 */
export function generateConnectionId(sourceId: string, targetId: string): string {
  return `conn_${sourceId}_${targetId}_${Math.random().toString(36).substr(2, 5)}`
}

/**
 * 转换工作流定义到LogicFlow格式
 */
export function workflowToLogicFlow(definition: WorkflowDefinition): {
  nodes: any[]
  edges: any[]
} {
  const nodes = definition.nodes.map(node => ({
    id: node.id,
    type: node.type,
    x: node.x,
    y: node.y,
    text: node.name,
    properties: {
      ...node.properties,
      nodeType: node.type,
      taskType: (node as any).taskType
    }
  }))

  const edges = definition.connections.map(conn => ({
    id: conn.id,
    type: 'line',
    sourceNodeId: conn.source,
    targetNodeId: conn.target,
    sourceHandle: conn.sourceHandle,
    targetHandle: conn.targetHandle,
    text: conn.properties?.label,
    properties: conn.properties
  }))

  return { nodes, edges }
}

/**
 * 转换LogicFlow格式到工作流定义
 */
export function logicFlowToWorkflow(graphData: {
  nodes: any[]
  edges: any[]
}): Partial<WorkflowDefinition> {
  const nodes: BaseNodeConfig[] = graphData.nodes.map(node => ({
    id: node.id,
    name: node.text || node.id,
    type: node.type,
    x: node.x,
    y: node.y,
    properties: {
      ...node.properties
    }
  }))

  const connections: ConnectionConfig[] = graphData.edges.map(edge => ({
    id: edge.id,
    source: edge.sourceNodeId,
    target: edge.targetNodeId,
    sourceHandle: edge.sourceHandle,
    targetHandle: edge.targetHandle,
    properties: {
      label: edge.text,
      ...edge.properties
    }
  }))

  return {
    nodes,
    connections
  }
}

/**
 * 计算工作流复杂度
 */
export function calculateWorkflowComplexity(
  nodes: BaseNodeConfig[],
  connections: ConnectionConfig[]
): {
  nodeCount: number
  edgeCount: number
  branchingFactor: number
  depth: number
  complexity: number
} {
  const nodeCount = nodes.length
  const edgeCount = connections.length
  const branchingFactor = nodeCount > 0 ? edgeCount / nodeCount : 0

  // 计算最大深度
  const startNode = getStartNode(nodes)
  let maxDepth = 0
  if (startNode) {
    const depths = new Map<string, number>()
    const visited = new Set<string>()

    const calculateDepth = (nodeId: string, currentDepth: number): void => {
      if (visited.has(nodeId)) return
      visited.add(nodeId)

      depths.set(nodeId, currentDepth)
      maxDepth = Math.max(maxDepth, currentDepth)

      const nextConnections = getNextNodes(nodeId, connections)
      nextConnections.forEach(conn => {
        calculateDepth(conn.target, currentDepth + 1)
      })
    }

    calculateDepth(startNode.id, 0)
  }

  // 简单的复杂度计算
  const complexity = nodeCount * 0.5 + edgeCount * 0.3 + branchingFactor * 10 + maxDepth * 0.2

  return {
    nodeCount,
    edgeCount,
    branchingFactor: Number(branchingFactor.toFixed(2)),
    depth: maxDepth,
    complexity: Number(complexity.toFixed(2))
  }
}

/**
 * 查找工作流中的孤立节点
 */
export function findOrphanNodes(
  nodes: BaseNodeConfig[],
  connections: ConnectionConfig[]
): BaseNodeConfig[] {
  const connectedNodeIds = new Set<string>()

  // 收集所有连接的节点ID
  connections.forEach(conn => {
    connectedNodeIds.add(conn.source)
    connectedNodeIds.add(conn.target)
  })

  // 查找未连接的节点
  return nodes.filter(node => !connectedNodeIds.has(node.id))
}

/**
 * 验证节点属性
 */
export function validateNode(node: BaseNodeConfig): {
  isValid: boolean
  errors: string[]
} {
  const errors: string[] = []

  if (!node.id?.trim()) {
    errors.push('节点ID不能为空')
  }

  if (!node.name?.trim()) {
    errors.push('节点名称不能为空')
  }

  if (!node.type) {
    errors.push('节点类型不能为空')
  }

  if (typeof node.x !== 'number') {
    errors.push('节点X坐标必须是数字')
  }

  if (typeof node.y !== 'number') {
    errors.push('节点Y坐标必须是数字')
  }

  // 根据节点类型进行特定验证
  switch (node.type) {
    case 'task':
      if (!(node as any).taskType) {
        errors.push('任务节点必须指定任务类型')
      }
      break
    case 'decision':
      if (!(node as any).properties?.condition) {
        errors.push('决策节点必须指定条件')
      }
      break
  }

  return {
    isValid: errors.length === 0,
    errors
  }
}