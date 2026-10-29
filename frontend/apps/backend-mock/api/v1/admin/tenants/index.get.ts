import { faker } from '@faker-js/faker';
import { eventHandler, getQuery } from 'h3';
import { verifyAccessToken } from '~/utils/jwt-utils';
import { unAuthorizedResponse, usePageResponseSuccess } from '~/utils/response';

const formatterCN = new Intl.DateTimeFormat('zh-CN', {
  timeZone: 'Asia/Shanghai',
  year: 'numeric',
  month: '2-digit',
  day: '2-digit',
  hour: '2-digit',
  minute: '2-digit',
  second: '2-digit',
});

/**
 * Generate mock tenant data
 */
function generateMockDataList(count: number) {
  const dataList = [];

  for (let i = 0; i < count; i++) {
    const dataItem: Record<string, any> = {
      id: i + 1,
      name: faker.company.name(),
      slug: faker.helpers.slugify(faker.company.name()).toLowerCase(),
      domain: faker.internet.domainName(),
      status: faker.helpers.arrayElement(['active', 'inactive', 'suspended']),
      config: {},
      max_sites: faker.number.int({ min: 1, max: 10 }),
      max_users: faker.number.int({ min: 5, max: 100 }),
      expires_at: faker.date.future().toISOString(),
      created_at: formatterCN.format(
        faker.date.between({ from: '2022-01-01', to: '2025-01-01' }),
      ),
      updated_at: formatterCN.format(
        faker.date.between({ from: '2024-01-01', to: '2025-01-01' }),
      ),
    };

    dataList.push(dataItem);
  }

  return dataList;
}

const mockData = generateMockDataList(50);

/**
 * Mock API for GET /v1/admin/tenants
 * Returns paginated tenant list
 */
export default eventHandler(async (event) => {
  const userinfo = verifyAccessToken(event);
  if (!userinfo) {
    return unAuthorizedResponse(event);
  }

  const {
    page = 1,
    pageSize = 20,
    name,
    slug,
    status,
    domain,
  } = getQuery(event);

  let listData = structuredClone(mockData);

  // Apply filters
  if (name) {
    listData = listData.filter((item) =>
      item.name.toLowerCase().includes(String(name).toLowerCase()),
    );
  }
  if (slug) {
    listData = listData.filter((item) =>
      item.slug.toLowerCase().includes(String(slug).toLowerCase()),
    );
  }
  if (domain) {
    listData = listData.filter((item) =>
      item.domain?.toLowerCase()?.includes(String(domain).toLowerCase()),
    );
  }
  if (status && ['active', 'inactive', 'suspended'].includes(status as string)) {
    listData = listData.filter((item) => item.status === status);
  }

  return usePageResponseSuccess(page as string, pageSize as string, listData);
});
