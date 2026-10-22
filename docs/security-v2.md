# 安全分支 v2：部署、迁移和客户端协议

这是协议破坏性升级，不应把安全分支直接覆盖到正在运行的旧系统。先做数据库及部署配置备份，在隔离环境验证客户端。保留原项目 MIT 许可证。

## 1. 环境与安装

- PHP 8.2+，推荐使用当前受支持版本并及时安装安全更新；需要 PDO MySQL、OpenSSL、mbstring。头像上传还需要 GD。
- MySQL/MariaDB，业务和安全状态表必须使用 InnoDB。本次回归使用 PHP 8.4.27 与隔离的 MariaDB 11.4.6；不是宣称已覆盖所有版本。
- 生产站点必须 HTTPS；由反向代理终止TLS时，应在受信 Web/FastCGI配置中正确设置 HTTPS=on，不信任客户端传来的 X-Forwarded-Proto。
- 数据库用户的建表/迁移权限只在安装、升级时使用。日常请求不再执行 DDL。迁移完成后改用最小权限数据库用户。
- 设置 GUYI_DB_HOST、GUYI_DB_PORT、GUYI_DB_NAME、GUYI_DB_USER、GUYI_DB_PASS。新安装同时显式设置 GUYI_ADMIN_USER 和 GUYI_ADMIN_PASSWORD（至少12字节）。
- 从命令行运行 `php scripts/install_cli.php`。它不会覆盖已有管理员或 config/local.php，不再提供默认账号。完成后从环境中移除管理员密码。
- 部署秘密位于被 Git 忽略的 config/local.php，也可通过 GUYI_CONFIG_FILE 指定非网站目录文件，或通过上述环境变量提供。SYS_SECRET 为独立随机32字节的小写十六进制表示，禁止提交仓库。
- Web安装器及兼容安装入口始终410，不依赖“自删除成功”作为安全边界。

建议站点文档根目录为 public，接口路径为 `/api/verify.php`，管理入口为 `/admin/cards.php`；将 `/assets/` 作为只读静态目录映射到仓库 assets，禁止其中任何脚本执行和目录索引。兼容项目根目录部署时，旧的 `/Verifyfile/api.php` 也进入同一 v2 处理器。服务器还应拒绝访问 .git、config、tests、scripts 及数据库备份。

## 2. 已有部署迁移

1. 在网站外保全旧部署配置、数据库备份和现用客户端版本，验证备份能还原。不要把含密码、卡密、api_secret 的备份提交 GitHub。
2. 把现用数据库配置安全转移到 config/local.php 或部署环境；不要从仓库示例推断你的生产连接信息。生成新的 SYS_SECRET，旧管理会话将失效。
3. 维护窗口执行 `php scripts/migrate.php`，新增 key_version、last_unbind_at、api_sessions、api_nonces、api_rate_limits。
4. 登录管理页，轮换所有空值、非法或曾与 app_key 相同的 api_secret；旧值默认拒绝，绝不回退到 app_key。
5. 同步升级客户端到本协议，再切流。禁用应用的所有 API 分支都拒绝访问。回退旧客户端不能成为重新打开万能卡或免签接口的理由。

## 3. 调用者及动作

| 动作 | 所需身份 | 成功响应 |
|---|---|---|
| config | 公开 app_key；只读，不等于认证 | 明文JSON，仅公开变量、更新元数据、当前key_version |
| activate | HTTPS上的合法卡密、明确device_hash、timestamp/nonce | 明文JSON，授权数据及短期会话秘密；绝不返回api_secret |
| verify / heartbeat | 当前短期会话及HMAC签名 | AES-GCM封装 |
| unbind | 同卡同设备会话及HMAC签名 | AES-GCM封装；成功扣12小时，至少24小时冷却；不足12小时拒绝 |
| generate / ban / unban / del_card / kick | 按应用及动作授权的服务端对接凭据、有效期、HMAC签名 | 用该应用api_secret派生密钥的AES-GCM封装 |
| ban_machine / blacklist | 客户端及对接API均不可执行 | 403；全局黑名单只能在已登录且CSRF校验通过的后台修改 |

设备哈希和UA均不是秘密，UA不再作为“专属客户端”身份证明。终端客户端不要持有全应用api_secret或管理凭据；初始认证仍依赖卡密持有证明与HTTPS，不宣称能阻止授权用户逆向修改自己的客户端。

管理对接默认关闭。服务器环境 GUYI_MANAGEMENT_CREDENTIALS 是JSON对象，以凭据ID为键，每项包含：`app_key`（单个授权应用）、`secret`（独立随机32字节的小写十六进制）、`actions`（允许动作数组）、`expires_at`（整数Unix秒）。任一字段缺失或过期拒绝；删除该项即可撤销。secret只交给受信服务端，不放在URL、前端代码或日志中。无需也不接受旧的api_token。

## 4. 请求格式及签名

所有动作仅接受POST、Content-Type: application/json，正文不超过64KiB，JSON最大嵌套16层，不接受查询参数与正文混合。每个请求都带：

- X-Timestamp：10位Unix秒；相对服务端偏差不得超过±300秒。
- X-Nonce：16字节随机数的小写十六进制（32字符）。每个逻辑请求重新生成。
- X-Key-Version：正整数。config可用旧版本查询当前版本；其余动作必须匹配当前应用版本。
- 已登录会话：X-Session-Id；服务端对接：X-Credential-Id；两者的受保护动作还必须带X-Signature。

