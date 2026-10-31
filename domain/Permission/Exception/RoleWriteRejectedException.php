<?php

declare(strict_types=1);

namespace Domain\Permission\Exception;

use RuntimeException;

/**
 * 角色写入被拒绝 | Role write rejected
 *
 * 触发场景（RoleRepository::save() 的更新分支）：
 *   1. 目标角色属于**其他租户** —— 跨租户改写，必须失败而不是静默当作成功；
 *   2. 目标角色根本不存在 —— 更新一个不存在的 id 同样不应被当成「成功」。
 *
 * 为什么单独建异常类而不是复用 InvalidArgumentException：
 * 调用方（控制器 / 未来其它写入路径）需要**只**针对「租户边界拒绝」这一件事做捕获，
 * 而不是把参数校验错误一起吞掉。
 *
 * 为什么用异常而不是返回 false：
 * save() 的返回类型是 `int`（角色 ID），返回 0 或负数会被误当作合法 id；
 * 让越权写入「抛出来」才能保证不可能被上层忽略。
 *
 * @package Domain\Permission\Exception
 */
class RoleWriteRejectedException extends RuntimeException
{
}
