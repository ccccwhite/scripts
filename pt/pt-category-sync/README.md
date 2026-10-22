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
