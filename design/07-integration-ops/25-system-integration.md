# AlkaidSYS 系统集成

## 📋 文档信息

| 项目 | 内容 |
|------|------|
| **文档名称** | AlkaidSYS 系统集成 |
| **文档版本** | v1.0 |
| **创建日期** | 2025-01-19 |

## 🎯 系统集成目标

1. **前后端集成** - Admin、Web、Mobile 三端与后端 API 无缝对接
2. **第三方服务集成** - 支付、短信、OSS、地图等第三方服务
3. **数据同步** - 多租户、多站点数据同步机制
4. **API 对接规范** - 统一的 API 对接规范和文档

## 🏗️ 系统集成架构

```mermaid
graph TB
    subgraph "前端应用"
        A[Admin 管理端]
        B[Web 客户端]
        C[Mobile 移动端]
    end

    subgraph "API 网关"
        D[Nginx]
        E[负载均衡]
    end

    subgraph "后端服务"
        F[Swoole HTTP Server]
        G[ThinkPHP 8.0]
    end

    subgraph "第三方服务"
        H[支付服务]
        I[短信服务]
        J[OSS 存储]
        K[地图服务]
    end

    subgraph "数据层"
        L[MySQL]
        M[Redis]
        N[RabbitMQ]
    end

    A & B & C --> D
    D --> E
    E --> F
    F --> G
    G --> H & I & J & K
    G --> L & M & N
```

## 🔗 前后端集成

## 🔐 API 回调签名与防重放（统一规范）

> 说明：本节 API 回调签名与防重放规范需与《04-security-performance/11-security-design.md》保持一致，
> 安全设计文档是权威来源；本节代码仅为设计阶段的示例实现，实际落地时如有差异以安全设计文档为准。
- 适用范围：第三方→本系统的回调/Webhook 以及业务系统内部回调
- 安全要求：所有回调必须携带以下请求头，并在 5 分钟有效期内，Nonce 仅可使用一次
  - X-App-Key：分配的应用 Key
  - X-Timestamp：Unix 毫秒时间戳
  - X-Nonce：16 字节随机字符串
  - X-Signature：HMAC-SHA256(appSecret, method + "\n" + path + "\n" + sha256(body) + "\n" + timestamp + "\n" + nonce)
- 校验步骤：检查时钟偏移（±5min）→ 查重 Nonce（Redis 5 分钟）→ 重算签名比对 → 关键字段一致性校验（tenant_id/site_id、金额、订单号等）

```php
// 示例（ThinkPHP）
use think\facade\Cache;

$ts = (int)request()->header('X-Timestamp');
$nonce = (string)request()->header('X-Nonce');
$sign = (string)request()->header('X-Signature');
$appKey = (string)request()->header('X-App-Key');

if (abs((int)(microtime(true)*1000) - $ts) > 5*60*1000) abort(401,'timestamp expired');
$key = "sig:nonce:{$appKey}:{$nonce}";
if (!Cache::handler()->setnx($key, 1)) abort(401,'replay');
Cache::handler()->expire($key, 300);

// 通过 appKey 查询 appSecret
$appSecret = $this->getAppSecretByKey($appKey);
$payload = request()->method()."\n".request()->path()."\n".hash('sha256', request()->getContent())."\n".$ts."\n".$nonce;
$expected = hash_hmac('sha256', $payload, $appSecret);
if (!hash_equals($expected, $sign)) abort(401,'invalid signature');
```


### 1. API 基础配置

```typescript
// /apps/admin/src/config/api.ts

export const API_CONFIG = {
  // API 基础地址
  baseURL: import.meta.env.VITE_API_BASE_URL || 'https://api.alkaid.com',

  // 超时时间
  timeout: 30000,

  // 请求头
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },

  // 重试配置
  retry: {
    times: 3,
    delay: 1000,
  },

  // 缓存配置
  cache: {
    enable: true,
    maxAge: 5 * 60 * 1000, // 5 分钟
  },
};
```

