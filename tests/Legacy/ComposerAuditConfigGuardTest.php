<?php

declare(strict_types=1);

namespace Tests\Legacy;

use PHPUnit\Framework\TestCase;

/**
 * 守卫：`composer.json` 里「依赖公告（composer audit）」这套配置不得退化。
 *
 * 为什么需要它：CI 的 `Dependency Audit` 门禁（`.github/workflows/backend-php-cs-fixer.yml`
 * 的 `jobs.dependency-audit`，见 AGENTS.md 门禁表）只回答一个问题 ——
 * 「**此刻**的 `composer.lock` 里有没有未豁免的公告」。它管不了另一类更隐蔽的退化：
 * 为了让门禁变绿，有人把 `config.audit.block-insecure` 关掉，或者塞进一条
 * **没有理由 / 没有到期日**的豁免。这两种改法都能让 `composer audit` 回到 exit 0，
 * 但门禁守的东西已经被拆掉了 —— 而且拆的时候 CI 全绿。
 *
 * 断言分组（失败信息里的前缀与之一一对应）：
 *   C1 `config.audit.block-insecure` 必须是布尔 `true`（不是字符串 `"true"`，也不是 1）
 *   C2 `config.audit.ignore` 的每个 ID 都必须在 `extra.audit-rationale` 里有一条**非空**理由
 *   C3 每个豁免都必须在 `extra.audit-review-by` 里有到期日：`YYYY-MM-DD` 且**未过期**
 *   C4 反向检查：`extra.audit-rationale` / `extra.audit-review-by` 不得留 `ignore` 之外的孤儿条目
 *
 * 约束（与同目录 `AgentsDocFactsGuardTest` 一致）：
 *   - **只读**：只 `file_get_contents` + `json_decode` 仓库里的 `composer.json`，
 *     不写文件、不连库、不调外部命令、不访问网络 —— `composer audit` 需要 packagist，
 *     那是 CI 门禁（`Dependency Audit`）的职责，本守卫只校验「配置本身有没有被改坏」；
 *   - 「今天」取 `new DateTimeImmutable('today')` 的 `Y-m-d`（容器与 runner 都是 UTC），
 *     比较用零填充日期的字符串序（`'2027-03-31' < '2027-04-01'` 即为真，与时区无关）；
 *     口径是「**到期日当天仍然有效**，过了到期日才红」；
 *   - 认不出的形态一律报违规并要求人工确认，**不做猜测、不放宽成「非空即通过」**。
 */
final class ComposerAuditConfigGuardTest extends TestCase
{
    /** composer.json（相对仓库根） */
    private const COMPOSER_JSON = 'composer.json';

    /** 到期日唯一合法格式（`!` 前缀让未指定的字段归零，避免带着今天的时分秒参与比较） */
    private const REVIEW_BY_FORMAT = '!Y-m-d';

    // -----------------------------------------------------------------------
    // C1：config.audit.block-insecure 必须是 true
    // -----------------------------------------------------------------------

    /**
     * C1：`config.audit.block-insecure` 必须是布尔 `true`。
     *
     * 这个开关的语义是「`composer install` 遇到不安全依赖是否直接失败」。它被关掉之后，
     * `Dependency Audit` 门禁**照样绿**（门禁只比对公告清单，看不见这个开关），
     * 于是「不安全依赖进不了流水线」这层保护就静默消失了。
     *
     * 为什么坚持布尔而不是「truthy」：Composer 的 schema 把这一项声明为 boolean，
     * `"true"` / `"1"` / `1` 这类写法要么被 `composer validate` 判为不符合 schema，
     * 要么能在 JSON 里通过却与「开关已打开」不等价 —— 都属于「看起来开了」，
     * 而本守卫的职责正是把这类「看起来」变成一条明确的红。
     */
    public function testC1BlockInsecureMustBeTrue(): void
    {
        $json = self::composerJson();
        $violations = [];

        $audit = $json['config']['audit'] ?? null;
        if (!is_array($audit)) {
            $violations[] = sprintf(
                'C1 config.audit 不存在或不是对象（实际是 %s）—— 整个 audit 配置被删掉后，'
                . '`block-insecure` 与 `ignore` 都回到默认值，`Dependency Audit` 门禁拦不住带公告的 lock。'
                . '请恢复 composer.json 的 config.audit 段。',
                self::describe($audit)
            );
        } else {
            $value = $audit['block-insecure'] ?? null;
            if ($value !== true) {
                $violations[] = sprintf(
                    'C1 config.audit.block-insecure 必须是布尔 true，实际是 %s —— '
                    . '关掉它（或写成字符串 "true" / 数字 1）等于把「不安全依赖直接失败」这条总闸关小：'
                    . 'CI 的 Dependency Audit 门禁只比对公告清单，**看不见**这个开关被改过，'
                    . '于是门禁仍然绿，而它本该拦住的东西已经能进流水线了。'
                    . '改哪里：把 composer.json 的 config.audit.block-insecure 改回 true（布尔），不要改本测试。',
                    self::describe($value)
                );
            }
        }

        self::assertSame([], $violations, self::summarize('C1', $violations));
    }

