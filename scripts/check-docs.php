#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Documentation Consistency Check | 文档一致性检查
 *
 * 检查 `docs/` 下的 Markdown 文档，**以及 `SCAN_FILES` 登记的根目录文档**
 * （目前是 README.md / CONTRIBUTING.md），覆盖四类问题：
 *   0. 扫描范围不完整（登记项不存在、根目录 md 既未纳入也未豁免）
 *   1. 链接失效 + 本机绝对路径
 *      （相对链接——`.md` / 目录 / 非 .md 文件——解析后不存在；
 *       `path:行号` 这类会被 GitHub 当成文件名的写法；`file://` 链接泄漏本机绝对路径）
 *   2. 代码围栏不配平（奇数个 ``` 会导致后续内容被吞入代码块）
 *   3. 示例代码引用了不存在的类（设计阶段命名 vs 实际实现）
 *
 * 扫描范围为什么只剩 `docs/`：`design/` 已作为「开发过程产物」移出版本库
 * （`git rm -r --cached`，磁盘保留），不再随仓库交付，因此也不再是本门禁的扫描根。
 *
 * 为什么要扫根目录：`AGENTS.md` 曾经是 AI 助手的强制指令文件，当初因为落在扫描范围之外
 * 从**不被任何机器校验**，先后写下过两条过期事实。检查 0 保证「新加的根目录 md
 * 不会静默漏扫」—— 要么纳入 `SCAN_FILES`，要么写进 `SCAN_FILES_EXEMPT` 并说明原因。
 * 现状：AGENTS.md / CLAUDE.md / README.local.md 均已移出版本库，改由豁免清单压掉报错。
 *
 * ⚠️ 覆盖边界（已知盲区，不要误以为「绿了=内容正确」）：
 *   本脚本只校验**结构**（链接目标存在、围栏成对、示例类存在），**不校验散文事实**。
 *   例如「`php think test` 支持指定文件」这类命令用法错误、或「某检查是两段式」这类
 *   流程描述过期，本脚本一律看不见，仍需人工与 PHPUnit 守卫覆盖。
 *   检查 1 现在同时覆盖 `](./x.md)`、`](../x.md)` 与**裸相对链接** `](docs/x.md)`，
 *   且会先剥离围栏代码块与行内代码（详见 `checkLinks()` 的注释）。
 *   **非 .md 目标（目录、`.php`、`.yml`、`.sh` 等）与 `.md` 目标同等校验**：
 *   只要不是协议链接，就按「所在文件目录」解析并对 `is_file() || is_dir()` 求值。
 *   仍未覆盖：**引用式链接定义**（`[x]: path.md`，实测 0 条）、
 *   锚点（`#section`）是否真实存在、以及**链接文本与目标形态是否匹配**
 *   （例如文本写 `Collection.php` 却链到同名目录 —— 本脚本只认「目标存在」，不读文本）。
 *
 * 用法：
 *   php scripts/check-docs.php              # 检查并输出报告
 *   php scripts/check-docs.php --strict     # 发现任何问题即返回非 0（用于 CI）
 *   php scripts/check-docs.php --skip-refs  # 跳过示例代码类引用检查
 *
 * 退出码：
 *   0 - 通过
 *   1 - 存在问题（--strict 模式）
 *
 * @package scripts
 */

// ---------------------------------------------------------------------------
// 配置
// ---------------------------------------------------------------------------

/**
 * 扫描根目录（相对仓库根）
 *
 * 只保留 `docs`：`design/` 已作为开发过程产物移出版本库，不再交付、也不再校验。
 * `docs/` 内部被移出的子目录（report / todo / superpowers ...）由
 * `EXCLUDE_PATTERNS` 排除 —— 它们在本机磁盘上仍然存在，不排除就会与 CI 结论分叉。
 */
const SCAN_ROOTS = ['docs'];

/**
 * 额外扫描的**单文件**（相对仓库根）
 *
 * 为什么需要它：`SCAN_ROOTS` 只接受目录，扫描范围之外的文件**完全不受校验**。
 * `AGENTS.md` 正是因此写下过两条过期事实（`php think test` 支持「指定文件」的错误说法、
 * `env-check` 的「两段式」描述）—— 没有任何机器检查会碰到根目录。
 *
 * 注意：这里**不能**用 `'.'` 代替 —— 那会把 `frontend/`、`plugins/` 下成千上万个 md
 * 一并扫进来（既慢又会产生大量与项目无关的失效链接）。
 *
 * 新增根目录 md 时请显式加入本清单；忘记加会被「检查 0」点名（见 SCAN_FILES_EXEMPT）。
 * 目前是 `README.md`（仓库门面）与 `CONTRIBUTING.md`（贡献指南）：两者都是交付物。
 */
