<?php

declare(strict_types=1);

namespace app\controller;

use app\BaseController;
use think\exception\HttpException;

/**
 * 框架自带演示控制器 | Framework demo controller
 *
 * 两个动作都属于 ThinkPHP 骨架的示例代码，**没有任何业务价值**：
 *   - `index()` —— 直接输出一张指向 thinkphp.cn 的欢迎页 iframe，并把框架版本号拼进 URL；
 *   - `hello($name)` —— 回显路径参数。
 *
 * ## 为什么生产环境必须在**控制器**这一层关掉（而不是只删路由）
 *
 * `config/route.php` 的 `url_route_must = false`：没有任何路由规则命中时，框架会退化为
 * 「控制器/方法」自动解析。也就是说 `route/app.php` 里不注册 `/hello/:name` 之后，
 * `GET /index/hello` 依然会进到本类 —— 只删路由等于「把门牌摘了，门还开着」。
 * 生产环境真正的两个问题点因此是：
 *   1. `GET /index` 把 `版本号` 明文写在 HTML 里（对外泄露框架与版本，属可被利用的指纹信息）；
 *   2. `GET /index/hello` 是任何人都能触发的无意义回显入口。
 *
 * 结论：本类在 `APP_ENV ∈ {production, prod, stage, staging}` 时整体按 404 处理（fail-closed），
 * 与 `route/app.php` 里「演示路由仅非生产环境注册」形成两道门：
 *   - 路由层：生产环境不注册 `/think`、`/hello/:name`；
 *   - 控制器层：即使有人绕过路由（自动解析、内部 `controller()` 调用），仍然 404。
 *
 * 为什么放在 `initialize()`（构造函数）而不是每个动作里：新增动作时不需要再记得补一行，
 * 「忘记写守卫」这类失误不会随代码演进悄悄回归。
 *
 * @package app/controller
 */
class Index extends BaseController
{
    /**
     * 生产类环境清单（与 `route/app.php` 保持同一份判定）
     *
     * @var list<string>
     */
    private const PROD_LIKE_ENVS = ['production', 'prod', 'stage', 'staging'];

    /**
     * 控制器初始化：生产环境整体禁用
     *
     * @throws HttpException 生产类环境下抛 404（由 `app\ExceptionHandle` 渲染为统一响应结构）
     */
    protected function initialize(): void
    {
        $env = strtolower((string) env('APP_ENV', 'production'));

        if (in_array($env, self::PROD_LIKE_ENVS, true)) {
            // 用和其它「资源不存在」一致的 404，不额外说明「这是演示控制器」——
            // 对外多解释一句，就等于多告诉攻击者一句「这里本来有个入口」。
            throw new HttpException(404, 'Not Found');
        }
    }

    /**
     * 演示首页（仅非生产环境）：框架欢迎页
     *
     * @return string HTML
     */
    public function index()
    {
        return '<style>*{ padding: 0; margin: 0; }</style><iframe src="https://www.thinkphp.cn/welcome?version=' . \think\facade\App::version() . '" width="100%" height="100%" frameborder="0" scrolling="auto"></iframe>';
    }

    /**
     * 演示问候（仅非生产环境）：回显路径参数
     *
     * @param string $name 名字
     * @return string
     */
    public function hello($name = 'ThinkPHP8')
    {
        return 'hello,' . $name;
    }
}
