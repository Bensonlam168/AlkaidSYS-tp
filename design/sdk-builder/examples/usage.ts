import { createClient } from '@alkaidsys/sdk';
import type { components } from '@alkaidsys/sdk/types/api';

type Order = components['schemas']['Order'];

async function main() {
  const client = createClient({
    baseUrl: process.env.API_BASE || 'http://localhost:9501',
    getToken: async () => process.env.TOKEN || ''
  });

  // 查询最近订单
  const orders = await client.db<Order>('orders')
    .select(['id', 'order_no', 'total_amount', 'status', 'created_at'])
    .orderBy('created_at', 'desc')
    .limit(10)
    .get();
  console.log('orders:', orders);

  // 创建订单（演示）
  const created = await client.db<Order>('orders')
    .insert({ order_no: 'SDK' + Date.now(), total_amount: 100, status: 1 } as Partial<Order>);
  console.log('created:', created);

  // 更新订单状态
  const affected = await client.db<Order>('orders')
    .eq('id', created.id as any)
    .update({ status: 2 } as Partial<Order>);
  console.log('affected:', affected);
}

main().catch((e) => {
  console.error(e);
  process.exit(1);
});
