<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;

/**
 * 仓储层「租户参数必须必填」的静态扫描守卫
 * Guard: tenant-scoped repository methods must not declare an optional tenant ID
 *
 * ## 为什么需要它
 *
 * `?int $tenantId = null` 是**fail-open** 的：调用方少传一个参数，条件 `where('tenant_id', ...)`
 * 就整段消失，查询/写入悄悄变成跨租户。它不会报错、不会告警，只会在某次重构「顺手少传一个参数」时
 * 静默放开租户边界（本次缺陷 2 就是这一类）。所以这里用静态扫描把它挡在提交前。
 *
 * 扫描范围：`infrastructure/**\/Repository/*.php`（不写死文件名，新增仓储自动纳入）。
 * 判定对象是**方法签名里的参数**，不是方法体 —— 契约层面的可空/默认值才是漏洞来源。
 *
 * ## 禁止的三种写法
 *
 *   - `?int $tenantId`              → 可空租户参数
 *   - `int $tenantId = 0`           → 「假必填」：默认值 0 仍会让调用方省略
 *   - `$tenantId = null`（任何类型） → 默认 null（含 `int|null`）
 *
 * 正确做法：`findById(int $id, int $tenantId)` —— 真正去掉 `?` 与默认值。
 * 平台级跨租户读确实需要「不过滤」时，**不要**用 null/0 当开关，而应新增显式命名方法
 * （如 `findByIdUnscoped()`）并在注释里写明「调用它即可被 grep 审计」。
 *
 * ## 豁免清单（EXEMPTIONS）
 *
 * 与 `scripts/check-migrations.php` 的 `ORPHAN_APPLIED_EXEMPTIONS` 同一套模式：
 * 每条豁免必须写 `reason` + `review_by`（YYYY-MM-DD），并列出具体方法名（按 `文件::方法` 粒度生效）。
 * 三种自净标签：
 *   - `[EXEMPT-META]`   元数据缺失/格式非法 → 写豁免的人的问题；
 *   - `[EXEMPT-EXPIRED]` 已过 review_by → 必须重新判断（收敛或续期并写明理由）；
 *   - `[EXEMPT-STALE]`  豁免指向的方法已经没有该问题 → 条目该删了（不允许留僵尸豁免）。
 */
final class TenantScopedRepositorySignatureGuardTest extends TestCase
{
    /**
     * 租户参数名（判定基准）
     */
    private const TENANT_PARAM = 'tenantId';

    /**
     * 禁止出现在租户参数上的写法 => 人类可读的原因
     *
     * 用列表而不是单个正则：三种写法的成因不同，报错要能直接指出是哪一种。
     *
     * @var array<string, string>
     */
    private const FORBIDDEN_PATTERNS = [
        '/\?\s*int\s+\$tenantId(?![A-Za-z0-9_])/' => '?int $tenantId（可空租户参数）',
        '/\bint\s+\$tenantId\s*=\s*(?:null|\d+)/' => 'int $tenantId 带默认值（假必填）',
        '/\$(?:tenantId)\s*=\s*null/' => '$tenantId 默认 null（省略即不过滤）',
    ];

    /**
     * 豁免清单：相对项目根的路径 => 元数据
     *
     * 目前**为空**：Lowcode 的四个仓储（CollectionRepository / FieldRepository /
     * RelationshipRepository / FormSchemaRepository）已全部改成租户参数必填，
     * 收敛包结束时同步删除了它们此前登记的四条过渡豁免。
     *
     * 以后确需登记过渡豁免时，务必写全 reason + review_by + methods；
     * 对应签名修好后必须删条目，否则 testExemptionsAreNotStale() 会报 [EXEMPT-STALE]。
     *
     * @var array<string, array{methods: array<int, string>, reason: string, review_by: string}>
     */
    private const EXEMPTIONS = [];