### 2. 请求拦截器

> 说明：本节 Axios 封装（包含请求/响应拦截器与错误处理）需与《06-frontend-design/25-frontend-error-and-auth-handling-spec.md》保持一致，
> 当前代码示例展示的是推荐实践，后续如有调整以该规范为准。
```typescript
// /apps/admin/src/utils/request.ts

import axios, { type AxiosInstance, type AxiosRequestConfig } from 'axios';
import { message } from 'ant-design-vue';
import { useAccessStore } from '@vben/stores';
import { useAuthStore } from '@/store/modules/auth';
import { useTenantStore } from '@/store/modules/tenant';
import { useSiteStore } from '@/store/modules/site';
import { API_CONFIG } from '@/config/api';

const service: AxiosInstance = axios.create({
  baseURL: API_CONFIG.baseURL,
  timeout: API_CONFIG.timeout,
  headers: API_CONFIG.headers,
});

// 请求拦截器
service.interceptors.request.use(
  (config) => {
    const accessStore = useAccessStore();
    const tenantStore = useTenantStore();
    const siteStore = useSiteStore();

    // 添加 Token
    const token = accessStore.accessToken;
    if (token) {
      config.headers.Authorization = `Bearer ${token}`;
    }

    // 添加租户信息
    if (tenantStore.currentTenantCode) {
      config.headers['X-Tenant-Code'] = tenantStore.currentTenantCode;
    }

    // 添加站点信息
    if (siteStore.currentSiteCode) {
      config.headers['X-Site-Code'] = siteStore.currentSiteCode;
    }

    // 添加请求 ID（用于追踪）
    config.headers['X-Request-ID'] = generateRequestId();

    // 添加时间戳（防止缓存）
    if (config.method === 'get') {
      config.params = {
        ...config.params,
        _t: Date.now(),
      };
    }

    return config;
  },
  (error) => {
    return Promise.reject(error);
  }
);

// 响应拦截器
service.interceptors.response.use(
  (response) => {
    const res = response.data;

    // 统一响应格式
    if (res.code !== 200) {
      message.error(res.message || '请求失败');
      return Promise.reject(new Error(res.message || '请求失败'));
    }

    return res.data;
  },
  async (error) => {
    // Token 过期处理
    if (error.response?.status === 401) {
      const accessStore = useAccessStore();
      const authStore = useAuthStore();

      try {
        // 尝试刷新 Token
        const refreshToken = accessStore.refreshToken;
        if (refreshToken) {
          const result = await authStore.refreshToken();
          accessStore.setAccessToken(result.access_token);
          accessStore.setRefreshToken(result.refresh_token);

          // 重试原请求
          return service(error.config);
        }
      } catch (e) {
        // 刷新失败，跳转登录
        await authStore.logout();
      }
    }

    // 权限不足
    if (error.response?.status === 403) {
      message.error('权限不足');
    }

    // 服务器错误
    if (error.response?.status >= 500) {
      message.error('服务器错误，请稍后重试');
    }

    return Promise.reject(error);
  }
);

/**
 * 生成请求 ID
 */
function generateRequestId(): string {
  return `${Date.now()}-${Math.random().toString(36).substring(2, 9)}`;
}

export function request<T = any>(config: AxiosRequestConfig): Promise<T> {
  return service(config);
}

export default service;
```

### 3. API 模块化

