# PT 手动选盘路由（Unraid / qBittorrent / Qui）

适用于 Unraid 6.12.13 与 qBittorrent 5.2.x。按任务标签手动选择 SSD、disk2、disk3、disk6，按当前种子分类分目录，保留 `mteam`、`tm18` 等已有分类。**不修改旧任务、不自动开始下载、不移动或删除任何数据。**

## 仓库结构（与其他 PT 脚本一致）

```text
pt/pt-disk-router/
├── pt_download_router.php   # Unraid 宿主机 PHP 主程序
├── config.json     # 无密钥的配置示例，下载到本地后按需编辑
├── script          # Unraid User Scripts 入口
├── on-add.sh       # qBit 容器内新增 Torrent 钩子
└── README.md
```

统一采用 `config.json`，不再使用 `config.example.json`。运行产生的 `state.json` 和 `run.lock` 在 `/boot/config/plugins/user.scripts/pt-disk-router/`；队列和容器钩子位于 `/mnt/user/appdata/qbittorrent/pt-router/`，因为 qBit 容器已有 `/mnt/user/appdata/qbittorrent:/config` 映射。账号密码继续从已有 `/boot/config/plugins/user.scripts/pt-common/credentials.json` 读取，**不要上传凭据、运行状态或真实敏感配置到 GitHub**。

## 安装

1. 确认已存在 `qbit` 用户共享，并设置拆分级别为 **Manual（不自动拆分目录）**；共享包含全部阵列盘也可，但只会开放 `config.json` 中指定的目标供手动选择。其他程序直接写共享仍可能触发不受控放置，避免绕过路由器。
2. qBittorrent Docker 新增映射 `/mnt/user/qbit:/qbit:rw`，**保留**旧的 `/temp`、`/hdd`、`/download-hdd`、`/movie` 和 `/config` 映射。
3. qBittorrent、Qui、MoviePilot/RSS 必须确保**添加时暂停**。关闭“未完成 Torrent 使用其他目录”。新增钩子不是前置拦截器，不能在下载启动后再安全改路径。
4. 从本目录下载 `pt_download_router.php`、`config.json`、`script` 到 `/boot/config/plugins/user.scripts/pt-disk-router/`；**如果已经存在本地 config.json，不要覆盖**。
5. 下载 `on-add.sh` 到 `/mnt/user/appdata/qbittorrent/pt-router/on-add.sh`，执行 `chmod 755`；qBit 的“新增 Torrent 时运行外部程序”填写：
   `/bin/sh /config/pt-router/on-add.sh "%I" "%J"`
6. `config.json` 的 `activation_epoch` 必须由 `date +%s` 得到实际激活时间，保持 `dry_run:true` 先验证。已存在的旧种子不会处理。
7. 新任务必须完全暂停、0% 进度、0 下载量，有唯一选盘标签 `target:ssd`、`target:disk2`、`target:disk3` 或 `target:disk6`，且分类合法（如 `mteam`、`tm18`）。
8. 先运行 `bash /boot/config/plugins/user.scripts/pt-disk-router/script` 预览，确认输出再把 `dry_run` 改成 `false`，**仅人工验证时**运行 `bash /boot/config/plugins/user.scripts/pt-disk-router/script --apply`。未完成真实环境验证前不要配置每分钟自动执行。

## 目录与做种

- 目标磁盘下会预先建立 `/mnt/diskN/qbit/分类/种子原始名称/`；qBit HDD 统一使用 `/qbit` 访问；SSD 使用 `/temp/qbit/分类`。
- 路由器读取 qBit Web API 文件清单：若清单中的全部文件已经处在同一个根目录，保持原始根目录不变，保存路径设置到 `/qbit/分类`；否则为平铺文件增加以种子名称命名的外层目录 `/qbit/分类/种子名称`。不会改种子内部相对路径。
- 平铺文件发生目录重名时会追加短 Hash；已有原始根目录发生冲突则停止处理，**不会擅自改名或覆盖**。
- 成功后仍暂停，添加 `route:ready`、`storage:ssd` 或 `storage:hdd,disk:N`，并移除 `target:...` 标签。人工检查完成后才启动。
- 原已有做种路径不会改变。同资源多个 Tracker、辅种共享文件、已有目录冲突、迁移脚本联动尚未适配，**不要用此工具自动处理**。
- 用户共享路径与磁盘路径不能互相用作复制/移动源和目标。跨磁盘搬运数据时应暂停相关任务，并只在两个不同物理磁盘路径间迁移。

## 常用命令

```bash
BASE=/boot/config/plugins/user.scripts/pt-disk-router
php -l "$BASE/pt_download_router.php"
bash -n "$BASE/script"
bash "$BASE/script" --status
bash "$BASE/script"          # 即使 dry_run=false 仍为预览
bash "$BASE/script" --apply  # dry_run=false 才会修改路径
```

程序默认只预览；出现错误会保留队列和当前状态供人工检查。禁用时请关闭新增钩子并停止 User Scripts 定时执行，**不要删除现有下载目录、队列或状态文件**。

## 生产发布规范

仓库仅提交上述五个文件；不提交模拟 Torrent、测试工具、测试目录、凭据或运行生成的文件。模拟测试已在线下完成，**未验证实际 qBit 5.2.4 的每种布局**，首次生产前仍需要只读检查并用暂停的测试任务验证路径。
