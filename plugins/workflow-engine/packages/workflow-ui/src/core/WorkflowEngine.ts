/**
 * 工作流引擎核心类
 * Workflow Engine Core Class
 */

import type LogicFlow from '@logicflow/core'
import type { WorkflowDefinition, WorkflowInstance, WorkflowTask } from '../types/workflow'
import type { LFNodeData, LFEdgeData } from '../types/logicflow'
import { validateWorkflow, type ValidationResult } from '../utils/validation'
import { createWorkflowEventEmitter, useWorkflowEvents } from '../utils/event'
import { workflowToLogicFlow, logicFlowToWorkflow } from '../utils/workflow'

/**
 * 工作流引擎选项
 */
export interface WorkflowEngineOptions {
  enableValidation?: boolean
  autoSave?: boolean
  saveInterval?: number
  maxHistory?: number
}

/**
 * 工作流引擎事件
 */
export interface WorkflowEngineEvents {
  'workflow:loaded': [definition: WorkflowDefinition]
  'workflow:changed': [definition: WorkflowDefinition]
  'workflow:saved': [definition: WorkflowDefinition]
  'workflow:validated': [result: ValidationResult]
  'workflow:error': [error: Error]
  'node:added': [node: LFNodeData]
  'node:removed': [nodeId: string]
  'edge:added': [edge: LFEdgeData]
  'edge:removed': [edgeId: string]
}

/**
 * 工作流引擎类
 */
export class WorkflowEngine {
  private lf: LogicFlow | null = null
  private definition: WorkflowDefinition | null = null
  private options: WorkflowEngineOptions
  private eventEmitter = createWorkflowEventEmitter()
  private autoSaveTimer: number | null = null
  private history: WorkflowDefinition[] = []
  private currentHistoryIndex: number = -1

  constructor(options: WorkflowEngineOptions = {}) {
    this.options = {
      enableValidation: true,
      autoSave: false,
      saveInterval: 5000,
      maxHistory: 50,
      ...options
    }
  }

  /**
   * 初始化引擎
   */
  initialize(lf: LogicFlow): void {
    this.lf = lf
    this.bindLogicFlowEvents()

    if (this.options.autoSave) {
      this.startAutoSave()
    }
  }

  /**
   * 绑定LogicFlow事件
   */
  private bindLogicFlowEvents(): void {
    if (!this.lf) return

    // 节点事件
    this.lf.on('node:add', (e) => {
      const node = e.node as any
      this.eventEmitter.emit('node:added', node)
      this.saveToHistory()
    })

    this.lf.on('node:remove', (e) => {
      const node = e.node as any
      this.eventEmitter.emit('node:removed', node.id)
      this.saveToHistory()
    })

    this.lf.on('node:update', (e) => {
      this.saveToHistory()
    })

    // 边事件
    this.lf.on('edge:add', (e) => {
      const edge = e.edge as any
      this.eventEmitter.emit('edge:added', edge)
      this.saveToHistory()
    })

    this.lf.on('edge:remove', (e) => {
      const edge = e.edge as any
      this.eventEmitter.emit('edge:removed', edge.id)
      this.saveToHistory()
    })

    this.lf.on('edge:update', (e) => {
      this.saveToHistory()
    })

    // 历史事件
    this.lf.on('history:change', () => {
      this.updateHistoryIndex()
    })
  }

  /**
   * 加载工作流定义
   */
  async loadWorkflow(definition: WorkflowDefinition): Promise<void> {
    this.definition = { ...definition }

    if (this.options.enableValidation) {
      const validationResult = validateWorkflow(definition)
      this.eventEmitter.emit('workflow:validated', validationResult)

      if (!validationResult.isValid) {
        const error = new Error(`工作流验证失败: ${validationResult.errors.map(e => e.message).join(', ')}`)
        this.eventEmitter.emit('workflow:error', error)
        throw error
      }
    }

    if (this.lf) {
      const graphData = workflowToLogicFlow(definition)
      this.lf.render(graphData)
    }

    this.history = [definition]
    this.currentHistoryIndex = 0
    this.eventEmitter.emit('workflow:loaded', definition)
  }

