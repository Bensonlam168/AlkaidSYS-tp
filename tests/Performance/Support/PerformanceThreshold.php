<?php

declare(strict_types=1);

namespace Tests\Performance\Support;

/**
 * 性能阈值读取器
 * Performance Threshold Reader
 *
 * 性能套件里所有「墙钟时间 / 吞吐 / 命中率」阈值都走这里：
 * **环境变量可配 + 代码里只写默认值**。
 *
 * 取值规则（与 CasbinControllerTest 的 CASBIN_RELOAD_BUDGET_MS 保持一致）：
 *   1. 变量不存在 / 为空串 / 非数字 / 小于等于 0 → **回落默认值**；
 *   2. 变量合法 → trim 后转成数值使用。
 *
 * 为什么非法值要回落默认值、而不是「当作无上限」：
 *   把变量名拼错、或者在 CI 里写成空串时，若预算被解释成「没有上限」，
 *   这条断言就永久静默失效 —— 套件永远是绿的，比红更糟。
 *   回落默认值至少保留原有的约束强度，并且默认值本身会随断言消息打印出来，
 *   一眼能看出「这次用的到底是环境变量还是默认值」。
 *
 * @package Tests\Performance\Support
 */
final class PerformanceThreshold
{
    /**
     * 读取浮点型阈值
     *
     * @param string $envName 环境变量名 | Environment variable name
     * @param float  $default 变量缺失或非法时的默认值 | Default when missing/invalid
     * @return float 生效阈值 | Effective threshold
     */
    public static function readFloat(string $envName, float $default): float
    {
        $raw = getenv($envName);

        // 变量不存在：直接用默认值（不写 warning，正常路径）
        if ($raw === false) {
            return $default;
        }

        $raw = trim((string) $raw);

        // 空串 / 非数字：拼错或写空的典型形态，回落默认值而不是「关掉预算」
        if ($raw === '' || !is_numeric($raw)) {
            return $default;
        }

        $value = (float) $raw;

        // 0 与负数对「上限 / 下限」都没有语义（只可能来自误配），同样回落默认值
        return $value > 0 ? $value : $default;
    }

    /**
     * 读取整数型阈值（如命中率百分比、最小 QPS）
     *
     * 复用 readFloat 的取值规则，最后四舍五入到整数。
     *
     * @param string $envName 环境变量名 | Environment variable name
     * @param int    $default 变量缺失或非法时的默认值 | Default when missing/invalid
     * @return int 生效阈值 | Effective threshold
     */
    public static function readInt(string $envName, int $default): int
    {
        return (int) round(self::readFloat($envName, (float) $default));
    }
}
