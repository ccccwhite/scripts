# 公共认证配置

复制 `credentials.example.json` 到 Unraid 的 `/boot/config/plugins/user.scripts/pt-common/credentials.json`，填写 qBittorrent / Transmission 账号密码。

设置权限：`chmod 700 /boot/config/plugins/user.scripts/pt-common`，`chmod 600 /boot/config/plugins/user.scripts/pt-common/credentials.json`。

每个脚本 `config.json` 中设置 `"credentials_file": "/boot/config/plugins/user.scripts/pt-common/credentials.json"`，加载的认证信息会覆盖脚本配置内同名客户端连接项。**不要上传真实 credentials.json。**