const SCAN_FILES = [
    'README.md',
    'CONTRIBUTING.md',
];

/**
 * 根目录 md 的「不纳入扫描」豁免清单（键为文件名，值为原因）
 *
 * 只有**确有必要**时才登记，且必须写明原因。未登记也未纳入 `SCAN_FILES` 的根目录 md
 * 会被「检查 0」报错，从而逼迫提交者显式表态（纳入 / 豁免），避免新文件被静默漏掉。
 *
 * ⚠️ 豁免项**允许不存在**：被豁免的常常是只存在于本地的文件（如被 .gitignore 忽略的
 * 备份），CI 干净检出里本来就没有。因此本清单只用于「压掉未纳入扫描的报错」，
 * 不校验存在性 —— 否则 CI 会误红。
 */
const SCAN_FILES_EXEMPT = [
    // 本地强制规则与代理指令，已移出版本库（见 .gitignore），不属于交付物
    'AGENTS.md' => '已移出版本库（见 .gitignore），本地 AI 代理强制规则，非交付文档',
    'CLAUDE.md' => '已移出版本库（见 .gitignore），本地 Claude Code 指令，非交付文档',
    'README.local.md' => '已移出版本库（见 .gitignore），本机个人说明，非交付文档',
    // 本地合并备份，已被 .gitignore 忽略，不属于项目文档，内容会随本地合并变化
    'README.local-merge-backup.md' => '未纳入 git（见 .gitignore），本地人工合并备份，非项目文档',
];

/**
 * 排除的路径片段
 *
 * 判定方式见 `isExcluded()`：把待检路径规范成 `/xxx/yyy.md` 后做**子串**匹配，
 * 因此每条都应带上首尾斜杠、并足以唯一定位目录（`/docs/report/` 不会误伤
 * `/docs/report_archive/`，因为后者该位置是下划线而不是斜杠）。
 *
 * 第一组是与项目无关的产物 / 第三方目录；
 * 第二组是**已移出版本库的开发过程产物**（`git rm -r --cached`，本机磁盘仍保留）。
 * 第二组必须排除：本脚本是递归扫 `docs/` 的，那些文件在本机还在，
 * 不排除就会去校验「已经不发货的文档」，与 CI 干净检出的结论分叉。
 *
 * ⚠️ 维护约定：第二组必须与 .gitignore 的「开发过程产物」分组保持一致。
 */
const EXCLUDE_PATTERNS = [
    '/node_modules/',
    '/vendor/',
    // --- 已移出版本库的开发过程产物（本机仍在磁盘上） ---
    '/docs/PROJECT-OVERVIEW.md',
    '/docs/report/',
    '/docs/report_archive/',
    '/docs/todo/',
    '/docs/superpowers/',
    '/docs/casbin/',
    '/docs/other-docs/',
    // technical-specs 是对外技术规范，整体仍属交付物（api / code-style / database / deployment /
    // git / lowcode / multi-tenant / performance / plugin / security / testing 全部参与扫描）；
    // 只有下面两类是过程产物，已移出版本库、本机磁盘仍保留 → 必须排除，
    // 否则本地会去校验「不发货的文档」，与 CI 干净检出结论分叉。
    '/docs/technical-specs/adr/',
    // 测试报告：记录某一次执行的结果，随环境变化、无长期参考价值。
    // 只排除 *test-report*，同目录的 git-workflow-guidelines / pr-review-checklist /
    // branch-protection-guidelines 都是要交付的规范，必须继续参与扫描。
    '/docs/technical-specs/git/git-workflow-automation-test-report',
    // 注意：performance/ 不在排除清单里。它曾被误判为「内部性能基线」而移出，实际是
    // MUST/SHOULD 条款的权威规范（performance-guidelines.md §1 自称 authoritative reference），
    // 已恢复交付，因此必须参与扫描，否则等于少查一个发货目录。
];

/**
 * 命名空间 → 目录映射（设计阶段命名 → 实际路径）
 *
 * 该表用于判定示例代码中的 use 声明是否指向真实存在的类。
 * 与 docs/technical-specs/lowcode/lowcode-architecture-guidelines.md §5.3 保持一致。
 */
