<?php

declare(strict_types=1);

namespace Tests\Legacy;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * 守卫：AGENTS.md 里「转述上游文件内部事实」的陈述一旦与实际漂移，必须立刻变红。
 *
 * 为什么需要它：`AGENTS.md` 已纳入 `scripts/check-docs.php`，但那个脚本只看**结构**
 * （链接目标存不存在、代码围栏成不成对、示例 `use` 的类存不存在），**完全看不见散文事实**。
 * 而 AGENTS.md 历史上已经两次写下过期事实，两次都没有任何机器检查碰到。
 *
 * 现在它镜像着一批「上游文件的内部细节」：required check 名称、`paths:` 是否存在、
 * `SCAN_FILES` 名单、phpunit 套件、下限常量等。上游文件改动时 AGENTS.md 不会自己更新，
 * 于是它只会在下一个人读到时悄悄是错的。本守卫把这些**可枚举事实**从散文变成断言。
 *
 * 断言分组（失败信息里的前缀与之一一对应）：
 *   A1 门禁表 ↔ workflow 实际定义（workflow 顶层 name / job id / job name）
 *   A2 四份工作流不带 `paths:` 过滤（R40 回归守卫）
 *   A3 `SCAN_FILES` 名单双向一致（AGENTS.md ↔ scripts/check-docs.php）
 *   A4 phpunit 套件名单（AGENTS.md ↔ phpunit.xml，Legacy 必须在其中）
 *   A5 `failOnEmptyTestSuite="true"`
 *   A6 8 个 required check 的 job 都没有 `needs:`（不是只钉 `phpunit`）
 *   A7 `TEMPLATE_REQUIRED_MIN_KEYS` 的「6」两处一致
 *
 * 约束：
 *   - **只读**：只 `file_get_contents` / `Yaml::parseFile` 读本地文件，不写文件、不连库、不调外部命令；
 *   - workflow 一律交给 `symfony/yaml` **真解析**，不再靠「四空格缩进切片」猜结构：
 *     那套文本切片的致命点是「一个 job 级键的缩进从四空格改成两空格，`needs:` 就被数成 0 处」
 *     —— 于是 A6 静默放行（假绿，比假红危险得多）。缩进是排版，不是结构，结构只能由 YAML 解析器回答；
 *   - ⚠️ `symfony/yaml` 是**仅为本守卫**引入的 require-dev 依赖（composer.json 里已注明），
 *     不是「没人用的多余包」：删掉它，本文件的 workflow 断言就只剩字符串匹配；
 *   - 解析失败（`ParseException`）必须转成**违规**而不是让用例 error：error 的语义是「守卫自己坏了」，
 *     而守卫崩溃等于连合法 YAML 都拦不住 —— 见 `loadWorkflow()`。
 */
final class AgentsDocFactsGuardTest extends TestCase
{
    /** AGENTS.md（相对仓库根） */
    private const AGENTS_DOC = 'AGENTS.md';

    /** phpunit 配置（相对仓库根） */
    private const PHPUNIT_XML = 'phpunit.xml';

    /**
     * A1：门禁表里**必须存在**的 8 行（check 名称 => [工作流文件, job id]）。
     *
     * 新增门禁不必改这里；但**重命名** check 会同时牵动三处：
     * GitHub 分支保护里勾选的 required check、AGENTS.md 的门禁表格、以及这份清单。
     * 只改其中一处就是「required 静默失效」（分支保护勾的是旧 name，新 name 不被要求），
     * 所以这里刻意钉死 —— 逼改的人显式表态。
     */
    private const REQUIRED_GATES = [
        'Documentation Consistency' => ['.github/workflows/docs-check.yml', 'docs-check'],
        'PHPUnit Config Consistency' => ['.github/workflows/phpunit-config-check.yml', 'phpunit-config-check'],
        'Env Example Consistency' => ['.github/workflows/env-check.yml', 'env-example-check'],
        'Language Pack Consistency Check (i18n)' => ['.github/workflows/backend-php-cs-fixer.yml', 'lang-check'],
        'Migration Safety Check' => ['.github/workflows/migrations-check.yml', 'migrations-check'],
        'Migration Data Consistency Check' => ['.github/workflows/migrations-check.yml', 'migration-data-check'],
        'Dependency Audit' => ['.github/workflows/backend-php-cs-fixer.yml', 'dependency-audit'],
        'PHPUnit Tests' => ['.github/workflows/backend-php-cs-fixer.yml', 'phpunit'],
    ];

    /**
     * A2：AGENTS.md「人工核对清单」点名「不再有 `paths:`」的四份工作流。
     *
     * 顺序与文档一致（文档里就是按这个顺序列出的），避免无意义抖动。
     * 刻意**不**对全部 workflow 断言：`frontend-ci.yml` 目前有 2 处 `paths:`，那是合法的。
     */
    private const PATHLESS_WORKFLOWS = [
        '.github/workflows/docs-check.yml',
        '.github/workflows/phpunit-config-check.yml',
        '.github/workflows/env-check.yml',
        '.github/workflows/migrations-check.yml',
    ];

    /** A7：下限常量的当前值（脚本与文档都必须等于它） */
    private const EXPECTED_TEMPLATE_MIN_KEYS = 6;

    /**
     * workflow 的 YAML 解析结果缓存（键 = 相对仓库根的 workflow 路径）。
     *
     * 同一份 workflow 会被 A1（门禁表逐行 + 钉死清单）与 A6 反复读取，
     * 缓存保证每个文件只解析一次。值为 null 表示「已经解析失败、违规也已报过」，
     * 后续断言直接复用该结论，不再重复报同一条违规。
     *
     * @var array<string, array<string, mixed>|null>
     */
    private array $workflowCache = [];

    public function testAgentsDocFactsMatchUpstreamSources(): void
    {
        $projectRoot = dirname(__DIR__, 2);
        $violations = [];

        $agentsDoc = self::readProjectFile($projectRoot, self::AGENTS_DOC, $violations, '守卫载入');
        if ($agentsDoc === null) {
            self::assertSame([], $violations, '无法读取 AGENTS.md，本守卫无法继续（见上面的违规条目）。');
            return;
        }

        $violations = array_merge(
            $violations,
            $this->checkGateTable($projectRoot, $agentsDoc),
            $this->checkNoPathsFilter($projectRoot, $agentsDoc),
            $this->checkScanFilesList($projectRoot, $agentsDoc),
            $this->checkPhpunitSuites($projectRoot, $agentsDoc),
            $this->checkFailOnEmptyTestSuite($projectRoot),
            $this->checkRequiredGatesHaveNoNeeds($projectRoot),
            $this->checkTemplateMinKeys($projectRoot, $agentsDoc)
        );

        $summary = "AGENTS.md 里有「转述上游文件内部事实」的陈述已与实际文件不一致。\n"
            . "这类事实没有任何其他机器校验覆盖（check-docs 只校验结构，看不见散文），所以必须在这里修好：\n"
            . "  · 若上游文件（workflow / phpunit.xml / scripts/*.php）的改动是**合规**的 → 同步修正 AGENTS.md；\n"
            . '  · 若上游文件被改**错了**（例如某个 required check 的 job 被加回 needs、paths 过滤被加回）→ 改回上游文件，'
            . "不要为了让守卫变绿而修改 AGENTS.md 的描述。\n"
            . '共 ' . count($violations) . ' 条：';

        self::assertSame([], $violations, $summary . "\n" . implode("\n", $violations));
    }

