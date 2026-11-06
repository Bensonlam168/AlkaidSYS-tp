import type { VbenFormSchema } from '#/adapter/form';
import type { WorkflowTaskApi } from '#/api/workflow/task';

import { afterEach, describe, expect, it, vi } from 'vitest';

import { toVbenFormSchema } from '../schema-form';

/** 每个用例结束后还原 spy（`console.warn` 之类的劫持不能跨用例泄漏） */
afterEach(() => {
  vi.restoreAllMocks();
});

/**
 * 后端表单 schema → Vben 表单 schema 的映射测试
 *
 * ## 这个文件在守什么
 *
 * 审批抽屉的表单是**后端驱动**的（字段、组件、枚举、必填全部来自流程节点的低代码表单配置）。
 * 映射层出错的后果不是「样式丑」，而是：
 * - 组件名拼错 → 渲染出空白项，用户以为「没有这一项」，提交时被后端 422 拦住；
 * - 枚举没转成 `options` → 下拉是空的，值域内的值一个都选不了；
 * - 必填规则没挂上 → 用户不填也能点提交，多一次 422 往返；
 * - 字段被丢弃 → 用户根本填不了，后端必填校验必然失败，且原因（少了一个看不见的字段）极难定位；
 * - `defaultValue` 丢失 → 已提交过的值不回显，二次审批等于让人重填。
 *
 * 因此这里逐条钉住上述行为，而不是只断言「函数能跑」。
 */

/**
 * 构造一个后端 schema 项（默认值取最常见的「普通输入框」形态）
 */
function schemaField(
  overrides: Partial<WorkflowTaskApi.TaskFormComponent> = {},
): WorkflowTaskApi.TaskFormComponent {
  return {
    component: 'Input',
    componentProps: {},
    decorator: 'FormItem',
    decoratorProps: {},
    enum: [],
    field: 'field_a',
    required: false,
    title: '字段 A',
    type: 'string',
    ...overrides,
  };
}

/**
 * 取某个字段的 `componentProps` 求值结果
 *
 * `VbenFormSchema['componentProps']` 是「函数或对象」的联合（渲染层统一按
 * `computed(() => componentProps(values, api))` 求值）。本文件产出的永远是对象，
 * 这里按渲染层同样的方式兼容两种形态，测试里就不用散落 `as any`。
 */
function propsOf(schema: VbenFormSchema[], index = 0): Record<string, any> {
  const componentProps = schema[index]?.componentProps;

  if (typeof componentProps === 'function') {
    return componentProps({}, undefined as never);
  }

  return componentProps ?? {};
}

describe('toVbenFormSchema: 普通字段映射', () => {
  it('field/title/component/componentProps 逐项映射到 Vben 字段', () => {
    const schema = toVbenFormSchema([
      schemaField({
        component: 'Input',
        componentProps: { maxLength: 32, placeholder: '请输入合同名称' },
        field: 'contract_name',
        title: '合同名称',
      }),
    ]);

    expect(schema).toHaveLength(1);
    expect(schema[0]).toMatchObject({
      component: 'Input',
      fieldName: 'contract_name',
      label: '合同名称',
    });
    // componentProps 是原样透传（后端 x-component-props）
    expect(schema[0]?.componentProps).toEqual({
      maxLength: 32,
      placeholder: '请输入合同名称',
    });
    // 非必填不挂规则（挂了就等于把「可选」变成「必填」）
    expect(schema[0]?.rules).toBeUndefined();
  });

  it('title 缺失时用字段名兜底，不产生空标签', () => {
    const schema = toVbenFormSchema([
      schemaField({ field: 'amount', title: '' }),
    ]);

    expect(schema[0]?.label).toBe('amount');
  });

  it('映射结果保持后端给的字段顺序', () => {
    const schema = toVbenFormSchema([
      schemaField({ field: 'b', title: 'B' }),
      schemaField({ field: 'a', title: 'A' }),
    ]);

    expect(schema.map((item) => item.fieldName)).toEqual(['b', 'a']);
  });
});