    /**
     * 主断言：仓储里不得再出现可选/带默认值的租户参数
     */
    public function testRepositoryTenantParameterIsMandatory(): void
    {
        $violations = $this->scanAllRepositories();
        $unexpected = [];

        foreach ($violations as $violation) {
            if ($this->isExempted($violation['file'], $violation['method'])) {
                continue;
            }

            $unexpected[] = sprintf(
                '%s::%s() 第 %d 行使用了 %s',
                $violation['file'],
                $violation['method'],
                $violation['line'],
                $violation['reason']
            );
        }

        $this->assertSame(
            [],
            $unexpected,
            "仓储的租户参数必须必填：\n  " . implode("\n  ", $unexpected)
            . "\n\n正确写法：findById(int \$id, int \$tenantId)（去掉 `?` 与默认值）。"
            . "\n平台级跨租户读请改用显式命名方法（如 findByIdUnscoped()）并在注释写明可被 grep 审计，"
            . "\n确需过渡的既有实现请登记到本文件的 EXEMPTIONS（含 reason 与 review_by）。"
        );
    }

    /**
     * 豁免元数据必须完整、可复核、未过期
     */
    public function testExemptionsHaveValidMetadataAndHaveNotExpired(): void
    {
        $problems = [];
        $today = new DateTimeImmutable('today');

        foreach (self::EXEMPTIONS as $file => $meta) {
            if (!is_array($meta)) {
                $problems[] = "[EXEMPT-META] {$file} 的豁免必须是数组（reason + review_by + methods）";
                continue;
            }

            $reason = $meta['reason'] ?? '';
            if (!is_string($reason) || trim($reason) === '') {
                $problems[] = "[EXEMPT-META] {$file} 缺少 reason（必须写明为什么可以暂时不满足）";
            }

            $methods = $meta['methods'] ?? [];
            if (!is_array($methods) || $methods === []) {
                $problems[] = "[EXEMPT-META] {$file} 的 methods 必须是非空数组（按 文件::方法 粒度豁免）";
            }

            $reviewBy = $meta['review_by'] ?? null;
            if (!is_string($reviewBy) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $reviewBy) !== 1) {
                $problems[] = "[EXEMPT-META] {$file} 的 review_by 缺失或格式非法（应为 YYYY-MM-DD）";
                continue;
            }

            $deadline = DateTimeImmutable::createFromFormat('!Y-m-d', $reviewBy);
            // createFromFormat 会把 2027-02-30 溢出成 2027-03-02，回写比对才能识别「不存在的日期」
            if ($deadline === false || $deadline->format('Y-m-d') !== $reviewBy) {
                $problems[] = "[EXEMPT-META] {$file} 的 review_by 不是合法日期：{$reviewBy}";
                continue;
            }

            if ($deadline < $today) {
                $problems[] = "[EXEMPT-EXPIRED] {$file} 的豁免已于 {$reviewBy} 到期："
                    . '要么把租户参数改成必填并删除本条，要么重新判断后续期并更新理由';
            }
        }

