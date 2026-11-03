<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use app\command\EnvCheckCommand;
use app\support\EnvRequirementCatalog;
use PHPUnit\Framework\TestCase;

/**
 * 「生产必需项」单一真相源的一致性测试 | Catalog is the single source of truth
 *
 * ## 这条用例钉住什么
 *
 * 生产要求已从 `EnvCheckCommand` 提到 `app\support\EnvRequirementCatalog`，两个消费者
 * （`php think env:check` 与 `php scripts/check-env-production.php`）共用同一份。
 * 「共用」这个说法必须被测试钉住，否则它只是一句注释：
 *
 *   1. **键集合不能只在一边增长**：只往 catalog 里加键（忘了给命令补分类/描述）时，
 *      那个键不会出现在 `env:check` 的输出里 —— 命令自己不会报错，只有一条用例能发现。
 *      反之亦然（命令里新加一个「生产必需」的键却没进 catalog，预检脚本就永远不检查它）。
 *   2. **规则必须真的合并进命令的定义表**：`production_rules` / `production_hint`
 *      逐键相等，才能证明命令的输出文案来自 catalog 而不是残留的旧副本。
 *   3. **规则名必须都实现了**：写错一个（`min_lenght:32`）在运行期表现为「永远通过」
 *      （`isKnownRule()` 返回 false 才会报错，但那条报错在非生产环境不阻断），
 *      所以必须在测试阶段就拦住。
 *   4. **范围不能悄悄扩大**：8 个键的清单被显式写死 —— 「顺手多加几个必需项」
 *      会让合理的部署凭空变红，而这属于显式决策，不该随实现漂移。
 *
 * 为什么用普通 `TestCase`：本用例只读静态规则表与一个不依赖容器的 protected 方法，
 * 不需要数据库、不需要 App，跑得越快越可能在每次提交前被真的执行。
 *
 * @package Tests\Unit\Command
 */
class EnvRequirementCatalogConsistencyTest extends TestCase
{
    /**
     * catalog 里**必须恰好**是这 8 个键（顺序也是输出顺序，因此一起钉住）
     *
     * 判定标准（三条同时成立才纳入）：① 配错不会让进程启动失败；
     * ② 故障出现在第一个业务请求或第一次鉴权；③ 有唯一正确的「可判定」取值形态。
     *
     * @var array<int, string>
     */
    private const EXPECTED_KEYS = [
        'APP_ENV',
        'APP_DEBUG',
        'DEFAULT_TENANT_ID',
        'DB_HOST',
        'DB_NAME',
        'DB_USER',
        'DB_PASS',
        'JWT_SECRET',
    ];

    /**
     * 用例 1：键集合与顺序被钉死（防止「顺手扩大范围」与「静默删项」）
     */
    public function testCatalogCoversExactlyTheAgreedKeys(): void
    {
        $this->assertSame(
            self::EXPECTED_KEYS,
            EnvRequirementCatalog::names(),
            '生产必需项的清单属显式决策：改它必须同步改本用例与 '
            . 'docs/technical-specs/deployment/environment-validation.zh-CN.md'
        );
    }

    /**
     * 用例 2：命令的定义表包含了 catalog 里的每一个键
     *
     * 漏一个的后果：该键不会出现在 `env:check` 的输出里（没有分类 → 不参与逐个检查），
     * 而命令自己**不会**报错 —— 也就是「规则写了但从不执行」。
     */
    public function testCommandDefinitionsCoverEveryCatalogKey(): void
    {
        $definitions = $this->commandDefinitions();

        $missing = array_values(array_diff(EnvRequirementCatalog::names(), array_keys($definitions)));

        $this->assertSame(
            [],
            $missing,
            'catalog 里这些键在 EnvCheckCommand 的定义表里不存在（不会有分类/描述 → 不会出现在输出里）：'
            . implode(', ', $missing)
            . "\n请给它们补上 category / description，或从 catalog 里删除。"
        );
    }

    /**
     * 用例 3：命令的定义表**没有**擅自新增 catalog 之外的生产规则
     *
     * 反向漂移：命令里写死 `production_rules`（而不是从 catalog 合并）时，
     * 预检脚本看不到这条规则 —— 两边判定不一致，且只有一边会红。
     */
    public function testCommandHasNoProductionRulesOutsideCatalog(): void
    {
        $definitions = $this->commandDefinitions();
        $catalogKeys = EnvRequirementCatalog::names();

        foreach ($definitions as $name => $definition) {
            $hasRules = isset($definition['production_rules']) && $definition['production_rules'] !== [];

            if (!$hasRules) {
                continue;
            }

            $this->assertContains(
                $name,
                $catalogKeys,
                "{$name} 在 EnvCheckCommand 里声明了 production_rules，但不在 EnvRequirementCatalog 中："
                . '规则必须只存在于 catalog（否则 check-env-production.php 看不到它）'
            );
        }
    }

