import { requestClient } from '#/api/request';

/**
 * 低代码表单定义（含后端校验规则）
 */
export namespace LowcodeFormApi {
  export interface ValidatorDefinition {
    rules: Record<string, string>;
    messages: Record<string, string>;
  }

  export interface LowcodeForm {
    id?: number;
    name: string;
    title?: string;
    collection_name?: string;
    schema: Record<string, any>;
    validator?: ValidatorDefinition;
  }
}

/**
 * 获取单个表单定义，默认携带后端校验规则与解析结果（with_validator/with_parsed），避免前后端漂移。
 * 返回体中的 validator.rules/messages 与 parsed.components/permissions 可直接用于前端渲染与校验。
 */
export async function getLowcodeForm(
  name: string,
  opts: { withValidator?: boolean; withParsed?: boolean } = {},
) {
  const { withValidator = true, withParsed = true } = opts;

  return requestClient.get<LowcodeFormApi.LowcodeForm>(
    `/v1/lowcode/forms/${name}`,
    {
      params: {
        with_validator: withValidator ? 1 : 0,
        with_parsed: withParsed ? 1 : 0,
      },
    },
  );
}
