<?php

/**
 *+------------------
 * ingenious
 *+------------------
 * Copyright (c) https://gitcode.com/motion-code  All rights reserved.
 *+------------------
 * Author: Mr. April (405784684@qq.com)
 *+------------------
 * Software Registration Number: 2024SR0694589
 * Official Website: https://madong.tech
 */

namespace Alkaid\Workflow\Engine\Core;

use DI\Container;
use Exception;
use Alkaid\Workflow\Engine\ex\LFlowException;
use Alkaid\Workflow\Engine\interface\IServiceContext;
use Alkaid\Workflow\Engine\libs\utils\Logger;
use ReflectionClass;

class ServiceContext implements IServiceContext
{
    protected static ?Container $container = null;
    protected static array $config = [];//扩展配置
    protected static array $backupConfig = []; //备份配置

    public static function setContext(array $context): void
    {
        if (!class_exists(\DI\ContainerBuilder::class)) {
            throw new LFlowException('The "PHP-DI" extension is not installed. Please install it to use the ServiceContext.');
        }

        // 验证上下文的有效性
        if (empty($context)) {
            throw new LFlowException('Context cannot be empty.');
        }

        $builder = new \DI\ContainerBuilder();
        $builder->addDefinitions($context);
        $builder->useAutowiring(true);
        //        $builder->useAnnotations(false); 7.x开始废弃默认开启
        self::$container = $builder->build();
    }

    public static function hasContext(): bool
    {
        return isset(self::$container);
    }

    public static function put(string $name, object $object): void
    {
        self::ensureContextIsSet();
        self::$container->set($name, $object);
    }

    public static function putClass(string $name, string $className): void
    {
        self::ensureContextIsSet();
        self::$container->set($name, \DI\create($className));
    }

    public static function exist(string $name): bool
    {
        return self::hasContext() && self::$container->has($name);
    }

    public static function find(string $clazz): mixed
    {
        return self::hasContext() && self::$container->has($clazz) ? self::$container->get($clazz) : null;
    }

    public static function findAll(string $interfaceName, array $constructorArgs = [], bool $instantiate = true): array
    {
        $instances = [];
        if (!interface_exists($interfaceName)) {
            return [];
        }
        foreach (get_declared_classes() as $class) {
            try {
                $reflectionClass = new ReflectionClass($class);
                if ($reflectionClass->implementsInterface($interfaceName) && $reflectionClass->isInstantiable()) {
                    if ($instantiate) {
                        $instances[] = $reflectionClass->newInstanceArgs($constructorArgs);
                    } else {
                        $instances[] = $class;
                    }
                    continue;
                }
            } catch (Exception $e) {
                Logger::error($e->getMessage());
            }
        }
        return $instances;
    }

    public static function findFirst(string $interfaceName)
    {
        try {
            $implementations = self::findAll($interfaceName);
            return !empty($implementations) ? $implementations[0] : null;
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    public static function findLast(string $interfaceName)
    {
        try {
            $implementations = self::findAll($interfaceName);
            return !empty($implementations) ? end($implementations) : null;
        } catch (\ReflectionException $e) {
            return null;
        }
    }

    /**
     * 注册单个服务或上下文值
     *
     * 取值口径（三类）：
     * 1）**存在的类名**字符串 —— 按类路径登记，取用时由 PHP-DI 实例化（原有行为，未变）；
     * 2）对象 —— 直接登记该实例（原有行为，未变）；
     * 3）标量 / null / 非类名字符串 —— 按**字面值**登记。
     *
     * 为什么必须有第 3 类：请求上下文（current_user_id、current_tenant_id、
     * request_ip_address、request_user_agent …）本质是 int/string/null 的**值**，
     * 不是可实例化的类。改造前这里对非 string/object 一律抛
     * LFlowException('Value must be a class path or an object.')，
     * 于是 ThinkPHPAdapter::setupRequestContext() 只要真的拿到了请求就会失败 ——
     * getCurrentUserId() 返回 int，registerBatch() 立刻抛异常，
     * 整个引擎初始化被 catch 成「ThinkPHP集成初始化失败」。
     * 也就是说「HTTP 请求上下文注入」这条路径此前从未真正可用。
     *
     * 另外，字面值字符串（如 CLI 场景的 'cli'）必须在第 3 类里走，
     * 否则会被当成类名交给 DI\create()，得到一个指向不存在类 "cli" 的定义。
     *
     * @param string $name  注册名
     * @param mixed  $value 类名、对象或字面值
     * @return void
     */
    public static function register(string $name, mixed $value): void
    {
        self::ensureContextIsSet();

        if (is_string($value) && class_exists($value)) {
            self::$container->set($name, \DI\create($value));
            return;
        }

        // 对象、标量、null、非类名字符串：一律按字面值登记
        self::$container->set($name, $value);
    }

    public static function registerBatch(array $services): void
    {
        foreach ($services as $name => $value) {
            self::register($name, $value);
        }
    }

    public static function extensions(array $config = []): void
    {
        self::$config = $config;
    }

    public static function getConfig(string $name, mixed $default = null): mixed
    {
        return self::$config[$name] ?? $default;
    }

    public static function setBackupConfig(array $data = []): array
    {
        return self::$backupConfig = $data;
    }

    public static function getBackupConfig(): array
    {
        return self::$backupConfig ?? [];
    }

    private static function ensureContextIsSet(): void
    {
        if (!self::hasContext()) {
            throw new LFlowException('Container is not set. Please set the context first.');
        }
    }

    public static function isDebug(): bool
    {
        return !empty(self::$config['debug']) && self::$config['debug'] === true;
    }
}
