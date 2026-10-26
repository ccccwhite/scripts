# PT 双下载盘与保种迁移

## 当前目录映射

qBittorrent 和 Transmission 均使用相同映射：

| 宿主机 | 容器路径 | 用途 | 存储标签 |
| --- | --- | --- | --- |
| `/mnt/download` | `/temp` | SSD 下载 | `storage:ssd` |
| `/mnt/disk3` | `/download-hdd` | Disk 3 下载 | `storage:download-hdd` |
| `/mnt/disk2` | `/hdd` | Disk 2 长期保种 | `storage:hdd` |

## 迁移规则

Transmission 中标记 `move:hdd` 且下载完成的任务，若位于 `/temp` 或 `/download-hdd`，可在安全检查通过后迁往 `/hdd/<site>`。站点依据标签、分类和目录识别；不确定时跳过。

迁移前检查 qBittorrent 同 Hash 任务必须暂停、完整且不与其他任务共享文件。迁移后检查目标文件大小，再请求 Transmission 完整校验。只有脚本**观察到校验中状态**，随后观察到校验结束、下载完成且无错误，才会尝试删除 qBittorrent 任务记录（`deleteFiles=false`，绝不主动删除数据文件），清理 `move:hdd` 并更新 `storage:hdd`。

校验中状态可能很短，脚本可能一直等待人工复核；这是有意采用的保守策略，不要仅为完成迁移而绕过保护。

## 安装及运行

本地目录：`/boot/config/plugins/user.scripts/pt-storage-migrate/`。本地凭据文件仍位于 `/boot/config/plugins/user.scripts/pt-common/credentials.json`，不要上传账号密码。

从 GitHub 下载 `migrate.php` 后：

```bash
BASE=/boot/config/plugins/user.scripts/pt-storage-migrate
php -l "$BASE/migrate.php"
bash "$BASE/script"
```

本地 `config.json` 保持独立，不要使用仓库版本覆盖它。确保：

```json
{
  "ssd_path": "/temp",
  "download_path": "/download-hdd",
  "hdd_path": "/hdd",
  "hdd_host_path": "/mnt/disk2",
  "ssd_label": "storage:ssd",
  "download_label": "storage:download-hdd",
  "hdd_label": "storage:hdd",
  "dry_run": true,
  "enable_move": false
}
```

`dry_run=true` 仅预览；`enable_move=false` 禁止实际移动。**首次验证前保持这两个值不变。**

> 脚本不修改 qBittorrent 默认下载位置；Disk 3 的新任务需要在下载器中选择 `/download-hdd` 下的路径。脚本不重命名现有数据文件。
