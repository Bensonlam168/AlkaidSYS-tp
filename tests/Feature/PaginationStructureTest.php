<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\ThinkPHPTestCase;

// Load ThinkPHP helper functions | 加载 ThinkPHP 助手函数
if (!function_exists('env')) {
    require_once __DIR__ . '/../../vendor/topthink/framework/src/helper.php';
}

/**
 * Pagination Structure Test | 分页结构测试
 *
 * Tests that pagination responses use the unified structure:
 * { list, total, page, page_size, total_pages }
 *
 * 测试分页响应使用统一的结构：
 * { list, total, page, page_size, total_pages }
 *
 * @package Tests\Feature
 */
class PaginationStructureTest extends ThinkPHPTestCase
{
    /**
     * Test pagination response structure | 测试分页响应结构
     */
    public function testPaginationResponseStructure(): void
    {
        // Create a test controller instance | 创建测试控制器实例
        $controller = new class ($this->app()) extends \app\controller\ApiController {
            public function testPagination()
            {
                $list = [
                    ['id' => 1, 'name' => 'Item 1'],
                    ['id' => 2, 'name' => 'Item 2'],
                    ['id' => 3, 'name' => 'Item 3'],
                ];

                return $this->paginate($list, 100, 1, 10);
            }
        };

        // Call the pagination method | 调用分页方法
        $response = $controller->testPagination();

        // Assert HTTP status code is 200 | 断言 HTTP 状态码为 200
        $this->assertEquals(200, $response->getCode());

        // Parse JSON response | 解析 JSON 响应
        $data = json_decode($response->getContent(), true);

        // Assert response structure | 断言响应结构
        $this->assertArrayHasKey('code', $data);
        $this->assertArrayHasKey('message', $data);
        $this->assertArrayHasKey('data', $data);
        $this->assertArrayHasKey('timestamp', $data);

        // Assert code is 0 (success) | 断言 code 为 0（成功）
        $this->assertEquals(0, $data['code']);

        // Assert pagination fields exist | 断言分页字段存在
        $this->assertArrayHasKey('list', $data['data']);
        $this->assertArrayHasKey('total', $data['data']);
        $this->assertArrayHasKey('page', $data['data']);
        $this->assertArrayHasKey('page_size', $data['data']);
        $this->assertArrayHasKey('total_pages', $data['data']);
    }

    /**
     * Test page_size field uses snake_case | 测试 page_size 字段使用下划线命名
     */
    public function testPageSizeFieldUsesSnakeCase(): void
    {
        $controller = new class ($this->app()) extends \app\controller\ApiController {
            public function testPagination()
            {
                return $this->paginate([], 50, 1, 20);
            }
        };

        $response = $controller->testPagination();
        $data = json_decode($response->getContent(), true);

        // Assert page_size field exists (snake_case) | 断言 page_size 字段存在（下划线命名）
        $this->assertArrayHasKey('page_size', $data['data']);

        // Assert pageSize field does NOT exist (camelCase) | 断言 pageSize 字段不存在（驼峰命名）
        $this->assertArrayNotHasKey('pageSize', $data['data']);

        // Assert page_size value is correct | 断言 page_size 值正确
        $this->assertEquals(20, $data['data']['page_size']);
    }

