<?php

declare(strict_types=1);

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(__DIR__)
    ->exclude('vendor')
    ->exclude('runtime')
    ->exclude('node_modules')
    ->exclude('storage')
    // docs/Example 是文档示例代码，且被 .gitignore 忽略（第 43 行 `/docs/Example`），
    // CI checkout 里根本不存在。它内部还套着两个独立的 git 仓库
    // （docs/Example/ingenious 与 docs/Example/ingenious/demo），
    // 若纳入 finder，本地一次 fix 会改写这两个「别人的仓库」的工作区，
    // 而主仓库完全看不见这些改动（git diff 为空），属于隐形副作用。
    // 更关键的是会造成「本地检查 931 个 / CI 只检查 669 个」的口径分裂 ——
    // 「本地绿」不再等于「CI 绿」。排除后两者对齐，门禁才有意义。
    ->notPath('docs/Example');

return (new Config())
    ->setRiskyAllowed(false)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'no_unused_imports' => true,
        'single_quote' => true,
    ])
    ->setFinder($finder);