    // -----------------------------------------------------------------------
    // A1：门禁表 ↔ workflow 实际定义
    // -----------------------------------------------------------------------

    /**
     * A1：AGENTS.md 的门禁表逐行对齐 workflow 的真实定义。
     *
     * 表格式：| 门禁 | 工作流文件 | 工作流 `name` | job id | check 名称 | 实际执行 |
     * 每行断言四件事：① 文件存在 ② 顶层 `name:` 的值 ③ `jobs:` 下存在该 job id
     * ④ 该 job 的 `name:` 值 == 表里的 check 名称。
     *
     * 为什么最高价值：**改了 workflow 的 job name 会让分支保护里勾选的 required check
     * 静默失效**，而 AGENTS.md 那张表是唯一记录。
     *
     * @return array<int, string>
     */
    private function checkGateTable(string $projectRoot, string $agentsDoc): array
    {
        $violations = [];
        $rows = self::parseGateTableRows($agentsDoc, $violations);

        // 1) 文档里出现的每一行，都要与实际 workflow 对上
        foreach ($rows as $row) {
            $workflowPath = $row['workflow'];
            $where = sprintf(
                'A1 门禁表：AGENTS.md 第 %d 行（门禁「%s」）',
                $row['line'],
                $row['gate']
            );

            $workflow = $this->loadWorkflow($projectRoot, $workflowPath, $violations, 'A1 门禁表');
            if ($workflow === null) {
                continue;
            }

            // ① 顶层 name
            $actualWorkflowName = self::workflowTopLevelName($workflow);
            if ($actualWorkflowName === null) {
                $violations[] = $where . sprintf(
                    ' 引用的 %s 里找不到顶层 `name:` —— 该 workflow 缺 name（或被写成了无法识别的形态）；'
                    . '请给 workflow 补顶层 name，并让 AGENTS.md 表格第 3 列与之一致。',
                    $workflowPath
                );
            } elseif ($actualWorkflowName !== $row['workflowName']) {
                $violations[] = $where . sprintf(
                    ' 第 3 列（工作流 name）写的是「%s」，但 %s 顶层 `name:` 实际是「%s」 —— '
                    . '以 workflow 为准改 AGENTS.md 的门禁表格；若反而要把 workflow 改名，请同时确认 Workflow 名称没有被人按旧名引用。',
                    $row['workflowName'],
                    $workflowPath,
                    $actualWorkflowName
                );
            }

            // ② job id 存在性
            $job = self::workflowJobDefinition($workflow, $row['jobId']);
            if ($job === null) {
                $violations[] = $where . sprintf(
                    ' 第 4 列（job id）写的是「%s」，但 %s 的 `jobs:` 下没有这个 job id（现有 job：%s）—— '
                    . 'job id 改名/删除后必须同步表格第 4 列；job 不存在时该 required check 会永久停在 Expected，PR 无法合并。',
                    $row['jobId'],
                    $workflowPath,
                    implode(' / ', self::workflowJobIds($workflow))
                );
                continue;
            }

            // ③ job name（= GitHub 上显示的 check 名称）
            $actualJobName = self::workflowJobName($job);
            if ($actualJobName === null) {
                $violations[] = $where . sprintf(
                    ' 指向 %s 的 jobs.%s，但该 job 没有 job 级 `name:` 键 —— '
                    . '缺少 name 时 GitHub 退化成用 job id 当 check 名称，与表格第 5 列的「%s」不一致。'
                    . '请给该 job 补 `name:`，或按 job id 更新表格与分支保护。',
                    $workflowPath,
                    $row['jobId'],
                    $row['checkName']
                );
            } elseif ($actualJobName !== $row['checkName']) {
                $violations[] = $where . sprintf(
                    ' 第 5 列（check 名称）写的是「%s」，但 %s 的 jobs.%s.name 实际是「%s」—— '
                    . '分支保护里能勾选的只有 `name:` 那一行，改了 workflow 就同步改 AGENTS.md 的门禁表格；'
                    . '否则 required check 会静默失效（勾的是旧名字，新名字不被要求）。',
                    $row['checkName'],
                    $workflowPath,
                    $row['jobId'],
                    $actualJobName
                );
            }
        }

        // 2) 钉死的 8 个 check：workflow 侧必须真的是这个名字（不依赖 AGENTS.md 写对）
        foreach (self::REQUIRED_GATES as $checkName => [$workflowPath, $jobId]) {
            $workflow = $this->loadWorkflow($projectRoot, $workflowPath, $violations, 'A1 门禁表');
            if ($workflow === null) {
                continue;
            }

            $job = self::workflowJobDefinition($workflow, $jobId);
            if ($job === null) {
                $violations[] = sprintf(
                    'A1 门禁表（钉死清单）：%s 的 `jobs:` 下找不到 job id「%s」（该 job 的 check 名称应为「%s」，现有 job：%s）—— '
                    . 'job 被删/改名会直接丢掉一个 required check，请恢复 job 或同步三处：分支保护 + AGENTS.md 表格 + 本测试的 REQUIRED_GATES。',
                    $workflowPath,
                    $jobId,
                    $checkName,
                    implode(' / ', self::workflowJobIds($workflow))
                );
                continue;
            }

            $actualJobName = self::workflowJobName($job);
            if ($actualJobName !== $checkName) {
                $violations[] = sprintf(
                    'A1 门禁表（钉死清单）：%s 的 jobs.%s.name 实际是「%s」，但分支保护与 AGENTS.md 记录的都是「%s」—— '
                    . '改 job name 会让 required check 静默失效（分支保护里勾的是旧 name，新 name 不会被要求，PR 反而少了这道门禁）。'
                    . '要么把 workflow 的 name 改回「%s」，要么同时改：分支保护勾选 + AGENTS.md 表格 + 本测试的 REQUIRED_GATES。',
                    $workflowPath,
                    $jobId,
                    (string) $actualJobName,
                    $checkName,
                    $checkName
                );
            }
        }

        // 3) 钉死的 8 个 check 必须在表格里出现且指向同一个 job（表格被删行就等于没人知道要勾它）
        $documented = [];
        foreach ($rows as $row) {
            $documented[$row['checkName']] = $row;
        }
        foreach (self::REQUIRED_GATES as $checkName => [$workflowPath, $jobId]) {
            if (!isset($documented[$checkName])) {
                $violations[] = sprintf(
                    'A1 门禁表（钉死清单）：AGENTS.md 的门禁表里没有 check 名称「%s」这一行（应为 %s 的 jobs.%s）—— '
                    . '删掉这一行等于让下一个人以为该门禁不必勾。若确实要下线该门禁，请同时改：分支保护 + AGENTS.md 表格 + 本测试的 REQUIRED_GATES。',
                    $checkName,
                    $workflowPath,
                    $jobId
                );
                continue;
            }

            if ($documented[$checkName]['workflow'] !== $workflowPath || $documented[$checkName]['jobId'] !== $jobId) {
                $violations[] = sprintf(
                    'A1 门禁表（钉死清单）：AGENTS.md 第 %d 行把「%s」指向 %s 的 jobs.%s，但该 check 实际来自 %s 的 jobs.%s —— '
                    . '表格里的文件/job id 写错后，核对的人会去改错的工作流。请修正 AGENTS.md 表格，或同步修正本测试的 REQUIRED_GATES。',
                    $documented[$checkName]['line'],
                    $checkName,
                    $documented[$checkName]['workflow'],
                    $documented[$checkName]['jobId'],
                    $workflowPath,
                    $jobId
                );
            }
        }

        return $violations;
    }