  /**
   * 保存工作流
   */
  async saveWorkflow(): Promise<WorkflowDefinition> {
    if (!this.definition) {
      throw new Error('没有加载的工作流定义')
    }

    const currentGraphData = this.lf?.getGraphData()
    if (currentGraphData) {
      const workflowData = logicFlowToWorkflow(currentGraphData)
      this.definition = {
        ...this.definition,
        ...workflowData,
        updatedAt: new Date().toISOString()
      }
    }

    this.eventEmitter.emit('workflow:saved', this.definition)
    return this.definition
  }

  /**
   * 获取当前工作流定义
   */
  getWorkflow(): WorkflowDefinition | null {
    return this.definition
  }

  /**
   * 验证当前工作流
   */
  validateWorkflow(): ValidationResult {
    if (!this.definition) {
      return {
        isValid: false,
        errors: [{ type: 'error', code: 'NO_WORKFLOW', message: '没有加载的工作流' }],
        warnings: []
      }
    }

    const result = validateWorkflow(this.definition)
    this.eventEmitter.emit('workflow:validated', result)
    return result
  }

  /**
   * 撤销操作
   */
  undo(): void {
    if (this.lf) {
      this.lf.undo()
    }
  }

  /**
   * 重做操作
   */
  redo(): void {
    if (this.lf) {
      this.lf.redo()
    }
  }

  /**
   * 添加节点
   */
  addNode(config: LFNodeData): void {
    if (!this.lf) return
    this.lf.addNode(config)
  }

  /**
   * 删除节点
   */
  removeNode(nodeId: string): void {
    if (!this.lf) return
    this.lf.removeNode(nodeId)
  }

  /**
   * 更新节点
   */
  updateNode(nodeId: string, config: Partial<LFNodeData>): void {
    if (!this.lf) return
    this.lf.updateNode(nodeId, config)
  }

  /**
   * 添加边
   */
  addEdge(config: LFEdgeData): void {
    if (!this.lf) return
    this.lf.addEdge(config)
  }

  /**
   * 删除边
   */
  removeEdge(edgeId: string): void {
    if (!this.lf) return
    this.lf.removeEdge(edgeId)
  }

  /**
   * 导出工作流
   */
  exportWorkflow(format: 'json' | 'xml' | 'bpmn'): string {
    if (!this.definition) {
      throw new Error('没有加载的工作流定义')
    }

    switch (format) {
      case 'json':
        return JSON.stringify(this.definition, null, 2)
      case 'xml':
      case 'bpmn':
        // TODO: 实现BPMN XML导出
        throw new Error('BPMN XML导出尚未实现')
      default:
        throw new Error(`不支持的导出格式: ${format}`)
    }
  }

  /**
   * 导入工作流
   */
  async importWorkflow(data: string, format: 'json' | 'xml' | 'bpmn'): Promise<void> {
    let definition: WorkflowDefinition

    switch (format) {
      case 'json':
        definition = JSON.parse(data)
        break
      case 'xml':
      case 'bpmn':
        // TODO: 实现BPMN XML导入
        throw new Error('BPMN XML导入尚未实现')
      default:
        throw new Error(`不支持的导入格式: ${format}`)
    }

    await this.loadWorkflow(definition)
  }

  /**
   * 清空工作流
   */
  clearWorkflow(): void {
    if (this.lf) {
      this.lf.clearSelectElements()
    }
    this.definition = null
    this.history = []
    this.currentHistoryIndex = -1
  }

  /**
   * 销毁引擎
   */
  destroy(): void {
    this.stopAutoSave()
    this.eventEmitter.removeAllListeners()
    this.lf = null
  }

