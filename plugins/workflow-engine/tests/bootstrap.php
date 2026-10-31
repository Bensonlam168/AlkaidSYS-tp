<?php

declare(strict_types=1);

/**
 * 工作流引擎测试引导文件
 *
 * @package Alkaid\Workflow\Engine\Tests
 */

// 设置测试环境
define('APP_ENV', 'testing');
define('APP_DEBUG', true);

// 加载Composer自动加载器
require_once __DIR__ . '/../vendor/autoload.php';

// 设置时区
date_default_timezone_set('Asia/Shanghai');

// 设置错误报告级别（测试环境中报告所有错误）
error_reporting(E_ALL);
ini_set('display_errors', '1');

// 设置测试专用配置
$_ENV['APP_ENV'] = 'testing';
$_ENV['DB_CONNECTION'] = 'mysql';
$_ENV['DB_HOST'] = '127.0.0.1';
$_ENV['DB_PORT'] = '3306';
$_ENV['DB_DATABASE'] = 'alkaidsys_test';
$_ENV['DB_USERNAME'] = 'root';
$_ENV['DB_PASSWORD'] = '';
$_ENV['WORKFLOW_DB_PREFIX'] = 'test_';
$_ENV['WORKFLOW_TENANT_ENABLED'] = 'true';
$_ENV['WORKFLOW_DEBUG'] = 'true';

// 注册测试用的异常处理
set_exception_handler(function ($exception) {
    echo '未捕获的异常: ' . $exception->getMessage() . "\n";
    echo '文件: ' . $exception->getFile() . "\n";
    echo '行号: ' . $exception->getLine() . "\n";
    echo "堆栈跟踪:\n" . $exception->getTraceAsString() . "\n";
    exit(1);
});

// 注册测试用的错误处理
set_error_handler(function ($severity, $message, $file, $line) {
    if (!(error_reporting() & $severity)) {
        // 忽略错误
        return false;
    }

    echo "PHP错误 [$severity]: $message 在 $file 的第 $line 行\n";
    return true;
});

// 测试工具函数

/**
 * 创建测试用的工作流定义数据
 *
 * @param array $override 覆盖属性
 * @return array
 */
function createTestWorkflowDefinition(array $override = []): array
{
    return array_merge([
        'id' => 'test_def_' . uniqid(),
        'tenant_id' => 1,
        'name' => 'test_workflow',
        'display_name' => '测试工作流',
        'description' => '这是一个测试工作流',
        'type' => 'approval',
        'category' => 'test',
        'icon' => 'test-icon',
        'version' => 1,
        'status' => 'draft',
        'bpmn_xml' => '<xml>test</xml>',
        'nodes_config' => json_encode([]),
        'triggers_config' => json_encode([]),
        'variables_schema' => json_encode([]),
        'is_active' => true,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'created_by' => 1,
        'updated_by' => 1,
    ], $override);
}

/**
 * 创建测试用的工作流实例数据
 *
 * @param array $override 覆盖属性
 * @return array
 */
function createTestWorkflowInstance(array $override = []): array
{
    return array_merge([
        'id' => 'test_instance_' . uniqid(),
        'definition_id' => 'test_def_' . uniqid(),
        'tenant_id' => 1,
        'business_key' => 'test_business_key',
        'title' => '测试工作流实例',
        'status' => 'pending',
        'priority' => 5,
        'current_node_id' => null,
        'current_node_name' => null,
        'context' => json_encode([]),
        'variables' => json_encode([]),
        'started_at' => null,
        'completed_at' => null,
        'suspended_at' => null,
        'duration' => null,
        'error_message' => null,
        'error_stack' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'created_by' => 1,
        'assignee' => null,
    ], $override);
}

/**
 * 创建测试用的工作流任务数据
 *
 * @param array $override 覆盖属性
 * @return array
 */
function createTestWorkflowTask(array $override = []): array
{
    return array_merge([
        'id' => 'test_task_' . uniqid(),
        'instance_id' => 'test_instance_' . uniqid(),
        'tenant_id' => 1,
        'node_id' => 'test_node',
        'node_name' => '测试节点',
        'task_type' => 'user_task',
        'task_sub_type' => 'approval',
        'title' => '测试任务',
        'description' => '这是一个测试任务',
        'status' => 'pending',
        'priority' => 5,
        'assignee' => 1,
        'assignee_name' => '测试用户',
        'candidate_users' => json_encode([]),
        'candidate_groups' => json_encode([]),
        'form_data' => json_encode([]),
        'result' => json_encode([]),
        'comment' => null,
        'due_date' => null,
        'reminder_times' => json_encode([]),
        'completed_at' => null,
        'rejected_at' => null,
        'cancelled_at' => null,
        'created_at' => date('Y-m-d H:i:s'),
        'updated_at' => date('Y-m-d H:i:s'),
        'created_by' => 1,
    ], $override);
}

/**
 * 创建测试用的工作流事件数据
 *
 * @param array $override 覆盖属性
 * @return array
 */
function createTestWorkflowEvent(array $override = []): array
{
    return array_merge([
        'id' => 'test_event_' . uniqid(),
        'instance_id' => 'test_instance_' . uniqid(),
        'task_id' => 'test_task_' . uniqid(),
        'tenant_id' => 1,
        'event_type' => 'task',
        'event_name' => 'task.created',
        'event_data' => json_encode(['test' => true]),
        'user_id' => 1,
        'user_name' => '测试用户',
        'ip_address' => '127.0.0.1',
        'user_agent' => 'Test Agent',
        'created_at' => date('Y-m-d H:i:s'),
    ], $override);
}

/**
 * 生成随机字符串
 *
 * @param int $length 长度
 * @return string
 */
function generateRandomString(int $length = 10): string
{
    $characters = '0123456789abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ';
    $charactersLength = strlen($characters);
    $randomString = '';
    for ($i = 0; $i < $length; $i++) {
        $randomString .= $characters[rand(0, $charactersLength - 1)];
    }
    return $randomString;
}

/**
 * 模拟网络请求
 *
 * @param string $method HTTP方法
 * @param string $url URL
 * @param array $data 数据
 * @return array
 */
function mockHttpRequest(string $method, string $url, array $data = []): array
{
    return [
        'method' => $method,
        'url' => $url,
        'data' => $data,
        'headers' => [
            'Content-Type' => 'application/json',
            'User-Agent' => 'WorkflowEngineTest/1.0',
        ],
        'timestamp' => time(),
    ];
}

// 输出测试开始信息
if (php_sapi_name() === 'cli') {
    echo "\n=== 工作流引擎测试环境已初始化 ===\n";
    echo '测试环境: ' . APP_ENV . "\n";
    echo 'PHP版本: ' . PHP_VERSION . "\n";
    echo '时区: ' . date_default_timezone_get() . "\n";
    echo "===================================\n\n";
}