const NAMESPACE_MAP = [
    'Alkaid\\Workflow\\Engine\\' => 'plugins/workflow-engine/src/',
    'Alkaid\\'                   => 'plugins/workflow-engine/src/',
    'app\\'                      => 'app/',
    'Domain\\'                   => 'domain/',
    'Infrastructure\\'           => 'infrastructure/',
    'Tests\\'                    => 'tests/',
];

/**
 * 允许「不存在」的已登记例外
 *
 * 这些类在文档中是被**明确标注为不存在/已删除**的反例说明，
 * 不应计为文档缺陷。
 */
const KNOWN_ABSENT_OK = [
    'Infrastructure\\Field\\FieldTypeRegistry',
    'Infrastructure\\Collection\\CollectionManager',
    'Domain\\Model\\Collection',
];

// ---------------------------------------------------------------------------
// 工具函数
// ---------------------------------------------------------------------------

/**
 * 判断路径是否命中排除规则
 *
 * 目录递归与单文件**共用**这一处判断，保证两边语义一致。
 */
function isExcluded(string $path): bool
{
    $normalized = '/' . str_replace('\\', '/', $path);
    foreach (EXCLUDE_PATTERNS as $pattern) {
        if (str_contains($normalized, $pattern)) {
            return true;
        }
    }

    return false;
}

/**
 * 收集待检查的 .md 文件
 *
 * 顺序：先收 `SCAN_FILES` 里的单文件（不存在 → 记入 $errors，**不静默跳过**：
 * 配置里写了却不存在，说明配置已腐烂），再递归收 `SCAN_ROOTS` 的目录。
 * 结果去重后排序，保证输出稳定。
 *
 * @param string[] $roots
 * @param string[] $files 单文件清单（相对仓库根）
 * @param string[] $errors 出参：收集阶段的错误（登记项不存在 / 配置已腐烂）
 * @return string[]
 */
function collectMarkdownFiles(array $roots, array $files = [], array &$errors = []): array
{
    $collected = [];

    // --- 单文件 ---
    foreach ($files as $file) {
        if (isExcluded($file)) {
            continue;
        }
        if (!is_file($file)) {
            $errors[] = "SCAN_FILES 登记的文件不存在: {$file}（配置已腐烂，请修正清单）";
            continue;
        }
        // 误把非 .md 登记进来时不参与扫描，只留给「检查 0」报错，
        // 否则「扫描文件数」会被一个 json 文件污染，读数失真。
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'md') {
            continue;
        }
        $collected[] = $file;
    }

    // --- 目录递归 ---
    foreach ($roots as $root) {
        if (!is_dir($root)) {
            continue;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            /** @var SplFileInfo $file */
            if ($file->getExtension() !== 'md') {
                continue;
            }
            $path = $file->getPathname();
            if (isExcluded($path)) {
                continue;
            }
            $collected[] = $path;
        }
    }

    $collected = array_values(array_unique($collected));
    sort($collected);

    return $collected;
}

/**
 * 检查 0：扫描范围的完整性
 *
 * 两个方向都要盯：
 *   1. `SCAN_FILES` 登记的文件**必须存在**（配置腐烂；该项由 collectMarkdownFiles 报），
 *      且两者登记的项若存在则必须是 .md；
 *   2. 根目录的 md **必须**出现在两者之一里（覆盖缺口）。
 *
 * 第 2 条是为了让「将来新增根目录 md（如 CONTRIBUTING.md）」不会静默漏扫 ——
 * 若不做这件事，本清单会悄悄过期，门禁再次变成只覆盖 `docs/` 这一条扫描根。
 *
 * @return string[]
 */
