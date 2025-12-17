# 工作流引擎测试指南

## 概述

工作流引擎测试框架基于PHPUnit构建，提供完整的单元测试、集成测试和功能测试支持。测试框架使用Mock对象模拟外部依赖，确保测试的独立性和可重复性。

## 测试结构

```
tests/
├── TestCase.php                          # 测试基类
├── bootstrap.php                         # 测试引导文件
├── Unit/                                 # 单元测试
│   ├── Container/
│   │   └── WorkflowContainerTest.php     # 容器测试
│   ├── Core/
│   │   └── ProcessEnginesTest.php        # 引擎核心测试
│   ├── Integration/
│   │   └── MockRepositoriesTest.php      # Mock仓储测试
│   └── Nodes/
├── Integration/                          # 集成测试
│   └── WorkflowEngineTest.php            # 工作流引擎集成测试
├── Feature/                              # 功能测试
├── Mock/                                 # Mock对象
│   ├── MockProcessDefineRepository.php   # 定义仓储Mock
│   ├── MockProcessInstanceRepository.php # 实例仓储Mock
│   ├── MockProcessTaskRepository.php     # 任务仓储Mock
│   └── MockWorkflowEventRepository.php   # 事件仓储Mock
└── coverage/                             # 代码覆盖率报告
    ├── html/                             # HTML格式报告
    ├── clover.xml                        # Clover格式报告
    └── junit.xml                         # JUnit格式报告
```

## 测试配置

### PHPUnit配置 (phpunit.xml)

```xml
<?xml version="1.0" encoding="UTF-8"?>
<phpunit xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance"
         xsi:noNamespaceSchemaLocation="https://schema.phpunit.de/10.5/phpunit.xsd"
         bootstrap="vendor/autoload.php"
         colors="true"
         verbose="true"
         stopOnFailure="false"
         cacheDirectory=".phpunit.cache">
    <testsuites>
        <testsuite name="Workflow Engine Test Suite">
            <directory>tests</directory>
        </testsuite>
    </testsuites>

    <php>
        <env name="APP_ENV" value="testing"/>
        <env name="DB_CONNECTION" value="mysql"/>
        <env name="DB_HOST" value="127.0.0.1"/>
        <env name="DB_PORT" value="3306"/>
        <env name="DB_DATABASE" value="alkaidsys_test"/>
        <env name="DB_USERNAME" value="root"/>
        <env name="DB_PASSWORD" value=""/>
        <env name="WORKFLOW_DB_PREFIX" value="test_"/>
        <env name="WORKFLOW_TENANT_ENABLED" value="true"/>
        <env name="WORKFLOW_DEBUG" value="true"/>
    </php>

    <logging>
        <log type="coverage-html" target="tests/coverage/html"/>
        <log type="coverage-clover" target="tests/coverage/clover.xml"/>
        <log type="junit" target="tests/coverage/junit.xml"/>
    </logging>

    <filter>
        <whitelist processUncoveredFilesFromWhitelist="true">
            <directory suffix=".php">src</directory>
            <exclude>
                <directory>src/vendor</directory>
                <directory>src/docs</directory>
            </exclude>
        </whitelist>
    </filter>
</phpunit>
```

### 测试环境变量

在 `.env.testing` 文件中设置测试环境变量：

```env
# 测试环境配置
APP_ENV=testing
APP_DEBUG=true

# 数据库配置
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=alkaidsys_test
DB_USERNAME=root
DB_PASSWORD=

# 工作流引擎配置
WORKFLOW_DB_PREFIX=test_
WORKFLOW_TENANT_ENABLED=true
WORKFLOW_DEBUG=true
WORKFLOW_PARTITION_ENABLED=false
WORKFLOW_INDEX_OPTIMIZATION=false

# 缓存配置
CACHE_DRIVER=array
SESSION_DRIVER=array
QUEUE_CONNECTION=sync

# 日志配置
LOG_CHANNEL=testing
LOG_LEVEL=debug
```

## 运行测试

### 基本命令

