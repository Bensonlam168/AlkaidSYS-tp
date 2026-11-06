import type { ComponentType } from '#/adapter/component';
import type { VbenFormSchema } from '#/adapter/form';
import type { WorkflowTaskApi } from '#/api/workflow/task';

/**
 * 后端表单 schema → Vben 表单 schema 的映射 | Backend task form schema → Vben form schema
 *
 * ## 输入是什么
 *
 * `GET /v1/workflow/tasks/{id}/form` 的 `form_schema`，即
 * `Infrastructure\Lowcode\Schema\SchemaParser::buildComponents()` 的输出 ——
 * 它与 `GET /v1/lowcode/forms/{name}?with_parsed=1` 的 `data.parsed.components` **是同一个数组**，
 * 后端把 JSON Schema 的 `x-component` / `x-component-props` / `enum` / `required` 摊平成
 * `{field, title, component, decorator, componentProps, decoratorProps, required, type, enum}`。
 *
 * ## 输出是什么
 *
 * Vben 表单（`#/adapter/form` 的 `useVbenForm`）吃的 `VbenFormSchema[]`：
 * `fieldName` / `label` / `component` / `componentProps` / `defaultValue` / `rules`。
 *
 * ## 三条设计约束（都是「不做会真出问题」的）
 *
 * 1. **组件名只允许落在 {@link ComponentType} 联合类型内**：表单一侧注册的组件表就是这份联合类型
 *    （`src/adapter/component/index.ts`），写一个不存在的名字，渲染出来是空白 —— 用户以为「没有这一项」，
 *    点提交才被后端校验拦下。所以映射表用 `Record<string, ComponentType>` 声明，让编译器兜住拼写；
 * 2. **未知组件一律降级为 `Input`，绝不丢弃字段**：丢掉字段 = 用户填不了 = 后端必填校验必然失败，
 *    而且失败原因（少了一个看不见的字段）极难定位。降级 + DEV 警告至少让人能填、也让人知道该去修配置；
 * 3. **本文件是纯函数**：不读全局状态、不发请求、不碰 i18n（`$t` 依赖当前 locale 的响应式上下文），
 *    这样它可以被单测直接覆盖（`__tests__/schema-form.test.ts`），而不用挂载整个抽屉。
 */

/**
 * 后端 `x-component` → Vben `ComponentType` 的映射表
 *
 * 右边每个取值都必须是 `src/adapter/component/index.ts` 里 `ComponentType` 的成员，
 * 否则 TypeScript 会在这里报错（这是本表唯一的价值：把「组件名写错」提前到编译期）。
 *
 * ## 为什么表里没有 `ApiSelect` / `ApiTreeSelect`
 *
 * 这两个组件要求传入一个**运行时函数**（`api: () => Promise<...>`）来取远端数据，
 * 而这里的一切都来自后端 JSON（`x-component-props` 只能是可序列化的值）。
 * 硬映射过去只会渲染出一个永远空着的下拉框 —— 比降级成 `Input` 更糟：
 * 前者看起来「能选但没数据」，后者至少能填。故不映射，按未知组件降级。
 *
 * `Mentions` / `InputPassword` 同理不映射：审批表单的字段值最终会作为业务数据落库，
 * 「提及某人」「密码输入」这两类控件的语义不该由流程节点的表单配置单方面决定。
 */
const COMPONENT_MAP: Record<string, ComponentType> = {
  AutoComplete: 'AutoComplete',
  Checkbox: 'Checkbox',
  'Checkbox.Group': 'CheckboxGroup',
  CheckboxGroup: 'CheckboxGroup',
  DatePicker: 'DatePicker',
  DateRangePicker: 'RangePicker',
  Input: 'Input',
  'Input.TextArea': 'Textarea',
  InputNumber: 'InputNumber',
  NumberPicker: 'InputNumber',
  Radio: 'Radio',
  'Radio.Group': 'RadioGroup',
  RadioGroup: 'RadioGroup',
  RangePicker: 'RangePicker',
  Rate: 'Rate',
  Select: 'Select',
  Switch: 'Switch',
  Textarea: 'Textarea',
  TimePicker: 'TimePicker',
  TreeSelect: 'TreeSelect',
  Upload: 'Upload',
};

/**
 * 「选择类」组件集合 | Selection-like components
 *
 * 只有这几类用适配器里的 `selectRequired` 规则，其余用 `required`。
 * 差异来自两条规则的判定口径（见 `src/adapter/form.ts` 的 `defineRules`）：
 * - `required`：`undefined` / `null` / `length === 0` 都算空（适用输入框、数字、日期等）；
 * - `selectRequired`：只把 `undefined` / `null` 算空 —— 选择类的空值是 `undefined`/`null`，
 *   而 `false` / `0` 是**合法取值**（例如单选里的 `0`），不能当「没填」。
 *
 * 多选类（`CheckboxGroup`）也归到这里：空数组 `[]` 同样是合法提交形态（后端按 `enum` 校验元素），
 * 用 `required` 会把「一个都没勾」判为未填，与后端语义不一致。
 */
const SELECT_LIKE_COMPONENTS: ReadonlySet<ComponentType> =
  new Set<ComponentType>([
    'CheckboxGroup',
    'RadioGroup',
    'Select',
    'TreeSelect',
  ]);

/**
 * 已经警告过的「字段 + 组件名」组合 | Already-warned keys
 *
 * 抽屉每打开一次就会重建一次 schema，若不去重，控制台会被同一条警告刷屏
 * （打开 10 次任务 = 10 条一模一样的警告），真正的新警告反而被淹没。
 */
