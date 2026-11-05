/**
 * @core/workflow-ui 工作流设计器包
 * 主入口文件
 */

import WorkflowDesigner from './components/designer/WorkflowDesigner.vue'
import type { DesignerProps, NodeComponentProps, EdgeComponentProps } from './types/vue'
import type { WorkflowDefinition, WorkflowInstance, WorkflowTask } from './types/workflow'
import type { LFNodeData, LFEdgeData, LFInstance } from './types/logicflow'

// 版本信息
export const VERSION = '0.1.0'

// 导出主要组件
export { WorkflowDesigner }

// 导出类型定义
export type {
  DesignerProps,
  NodeComponentProps,
  EdgeComponentProps,
  WorkflowDefinition,
  WorkflowInstance,
  WorkflowTask,
  LFNodeData,
  LFEdgeData,
  LFInstance
}

// 导出工具函数
export * from './utils/workflow'
export * from './utils/logicflow'
export * from './utils/validation'

// 导出默认配置
export const defaultConfig = {
  grid: {
    enabled: true,
    cell: 20,
    type: 'dot' as const,
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
  theme: {
    baseNode: {
      fill: '#f5f5f5',
      stroke: '#d9d9d9',
      strokeWidth: 1
    },
    baseEdge: {
      stroke: '#555555',
      strokeWidth: 2
    },
    anchor: {
      fill: '#ffffff',
      stroke: '#1890ff',
      strokeWidth: 1
    }
  }
}

// 包信息
export const packageInfo = {
  name: '@core/workflow-ui',
  version: VERSION,
  description: 'AlkaidSYS工作流设计器UI组件包',
  keywords: ['workflow', 'bpmn', 'designer', 'vue3', 'logicflow'],
  author: 'AlkaidSYS Team',
  license: 'Apache-2.0'
}

// 默认导出
export default {
  name: packageInfo.name,
  version: VERSION,
  component: WorkflowDesigner,
  types: {
    DesignerProps,
    NodeComponentProps,
    EdgeComponentProps,
    WorkflowDefinition,
    WorkflowInstance,
    WorkflowTask,
    LFNodeData,
    LFEdgeData,
    LFInstance
  },
  utils: {},
  defaultConfig
}