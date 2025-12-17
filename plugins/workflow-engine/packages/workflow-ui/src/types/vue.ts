/**
 * Vue 组件相关类型定义
 * Vue Component Type Definitions
 */

import type { DefineComponent } from 'vue'

// 组件实例类型
export type ComponentInstance = InstanceType<DefineComponent>

// Props 类型
export interface BaseComponentProps {
  // 基础属性
  class?: string | Record<string, boolean>
  style?: string | Record<string, string | number>

  // 尺寸
  width?: string | number
  height?: string | number

  // 状态
  disabled?: boolean
  loading?: boolean

  // 事件
  on?: Record<string, Function | Function[]>
}

// 设计器组件 Props
export interface DesignerProps extends BaseComponentProps {
  // 工作流定义数据
  definition?: any

  // 配置选项
  config?: any

  // 只读模式
  readonly?: boolean

  // 高度
  height?: string | number

  // 事件回调
  onChange?: (data: any) => void
  onSave?: (data: any) => void
  onValidate?: (data: any) => boolean | string
  onError?: (error: Error) => void

  // 节点事件
  onNodeClick?: (node: any) => void
  onNodeContextMenu?: (node: any) => void
  onNodeDoubleClick?: (node: any) => void

  // 边事件
  onEdgeClick?: (edge: any) => void
  onEdgeContextMenu?: (edge: any) => void

  // 画布事件
  onCanvasClick?: (event: MouseEvent) => void
  onCanvasContextMenu?: (event: MouseEvent) => void
  onCanvasDrop?: (event: DragEvent) => void
}

// 设计器组件实例
export interface DesignerInstance {
  // LogicFlow 实例
  lf: any | null

  // 获取图形数据
  getGraphData: () => any

  // 设置图形数据
  setGraphData: (data: any) => void

  // 导出 PNG
  exportPNG: (background?: string) => string

  // 导入 JSON
  importJson: (data: any) => void

  // 撤销
  undo: () => void

  // 重做
  redo: () => void

  // 重置视图
  resetView: () => void

  // 适配视图
  fitView: () => void

  // 添加节点
  addNode: (config: any) => void

  // 删除节点
  removeNode: (id: string) => void

  // 更新节点
  updateNode: (id: string, config: any) => void

  // 添加边
  addEdge: (config: any) => void

  // 删除边
  removeEdge: (id: string) => void

  // 选中节点
  selectNode: (id: string) => void

  // 清除选择
  clearSelect: () => void
}

// 节点组件 Props
export interface NodeComponentProps extends BaseComponentProps {
  // 节点数据
  data: any

  // 节点 ID
  id: string

  // 节点类型
  type: string

  // 选中状态
  selected?: boolean

  // 位置
  x: number
  y: number

  // 尺寸
  width?: number
  height?: number

  // 文本
  text?: string

  // 属性
  properties?: Record<string, any>

  // 事件
  onSelect?: (id: string) => void
  onUpdate?: (id: string, data: any) => void
  onDelete?: (id: string) => void
  onContextMenu?: (id: string, event: MouseEvent) => void
}

// 边组件 Props
export interface EdgeComponentProps extends BaseComponentProps {
  // 边数据
  data: any

  // 边 ID
  id: string

  // 边类型
  type: string

  // 选中状态
  selected?: boolean

  // 源节点
  source: any

  // 目标节点
  target: any

  // 位置
  sourceX: number
  sourceY: number
  targetX: number
  targetY: number

  // 文本
  text?: string

  // 属性
  properties?: Record<string, any>

  // 事件
  onSelect?: (id: string) => void
  onUpdate?: (id: string, data: any) => void
  onDelete?: (id: string) => void
  onContextMenu?: (id: string, event: MouseEvent) => void
}

// 属性面板 Props
export interface PropertyPanelProps extends BaseComponentProps {
  // 当前选中的元素
  selected?: {
    type: 'node' | 'edge' | null
    data: any
  }

  // 属性定义
  schema?: any[]

  // 只读模式
  readonly?: boolean

  // 事件
  onChange?: (key: string, value: any) => void
  onSave?: () => void
  onCancel?: () => void
}

// 属性表单 Item Props
export interface FormItemProps {
  // 字段名
  prop: string

  // 标签
  label?: string

  // 占位符
  placeholder?: string

  // 必填
  required?: boolean

  // 禁用
  disabled?: boolean

  // 选项
  options?: Array<{
    label: string
    value: any
  }>