    /**
     * 用例 4：合并是**真的**发生了（逐键比对规则与提示文案）
     */
    public function testMergedRulesAndHintsMatchCatalog(): void
    {
        $definitions = $this->commandDefinitions();

        foreach (EnvRequirementCatalog::requirements() as $name => $requirement) {
            $this->assertArrayHasKey($name, $definitions, "命令定义表缺少 {$name}");

            $this->assertSame(
                $requirement['production_rules'],
                $definitions[$name]['production_rules'] ?? null,
                "{$name} 的 production_rules 与 catalog 不一致：命令里的规则必须是合并来的，不能自己写一份"
            );

            $this->assertSame(
                $requirement['production_hint'],
                $definitions[$name]['production_hint'] ?? null,
                "{$name} 的 production_hint 与 catalog 不一致"
            );

            $this->assertTrue(
                (bool) ($definitions[$name]['production_required'] ?? false),
                "{$name} 的 production_required 应为 true（生产类环境必须显式配置）"
            );
        }
    }

    /**
     * 用例 5：catalog 里声明的每个规则名都已实现
     *
     * 写错规则名（如 `min_lenght:32`）在运行期的表现是「这条规则永远通过」——
     * 门禁最典型的静默失效方式。必须在这里拦住。
     */
    public function testEveryDeclaredRuleIsImplemented(): void
    {
        foreach (EnvRequirementCatalog::requirements() as $name => $requirement) {
            foreach (array_keys($requirement['production_rules']) as $rule) {
                $this->assertTrue(
                    EnvRequirementCatalog::isKnownRule((string) $rule),
                    "{$name} 声明了未实现的规则「{$rule}」（拼写错误会让它永远通过）"
                );
            }
        }
    }

    /**
     * 用例 6：`APP_ENV` 的生产类取值清单与运行期判定同源
     *
     * `config/cache.php` / `CacheEnvironmentGuardService` / `SessionEnvironmentGuardService`
     * 都按 production / prod / stage / staging 判定；`isProductionEnv()` 必须与之一致，
     * 否则会出现「cache 已强制 redis，但预检认为不是生产」这类错位。
     */
    public function testProductionEnvDetectionMatchesGuardServices(): void
    {
        foreach (['production', 'prod', 'stage', 'staging', 'Production', ' STAGING '] as $value) {
            $this->assertTrue(EnvRequirementCatalog::isProductionEnv($value), "「{$value}」应判为生产类环境");
        }

        foreach (['local', 'dev', 'development', 'testing', 'qa', '', null, false] as $value) {
            $this->assertFalse(
                EnvRequirementCatalog::isProductionEnv($value),
                '「' . var_export($value, true) . '」不应判为生产类环境'
            );
        }
    }

    /**
     * 用例 7：脱敏规则（前 2 位 + 长度）对敏感键生效、对非敏感键不生效
     *
     * 这个规则被 `env:check` 与预检脚本共用，输出会进 CI / 发布日志 —— 退化成明文即泄露。
     */
    public function testDescribeValueMasksSensitiveKeysOnly(): void
    {
        $this->assertSame(
            '"ro"***（已脱敏：共 4 字符）',
            EnvRequirementCatalog::describeValue('DB_PASS', 'root'),
            '敏感键必须只暴露前 2 位 + 长度'
        );
        $this->assertSame(
            '"ab"***（已脱敏：共 3 字符）',
            EnvRequirementCatalog::describeValue('JWT_SECRET', 'abc')
        );
        $this->assertSame(
            '"1"',
            EnvRequirementCatalog::describeValue('DEFAULT_TENANT_ID', '1'),
            '非敏感键应全量显示（便于定位配置错误）'
        );
        $this->assertSame(
            '(未设置 / 空值)',
            EnvRequirementCatalog::describeValue('DB_HOST', null)
        );
    }

    /**
     * 取 `EnvCheckCommand` 加载后的定义表
     *
     * `$envDefinitions` 与 `loadEnvDefinitions()` 都是 protected（它们属于实现细节），
     * 这里用匿名子类开一个只读窗口，而不是反射：意图更直白，也不需要处理可见性报错。
     *
     * @return array<string, array<string, mixed>>
     */
    private function commandDefinitions(): array
    {
        $command = new class () extends EnvCheckCommand {
            /**
             * 只读取，不产生任何副作用（loadEnvDefinitions 是纯数据组装）
             *
             * @return array<string, array<string, mixed>>
             */
            public function definitionsForAssertion(): array
            {
                $this->loadEnvDefinitions();

                return $this->envDefinitions;
            }
        };

        return $command->definitionsForAssertion();
    }
}
