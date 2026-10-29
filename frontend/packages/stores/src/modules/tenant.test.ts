import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it } from 'vitest';

import { useTenantStore } from './tenant';

describe('useTenantStore', () => {
  beforeEach(() => {
    setActivePinia(createPinia());
  });

  it('initializes with null tenant context', () => {
    const store = useTenantStore();
    expect(store.tenantId).toBeNull();
    expect(store.tenantCode).toBeNull();
    expect(store.siteId).toBeNull();
    expect(store.siteCode).toBeNull();
  });

  it('sets and clears tenant context correctly', () => {
    const store = useTenantStore();

    store.setTenant({
      tenantId: 1,
      tenantCode: 'demo',
      siteId: 2,
      siteCode: 'main',
    });

    expect(store.tenantId).toBe(1);
    expect(store.tenantCode).toBe('demo');
    expect(store.siteId).toBe(2);
    expect(store.siteCode).toBe('main');

    store.clearTenant();

    expect(store.tenantId).toBeNull();
    expect(store.tenantCode).toBeNull();
    expect(store.siteId).toBeNull();
    expect(store.siteCode).toBeNull();
  });
});