```typescript
// /apps/admin/src/api/user.ts

import { request } from '@/utils/request';

export interface User {
  id: number;
  username: string;
  email: string;
  nickname: string;
  avatar: string;
  status: number;
  created_at: string;
}

export interface UserListParams {
  page: number;
  page_size: number;
  username?: string;
  email?: string;
  status?: number;
}

export interface UserListResult {
  list: User[];
  total: number;
}

/**
 * 获取用户列表
 */
export function getUserList(params: UserListParams) {
  return request<UserListResult>({
    url: '/admin/users',
    method: 'GET',
    params,
  });
}

/**
 * 获取用户详情
 */
export function getUserDetail(id: number) {
  return request<User>({
    url: `/admin/users/${id}`,
    method: 'GET',
  });
}

/**
 * 创建用户
 */
export function createUser(data: Partial<User>) {
  return request<User>({
    url: '/admin/users',
    method: 'POST',
    data,
  });
}

/**
 * 更新用户
 */
export function updateUser(id: number, data: Partial<User>) {
  return request<User>({
    url: `/admin/users/${id}`,
    method: 'PUT',
    data,
  });
}

/**
 * 删除用户
 */
export function deleteUser(id: number) {
  return request({
    url: `/admin/users/${id}`,
    method: 'DELETE',
  });
}

/**
 * 批量删除用户
 */
export function batchDeleteUser(ids: number[]) {
  return request({
    url: '/admin/users/batch-delete',
    method: 'POST',
    data: { ids },
  });
}
```

## 💳 第三方服务集成

### 1. 支付服务集成

> 说明：本小节代码为**设计阶段的示例实现**，主要用于展示推荐的分层设计、异常处理和幂等控制方式；
> 实际落地时，建议将各支付渠道封装为接口 + 适配器，并严格遵守
> 《04-security-performance/11-security-design.md》《05-deployment-testing/17-configuration-and-environment-management.md》
> 中关于密钥管理（如证书/私钥）、回调签名、防重放及多环境配置的规范，避免在代码中硬编码敏感配置。


