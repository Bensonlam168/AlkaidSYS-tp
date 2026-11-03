import type { SystemPermissionApi } from '#/api/system/permission';

import { mount } from '@vue/test-utils';
import { h, ref } from 'vue';

import { globalShareState } from '@vben/common-ui';

import { beforeAll, describe, expect, it } from 'vitest';

import { initSetupVbenForm, useVbenForm } from '#/adapter/form';

import { PERMISSION_CODE_FIELDS, useFormSchema } from '../data';

/**
 * 权限表单「不可变字段置灰」测试（#3 回归防护）
 *
 * ## 守住的缺陷
 *
 * 后端把权限码三要素（`slug` / `resource` / `action`）视为**不可变**：
 * `PUT /v1/admin/permissions/:id` 带上与当前值不同的这三项会返回 **422**。
 * 而前端表单此前仍允许编辑它们 —— 用户改完点保存才撞 422，交互上是白改一遍。
 *
 * ## 本文件断言什么
 *
 * 1. **schema 层**：编辑态下这三个字段的 `componentProps()` 求值出 `disabled: true`；
 *    新建态为 `false`；不可编辑的字段集合恰好等于 {@link PERMISSION_CODE_FIELDS}。
 * 2. **渲染层**：用真实 `useVbenForm` 挂载（仅把最底层 Input/Textarea 换成桩组件，
 *    避免 happy-dom 里跑整棵 ant-design-vue），断言编辑态下有且只有 3 个 `<input>`
 *    带 `disabled`，新建态下一个都没有。
 * 3. **值不被丢弃**：置灰后 `formApi.getValues()` 仍是原值 —— 后端对「相同值」幂等放行，
 *    所以整对象回传不会 422；这也保证「置灰 ≠ 从提交里删掉字段」。
 *
 * 桩组件只做两件事：把 `disabled` 透出到 DOM、把 `value` 透出到 DOM。
 * 表单库（vee-validate + vben form）的其余部分都是真实实现，因此
 * 「响应式 → disabled」这条链路是被真实求值过的，不是对断言的重写。
 */