function checkScanCoverage(): array
{
    $issues = [];

    // 1a) SCAN_FILES：存在性由收集阶段负责（避免同一条问题被报两遍），此处只校验扩展名
    foreach (SCAN_FILES as $file) {
        if (isExcluded($file) || !is_file($file)) {
            continue; // 排除规则优先；不存在的情况已在 collectMarkdownFiles 里报过
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'md') {
            $issues[] = "SCAN_FILES 登记的文件不是 .md: {$file}";
        }
    }

    // 1b) SCAN_FILES_EXEMPT：只校验「存在时才必须是 .md」，**不校验存在性**
    //
    // 为什么豁免项允许缺席：被豁免的往往是**只存在于本地**的文件（例：被 .gitignore
    // 忽略的 README.local-merge-backup.md）。若在这里要求文件必须存在，CI 的干净检出
    // 里没有这个文件 → 门禁直接误红。豁免清单的职责只是「别再对这个名字报未纳入扫描」，
    // 不承担「文件健康度」的担保。
    foreach (array_keys(SCAN_FILES_EXEMPT) as $file) {
        if (isExcluded($file) || !is_file($file)) {
            continue;
        }
        if (strtolower(pathinfo($file, PATHINFO_EXTENSION)) !== 'md') {
            $issues[] = "SCAN_FILES_EXEMPT 登记的文件不是 .md: {$file}";
        }
    }

    // 2) 根目录 md 必须被显式表态（只扫根目录这一层，不下钻）
    $rootFiles = glob('*.md') ?: [];
    sort($rootFiles);
    foreach ($rootFiles as $file) {
        if (isExcluded($file)) {
            continue;
        }
        if (in_array($file, SCAN_FILES, true)) {
            continue;
        }
        if (array_key_exists($file, SCAN_FILES_EXEMPT)) {
            continue;
        }
        $issues[] = "根目录 md 未纳入扫描: {$file}"
            . '（请加入 SCAN_FILES，或写进 SCAN_FILES_EXEMPT 并注明原因）';
    }

    return $issues;
}

/**
 * 读取文件为行数组
 *
 * @return string[]
 */
function readLines(string $path): array
{
    $content = @file_get_contents($path);
    if ($content === false) {
        return [];
    }

    return explode("\n", $content);
}

/**
 * 检查 1：链接有效性 —— 任意非协议目标（.md / 代码文件 / 配置 / 目录）按所在目录解析，
 *         另含本机绝对路径（`file://`）判错与 `path:行号` 判错
 *
 * ## 支持的链接形态
 *   1. 显式相对：`](./x.md)`、`](../x.md)` —— 历史形态，保持不变
 *   2. 裸相对：  `](x.md)`、`](a/b/x.md)`
 *   3. **目录**：`](../design/06-frontend-design/)` —— 末尾 `/` 只是写法，按目录判存在
 *   4. **非 .md 文件**：`](../app/Request.php)`、`](scripts/git-workflow-tools.sh)`
 *
 * 所有形态都**按所在文件所在目录解析**（`dirname($file) . '/' . $link`）。
 * 这一点是裸链接校验的全部价值：Markdown 相对链接的解析基准是**文档自身位置**，不是仓库根。
 * 因此写在 `docs/report/` 里的 `](docs/x.md)` 在 GitHub 上实际指向
 * `docs/report/docs/x.md` —— 一个**必然不存在**的位置。这类「从仓库根起算」的写法是最常见的
 * 失效形态，且只有真的按目录解析才能被发现。
 *
 * ## 目录 / 非 .md 目标：为什么现在也要校验
 *   此前只判「`.md` 且 `is_file()`」，于是 `.php`、`.yml`、`.json`、目录链接**全部不校验**。
 *   扩到任意目标后，`docs/report/` 一下子暴露 45 条坏链接（其中 43 条目标真实存在，只是少了
 *   `../../` 前缀、或把 `:行号` 写进了 URL），说明「只校验 .md」并不是「保守」，而是让
 *   一大类可修的错写法长期失明。
 *   判定放宽为 `is_file() || is_dir()`：**目录也是合法目标**，否则所有「目录索引」链接都会误报。
 *
 * ## `path:行号` 一律判错（不做剥离）
 *   `](app/Request.php:1)` 在 GitHub 生成的 href 里，`:1` 会成为**文件名的一部分** → 必然 404。
 *   因此这里**不**在内部把 `:1` 剥掉再判存在 —— 那会让坏链伪装成好链。
 *   报错时附带定向提示（`hint = line-suffix`），告诉作者改用 `#L12` / `#L12-L20`。
 *
 * ## 主动跳过（不是缺陷，也不新增错误类别）
 *   - 协议链接：`http://`、`https://`、`mailto:` 等任意 `scheme:` 形式（外网可达性不在本脚本职责内）
 *   - 纯锚点：`](#section)`
 *   - 根绝对：`](/x.md)` —— 语义是「从仓库根解析」，与本地文件系统路径不是一回事；
 *     实测扫描范围内 0 条，故只跳过、不新增报错类别，避免凭空造一条没人验证过的规则
 *   - 例外见 `detectLineSuffixLink()`：`composer.json:1` 这种「scheme 名恰好是文件名」的写法
 *     会先被摘出来按路径判，而不是当成协议（否则整类 `path:行号` 错误会静默消失）
 *
 * ## 单独判错：`file://` 本机绝对路径
 *   `](file:///Users/xxx/project/app/Foo.php#L16-L20)` 在**作者本机**能点开，换台机器、换个人
 *   就必然失效，同时把本机用户名与目录结构泄漏进版本库。该判定与路径判定**相互独立**
 *   （`file://` 可以指向 `.php` 等任意文件），因此同一行里的
 *   `](file:///.../security-guidelines.md)` **只报一次 `file://` 错误，不再被算作失效链接**
 *   —— 否则同一条问题记两遍，报错账目对不上。
 *
 * ## `docs/report` 策略：**不豁免**（决策记录）
 *   该目录曾是裸链接最密集处（实测 45 条坏链，其中 43 条目标真实存在、只是少了 `../`），
 *   属于「能被修的问题」，所以检查 1 当年刻意不按目录豁免它，而是逐条修正；
 *   检查 3 则豁免它 —— 审计报告会**故意引用不存在的类**作为证据。
 *   现状：`docs/report/` 已作为开发过程产物移出版本库（见 `EXCLUDE_PATTERNS`），
 *   整个目录不再参与扫描，检查 3 的对应豁免常量 `REF_CHECK_EXCLUDE` 也随之删除。
 *   通用处置方式仍然有效：指向已移出 / 已删除文件的链接**降级为行内代码**
 *   （`` `docs/xxx.md` ``：保留原文陈述、退出链接校验），而不是按目录一刀切豁免。
 *
 * ## 代码剥离（避免把「说明文字」当成真链接）
 *   扫描前先剥离两类代码，否则描述正则本身的文字会被误报
 *   （实例：`docs/report/doc-review-design-docs-2026-10-30.md` 里那行表格，
 *   反引号内写着 `](...md)` 作为**检查规则说明**，并不是链接）：
 *   - **围栏代码块**：状态机逐行切换，判定用 `str_starts_with(trim($line), '```')`；
 *     围栏行自身也不参与扫描（它是语法标记，不是内容）
 *   - **行内代码 span**：见 `isInsideInlineCode()`
 *
 * ## 与检查 2 的关系
 *   检查 2 只负责「围栏是否配平」，仍按**行首**（不 trim）判定，本次**未改动**。
 *   本函数为了不漏掉列表内缩进的围栏（实测存在 240 行缩进围栏，如 `design/06-ratelimit/`），
 *   改用 trim 后判定。已在 212 个扫描文件上验证两种判定的结论一致：
 *   每个文件的围栏数在两种判定下均为偶数，且缩进围栏内部含链接 0 条。
 *
 * @param string[] $files
 * @return array<int, array{file:string, line:int, link:string, kind:string, target:string, hint:string}>
 */
