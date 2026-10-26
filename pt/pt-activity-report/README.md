# PT 双下载器活跃度与 H&R 本地统计

适用于 Unraid 的 qBittorrent 5.2.x + Transmission 4.1.x。

统计每个种子的累计上传、累计做种时间、分享率、最近活动、距离上次**观察到上传量增长**的天数。站点 H&R 采用手工配置阈值的**本地估算**，不等同于 PT 站点实际确认。

同一站点且相同 Info Hash 的两客户端任务可合并做种秒数与累计上传字节；**分享率不能直接相加**，应按总上传量除以单份种子大小估算。两个客户端同时做种的时间会重复累计；不同 Hash 不合并。

首次运行只建立基线，之后每天比较增量。CSV 存在配置的 `csv_file` 路径。只读，不修改、暂停或删除种子，不移动文件。

## 安装

将 `pt_activity.php` 与模板下载到 `/boot/config/plugins/user.scripts/pt-activity-report/`，模板保存为 `config.json`，在 pt-common/credentials.json 中填写认证信息，并在本脚本 config.json 中设置 credentials_file。兼容原有在 config.json 内直接设置账号的模式。将 `script` 内容填入 Unraid User Scripts，先手动测试，再设定 Cron `10 3 * * *`。

`hr_rules` 中 0 表示规则未配置，禁止视为达标。规则须自行依据站点政策核实。无需提交 `config.json`、`state.json`、`report.csv` 等运行文件。

**预览版：未在真实下载器环境完成联调。**


## 下载完成时间与输出

CSV 和 HTML 新增“下载完成时间”：qBittorrent 使用 completion_on，Transmission 使用 done_date，按配置 timezone 显示；零或负值显示未知。两客户端分别保留记录，合计行显示已知最早时间。Transmission 导入任务的时间不一定是 qBit 原始完成时间，qBit 任务删除后不能恢复未保存的原始时间。

统一入口先采集 CSV，再自动运行 render_report.php 更新 HTML。渲染器支持同一个配置参数，读取 csv_file，HTML 默认输出在 CSV 同目录，也可设置 html_file。旧 CSV 缺少时间列仍可渲染，显示未知。

直接 PHP 采集也有运行锁；CSV 使用临时文件原子替换后再保存采样状态，失败会报错。上传计数重置后重新建立基线，小额上传会累计至 minimum_upload_bytes 阈值。H&R any 模式在已知做种时间满足时，不因缺少分享率而误报。统计仍是本地估算，两个客户端做种时间可能重叠。

未配置 H&R 规则的站点仍可根据 qBit 分类或单一非系统标签显示站点名，但不会推定 H&R 达标。