    /**
     * 解析 AGENTS.md 里的门禁表行（只取含 `.github/workflows/` 的行）。
     *
     * @param array<int, string> $violations
     * @return array<int, array{line: int, gate: string, workflow: string, workflowName: string, jobId: string, checkName: string}>
     */
    private static function parseGateTableRows(string $agentsDoc, array &$violations): array
    {
        $rows = [];

        foreach (preg_split('/\R/u', $agentsDoc) ?: [] as $index => $line) {
            if (strpos($line, '.github/workflows/') === false || strpos($line, '|') === false) {
                continue;
            }

            $cells = array_map(
                static fn (string $cell): string => trim(str_replace(['**', '`'], '', $cell)),
                explode('|', $line)
            );

            // 行首行尾各有一个空单元格，故有效列从索引 1 开始：1=门禁 2=文件 3=name 4=job id 5=check 名称 6=实际执行
            if (count($cells) < 7) {
                $violations[] = sprintf(
                    'A1 门禁表：AGENTS.md 第 %d 行含 `.github/workflows/` 但按 `|` 切分只得到 %d 段（门禁表每行需要 6 列）—— '
                    . '表格结构被破坏后本守卫无法核对 check 名称，请恢复成 6 列。',
                    $index + 1,
                    count($cells)
                );
                continue;
            }

            $rows[] = [
                'line' => $index + 1,
                'gate' => $cells[1],
                'workflow' => $cells[2],
                'workflowName' => $cells[3],
                'jobId' => $cells[4],
                'checkName' => $cells[5],
            ];
        }

        if ($rows === []) {
            $violations[] = 'A1 门禁表：AGENTS.md 里没有解析到任何含 `.github/workflows/` 的门禁表行 —— '
                . '这张表是 required check 名称的唯一记录处，被删/被改成非表格形态后，'
                . '下一次改 workflow 就没有任何对照物了。请恢复 §合并前必须通过的配置类门禁 下的表格。';
        }

        return $rows;
    }

    // -----------------------------------------------------------------------
    // A2：四份工作流不带 `paths:` 过滤（R40 回归守卫）
    // -----------------------------------------------------------------------

    /**
     * A2：AGENTS.md「人工核对清单」要求四份工作流**不再有** `paths:`，逐份数一遍。
     *
     * 带 `paths:` 的工作流一旦被设为 required，未命中路径的 PR 根本不会触发该 check，
     * 于是 GitHub 一直显示 `Expected — Waiting for status to be reported`，PR 永久无法合并。
     *
     * @return array<int, string>
     */
    private function checkNoPathsFilter(string $projectRoot, string $agentsDoc): array
    {
        $violations = [];

        // 文档侧的名单也要钉住：少列一份 = 少守一份
        $documented = self::extractPathlessWorkflowsFromDoc($agentsDoc, $violations);
        if ($documented !== self::PATHLESS_WORKFLOWS) {
            $missing = array_values(array_diff(self::PATHLESS_WORKFLOWS, $documented));
            $extra = array_values(array_diff($documented, self::PATHLESS_WORKFLOWS));
            $violations[] = sprintf(
                'A2 `paths:` 清单漂移：AGENTS.md「人工核对清单」列出的四份工作流是 [%s]，本守卫钉死的是 [%s]。'
                . '文档缺少：%s；文档多出：%s —— 以 R40 的结论为准改文档（这三份配置类门禁 + migrations-check 必须对每个 PR 都有结论），'
                . '要新增/移除被守的文件请显式修改本测试的 PATHLESS_WORKFLOWS。',
                implode(' / ', $documented),
                implode(' / ', self::PATHLESS_WORKFLOWS),
                $missing === [] ? '（无）' : implode(' / ', $missing),
                $extra === [] ? '（无）' : implode(' / ', $extra)
            );
        }

        foreach (self::PATHLESS_WORKFLOWS as $workflowPath) {
            $yaml = self::readProjectFile($projectRoot, $workflowPath, $violations, 'A2 paths 回归');
            if ($yaml === null) {
                continue;
            }

            $count = self::countMatchingLines($yaml, '/^[ \t]*paths:/');
            if ($count !== 0) {
                $violations[] = sprintf(
                    'A2 `paths:` 回归：%s 里仍有 %d 行 `paths:` 过滤（AGENTS.md「人工核对清单」要求为 0，R40 已删除）—— '
                    . '带 `paths:` 的 required check 在未命中路径的 PR 上不会触发，GitHub 会永久停在 `Expected`，PR 无法合并。'
                    . '请删掉这份 workflow 的 paths 过滤，不要改 AGENTS.md 的数字。',
                    $workflowPath,
                    $count
                );
            }
        }

        // AGENTS.md「它为什么不会被「跳过」」还单独点了 backend-php-cs-fixer.yml 的 paths 计数应为 0
        $backendCi = self::extractBackendCiFromDoc($agentsDoc, $violations);
        if ($backendCi !== null) {
            $yaml = self::readProjectFile($projectRoot, $backendCi, $violations, 'A2 paths 回归');
            if ($yaml !== null) {
                $count = self::countMatchingLines($yaml, '/^[ \t]*paths:/');
                if ($count !== 0) {
                    $violations[] = sprintf(
                        'A2 `paths:` 回归：%s 里有 %d 行 `paths:` 过滤，但 AGENTS.md「它为什么不会被跳过」一节点名这份 workflow 的计数应为 0 —— '
                        . '该 job 同时挂着 `PHPUnit Tests`，带 paths 时未命中路径的 PR 拿不到测试结论。请删掉 paths 过滤。',
                        $backendCi,
                        $count
                    );
                }
            }
        }

        return $violations;
    }