function checkLinks(array $files): array
{
    $issues = [];

    foreach ($files as $file) {
        $dir = dirname($file);
        $inFence = false;

        foreach (readLines($file) as $index => $line) {
            // --- 剥离 1：围栏代码块（状态机；围栏行自身跳过）---
            if (str_starts_with(trim($line), '```')) {
                $inFence = !$inFence;
                continue;
            }
            if ($inFence) {
                continue;
            }

            // 抓取 `](target)` 里的 target（不取空白，从而天然忽略 `(x.md "标题")` 的标题部分）
            if (!preg_match_all('/\]\(\s*([^)\s]+)\s*\)/u', $line, $matches, PREG_OFFSET_CAPTURE)) {
                continue;
            }

            foreach ($matches[1] as $i => $match) {
                $link = $match[0];

                // --- 剥离 2：行内代码 span（近似判定，见 isInsideInlineCode）---
                if (isInsideInlineCode($line, (int) $matches[0][$i][1])) {
                    continue;
                }

                $issue = inspectLinkTarget($file, $index + 1, $dir, $link);
                if ($issue !== null) {
                    $issues[] = $issue;
                }
            }
        }
    }

    return $issues;
}

/**
 * 判定单个链接目标是否需要报错；返回 `null` 表示「跳过，不是缺陷」
 *
 * `kind` 取值：
 *   - `file-url`：`file://` 本机绝对路径（与路径判定相互独立，见 checkLinks 注释）
 *   - `missing` ：相对链接（`.md` / 目录 / 任意非 .md 文件）按所在目录解析后不存在
 *
 * `hint` 取值（与 `kind` 正交：**同一条问题只记一次**，提示只是附加信息）：
 *   - `''`           ：无额外提示
 *   - `line-suffix`  ：链接形如 `xxx:12` / `xxx:12-20`，GitHub 会把 `:12` 当文件名的一部分，
 *                      需改用 `#L12` / `#L12-L20`。本质仍是 `missing`，不单列成新类别。
 *
 * @return array{file:string, line:int, link:string, kind:string, target:string, hint:string}|null
 */
