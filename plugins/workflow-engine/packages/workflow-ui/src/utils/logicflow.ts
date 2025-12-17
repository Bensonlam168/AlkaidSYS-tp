/**
 * LogicFlow相关工具函数
 * LogicFlow Utility Functions
 */

import type LogicFlow from '@logicflow/core'
import type { LFNodeData, LFEdgeData, LFCanvasConfigOptions } from '../types/logicflow'
import type { BaseNodeConfig, ConnectionConfig } from '../types/workflow'

/**
 * 创建默认的LogicFlow配置
 */
export function createDefaultLFConfig(): LFCanvasConfigOptions {
  return {
    grid: {
      enabled: true,
      cell: 20,
      type: 'dot',
      grid: 1,
      gap: 10
    },
    background: {
      color: '#ffffff'
    },
    keyboard: {
      enabled: true
    },
    autoAlign: true,
    autoAlignCandidate: {
      nodeType: ['task', 'decision'],
      anchorType: ['left', 'right'],
      offset: 10
    }
  }
}

/**
 * 创建节点配置
 */
export function createNodeConfig(node: BaseNodeConfig): LFNodeData {
  return {
    id: node.id,
    type: node.type,
    x: node.x,
    y: node.y,
    text: node.name,
    properties: {
      ...node.properties,
      width: node.width,
      height: node.height
    },
    width: node.width,
    height: node.height
  }
}

/**
 * 创建边配置
 */
export function createEdgeConfig(connection: ConnectionConfig): LFEdgeData {
  return {
    id: connection.id,
    type: 'line',
    sourceNodeId: connection.source,
    targetNodeId: connection.target,
    sourceAnchorId: connection.sourceHandle,
    targetAnchorId: connection.targetHandle,
    text: connection.properties?.label,
    properties: connection.properties
  }
}

/**
 * 转换节点数据为工作流节点
 */
export function nodeDataToWorkflowNode(nodeData: LFNodeData): BaseNodeConfig {
  return {
    id: nodeData.id,
    name: nodeData.text || nodeData.id,
    type: nodeData.type as any,
    x: nodeData.x,
    y: nodeData.y,
    width: nodeData.width,
    height: nodeData.height,
    properties: {
      ...nodeData.properties
    }
  }
}

/**
 * 转换边数据为工作流连接
 */
export function edgeDataToWorkflowConnection(edgeData: LFEdgeData): ConnectionConfig {
  return {
    id: edgeData.id,
    source: edgeData.sourceNodeId,
    target: edgeData.targetNodeId,
    sourceHandle: edgeData.sourceAnchorId,
    targetHandle: edgeData.targetAnchorId,
    properties: {
      label: edgeData.text,
      ...edgeData.properties
    }
  }
}

/**
 * 获取节点在画布中的位置
 */
export function getNodePosition(lf: LogicFlow, nodeId: string): {
  x: number
  y: number
} | null {
  const nodeModel = lf.getNodeModel(nodeId)
  if (!nodeModel) return null

  return {
    x: nodeModel.x,
    y: nodeModel.y
  }
}

/**
 * 设置节点位置
 */
export function setNodePosition(lf: LogicFlow, nodeId: string, x: number, y: number): void {
  lf.updateNode(nodeId, { x, y })
}

/**
 * 选中节点
 */
export function selectNode(lf: LogicFlow, nodeId: string): void {
  lf.selectNodeById(nodeId)
}

/**
 * 清除所有选择
 */
export function clearSelection(lf: LogicFlow): void {
  lf.clearSelectElements()
}

/**
 * 获取所有选中的元素
 */
export function getSelectedElements(lf: LogicFlow) {
  return lf.getSelectElements()
}

/**
 * 缩放到指定级别
 */
export function zoomTo(lf: LogicFlow, scale: number): void {
  lf.zoomTo(scale)
}

/**
 * 适配视图
 */
export function fitView(lf: LogicFlow, padding?: number): void {
  lf.fitView(padding)
}

/**
 * 重置缩放
 */
export function resetZoom(lf: LogicFlow): void {
  lf.resetZoom()
}

