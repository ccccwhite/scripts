# PT 双下载器活跃度与 H&R 本地统计

适用于 Unraid 的 qBittorrent 5.2.x + Transmission 4.1.x。

统计每个种子的累计上传、累计做种时间、分享率、最近活动、距离上次**观察到上传量增长**的天数。站点 H&R 采用手工配置阈值的**本地估算**，不等同于 PT 站点实际确认。

同一站点且相同 Info Hash 的两客户端任务可合并做种秒数与累计上传字节；**分享率不能直接相加**，应按总上传量除以单份种子大小估算。两个客户端同时做种的时间会重复累计；不同 Hash 不合并。

首次运行只建立基线，之后每天比较增量。CSV 存在配置的 `csv_file` 路径。只读，不修改、暂停或删除种子，不移动文件。

## 安装

将 `pt_activity.php` 与模板下载到 `/boot/config/plugins/user.scripts/pt-activity-report/`，模板保存为 `config.json`，填写地址与认证信息。将 `script` 内容填入 Unraid User Scripts，先手动测试，再设定 Cron `10 3 * * *`。

`hr_rules` 中 0 表示规则未配置，禁止视为达标。规则须自行依据站点政策核实。无需提交 `config.json`、`state.json`、`report.csv` 等运行文件。

**预览版：未在真实下载器环境完成联调。**