```php
<?php
// /app/common/service/PaymentService.php

namespace app\common\service;

use app\common\model\Order;
use think\facade\Log;

class PaymentService
{
    /**
     * 支付配置
     */
    protected array $config = [
        'wechat' => [
            'app_id' => '',
            'mch_id' => '',
            'key' => '',
            'cert_path' => '',
            'key_path' => '',
        ],
        'alipay' => [
            'app_id' => '',
            'private_key' => '',
            'public_key' => '',
        ],
    ];

    /**
     * 创建支付订单
     */
    public function createPayment(Order $order, string $paymentMethod): array
    {
        switch ($paymentMethod) {
            case 'wechat':
                return $this->createWechatPayment($order);
            case 'alipay':
                return $this->createAlipayPayment($order);
            default:
                throw new \Exception('不支持的支付方式');
        }
    }

    /**
     * 创建微信支付订单
     */
    protected function createWechatPayment(Order $order): array
    {
        $config = $this->config['wechat'];

        // 构建支付参数
        $params = [
            'appid' => $config['app_id'],
            'mch_id' => $config['mch_id'],
            'nonce_str' => $this->generateNonceStr(),
            'body' => $order->title,
            'out_trade_no' => $order->order_no,
            'total_fee' => $order->amount * 100, // 单位：分
            'spbill_create_ip' => request()->ip(),
            'notify_url' => url('api/payment/wechat/notify', [], false, true),
            'trade_type' => 'NATIVE',
        ];

        // 生成签名
        $params['sign'] = $this->generateWechatSign($params, $config['key']);

        // 调用微信统一下单接口
        $xml = $this->arrayToXml($params);
        $response = $this->httpPost('https://api.mch.weixin.qq.com/pay/unifiedorder', $xml);
        $result = $this->xmlToArray($response);

        if ($result['return_code'] !== 'SUCCESS' || $result['result_code'] !== 'SUCCESS') {
            Log::error('微信支付创建失败', $result);
            throw new \Exception($result['return_msg'] ?? '支付创建失败');
        }

        return [
            'payment_method' => 'wechat',
            'code_url' => $result['code_url'],
            'prepay_id' => $result['prepay_id'],
        ];
    }

    /**
     * 创建支付宝支付订单
     */
    protected function createAlipayPayment(Order $order): array
    {
        $config = $this->config['alipay'];

        // 构建支付参数
        $params = [
            'app_id' => $config['app_id'],
            'method' => 'alipay.trade.page.pay',
            'charset' => 'utf-8',
            'sign_type' => 'RSA2',
            'timestamp' => date('Y-m-d H:i:s'),
            'version' => '1.0',
            'notify_url' => url('api/payment/alipay/notify', [], false, true),
            'biz_content' => json_encode([
                'out_trade_no' => $order->order_no,
                'total_amount' => $order->amount,
                'subject' => $order->title,
                'product_code' => 'FAST_INSTANT_TRADE_PAY',
            ]),
        ];

        // 生成签名
        $params['sign'] = $this->generateAlipaySign($params, $config['private_key']);

        // 构建支付 URL
        $paymentUrl = 'https://openapi.alipay.com/gateway.do?' . http_build_query($params);

        return [
            'payment_method' => 'alipay',
            'payment_url' => $paymentUrl,
        ];
    }

    /**
     * 支付回调处理
     */
    public function handleNotify(string $paymentMethod, array $data): bool
    {
        switch ($paymentMethod) {
            case 'wechat':
                return $this->handleWechatNotify($data);
            case 'alipay':
                return $this->handleAlipayNotify($data);
            default:
                return false;
        }
    }

    /**
     * 处理微信支付回调
     */
    protected function handleWechatNotify(array $data): bool
    {
        // 验证签名
        $sign = $data['sign'];
        unset($data['sign']);
        $expectedSign = $this->generateWechatSign($data, $this->config['wechat']['key']);

        if ($sign !== $expectedSign) {
            Log::error('微信支付回调签名验证失败', $data);
            return false;
        }

        // 更新订单状态
        $orderNo = $data['out_trade_no'];
        $order = Order::where('order_no', $orderNo)->find();

        if (!$order) {
            Log::error('订单不存在', ['order_no' => $orderNo]);
            return false;
        }

        if ($order->status !== Order::STATUS_PENDING) {
            return true; // 已处理
        }

        $order->status = Order::STATUS_PAID;
        $order->paid_at = date('Y-m-d H:i:s');
        $order->transaction_id = $data['transaction_id'];
        $order->save();

        // 触发支付成功事件
        event('OrderPaid', $order);

        return true;
    }

    /**
     * 生成随机字符串
     */
    protected function generateNonceStr(int $length = 32): string
    {
        // 使用加密安全的随机数，默认输出 32 位十六进制字符串
        return bin2hex(random_bytes(intval($length / 2)));
    }

    /**
     * 生成微信签名
     */
    protected function generateWechatSign(array $params, string $key): string
    {
        ksort($params);
        $string = urldecode(http_build_query($params));
        $string .= "&key={$key}";
        return strtoupper(md5($string));
    }

    /**
     * 生成支付宝签名
     */
    protected function generateAlipaySign(array $params, string $privateKey): string
    {
        ksort($params);
        $string = urldecode(http_build_query($params));

        $privateKey = "-----BEGIN RSA PRIVATE KEY-----\n" .
            wordwrap($privateKey, 64, "\n", true) .
            "\n-----END RSA PRIVATE KEY-----";

        openssl_sign($string, $sign, $privateKey, OPENSSL_ALGO_SHA256);
        return base64_encode($sign);
    }

    /**
     * 数组转 XML
     */
    protected function arrayToXml(array $data): string
    {
        $xml = '<xml>';
        foreach ($data as $key => $val) {
            $xml .= "<{$key}><![CDATA[{$val}]]></{$key}>";
        }
        $xml .= '</xml>';
        return $xml;
    }

    /**
     * XML 转数组
     */
    protected function xmlToArray(string $xml): array
    {
        return json_decode(json_encode(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    }

    /**
     * HTTP POST 请求
     */
    protected function httpPost(string $url, string $data): string
    {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
        $response = curl_exec($ch);
        curl_close($ch);
        return $response;
    }
}
```

### 2. 短信服务集成

