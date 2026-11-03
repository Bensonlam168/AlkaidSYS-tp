/**
 * 工作流工具函数测试
 * Workflow Utilities Tests
 */

import { describe, it, expect } from 'vitest'
import {
  validateWorkflowDefinition,
  getStartNode,
  getEndNodes,
  hasCircularDependency,
  generateUniqueId,
  workflowToLogicFlow,
  logicFlowToWorkflow,
  calculateWorkflowComplexity
} from '../../utils/workflow'
import type { WorkflowDefinition, BaseNodeConfig, ConnectionConfig } from '../../types/workflow'

describe('Workflow Utils', () => {
  describe('validateWorkflowDefinition', () => {
    it('should validate a valid workflow', () => {
      const workflow: WorkflowDefinition = {
        id: 'wf-1',
        name: '测试工作流',
        displayName: '测试工作流',
        version: 1,
        status: 'draft',
        nodes: [
          {
            id: 'node-1',
            name: '开始',
            type: 'start',
            x: 100,
            y: 100
          },
          {
            id: 'node-2',
            name: '结束',
            type: 'end',
            x: 300,
            y: 100
          }
        ],
        connections: [
          {
            id: 'conn-1',
            source: 'node-1',
            target: 'node-2'
          }
        ],
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        createdBy: 1
      }

      const result = validateWorkflowDefinition(workflow)
      expect(result.isValid).toBe(true)
      expect(result.errors).toHaveLength(0)
    })

    it('should detect missing name', () => {
      const workflow: Partial<WorkflowDefinition> = {
        id: 'wf-1',
        version: 1,
        status: 'draft',
        nodes: [],
        connections: [],
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        createdBy: 1
      }

      const result = validateWorkflowDefinition(workflow as WorkflowDefinition)
      expect(result.isValid).toBe(false)
      expect(result.errors.length).toBeGreaterThan(0)
    })

    it('should detect missing start node', () => {
      const workflow: WorkflowDefinition = {
        id: 'wf-1',
        name: '测试工作流',
        displayName: '测试工作流',
        version: 1,
        status: 'draft',
        nodes: [
          {
            id: 'node-1',
            name: '任务',
            type: 'task',
            x: 100,
            y: 100
          },
          {
            id: 'node-2',
            name: '结束',
            type: 'end',
            x: 300,
            y: 100
          }
        ],
        connections: [],
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        createdBy: 1
      }

      const result = validateWorkflowDefinition(workflow)
      expect(result.isValid).toBe(false)
      expect(result.errors.some(e => e.includes('开始节点'))).toBe(true)
    })
  })

  describe('getStartNode', () => {
    it('should return start node', () => {
      const nodes: BaseNodeConfig[] = [
        {
          id: 'node-1',
          name: '开始',
          type: 'start',
          x: 100,
          y: 100
        },
        {
          id: 'node-2',
          name: '任务',
          type: 'task',
          x: 300,
          y: 100
        }
      ]

      const startNode = getStartNode(nodes)
      expect(startNode).not.toBeNull()
      expect(startNode?.type).toBe('start')
      expect(startNode?.id).toBe('node-1')
    })

    it('should return null if no start node', () => {
      const nodes: BaseNodeConfig[] = [
        {
          id: 'node-1',
          name: '任务',
          type: 'task',
          x: 100,
          y: 100
        }
      ]

      const startNode = getStartNode(nodes)
      expect(startNode).toBeNull()
    })
  })

  describe('getEndNodes', () => {
    it('should return all end nodes', () => {
      const nodes: BaseNodeConfig[] = [
        {
          id: 'node-1',
          name: '开始',
          type: 'start',
          x: 100,
          y: 100
        },
        {
          id: 'node-2',
          name: '结束1',
          type: 'end',
          x: 300,
          y: 100
        },
        {
          id: 'node-3',
          name: '结束2',
          type: 'end',
          x: 300,
          y: 200
        }
      ]

      const endNodes = getEndNodes(nodes)
      expect(endNodes).toHaveLength(2)
      expect(endNodes.every(node => node.type === 'end')).toBe(true)
    })
  })

  describe('hasCircularDependency', () => {
    it('should detect circular dependency', () => {
      const nodes: BaseNodeConfig[] = [
        { id: 'node-1', name: '开始', type: 'start', x: 100, y: 100 },
        { id: 'node-2', name: '任务', type: 'task', x: 300, y: 100 }
      ]

      const connections: ConnectionConfig[] = [
        { id: 'conn-1', source: 'node-1', target: 'node-2' },
        { id: 'conn-2', source: 'node-2', target: 'node-1' }
      ]

      expect(hasCircularDependency(nodes, connections)).toBe(true)
    })

    it('should not detect circular dependency in valid workflow', () => {
      const nodes: BaseNodeConfig[] = [
        { id: 'node-1', name: '开始', type: 'start', x: 100, y: 100 },
        { id: 'node-2', name: '任务', type: 'task', x: 300, y: 100 },
        { id: 'node-3', name: '结束', type: 'end', x: 500, y: 100 }
      ]

      const connections: ConnectionConfig[] = [
        { id: 'conn-1', source: 'node-1', target: 'node-2' },
        { id: 'conn-2', source: 'node-2', target: 'node-3' }
      ]

      expect(hasCircularDependency(nodes, connections)).toBe(false)
    })
  })

  describe('generateUniqueId', () => {
    it('should generate unique ids', () => {
      const id1 = generateUniqueId('test')
      const id2 = generateUniqueId('test')

      expect(id1).not.toBe(id2)
      expect(id1).toMatch(/^test_/)
      expect(id2).toMatch(/^test_/)
    })
  })

  describe('workflowToLogicFlow', () => {
    it('should convert workflow to LogicFlow format', () => {
      const workflow: WorkflowDefinition = {
        id: 'wf-1',
        name: '测试工作流',
        displayName: '测试工作流',
        version: 1,
        status: 'draft',
        nodes: [
          {
            id: 'node-1',
            name: '开始',
            type: 'start',
            x: 100,
            y: 100
          }
        ],
        connections: [
          {
            id: 'conn-1',
            source: 'node-1',
            target: 'node-2'
          }
        ],
        createdAt: new Date().toISOString(),
        updatedAt: new Date().toISOString(),
        createdBy: 1
      }

      const lfData = workflowToLogicFlow(workflow)

      expect(lfData.nodes).toHaveLength(1)
      expect(lfData.edges).toHaveLength(1)
      expect(lfData.nodes[0].id).toBe('node-1')
      expect(lfData.edges[0].id).toBe('conn-1')
    })
  })

  describe('logicFlowToWorkflow', () => {
    it('should convert LogicFlow format to workflow', () => {
      const lfData = {
        nodes: [
          {
            id: 'node-1',
            type: 'start',
            x: 100,
            y: 100,
            text: '开始'
          }
        ],
        edges: [
          {
            id: 'conn-1',
            type: 'line',
            sourceNodeId: 'node-1',
            targetNodeId: 'node-2'
          }
        ]
      }

      const workflow = logicFlowToWorkflow(lfData)

      expect(workflow.nodes).toHaveLength(1)
      expect(workflow.connections).toHaveLength(1)
      expect(workflow.nodes?.[0].name).toBe('开始')
    })
  })

  describe('calculateWorkflowComplexity', () => {
    it('should calculate complexity metrics', () => {
      const nodes: BaseNodeConfig[] = [
        { id: 'node-1', name: '开始', type: 'start', x: 100, y: 100 },
        { id: 'node-2', name: '任务', type: 'task', x: 300, y: 100 },
        { id: 'node-3', name: '结束', type: 'end', x: 500, y: 100 }
      ]

      const connections: ConnectionConfig[] = [
        { id: 'conn-1', source: 'node-1', target: 'node-2' },
        { id: 'conn-2', source: 'node-2', target: 'node-3' }
      ]

      const metrics = calculateWorkflowComplexity(nodes, connections)

      expect(metrics.nodeCount).toBe(3)
      expect(metrics.edgeCount).toBe(2)
      expect(metrics.complexity).toBeGreaterThan(0)
    })
  })
})