    /**
     * Test total_pages calculation | 测试 total_pages 计算
     */
    public function testTotalPagesCalculation(): void
    {
        $controller = new class ($this->app()) extends \app\controller\ApiController {
            public function testPagination($total, $pageSize)
            {
                return $this->paginate([], $total, 1, $pageSize);
            }
        };

        // Test case 1: 100 total, 10 per page = 10 pages | 测试用例 1：100 条，每页 10 条 = 10 页
        $response1 = $controller->testPagination(100, 10);
        $data1 = json_decode($response1->getContent(), true);
        $this->assertEquals(10, $data1['data']['total_pages']);

        // Test case 2: 95 total, 10 per page = 10 pages (ceil) | 测试用例 2：95 条，每页 10 条 = 10 页（向上取整）
        $response2 = $controller->testPagination(95, 10);
        $data2 = json_decode($response2->getContent(), true);
        $this->assertEquals(10, $data2['data']['total_pages']);

        // Test case 3: 91 total, 10 per page = 10 pages (ceil) | 测试用例 3：91 条，每页 10 条 = 10 页（向上取整）
        $response3 = $controller->testPagination(91, 10);
        $data3 = json_decode($response3->getContent(), true);
        $this->assertEquals(10, $data3['data']['total_pages']);

        // Test case 4: 0 total, 10 per page = 0 pages | 测试用例 4：0 条，每页 10 条 = 0 页
        $response4 = $controller->testPagination(0, 10);
        $data4 = json_decode($response4->getContent(), true);
        $this->assertEquals(0, $data4['data']['total_pages']);

        // Test case 5: 1 total, 10 per page = 1 page | 测试用例 5：1 条，每页 10 条 = 1 页
        $response5 = $controller->testPagination(1, 10);
        $data5 = json_decode($response5->getContent(), true);
        $this->assertEquals(1, $data5['data']['total_pages']);
    }

    /**
     * Test edge cases | 测试边界情况
     */
    public function testEdgeCases(): void
    {
        $controller = new class ($this->app()) extends \app\controller\ApiController {
            public function testPagination($total, $pageSize)
            {
                return $this->paginate([], $total, 1, $pageSize);
            }
        };

        // Test case: 1 item per page | 测试用例：每页 1 条
        $response1 = $controller->testPagination(100, 1);
        $data1 = json_decode($response1->getContent(), true);
        $this->assertEquals(100, $data1['data']['total_pages']);

        // Test case: Large page size | 测试用例：大的每页数量
        $response2 = $controller->testPagination(100, 100);
        $data2 = json_decode($response2->getContent(), true);
        $this->assertEquals(1, $data2['data']['total_pages']);

        // Test case: Page size larger than total | 测试用例：每页数量大于总数
        $response3 = $controller->testPagination(50, 100);
        $data3 = json_decode($response3->getContent(), true);
        $this->assertEquals(1, $data3['data']['total_pages']);
    }

    /**
     * Test all required fields are present | 测试所有必需字段都存在
     */
    public function testAllRequiredFieldsPresent(): void
    {
        $controller = new class ($this->app()) extends \app\controller\ApiController {
            public function testPagination()
            {
                $list = [
                    ['id' => 1, 'name' => 'Item 1'],
                    ['id' => 2, 'name' => 'Item 2'],
                ];

                return $this->paginate($list, 50, 2, 10);
            }
        };

        $response = $controller->testPagination();
        $data = json_decode($response->getContent(), true);

        // Assert all required fields | 断言所有必需字段
        $requiredFields = ['list', 'total', 'page', 'page_size', 'total_pages'];
        foreach ($requiredFields as $field) {
            $this->assertArrayHasKey($field, $data['data'], "Missing required field: {$field}");
        }

        // Assert field values | 断言字段值
        $this->assertIsArray($data['data']['list']);
        $this->assertCount(2, $data['data']['list']);
        $this->assertEquals(50, $data['data']['total']);
        $this->assertEquals(2, $data['data']['page']);
        $this->assertEquals(10, $data['data']['page_size']);
        $this->assertEquals(5, $data['data']['total_pages']);  // ceil(50 / 10) = 5
    }

    /**
     * Test field types | 测试字段类型
     */
    public function testFieldTypes(): void
    {
        $controller = new class ($this->app()) extends \app\controller\ApiController {
            public function testPagination()
            {
                return $this->paginate([], 100, 1, 10);
            }
        };

        $response = $controller->testPagination();
        $data = json_decode($response->getContent(), true);

        // Assert field types | 断言字段类型
        $this->assertIsArray($data['data']['list']);
        $this->assertIsInt($data['data']['total']);
        $this->assertIsInt($data['data']['page']);
        $this->assertIsInt($data['data']['page_size']);
        $this->assertIsInt($data['data']['total_pages']);
    }
}
