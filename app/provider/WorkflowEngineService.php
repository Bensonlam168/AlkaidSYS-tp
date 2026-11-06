<?php

declare(strict_types=1);

namespace app\provider;

use app\AppService;
use Infrastructure\Workflow\WorkflowEngineBootstrap;
use RuntimeException;
use think\facade\Config;
use think\Service;
use Throwable;

/**
 * 工作流引擎宿主装配服务
 *
 * ## 职责（只做接线，不含业务逻辑）
 *
 *   1. 把 {@see WorkflowEngineBootstrap} 登记进 ThinkPHP 容器，使控制器/服务层可以用
 *      `app(WorkflowEngineBootstrap::class)` 或构造注入拿到它；
 *   2. 按宿主配置 `workflow.engine.warmup` 决定是否在启动期预热引擎。
 *
 * ## 为什么「登记进容器」不等于「每请求重建」
 *
 * `think-swoole` 的 `Sandbox` 会按请求 **clone 一份 App（含容器）**，请求结束再
 * `clearInstances()` 丢弃。也就是说容器 instances 里的对象**只活一个请求**。
 * 而这里登记的是一个闭包，它每次都返回 `WorkflowEngineBootstrap::instance()` ——
 * 真正的单例在**静态属性**里（进程级），所以：
 *
 *   - 请求 1 解析 → 构造引擎（首次约 140ms）→ 静态单例持有；
 *   - 请求 2..N（含克隆出来的请求容器）→ 直接拿到同一个引擎对象，0.2ms 级。
 *
 * 这就是「只构造一次」的实现方式：不在容器里缓存对象，而在进程静态里缓存。
 *
 * ## 为什么默认**不**在 boot 期预热
 *
 * `Service::boot()` 在每个进程启动时都会执行（`php think <任意命令>` 也是），
 * 而引擎装配实测约 140ms（首次，主要是 PHP-DI 容器 + 反射 + 类加载）。
 * 为一个「控制器还是内存 Mock、尚未接入主流程」的子系统让全部 CLI 命令白付这笔钱，
 * 收益不成立；更糟的是「引擎配置有问题 ⇒ 整个应用起不来」。
 * 需要「启动期就失败」的部署把 `workflow.engine.warmup` 置为 true 即可（见配置文件说明）。
 *
 * @package app\provider
 */
class WorkflowEngineService extends Service
{
    /**
     * 注册期：把引导器登记为容器中的可解析项
     *
     * 只登记闭包、不解析它：解析时机会推迟到第一次真正使用，
     * 保证「没用到工作流的请求」不付任何装配成本。
     */
    public function register(): void
    {
        $this->app->bind(
            WorkflowEngineBootstrap::class,
            static fn (): WorkflowEngineBootstrap => WorkflowEngineBootstrap::instance()
        );
    }

    /**
     * 启动期：按配置决定是否预热引擎
     *
     * @throws RuntimeException 预热开启且引擎装配失败
     */
    public function boot(): void
    {
        if (!(bool) Config::get('workflow.engine.warmup', false)) {
            return;
        }

        try {
            WorkflowEngineBootstrap::instance()->engine();
        } catch (Throwable $e) {
            // R51：boot 期失败必须让命令行进程以非 0 退出。
            // 框架的全局异常处理器只渲染异常、不设置退出码，不先标记就会「打印异常但 exit 0」，
            // 部署脚本 `php think xxx || exit 1` 会把「引擎起不来」误判成成功。
            AppService::markBootFailed();

            throw new RuntimeException(
                '工作流引擎预热失败（workflow.engine.warmup = true）：' . $e->getMessage(),
                0,
                $e
            );
        }
    }
}