describe('toVbenFormSchema: 组件名映射', () => {
  it('后端组件名按约定映射到 ComponentType（含点号写法与别名）', () => {
    const cases: Array<[string, string]> = [
      ['Input', 'Input'],
      ['Textarea', 'Textarea'],
      ['Input.TextArea', 'Textarea'],
      ['Select', 'Select'],
      ['Radio.Group', 'RadioGroup'],
      ['RadioGroup', 'RadioGroup'],
      ['Checkbox.Group', 'CheckboxGroup'],
      ['DatePicker', 'DatePicker'],
      ['DateRangePicker', 'RangePicker'],
      ['RangePicker', 'RangePicker'],
      ['InputNumber', 'InputNumber'],
      ['NumberPicker', 'InputNumber'],
      ['TimePicker', 'TimePicker'],
      ['Switch', 'Switch'],
      ['Rate', 'Rate'],
      ['Upload', 'Upload'],
      ['TreeSelect', 'TreeSelect'],
      ['AutoComplete', 'AutoComplete'],
    ];

    const schema = toVbenFormSchema(
      cases.map(([raw], index) =>
        schemaField({ component: raw, field: `f_${index}` }),
      ),
    );

    expect(schema.map((item) => item.component)).toEqual(
      cases.map(([, expected]) => expected),
    );
  });
});

describe('toVbenFormSchema: enum → options', () => {
  it('字符串枚举转成 {label, value}', () => {
    const schema = toVbenFormSchema([
      schemaField({
        component: 'Select',
        enum: ['draft', 'reviewing'],
        field: 'status',
      }),
    ]);

    expect(propsOf(schema).options).toEqual([
      { label: 'draft', value: 'draft' },
      { label: 'reviewing', value: 'reviewing' },
    ]);
  });

  it('{label, value} 形态的枚举原样保留标签', () => {
    const schema = toVbenFormSchema([
      schemaField({
        component: 'Radio.Group',
        enum: [
          { label: '通过', value: 1 },
          { label: '驳回', value: 0 },
        ],
        field: 'decision',
      }),
    ]);

    expect(propsOf(schema).options).toEqual([
      { label: '通过', value: 1 },
      { label: '驳回', value: 0 },
    ]);
  });

  it('enum 为空时不覆盖 componentProps 里已有的 options', () => {
    const schema = toVbenFormSchema([
      schemaField({
        component: 'Select',
        componentProps: { options: [{ label: '原有', value: 'raw' }] },
        enum: [],
      }),
    ]);

    expect(propsOf(schema).options).toEqual([{ label: '原有', value: 'raw' }]);
  });

  it('enum 非空时以枚举为准（后端 in: 校验规则就来自 enum）', () => {
    const schema = toVbenFormSchema([
      schemaField({
        component: 'Select',
        componentProps: { options: [{ label: '过期配置', value: 'stale' }] },
        enum: ['fresh'],
      }),
    ]);

    expect(propsOf(schema).options).toEqual([
      { label: 'fresh', value: 'fresh' },
    ]);
  });
});

describe('toVbenFormSchema: 必填规则分派', () => {
  it('选择类组件用 selectRequired，输入类用 required', () => {
    const schema = toVbenFormSchema([
      schemaField({ component: 'Select', field: 's', required: true }),
      schemaField({ component: 'TreeSelect', field: 'ts', required: true }),
      schemaField({ component: 'Radio.Group', field: 'rg', required: true }),
      schemaField({ component: 'Checkbox.Group', field: 'cg', required: true }),
      schemaField({ component: 'Input', field: 'i', required: true }),
      schemaField({ component: 'InputNumber', field: 'n', required: true }),
      schemaField({ component: 'DatePicker', field: 'd', required: true }),
    ]);

    expect(schema.map((item) => [item.fieldName, item.rules])).toEqual([
      ['s', 'selectRequired'],
      ['ts', 'selectRequired'],
      ['rg', 'selectRequired'],
      ['cg', 'selectRequired'],
      ['i', 'required'],
      ['n', 'required'],
      ['d', 'required'],
    ]);
  });

  it('降级成 Input 的未知组件按输入类处理（required）', () => {
    const schema = toVbenFormSchema([
      schemaField({
        component: 'SomeUnknownWidget',
        field: 'x',
        required: true,
      }),
    ]);

    expect(schema[0]?.component).toBe('Input');
    expect(schema[0]?.rules).toBe('required');
  });
});

