# PT Tracker IP Monitor — qBittorrent + Transmission

适配 Unraid 6.12.13、qBittorrent 5.3.0、Transmission 4.1.3。**两个客户端同时定期检查** `tracker.m-team.cc`，连续判定网络异常时测试 Cloudflare 候选 IPv4，然后同步更新**两个 Docker 容器的运行时 `/etc/hosts`**，保留全部 Torrent Tracker URL、Passkey、路径、标签。与已有更换 Tracker URL 的 `pt-tracker-failover` 不同，两个方案不要同时启用 `--apply`。

## 默认安全条件
- `dry_run: true`；`--preview` 只读，不触发重新汇报。启用 `--apply` 后才对样本发起重新汇报。
- 必须成功读取 **qBittorrent 与 Transmission** 的 API，任何一端不可用即中止。
- qBit 检查目标域名各条种子的 Tracker 状态；状态 4 且消息属于 timed out、stream truncated 等网络故障才计为异常，状态 1 不算故障。
- Transmission 读取 `tracker_stats`，以 `last_announce_succeeded` / `last_announce_result` 等判断是否成功或网络故障。
- 同一 Tracker 发现网络异常时，最多从运行中的任务抽取 2 个样本，优先 qBittorrent、Transmission 各 1 个。只对样本重新汇报；下次检查确认产生新汇报且仍异常，才考虑切换 IP。
- 在两个容器内用 `curl --resolve` 进行无代理 IPv4 TLS 探测，选取两个容器都可完成 TLS 握手且合计握手时间较短的候选 IP。HTTP 403/404 仍可能通过 TLS，**不能证明实际 announce 成功**；只用于筛选候选，实际需观察下次 Tracker 汇报。
- 只维护目标域名 `tracker.m-team.cc`。检测到 Docker `extra_hosts`、`--add-host` 或第三方未管理的同域名记录时拒绝覆盖。修改之前预检查两个容器；第二个失败时尝试回滚。
- `/etc/hosts` 运行时写入**不会跨容器重建持久化**。已选 IP 存在本地 `state.json`，每次 `--apply` 会先同步恢复至两个运行中的容器，避免容器重建后长期丢失。切换不重启容器，也不关闭 IPv6 Peer。
- 监测不会强制 Tracker announce，更不会删除、停止或移动种子。若候选 IP 被 Cloudflare WAF 拒绝，即使 TLS 通过也可能无法汇报，需人工验证。
- 不再保存固定候选 IPv4：仅在抽样重新汇报确认失败后，从 `config.json` 的 `dns_sources` 实时查询 A 记录，去重并从两个容器直连验证；若无可用候选，保持原 IP 不变。DNS 查询结果并不自动等于 Tracker 可用。
- 正式启用前不建议另开 `pt-tracker-failover` 写入模式。M-Team 官方 CF 线路参考：https://wiki.m-team.cc/zh-tw/cf-choose-better-ip

## 重新汇报限流

每个 Tracker 每轮最多选取 `sample_torrents_per_tracker=2` 个运行中的异常种子。抽样后至少经过 `probe_settle_seconds=180` 秒再读取结果；`min_reannounce_interval_seconds=1800` 防止重复请求。Transmission 只选择不混有其他 Tracker 域名的种子。若任一抽样汇报恢复正常则不切换 IP。

## 安装

```bash
BASE=/boot/config/plugins/user.scripts/pt-tracker-ip-monitor
URL=https://raw.githubusercontent.com/ccccwhite/scripts/main/pt/pt-tracker-ip-monitor
mkdir -p "$BASE"
for f in pt_tracker_ip_monitor.php config.json script; do
  if [ -e "$BASE/$f" ]; then echo "已有 $f，请先确认后更新"; continue; fi
  curl -fL --retry 3 --proxy http://192.168.1.199:7890 "$URL/$f" -o "$BASE/$f" || exit 1
done
chmod 755 "$BASE/script"
php -l "$BASE/pt_tracker_ip_monitor.php"
bash -n "$BASE/script"
bash "$BASE/script" --status
bash "$BASE/script" --preview
```

GitHub 下载命令使用树莓派代理只是为了拉取代码；Tracker 测试、客户端 API 和解析修改过程中均**明确禁用代理**。

共享凭据沿用 `/boot/config/plugins/user.scripts/pt-common/credentials.json`，不将凭据、Passkey 和运行态文件上传 GitHub。

## 联调成功后开启自动检查

先检查 `--preview` 的两个客户端数据、候选 IP 结果和解析冲突。**首次生产环境运行仍需人工观察一次真实 announce**，不要仅凭 HTTPS 403 开启无人值守切换。

将本地 `config.json` 的 `dry_run` 设置为 `false`，然后在 Unraid User Scripts 建立新任务 `pt-tracker-ip-monitor-auto`：

```bash
#!/bin/bash
bash /boot/config/plugins/user.scripts/pt-tracker-ip-monitor/script --apply
```

Cron: `*/15 * * * *`（每 15 分钟）。两个下载器每次都会检查；连续两次达到阈值、距上次切换超过 1 小时才会更换。也可在 Array Startup 建立单独一次性任务恢复解析，注意容器需已运行。

```bash
BASE=/boot/config/plugins/user.scripts/pt-tracker-ip-monitor
bash "$BASE/script" --status
bash "$BASE/script" --preview
bash "$BASE/script" --apply
```

**限制**：Docker 容器的 `/etc/hosts` 由 Docker 管理，应用或重建容器会覆盖手工条目；脚本在下一次执行时再修复，不会永久改变 Docker 配置模板。切换后客户端上报状态存在延迟；过于频繁切换会影响 PT 站请求频率。