> 说明：本小节代码为**设计阶段的示例实现**，主要用于展示推荐的分层、异常处理与限流/防刷思路；
> 实际项目落地时，应将短信服务抽象为接口 + 具体适配器，并严格遵守
> 《04-security-performance/11-security-design.md》《05-deployment-testing/17-configuration-and-environment-management.md》
> 等文档中的密钥管理与配置管理规范，不得在代码中直接硬编码 AccessKey、签名等敏感信息。


```php
<?php
// /app/common/service/SmsService.php

namespace app\common\service;

use think\facade\Log;
use think\facade\Cache;

class SmsService
{
    /**
     * 短信配置
     */
    protected array $config = [
        'aliyun' => [
            'access_key_id' => '',
            'access_key_secret' => '',
            'sign_name' => '',
        ],
    ];

    /**
     * 发送短信验证码
     */
    public function sendVerifyCode(string $mobile, string $scene = 'login'): bool
    {
        // 检查发送频率
        $cacheKey = "sms:verify:{$mobile}";
        if (Cache::has($cacheKey)) {
            throw new \Exception('发送过于频繁，请稍后再试');
        }

        // 生成验证码
        $code = $this->generateCode();

        // 发送短信
        $result = $this->send($mobile, [
            'template_code' => 'SMS_123456789',
            'template_param' => json_encode(['code' => $code]),
        ]);

        if (!$result) {
            throw new \Exception('短信发送失败');
        }

        // 缓存验证码（5 分钟有效）
        Cache::set("sms:code:{$mobile}:{$scene}", $code, 300);

        // 设置发送频率限制（60 秒）
        Cache::set($cacheKey, true, 60);

        return true;
    }

    /**
     * 验证短信验证码
     */
    public function verifyCode(string $mobile, string $code, string $scene = 'login'): bool
    {
        $cacheKey = "sms:code:{$mobile}:{$scene}";
        $cachedCode = Cache::get($cacheKey);

        if (!$cachedCode) {
            throw new \Exception('验证码已过期');
        }

        if ($cachedCode !== $code) {
            throw new \Exception('验证码错误');
        }

        // 验证成功后删除验证码
        Cache::delete($cacheKey);

        return true;
    }

    /**
     * 发送短信
     */
    protected function send(string $mobile, array $params): bool
    {
        $config = $this->config['aliyun'];

        // 构建请求参数
        $requestParams = [
            'PhoneNumbers' => $mobile,
            'SignName' => $config['sign_name'],
            'TemplateCode' => $params['template_code'],
            'TemplateParam' => $params['template_param'],
        ];

        // 调用阿里云短信接口
        try {
            // 这里使用阿里云 SDK
            // $client = new \AlibabaCloud\Client\AlibabaCloud();
            // $result = $client->dysmsapi()->sendSms($requestParams);

            Log::info('短信发送成功', ['mobile' => $mobile, 'params' => $params]);
            return true;
        } catch (\Exception $e) {
            Log::error('短信发送失败', ['mobile' => $mobile, 'error' => $e->getMessage()]);
            return false;
        }
    }

    /**
     * 生成验证码
     */
    protected function generateCode(int $length = 6): string
    {
        return str_pad((string)mt_rand(0, pow(10, $length) - 1), $length, '0', STR_PAD_LEFT);
    }
}
```

## 🆚 与 NIUCLOUD 系统集成对比

| 特性 | AlkaidSYS | NIUCLOUD | 优势 |
|------|-----------|----------|------|
| **API 规范** | RESTful + 统一响应 | 部分 RESTful | ✅ 更规范 |
| **请求拦截** | 完整拦截器 | 基础拦截 | ✅ 更强大 |
| **支付集成** | 微信 + 支付宝 | 微信 + 支付宝 | ✅ 相同 |
| **短信集成** | 阿里云 | 阿里云 | ✅ 相同 |
| **错误处理** | 统一错误处理 | 分散处理 | ✅ 更完善 |

---

**最后更新**: 2025-01-19
**文档版本**: v1.0
**维护者**: AlkaidSYS 架构团队

