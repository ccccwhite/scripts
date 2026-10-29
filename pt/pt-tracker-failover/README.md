# PT Tracker 故障切换（Unraid / qBittorrent / Transmission）

独立项目，不覆盖其他 PT 脚本。按同站备用域名尝试切换 qBittorrent 中 Tracker 状态 4（异常）的种子，并通过 **相同 Info Hash** 同步 Transmission 4.1.x 的 Tracker。Unraid 6.12.13，宿主 PHP/cURL，无需 Python。

## 安全性
- 默认 `dry_run: true`，`--preview` 永远只读。
- 只处理同时匹配 **站点分类和 Tracker 精确域名** 的异常种子；没有配置的站点、只有一个 Tracker 的站点都不会处理。
- 配置的 `trackers` 是备用 Tracker 的 **origin**（如 `https://tra99.manfuz.co`），按数组先后选择能直连的域名。保留种子的 `/announce` 和私人密钥参数，不在日志中展示。
- 检测由 qBittorrent 容器里的 curl 直接测试 IPv4/IPv6 HTTPS 根路径，**禁用代理**；HTTP 403/404 也可证明 TLS 连通，但**不代表真实 announce 正常**。首次生产请人工验证某个真实种子（当前 `tra99.manfuz.co` 已在 NNPJ-318 上实际汇报成功）。
- 只更新 Tracker，不删除、下载、停止、移动种子，不改变分类、标签、路径。Tracker 修改可能产生汇报，请遵守站点限频。
- 只有 qBittorrent 确认修改后才对 Transmission 同 Hash 的种子调用 RPC `torrent_set`，通过 `tracker_list` 原位替换旧域名，保留 Tracker 的层级分组与其他域名。
- 若中断或者同步失败，保存 `pending_sync.json`，下次先补 Transmission；未处理完成时停止新增操作。注意这是补偿机制，不是跨客户端原子事务。
- 每次最多 `max_changes_per_run` 条（默认 5），修改间隔 `delay_seconds` 秒。建议初次只运行一次、先观察两端 Tracker 状态，不要立即设置短周期自动任务。
- 仅限 HTTP(S) Tracker origin，不处理 UDP Tracker；若当前错误属于 Passkey 无效、封禁、种子删除，**不要**尝试换域名解决（当前版本依赖状态 4，不对全部错误消息语义做严格判别）。

## 依据 M-Team 官方排障说明
参考 https://wiki.m-team.cc/zh-tw/trackerproblem ：Tracker 不可达往往是 Cloudflare 网络连通性，官网建议换访问域名（例如 cc → io）或尝试 Cloudflare 优选 IP；访问 Tracker 根目录得到拒绝页面并不意味着 announce 故障；站点控制台设置只影响**之后下载**的种子，不会修改已有下载器任务。官方还区分 `Forbidden`（Cloudflare 拒绝）、`timed out`（网络故障）、`Client is banned`（客户端不允许）、`torrent not register`（种子已删）、`you already are downloading the same torrent`（重复连接），不要全部当作可切换域名的错误。

脚本采用多官方域名 **有限次直连探测**，不自动设置 CF hosts / 优选 IP，以免污染整台服务器的 DNS。

## 安装
```bash
BASE=/boot/config/plugins/user.scripts/pt-tracker-failover
mkdir -p "$BASE"
URL=https://raw.githubusercontent.com/ccccwhite/scripts/main/pt/pt-tracker-failover
for f in pt_tracker_failover.php config.json script; do
  curl -fL --retry 3 --proxy http://192.168.1.199:7890 "$URL/$f" -o "$BASE/$f" || exit 1
done
chmod 755 "$BASE/script"
php -l "$BASE/pt_tracker_failover.php"
bash -n "$BASE/script"
bash "$BASE/script" --preview
```
以上代理 **只用于下载 GitHub 脚本**；运行时全部 Tracker 连通性测试和客户端 API 均为直连。

如果本地已有 `config.json`，不要覆盖。Shared credentials 使用 `pt-common/credentials.json`，其中 `qbittorrent` 包含 `url,username,password`，`transmission` 包含 `rpc_url,username,password`，凭据不上传 GitHub。

## 命令
```bash
BASE=/boot/config/plugins/user.scripts/pt-tracker-failover
bash "$BASE/script" --status
bash "$BASE/script" --preview
# 观察预览，编辑 "$BASE/config.json" 设置 dry_run=false 后：
bash "$BASE/script" --apply
```
`pending_sync.json` 和 `run.lock` 为本地生成文件，绝不上传 GitHub。完成一次修改之后检查 qBit/Transmission 中相同 Hash 是否指向同一站点的新地址。未检验实际 Transmission RPC 同步前请保持 dry_run=true。

## 站点配置
`sites` 可加入多个站点（如站点 A: `categories:["sitea"]`、`trackers:["https://tra.sitea.example","https://trb.sitea.example"]`），**只需要配置一个站点存在两个或更多受支持的 Tracker 域名时** 才写入此站点。候选域名必须由站点官方提供，不能跨站点转移 Passkey。

只有一个域名的站点无需配置、自动跳过；后续官方公布新域名再补充。多个来源站点共用分类时需要更细的识别规则，暂不支持。
