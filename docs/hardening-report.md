# 2026-10-22 安全修复交付记录

## 范围与状态

用户授权在 wangpangzi97/GuYi-Access 的独立安全分支修改 GuYiovo/GuYi-Access，基线提交为34c7e500ba5c1858167b283387477d3bfcedf23a。保留MIT许可证；没有改动上游，没有合并main，没有部署生产，没有访问业务实例。

已处理：万能卡、固定管理令牌、辱骂返回、app_key与AES密钥混用、换盐失效、未授权拉黑/解绑、请求签名与重放防护，以及审计追加的XSS、第三方HTML注入、长期管理cookie、默认管理员创建、UA伪认证、并发激活/限流、安装重入、恢复事务、配置保存和分页边界。

注意：这是破坏性协议升级，先读[部署与迁移说明](security-v2.md)。MFA、硬件证明、非对称响应签名没有在本轮实现；不声称终端内秘密不可被逆向。生产HTTPS、数据库最小权限、历史上传隔离、静态资源供应链和真实客户端升级仍由部署验收完成。

## Evidence → Finding → Path

| 证据 | 修复/验证位置 | 已观察结果 |
|---|---|---|
| E-V2-01 | app/Api/Verify.php、VerifyV2.php | 旧入口统一进入v2；不存在特殊卡成功分支或固定管理令牌授权分支 |
| E-V2-02 | app/Security/Protocol.php、tests/integration.py | 合法签名及AES-GCM解密成功；错密钥、篡改、过期/未来时间戳、跨设备/应用、重放均拒绝 |
| E-V2-03 | api_nonces唯一键、两PHP进程 | 同nonce并发仅一个200，另一个409；数据库原子去重，不依赖单进程记忆 |
| E-V2-04 | Database::verifyCard、unbindCardByApi、resetAppSecret | 两设备争用一张卡只有一个成功；解绑恰扣12小时且冷却期拒绝再次扣时；轮换版本加一、旧会话/版本失效，新版激活及心跳成功 |
| E-V2-05 | Database::importAllData | 注入重复主键制造恢复中途失败，原始八表内容保持不变；未知列拒绝；合法完整备份恢复成功 |
| E-V2-06 | tests/integration.py、PHP无OpenSSL测试进程 | 加密不可用返回503，生成卡密事务回滚，卡密数量不增加 |
| E-V2-07 | 管理登录、设置、列表、配置 | HTTPS限制生效；Secure/HttpOnly/SameSite=Strict；登录更换session ID；旧remember cookie无效；改密撤销会话；CSRF拒绝；filter无害标记未进入非预期HTML；limit=0不再除零；设置保存不覆盖标题 |
| E-V2-08 | scripts/install_cli.php、scripts/install.php | 独立空数据库安装退出0，重复安装拒绝退出1；Web安装410，不以自删除为边界 |

P-V2-01，path_type=callflow：兼容入口 → 严格POST JSON与HTTPS → 时间/nonce/版本 → 统一应用启用检查 → 会话或分权管理认证 → 行锁/事务 → 先加密后提交。

P-V2-02，path_type=callflow：用户卡密通过HTTPS激活 → 得到随机会话ID与派生会话秘密 → 签名心跳/同设备解绑；终端不持有应用api_secret。config仅返回公开数据与版本，不能拿到私有变量。

P-V2-03，path_type=callflow：管理员轮换 → 新随机api_secret、key_version加一、删除会话 → 旧版请求401 → 查询公开版本并重新激活 → 新会话成功。服务端对接另行同步其响应解密秘密。

## 执行结果

- PHP 8.4.27：55个PHP文件通过语法检查。
- 本地隔离MariaDB 11.4.6：71项真实HTTP/数据库集成检查通过，退出0、stderr为空。测试包含正常授权流程及负向拒绝，不只是关键字扫描。
- 初次测试因测试驱动把admin_trust的删除cookie误当作PHPSESSID而失败；修正驱动选择后重新通过，原始失败日志保存在本地验证记录中。
- 同一条无timestamp/session/signature的特殊卡请求，用受控Database测试替身运行原始入口：BASELINE为200，返回2099到期及私有测试变量；MODIFIED为401且不返回业务数据；独立副本执行回滚后，ROLLBACK再次为200旧行为。该对照不是对生产泄露的声称；带完整协议字段的特殊卡负向测试也已在真实数据库中拒绝。
- 原始完整源码51个文件的Git blob已保全。补丁可重建修改文件集，并生成逐字节相同的确定性源码ZIP。回滚在有显式标记、无.git的独立副本上执行，51份原始文件哈希全部恢复，新增文件被移除，修复工作副本保持修改状态。
- 回滚仅恢复源码，不恢复数据库，也不应用到线上；恢复后的原版故意重现漏洞，用于证明事务可逆性。

## 本地交付物

MODIFIED_FILE.zip为可解析源码包，DIFF_FILE.patch为原生Git补丁，VERIFICATION.txt保留命令/输入/字面stdout/stderr/退出码及哈希，ROLLBACK.sh为便携shell/Python回滚入口，依赖同目录已核验baseline.zip。源文件的原始/修改哈希、补丁重建和回滚结果有单独JSON清单；私有部署配置和数据库测试运行时不在Git仓库或源码包中。

## 时间线

1. 2026-10-22：确认用户Fork、写权限及上游来源，建立security/harden-v2分支和原始字节基线。
2. 2026-10-22：执行基线对照；修改API、数据库原子边界、后台与CLI安装路径，更新协议文档及参考客户端。
3. 2026-10-22：修正执行环境、测试驱动和换行转换问题；真实集成检查通过，保留失败来源与退出状态。
4. 2026-10-22：生成补丁与确定性源码包，在独立副本还原原始哈希并验证旧行为重现；交付安全分支供PR审阅，不自动部署。