function inspectLinkTarget(string $file, int $line, string $dir, string $link): ?array
{
    // 1) `file://` 本机绝对路径：独立成类，先判（避免同一条再被算成普通失效链接）
    if (stripos($link, 'file://') === 0) {
        return [
            'file' => $file, 'line' => $line, 'link' => $link,
            'kind' => 'file-url', 'target' => '', 'hint' => '',
        ];
    }

    // 2) 先摘 `path:行号` 形态（必须在「协议」判定之前，否则下一行的正则会把
    //    `composer.json:1` 当成 scheme 为 `composer.json` 的协议链接直接跳过）
    $lineSuffixBare = detectLineSuffixLink($link);

    // 3) 协议链接（http/https/mailto/...）、纯锚点、根绝对路径：主动跳过
    //    —— 仅当它不是 `path:行号` 形态时才跳过（那类要按路径判，见第 2 步）
    if ($lineSuffixBare === null
        && preg_match('~^(?:[a-z][a-z0-9+.\-]*:|/|\#)~i', $link) === 1) {
        return null;
    }

    // 4) 取路径部分：只去掉 `#锚点`（锚点是否存在不在本项范围）
    //    ⚠️ `:行号` **不剥离** —— GitHub 生成的 href 里 `:12` 是文件名的一部分，必然 404；
    //    剥掉再判存在会把坏链伪装成好链，因此原样参与下面的存在性判定。
    $pathPart = (string) preg_replace('/#.*$/', '', $link);
    if ($pathPart === '') {
        return null; // 形如 `#section`（已在第 3 步跳过）或空目标
    }

    // 5) 按**所在文件目录**解析（`./`、`../`、裸链接共用这一处）；
    //    **目录也是合法目标** —— 否则所有「目录索引」链接都会被误报
    $resolved = normalizePath($dir . '/' . $pathPart);
    if (is_file($resolved) || is_dir($resolved)) {
        return null;
    }

    return [
        'file' => $file, 'line' => $line, 'link' => $link,
        'kind' => 'missing', 'target' => $resolved,
        'hint' => $lineSuffixBare === null ? '' : 'line-suffix',
    ];
}

/**
 * 识别 `xxx:12` / `xxx:12-20` 形态的「行号后缀」链接，返回冒号前的候选路径
 *
 * ## 为什么需要它（真实缺陷，不是理论风险）
 *   第 3 步的「协议链接」正则是 `^[a-z][a-z0-9+.\-]*:`。而 scheme 名允许 `.`、`+`、`-`，
 *   于是 `composer.json:1` 恰好被解析成 `scheme=composer.json` + 剩余 `1` → 整条链接被跳过。
 *   实测 `docs/report/comprehensive-project-audit-report-2025-12-16.md` 有 5 条这样被吞掉
 *   （`composer.json:1`、`docker-compose.yml:1`、`phpunit.xml:1` ×2、`phpunit.performance.xml:1`），
 *   它们的真实目标都在仓库根、只是少了 `../../` 前缀 —— 属于**必须报出来**的一类。
 *
 * ## 判定规则（保守：宁可判成协议，也不误伤真 URL）
 *   - 冒号后必须是**纯行号**（`12` 或 `12-20`），其余一律当协议
 *   - 冒号前含 `://` → 真 URL（`https://host/a.php:12`），不参与路径解析
 *   - 冒号前既无 `/` 也无 `.` → 没有路径特征（`tel:123`、`mailto:x` 之类），仍按协议跳过
 *
 * @return string|null 匹配时返回「冒号前」的候选路径；否则 null（交给协议判定）
 */
function detectLineSuffixLink(string $link): ?string
{
    if (preg_match('/^(.+):[0-9]+(?:-[0-9]+)?$/', $link, $m) !== 1) {
        return null;
    }

    $bare = $m[1];
    if (str_contains($bare, '://')) {
        return null; // 真协议：`https://...:12` 的行号语义由平台自己处理
    }
    if (!str_contains($bare, '/') && !str_contains($bare, '.')) {
        return null; // 无路径特征的裸 scheme：`tel:123`
    }

    return $bare;
}

