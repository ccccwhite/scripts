# 公共认证与 Tracker 配置

认证信息：复制 `credentials.example.json` 至 Unraid 的 `/boot/config/plugins/user.scripts/pt-common/credentials.json` 并填写 qBittorrent / Transmission 的账号密码。权限建议目录 700、认证文件 600。其他 PT 脚本通过 `credentials_file` 字段复用此路径。**不要把真实凭据或 Tracker Passkey 上传 GitHub。**

共享 Tracker 配置：`tracker-ip-common.json`，部署到同目录。不含密钥；配置 `sites` 和 `dns_groups`，供 `pt-tracker-ip-monitor` 复用：

- `sites.<站点>.trackers`：属于同一站点的 Tracker 主机名（不含协议、路径和 Passkey），用于确定可共用哪些候选 DNS。
- `sites.<站点>.dns_group`：引用 `dns_groups` 的名称。
- `dns_groups.<组名>`：候选 A 记录查询的公共 DNS 名称列表。
- **异常域名由 qBit/Transmission 实时发现，无需在脚本 config 中写死。**未登记站点仍检测，但只查询自身 DNS。
- 共享配置只用于候选 IP 发现，**不会替换 Tracker URL**。必须确认所选 IP 可为原 Tracker 域名正确提供服务，TLS 成功不等于实际 Announce 成功。
