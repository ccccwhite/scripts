# PT Disk Router (Unraid + qBittorrent + Qui)

这是**新任务手动选盘**工具，和现有 `pt-storage-migrate` 分离；默认预览、失败即停止，不迁移旧数据、不自动开始下载。

## 规则

- 分类 `mteam`（普通影视）、`tm18`（9Kg）、其他站点分类，保持不变。
- 新任务使用**唯一一个** `target:disk2` / `target:disk3` / `target:disk6` / `target:ssd` 标签选盘。
- HDD 实际目录 `/mnt/diskN/qbit/分类/hash`；qBit 看到统一目录 `/qbit/分类/hash`。
- SSD 继续通过 `/temp/qbit/分类/hash`。
- 更新成功后仍暂停，标签变为 `route:ready` 和 `storage:hdd,disk:N`（或 `storage:ssd`）。请人工检查后才启动下载。
- 每个种子单独 Hash 目录，不复用已经下载的相同资源，**辅种/交叉做种须另行设计**。
- 不处理旧任务：必须有新增事件队列、有目标标签、加入时间不早于激活时间、处于停止状态、进度和已下载量均为0。

## Unraid 配置

1. 创建 User Share `qbit`，Primary Storage=Array，Secondary=None；只允许 disk2/disk3/disk6；**Split Level 选择 Manual（不自动拆分目录）**。分配策略仅作为兜底；由宿主机程序在选中盘预先建立专属目录。不要在生产大包上直接测试。
2. qBit Docker 在原映射基础上**新增** `/mnt/user/qbit:/qbit:rw`，保留旧映射 `/temp`、`/hdd`、`/download-hdd`、`/movie` 和 `/config`。
3. **务必**将 qBit 的新增 Torrent 默认为停止/暂停，关闭“未完成种子使用其他位置”；Qui / RSS / MoviePilot 各添加入口也必须保证新增时暂停。**on-add 外部程序并不是添加前阻塞钩子**，不能在下载开始后再安全地选盘。
4. qBit 外部程序勾选“新增 Torrent 时运行”，填写：
   `/bin/sh /config/pt-router/on-add.sh "%I" "%J"`
   不要选“torrent 完成时运行”。
5. 在宿主机创建 `/mnt/user/appdata/qbittorrent/pt-router/queue`。部署 `on-add.sh` 到 `/mnt/user/appdata/qbittorrent/pt-router/on-add.sh`，设置权限755。宿主机 `/boot/config/plugins/user.scripts/pt-disk-router/` 放 `router.php`、`script` 和从 `config.example.json` 复制的 `config.json`。
6. `config.json` 填写 `activation_epoch`（执行 `date +%s` 取得），先保持 `dry_run:true`。使用你已有的私密凭据 `/boot/config/plugins/user.scripts/pt-common/credentials.json`，不要上传该文件。
7. 添加**全新**小测试 Torrent：类别 `mteam`，单一标签 `target:disk6`，必须暂停且0下载量。
8. 执行 `bash /boot/config/plugins/user.scripts/pt-disk-router/script`，确认显示 DRY RUN、路径和大小正确。此时没有任何修改。
9. 手动检查通过后将 `dry_run` 改为 `false`，执行 `bash /boot/config/plugins/user.scripts/pt-disk-router/script --apply`。校验标签 `route:ready`，保存路径 `/qbit/mteam/hash`、物理位置在 disk6，并确认任务仍为暂停。
10. 测试成功后使用 Unraid User Scripts 将 `script --apply` 设为每分钟执行；先试少量种子，**不要直接在 MoviePilot 自动下载的任务上启用**。

## 检查与停用

```bash
BASE=/boot/config/plugins/user.scripts/pt-disk-router
php -l "$BASE/router.php"
bash -n "$BASE/script"
bash "$BASE/script" --status
bash "$BASE/script"            # 预览，即使 dry_run=false
bash "$BASE/script" --apply    # 仅 dry_run=false 时实际执行
```

要停用：取消 qBit 新增 Torrent 外部程序勾选，禁用定时 User Scripts。保留状态文件和目录，不要直接删除队列或清空数据。

## 注意

- 当前脚本仅操作新增且完全未下载的任务，不会移动/删除种子文件，不会启动下载。
- 同一资源多 Tracker 辅种时独立Hash目录会重复占空间；若要共用文件，应另行建立共享文件引用方案。
- 分享目录不能保证数据冗余：当前无校验盘，硬盘坏掉仍可能丢数据。
- 未来换盘，保持 `/qbit/分类/hash` 相对路径一致，暂停种子并在**两条物理盘路径之间**迁移，校验后恢复。切勿在 `/mnt/user/qbit` 与 `/mnt/diskN/qbit` 间相互复制移动同一数据。
- qBit 分类同步程序应保护 `tm18` 不被 Tracker 自动改回 `mteam`。
- 现有 `pt-storage-migrate` 尚未适配新共享路径，升级前不要让其处理此路由器的新种子。
- MoviePilot 应配置路径映射以访问 `/qbit`，且自动添加的任务需保证暂停。