/**
 * 判断某一位置是否落在**行内代码 span** 内（近似判定）
 *
 * 规则：该行此位置之前出现**奇数个**反引号 → 认为处于行内代码内 → 跳过。
 *
 * 为什么是近似而非完整 CommonMark：完整规则要处理 `\`` 转义、以及「N 个反引号开头
 * 必须 N 个反引号闭合」的等长配对。这里刻意取最简判定，理由是**够用**：
 * 全仓实测「行内代码里的 .md 链接」只有 1 条，且是单反引号包裹的说明文字。
 * 若将来出现嵌套多反引号的行内代码导致漏报/误报，再升级为逐字符状态机。
 */
function isInsideInlineCode(string $line, int $offset): bool
{
    return substr_count(substr($line, 0, max(0, $offset)), '`') % 2 === 1;
}

/**
 * 检查 2：代码围栏配平
 *
 * @param string[] $files
 * @return array<int, array{file:string, count:int}>
 */
function checkFences(array $files): array
{
    $issues = [];
    foreach ($files as $file) {
        $count = 0;
        foreach (readLines($file) as $line) {
            if (str_starts_with($line, '```')) {
                $count++;
            }
        }
        if ($count % 2 !== 0) {
            $issues[] = ['file' => $file, 'count' => $count];
        }
    }

    return $issues;
}

/**
 * 检查 3：示例代码引用的类是否存在
 *
 * **豁免规则**：
 *   1. 文档头部若已包含「命名空间声明」或「待实施方案」横幅 → 不参与本项检查
 *      （不匹配已被显式声明，不会误导读者）
 *
 * 历史：曾有第 2 条「`docs/report/` 下的审计报告不参与本项检查」（它们故意引用
 * 不存在的类作为证据）。该目录已移出版本库并写进 `EXCLUDE_PATTERNS`，根本不会进入
 * `$files`，那条豁免已无作用，故连同 `REF_CHECK_EXCLUDE` 常量一并删除。
 *
 * 该豁免**只对已声明的文档生效**，用于防止新增未声明文档再次引入错误引用。
 *
 * @param string[] $files
 * @return array<int, array{file:string, line:int, class:string}>
 */
function checkExampleClassRefs(array $files): array
{
    $issues = [];
    foreach ($files as $file) {
        if (hasDeclarationBanner($file)) {
            continue;
        }
        $lines = readLines($file);
        foreach ($lines as $index => $line) {
            if (!preg_match('/^\s*use\s+([A-Za-z_][A-Za-z0-9_\\\\]*)\s*[;{]/', $line, $m)) {
                continue;
            }
            $class = $m[1];
            if (isKnownAbsentOk($class)) {
                continue;
            }
            $resolved = resolveClassPath($class);
            if ($resolved === null) {
                continue; // 非项目命名空间（框架/vendor 类），本脚本不校验
            }
            if (!is_file($resolved)) {
                $issues[] = ['file' => $file, 'line' => $index + 1, 'class' => $class];
            }
        }
    }

    return $issues;
}

/**
 * 判断文档是否已声明「示例使用设计阶段命名」或「待实施方案」
 *
 * 仅检查文档前 80 行，避免正文引用误判。
 */
function hasDeclarationBanner(string $file): bool
{
    $lines = readLines($file);
    $head = array_slice($lines, 0, 80);
    foreach ($head as $line) {
        if (str_contains($line, '命名空间声明') || str_contains($line, '待实施方案')) {
            return true;
        }
    }

    return false;
}

/**
 * 判断类是否属于「文档已标注不存在」的例外
 */
function isKnownAbsentOk(string $class): bool
{
    return in_array($class, KNOWN_ABSENT_OK, true);
}

/**
 * 将项目命名空间解析为文件路径；非项目命名空间返回 null
 */
function resolveClassPath(string $class): ?string
{
    foreach (NAMESPACE_MAP as $prefix => $base) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $rest = substr($class, strlen($prefix));
        if ($rest === '') {
            return null;
        }

        return $base . str_replace('\\', '/', $rest) . '.php';
    }

    return null;
}

/**
 * 规范化路径（处理 . 与 ..）
 */
function normalizePath(string $path): string
{
    $parts = [];
    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }

    return implode('/', $parts);
}

/**
 * 终端着色
 */
