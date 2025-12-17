/**
 * 测试环境设置
 * Test Environment Setup
 */

import { beforeAll, vi } from 'vitest'

// 模拟窗口对象
Object.defineProperty(window, 'TextEncoder', {
  writable: true,
  value: TextEncoder
})

Object.defineProperty(window, 'TextDecoder', {
  writable: true,
  value: TextDecoder
})

// 模拟 IntersectionObserver
global.IntersectionObserver = class IntersectionObserver {
  constructor() {}
  observe() {
    return null
  }
  disconnect() {
    return null
  }
  unobserve() {
    return null
  }
}

// 模拟 ResizeObserver
global.ResizeObserver = class ResizeObserver {
  constructor() {}
  observe() {
    return null
  }
  disconnect() {
    return null
  }
  unobserve() {
    return null
  }
}

// 模拟 matchMedia
Object.defineProperty(window, 'matchMedia', {
  writable: true,
  value: vi.fn().mockImplementation((query) => ({
    matches: false,
    media: query,
    onchange: null,
    addListener: vi.fn(),
    removeListener: vi.fn(),
    addEventListener: vi.fn(),
    removeEventListener: vi.fn(),
    dispatchEvent: vi.fn()
  }))
})

// 模拟 console
global.console = {
  ...console,
  warn: vi.fn(),
  error: vi.fn()
}