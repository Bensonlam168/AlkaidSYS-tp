<?php

declare(strict_types=1);

use think\migration\Migrator;

/**
 * 清理冒号格式的重复权限码（lowcode:read / lowcode:write / lowcode:delete）
 * Clean up duplicated colon-format permission codes
 *
 * ## 缺陷：同一件事在库里存在两份
 *
 * 开发库 `permissions` 里同时存在：
 * ```
 * id 21  lowcode:read     ← 冒号格式（不符合规范，且不是任何迁移建的）
 * id 22  lowcode:write
 * id 23  lowcode:delete
 * id 32  lowcode.read     ← 点号格式（规范形态：resource.action）
 * id 33  lowcode.write
 * id 34  lowcode.delete
 * ```
 * 两套的 `resource` / `action` 完全相同（`lowcode` / `read` 等），因此**业务语义完全重复**：
 * 授权判定用的是 `resource + ':' + action`（见 `PermissionService::getUserPermissionsFromDatabase()`）
 * 与 Casbin 的 `p.resource` / `p.action`（见 `DatabaseAdapter::loadPermissionAssignments()`），
 * 两者都不读 `permissions.slug` —— 也就是说这 3 行既不影响鉴权结果，也不该存在。
 *
 * 来源已核实：全部 `database/migrations/*.php` 里只建过点号形态，冒号形态是历史测试/人工操作
 * 留下的污染（`scripts/check-permission-codes.php` 只扫 `route/`，扫不到 DB，所以一直没被拦住）。
 *
 * ## 为什么是「新建修复迁移」，而不是一次性 DELETE
 *
 * 1. **可收敛**：任何环境（含生产）只要存在同样的污染，`migrate:run` 就会自动清理；
 *    一次性清理只修当前这台机器，别的库会带着重复行继续跑。
 * 2. **可回溯**：清理动作进了 `migrations` 账本，后人能回答「这 3 行为什么没了」。
 * 3. **与既有修复同构**：`20251225000001_ensure_system_permissions` /
 *    `20251226000001_ensure_dept_menu_permissions` 都是「数据收敛型」修复迁移，本迁移沿用同一套做法。
 *
 * 版本号 `20251227000001`：14 位，且大于开发库已应用的最大值 `20251226000001`
 * （`scripts/check-migrations.php` 检查 B 要求未应用迁移的版本必须更大，否则无参回滚会打错迁移）。
 *
 * ## 顺序：**先转授权，再删行**（不能反）
 *
 * `role_permissions.permission_id` 的外键是 `fk_role_permissions_permission_id ... ON DELETE CASCADE`
 * —— 直接删 `permissions` 行会**静默连带删掉**持有该权限的角色授权。所以必须先确保
 * 「原本持有冒号版的角色」拿到了对应的点号版，再删冒号版。这样删除时被级联掉的只是
 * **重复的那一份**授权，角色的有效权限集合不变。
 *
 * 转授权语句自带 `NOT EXISTS`，与 `role_permissions` 的复合主键 `(role_id, permission_id)` 配合，
 * 使整个 up() **幂等**：重复执行不会重复插入、不会撞主键、也不会报错。
 *
 * ## 全新库 / 干净的库：完全 no-op
 *
 * 这 3 行在全新库和测试库（`alkaid_sys_test`，只有点号版）里**根本不存在**。
 * 因此 up() 对每条 slug 都先用 `SELECT id FROM permissions WHERE slug = ?` 探测：
 * 不存在就打印「跳过」并继续 —— 0 行插入、0 行删除、0 报错，绝不因为「没有可清理的东西」中断迁移链。
 *
 * ## 保守边界：点号版不存在时**不删**
 *
 * 若某环境只有冒号版而缺规范版（理论上不该出现），删除就等于真的删掉了这些角色的权限。
 * 本迁移在这种情况下保留冒号行并打印提示 —— 宁可留一处可查询的污染，也不制造一次静默的授权丢失。
 *
 * @package database/migrations
 */
class CleanupColonFormatLowcodePermissions extends Migrator
{
    /**
     * 历史污染：冒号格式的权限 slug（→ 点号格式，见 up() 里的 str_replace）
     *
     * 【为什么键名不是 slug、值也不是 resource.action 形态】
     *   `scripts/check-migration-data.php` 会从迁移源码的**数组字面量**里提取
     *   `'slug' => 'resource.action'` 作为「应当存在」的数据意图。本迁移的意图恰好相反（**删除**），
     *   所以这里既不用 `slug` 作键，值也是冒号形态（不匹配 `resource.action` 模式），
     *   不会被那个门禁误当成「期望存在的权限」—— 否则清理完反而会被门禁判成缺失。
     *
     * @var string[]
     */
    private array $legacyColonSlugs = [
        'lowcode:read',
        'lowcode:write',
        'lowcode:delete',
    ];

    /**
     * 执行迁移：先转授权、再删除冒号版权限行
     * Run migration: transfer grants first, then delete the colon-format permission rows
     */
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $transferredGrants = 0;
        $deletedRows       = 0;
        $skipped           = [];