describe('toVbenFormSchema: 未知组件降级', () => {
  it('未知组件降级为 Input，且字段不丢', () => {
    const schema = toVbenFormSchema([
      schemaField({
        component: 'BrandNewWidget',
        field: 'custom',
        title: '自定义',
      }),
      schemaField({ component: 'Input', field: 'normal', title: '普通' }),
    ]);

    // 字段一个都不能少：丢字段 = 用户填不了 = 后端必填校验必然失败
    expect(schema.map((item) => item.fieldName)).toEqual(['custom', 'normal']);
    expect(schema[0]?.component).toBe('Input');
    // 标题原样保留，用户至少能看到该字段在问什么
    expect(schema[0]?.label).toBe('自定义');
  });

  it('开发环境下对未知组件告警一次，重复构建不刷屏', () => {
    // 用 vi.spyOn 而不是手写替换 console.warn：断言失败时 afterEach 的
    // vi.restoreAllMocks() 一样能还原，不会把整个 worker 的 console 留在被劫持状态。
    // ⚠️ 必须在 `mockRestore()` **之前**把调用记录取出来 —— 它会连同 mock.calls 一起清空。
    const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
    let hit: string[] = [];

    try {
      const unknown = schemaField({
        component: 'MysteryBox',
        field: 'mystery',
      });
      // 同一份配置连续构建两次：警告只应出现一次（`WARNED_KEYS` 去重）
      toVbenFormSchema([unknown]);
      toVbenFormSchema([unknown]);
    } finally {
      hit = warnSpy.mock.calls
        .map((args) => args.map(String).join(' '))
        .filter((line) => line.includes('MysteryBox'));
      warnSpy.mockRestore();
    }

    expect(hit).toHaveLength(1);
    // 警告里必须写明字段名与原始组件名，否则运维不知道该去改哪一项配置
    expect(hit[0]).toContain('mystery');
  });

  it('没有 field 的表单项被跳过（无法绑定值），其余照常渲染', () => {
    const schema = toVbenFormSchema([
      schemaField({ field: '' }),
      schemaField({ field: 'kept' }),
    ]);

    expect(schema.map((item) => item.fieldName)).toEqual(['kept']);
  });
});

describe('toVbenFormSchema: defaultValue 回显', () => {
  it('从 formData 取同名字段作为默认值', () => {
    const schema = toVbenFormSchema(
      [
        schemaField({ field: 'amount', title: '金额' }),
        schemaField({ field: 'note', title: '备注' }),
      ],
      { amount: 128, note: '已核对发票' },
    );

    expect(schema[0]?.defaultValue).toBe(128);
    expect(schema[1]?.defaultValue).toBe('已核对发票');
  });

  it('formData 缺失该字段时为 undefined（不编造默认值）', () => {
    const schema = toVbenFormSchema([schemaField({ field: 'absent' })], {});

    expect(schema[0]?.defaultValue).toBeUndefined();
  });

  it('formData 为 null 时不抛错', () => {
    const schema = toVbenFormSchema([schemaField({ field: 'a' })], null);

    expect(schema[0]?.defaultValue).toBeUndefined();
  });
});

describe('toVbenFormSchema: 空 schema', () => {
  it('null / undefined / 空数组都返回空数组', () => {
    expect(toVbenFormSchema(null)).toEqual([]);
    expect(toVbenFormSchema(undefined)).toEqual([]);
    expect(toVbenFormSchema([])).toEqual([]);
  });
});