```bash
# 运行所有测试
./vendor/bin/phpunit

# 运行特定测试文件
./vendor/bin/phpunit tests/Unit/Container/WorkflowContainerTest.php

# 运行特定测试类
./vendor/bin/phpunit --testsuite "Workflow Engine Test Suite"

# 运行特定测试方法
./vendor/bin/phpunit --filter "testContainerInitialization"

# 生成代码覆盖率报告
./vendor/bin/phpunit --coverage-html tests/coverage/html

# 生成Clover格式报告（用于CI/CD）
./vendor/bin/phpunit --coverage-clover tests/coverage/clover.xml

# 运行测试并显示详细输出
./vendor/bin/phpunit -vvv

# 运行测试并停止在第一个失败
./vendor/bin/phpunit --stop-on-failure
```

### 使用Composer脚本

在 `composer.json` 中添加测试脚本：

```json
{
    "scripts": {
        "test": "phpunit",
        "test-unit": "phpunit tests/Unit",
        "test-integration": "phpunit tests/Integration",
        "test-feature": "phpunit tests/Feature",
        "test-coverage": "phpunit --coverage-html tests/coverage/html",
        "test-coverage-clover": "phpunit --coverage-clover tests/coverage/clover.xml",
        "test-coverage-xml": "phpunit --coverage-xml tests/coverage/coverage.xml"
    }
}
```

然后运行：

```bash
# 运行所有测试
composer test

# 运行单元测试
composer test-unit

# 运行集成测试
composer test-integration

# 生成覆盖率报告
composer test-coverage
```

## 测试类型

### 1. 单元测试 (Unit Tests)

测试单个类或方法的功能。

#### 容器测试

测试依赖注入容器的功能：

```php
public function testServiceRegistrationAndRetrieval(): void
{
    $container = $this->getContainer();

    // 注册测试服务
    $testService = new \stdClass();
    $testService->name = 'test_service';

    $container->register('test_service', $testService);

    // 验证服务存在
    $this->assertTrue($container->has('test_service'));

    // 验证服务获取
    $retrievedService = $container->get('test_service');
    $this->assertSame($testService, $retrievedService);
}
```

#### 引擎核心测试

测试工作流引擎核心类：

```php
public function testEngineInstantiation(): void
{
    $engine = $this->getService('processEngines');

    $this->assertInstanceOf(ProcessEngines::class, $engine);
    $this->assertInstanceOf(IProcessEngines::class, $engine);
}
```

#### Mock仓储测试

测试Mock数据访问层：

```php
public function testProcessDefineRepository(): void
{
    // 创建测试定义
    $definition = $this->defineRepository->createTestDefinition([
        'name' => 'test_workflow',
    ]);

    // 验证定义创建
    $this->assertNotNull($definition->getData('id'));
    $this->assertEquals('test_workflow', $definition->getData('name'));

    // 验证数据获取
    $retrievedDefinition = $this->defineRepository->findById($definition->getData('id'));
    $this->assertNotNull($retrievedDefinition);
}
```

### 2. 集成测试 (Integration Tests)

测试多个组件协同工作。

#### 工作流引擎集成测试

```php
public function testWorkflowEngineInitialization(): void
{
    // 初始化工作流引擎
    $adapter = WorkflowBootstrap::initialize();

    // 验证初始化成功
    $this->assertNotNull($adapter);
    $this->assertTrue(WorkflowBootstrap::isInitialized());

    // 验证核心组件
    $container = WorkflowBootstrap::getContainer();
    $this->assertNotNull($container);

    $engine = WorkflowBootstrap::getEngine();
    $this->assertNotNull($engine);
}
```

### 3. 功能测试 (Feature Tests)

测试完整的功能流程。

## Mock对象

### Mock仓储类

测试框架提供四个Mock仓储类：

1. **MockProcessDefineRepository** - 工作流定义数据访问
2. **MockProcessInstanceRepository** - 工作流实例数据访问
3. **MockProcessTaskRepository** - 工作流任务数据访问
4. **MockWorkflowEventRepository** - 工作流事件数据访问