/**
 * 居中显示
 */
export function fitCenter(lf: LogicFlow): void {
  lf.fitCenter()
}

/**
 * 导出画布为PNG
 */
export function exportPNG(lf: LogicFlow, background?: string): string {
  return lf.exportPNG(background)
}

/**
 * 导出画布为JSON
 */
export function exportJSON(lf: LogicFlow): {
  nodes: LFNodeData[]
  edges: LFEdgeData[]
} {
  return lf.exportJson()
}

/**
 * 导入JSON数据
 */
export function importJSON(lf: LogicFlow, data: {
  nodes: LFNodeData[]
  edges: LFEdgeData[]
}): void {
  lf.render(data)
}

/**
 * 撤销操作
 */
export function undo(lf: LogicFlow): void {
  lf.undo()
}

/**
 * 重做操作
 */
export function redo(lf: LogicFlow): void {
  lf.redo()
}

/**
 * 获取历史索引
 */
export function getHistoryIndex(lf: LogicFlow): number {
  return lf.getHistoryIndex()
}

/**
 * 添加节点
 */
export function addNode(lf: LogicFlow, config: LFNodeData): any {
  return lf.addNode(config)
}

/**
 * 删除节点
 */
export function removeNode(lf: LogicFlow, nodeId: string): void {
  lf.removeNode(nodeId)
}

/**
 * 更新节点
 */
export function updateNode(lf: LogicFlow, nodeId: string, config: Partial<LFNodeData>): void {
  lf.updateNode(nodeId, config)
}

/**
 * 添加边
 */
export function addEdge(lf: LogicFlow, config: LFEdgeData): any {
  return lf.addEdge(config)
}

/**
 * 删除边
 */
export function removeEdge(lf: LogicFlow, edgeId: string): void {
  lf.removeEdge(edgeId)
}

/**
 * 更新边
 */
export function updateEdge(lf: LogicFlow, edgeId: string, config: Partial<LFEdgeData>): void {
  lf.updateEdge(edgeId, config)
}

/**
 * 获取节点模型
 */
export function getNodeModel(lf: LogicFlow, nodeId: string): any {
  return lf.getNodeModel(nodeId)
}

/**
 * 获取边模型
 */
export function getEdgeModel(lf: LogicFlow, edgeId: string): any {
  return lf.getEdgeModel(edgeId)
}

/**
 * 设置节点状态
 */
export function setItemState(
  lf: LogicFlow,
  id: string,
  state: string,
  value: boolean
): void {
  lf.setItemState(id, state, value)
}

/**
 * 聚焦到节点
 */
export function focusOnItem(lf: LogicFlow, id: string): void {
  lf.focusOnItem(id)
}

/**
 * 获取节点属性
 */
export function getNodeProperties(lf: LogicFlow, nodeId: string): Record<string, any> {
  const nodeModel = lf.getNodeModel(nodeId)
  return nodeModel ? nodeModel.getProperties() : {}
}

/**
 * 设置节点属性
 */
export function setNodeProperties(
  lf: LogicFlow,
  nodeId: string,
  properties: Record<string, any>
): void {
  const nodeModel = lf.getNodeModel(nodeId)
  if (nodeModel) {
    nodeModel.setProperties(properties)
  }
}

/**
 * 更新节点属性
 */
export function updateNodeProperties(
  lf: LogicFlow,
  nodeId: string,
  properties: Record<string, any>
): void {
  const nodeModel = lf.getNodeModel(nodeId)
  if (nodeModel) {
    nodeModel.updateProperties(properties)
  }
}

/**
 * 获取边属性
 */
export function getEdgeProperties(lf: LogicFlow, edgeId: string): Record<string, any> {
  const edgeModel = lf.getEdgeModel(edgeId)
  return edgeModel ? edgeModel.getProperties() : {}
}

/**
 * 设置边属性
 */
export function setEdgeProperties(
  lf: LogicFlow,
  edgeId: string,
  properties: Record<string, any>
): void {
  const edgeModel = lf.getEdgeModel(edgeId)
  if (edgeModel) {
    edgeModel.setProperties(properties)
  }
}

