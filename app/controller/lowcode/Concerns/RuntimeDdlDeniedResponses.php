<?php

declare(strict_types=1);

namespace app\controller\lowcode\Concerns;

use app\constant\ErrorCode;
use think\exception\HttpResponseException;
use think\Response;

/**
 * 低代码控制器「运行时 DDL 被拒」统一响应 | Shared runtime-DDL denial responses
 *
 * ## 为什么需要这个 trait（缺陷 B 的复制粘贴风险）
 *
 * `Infrastructure\Schema\SchemaBuilder::assertCanRunDDL()` 在运行环境策略不允许运行时 DDL 时
 * 抛 `think\exception\HttpResponseException`。该异常**只携带一个已构造好的 Response**：
 *
 * ```php
 * class HttpResponseException extends \RuntimeException {
 *     public function __construct(protected Response $response) {}   // 没有调用 parent::__construct()
 * }
 * ```
 *
 * 实测（PHP 8.2 / topthink/framework 8.x）：
 *   - 继承链 `HttpResponseException -> RuntimeException -> Exception`，`instanceof \Exception` 为 **true**
 *     ⇒ 控制器的 `catch (\Exception $e)` **抓得到**它，不会变成 500；
 *   - 但因为构造函数没有调用父类，`\Exception::$message` 保持默认值 ⇒ `getMessage()` 恒为 **空串**，
 *     `getCode()` 恒为 **0**。
 *
 * 于是 `catch (\Exception $e) { return $this->error($e->getMessage()); }` 把
 * 「HTTP 403 + 业务码 5003 + 明确的 policy reason」降级成「HTTP 400 + 空 message」，
 * 调用方既看不出为什么失败，也拿不到下一步该做什么。**异常抓到了，但信息全丢了。**
 *
 * ## 为什么要提取成 trait 而不是每个控制器各写一份
 *
 * 撞这道闸门的动作有五类（建集合 `create_table`、删集合 `drop_table`、加字段 `add_column`、
 * 删字段 `drop_column`、建/删关系中间表 `create_table`/`drop_table`），分散在
 * `CollectionController` / `FieldController` / `RelationshipController` 三个控制器里。
 * 逐份复制 `runtimeDdlDeniedResponse()` 的后果是：**文案与提示只会在被改的那一份里更新**，
 * 另外几份悄悄退化成「提示过时」——上一轮修 `CollectionController` 时，同类缺陷已经在
 * `FieldController` / `RelationshipController` 上原样存活着。
 *
 * 因此把三件事收敛到一处：
 *   - `runtimeDdlDeniedResponse()` —— HttpResponseException → 可读的 403/5003 响应；
 *   - `runtimeDdlDeniedHint()`     —— 按 reason 码给出「改哪个键」的可操作提示；
 *   - `describeExceptionMessage()` —— 普通异常的空 message 兜底（回落到异常类名）。
 *
 * ## 对外契约（不得因为重构而改变）
 *
 *   - HTTP 状态码沿用原响应的 403（取不到时回落到 403），业务码沿用 5003；
 *   - message = 「异常类型 + operation + reason + 可操作提示」，**非空**；
 *   - **只**暴露策略原因（`env` / `operation` / `reason`），不拼接堆栈（`#0`）、不拼接 SQL、
 *     不带绝对路径 —— 生产环境即便走到这里也不泄露实现细节。
 *
 * ## 使用方（当前 3 个控制器）
 *
 *   - `app\controller\lowcode\CollectionController`（save / update / delete）
 *   - `app\controller\lowcode\FieldController`（save / delete）
 *   - `app\controller\lowcode\RelationshipController`（save / delete）
 *
 * @package app\controller\lowcode\Concerns
 */
trait RuntimeDdlDeniedResponses
{
    /**
     * 运行时 DDL 被环境策略拒绝时的对外响应 | Response when runtime DDL is denied
     *
     * 处理原则：
     *   - **保留**原响应的 HTTP 状态码（403）与业务码（5003），不改变既有契约；
     *   - message 换成「异常类型 + 拦截原因 + 可操作提示」，让调用方自己就能修好配置；
     *   - **只**暴露策略原因（`env` / `operation` / `reason`），不拼接堆栈、不拼接 SQL。
     *
     * @param HttpResponseException $e 由 SchemaBuilder 抛出的响应异常
     * @return Response
     */
    protected function runtimeDdlDeniedResponse(HttpResponseException $e): Response
    {
        $origin = $e->getResponse();
        $body = $origin->getData();

        // 原响应的状态码/业务码优先；取不到时回落到 403 + 2002（权限不足）——
        // 语义上「当前环境不允许这个操作」属于「禁止」，不是「参数错误」。
        $status = $origin->getCode() > 0 ? $origin->getCode() : 403;
        $code = is_array($body) && isset($body['code']) ? (int) $body['code'] : ErrorCode::FORBIDDEN;
        $reason = is_array($body) ? (string) ($body['data']['reason'] ?? '') : '';
        $operation = is_array($body) ? (string) ($body['data']['operation'] ?? 'runtime_ddl') : 'runtime_ddl';

        $message = sprintf(
            '运行时 DDL 被环境策略拒绝（异常类型=%s, operation=%s, reason=%s）。%s',
            get_class($e),
            $operation === '' ? 'unknown' : $operation,
            $reason === '' ? 'unknown' : $reason,
            $this->runtimeDdlDeniedHint($reason)
        );

        return $this->error($message, $code, [], $status);
    }

    /**
     * 按拦截原因给出可操作提示 | Actionable hint per denial reason
     *
     * 原因码与 `SchemaBuilder::assertCanRunDDL()` 一一对应，新增原因时须同步这里，
     * 否则会落到 default 分支（仍可读，但少一条具体指引）。
     *
     * @param string $reason 拦截原因码
     * @return string
     */
    protected function runtimeDdlDeniedHint(string $reason): string
    {
        return match ($reason) {
            'production_environment' => '生产类环境永久禁止运行时 DDL，与 ALLOW_RUNTIME_DDL 取值无关，请改用迁移文件执行建表/删表。',
            'env_not_in_whitelist' => 'APP_ENV 必须属于白名单 dev / local / testing（白名单由 SCHEMA_RUNTIME_DDL_ALLOWED_ENVS 配置）。',
            'flag_disabled' => '请在非生产环境的 .env 中设置 ALLOW_RUNTIME_DDL = true 后重试（仅限 dev / local / testing）。',
            default => '请检查 APP_ENV 与 ALLOW_RUNTIME_DDL 配置；生产类环境请改用迁移文件。',
        };
    }

    /**
     * 取出异常的可读消息 | Readable message of an exception
     *
     * 保留原有行为：非 DDL 策略类异常仍回传自身 message（调用方排障依赖它）。
     * 唯一改动是 message 为空时回落到类名 —— 空白 message 等于把「错误」变成「无原因的错误」，
     * 而类名足以让调用方定位到异常类型，且不含堆栈与 SQL。
     *
     * @param \Exception $e 原始异常
     * @return string
     */
    protected function describeExceptionMessage(\Exception $e): string
    {
        $message = trim($e->getMessage());

        return $message !== '' ? $message : get_class($e);
    }
}