        $this->assertSame([], $problems, "豁免清单需要修正：\n  " . implode("\n  ", $problems));
    }

    /**
     * 反向检查：豁免条目必须仍然指向真实存在的问题（不允许留僵尸豁免）
     */
    public function testExemptionsAreNotStale(): void
    {
        $projectRoot = $this->projectRoot();
        $violations = $this->scanAllRepositories();
        $stillViolating = [];

        foreach ($violations as $violation) {
            $stillViolating[$violation['file']][$violation['method']] = true;
        }

        $problems = [];

        foreach (self::EXEMPTIONS as $file => $meta) {
            if (!is_file($projectRoot . '/' . $file)) {
                $problems[] = "[EXEMPT-STALE] {$file} 已不存在，请从 EXEMPTIONS 删除本条";
                continue;
            }

            $methods = is_array($meta['methods'] ?? null) ? $meta['methods'] : [];
            $hasViolation = false;
            foreach ($methods as $method) {
                if (isset($stillViolating[$file][$method])) {
                    $hasViolation = true;
                    break;
                }
            }

            if (!$hasViolation) {
                $problems[] = "[EXEMPT-STALE] {$file} 的方法已不再使用可空租户参数，"
                    . '说明问题已收敛，请从 EXEMPTIONS 删除本条（不允许留僵尸豁免）';
            }
        }

        $this->assertSame([], $problems, "豁免清单已失效：\n  " . implode("\n  ", $problems));
    }

    /**
     * 扫描器自检：确认它真的能识别违规写法（防止正则坏掉后「永远绿」）
     */
    public function testScannerDetectsForbiddenSignatures(): void
    {
        $sample = <<<'PHP'
<?php
class SampleRepository
{
    public function findById(int $id, ?int $tenantId = null): ?array
    {
        return null;
    }

    public function delete(int $id, int $tenantId = 0): bool
    {
        return false;
    }

    public function ok(int $id, int $tenantId): bool
    {
        return true;
    }
}
PHP;

        $violations = $this->scanContents('infrastructure/Fake/Repository/SampleRepository.php', $sample);
        $methods = array_column($violations, 'method');
        sort($methods);

        $this->assertSame(['delete', 'findById'], $methods);
    }

    /**
     * 返回项目根目录
     */
    private function projectRoot(): string
    {
        return dirname(__DIR__, 3);
    }

    /**
     * 扫描全部仓储文件
     *
     * @return array<int, array{file: string, method: string, line: int, reason: string}>
     */
    private function scanAllRepositories(): array
    {
        $projectRoot = $this->projectRoot();
        $baseDir = $projectRoot . '/infrastructure';
        $violations = [];
        $scannedFiles = 0;

        if (!is_dir($baseDir)) {
            $this->fail('找不到 infrastructure 目录，守卫无法工作：' . $baseDir);
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($baseDir, \FilesystemIterator::SKIP_DOTS)
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            if ($file->getPathInfo()->getFilename() !== 'Repository') {
                continue;
            }

            $relativePath = str_replace($projectRoot . '/', '', $file->getPathname());
            $contents = @file_get_contents($file->getPathname());
            if ($contents === false) {
                $this->fail('无法读取仓储文件：' . $relativePath);
            }

            $scannedFiles++;

            foreach ($this->scanContents($relativePath, $contents) as $violation) {
                $violations[] = $violation;
            }
        }

        // fail-closed：一个仓储都没扫到说明扫描逻辑失效，此时「没有违规」不能算通过
        $this->assertGreaterThan(
            0,
            $scannedFiles,
            '未扫描到任何仓储文件（infrastructure/**/Repository/*.php），守卫形同虚设'
        );

        return $violations;
    }

    /**
     * 扫描单个文件的全部方法签名
     *
     * @return array<int, array{file: string, method: string, line: int, reason: string}>
     */
    private function scanContents(string $relativePath, string $contents): array
    {
        $violations = [];

        foreach ($this->extractMethodSignatures($contents) as $signature) {
            foreach (self::FORBIDDEN_PATTERNS as $pattern => $reason) {
                if (preg_match($pattern, $signature['params']) === 1) {
                    $violations[] = [
                        'file' => $relativePath,
                        'method' => $signature['method'],
                        'line' => $signature['line'],
                        'reason' => $reason,
                    ];
                    break;
                }
            }
        }

        return $violations;
    }

    /**
     * 提取方法名、参数列表原文与签名所在行号
     *
     * 不用 `/function\s+\w+\s*\(([^)]*)\)/`：默认值里可能出现括号（如 `= []` 之外的表达式），
     * 只按「第一个右括号」截断会截错。这里用括号计数找到真正的收尾括号。
     *
     * @return array<int, array{method: string, params: string, line: int}>
     */
    private function extractMethodSignatures(string $contents): array
    {
        $signatures = [];

        if (preg_match_all('/function\s+([A-Za-z_][A-Za-z0-9_]*)\s*\(/', $contents, $matches, PREG_OFFSET_CAPTURE) === false) {
            return [];
        }

        foreach ($matches[1] as $index => $match) {
            $methodName = $match[0];
            $parenStart = $matches[0][$index][1] + strlen($matches[0][$index][0]) - 1;

            $depth = 0;
            $length = strlen($contents);
            $paramsStart = $parenStart + 1;
            $paramsEnd = null;

            for ($i = $parenStart; $i < $length; $i++) {
                $char = $contents[$i];
                if ($char === '(') {
                    $depth++;
                    continue;
                }
                if ($char === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $paramsEnd = $i;
                        break;
                    }
                }
            }

            if ($paramsEnd === null) {
                continue;
            }

            $signatures[] = [
                'method' => $methodName,
                'params' => substr($contents, $paramsStart, $paramsEnd - $paramsStart),
                'line' => substr_count(substr($contents, 0, $matches[0][$index][1]), "\n") + 1,
            ];
        }

        return $signatures;
    }

    /**
     * 判断某文件的某个方法是否被豁免
     */
    private function isExempted(string $relativePath, string $method): bool
    {
        $methods = self::EXEMPTIONS[$relativePath]['methods'] ?? [];

        return is_array($methods) && in_array($method, $methods, true);
    }
}
