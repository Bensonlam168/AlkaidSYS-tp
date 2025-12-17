/**
 * LogicFlow 相关类型定义
 * LogicFlow Type Definitions
 */

import { NodeConfig, EdgeConfig } from '@logicflow/core'

// LogicFlow 画布类型
export type LFCanvas = any

// LogicFlow 实例类型
export type LFInstance = any

// LogicFlow 事件类型
export type LFEventType =
  | 'node:click'
  | 'edge:click'
  | 'node:contextmenu'
  | 'edge:contextmenu'
  | 'canvas:contextmenu'
  | 'node:selected'
  | 'edge:selected'
  | 'node:unselected'
  | 'edge:unselected'
  | 'node:dragstart'
  | 'node:drag'
  | 'node:dragend'
  | 'node:mousemove'
  | 'node:mouseup'
  | 'edge:click'
  | 'edge:mouseup'
  | 'connection'
  | 'disconnection'
  | 'history:change'
  | 'selection:selected'
  | 'selection:unselected'

// 节点数据类型
export interface LFNodeData {
  id: string
  type: string
  x: number
  y: number
  text?: string
  properties?: Record<string, any>
  width?: number
  height?: number
  [key: string]: any
}

// 边数据类型
export interface LFEdgeData {
  id: string
  type: string
  sourceNodeId: string
  targetNodeId: string
  sourceAnchorId?: string
  targetAnchorId?: string
  text?: string
  properties?: Record<string, any>
  startPoint?: {
    x: number
    y: number
  }
  endPoint?: {
    x: number
    y: number
  }
  [key: string]: any
}

// 自定义节点配置
export interface CustomNodeConfig {
  type: string
  model: any
  component: any
  width?: number
  height?: number
  [key: string]: any
}

// 自定义边配置
export interface CustomEdgeConfig {
  type: string
  model: any
  component: any
  [key: string]: any
}

// 节点事件参数
export interface LFNodeEventParams {
  e: MouseEvent
  node: LFNodeData
  id: string
  type: string
}

// 边事件参数
export interface LFEdgeEventParams {
  e: MouseEvent
  edge: LFEdgeData
  id: string
  type: string
}

// 画布事件参数
export interface LFCanvasEventParams {
  e: MouseEvent
}

// 连接事件参数
export interface LFConnectionParams {
  sourceNodeId: string
  sourceAnchorId: string
  targetNodeId: string
  targetAnchorId: string
}

// 自定义节点样式
export interface LFNodeStyle {
  fill?: string
  stroke?: string
  strokeWidth?: number
  radius?: number
  [key: string]: any
}

// 自定义边样式
export interface LFEdgeStyle {
  stroke?: string
  strokeWidth?: number
  strokeDasharray?: string
  [key: string]: any
}

// 节点配置选项
export interface LFNodeConfigOptions {
  isVisible?: boolean
  isSourceAnchor?: boolean
  isTargetAnchor?: boolean
  anchorPoints?: Array<{
    x: number
    y: number
    [key: string]: any
  }>
  [key: string]: any
}

// 画布配置选项
export interface LFCanvasConfigOptions {
  container?: HTMLElement | string
  width?: number
  height?: number
  grid?: {
    enabled?: boolean
    cell?: number
    type?: 'dot' | 'mesh'
    grid?: number
    gap?: number
  }
  background?: {
    color?: string
    image?: string
    repeat?: string
    size?: string
    position?: string
  }
  keyboard?: {
    enabled?: boolean
    [key: string]: any
  }
  autoAlign?: boolean
  autoAlignCandidate?: {
    nodeType?: string[]
    anchorType?: string[]
    offset?: number
  }
  [key: string]: any
}

// 插件配置
export interface LFPluginConfig {
  'control-panel'?: {
    isShowHeader?: boolean
    header?: string
    panel?: any[]
  }
  'menu'?: {
    menu?: Array<{
      text: string
      className?: string
      callback?: (data: any) => void
    }>
  }
  'selection-select'?: {
    multiple?: boolean
  }
  'mini-map'?: {
    isShowHeader?: boolean
    header?: string
    [key: string]: any
  }
  'snapshot'?: {
    fileName?: string
    backgroundColor?: string
    width?: number
    height?: number
    padding?: number
    pixelRatio?: number
  }
  [key: string]: any
}

// 主题配置
export interface LFThemeConfig {
  baseNode?: {
    [key: string]: any
  }
  baseEdge?: {
    [key: string]: any
  }
  nodeTextEdit?: {
    [key: string]: any
  }
  edgeTextEdit?: {
    [key: string]: any
  }
  selection?: {
    [key: string]: any
  }
  anchor?: {
    [key: string]: any
  }
  grid?: {
    [key: string]: any
  }
  [key: string]: any
}

// 事件监听器
export interface LFEventListener {
  (params: any): void
}

// 事件映射
export interface LFEventMap {
  'node:click': (params: LFNodeEventParams) => void
  'edge:click': (params: LFEdgeEventParams) => void
  'node:contextmenu': (params: LFNodeEventParams) => void
  'edge:contextmenu': (params: LFEdgeEventParams) => void
  'canvas:contextmenu': (params: LFCanvasEventParams) => void
  'node:selected': (params: LFNodeEventParams) => void
  'edge:selected': (params: LFEdgeEventParams) => void
  'connection': (params: LFConnectionParams) => void
  'disconnection': (params: LFConnectionParams) => void
  [key: string]: (params: any) => void
}

// 节点方法
export interface LFNodeMethods {
  setProperties(properties: Record<string, any>): void
  getProperties(): Record<string, any>
  updateProperties(properties: Record<string, any>): void
  setZIndex(zIndex: number): void
  toFront(): void
  toBack(): void
  remove(): void
  getModel(): any
  getGraphData(): any
  getData(): any
  [key: string]: any
}

// 边方法
export interface LFEdgeMethods {
  setProperties(properties: Record<string, any>): void
  getProperties(): Record<string, any>
  updateProperties(properties: Record<string, any>): void
  setZIndex(zIndex: number): void
  toFront(): void
  toBack(): void
  remove(): void
  getModel(): any
  getData(): any
  [key: string]: any
}

// LogicFlow 实例方法
export interface LFInstanceMethods {
  // 节点操作
  addNode(config: LFNodeConfig): any
  removeNode(id: string): void
  getNodeModel(id: string): any
  getNode(id: string): any
  updateNode(id: string, config: Partial<LFNodeConfig>): void
  selectNodeById(id: string): void
  setItemState(id: string, state: string, value: boolean): void
  getSelectElements(): any
  clearSelectElements(): void
  focusOnItem(id: string): void

  // 边操作
  addEdge(config: LFEdgeConfig): any
  removeEdge(id: string): void
  getEdgeModel(id: string): any
  getEdge(id: string): any
  updateEdge(id: string, config: Partial<LFEdgeConfig>): void

  // 画布操作
  resizeCanvas(width: number, height: number): void
  setCanvasGridBackground(background: any): void
  resetZoom(): void
  zoomTo(scale: number, center?: { x: number; y: number }): void
  fitView(padding?: number): void
  fitCenter(): void

  // 历史操作
  undo(): void
  redo(): void
  getHistoryIndex(): number

  // 导出导入
  exportPNG(background?: string): string
  exportJson(): any

  // 事件操作
  on(event: string, listener: LFEventListener): void
  off(event: string, listener: LFEventListener): void

  // 工具方法
  getGraphData(): {
    nodes: LFNodeData[]
    edges: LFEdgeData[]
  }
  setGraphData(data: { nodes: LFNodeConfig[]; edges: LFEdgeConfig[] }): void

  [key: string]: any
}