    /**
     * 从 AGENTS.md「人工核对清单」里取出「不再有 paths:」那句点名的四份工作流。
     *
     * @param array<int, string> $violations
     * @return array<int, string>
     */
    private static function extractPathlessWorkflowsFromDoc(string $agentsDoc, array &$violations): array
    {
        foreach (preg_split('/\R/u', $agentsDoc) ?: [] as $line) {
            if (strpos($line, '不再有') === false || strpos($line, '.github/workflows/') === false) {
                continue;
            }

            $paths = self::extractWorkflowPathsFromLine($line);
            if ($paths !== []) {
                return $paths;
            }
        }

        $violations[] = 'A2 `paths:` 清单漂移：AGENTS.md 里找不到「已确认四份工作流……**不再有** `paths:`」这一条核对项 —— '
            . '该核对项是 R40 结论的唯一记录，请恢复它（四份：docs-check / phpunit-config-check / env-check / migrations-check）。';

        return [];
    }

    /**
     * 从 AGENTS.md「它为什么不会被「跳过」」那条 `grep -cE '^[[:space:]]*paths:' <file>` 里取出 workflow 路径。
     *
     * @param array<int, string> $violations
     */
    private static function extractBackendCiFromDoc(string $agentsDoc, array &$violations): ?string
    {
        foreach (preg_split('/\R/u', $agentsDoc) ?: [] as $line) {
            if (strpos($line, '不声明') === false || strpos($line, 'paths:') === false) {
                continue;
            }

            $paths = self::extractWorkflowPathsFromLine($line);
            if ($paths !== []) {
                return $paths[0];
            }
        }

        $violations[] = 'A2 `paths:` 清单漂移：AGENTS.md 里找不到「不声明 `paths:`（grep -cE ... 应为 0）」这条说明 —— '
            . '它是「PHPUnit Tests 不会被 paths 过滤吞掉」的依据，请恢复它并写明 workflow 路径。';

        return null;
    }

    // -----------------------------------------------------------------------
    // A3：`SCAN_FILES` 名单双向一致
    // -----------------------------------------------------------------------

    /**
     * A3：AGENTS.md §4「检查 0」列的根目录文档清单 ↔ `scripts/check-docs.php` 的 `SCAN_FILES`。
     *
     * 双向断言（多一个、少一个都红）+ 顺序一致（顺便固定顺序，避免无意义抖动）。
     * 另外确认 `SCAN_FILES_EXEMPT` 仍登记着 `README.local-merge-backup.md`：
     * AGENTS.md 描述的「根目录 md 要么进扫描、要么进豁免」这套机制里，该本地备份只能靠豁免。
     *
     * @return array<int, string>
     */
    private function checkScanFilesList(string $projectRoot, string $agentsDoc): array
    {
        $violations = [];
        $scriptPath = 'scripts/check-docs.php';
        $script = self::readProjectFile($projectRoot, $scriptPath, $violations, 'A3 SCAN_FILES');
        if ($script === null) {
            return $violations;
        }

        $codeFiles = self::extractStringListFromConst($script, 'SCAN_FILES');
        if ($codeFiles === []) {
            $violations[] = sprintf(
                'A3 SCAN_FILES：%s 里没有解析到 `const SCAN_FILES = [...];` 的字符串清单（已被改写/改名？）—— '
                . '本守卫用它作为权威来源，找不到就必须先修这里，否则「检查 0」到底扫哪些根目录文档无从核对。',
                $scriptPath
            );
            return $violations;
        }

        $documented = self::extractScanFilesFromDoc($agentsDoc, $violations);
        if ($documented === []) {
            return $violations;
        }

        if ($documented !== $codeFiles) {
            $missing = array_values(array_diff($codeFiles, $documented));
            $extra = array_values(array_diff($documented, $codeFiles));

            if ($missing === [] && $extra === []) {
                // 元素相同、只是顺序不同：同样报出来，避免无意义抖动掩盖真正的增删
                $violations[] = sprintf(
                    'A3 SCAN_FILES 顺序不一致：AGENTS.md 列的是 [%s]，%s 的 const SCAN_FILES 是 [%s]。'
                    . '两者元素相同但顺序不同 —— 这里刻意固定顺序（与代码一致），避免每次重排都产生噪音 diff。',
                    implode(' / ', $documented),
                    $scriptPath,
                    implode(' / ', $codeFiles)
                );
            } else {
                $violations[] = sprintf(
                    'A3 SCAN_FILES 名单漂移：AGENTS.md §4「检查 0」列的是 [%s]，%s 的 const SCAN_FILES 是 [%s]。'
                    . '文档缺少：%s；文档多出：%s。以代码为准改 AGENTS.md（check-docs 用的是代码里的清单）；'
                    . '若反过来改代码，等于改扫描范围，必须同时改文档 —— 不能只改一边。',
                    implode(' / ', $documented),
                    $scriptPath,
                    implode(' / ', $codeFiles),
                    $missing === [] ? '（无）' : implode(' / ', $missing),
                    $extra === [] ? '（无）' : implode(' / ', $extra)
                );
            }
        }

        $exemptKeys = self::extractArrayKeysFromConst($script, 'SCAN_FILES_EXEMPT');
        if (!in_array('README.local-merge-backup.md', $exemptKeys, true)) {
            $violations[] = sprintf(
                'A3 SCAN_FILES_EXEMPT：%s 的 const SCAN_FILES_EXEMPT 里没有 README.local-merge-backup.md（现有豁免：%s）—— '
                . 'AGENTS.md §4「检查 0」描述的机制是「根目录 md 要么进 SCAN_FILES、要么进豁免清单，否则报错」，'
                . '而该文件是本地合并备份（见 .gitignore），只能靠豁免；豁免被删后本地跑 check-docs 会对它报「未纳入扫描」，'
                . 'CI 干净检出里又没有这个文件 —— 两边结论不同。要下线该豁免请显式修改本测试并在文档里说明。',
                $scriptPath,
                $exemptKeys === [] ? '（空）' : implode(' / ', $exemptKeys)
            );
        }

        return $violations;
    }