function colorize(string $text, string $color): string
{
    $codes = ['red' => '31', 'green' => '32', 'yellow' => '33', 'blue' => '34', 'gray' => '90'];
    $code = $codes[$color] ?? null;
    if ($code === null || getenv('NO_COLOR') !== false) {
        return $text;
    }

    return "\033[{$code}m{$text}\033[0m";
}

// ---------------------------------------------------------------------------
// 主流程
// ---------------------------------------------------------------------------

$strict = in_array('--strict', $argv, true);
$skipRefs = in_array('--skip-refs', $argv, true);

echo colorize('Documentation Consistency Check | 文档一致性检查', 'blue') . PHP_EOL;
echo str_repeat('=', 64) . PHP_EOL;

$collectErrors = [];
$files = collectMarkdownFiles(SCAN_ROOTS, SCAN_FILES, $collectErrors);
echo '扫描文件数: ' . count($files) . PHP_EOL;

// --- 检查 0：扫描范围完整性（依赖收集阶段的结果，故单独输出）---
$coverageIssues = array_merge($collectErrors, checkScanCoverage());
printf("检查 0  扫描范围完整性        %s\n", formatResult(count($coverageIssues)));
foreach ($coverageIssues as $issue) {
    echo '   ' . colorize('[COVERAGE]', 'yellow') . " {$issue}" . PHP_EOL;
}
echo PHP_EOL;

$totalIssues = count($coverageIssues);

// --- 检查 1：链接 ---
$linkIssues = checkLinks($files);
// 标签用「链接有效性」而非「相对链接有效性」：检查 1 已覆盖目录与非 .md 目标，
// 旧标签会让人误以为只查 .md 相对链接（详见上方检查 1 的注释与 AGENTS.md 的 4.1 / 4.2）
printf("检查 1  链接有效性            %s\n", formatResult(count($linkIssues)));
foreach ($linkIssues as $issue) {
    if ($issue['kind'] === 'file-url') {
        echo '   ' . colorize('[FILE-URL]', 'yellow')
            . " {$issue['file']}:{$issue['line']} -> {$issue['link']}"
            . '（本机绝对路径，他人无法打开；请改成从文档所在目录出发的相对链接）' . PHP_EOL;
        continue;
    }
    echo '   ' . colorize('[LINK]', 'yellow')
        . " {$issue['file']}:{$issue['line']} -> {$issue['link']}"
        . "（解析为 {$issue['target']}，不存在）";
    // `path:行号` 形态额外给定向提示（仍是同一条 `missing` 记录，不重复计数）
    if ($issue['hint'] === 'line-suffix') {
        echo '；URL 里不要带 `:行号` 后缀，GitHub 会当成文件名的一部分；'
            . '要定位行请改用 `#L12` 或 `#L12-L20`';
    }
    echo PHP_EOL;
}
$totalIssues += count($linkIssues);

// --- 检查 2：围栏 ---
$fenceIssues = checkFences($files);
printf("检查 2  代码围栏配平          %s\n", formatResult(count($fenceIssues)));
foreach ($fenceIssues as $issue) {
    echo '   ' . colorize('[FENCE]', 'yellow') . " {$issue['file']} ({$issue['count']} 个围栏)" . PHP_EOL;
}
$totalIssues += count($fenceIssues);

// --- 检查 3：示例类引用 ---
if ($skipRefs) {
    echo '检查 3  示例代码类引用        ' . colorize('SKIPPED', 'gray') . PHP_EOL;
} else {
    $refIssues = checkExampleClassRefs($files);
    printf("检查 3  示例代码类引用        %s\n", formatResult(count($refIssues)));
    foreach ($refIssues as $issue) {
        echo '   ' . colorize('[REF]', 'yellow')
            . " {$issue['file']}:{$issue['line']} -> {$issue['class']}" . PHP_EOL;
    }
    $totalIssues += count($refIssues);
}

echo str_repeat('-', 64) . PHP_EOL;

if ($totalIssues === 0) {
    echo colorize('✓ 全部检查通过', 'green') . PHP_EOL;
    exit(0);
}

echo colorize("发现 {$totalIssues} 个问题", 'red') . PHP_EOL;
if ($strict) {
    exit(1);
}

echo colorize('提示：使用 --strict 可在 CI 中作为门禁（存在问题即返回非 0）', 'gray') . PHP_EOL;
exit(0);

/**
 * 格式化检查结果
 */
function formatResult(int $count): string
{
    return $count === 0
        ? colorize('OK', 'green')
        : colorize("{$count} 个问题", 'red');
}