  // 默认值
  defaultValue?: any

  // 校验规则
  rules?: any[]

  // 组件类型
  component?: string | ComponentInstance

  // 组件属性
  componentProps?: Record<string, any>
栏 Props}

// 工具
export interface ToolbarProps extends BaseComponentProps {
  // 是否显示
  visible?: boolean

  // 工具项
  tools?: Array<{
    key: string
    icon?: string
    text?: string
    tooltip?: string
    disabled?: boolean
    hidden?: boolean
  }>

  // 事件
  onClick?: (key: string) => void
}

// 小地图 Props
export interface MiniMapProps extends BaseComponentProps {
  // 宽度
  width?: number

  // 高度
  height?: number

  // 位置
  position?: 'top-left' | 'top-right' | 'bottom-left' | 'bottom-right'

  // 样式
  className?: string

  // 是否显示头部
  showHeader?: boolean

  // 头部标题
  header?: string
}

// 控制面板 Props
export interface ControlPanelProps extends BaseComponentProps {
  // 面板数据
  data?: any[]

  // 是否显示头部
  showHeader?: boolean

  // 头部标题
  header?: string

  // 折叠状态
  collapsed?: boolean

  // 默认展开的分组
  defaultOpenGroups?: string[]

  // 事件
  onCollapse?: (collapsed: boolean) => void
  onItemClick?: (item: any) => void
  onGroupToggle?: (group: string, expanded: boolean) => void
}

// 节点选择器 Props
export interface NodeSelectorProps extends BaseComponentProps {
  // 节点类型
  nodeTypes?: Array<{
    type: string
    name: string
    icon?: string
    description?: string
    category?: string
  }>

  // 分组
  groups?: Array<{
    name: string
    label: string
    types: string[]
  }>

  // 搜索
  searchable?: boolean

  // 多选
  multiple?: boolean

  // 选中的节点
  selected?: string[]

  // 事件
  onSelect?: (types: string[]) => void
  onItemClick?: (type: string) => void
}

// 导出对话框 Props
export interface ExportDialogProps extends BaseComponentProps {
  // 可见性
  visible?: boolean

  // 导出格式
  format?: 'png' | 'jpg' | 'svg' | 'pdf' | 'json'

  // 文件名
  filename?: string

  // 背景色
  backgroundColor?: string

  // 宽度
  width?: number

  // 高度
  height?: number

  // 边距
  padding?: number

  // 像素比
  pixelRatio?: number

  // 质量 (JPEG)
  quality?: number

  // 事件
  onExport?: (format: string, options: any) => void
  onClose?: () => void
}

// 导入对话框 Props
export interface ImportDialogProps extends BaseComponentProps {
  // 可见性
  visible?: boolean

  // 支持的格式
  accept?: string

  // 最大文件大小 (MB)
  maxSize?: number

  // 事件
  onImport?: (file: File, data: any) => void
  onError?: (error: Error) => void
  onClose?: () => void
}

// 快捷键配置
export interface ShortcutConfig {
  // 撤销
  undo?: string | string[]

  // 重做
  redo?: string | string[]

  // 复制
  copy?: string | string[]

  // 粘贴
  paste?: string | string[]

  // 删除
  delete?: string | string[]

  // 全选
  selectAll?: string | string[]

  // 放大
  zoomIn?: string | string[]

  // 缩小
  zoomOut?: string | string[]

  // 适配视图
  fitView?: string | string[]

  // 重置视图
  resetView?: string | string[]

  // 保存
  save?: string | string[]

  // 运行
  run?: string | string[]
}

// 主题配置
export interface ThemeConfig {
  // 主色
  primary?: string

  // 成功色
  success?: string

  // 警告色
  warning?: string

  // 错误色
  error?: string

  // 信息色
  info?: string

  // 文本色
  text?: {
    primary?: string
    secondary?: string
    disabled?: string
  }

  // 背景色
  background?: {
    primary?: string
    secondary?: string
    tertiary?: string
  }

  // 边框色
  border?: {
    light?: string
    base?: string
    dark?: string
  }

  // 阴影
  shadow?: {
    light?: string
    base?: string
    dark?: string
  }

  // 圆角
  borderRadius?: {
    small?: number
    base?: number
    large?: number
  }

  // 字体
  font?: {
    family?: string
    size?: {
      small?: number
      base?: number
      large?: number
    }
    weight?: {
      normal?: number
      bold?: number
    }
  }
}