    /**
     * 从 AGENTS.md §4「检查 0」那句里取出登记在 `SCAN_FILES` 的根目录文档名。
     *
     * @param array<int, string> $violations
     * @return array<int, string>
     */
    private static function extractScanFilesFromDoc(string $agentsDoc, array &$violations): array
    {
        $marker = '`SCAN_FILES` 登记的根目录文档';
        $position = strpos($agentsDoc, $marker);
        if ($position === false) {
            $violations[] = 'A3 SCAN_FILES：AGENTS.md §4「检查 0」里找不到「`SCAN_FILES` 登记的根目录文档」这句话 —— '
                . '扫描范围（哪些根目录 md 受校验）就失去了文档记录，请恢复该句并列出文件名。';
            return [];
        }

        // 只截紧随其后的一小段（约两行），避免把 §4 其它清单吸进来
        $tail = substr($agentsDoc, $position, 400);
        $closing = strpos($tail, '）');
        $segment = $closing === false ? $tail : substr($tail, 0, $closing);

        preg_match_all('/`([A-Za-z0-9._-]+\.md)`/u', $segment, $matches);
        $files = $matches[1];

        if ($files === []) {
            $violations[] = 'A3 SCAN_FILES：AGENTS.md §4「检查 0」提到了 `SCAN_FILES`，但其后的括号里没有解析到任何 `*.md` 文件名 —— '
                . '请按「（`AGENTS.md` / `CLAUDE.md` / `README.md` / `README.local.md`）」的形态列出全部登记文件。';
        }

        return $files;
    }

    // -----------------------------------------------------------------------
    // A4：phpunit 套件名单
    // -----------------------------------------------------------------------

    /**
     * A4：AGENTS.md 声称 `phpunit.xml` 共五个套件，且 `Legacy` 在其中。
     *
     * `Legacy` 缺失会让整个守卫组空转 —— 这正是历史事故（「已纳入 CI」是假的）。
     *
     * @return array<int, string>
     */
    private function checkPhpunitSuites(string $projectRoot, string $agentsDoc): array
    {
        $violations = [];
        $xml = self::loadPhpunitXml($projectRoot, $violations);
        if ($xml === null) {
            return $violations;
        }

        $actual = [];
        foreach ($xml->xpath('//testsuite') ?: [] as $node) {
            $actual[] = (string) $node['name'];
        }

        if ($actual === []) {
            $violations[] = sprintf(
                '%s 里没有任何 `<testsuite name="...">` —— 套件全被删空后 php think test 只会打印 No tests executed!，'
                . '请恢复 Unit / Feature / Integration / E2E / Legacy 五个套件。',
                self::PHPUNIT_XML
            );
            return $violations;
        }

        $documented = self::extractPhpunitSuitesFromDoc($agentsDoc, $violations);

        if ($documented !== [] && $documented !== $actual) {
            $missing = array_values(array_diff($actual, $documented));
            $extra = array_values(array_diff($documented, $actual));
            $violations[] = sprintf(
                'A4 phpunit 套件名单漂移：AGENTS.md §Testing Guidelines 写的是 [%s]，%s 里实际的 <testsuite name> 是 [%s]。'
                . '文档缺少：%s；文档多出：%s。以 phpunit.xml 为准同步 AGENTS.md；'
                . '若反过来改动套件，请确认 CI 里 `--testsuite=` 的取值也同步（套件名写错不会报错，只会一条都不跑）。',
                implode(' / ', $documented),
                self::PHPUNIT_XML,
                implode(' / ', $actual),
                $missing === [] ? '（无）' : implode(' / ', $missing),
                $extra === [] ? '（无）' : implode(' / ', $extra)
            );
        }

        if (!in_array('Legacy', $actual, true)) {
            $violations[] = sprintf(
                'A4 phpunit 套件名单漂移：%s 里没有 <testsuite name="Legacy"> —— '
                . 'tests/Legacy 下的静态守卫（含本文件）将完全跑不到：CI 仍会绿，但一个字节都没扫过，'
                . '「已纳入 CI」变成假话。请把 Legacy 套件加回 phpunit.xml，不要改 AGENTS.md。',
                self::PHPUNIT_XML
            );
        }

        if ($documented !== [] && !in_array('Legacy', $documented, true)) {
            $violations[] = 'A4 phpunit 套件名单漂移：AGENTS.md §Testing Guidelines 的套件名单里没有 `Legacy` —— '
                . '漏写会让下一个人以为 tests/Legacy 不受任何套件覆盖，请与 phpunit.xml 对齐。';
        }

        // 文档里「共 N 个套件」的中文数字也要与实际数量一致（只识别简单的 一..十，识别不到就跳过，宁可少断言）
        if (preg_match('/共([一二三四五六七八九十]+)个套件/u', $agentsDoc, $matches) === 1) {
            $number = self::chineseNumeralToInt($matches[1]);
            if ($number !== null && $number !== count($actual)) {
                $violations[] = sprintf(
                    'A4 phpunit 套件数量漂移：AGENTS.md 写「共%s个套件」，但 %s 里实际有 %d 个（%s）—— '
                    . '数字与名单必须一起改。',
                    $matches[1],
                    self::PHPUNIT_XML,
                    count($actual),
                    implode(' / ', $actual)
                );
            }
        }

        return $violations;
    }

    /**
     * 从 AGENTS.md 里取出「`phpunit.xml` 共 N 个套件：`Unit` / ... / `Legacy`」这句的名单。
     *
     * @param array<int, string> $violations
     * @return array<int, string>
     */
    private static function extractPhpunitSuitesFromDoc(string $agentsDoc, array &$violations): array
    {
        foreach (preg_split('/\R/u', $agentsDoc) ?: [] as $line) {
            if (strpos($line, '套件') === false || strpos($line, '`Unit`') === false) {
                continue;
            }

            $keyword = strpos($line, '套件');
            $colon = $keyword === false ? false : strpos($line, '：', $keyword);
            $segment = $colon === false ? $line : substr($line, $colon + strlen('：'));

            preg_match_all('/`([A-Za-z0-9_]+)`/u', $segment, $matches);
            if ($matches[1] !== []) {
                return $matches[1];
            }
        }

        $violations[] = 'A4 phpunit 套件名单漂移：AGENTS.md §Testing Guidelines 里找不到「`phpunit.xml` 共 N 个套件：'
            . '`Unit` / `Feature` / `Integration` / `E2E` / `Legacy`」这句描述 —— 套件名单没有记录处时，'
            . 'Legacy 再次被移出套件就没人会注意到（历史事故：守卫长期空转还显示「已纳入 CI」）。请恢复该句。';

        return [];
    }

    // -----------------------------------------------------------------------
    // A5：`failOnEmptyTestSuite`
    // -----------------------------------------------------------------------

