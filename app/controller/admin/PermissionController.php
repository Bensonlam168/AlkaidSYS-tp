<?php

declare(strict_types=1);

namespace app\controller\admin;

use app\controller\ApiController;
use Domain\Permission\Model\Permission;
use Infrastructure\Permission\Repository\PermissionRepository;
use Infrastructure\Permission\Service\PermissionService;
use think\App;
use think\Response;
use think\facade\Log;

/**
 * Permission Controller | 权限控制器
 *
 * Provides CRUD operations for permission management.
 * 提供权限管理的增删改查操作。
 *
 * ## 权限码（`resource:action`）的两条边界
 *
 * 鉴权中间件与 `PermissionService` 都是按 **`resource` + `action` 拼出的权限码**
 * （形如 `tenant:write`）判断「能不能做」，而 `permissions` 是**全局目录**
 * （该表没有 `tenant_id`，一行被改写会影响所有租户）。因此本控制器对"码"的处理有两条硬边界：
 *
 * 1. **已存在的码不可改写**（`update`）：只允许改 `name` / `description`。
 *    历史实现直接接受 `slug` / `resource` / `action`，于是「持有 `permission.write`」的人
 *    可以把自己**已持有**的那一行（如 `role.write`）改成 `resource=tenant, action=write`，
 *    `role_permissions` 绑定不变，但他立刻持有 `tenant:write` —— **等价提权**。
 * 2. **新建的码必须落在操作者自己的权限集内**（`save`）：否则 `permission.write`
 *    持有者可以往全局目录里塞任意码（如 `tenant.write`），污染所有租户的授权界面。
 *    判定口径与 ER-05 的授予边界**同源**：统一取 `PermissionService::getUserPermissions()`
 *    返回的 `resource:action`（也就是鉴权中间件当场用的那一份），没有第二个真相源。
 *
 * 两条都没用「硬编码超管绕过」：seeded `admin` 角色天然持有全部权限行，
 * 因此对超管来说这两条校验都是**恒真**的（他本来就能持有这些码），无需特判。
 *
 * @package app\controller\admin
 */
class PermissionController extends ApiController
{
    /**
     * 权限码的构成字段：一旦落库即不可变 | Immutable code parts
     *
     * 之所以是这三个：`resource` + `action` 决定中间件认的权限码
     * （`resource:action`），`slug` 是同一份码在外部（前端/文档/审计）的
     * 人类可读投影（`resource.action`）。三者任何一个被改都会让"码"与"身份"脱节。
     *
     * @var array<string>
     */
    protected const IMMUTABLE_CODE_FIELDS = ['slug', 'resource', 'action'];

    protected PermissionRepository $permissionRepository;

    /**
     * 权限服务（操作者权限集的唯一来源，与鉴权中间件同源）
     */
    protected PermissionService $permissionService;

    /**
     * Constructor | 构造函数
     */
    public function __construct(App $app)
    {
        parent::__construct($app);
        $this->permissionRepository = new PermissionRepository();
        $this->permissionService = new PermissionService();
    }

    /**
     * List permissions | 权限列表
     *
     * GET /v1/admin/permissions
     *
     * Query Parameters:
     * - page: int (default: 1)
     * - pageSize: int (default: 20)
     * - name: string (optional, fuzzy search)
     * - slug: string (optional, fuzzy search)
     * - resource: string (optional, exact match)
     * - action: string (optional, exact match)
     *
     * @return Response
     */
    public function index(): Response
    {
        $page = (int)$this->request->get('page', 1);
        $pageSize = (int)$this->request->get('pageSize', 20);

        // Build filters | 构建筛选条件
        $filters = [];
        $filterFields = ['name', 'slug', 'resource', 'action'];
        foreach ($filterFields as $field) {
            $value = $this->request->get($field);
            if ($value !== null && $value !== '') {
                $filters[$field] = $value;
            }
        }

        // Validate pagination | 验证分页参数
        $page = max(1, $page);
        $pageSize = min(100, max(1, $pageSize));

        $result = $this->permissionRepository->list($filters, $page, $pageSize);

        return $this->paginate(
            $result['list'],
            $result['total'],
            $result['page'],
            $result['pageSize']
        );
    }

