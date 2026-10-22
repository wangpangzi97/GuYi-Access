# GuYi-Access — security hardening fork

Fork of [GuYiovo/GuYi-Access](https://github.com/GuYiovo/GuYi-Access), preserving the MIT license.

## 重要：v2 不是旧客户端的透明替换

本安全分支移除万能卡和固定管理令牌，禁用客户端任意拉黑，采用短期会话、请求签名、时间戳、原子nonce及真正的密钥轮换。旧的不带签名请求不能继续按原协议使用。未部署生产。

请先阅读 [安全部署、迁移和完整协议](docs/security-v2.md)。需要PHP 8.2+、PDO MySQL、OpenSSL、mbstring与InnoDB；头像需要GD。Web安装已禁用，通过 scripts/install_cli.php 显式初始化管理员和私密配置；已有数据库通过 scripts/migrate.php 升级。

- 终端：HTTPS卡密激活 → 15分钟会话 → HMAC签名心跳/解绑。不要内置全应用api_secret。
- 受信服务端：独立、按应用/动作/有效期授权的管理凭据，默认关闭。
- app_key仅作公开标识，不再是AES密钥。
- [PHP参考客户端](examples/client_v2.php)；[真实数据库与HTTP回归测试](tests/integration.py)。测试会清空专用*_test数据库，严禁使用生产连接。
- 生产配置、卡密和备份不得提交GitHub。请保留LICENSE与上游版权信息。