### Mock对象功能

- **数据模拟**: 模拟数据库操作
- **自动ID生成**: 自动生成唯一ID
- **批量操作**: 支持批量CRUD操作
- **数据清理**: 提供清理方法
- **测试数据**: 内置测试数据生成方法

### 使用示例

```php
// 创建定义仓储
$defineRepository = new MockProcessDefineRepository();

// 创建测试定义
$definition = $defineRepository->createTestDefinition([
    'name' => 'test_workflow',
    'display_name' => '测试工作流',
]);

// 获取定义
$retrievedDefinition = $defineRepository->findById($definition->getData('id'));

// 更新定义
$defineRepository->updateById($definition->getData('id'), [
    'status' => 'published',
]);

// 删除定义
$defineRepository->deleteById($definition->getData('id'));

// 清理数据
$defineRepository->clear();
```

## 测试基类 (TestCase)

### 功能特性

- **自动初始化**: 自动创建测试容器和配置
- **Mock服务**: 提供Mock服务注册
- **测试数据**: 内置测试数据生成方法
- **断言方法**: 提供自定义断言方法
- **资源清理**: 自动清理测试资源

### 使用示例

```php
use Alkaid\Workflow\Engine\Tests\TestCase;

class MyTest extends TestCase
{
    protected function setupTestData(): void
    {
        // 设置测试数据
    }

    public function testMyFeature(): void
    {
        // 获取容器
        $container = $this->getContainer();

        // 获取服务
        $service = $this->getService('my_service');

        // 使用断言
        $this->assertNotNull($service);
        $this->assertContainerHasService('my_service');
    }
}
```

## 测试工具函数

### 引导文件提供工具函数

```php
// 创建测试数据
$definition = createTestWorkflowDefinition();
$instance = createTestWorkflowInstance();
$task = createTestWorkflowTask();
$event = createTestWorkflowEvent();

// 生成随机字符串
$randomString = generateRandomString(10);

// 模拟HTTP请求
$request = mockHttpRequest('POST', '/api/workflow', $data);
```

## 代码覆盖率

### 覆盖率报告

```bash
# 生成HTML覆盖率报告
./vendor/bin/phpunit --coverage-html tests/coverage/html

# 生成Clover格式报告（用于CI/CD）
./vendor/bin/phpunit --coverage-clover tests/coverage/clover.xml

# 生成XML格式报告
./vendor/bin/phpunit --coverage-xml tests/coverage/coverage.xml

# 生成文本覆盖率报告
./vendor/bin/phpunit --coverage-text
```

### 覆盖率配置

在 `phpunit.xml` 中配置覆盖率过滤：

```xml
<filter>
    <whitelist processUncoveredFilesFromWhitelist="true">
        <directory suffix=".php">src</directory>
        <exclude>
            <directory>src/vendor</directory>
            <directory>src/docs</directory>
            <file>src/Container/WorkflowContainer.php</file>
        </exclude>
    </whitelist>
</filter>
```

## 最佳实践

### 1. 测试命名

- 测试类名：`[ClassName]Test`
- 测试方法名：`test[FeatureName]`
- 测试文件路径：`tests/Unit/[Module]/[ClassName]Test.php`

### 2. 测试结构

```php
public function testFeatureName(): void
{
    // Arrange - 设置测试数据
    $expected = 'value';

    // Act - 执行被测功能
    $actual = $this->object->method();

    // Assert - 验证结果
    $this->assertEquals($expected, $actual);
}
```

### 3. 断言方法

```php
// 基本断言
$this->assertEquals($expected, $actual);
$this->assertNotEquals($expected, $actual);
$this->assertTrue($condition);
$this->assertFalse($condition);
$this->assertNull($value);
$this->assertNotNull($value);

// 容器断言
$this->assertContainerHasService('service_name');
$this->assertContainerDoesNotHaveService('service_name');
$this->assertServiceInstanceOf('service_name', ServiceClass::class);

// 数组断言
$this->assertArrayHasKeys($array, ['key1', 'key2']);
$this->assertArrayDoesNotHaveKeys($array, ['key1', 'key2']);
```