正文公共字段为app_key、action。activate需要card_code（至多50字节）、device_hash（1–100字节）；verify/heartbeat/unbind只从会话确定卡，不允许通过额外card_code替换身份。custom_data至多4096字节。generate接受type、num（整数1–1000）、pre（至多20字节）、note（至多500字节）、custom_hours（custom类型须为正数，至多87600小时）。app_id不能覆盖凭据授权范围。

签名原文按以下顺序，以一个LF分隔，无结尾LF：

```text
v2
POST
实际请求URL的路径（不含scheme、host、query）
session:会话ID 或 management:凭据ID
X-Key-Version
X-Timestamp
X-Nonce
原始HTTP正文的SHA-256小写十六进制
```

摘要必须基于真正发送的原始UTF-8字节，不能重新序列化JSON。不同入口路径的签名不能互用。请求签名密钥为：HKDF-SHA256，IKM=hex_decode(secret)，length=32，info=`guyi-v2/request-sign`，salt为空。X-Signature为HMAC-SHA256的小写十六进制；服务端hash_equals比较。

会话secret取activate返回的session_key；管理请求secret取受信部署环境的对接secret。api_secret不作管理请求身份凭据。

nonce以principal+key_version隔离，在MySQL唯一键下原子消费，保存600秒；跨PHP进程也只能成功一次。合法重试也应使用新nonce；若需要“同业务请求幂等返回原结果”，需在调用服务里另行设计idempotency key，不能复用已成功请求的nonce。数据库不可用时失败关闭，不退回进程内记忆。

## 5. 会话、AES和轮换

activate返回data中的session_id、session_key、key_version、expires_at；会话至多15分钟且不超过卡的到期时间。每张卡只保留最近会话。服务端不存储明文session_key，而由应用秘密与随机session_id派生；轮换立即删除该应用全部会话。

受保护响应结构：protocol=v2、aad、encrypted_data。encrypted_data为base64(12字节IV || 16字节tag || ciphertext)。AES密钥为HKDF-SHA256，IKM=hex_decode(secret)，length=32，info=`guyi-v2/response-encrypt`，salt为空。会话响应使用session_key，对接响应使用应用api_secret。请求签名和响应加密密钥用途隔离。

AAD为 `v2:app_id:key_version:request_nonce:200`。客户端应检查自己预期的app_id、版本和本次nonce，再验证GCM tag；不能只解密而不验证请求绑定。参考 `examples/client_v2.php`，将可信配置中的app_id附到会话参数中。

管理页“轮换密钥”生成新的api_secret，key_version加一并撤销会话。旧版本请求立即401；config允许公开查询新版本，终端随后重新activate。管理对接方必须同步取得新的响应解密secret。OpenSSL不可用、返回false或生成错误tag时返回503，不把受保护响应降级为明文；加密发生在数据库提交之前。

错误为真实HTTP 4xx/5xx及中性JSON，不含数据库异常或部署秘密。配置与激活成功的明文响应是明确的HTTPS协议步骤，不是加密失败回退。

## 6. 后台和数据恢复

- 不再接受十年admin_trust cookie。管理会话为最长1小时空闲期，Secure、HttpOnly、SameSite=Strict；密码变化每次请求校验并撤销既有会话。没有实现MFA，请结合反向代理访问控制配置。
- 登录按账号和IP在数据库中原子限速；API按IP每分钟60次。多节点需共享同一安全状态数据库。
- 全部管理写请求及导出都要求POST与CSRF。改密还需当前密码。
- 上传头像至多2MiB、2048×2048，仅验证后的JPEG/PNG/WebP并重新编码PNG；不再接收SVG。历史上传文件没有自动删除，升级时应隔离检查。
- 备份恢复要求完整八表JSON、可信字段、有效应用秘密及管理员哈希，最多10MiB/10万记录；拒绝非InnoDB。使用事务性DELETE/INSERT，不用TRUNCATE，不关闭外键。约束失败回滚，成功恢复撤销API会话及nonce，当前管理会话退出。
- 旧备份中无效/曾公开的api_secret必须先安全轮换，不能为了恢复而重新启用泄露秘密。数据备份及配置必须分开加密保全。

## 7. 测试与回滚

`tests/integration.py` 会清空指定的专用测试数据库，必须同时设置GUYI_TESTING=1及以_test结尾的GUYI_DB_NAME。不要对生产运行。指定PHP_BINARY与GUYI_DB_*、GUYI_SYS_SECRET后运行 `python tests/integration.py`。测试覆盖真实PDO数据库、HTTP请求、AES解密、跨进程nonce、两设备激活竞争、密钥轮换、解绑及恢复失败回滚，不仅是字符串扫描。

修复包的ROLLBACK.sh仅用于带显式标记的独立源码副本，恢复的是有漏洞的原始源码，**不能对线上使用**，也不能替代数据库恢复。上线升级必须保留数据库备份和独立配置。此次未向任何生产实例部署。

仍需部署方负责：HTTPS终止配置、数据库权限、服务器级请求大小和上传限制、静态目录禁止执行、日志与备份访问控制、客户端改造、MFA/硬件证明等可选增强。第三方HTML公告及动态更新HTML已关闭；现有静态CDN资源应按组织供应链策略自行托管或固定版本。