    /**
     * A5：AGENTS.md 声称 `phpunit.xml` 已开 `failOnEmptyTestSuite`。
     *
     * 关闭它，「filter 写错 / 套件名写错 / 目录被挪走」都会打印 `No tests executed!` 却 exit 0。
     *
     * @return array<int, string>
     */
    private function checkFailOnEmptyTestSuite(string $projectRoot): array
    {
        $violations = [];
        $xml = self::loadPhpunitXml($projectRoot, $violations);
        if ($xml === null) {
            return $violations;
        }

        $actual = (string) $xml['failOnEmptyTestSuite'];
        if (strtolower($actual) !== 'true') {
            $violations[] = sprintf(
                'A5 failOnEmptyTestSuite 未开启：%s 根元素的 failOnEmptyTestSuite 实际是「%s」—— '
                . 'AGENTS.md §Testing Guidelines 声称已开启，而这正是「一条都没跑必须变红」的唯一保障：'
                . '关闭后 filter 写错、套件名写错、目录被挪走、用例被删空都会打印 No tests executed! 之后 exit 0。'
                . '请把 phpunit.xml 改回 failOnEmptyTestSuite="true"，不要改 AGENTS.md。',
                self::PHPUNIT_XML,
                $actual === '' ? '(缺失)' : $actual
            );
        }

        return $violations;
    }

    // -----------------------------------------------------------------------
    // A6：required check 对应的 job 一律没有 `needs`
    // -----------------------------------------------------------------------

