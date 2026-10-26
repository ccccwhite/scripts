# PT 分类同步（Unraid）

qBittorrent 站点分类 → Transmission 4.1.3 标签。支持相同 Info Hash 匹配，以及通过 Tracker 域名识别不同 Hash 的跨站种子。

## 目录

放入 Unraid 的 `/boot/config/plugins/user.scripts/pt-category-sync/`。

复制 `config.example.json` 为 `config.json` 并填写下载器地址与账号。首次保持 `dry_run: true`，在 User Scripts 中创建脚本并粘贴 `script` 内容手动测试。核对日志后设置 `dry_run: false`，设置 Cron `*/5 * * * *`。

- 每轮先读取 qBittorrent 分类及种子，学习 Tracker 域名和分类关系，再读取 Transmission 并追加匹配标签。
- 多个不同分类指向同一 Tracker 域名时跳过歧义规则；避免猜测站点。
- 不移动或删除数据，不修改 Tracker，不覆盖 Transmission 原有标签。
- `config.json`、`cache.json` 含敏感配置或运行状态，禁止提交仓库。
- 如果 IYUU 在第一次脚本执行前删除 qBittorrent 源任务，可能无法恢复分类映射。

依赖：Unraid 宿主机 PHP 及 cURL 扩展、qBittorrent Web API、Transmission 4.1 JSON-RPC。

## qBittorrent 自动认证

不需要设置 `auth_mode`。填写 `api_key` 或 `username` + `password` 即可，也可以两者都填。

- 优先尝试 API Key (Bearer)，并通过读取分类与任务接口验证。
- 如果 Key 不可用且用户名、密码均已填写，则自动回退账号密码登录。
- 两种方式都不可用时明确报错；日志只说明选择的方式，不打印凭据。
- Transmission 使用自身 RPC 的用户名/密码认证。

不要提交真实的 `config.json` 到 GitHub。


## 修复与运行保护

当前 qBit 同 Hash 分类优先于 Tracker 推断；任务已在 qBit 清空分类时清除旧 Hash 缓存。Tracker 学习只接受 HTTP/HTTPS/UDP 地址，域名冲突时跳过，不从旧缓存补回冲突结果。预览不写分类缓存。

直接 PHP 调用也有运行锁；分类同步与同目录下 pt-storage-migrate 的每轮操作共用迁移锁，迁移忙时跳过本次同步。更新标签前重新读取并保留已有标签，用完整 Hash 定位任务。其他外部工具同时改标签仍可能发生竞争。