  /**
   * 事件监听
   */
  on<K extends keyof WorkflowEngineEvents>(
    event: K,
    handler: WorkflowEngineEvents[K]
  ): void {
    this.eventEmitter.on(event, handler as any)
  }

  /**
   * 一次性事件监听
   */
  once<K extends keyof WorkflowEngineEvents>(
    event: K,
    handler: WorkflowEngineEvents[K]
  ): void {
    this.eventEmitter.once(event, handler as any)
  }

  /**
   * 移除事件监听
   */
  off<K extends keyof WorkflowEngineEvents>(
    event: K,
    handler?: WorkflowEngineEvents[K]
  ): void {
    this.eventEmitter.off(event, handler as any)
  }

  /**
   * 发射事件
   */
  emit<K extends keyof WorkflowEngineEvents>(
    event: K,
    ...args: WorkflowEngineEvents[K]
  ): void {
    this.eventEmitter.emit(event, ...args)
  }

  /**
   * 保存到历史记录
   */
  private saveToHistory(): void {
    if (!this.definition || !this.lf) return

    const graphData = this.lf.getGraphData()
    const workflowData = logicFlowToWorkflow(graphData)
    const newDefinition = {
      ...this.definition,
      ...workflowData,
      updatedAt: new Date().toISOString()
    }

    // 如果当前不在历史末尾，删除后面的历史
    if (this.currentHistoryIndex < this.history.length - 1) {
      this.history = this.history.slice(0, this.currentHistoryIndex + 1)
    }

    // 添加到历史
    this.history.push(newDefinition)
    this.currentHistoryIndex = this.history.length - 1

    // 限制历史记录数量
    if (this.history.length > this.options.maxHistory!) {
      this.history.shift()
      this.currentHistoryIndex--
    }

    this.definition = newDefinition
    this.eventEmitter.emit('workflow:changed', newDefinition)
  }

  /**
   * 更新历史索引
   */
  private updateHistoryIndex(): void {
    if (!this.lf) return
    this.currentHistoryIndex = this.lf.getHistoryIndex()
  }

  /**
   * 启动自动保存
   */
  private startAutoSave(): void {
    if (this.autoSaveTimer) return

    this.autoSaveTimer = window.setInterval(() => {
      this.saveWorkflow().catch(error => {
        console.error('自动保存失败:', error)
      })
    }, this.options.saveInterval)
  }

  /**
   * 停止自动保存
   */
  private stopAutoSave(): void {
    if (this.autoSaveTimer) {
      clearInterval(this.autoSaveTimer)
      this.autoSaveTimer = null
    }
  }
}

/**
 * Vue组合式API Hook
 */
export function useWorkflowEngine() {
  const engine = new WorkflowEngine()

  return {
    engine,
    events: useWorkflowEvents(),

    // 便捷方法
    initialize: engine.initialize.bind(engine),
    loadWorkflow: engine.loadWorkflow.bind(engine),
    saveWorkflow: engine.saveWorkflow.bind(engine),
    getWorkflow: engine.getWorkflow.bind(engine),
    validateWorkflow: engine.validateWorkflow.bind(engine),
    undo: engine.undo.bind(engine),
    redo: engine.redo.bind(engine),
    addNode: engine.addNode.bind(engine),
    removeNode: engine.removeNode.bind(engine),
    updateNode: engine.updateNode.bind(engine),
    addEdge: engine.addEdge.bind(engine),
    removeEdge: engine.removeEdge.bind(engine),
    exportWorkflow: engine.exportWorkflow.bind(engine),
    importWorkflow: engine.importWorkflow.bind(engine),
    clearWorkflow: engine.clearWorkflow.bind(engine),
    destroy: engine.destroy.bind(engine),
    on: engine.on.bind(engine),
    once: engine.once.bind(engine),
    off: engine.off.bind(engine),
    emit: engine.emit.bind(engine)
  }
}