describe('permission form: immutable permission code fields', () => {
  beforeAll(async () => {
    // 只替换最底层输入组件；表单渲染、依赖求值、值管理全部走真实实现
    globalShareState.setComponents({
      Input: {
        name: 'StubInput',
        props: {
          disabled: { default: false, type: Boolean },
          placeholder: { default: '', type: String },
          value: { default: '', type: [Number, String] },
        },
        setup(props: Record<string, any>) {
          return () =>
            h('input', {
              disabled: props.disabled,
              placeholder: props.placeholder,
              value: props.value,
            });
        },
      },
      Textarea: {
        name: 'StubTextarea',
        props: {
          disabled: { default: false, type: Boolean },
          value: { default: '', type: [Number, String] },
        },
        setup(props: Record<string, any>) {
          return () =>
            h('textarea', {
              disabled: props.disabled,
              value: props.value,
            });
        },
      },
    });
    // 走 App 真实的 adapter 初始化（组件表 + 规则），保证与生产同一套映射
    await initSetupVbenForm();
  });

  /**
   * 取某个字段的 props 求值结果（渲染层就是这么调的：componentProps(values, api)）
   */
  function resolveProps(
    isEditMode: () => boolean,
    fieldName: string,
  ): Record<string, any> {
    const schema = useFormSchema({ isEditMode });
    const item = schema.find((field) => field.fieldName === fieldName);
    expect(item, `schema 里应存在字段 ${fieldName}`).toBeTruthy();

    const componentProps = item?.componentProps;
    return typeof componentProps === 'function'
      ? componentProps({}, undefined as never)
      : (componentProps ?? {});
  }

  it('编辑态：slug / resource / action 的 componentProps 求值为 disabled', () => {
    const isEdit = () => true;

    for (const field of PERMISSION_CODE_FIELDS) {
      expect(
        resolveProps(isEdit, field).disabled,
        `${field} 编辑态必须 disabled`,
      ).toBe(true);
    }

    // 可见提示：置灰字段带 title，悬停时能读到原因
    expect(resolveProps(isEdit, 'slug').title).toBeTruthy();
  });

  it('新建态：三字段保持可编辑（越界权限码由后端 403 拦截）', () => {
    const isCreate = () => false;

    for (const field of PERMISSION_CODE_FIELDS) {
      expect(
        resolveProps(isCreate, field).disabled,
        `${field} 新建态必须可编辑`,
      ).toBe(false);
    }
  });

  it('name / description 永远可编辑（后端允许改显示名与描述）', () => {
    for (const field of ['name', 'description']) {
      expect(resolveProps(() => true, field).disabled).toBeFalsy();
      expect(resolveProps(() => false, field).disabled).toBeFalsy();
    }
  });

  it('不可变字段集合恰好是 slug / resource / action（防止漏配或误配）', () => {
    const disabledInEditMode = useFormSchema({ isEditMode: () => true })
      .filter(
        (field) => resolveProps(() => true, field.fieldName).disabled === true,
      )
      .map((field) => field.fieldName)
      .sort();

    expect(disabledInEditMode).toEqual([...PERMISSION_CODE_FIELDS].sort());
  });

  it('slug 格式规则：新建态拦冒号格式，编辑态放行（历史遗留数据仍可改显示名）', async () => {
    /**
     * 取 slug 字段的 zod 规则，并对给定值跑一次校验
     */
    const parseSlug = async (isEdit: () => boolean, value: string) => {
      const rules = useFormSchema({ isEditMode: isEdit }).find(
        (field) => field.fieldName === 'slug',
      )?.rules;
      expect(rules).toBeTruthy();
      return await (
        rules as { safeParseAsync: (v: string) => Promise<any> }
      ).safeParseAsync(value);
    };

    // 库里的历史遗留权限码：lowcode:read（id 21）等三条用的是冒号
    const legacySlug = 'lowcode:read';
    const normalSlug = 'dept.read';

    // 新建态：格式必须合规
    const createNormal = await parseSlug(() => false, normalSlug);
    expect(createNormal.success).toBe(true);

    const createLegacy = await parseSlug(() => false, legacySlug);
    expect(createLegacy.success).toBe(false);

    // 编辑态：slug 置灰不可改，格式不再拦截（否则连改显示名都保存不了）
    const editLegacy = await parseSlug(() => true, legacySlug);
    expect(editLegacy.success).toBe(true);

    // 但空值仍然不允许（新建态与编辑态一致）
    const editEmpty = await parseSlug(() => true, '');
    expect(editEmpty.success).toBe(false);
  });

  it('渲染层：编辑态有且只有 3 个 input 被 disabled，新建态全部可编辑', async () => {
    const isEdit = ref(false);

    const Host = {
      setup() {
        const [Form] = useVbenForm({
          schema: useFormSchema({ isEditMode: () => isEdit.value }),
          showDefaultActions: false,
        });
        return () => h(Form);
      },
    };

    const wrapper = mount(Host);
    await wrapper.vm.$nextTick();

    // 新建态：所有输入可编辑
    expect(wrapper.findAll('input')).toHaveLength(4);
    expect(
      wrapper
        .findAll('input')
        .filter((node) => node.attributes('disabled') !== undefined),
    ).toHaveLength(0);

    // 切到编辑态：slug / resource / action 三个 input 变为 disabled
    isEdit.value = true;
    await wrapper.vm.$nextTick();

    const disabledInputs = wrapper
      .findAll('input')
      .filter((node) => node.attributes('disabled') !== undefined);
    expect(disabledInputs).toHaveLength(PERMISSION_CODE_FIELDS.length);
  });

  it('置灰不等于丢值：编辑态下 getValues() 仍返回原始权限码', async () => {
    const isEdit = ref(false);
    let formApi: any;

    const Host = {
      setup() {
        const [Form, api] = useVbenForm({
          schema: useFormSchema({ isEditMode: () => isEdit.value }),
          showDefaultActions: false,
        });
        formApi = api;
        return () => h(Form);
      },
    };

    const row: SystemPermissionApi.SystemPermission = {
      action: 'read',
      created_at: '2025-01-01 00:00:00',
      description: '部门查询',
      id: 1,
      name: '部门查询',
      resource: 'dept',
      slug: 'dept.read',
      updated_at: '2025-01-01 00:00:00',
    };

    const wrapper = mount(Host);
    await wrapper.vm.$nextTick();

    isEdit.value = true;
    await formApi.setValues(row);
    await wrapper.vm.$nextTick();

    const values = await formApi.getValues();
    expect(values.slug).toBe('dept.read');
    expect(values.resource).toBe('dept');
    expect(values.action).toBe('read');
    expect(values.name).toBe('部门查询');
  });
});