/**
 * 更新边属性
 */
export function updateEdgeProperties(
  lf: LogicFlow,
  edgeId: string,
  properties: Record<string, any>
): void {
  const edgeModel = lf.getEdgeModel(edgeId)
  if (edgeModel) {
    edgeModel.updateProperties(properties)
  }
}

/**
 * 设置画布网格背景
 */
export function setCanvasGridBackground(lf: LogicFlow, background: any): void {
  lf.setCanvasGridBackground(background)
}

/**
 * 调整画布大小
 */
export function resizeCanvas(lf: LogicFlow, width: number, height: number): void {
  lf.resizeCanvas(width, height)
}

/**
 * 注册事件监听器
 */
export function on(lf: LogicFlow, event: string, listener: (params: any) => void): void {
  lf.on(event, listener)
}

/**
 * 移除事件监听器
 */
export function off(lf: LogicFlow, event: string, listener: (params: any) => void): void {
  lf.off(event, listener)
}

/**
 * 获取画布数据
 */
export function getGraphData(lf: LogicFlow): {
  nodes: LFNodeData[]
  edges: LFEdgeData[]
} {
  return lf.getGraphData()
}

/**
 * 设置画布数据
 */
export function setGraphData(lf: LogicFlow, data: {
  nodes: LFNodeData[]
  edges: LFEdgeData[]
}): void {
  lf.setGraphData(data)
}

/**
 * 检查节点是否存在
 */
export function hasNode(lf: LogicFlow, nodeId: string): boolean {
  return lf.getNodeModel(nodeId) !== null
}

/**
 * 检查边是否存在
 */
export function hasEdge(lf: LogicFlow, edgeId: string): boolean {
  return lf.getEdgeModel(edgeId) !== null
}

/**
 * 获取节点数量
 */
export function getNodeCount(lf: LogicFlow): number {
  const graphData = lf.getGraphData()
  return graphData.nodes?.length || 0
}

/**
 * 获取边数量
 */
export function getEdgeCount(lf: LogicFlow): number {
  const graphData = lf.getGraphData()
  return graphData.edges?.length || 0
}

/**
 * 清空画布
 */
export function clearCanvas(lf: LogicFlow): void {
  const graphData = lf.getGraphData()
  if (graphData.edges) {
    graphData.edges.forEach(edge => {
      lf.removeEdge(edge.id)
    })
  }
  if (graphData.nodes) {
    graphData.nodes.forEach(node => {
      lf.removeNode(node.id)
    })
  }
}

/**
 * 验证画布数据
 */
export function validateGraphData(data: {
  nodes: LFNodeData[]
  edges: LFEdgeData[]
}): {
  isValid: boolean
  errors: string[]
} {
  const errors: string[] = []

  if (!data) {
    errors.push('画布数据不能为空')
    return { isValid: false, errors }
  }

  if (!Array.isArray(data.nodes)) {
    errors.push('节点数据必须是数组')
  }

  if (!Array.isArray(data.edges)) {
    errors.push('边数据必须是数组')
  }

  if (data.nodes) {
    data.nodes.forEach((node, index) => {
      if (!node.id) {
        errors.push(`节点 ${index} 缺少ID`)
      }
      if (!node.type) {
        errors.push(`节点 ${node.id || index} 缺少类型`)
      }
      if (typeof node.x !== 'number') {
        errors.push(`节点 ${node.id || index} X坐标必须是数字`)
      }
      if (typeof node.y !== 'number') {
        errors.push(`节点 ${node.id || index} Y坐标必须是数字`)
      }
    })
  }

  if (data.edges) {
    data.edges.forEach((edge, index) => {
      if (!edge.id) {
        errors.push(`边 ${index} 缺少ID`)
      }
      if (!edge.sourceNodeId) {
        errors.push(`边 ${edge.id || index} 缺少源节点ID`)
      }
      if (!edge.targetNodeId) {
        errors.push(`边 ${edge.id || index} 缺少目标节点ID`)
      }
    })
  }

  return {
    isValid: errors.length === 0,
    errors
  }
}