        foreach ($this->legacyColonSlugs as $colonSlug) {
            // 点号形态即规范形态：resource.action
            $canonicalSlug = str_replace(':', '.', $colonSlug);

            $colonId = $this->findPermissionId($colonSlug);
            if ($colonId === null) {
                // 全新库 / 已清理库的正常路径：什么都不做（no-op）
                $skipped[] = $colonSlug;
                $this->output->writeln(
                    "<comment>  = 权限 {$colonSlug} 不存在，跳过（干净库的正常路径，不做任何写入）</comment>"
                );
                continue;
            }

            $canonicalId = $this->findPermissionId($canonicalSlug);
            if ($canonicalId === null) {
                // 缺规范版 → 不能删（删了就是真的丢授权），保留并提示人工确认
                $skipped[] = $colonSlug;
                $this->output->writeln(
                    "<comment>  ! 规范权限 {$canonicalSlug} 不存在，保留 {$colonSlug}"
                    . '（不做转授权、不做删除：避免静默丢失授权）</comment>'
                );
                continue;
            }

            // ------------------------------------------------
            // 第 1 步：转授权（必须在删除之前）
            // ------------------------------------------------
            $granted = $this->transferGrants($colonId, $canonicalId, $now);
            $transferredGrants += $granted;

            // ------------------------------------------------
            // 第 2 步：删除冒号版权限行（CASCADE 只会连带删掉「重复的那一份」授权）
            // ------------------------------------------------
            // 同时用 id 与 slug 双条件，避免误删（slug 有唯一索引 idx_slug，双条件等价于更强约束）。
            $removed = $this->execute(
                'DELETE FROM `permissions` WHERE `id` = ? AND `slug` = ?',
                [$colonId, $colonSlug]
            );
            $deletedRows += $removed;

            $this->output->writeln(sprintf(
                '<info>  - 删除权限 %s（id: %d），此前已把 %d 条授权转到 %s（id: %d）</info>',
                $colonSlug,
                $colonId,
                $granted,
                $canonicalSlug,
                $canonicalId
            ));
        }

        $this->output->writeln(sprintf(
            '<info>冒号格式权限清理完成：转授权 %d 条，删除权限行 %d 条，跳过 %d 条（共 %d 条）</info>',
            $transferredGrants,
            $deletedRows,
            count($skipped),
            count($this->legacyColonSlugs)
        ));
    }

    /**
     * 把「持有冒号版权限的角色」补上点号版权限（幂等）
     * Grant the canonical permission to every role that holds the legacy colon one (idempotent)
     *
     * 【幂等性说明】
     *   - `NOT EXISTS` 逐行判断「该角色是否已持有规范版权限」，已持有则跳过；
     *   - `role_permissions` 的主键是 `(role_id, permission_id)`，即使并发下重复执行，
     *     唯一键也会挡住重复行（本迁移由迁移表串行执行，实际不会并发）。
     *   因此「跑第二次」时本条语句的受影响行数必然是 0。
     *
     * 【为什么用 INSERT ... SELECT 而不是先查再插】
     *   一条语句完成「按集合转授权」，不存在「查到一半有人改了」的两步竞态；
     *   与 `20251226000001_ensure_dept_menu_permissions` 的授权写法保持一致。
     *
     * @param int $colonId 冒号版权限 id | Legacy (colon) permission id
     * @param int $canonicalId 点号版权限 id（规范）| Canonical (dot) permission id
     * @param string $now 统一的写入时间戳 | Inserted timestamp
     * @return int 本次新增的授权行数 | Number of inserted grant rows
     */
    private function transferGrants(int $colonId, int $canonicalId, string $now): int
    {
        return $this->execute(
            'INSERT INTO `role_permissions` (`role_id`, `permission_id`, `created_at`)
             SELECT rp.`role_id`, ?, ?
             FROM `role_permissions` rp
             WHERE rp.`permission_id` = ?
               AND NOT EXISTS (
                   SELECT 1 FROM `role_permissions` rp2
                   WHERE rp2.`role_id` = rp.`role_id`
                     AND rp2.`permission_id` = ?
               )',
            [$canonicalId, $now, $colonId, $canonicalId]
        );
    }

    /**
     * 按 slug 查权限 id（不存在返回 null）
     * Resolve a permission id by slug (null when absent)
     *
     * @param string $slug 权限 slug | Permission slug
     * @return int|null 权限 id 或 null | Permission id or null
     */
    private function findPermissionId(string $slug): ?int
    {
        $statement = $this->query('SELECT `id` FROM `permissions` WHERE `slug` = ? LIMIT 1', [$slug]);

        if (!($statement instanceof PDOStatement)) {
            return null;
        }

        $id = $statement->fetchColumn();

        return $id === false ? null : (int) $id;
    }

    /**
     * 回滚迁移：**有意为空操作（no-op）**
     * Rollback migration: intentionally a no-op
     *
     * 【为什么 down() 什么都不做】
     *   1. 本迁移的语义是「把重复数据收敛掉」，不是「新增了一个能力」。
     *      回滚时把 `lowcode:read/write/delete` 三行冒号权限**重新插回来**，
     *      等于主动恢复一处已知污染，`role_permissions` 里还会再多出 3 份重复授权。
     *   2. 迁移没有状态，无法区分「本迁移删掉的行」与「环境里本来就没有的行」：
     *      在干净库上回滚如果没有 up()（no-op）做前提，也可能插出本来不存在的行。
     *   3. up() 本身是幂等的（缺行跳过 / 转授权 NOT EXISTS），
     *      因此 `migrate:rollback` + `migrate:run` 是**安全的空转**：账本进出一趟，数据不变。
     *
     * 【为什么不留空方法体】
     *   留一段显式说明，避免后来者把「看起来什么都没做」当漏写而顺手补一个 INSERT ——
     *   那会把这条迁移变成「回滚即重新制造重复权限」的陷阱。
     *   同时满足 check-migrations 的检查 C（必须实现 up() + down()，且不得写 change()）。
     */
    public function down(): void
    {
        $this->output->writeln(
            '<comment>CleanupColonFormatLowcodePermissions::down() 为有意空操作：'
            . '本迁移只删除重复的冒号格式权限码，回滚时重新插回等于主动恢复已知污染，'
            . '并会在 role_permissions 里再添重复授权。up() 幂等，rollback + run 可安全空转。</comment>'
        );
    }
}