### 4. Mock使用

```php
// 创建Mock对象
$mock = $this->createMock(ServiceInterface::class);

// 设置期望
$mock->expects($this->once())
     ->method('methodName')
     ->with($expectedParameter)
     ->willReturn($expectedResult);

// 使用Mock
$container = $this->getContainer();
$container->register('service_name', $mock);
```

### 5. 测试隔离

- 每个测试应该是独立的
- 在 `setUp()` 中初始化
- 在 `tearDown()` 中清理
- 使用Mock对象隔离外部依赖

### 6. 数据驱动测试

```php
/**
 * @dataProvider additionProvider
 */
public function testAddition(int $a, int $b, int $expected): void
{
    $result = $a + $b;
    $this->assertEquals($expected, $result);
}

public function additionProvider(): array
{
    return [
        [1, 2, 3],
        [0, 0, 0],
        [-1, 1, 0],
    ];
}
```

## CI/CD集成

### GitHub Actions

```yaml
name: Tests

on: [push, pull_request]

jobs:
  test:
    runs-on: ubuntu-latest
    steps:
      - uses: actions/checkout@v2

      - name: Setup PHP
        uses: shivammathur/setup-php@v2
        with:
          php-version: 8.2
          extensions: mbstring, xml, ctype, iconv, intl, pdo_mysql, dom, filter, gd, iconv, json, session

      - name: Install dependencies
        run: composer install --no-progress --prefer-dist --optimize-autoloader

      - name: Run tests
        run: ./vendor/bin/phpunit --coverage-clover tests/coverage/clover.xml

      - name: Upload coverage to Codecov
        uses: codecov/codecov-action@v2
        with:
          file: ./tests/coverage/clover.xml
```

### GitLab CI

```yaml
test:
  stage: test
  image: php:8.2
  services:
    - mysql:8.0
  variables:
    MYSQL_ROOT_PASSWORD: password
    MYSQL_DATABASE: alkaidsys_test
    DB_HOST: mysql
    DB_USERNAME: root
    DB_PASSWORD: password
    DB_DATABASE: alkaidsys_test
  before_script:
    - composer install --no-progress --prefer-dist --optimize-autoloader
  script:
    - ./vendor/bin/phpunit --coverage-clover tests/coverage/clover.xml
  coverage: '/Lines:\s+\d+\.\d+\%/'
  artifacts:
    reports:
      coverage_report:
        coverage_format: clover
        path: tests/coverage/clover.xml
```

## 故障排除

### 常见问题

1. **内存不足**
   ```bash
   # 增加PHP内存限制
   php -d memory_limit=1G vendor/bin/phpunit
   ```

2. **测试超时**
   ```xml
   <!-- 在phpunit.xml中增加超时时间 -->
   <phpunit timeoutForSmallTests="10" timeoutForMediumTests="30" timeoutForLargeTests="60">
   ```

3. **数据库连接失败**
   - 检查测试数据库配置
   - 确保数据库服务运行
   - 验证连接参数

4. **Mock对象不生效**
   - 检查Mock对象注册
   - 验证依赖注入配置
   - 确保使用正确的接口

### 调试测试

```bash
# 启用详细输出
./vendor/bin/phpunit -vvv

# 在特定测试停止
./vendor/bin/phpunit --stop-on-failure

# 输出调试信息
./vendor/bin/phpunit --debug

# 显示测试进度
./vendor/bin/phpunit --progress
```

## 总结

工作流引擎测试框架提供了：

1. **完整的测试覆盖** - 单元测试、集成测试、功能测试
2. **Mock对象支持** - 隔离外部依赖
3. **代码覆盖率报告** - 量化测试质量
4. **CI/CD集成** - 自动化测试流程
5. **详细的文档** - 易于使用和维护

遵循本指南，您可以高效地编写和维护工作流引擎的测试用例。
