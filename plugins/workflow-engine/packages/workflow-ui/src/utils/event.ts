/**
 * 事件发射器工具函数
 * Event Emitter Utility Functions
 */

type EventHandler = (...args: any[]) => void

interface EventMap {
  [event: string]: EventHandler[]
}

/**
 * 简单的事件发射器
 */
class EventEmitter {
  private events: EventMap = {}

  /**
   * 订阅事件
   */
  on(event: string, handler: EventHandler): void {
    if (!this.events[event]) {
      this.events[event] = []
    }
    this.events[event].push(handler)
  }

  /**
   * 订阅一次性事件
   */
  once(event: string, handler: EventHandler): void {
    const onceHandler: EventHandler = (...args: any[]) => {
      handler(...args)
      this.off(event, onceHandler)
    }
    this.on(event, onceHandler)
  }

  /**
   * 取消订阅事件
   */
  off(event: string, handler?: EventHandler): void {
    if (!this.events[event]) return

    if (!handler) {
      delete this.events[event]
      return
    }

    const index = this.events[event].indexOf(handler)
    if (index > -1) {
      this.events[event].splice(index, 1)
    }

    if (this.events[event].length === 0) {
      delete this.events[event]
    }
  }

  /**
   * 发射事件
   */
  emit(event: string, ...args: any[]): void {
    const handlers = this.events[event]
    if (handlers) {
      handlers.forEach(handler => {
        try {
          handler(...args)
        } catch (error) {
          console.error(`Error in event handler for '${event}':`, error)
        }
      })
    }
  }

  /**
   * 获取事件处理器数量
   */
  listenerCount(event: string): number {
    return this.events[event]?.length || 0
  }

  /**
   * 获取所有事件名
   */
  eventNames(): string[] {
    return Object.keys(this.events)
  }

  /**
   * 移除所有事件监听器
   */
  removeAllListeners(event?: string): void {
    if (event) {
      delete this.events[event]
    } else {
      this.events = {}
    }
  }
}

/**
 * 创建事件发射器实例
 */
export function createEventEmitter(): EventEmitter {
  return new EventEmitter()
}

/**
 * Vue组合式API事件发射器Hook
 */
export function useEventEmitter() {
  const emitter = createEventEmitter()

  return {
    // 订阅事件
    on: emitter.on.bind(emitter),
    once: emitter.once.bind(emitter),
    off: emitter.off.bind(emitter),

    // 发射事件
    emit: emitter.emit.bind(emitter),

    // 工具方法
    listenerCount: emitter.listenerCount.bind(emitter),
    eventNames: emitter.eventNames.bind(emitter),
    removeAllListeners: emitter.removeAllListeners.bind(emitter)
  }
}

/**
 * 预定义的工作流事件类型
 */
export const WORKFLOW_EVENTS = {
  // 画布事件
  CANVAS_CLICK: 'canvas:click',
  CANVAS_CONTEXTMENU: 'canvas:contextmenu',
  CANVAS_DROP: 'canvas:drop',

  // 节点事件
  NODE_CLICK: 'node:click',
  NODE_CONTEXTMENU: 'node:contextmenu',
  NODE_DOUBLECLICK: 'node:dblclick',
  NODE_SELECTED: 'node:selected',
  NODE_UNSELECTED: 'node:unselected',
  NODE_DRAGSTART: 'node:dragstart',
  NODE_DRAG: 'node:drag',
  NODE_DRAGEND: 'node:dragend',

  // 边事件
  EDGE_CLICK: 'edge:click',
  EDGE_CONTEXTMENU: 'edge:contextmenu',
  EDGE_SELECTED: 'edge:selected',
  EDGE_UNSELECTED: 'edge:unselected',

  // 连接事件
  CONNECTION: 'connection',
  DISCONNECTION: 'disconnection',

  // 选择事件
  SELECTION_SELECTED: 'selection:selected',
  SELECTION_UNSELECTED: 'selection:unselected',

  // 历史事件
  HISTORY_CHANGE: 'history:change',

  // 自定义事件
  WORKFLOW_CHANGE: 'workflow:change',
  WORKFLOW_SAVE: 'workflow:save',
  WORKFLOW_VALIDATE: 'workflow:validate',
  WORKFLOW_ERROR: 'workflow:error'
} as const

/**
 * 工作流事件类型
 */
export type WorkflowEventType = typeof WORKFLOW_EVENTS[keyof typeof WORKFLOW_EVENTS]

/**
 * 工作流事件参数
 */
export interface WorkflowEventParams {
  type: WorkflowEventType
  data?: any
  source?: string
  timestamp: number
}

/**
 * 创建工作流事件发射器
 */
export function createWorkflowEventEmitter(): EventEmitter & {
  trigger: (event: WorkflowEventType, data?: any) => void
  getHistory: () => WorkflowEventParams[]
} {
  const emitter = createEventEmitter()
  const history: WorkflowEventParams[] = []

  // 添加历史记录功能
  const originalEmit = emitter.emit.bind(emitter)
  emitter.emit = (event: string, ...args: any[]) => {
    const params: WorkflowEventParams = {
      type: event as WorkflowEventType,
      data: args[0],
      timestamp: Date.now()
    }
    history.push(params)

    // 保持历史记录不超过1000条
    if (history.length > 1000) {
      history.shift()
    }

    originalEmit(event, ...args)
  }

  return {
    ...emitter,
    trigger: (event: WorkflowEventType, data?: any) => {
      emitter.emit(event, data)
    },
    getHistory: () => [...history]
  }
}

/**
 * Vue组合式API工作流事件Hook
 */
export function useWorkflowEvents() {
  const emitter = createWorkflowEventEmitter()

  // 便捷方法
  const triggerCanvasClick = (data: any) => {
    emitter.trigger(WORKFLOW_EVENTS.CANVAS_CLICK, data)
  }

  const triggerNodeClick = (node: any) => {
    emitter.trigger(WORKFLOW_EVENTS.NODE_CLICK, node)
  }

  const triggerEdgeClick = (edge: any) => {
    emitter.trigger(WORKFLOW_EVENTS.EDGE_CLICK, edge)
  }

  const triggerWorkflowChange = (data: any) => {
    emitter.trigger(WORKFLOW_EVENTS.WORKFLOW_CHANGE, data)
  }

  const triggerWorkflowSave = (data: any) => {
    emitter.trigger(WORKFLOW_EVENTS.WORKFLOW_SAVE, data)
  }

  return {
    // 基础事件方法
    on: emitter.on.bind(emitter),
    once: emitter.once.bind(emitter),
    off: emitter.off.bind(emitter),
    emit: emitter.emit.bind(emitter),

    // 便捷触发方法
    trigger: emitter.trigger.bind(emitter),
    triggerCanvasClick,
    triggerNodeClick,
    triggerEdgeClick,
    triggerWorkflowChange,
    triggerWorkflowSave,

    // 工具方法
    getHistory: emitter.getHistory,
    listenerCount: emitter.listenerCount.bind(emitter),
    eventNames: emitter.eventNames.bind(emitter),
    removeAllListeners: emitter.removeAllListeners.bind(emitter)
  }
}