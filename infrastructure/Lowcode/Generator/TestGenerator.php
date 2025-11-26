<?php

declare(strict_types=1);

namespace Infrastructure\Lowcode\Generator;

use Domain\Lowcode\Collection\Interfaces\CollectionInterface;

/**
 * Test Generator | 测试生成器
 *
 * Generates test code for a Collection.
 * 为Collection生成测试代码。
 *
 * @package Infrastructure\Lowcode\Generator
 */
class TestGenerator
{
    /**
     * Generate test code | 生成测试代码
     *
     * @param CollectionInterface $collection Collection instance | Collection实例
     * @param array $options Generation options | 生成选项
     * @return string Generated file path | 生成的文件路径
     */
    public function generate(CollectionInterface $collection, array $options = []): string
    {
        $name = $collection->getName();
        $className = $this->getClassName($name);
        $resourceName = strtolower($name);

        $code = $this->generateCode($className, $resourceName, $collection);

        $filePath = $this->getFilePath($className, $options);
        $this->writeFile($filePath, $code);

        return $filePath;
    }

    /**
     * Get class name from collection name | 从Collection名称获取类名
     *
     * @param string $name Collection name | Collection名称
     * @return string Class name | 类名
     */
    protected function getClassName(string $name): string
    {
        $parts = explode('_', $name);
        $parts = array_map('ucfirst', $parts);
        return implode('', $parts) . 'CrudTest';
    }

    /**
     * Generate test code | 生成测试代码
     *
     * @param string $className Class name | 类名
     * @param string $resourceName Resource name | 资源名称
     * @param CollectionInterface $collection Collection instance | Collection实例
     * @return string Generated code | 生成的代码
     */
    protected function generateCode(string $className, string $resourceName, CollectionInterface $collection): string
    {
        $sampleData = $this->generateSampleData($collection);

        return <<<PHP
<?php

declare(strict_types=1);

namespace Tests\Feature\Lowcode;

use Tests\ThinkPHPTestCase;

/**
 * {$className} | {$resourceName}CRUD测试
 *
 * Auto-generated CRUD tests for {$resourceName} collection.
 * 自动生成的{$resourceName}集合CRUD测试。
 *
 * @package Tests\Feature\Lowcode
 */
class {$className} extends ThinkPHPTestCase
{
    protected string \$baseUrl = '/v1/{$resourceName}';
    protected int \$tenantId = 1;
    protected int \$siteId = 0;
    protected ?int \$createdId = null;

    /**
     * Test list records | 测试列表记录
     *
     * @return void
     */
    public function testIndex(): void
    {
        \$response = \$this->get(\$this->baseUrl, [
            'X-Tenant-ID' => \$this->tenantId,
            'X-Site-ID' => \$this->siteId,
        ]);

        \$this->assertEquals(200, \$response->getStatusCode());
        \$data = json_decode(\$response->getBody(), true);
        \$this->assertArrayHasKey('list', \$data);
        \$this->assertArrayHasKey('total', \$data);
    }

    /**
     * Test create record | 测试创建记录
     *
     * @return void
     */
    public function testCreate(): void
    {
        \$data = {$sampleData};

        \$response = \$this->post(\$this->baseUrl, \$data, [
            'X-Tenant-ID' => \$this->tenantId,
            'X-Site-ID' => \$this->siteId,
        ]);

        \$this->assertEquals(201, \$response->getStatusCode());
        \$result = json_decode(\$response->getBody(), true);
        \$this->assertArrayHasKey('id', \$result);
        \$this->createdId = \$result['id'];
    }

    /**
     * Test read record | 测试读取记录
     *
     * @depends testCreate
     * @return void
     */
    public function testRead(): void
    {
        if (!\$this->createdId) {
            \$this->markTestSkipped('No record created');
        }

        \$response = \$this->get(\$this->baseUrl . '/' . \$this->createdId, [
            'X-Tenant-ID' => \$this->tenantId,
            'X-Site-ID' => \$this->siteId,
        ]);

        \$this->assertEquals(200, \$response->getStatusCode());
        \$data = json_decode(\$response->getBody(), true);
        \$this->assertArrayHasKey('id', \$data);
        \$this->assertEquals(\$this->createdId, \$data['id']);
    }

    /**
     * Test update record | 测试更新记录
     *
     * @depends testCreate
     * @return void
     */
    public function testUpdate(): void
    {
        if (!\$this->createdId) {
            \$this->markTestSkipped('No record created');
        }

        \$data = {$sampleData};

        \$response = \$this->put(\$this->baseUrl . '/' . \$this->createdId, \$data, [
            'X-Tenant-ID' => \$this->tenantId,
            'X-Site-ID' => \$this->siteId,
        ]);

        \$this->assertEquals(200, \$response->getStatusCode());
    }

    /**
     * Test delete record | 测试删除记录
     *
     * @depends testCreate
     * @return void
     */
    public function testDelete(): void
    {
        if (!\$this->createdId) {
            \$this->markTestSkipped('No record created');
        }

        \$response = \$this->delete(\$this->baseUrl . '/' . \$this->createdId, [
            'X-Tenant-ID' => \$this->tenantId,
            'X-Site-ID' => \$this->siteId,
        ]);

        \$this->assertEquals(200, \$response->getStatusCode());
    }
}

PHP;
    }

    /**
     * Generate sample data from collection | 从Collection生成示例数据
     *
     * @param CollectionInterface $collection Collection instance | Collection实例
     * @return string Sample data array | 示例数据数组
     */
    protected function generateSampleData(CollectionInterface $collection): string
    {
        $data = [];

        foreach ($collection->getFields() as $field) {
            $fieldName = $field->getName();
            $fieldType = $field->getType();

            $data[$fieldName] = $this->getSampleValue($fieldType);
        }

        return var_export($data, true);
    }

    /**
     * Get sample value by field type | 根据字段类型获取示例值
     *
     * @param string $type Field type | 字段类型
     * @return mixed Sample value | 示例值
     */
    protected function getSampleValue(string $type)
    {
        $samples = [
            'string' => 'Test String',
            'text' => 'Test Text Content',
            'integer' => 100,
            'bigint' => 1000000,
            'decimal' => 99.99,
            'boolean' => true,
            'date' => date('Y-m-d'),
            'datetime' => date('Y-m-d H:i:s'),
            'timestamp' => time(),
            'json' => ['key' => 'value'],
            'select' => 'option1',
            'radio' => 'option1',
            'checkbox' => ['option1', 'option2'],
            'file' => '/path/to/file.pdf',
            'image' => '/path/to/image.jpg',
        ];

        return $samples[$type] ?? 'test_value';
    }

    /**
     * Get file path for test | 获取测试文件路径
     *
     * @param string $className Class name | 类名
     * @param array $options Generation options | 生成选项
     * @return string File path | 文件路径
     */
    protected function getFilePath(string $className, array $options): string
    {
        $basePath = $options['test_path'] ?? app()->getRootPath() . 'tests/Feature/Lowcode';
        return $basePath . '/' . $className . '.php';
    }

    /**
     * Write file | 写入文件
     *
     * @param string $filePath File path | 文件路径
     * @param string $content File content | 文件内容
     * @return void
     */
    protected function writeFile(string $filePath, string $content): void
    {
        $dir = dirname($filePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($filePath, $content);
    }
}