    /**
     * A6：AGENTS.md 写「该 job **没有 `needs:`**（因此不会被上游跳过）」—— 这条要求对
     * **每一个** required check 都成立，不只是 `phpunit`。
     *
     * 为什么：required 的语义是「必须出现且 success」。一挂 `needs`，上游 job 一失败/被跳过，
     * 本 job 就变成 skipped —— 而 skipped 不等于 success，于是该 check 永久 pending，PR 永远合不进去。
     * 这与 R40 修掉的「带 `paths:` 的 required check 永久 `Expected`」是同一类故障。
     *
     * 历史：`phpunit` 曾是 `needs: [php-cs-fixer, lang-check]`，而风格检查恒红 → phpunit 被判 skipped
     * → 测试信号整段丢失。此前本守卫只钉 `phpunit` 这一个 job，于是 `dependency-audit` 这类新门禁
     * 被加上 `needs:` 时没有任何测试会红；现在改成遍历 REQUIRED_GATES 的 8 条，逐条给出独立违规。
     *
     * @return array<int, string>
     */
    private function checkRequiredGatesHaveNoNeeds(string $projectRoot): array
    {
        $violations = [];

        // 缓存交给 loadWorkflow()（按 workflow 路径缓存解析结果）：
        // backend-php-cs-fixer.yml 有 3 个 required job、migrations-check.yml 有 2 个，
        // 同一份文件不必解析三遍。
        foreach (self::REQUIRED_GATES as $checkName => [$workflowPath, $jobId]) {
            $workflow = $this->loadWorkflow($projectRoot, $workflowPath, $violations, 'A6 required check 的 needs');
            if ($workflow === null) {
                continue;
            }

            $job = self::workflowJobDefinition($workflow, $jobId);
            if ($job === null) {
                // 定位不到 job 时**不许静默跳过**：解析不出 job 就核对不了 needs，静默通过等于「非空即通过」。
                // A1 也会报同一条（那里同样给出 check 名称），这里再报一次是为了让「A6 到底守住了没有」自成一体。
                $violations[] = sprintf(
                    'A6 required check 的 needs：%s 的 `jobs:` 下找不到 job id「%s」（该 job 的 check 名称应为「%s」，现有 job：%s）—— '
                    . 'job 被改名/删除时，这里就核对不了它有没有 `needs:`。'
                    . '请恢复 job 或同步更新本测试的 REQUIRED_GATES；不要靠删掉这条断言来变绿。',
                    $workflowPath,
                    $jobId,
                    $checkName,
                    implode(' / ', self::workflowJobIds($workflow))
                );
                continue;
            }

            // 「有没有 needs」是**键是否存在**，不是行数：
            // `needs: phpunit`（字符串）只占一行、`needs: [a, b]`（数组）也是一行，
            // 靠数行数/数出现次数会把第一种写法整个漏掉，所以直接看键。
            if (!array_key_exists('needs', $job)) {
                continue;
            }

            $needs = (string) json_encode($job['needs'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            $violations[] = sprintf(
                'A6 required check 的 needs：「%s」这个 required check 对应的 %s 的 jobs.%s 显式声明了 job 级 `needs:`（值为 %s）—— '
                . 'required 的语义是「必须出现且 success」：一挂 needs，上游 job 一失败/被跳过，jobs.%s 就变 skipped，'
                . '而 skipped 不等于 success，于是该 check 永久停在 pending，PR 永远合不进去'
                . '（与 R40 修掉的「带 `paths:` 的 required check 永久 `Expected`」是同一类故障）。'
                . '改哪里：改回 workflow —— 删掉 jobs.%s 的 needs；需要「都通过才放行」请用分支保护的 required status checks 表达，'
                . '不要用 job 之间的依赖链。不要改本测试来放宽断言。',
                $checkName,
                $workflowPath,
                $jobId,
                $needs,
                $jobId,
                $jobId
            );
        }

        return $violations;
    }

    // -----------------------------------------------------------------------
    // A7：`TEMPLATE_REQUIRED_MIN_KEYS` 的「6」
    // -----------------------------------------------------------------------

    /**
     * A7：`scripts/check-env-example.php` 的 `TEMPLATE_REQUIRED_MIN_KEYS`
     * 与 AGENTS.md 里「当前 6」两处镜像必须一致（且当前应为 6）。
     *
     * @return array<int, string>
     */
    private function checkTemplateMinKeys(string $projectRoot, string $agentsDoc): array
    {
        $violations = [];
        $scriptPath = 'scripts/check-env-example.php';
        $script = self::readProjectFile($projectRoot, $scriptPath, $violations, 'A7 TEMPLATE_REQUIRED_MIN_KEYS');
        if ($script === null) {
            return $violations;
        }

        $codeValue = null;
        if (preg_match('/const\s+TEMPLATE_REQUIRED_MIN_KEYS\s*=\s*(\d+)\s*;/', $script, $matches) === 1) {
            $codeValue = (int) $matches[1];
        } else {
            $violations[] = sprintf(
                'A7 TEMPLATE_REQUIRED_MIN_KEYS：%s 里找不到 `const TEMPLATE_REQUIRED_MIN_KEYS = <数字>;` —— '
                . '下限常量被改名/删除后，检查 D0 的 [CATALOG-SHRUNK] 断言也随之消失（键集合被删到只剩一两个也照样绿）。'
                . '请恢复该常量，并同步 AGENTS.md「当前 6」。',
                $scriptPath
            );
        }

        $docValue = null;
        foreach (preg_split('/\R/u', $agentsDoc) ?: [] as $line) {
            if (strpos($line, 'TEMPLATE_REQUIRED_MIN_KEYS') === false || strpos($line, '当前') === false) {
                continue;
            }
            if (preg_match('/当前\s*(\d+)/u', $line, $matches) === 1) {
                $docValue = (int) $matches[1];
                break;
            }
        }

        if ($docValue === null) {
            $violations[] = sprintf(
                'A7 TEMPLATE_REQUIRED_MIN_KEYS：AGENTS.md 里找不到「`count(template_required) >= TEMPLATE_REQUIRED_MIN_KEYS`，当前 N」'
                    . '这句（或其中的「当前 N」数字）—— 下限值没有文档记录时，改常量的人不会知道文档也要改。'
                    . '请在 AGENTS.md §合并前必须通过的配置类门禁 的 D0 段落里写明「当前 %d」。',
                self::EXPECTED_TEMPLATE_MIN_KEYS
            );
        }

        if ($codeValue !== null && $docValue !== null && $codeValue !== $docValue) {
            $violations[] = sprintf(
                'A7 TEMPLATE_REQUIRED_MIN_KEYS 镜像漂移：%s 的常量是 %d，AGENTS.md 写「当前 %d」—— '
                . '两处必须一致。以脚本为准改文档；若是真要下调下限，请同时改「脚本常量 + 文档数字 + 本测试的 EXPECTED_TEMPLATE_MIN_KEYS」三处。',
                $scriptPath,
                $codeValue,
                $docValue
            );
        }

        if ($codeValue !== null && $codeValue !== self::EXPECTED_TEMPLATE_MIN_KEYS) {
            $violations[] = sprintf(
                'A7 TEMPLATE_REQUIRED_MIN_KEYS 值变更：%s 的常量现在是 %d，本守卫记录的期望值是 %d —— '
                . '下限的存在意义是「逼人显式表态」：真要下线某个键，必须同时把下限改小，并让这次改动出现在 diff 里。'
                . '确认无误后请同时更新 AGENTS.md「当前 %d」与本测试的 EXPECTED_TEMPLATE_MIN_KEYS。',
                $scriptPath,
                $codeValue,
                self::EXPECTED_TEMPLATE_MIN_KEYS,
                $codeValue
            );
        }

        return $violations;
    }

    // -----------------------------------------------------------------------
    // 通用只读辅助方法
    // -----------------------------------------------------------------------

    /**
     * 读取仓库内文件；失败时向 $violations 追加一条可操作信息并返回 null。
     *
     * @param array<int, string> $violations
     */
    private static function readProjectFile(
        string $projectRoot,
        string $relativePath,
        array &$violations,
        string $group
    ): ?string {
        $fullPath = $projectRoot . DIRECTORY_SEPARATOR . $relativePath;

        if (!is_file($fullPath)) {
            $violations[] = sprintf(
                '%s：文件不存在 —— %s。本守卫断言的事实全部来自该文件，被移动/改名后必须同步更新本测试与 AGENTS.md。',
                $group,
                $relativePath
            );
            return null;
        }

        $contents = @file_get_contents($fullPath);
        if ($contents === false) {
            $violations[] = sprintf('%s：无法读取 %s（权限或 IO 问题）', $group, $relativePath);
            return null;
        }

        return $contents;
    }

    /**
     * 统计匹配某正则的行数（逐行匹配，避免跨行模式误伤）。
     */
    private static function countMatchingLines(string $contents, string $pattern): int
    {
        $count = 0;
        foreach (preg_split('/\R/u', $contents) ?: [] as $line) {
            if (preg_match($pattern, $line) === 1) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * 从一行文本里取出所有 `.github/workflows/*.yml` 路径（去重，保留出现顺序）。
     *
     * @return array<int, string>
     */
    private static function extractWorkflowPathsFromLine(string $line): array
    {
        preg_match_all('/\.github\/workflows\/[A-Za-z0-9._-]+\.yml/u', $line, $matches);

        return array_values(array_unique($matches[0]));
    }

    /**
     * 从 PHP 源码里取出某数组常量（形如 `const X = ['a', 'b'];`）的字符串元素。
     *
     * @return array<int, string>
     */
    private static function extractStringListFromConst(string $source, string $constName): array
    {
        $pattern = '/const\s+' . preg_quote($constName, '/') . '\s*=\s*\[(.*?)\];/s';
        if (preg_match($pattern, $source, $matches) !== 1) {
            return [];
        }

        preg_match_all("/'([^']*)'/", $matches[1], $items);

        return $items[1];
    }

    /**
     * 从 PHP 源码里取出某数组常量（形如 `const X = ['k' => 'v'];`）的键名。
     *
     * @return array<int, string>
     */
    private static function extractArrayKeysFromConst(string $source, string $constName): array
    {
        $pattern = '/const\s+' . preg_quote($constName, '/') . '\s*=\s*\[(.*?)\];/s';
        if (preg_match($pattern, $source, $matches) !== 1) {
            return [];
        }

        preg_match_all("/'([^']*)'\s*=>/", $matches[1], $keys);

        return $keys[1];
    }

    /**
     * 解析 phpunit.xml 为 SimpleXML。
     *
     * 为什么用 XML 解析而不是正则：phpunit.xml 里大段注释中出现了 `failOnEmptyTestSuite`、
     * `<testsuite ...>` 这类字样，正则会把注释内容一起匹配进来（本仓库已经出现过「注释里写的例子
     * 被当成真实配置」的误判）。
     *
     * @param array<int, string> $violations
     */
    private static function loadPhpunitXml(string $projectRoot, array &$violations): ?\SimpleXMLElement
    {
        $contents = self::readProjectFile($projectRoot, self::PHPUNIT_XML, $violations, 'phpunit.xml');
        if ($contents === null) {
            return null;
        }

        $previous = libxml_use_internal_errors(true);
        $xml = simplexml_load_string($contents);
        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if ($xml === false) {
            $violations[] = sprintf(
                'phpunit.xml 无法解析为 XML：%s —— 配置写坏后 php think test 与 CI 都会直接失败，请先修好 XML。',
                isset($errors[0]) ? trim($errors[0]->message) : '未知 XML 错误'
            );
            return null;
        }

        return $xml;
    }

    /**
     * 读取并解析一份 workflow YAML（按文件缓存）；失败时向 $violations 追加一条可操作信息并返回 null。
     *
     * 为什么要真解析：`name:` / `needs:` 的**归属**由 YAML 结构决定，与缩进列数无关。
     * 旧实现按「job id 两空格、job 级键一律四空格」切文本，一旦某个 job 的键改成两空格缩进，
     * `needs:` 就被数成 0 处 → A6 静默放行（假绿，比假红危险得多）。真解析之后，
     * 同一份文件里的缩进是两格、四格还是 flow style，结论都一样。
     *
     * 解析失败必须**变违规**而不是把异常抛出去：抛出去会让用例 error（error 的语义是「守卫自己坏了」），
     * 而守卫崩溃等于连合法 YAML 都拦不住 —— 那正好取消了本守卫存在的理由。
     *
     * @param array<int, string> $violations
     * @return array<string, mixed>|null null = 读不到 / 解析失败（原因已进 $violations）
     */
    private function loadWorkflow(
        string $projectRoot,
        string $workflowPath,
        array &$violations,
        string $group
    ): ?array {
        // 命中缓存直接复用结论：backend-php-cs-fixer.yml 会被 A1（逐行 + 钉死清单）与 A6 读三遍，
        // 失败过的文件也只在第一次报违规（重复报同一条会把真正的第一条淹掉）。
        if (array_key_exists($workflowPath, $this->workflowCache)) {
            return $this->workflowCache[$workflowPath];
        }

        $fullPath = $projectRoot . DIRECTORY_SEPARATOR . $workflowPath;
        if (!is_file($fullPath)) {
            $violations[] = sprintf(
                '%s：文件不存在 —— %s。本守卫断言的事实全部来自该文件，被移动/改名后必须同步更新本测试与 AGENTS.md。',
                $group,
                $workflowPath
            );

            return $this->workflowCache[$workflowPath] = null;
        }

        try {
            $parsed = Yaml::parseFile($fullPath);
        } catch (ParseException $exception) {
            $violations[] = sprintf(
                '%s：%s 不是合法 YAML，解析失败：%s —— '
                . '写坏的 workflow 在 GitHub 上整个文件一次都不会触发，本守卫要核对的 check 名称与 job 结构随之全部作废。'
                . '改哪里：修 workflow 的 YAML 语法（重点看缩进、未闭合的 `[` / `{` / 引号、块标量 `|` / `>` 的缩进），'
                . '修好后本条会自动消失；不要为了让守卫变绿而删掉断言。',
                $group,
                $workflowPath,
                $exception->getMessage()
            );

            return $this->workflowCache[$workflowPath] = null;
        } catch (\Throwable $exception) {
            // 兜底：ParseException 之外的异常（YAML 组件大版本改了 API、文件级 IO 问题等）。
            // 同样降级成违规 —— 守卫绝不能以 error 收场。
            $violations[] = sprintf(
                '%s：%s 的 YAML 解析抛出非预期异常（%s：%s）—— '
                . 'parseFile() 正常只抛 ParseException，出现别的异常说明解析链路本身变了，请人工确认后再继续依赖本断言。',
                $group,
                $workflowPath,
                get_class($exception),
                $exception->getMessage()
            );

            return $this->workflowCache[$workflowPath] = null;
        }

        // 顶层必须是键值映射：GitHub Actions 只接受 `name:` / `on:` / `jobs:` 这种结构。
        // 顶层被写成列表（`- xxx`）或单个标量时按 `jobs` 取键必然取不到，与其静默报「没有 job」，
        // 不如直接说清是顶层结构坏了。
        if (!is_array($parsed) || ($parsed !== [] && array_is_list($parsed))) {
            $violations[] = sprintf(
                '%s：%s 的顶层不是键值映射（实际解析成 %s）—— '
                . 'workflow 顶层必须是 `name:` / `on:` / `jobs:` 这样的映射，写成列表或标量时无法按 job id 取键。'
                . '改哪里：修 workflow 顶层结构（常见成因是顶层多缩进了一层，或漏了键名）。',
                $group,
                $workflowPath,
                get_debug_type($parsed)
            );

            return $this->workflowCache[$workflowPath] = null;
        }

        return $this->workflowCache[$workflowPath] = $parsed;
    }

    /**
     * 取 workflow 的顶层 `name:`；缺失或不是字符串/数字时返回 null。
     *
     * ⚠️ 关于 GitHub Actions 的顶层 `on:`：用 symfony/yaml 7.4 实测，它**仍被解析成字符串键 `'on'`**
     * （值为映射），没有变成 YAML 1.1 语义下的布尔 `true`（那样 PHP 数组键会变成整数 1）。
     * 本守卫的 A1 / A6 只读 `name` / `jobs`，不受这一点影响；但将来若有断言要按 `on` 取键，
     * 换解析器或升大版本时必须重新实测，否则会静默拿到 null 而不是报错。
     *
     * @param array<string, mixed> $workflow
     */
    private static function workflowTopLevelName(array $workflow): ?string
    {
        return self::yamlScalarText($workflow['name'] ?? null);
    }

    /**
     * 取 workflow 的全部 job id（仅用于失败信息里提示「现有 job 有哪些」）。
     *
     * @param array<string, mixed> $workflow
     * @return array<int, string>
     */
    private static function workflowJobIds(array $workflow): array
    {
        $jobs = $workflow['jobs'] ?? null;
        if (!is_array($jobs)) {
            return [];
        }

        return array_map(static fn (int|string $jobId): string => (string) $jobId, array_keys($jobs));
    }

    /**
     * 取某个 job 的 job 级键值映射；`jobs:` 缺失、不是映射或没有该 job id 时返回 null。
     *
     * job id 存在但定义不是映射时（例如 `phpunit: PHPUnit Tests`，GitHub Actions 本身也不接受）
     * 返回空数组：这种 job 必然没有 `name:`，A1 的 name 断言会把它判红，这里不做二次猜测。
     *
     * @param array<string, mixed> $workflow
     * @return array<string, mixed>|null
     */
    private static function workflowJobDefinition(array $workflow, string $jobId): ?array
    {
        $jobs = $workflow['jobs'] ?? null;
        if (!is_array($jobs) || !array_key_exists($jobId, $jobs)) {
            return null;
        }

        $job = $jobs[$jobId];

        return is_array($job) ? $job : [];
    }

    /**
     * 取 job 的 job 级 `name:`；缺失或不是字符串/数字时返回 null。
     *
     * 真解析之后不必再区分缩进层级：`steps[].name` / `artifacts.name` 属于**别的路径**，
     * 根本不会出现在 job 这一层的键里。
     *
     * @param array<string, mixed> $job
     */
    private static function workflowJobName(array $job): ?string
    {
        return self::yamlScalarText($job['name'] ?? null);
    }

    /**
     * 把 YAML 解析出的标量转成字符串；null / 布尔 / 数组一律返回 null（宁可报「没有 name」，也不猜）。
     */
    private static function yamlScalarText(mixed $value): ?string
    {
        return is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
    }

    /**
     * 中文数字（一..十）转整数；识别不了返回 null（宁可少断言，也不误红）。
     */
    private static function chineseNumeralToInt(string $numeral): ?int
    {
        $map = [
            '一' => 1,
            '二' => 2,
            '三' => 3,
            '四' => 4,
            '五' => 5,
            '六' => 6,
            '七' => 7,
            '八' => 8,
            '九' => 9,
            '十' => 10,
        ];

        return $map[$numeral] ?? null;
    }
}
