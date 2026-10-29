# PT Tracker IP Monitor（qBittorrent + Transmission）

适用 Unraid 6.12.13 / qBittorrent 5.3.x / Transmission 4.1.x。自动从两个客户端种子中**发现 HTTPS Tracker 域名**，不预设某个 Tracker。只对异常域名挑选最多 2 个运行中的种子（优先两端各 1 个）重新汇报，等待新的汇报结果；再次确认异常，才从 DNS 实时查询候选 IPv4、双容器直连测试，并可同步更新两容器运行时 `/etc/hosts`。**不修改种子 Tracker URL、Passkey、文件或标签。**

## 公共配置
`pt-common/tracker-ip-common.json` 放到 `/boot/config/plugins/user.scripts/pt-common/tracker-ip-common.json`。它只配置**哪些 Tracker 域名同属一个站点、可共用哪组候选 DNS 来源**，不写入账号与密钥。默认的 M-Team 域名归属及 Cloudflare DNS 来源只是示例，需核对实际网站公告。**不在公共配置里的单域名站点同样会被自动监测**，但候选 IP 只从自身 A 记录获取，避免借用其他站点 IP。

监控本地 `config.json` 仅包含公共配置路径、API 凭据路径、Docker 容器名以及运行频率、抽样上限和冷却时间；已删除旧版 `failed_ratio`、`min_failed_trackers`、`consecutive_bad_runs`、`tracker_domain`、`categories`、`dns_sources`。

## 默认安全机制
- `dry_run=true`；`--preview` 不发送汇报、不写 hosts；`--apply` 才会执行。不要同时让 `pt-tracker-failover` 运行写入模式。
- 只统计运行中种子的网络故障；不把已暂停种子的旧错误当作实时故障。
- 同一域名每次至多重新汇报 2 个；最短间隔 1800 秒，至少经过 180 秒后才核对新结果。最多每轮处理 2 个域名。
- 不同域名独立保存检测状态和选定 IP；中断不会将一个站点的状态套到其他站点。
- `curl --resolve` 验证 IPv4 HTTPS/TLS；HTTP 403/404 只能证明 TLS 连通，**不能证明 Tracker announce 正常**。
- 只有两个容器均测试通过才会切换；发生写入错误会尽力回滚。Docker 容器重建会清除临时 hosts，下次脚本运行才恢复；原有 Docker Extra Hosts 配置冲突则拒绝修改。
- 当前仅处理 HTTPS 默认 443 端口；UDP/HTTP/其他端口 Tracker 会被跳过。
- **尚未在真实客户端完成双端重新汇报及切换联调；不要直接设置无人值守 --apply。**

## IP 切换后批量重新汇报

切换 IP **之前**仍然对每个异常域名抽样最多 2 个种子确认故障。切换 IP **之后**，自动把当时 qBittorrent 与 Transmission 中属于该域名、正在运行、Tracker 为网络异常的种子逐个放入队列，**全部各重新汇报一次**，不动暂停种子或正常 Tracker。为避免集中请求，默认每条间隔 5 秒、每轮最多 120 个任务；剩余的由下一次 `--apply` 继续。比如 108+8 条预计需要约 10 分钟。队列存储在现有 `state.json`，只包含客户端和 Hash，不存 Passkey；每一条请求前持久化进度，重复执行不会重复发送已处理任务。

如果升级前已经切过 IP，且旧状态尚无批量进度，首次 `--apply` 会为此前 IP 自动建立并处理一次异常种子队列。此操作会向 PT 站产生真实 Tracker 请求，请先确认符合站点限流要求。`--preview` 绝不发起汇报。

**当前未实现新 IP 的真实 Announce 失败自动回滚**，请核实两个下载器恢复情况，确认前不要启用无人值守的定时切换。

## 首次安装
```bash
BASE=/boot/config/plugins/user.scripts/pt-tracker-ip-monitor
COMMON=/boot/config/plugins/user.scripts/pt-common
URL=https://raw.githubusercontent.com/ccccwhite/scripts/main/pt
mkdir -p "$BASE" "$COMMON"
curl -fL --proxy http://192.168.1.199:7890 "$URL/pt-common/tracker-ip-common.json" -o "$COMMON/tracker-ip-common.json"
for f in pt_tracker_ip_monitor.php config.json script; do
  curl -fL --proxy http://192.168.1.199:7890 "$URL/pt-tracker-ip-monitor/$f" -o "$BASE/$f" || break
done
php -l "$BASE/pt_tracker_ip_monitor.php"
bash "$BASE/script" --preview
```

既有配置升级时不要覆盖凭据。旧版状态文件不兼容新多域名状态结构：请备份并人工检查旧 `state.json` 后再迁移，不要直接覆盖或者删除旧状态，避免丢失现有已应用 hosts 记录。

## 命令
```bash
BASE=/boot/config/plugins/user.scripts/pt-tracker-ip-monitor
bash "$BASE/script" --status
bash "$BASE/script" --preview
# 本地 config.json 改 dry_run=false 并完成验证后：
bash "$BASE/script" --apply
```

推荐验证通过后每 15 分钟调用一次 `--apply`，但不要在测试前创建定时任务。
