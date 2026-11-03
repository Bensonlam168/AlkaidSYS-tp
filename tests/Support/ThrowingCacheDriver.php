<?php

declare(strict_types=1);

namespace Tests\Support;

use think\cache\Driver;

/**
 * 「读取必抛 \TypeError」的缓存驱动（测试替身）| Cache driver whose reads always throw \TypeError
 *
 * ## 为什么是「真实驱动」而不是 mock 静态 `Cache` 门面
 *
 * `CasbinService` 里的缓存调用是 `Cache::get()`（静态门面）。门面的调用链是
 * `think\facade\Cache` → `think\Cache`（管理器）→ 当前 store 的驱动实例。
 * 把**驱动**换成这个类，等于让真实调用链上的最后一步抛错 —— 与生产上
 * 「Redis 不可用 / 连接池已释放」时的形态一致；而 mock 门面只能证明「try/catch 写在那一行」，
 * 证明不了「真实调用链抛的东西会被接住」。
 *
 * ## 为什么抛 `\TypeError` 而不是 `\Exception`
 *
 * `\Error`（含 `\TypeError`）**不继承** `\Exception`。这正是历史上 `catch (\Exception)`
 * 漏接、把缓存故障升级成 HTTP 500 的那一类（见 R3/R4）。用 `\TypeError` 做替身，
 * 能同时锁定「必须捕 `\Throwable`」这条边界。
 *
 * ## 为什么所有方法都抛（而不是只有 get()）
 *
 * 替身要模拟的是「缓存整体不可用」：真实 Redis 挂掉时，读、写、删、清全部失败。
 * 只让 `get()` 抛，会让「写失败/清缓存失败」仍然走正常路径，等于把最危险的组合
 * （读失败 → 触发重载 → 重载里写时间戳/清缓存也失败）漏掉。
 *
 * ## 用法（见 CasbinShouldReloadTest）
 *
 * ```php
 * Config::set(['cache.stores.r6_broken' => ['type' => ThrowingCacheDriver::class]]);
 * Config::set(['cache.default' => 'r6_broken']);
 * ```
 *
 * 只在本进程内生效：用完必须还原 `cache.default` 并 `forgetDriver()`，
 * 否则后续用例会拿到这个「一读就炸」的驱动。
 *
 * @package Tests\Support
 */
class ThrowingCacheDriver extends Driver
{
    /**
     * 构造函数
     *
     * 注意：父类 `think\cache\Driver` **没有**构造函数（`$options` 由各驱动自行保存），
     * 所以这里**不能**写 `parent::__construct($options)` —— 那会抛
     * `Error: Cannot call constructor`（构造期就把「故障」变成另一处故障，容易误判）。
     *
     * 框架通过 `Container::invokeClass($class, [$storeConfig])` 按顺序注入 store 配置，
     * 这里原样存进父类的 `$options`（`getCacheKey()` 会用到 prefix），本替身并不读取它们。
     *
     * @param array $options store 配置（`cache.stores.<name>`）
     */
    public function __construct(array $options = [])
    {
        $this->options = $options;
    }

    /**
     * 读取缓存：必定抛 `\TypeError`
     *
     * @param string $name 缓存键 | Cache key
     * @param mixed $default 默认值 | Default value
     * @return mixed
     */
    public function get($name, $default = null): mixed
    {
        throw new \TypeError('模拟缓存读取故障：Redis 不可用（\TypeError，非 \Exception）');
    }

    /**
     * 判断缓存是否存在：必定抛 `\TypeError`
     *
     * @param string $name 缓存键 | Cache key
     * @return bool
     */
    public function has($name): bool
    {
        throw new \TypeError('模拟缓存存在性判断故障：Redis 不可用');
    }

    /**
     * 写入缓存：必定抛 `\TypeError`
     *
     * @param string $name 缓存键 | Cache key
     * @param mixed $value 缓存值 | Cache value
     * @param mixed $expire 有效期 | Expire
     * @return bool
     */
    public function set($name, $value, $expire = null): bool
    {
        throw new \TypeError('模拟缓存写入故障：Redis 不可用');
    }

    /**
     * 删除缓存：必定抛 `\TypeError`
     *
     * @param string $name 缓存键 | Cache key
     * @return bool
     */
    public function delete($name): bool
    {
        throw new \TypeError('模拟缓存删除故障：Redis 不可用');
    }

    /**
     * 清空缓存：必定抛 `\TypeError`
     *
     * @return bool
     */
    public function clear(): bool
    {
        throw new \TypeError('模拟缓存清空故障：Redis 不可用');
    }

    /**
     * 自增：必定抛 `\TypeError`
     *
     * @param string $name 缓存键 | Cache key
     * @param int $step 步长 | Step
     * @return false|int
     */
    public function inc($name, $step = 1)
    {
        throw new \TypeError('模拟缓存自增故障：Redis 不可用');
    }

    /**
     * 自减：必定抛 `\TypeError`
     *
     * @param string $name 缓存键 | Cache key
     * @param int $step 步长 | Step
     * @return false|int
     */
    public function dec($name, $step = 1)
    {
        throw new \TypeError('模拟缓存自减故障：Redis 不可用');
    }

    /**
     * 清除标签：必定抛 `\TypeError`
     *
     * @param array $keys 标签列表 | Tag keys
     * @return void
     */
    public function clearTag($keys): void
    {
        throw new \TypeError('模拟缓存标签清除故障：Redis 不可用');
    }
}