    /**
     * Get single permission | 获取单个权限
     *
     * GET /v1/admin/permissions/:id
     *
     * @param int $id Permission ID | 权限 ID
     * @return Response
     */
    public function read(int $id): Response
    {
        $permission = $this->permissionRepository->findById($id);

        if ($permission === null) {
            return $this->error(
                $this->langService->trans('permission.not_found'),
                404,
                [],
                404
            );
        }

        $data = $permission->toArray();
        $data['roles'] = $this->permissionRepository->getRolesWithPermission($id);

        return $this->success($data);
    }

    /**
     * Create permission | 创建权限
     *
     * POST /v1/admin/permissions
     *
     * Request Body:
     * - name: string (required)
     * - slug: string (required, unique, format: resource.action)
     * - resource: string (optional)
     * - action: string (optional)
     * - description: string (optional)
     *
     * ## 为什么"新建"也要约束（结论见交付报告）
     *
     * 「新建」本身确实不是"改写"（不违反不可变），但它**不是**无害的：
     * `permissions` 是全局目录，谁能写它，谁就能往所有租户的授权界面里塞行。
     * 因此这里要求**新建的码必须落在操作者自身权限集内**（口径与 ER-05 授予边界同源）。
     *
     * 这一条**不会**牺牲任何真实能力：ER-05 之后，一行新权限必须"有人持有该码"才能
     * 被授予任何角色（`RoleController` 的子集校验），而**新建的码任何人都还不持有**
     * —— 也就是说不论有没有本约束，`permission.write` 持有者新建出来的"新码"
     * 都无法被授予、无法带来任何新能力，只会成为目录噪音。若真需要新增一枚码，
     * 正确路径是迁移/种子（本仓库 12 条系统权限就是这么来的）。
     *
     * @return Response
     */
    public function save(): Response
    {
        $data = $this->request->post();

        // Validate required fields | 验证必填字段
        $errors = $this->validatePermissionData($data);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                $errors,
                422
            );
        }

        // Check slug uniqueness | 检查 slug 唯一性
        if ($this->permissionRepository->slugExists($data['slug'])) {
            return $this->error(
                $this->langService->trans('permission.slug_exists'),
                422,
                ['slug' => [$this->langService->trans('permission.slug_exists')]],
                422
            );
        }

        // Auto-extract resource and action from slug | 从 slug 自动提取 resource 和 action
        // 显式传空串与"没传"等价（前端清空输入框时给的是 ''，不能让它落成 NULL 之外的第三种状态）
        $slugParts = explode('.', $data['slug'], 2);
        $resource = $this->normalizeText($data['resource'] ?? null) ?? ($slugParts[0] ?? null);
        $action = $this->normalizeText($data['action'] ?? null) ?? ($slugParts[1] ?? null);

        // 折算不出权限码（resource/action 有一个为空）→ 该行谁都"不持有"，
        // 也永远无法被授予，属于纯脏数据；直接拒绝，而不是落库后再也删不掉。
        if ($this->normalizeText($resource) === null || $this->normalizeText($action) === null) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                ['resource' => [$this->langService->trans('permission.code_required')]],
                422
            );
        }

        // 权限码归属校验：新建的码必须已在操作者自身权限集内（与 ER-05 授予边界同源）
        $creationDenied = $this->guardPermissionCodeCreation((string) $resource, (string) $action);
        if ($creationDenied !== null) {
            return $creationDenied;
        }

        // Create permission | 创建权限
        $permission = new Permission($data['name'], $data['slug']);
        $permission->fromArray([
            'resource' => $resource,
            'action' => $action,
        ]);

        // Set description if provided | 如果提供了描述则设置
        // 用实体自身的 setter（历史上是反射直接写私有属性，语义相同但更脆）
        if (array_key_exists('description', $data)) {
            $permission->setDescription($this->normalizeText($data['description']));
        }

        $permissionId = $this->permissionRepository->save($permission);

        // Reload to get complete data | 重新加载获取完整数据
        $savedPermission = $this->permissionRepository->findById($permissionId);

        return $this->success(
            $savedPermission->toArray(),
            $this->langService->trans('permission.created'),
            0,
            201
        );
    }

    /**
     * Update permission | 更新权限
     *
     * PUT /v1/admin/permissions/:id
     *
     * ## 权限码不可变（缺陷 #1：权限定义改写 = 等价提权）
     *
     * 历史实现把请求里的 `slug` / `resource` / `action` **直接落库**，而鉴权认的是
     * `resource + action` 拼出的权限码。于是持有 `permission.write` 的人可以：
     *
     *   1. 把自己**已经持有**的那一行（例如 `role.write`）改成
     *      `resource=tenant, action=write`；
     *   2. `role_permissions` 里的绑定一行都没变；
     *   3. 他从下一次请求起就持有 `tenant:write` —— **等价提权**。
     *
     * 更糟的是 `permissions` 是全局目录（没有 `tenant_id`），一次改写影响**所有租户**。
     *
     * 现在只接受 `name` 与 `description`：
     *   - 没传 `slug` / `resource` / `action` → 沿用库里的原值（其它字段照常更新）；
     *   - 传了**且与当前值不同** → 422，文案明确「权限码不可修改，如需变更请走迁移」，
     *     并且**一个字段都不落库**；
     *   - 传了**与当前值相同** → 视为"原样回传"直接忽略。这是为了幂等友好：
     *     前端编辑抽屉会把整个对象回传，若"原样回传"也报 422，那"只改描述"这个
     *     合法操作会被连带拒绝（等于把整个编辑功能封死）。
     *
     * 顺带修掉一个静默数据丢失：历史实现在请求没带 `description` 时会构造一个
     * description 为 null 的新实体并用它整行覆盖 —— 于是"只改名字"会**清空原有描述**。
     * 现在缺省字段一律沿用原值。
     *
     * @param int $id Permission ID | 权限 ID
     * @return Response
     */
    public function update(int $id): Response
    {
        $permission = $this->permissionRepository->findById($id);

        if ($permission === null) {
            return $this->error(
                $this->langService->trans('permission.not_found'),
                404,
                [],
                404
            );
        }

        $data = $this->request->put();

        // ① 先判"码是否被改写"：这是安全边界，必须早于任何校验/落库分支，
        //    避免"格式校验先报错、改写尝试被别的错误掩盖"。
        $attemptedFields = $this->detectImmutableCodeChanges($permission, $data);
        if ($attemptedFields !== []) {
            Log::warning('Permission update rejected: permission code is immutable', [
                'permission_id' => $id,
                'current_slug' => $permission->getSlug(),
                'current_code' => $permission->getResource() . ':' . $permission->getAction(),
                'attempted_fields' => $attemptedFields,
                'operator_user_id' => $this->getCurrentUserId(),
                'trace_id' => $this->getTraceId(),
            ]);

            $message = $this->langService->trans('permission.code_immutable');
            $errors = [];
            foreach ($attemptedFields as $field) {
                $errors[$field] = [$message];
            }

            return $this->error($message, 422, $errors, 422);
        }

        // ② 只校验将被真正使用的字段（name / description）
        $errors = $this->validatePermissionData($data, true);
        if (!empty($errors)) {
            return $this->error(
                $this->langService->trans('validation.failed'),
                422,
                $errors,
                422
            );
        }

        // ③ 重建实体时，码相关的三个字段**一律取库里的原值**，请求里的值不再参与
        //    （即使它们与当前值相同 —— 相同就没有携带新信息的必要）。
        //    这里保留 fromArray(['resource'=>..,'action'=>..]) 的用法：
        //    Permission 没有 resource/action 的 setter，fromArray 是它唯一的赋值入口
        //    （创建路径与 PermissionRepository::hydrate() 都依赖它）。
        $newPermission = new Permission(
            array_key_exists('name', $data) ? (string) $data['name'] : $permission->getName(),
            $permission->getSlug()
        );
        $newPermission->setId($id);
        $newPermission->fromArray([
            'resource' => $permission->getResource(),
            'action' => $permission->getAction(),
        ]);
        $newPermission->setDescription(
            array_key_exists('description', $data)
                ? $this->normalizeText($data['description'])
                : $permission->getDescription()
        );

        $this->permissionRepository->save($newPermission);

        // Reload to get updated data | 重新加载获取更新后的数据
        $updatedPermission = $this->permissionRepository->findById($id);

        return $this->success(
            $updatedPermission->toArray(),
            $this->langService->trans('permission.updated')
        );
    }

    /**
     * Delete permission | 删除权限
     *
     * DELETE /v1/admin/permissions/:id
     *
     * @param int $id Permission ID | 权限 ID
     * @return Response
     */
    public function delete(int $id): Response
    {
        $permission = $this->permissionRepository->findById($id);

        if ($permission === null) {
            return $this->error(
                $this->langService->trans('permission.not_found'),
                404,
                [],
                404
            );
        }

        // Check if permission is used by any roles | 检查权限是否被任何角色使用
        $roles = $this->permissionRepository->getRolesWithPermission($id);
        if (!empty($roles)) {
            return $this->error(
                $this->langService->trans('permission.in_use'),
                409,
                ['roles' => $roles],
                409
            );
        }

        $deleted = $this->permissionRepository->delete($id);

        if (!$deleted) {
            return $this->error(
                $this->langService->trans('permission.delete_failed'),
                500,
                [],
                500
            );
        }

        return $this->success(
            null,
            $this->langService->trans('permission.deleted')
        );
    }

    /**
     * Get all resources | 获取所有资源类型
     *
     * GET /v1/admin/permissions/resources
     *
     * @return Response
     */
    public function resources(): Response
    {
        $resources = $this->permissionRepository->getResources();

        return $this->success(['list' => $resources]);
    }

    /**
     * Get all actions | 获取所有操作类型
     *
     * GET /v1/admin/permissions/actions
     *
     * @return Response
     */
    public function actions(): Response
    {
        $actions = $this->permissionRepository->getActions();

        return $this->success(['list' => $actions]);
    }

    /**
     * Get permissions grouped by resource | 获取按资源分组的权限
     *
     * GET /v1/admin/permissions/grouped
     *
     * Returns both list and grouped permissions for role assignment.
     * 返回列表和分组权限，用于角色授权。
     *
     * @return Response
     */
    public function grouped(): Response
    {
        $grouped = $this->permissionRepository->getGroupedByResource();

        // Flatten grouped to list for convenience | 将分组展平为列表以便使用
        $list = [];
        foreach ($grouped as $permissions) {
            foreach ($permissions as $permission) {
                $list[] = $permission;
            }
        }

        return $this->success([
            'list' => $list,
            'grouped' => $grouped,
        ]);
    }

    /**
     * 检测请求是否试图改写权限码（`slug` / `resource` / `action`）
     * Detect attempts to rewrite the immutable permission code
     *
     * 判定口径：**请求里带了该字段，且规范化后与库里当前值不同**。
     * 「带了但相同」被刻意视为合法（幂等友好，见 `update()` 的说明）。
     *
     * 规范化用 `normalizeText()`：`null` 与 `''` 等价（`resource` / `action` 列可为
     * NULL，前端回传时可能是 `null` 也可能是空串，不能因此误判成"改码"），
     * 同时去首尾空白（`'role '` 与 `'role'` 语义上没变，不应该因此 422）。
     *
     * @param Permission $permission 当前库里的权限实体
     * @param array $data 请求数据
     * @return array<string> 被试图改写的字段名列表（空数组 = 没有改写尝试）
     */
    protected function detectImmutableCodeChanges(Permission $permission, array $data): array
    {
        $current = [
            'slug' => $permission->getSlug(),
            'resource' => $permission->getResource(),
            'action' => $permission->getAction(),
        ];

        $changed = [];

        foreach (self::IMMUTABLE_CODE_FIELDS as $field) {
            if (!array_key_exists($field, $data)) {
                // 没带 → 不改
                continue;
            }

            if ($this->normalizeText($data[$field]) !== $this->normalizeText($current[$field])) {
                $changed[] = $field;
            }
        }

        return $changed;
    }

    /**
     * 权限码归属校验：新建的码必须已在操作者自身权限集内
     * Guard: a newly created permission code must already be held by the operator
     *
     * ## 为什么"新建"也归到授予边界
     *
     * `permissions` 是**全局目录**（无 `tenant_id`）。允许任意写，等于允许任何
     * `permission.write` 持有者往所有租户的授权界面里塞行 —— 即便这些行**无法**被
     * 授予（ER-05 的子集校验要求操作者自己持有该码），也足以污染目录、伪装能力、
     * 把「权限目录」变成可写的攻击面。
     *
     * 因此这里复用与 `GrantCeilingService` **完全相同**的口径：
     * 取 `PermissionService::getUserPermissions()`（也就是 `app\middleware\Permission`
     * 当场用的那一份数据，含 DB_ONLY / CASBIN_ONLY / DUAL_MODE 三种模式与多级缓存）里的
     * `resource:action` 集合，判断"这个码是否属于操作者"。
     *
     * 为什么不直接调 `GrantCeilingService::inspectPermissions()`：它按**权限 ID** 校验，
     * 而新建时这个 ID 还不存在。本方法只做"码 ∈ 操作者权限集"这一件事，判定口径不变，
     * 也不新增第二个真相源（若将来需要，可把它上收为 `GrantCeilingService::inspectCode()`）。
     *
     * 失败语义：**fail-closed**。取不到操作者身份、或取到的权限集为空，
     * 一律拒绝，而不是"查不到就放行"。不含任何硬编码超管绕过 —— seeded `admin`
     * 天然持有全部权限行，子集校验自然通过。
     *
     * @param string $resource 资源名（非空）
     * @param string $action 动作名（非空）
     * @return Response|null null = 通过；非 null = 应直接 return 的拒绝响应
     */
    protected function guardPermissionCodeCreation(string $resource, string $action): ?Response
    {
        $code = $resource . ':' . $action;

        $operatorId = $this->getCurrentUserId();

        if ($operatorId === null) {
            Log::warning('Permission code creation denied: operator identity missing', [
                'code' => $code,
                'path' => $this->request->pathinfo(),
                'trace_id' => $this->getTraceId(),
            ]);

            return $this->permissionCodeCreationDeniedResponse();
        }

        $heldCodes = $this->permissionService->getUserPermissions($operatorId);

        if (!in_array($code, $heldCodes, true)) {
            // 拒绝文案不回显"操作者有哪些权限"，只记录本次尝试的码，便于事后取证
            Log::warning('Permission code creation denied: operator does not hold the code', [
                'operator_user_id' => $operatorId,
                'attempted_code' => $code,
                'path' => $this->request->pathinfo(),
                'trace_id' => $this->getTraceId(),
            ]);

            return $this->permissionCodeCreationDeniedResponse();
        }

        return null;
    }

    /**
     * 统一的"不能新建自己不持有的权限码"响应：HTTP 403 / 业务码 2002
     *
     * 与 `app\middleware\Permission` 的「权限不足」和 ER-05 的「授予边界越界」
     * 用同一组状态码，客户端不需要为它单独写一套错误处理。
     *
     * @return Response
     */
    protected function permissionCodeCreationDeniedResponse(): Response
    {
        return $this->error(
            $this->langService->trans('permission.code_not_owned'),
            2002,
            [],
            403
        );
    }

    /**
     * 规范化"字符串或空"：非字符串 → null；去首尾空白；空串 → null
     *
     * 用途有二：
     *   1. 判"码是否被改写"时消除 `null` / `''` / `'  '` 这三种"无值"形态的差异；
     *   2. 落库前把 `description` 的 `''` 归一成 `null`（该列可为 NULL，两种空值没有区别）。
     *
     * @param mixed $value 原始值（可能来自 JSON 的 null / string / 其它类型）
     * @return string|null
     */
    protected function normalizeText(mixed $value): ?string
    {
        if (!is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    /**
     * 获取当前已认证操作者的用户 ID
     *
     * **只信任 Auth 中间件从 JWT 解出并写入请求上下文的用户 ID**，
     * 刻意不读 `X-User-Id` 之类的请求头（那是客户端可任意设置的值）。
     *
     * @return int|null 未认证时为 null
     */
    protected function getCurrentUserId(): ?int
    {
        if (method_exists($this->request, 'userId')) {
            $userId = $this->request->userId();
            if (is_int($userId) && $userId > 0) {
                return $userId;
            }
        }

        return null;
    }

    /**
     * Validate permission data | 验证权限数据
     *
     * 注意（#1）：`update()` 已不接受 `slug` / `resource` / `action`，
     * 因此这几个字段的格式校验实际只服务于创建分支；保留它们是为了让创建拿到
     * 一致的 422 反馈（更新分支上它们与库内原值相同，必然通过）。
     *
     * @param array $data Data to validate | 要验证的数据
     * @param bool $isUpdate Whether this is an update operation | 是否为更新操作
     * @return array Validation errors | 验证错误
     */
    protected function validatePermissionData(array $data, bool $isUpdate = false): array
    {
        $errors = [];

        // 类型前置校验（#1）：请求体是任意 JSON，`name` / `description` 若传成数组或数字，
        // 后面的 mb_strlen()/setDescription() 会抛 TypeError（500），这里统一收敛成 422。
        foreach (['name', 'description', 'slug', 'resource', 'action'] as $textField) {
            if (array_key_exists($textField, $data) && $data[$textField] !== null && !is_string($data[$textField])) {
                $errors[$textField] = [$this->langService->trans('permission.invalid_field_type', ['field' => $textField])];
            }
        }

        if (!empty($errors)) {
            // 类型都不对，后续长度/格式校验没有意义（且可能在数组上抛错）
            return $errors;
        }

        // Required fields for create | 创建时必填字段
        if (!$isUpdate) {
            if (empty($data['name'])) {
                $errors['name'] = [$this->langService->trans('validation.required', ['field' => 'name'])];
            }
            if (empty($data['slug'])) {
                $errors['slug'] = [$this->langService->trans('validation.required', ['field' => 'slug'])];
            }
        }

        // Validate name length | 验证名称长度
        if (!empty($data['name']) && mb_strlen($data['name']) > 100) {
            $errors['name'] = [$this->langService->trans('validation.max', ['field' => 'name', 'max' => 100])];
        }

        // Validate slug format | 验证 slug 格式
        if (!empty($data['slug']) && !preg_match('/^[a-z0-9_.]+$/', $data['slug'])) {
            $errors['slug'] = [$this->langService->trans('permission.invalid_slug_format')];
        }

        // Validate slug length | 验证 slug 长度
        if (!empty($data['slug']) && strlen($data['slug']) > 100) {
            $errors['slug'] = [$this->langService->trans('validation.max', ['field' => 'slug', 'max' => 100])];
        }

        // Validate description length | 验证描述长度
        if (!empty($data['description']) && mb_strlen($data['description']) > 255) {
            $errors['description'] = [$this->langService->trans('validation.max', ['field' => 'description', 'max' => 255])];
        }

        // Validate resource length | 验证资源长度
        if (!empty($data['resource']) && strlen($data['resource']) > 50) {
            $errors['resource'] = [$this->langService->trans('validation.max', ['field' => 'resource', 'max' => 50])];
        }

        // Validate action length | 验证操作长度
        if (!empty($data['action']) && strlen($data['action']) > 50) {
            $errors['action'] = [$this->langService->trans('validation.max', ['field' => 'action', 'max' => 50])];
        }

        return $errors;
    }
}
