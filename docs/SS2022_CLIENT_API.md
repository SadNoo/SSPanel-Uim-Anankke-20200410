# SS2022 单端口与客户端 API v1

本文档描述本 fork 新增的 SS2022 多用户单端口节点，以及 iOS、Android、macOS、Windows 和 Linux 客户端共用的配置 API。旧 `/api/token` 接口保持兼容，但新客户端不应继续使用它。

## 1. 部署迁移

先备份数据库，然后执行：

```bash
mysql -u panel -p panel_database < sql/2026-07-18-ss2022-client-api-v1.sql
```

迁移会把 `ss_node.server` 扩到 255 字符，并创建只保存 token SHA-256 摘要的 `client_api_tokens` 表。

## 2. 添加 SS2022 单端口节点

在“管理面板 → 节点 → 添加节点”中选择：

```text
Shadowsocks 2022 单端口多用户
```

节点地址严格使用：

```text
host;port;server_key_base64
```

例如：

```text
ss2022.example.com;443;AAECAwQFBgcICQoLDA0ODxAREhMUFRYXGBkaGxwdHh8=
```

服务端密钥必须是标准 Base64，解码后为 32 字节。可生成新密钥：

```bash
openssl rand -base64 32
```

面板会强制该节点使用 `sort=14`、`2022-blake3-aes-256-gcm` 和单端口模式。用户密钥派生与 `sshappy/sshappy/keys.py` 一致：

```text
base64(sha256(user_id|passwd|method|node_id|server_key_base64))
```

mihomo 接收到的密码为：

```text
server_key_base64:user_key_base64
```

用户节点列表和 Vue 接口只显示 `host:port`，不会返回服务端密钥。

## 3. API 一览

基础路径：`/api/client/v1`

| 方法 | 路径 | Bearer | 用途 |
| --- | --- | --- | --- |
| GET | `/capabilities` | 否 | 客户端启动时协商能力 |
| POST | `/auth/login` | 否 | 使用 SSPanel 邮箱和密码登录 |
| POST | `/auth/refresh` | 否 | 轮换 refresh token |
| POST | `/auth/logout` | 是 | 撤销当前设备整个 token family |
| GET | `/me` | 是 | 安全的账户摘要 |
| GET | `/subscription` | 是 | 套餐、流量和配置入口 |
| GET | `/subscription/config` | 是 | 获取 mihomo YAML 或分享链接 |

访问 token 有效期 15 分钟，refresh token 有效期 30 天且每次刷新后立即失效。原始 token 不写入数据库，也不支持 query string token。

支持平台值：`ios`、`android`、`macos`、`windows`、`linux`。兼容别名 `mac`、`osx`、`pc`、`win`。

## 4. 登录与更新示例

登录：

```bash
curl -X POST https://panel.example.com/api/client/v1/auth/login \
  -H 'Content-Type: application/json' \
  -d '{"email":"user@example.com","password":"password","platform":"ios","device_name":"iPhone"}'
```

启用了 TOTP 的账户需要同时发送 `totp_code`。如果面板开启网页登录验证码，API 会返回 `CAPTCHA_REQUIRED`；客户端应引导用户打开返回的 `login_url`。生产环境还应在反向代理层为登录接口设置 IP 和账号维度的限速。

读取订阅摘要：

```bash
curl https://panel.example.com/api/client/v1/subscription \
  -H 'Authorization: Bearer ca_ACCESS_TOKEN'
```

下载 iOS mihomo 配置：

```bash
curl 'https://panel.example.com/api/client/v1/subscription/config?platform=ios&format=mihomo' \
  -H 'Authorization: Bearer ca_ACCESS_TOKEN'
```

下载 Windows 配置：

```bash
curl 'https://panel.example.com/api/client/v1/subscription/config?platform=windows&format=mihomo' \
  -H 'Authorization: Bearer ca_ACCESS_TOKEN'
```

桌面平台预设包含本机 `mixed-port: 7890`；移动平台不创建本地监听端口，由 App 的 Network Extension/VPN 生命周期管理。配置不包含 `external-controller`，且固定 `allow-lan: false`。

客户端可保存响应的 `ETag`，下次通过 `If-None-Match` 检查更新。配置未变化时服务端返回 `304`。这属于节点与规则数据热更新，不包含可执行代码，可作为 iOS、Android、macOS 和 Windows 共用的安全更新通道。

刷新令牌：

```bash
curl -X POST https://panel.example.com/api/client/v1/auth/refresh \
  -H 'Content-Type: application/json' \
  -d '{"refresh_token":"cr_REFRESH_TOKEN"}'
```

客户端必须原子替换 access token 与 refresh token；旧 refresh token 不能再次使用。服务端检测到已轮换 refresh token 被重放时，会撤销该设备的整个 token family。

## 5. 节点与账户过滤

SS2022 API 与现有 `sshappy` 后端保持相同约束：

- 用户 `enable=1`；
- 账户未过期且剩余流量大于 0；
- 节点为 `sort=14`、`type=1`，并且未达到节点流量上限；
- 普通用户满足节点等级与节点群组；管理员可以访问所有 SS2022 节点。

新 mihomo 配置还会保留面板中已有、当前账号可访问的 Shadowsocks 和 VMess 节点。

## 6. 客户端错误处理

JSON 响应统一包含 `ok`、`data`、`error` 和 `meta`。建议客户端至少处理：

- `INVALID_CREDENTIALS`：邮箱或密码错误；
- `TOTP_REQUIRED` / `INVALID_TOTP`：需要或错误的两步验证码；
- `CAPTCHA_REQUIRED`：需要网页验证；
- `UNAUTHORIZED` / `INVALID_REFRESH_TOKEN`：重新刷新或重新登录；
- `SUBSCRIPTION_UNAVAILABLE`：账户禁用、过期或流量耗尽；
- `NO_AVAILABLE_NODES`：当前账户没有可用节点。
