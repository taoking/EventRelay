# Webhook HMAC 签名 v1

启用端点签名后，`POST /api/endpoints/{endpointId}/signing-secret` 会在响应中**仅一次**返回 `secret`。接收方必须安全保存该完整 `whsec_...` 字符串，并将其 UTF-8 bytes 直接作为 HMAC key；不要移除 `whsec_` 前缀。

EventRelay 发送的 HTTP body 是 JSON 原始字节。验签必须读取 raw request body，不要重新 JSON encode、格式化或排序键。

签名请求包含：

- `X-EventRelay-Signature: v1=<64 位小写 hex>`
- `X-EventRelay-Timestamp: <Unix seconds>`
- `X-EventRelay-Signing-Key-Id: <key UUID>`
- `X-EventRelay-Delivery-Id: <delivery UUID>`
- `X-EventRelay-Attempt: <attempt number>`

v1 的 canonical bytes（没有尾随换行）为：

```text
v1\n
<timestamp>\n
<delivery-id>\n
<attempt-number>\n
<raw HTTP body>
```

即：

```php
$canonical = "v1\n{$timestamp}\n{$deliveryId}\n{$attemptNumber}\n{$rawBody}";
$expected = hash_hmac('sha256', $canonical, $secret);
$valid = hash_equals($expected, $receivedHex);
```

接收方应以 `X-EventRelay-Signing-Key-Id` 选择相应的当前或退休密钥，并自行执行 timestamp freshness 检查及基于 Delivery ID 的业务去重。EventRelay 是 at-least-once 系统：稳定 Delivery ID 可帮助接收方去重，但不代表 exactly-once。