const WARNED_KEYS = new Set<string>();

/**
 * 开发环境告警（生产构建下是空操作）| Dev-only warning
 *
 * 只告警、不抛错：表单配置错是运维问题，不该让审批界面直接白屏 ——
 * 用户至少还能用降级后的 `Input` 把值填上、把流程走完。
 *
 * @param key 去重键（同一「字段 + 组件名」只警告一次）
 * @param message 警告正文
 * @param detail 附带的原始数据结构（便于在控制台里直接展开看是哪一项配置错了）
 */
function warnInDev(key: string, message: string, detail?: unknown): void {
  if (!import.meta.env.DEV || WARNED_KEYS.has(key)) {
    return;
  }

  WARNED_KEYS.add(key);

  if (detail === undefined) {
    console.warn(message);
    return;
  }

  console.warn(message, detail);
}

/**
 * `enum` → 下拉/单选选项 | Map declared enum to component options
 *
 * 后端 `enum` 的元素形态不确定（JSON Schema 只要求是数组），实测存在两种：
 * - `string`（最常见：`["draft", "reviewing"]`）；
 * - `{ label, value }`（低代码表单里给枚举配了中文标签时）。
 *
 * 两种都兼容：字符串 → `{label: 原值, value: 原值}`；对象 → `{label: label ?? value, value}`。
 * 其余形态（数字、布尔）按「值即标签」处理，避免整份 schema 因一个元素形态异常而无法渲染。
 *
 * @param list 后端声明的枚举值域
 */
function toOptions(
  list: any[] | null | undefined,
): Array<{ label: string; value: any }> {
  if (!Array.isArray(list)) {
    return [];
  }

  const options: Array<{ label: string; value: any }> = [];

  for (const item of list) {
    if (item === null || item === undefined) {
      continue;
    }

    if (typeof item === 'object' && 'value' in item) {
      options.push({
        label: String(item.label ?? item.value),
        value: item.value,
      });
      continue;
    }

    options.push({ label: String(item), value: item });
  }

  return options;
}

/**
 * 后端 schema → Vben 表单 schema | Convert backend components to Vben schema
 *
 * 映射规则（逐条对应本包的需求，任一偏离都会让表单「看起来能填、其实填不对」）：
 *
 * | 后端字段 | Vben 字段 | 说明 |
 * | -------- | --------- | ---- |
 * | `field` | `fieldName` | 提交时 `form_data` 的键，必须与后端一致 |
 * | `title` | `label` | 缺失时回退到字段名，避免出现空标签的表单项 |
 * | `component` | `component` | 见 {@link COMPONENT_MAP}，未知降级 `Input` |
 * | `componentProps` | `componentProps` | 原样透传（`x-component-props`，如 `placeholder` / `maxLength`） |
 * | `enum` | `componentProps.options` | 非空时转成 `{label, value}`；**enum 优先于 `x-component-props.options`**（枚举是后端真正的值域，也是它 `in:` 校验规则的来源） |
 * | `formData[field]` | `defaultValue` | 已提交过的值要回显，否则二次审批等于让人重填 |
 * | `required` | `rules` | 选择类 → `'selectRequired'`，其余 → `'required'` |
 *
 * @param components 后端 `form_schema`（`null` / 空数组都返回空 schema）
 * @param formData 后端 `form_data`（回显用，缺字段按 `undefined` 处理）
 * @returns 可直接交给 `useVbenForm({ schema })` 的数组（**顺序与后端一致**）
 */
export function toVbenFormSchema(
  components: null | undefined | WorkflowTaskApi.TaskFormComponent[],
  formData?: null | Record<string, any>,
): VbenFormSchema[] {
  if (!Array.isArray(components) || components.length === 0) {
    return [];
  }

  const schema: VbenFormSchema[] = [];

  for (const component of components) {
    const field =
      typeof component?.field === 'string' ? component.field.trim() : '';

    // 没有字段名的表单项无法绑定值，也无法提交 —— 保留它只会渲染出一个「填了也传不上去」的控件
    if (!field) {
      warnInDev(
        'missing-field',
        '[workflow/todo] 表单 schema 中存在没有 field 的项，已跳过（无法绑定表单值）：',
        component,
      );
      continue;
    }

    const rawComponent =
      typeof component.component === 'string' ? component.component.trim() : '';
    const mapped = COMPONENT_MAP[rawComponent];
    const resolved: ComponentType = mapped ?? 'Input';

    if (!mapped) {
      warnInDev(
        `unknown-component:${field}:${rawComponent}`,
        `[workflow/todo] 未知的表单组件「${rawComponent || '(空)'}」（字段「${field}」），已降级为 Input 渲染；请检查流程节点的表单配置。`,
      );
    }

    // 透传后端给的组件参数；enum 非空时用枚举覆盖 options（枚举是后端值域 + 校验规则的真实来源）
    const componentProps: Record<string, any> = {
      ...component.componentProps,
    };
    const options = toOptions(component.enum);
    if (options.length > 0) {
      componentProps.options = options;
    }

    const item: VbenFormSchema = {
      component: resolved,
      componentProps,
      defaultValue: formData?.[field],
      fieldName: field,
      label: component.title || field,
    };

    if (component.required) {
      item.rules = SELECT_LIKE_COMPONENTS.has(resolved)
        ? 'selectRequired'
        : 'required';
    }

    schema.push(item);
  }

  return schema;
}