    // -----------------------------------------------------------------------
    // C2：每个豁免都必须有非空理由
    // -----------------------------------------------------------------------

    /**
     * C2：`config.audit.ignore` 里的每一个公告 ID 都必须在 `extra.audit-rationale`
     * 里有对应条目，且理由 trim 之后非空。
     *
     * 没有理由的豁免等于「把公告静音」，下一个人看到 exit 0 会以为依赖是干净的。
     */
    public function testC2EveryExemptionHasRationale(): void
    {
        $json = self::composerJson();
        $exemptions = self::exemptions($json);
        $rationales = self::stringMap($json, 'audit-rationale', 'C2');
        $violations = [];

        foreach ($exemptions as $exemption) {
            $id = $exemption['id'];
            if (!array_key_exists($id, $rationales)) {
                $violations[] = sprintf(
                    'C2 豁免「%s」在 extra.audit-rationale 里没有对应条目 —— '
                    . '每条 config.audit.ignore 都必须写清「为什么可以暂留、影响面多大、什么时候能消掉」，'
                    . '否则它就是一条静音开关。改哪里：在 composer.json 的 extra.audit-rationale 里补上 "%s": "理由…"。',
                    $id,
                    $id
                );
                continue;
            }

            if (trim($rationales[$id]) === '') {
                $violations[] = sprintf(
                    'C2 豁免「%s」在 extra.audit-rationale 里的理由是空的（只有空白字符）—— '
                    . '空理由与没写等价，导出到 GitHub 上只会显示一行空白。'
                    . '改哪里：补上非空的理由，或删掉该豁免（删掉后 `Dependency Audit` 会自己变红提醒你升级）。',
                    $id
                );
            }
        }

        self::assertSame([], $violations, self::summarize('C2', $violations));
    }

    // -----------------------------------------------------------------------
    // C3：豁免必须有到期日，过期即红
    // -----------------------------------------------------------------------

    /**
     * C3：每个豁免在 `extra.audit-review-by` 里都必须有到期日，格式 `YYYY-MM-DD`，且未过期。
     *
     * 这条兑现评审报告里「豁免没有到期日」的遗留：只写理由的豁免会一直躺在仓库里 ——
     * 当初写理由的人已经离职、依赖早就能升级了，却没有任何东西会提醒任何人。
     * 到期日的作用不是「到期自动解除豁免」，而是**到期就把门禁变红**，
     * 逼人显式表态「继续留（更新到期日）」或「消掉它（删豁免）」，两种都必须留下 diff。
     *
     * 口径：到期日**当天**仍然有效，`review_by < 今天` 才红。
     */
    public function testC3EveryExemptionHasUnexpiredReviewDate(): void
    {
        $json = self::composerJson();
        $exemptions = self::exemptions($json);
        $reviewBy = self::stringMap($json, 'audit-review-by', 'C3');
        $violations = [];
        $today = (new \DateTimeImmutable('today'))->format('Y-m-d');

        foreach ($exemptions as $exemption) {
            $id = $exemption['id'];

            if (!array_key_exists($id, $reviewBy)) {
                $violations[] = sprintf(
                    'C3 豁免「%s」在 extra.audit-review-by 里没有到期日 —— '
                    . '没有到期日的豁免永远不会被复查：理由会过期，依赖早就能升级了也没人知道。'
                    . '改哪里：在 composer.json 的 extra.audit-review-by 里补上 "%s": "YYYY-MM-DD"（建议 3~6 个月后）。',
                    $id,
                    $id
                );
                continue;
            }

            $raw = $reviewBy[$id];
            $date = trim($raw);

            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || !self::isRealDate($date)) {
                $violations[] = sprintf(
                    'C3 豁免「%s」的到期日「%s」不是合法的 YYYY-MM-DD 日期 —— '
                    . '格式不对就没法比较先后，「过期」检测会永远不成立（看起来有到期日，实际永不生效）。'
                    . '改哪里：改成形如 "2027-03-31" 的零填充日期（月份两位数、不要写 2027-3-31，也不要写时间戳）。',
                    $id,
                    $raw
                );
                continue;
            }

            if (strcmp($date, $today) < 0) {
                $violations[] = sprintf(
                    'C3 豁免「%s」的到期日 %s 已过期（今天 %s）—— 到期待复核，复核完请更新或删除该豁免：'
                    . '① 若依赖已能升级 / 公告的可利用性已变化 → 删掉 config.audit.ignore 里的这一条，'
                    . '并同时删掉 extra.audit-rationale 与 extra.audit-review-by 里的对应条目（否则 C4 会红）；'
                    . '② 若确实必须继续暂留 → 在 extra.audit-rationale 里补充这段时间的新结论，'
                    . '并把 extra.audit-review-by 的日期往后推（这次改动会出现在 diff 里，评审看得到）。'
                    . '不要直接把本测试的断言删掉。',
                    $id,
                    $date,
                    $today
                );
            }
        }

        self::assertSame([], $violations, self::summarize('C3', $violations));
    }

    // -----------------------------------------------------------------------
    // C4：反向检查 —— 不留僵尸豁免
    // -----------------------------------------------------------------------

    /**
     * C4：`extra.audit-rationale` / `extra.audit-review-by` 里不允许出现 `config.audit.ignore`
     * 之外的条目。
     *
     * 为什么要反向查：删掉一条豁免时最容易只删 `ignore`（那是 composer 真正读的），
     * 留下理由与到期日变成「僵尸记录」。僵尸记录会让下一个人误以为那条公告仍被豁免着，
     * 也让 `audit-review-by` 的到期检查对着一条不存在的豁免反复报红或永远飘着。
     */
    public function testC4NoOrphanRationaleOrReviewDateEntries(): void
    {
        $json = self::composerJson();
        $ignoredIds = array_map(
            static fn (array $exemption): string => $exemption['id'],
            self::exemptions($json)
        );
        $violations = [];

        foreach (['audit-rationale', 'audit-review-by'] as $extraKey) {
            foreach (array_keys(self::stringMap($json, $extraKey, 'C4')) as $id) {
                if (!in_array($id, $ignoredIds, true)) {
                    $violations[] = sprintf(
                        'C4 孤儿条目：extra.%s 里有「%s」，但 config.audit.ignore 里没有这个 ID（已豁免的是：%s）—— '
                        . '这通常意味着豁免被删掉时只删了 ignore，留下了理由 / 到期日的僵尸记录。'
                        . '改哪里：删掉 extra.%s 里的「%s」；若那是刚新增、还没来得及加进 ignore 的，'
                        . '请把该 ID 一并写进 config.audit.ignore（并确认 composer audit 真的需要豁免它）。',
                        $extraKey,
                        $id,
                        $ignoredIds === [] ? '（无）' : implode(' / ', $ignoredIds),
                        $extraKey,
                        $id
                    );
                }
            }
        }

        self::assertSame([], $violations, self::summarize('C4', $violations));
    }

    // -----------------------------------------------------------------------
    // 通用只读辅助方法
    // -----------------------------------------------------------------------

    /**
     * 读取并解析仓库根的 composer.json。
     *
     * 读不到 / 解析不了时直接 fail：这份守卫的全部断言都建立在它之上，
     * 「读不到就跳过」会让四个 C 组一起空转，正是本仓库最反感的假绿。
     *
     * @return array<string, mixed>
     */
    private static function composerJson(): array
    {
        $path = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . self::COMPOSER_JSON;

        if (!is_file($path)) {
            self::fail(sprintf(
                '%s 不存在（期望路径：%s）—— 本守卫断言的事实全部来自该文件，被移动/改名后必须同步更新本测试。',
                self::COMPOSER_JSON,
                $path
            ));
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            self::fail(sprintf('%s 无法读取（权限或 IO 问题）。', self::COMPOSER_JSON));
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $exception) {
            self::fail(sprintf(
                '%s 不是合法 JSON：%s —— Composer 与 CI 都会直接失败，请先修好 JSON。',
                self::COMPOSER_JSON,
                $exception->getMessage()
            ));
        }

        if (!is_array($decoded)) {
            self::fail(sprintf('%s 的顶层不是对象，无法解析 audit 配置。', self::COMPOSER_JSON));
        }

        return $decoded;
    }

    /**
     * 取出 `config.audit.ignore` 里的豁免清单。
     *
     * 兼容两种历史写法：
     *   - 映射形态（**唯一推荐**）：`{"PKSA-…": "理由"}`；
     *   - 列表形态（旧版 Composer 允许）：`["PKSA-…"]` —— 此时 `reason` 为空字符串，
     *     由 C2 报「缺理由」，而不是在这里静默当成「有理由」。
     *
     * 形态无法识别（不是数组、元素不是字符串、键既不是字符串也不是下标）时直接 fail：
     * 那意味着 ignore 的语义已经变了，本守卫继续跑只会给出错误的绿灯。
     *
     * @param array<string, mixed> $json
     * @return array<int, array{id: string, reason: string}>
     */
    private static function exemptions(array $json): array
    {
        $ignore = $json['config']['audit']['ignore'] ?? [];

        if ($ignore === []) {
            return [];
        }

        if (!is_array($ignore)) {
            self::fail(sprintf(
                'C0 config.audit.ignore 必须是对象（形如 {"公告ID": "理由"}），实际是 %s —— '
                . 'Composer 只认「ID => 理由」的映射；写成别的形态时它读不出一条豁免，'
                . '`Dependency Audit` 会突然对着本该豁免的公告报红，或者（更糟）静默忽略整段配置。'
                . '改哪里：恢复成映射形态。',
                self::describe($ignore)
            ));
        }

        $exemptions = [];
        foreach ($ignore as $key => $value) {
            if (!is_string($value)) {
                self::fail(sprintf(
                    'C0 config.audit.ignore 的「%s」取值必须是字符串（理由），实际是 %s。',
                    (string) $key,
                    self::describe($value)
                ));
            }

            if (is_string($key) && trim($key) !== '') {
                $exemptions[] = ['id' => trim($key), 'reason' => $value];
                continue;
            }

            // 列表形态：键是 0、1、2…，值本身就是公告 ID，没有地方放理由
            $exemptions[] = ['id' => trim($value), 'reason' => ''];
        }

        return $exemptions;
    }

    /**
     * 取出 `extra.<key>` 这份「字符串 => 字符串」映射。
     *
     * 缺失视为空映射（由 C2 / C3 按「缺失」报错，而不是在这里直接红）：
     * 一份完全没有豁免的 composer.json 是合法且健康的状态，不该被本守卫拦住。
     *
     * @param array<string, mixed> $json
     * @return array<string, string>
     */
    private static function stringMap(array $json, string $extraKey, string $group): array
    {
        $section = $json['extra'][$extraKey] ?? [];

        if ($section === []) {
            return [];
        }

        if (!is_array($section)) {
            self::fail(sprintf(
                '%s extra.%s 必须是对象（形如 {"公告ID": "…"}），实际是 %s。',
                $group,
                $extraKey,
                self::describe($section)
            ));
        }

        $map = [];
        foreach ($section as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                self::fail(sprintf(
                    '%s extra.%s 的条目必须是「字符串键 => 字符串值」，实际遇到 %s => %s。',
                    $group,
                    $extraKey,
                    self::describe($key),
                    self::describe($value)
                ));
            }

            $map[$key] = $value;
        }

        return $map;
    }

    /**
     * 判断一个 `YYYY-MM-DD` 字符串是不是真实存在的日历日期。
     *
     * 为什么要回读比较：`DateTimeImmutable::createFromFormat` 对 `2027-02-31` 会**顺延**成
     * `2027-03-03` 而不是报错，不比较回格式化结果就会把不存在的日期当成合法。
     */
    private static function isRealDate(string $date): bool
    {
        $parsed = \DateTimeImmutable::createFromFormat(self::REVIEW_BY_FORMAT, $date);
        if ($parsed === false) {
            return false;
        }

        $errors = \DateTimeImmutable::getLastErrors();
        if (is_array($errors) && ($errors['warning_count'] > 0 || $errors['error_count'] > 0)) {
            return false;
        }

        return $parsed->format('Y-m-d') === $date;
    }

    /**
     * 把任意值描述成人能读的形态（用于失败信息里说明「实际是什么」）。
     */
    private static function describe(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '布尔 true' : '布尔 false';
        }

        if ($value === null) {
            return 'null（缺失）';
        }

        if (is_string($value)) {
            return sprintf('字符串 "%s"', $value);
        }

        if (is_int($value) || is_float($value)) {
            return sprintf('数字 %s', (string) $value);
        }

        if (is_array($value)) {
            return sprintf('数组（%d 个元素）', count($value));
        }

        return get_debug_type($value);
    }

    /**
     * 统一的失败抬头：说明「这是哪一组断言、为什么必须在这里修」。
     *
     * @param array<int, string> $violations
     */
    private static function summarize(string $group, array $violations): string
    {
        return sprintf(
            "composer.json 的依赖公告配置已退化（%s 组，共 %d 条）。\n"
            . "这组断言守的是「CI 门禁还在、且没被人静默拆掉」——`Dependency Audit` 只查公告清单，\n"
            . "看不见 block-insecure 被关、也看不见没有理由 / 没有到期日的豁免，所以必须在这里拦：\n"
            . "  · 若你**确实**要新增 / 续期一条豁免 → 按上面的提示补齐 extra.audit-rationale 与 extra.audit-review-by；\n"
            . "  · 若你**只是想让它变绿** → 不要删本测试的断言，那样门禁就成了摆设。\n"
            . '违规明细：',
            $group,
            count($violations)
        ) . "\n" . implode("\n", $violations);
    }
}
