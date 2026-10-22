# PT 存储标签与 HDD 迁移脚本

适用于 Unraid，使用 PHP，通过 qBittorrent Web API 和 Transmission RPC 管理种子的存储标签，并按标签将 Transmission 中的种子从 SSD 迁移至 HDD。

## 目录映射

两个下载器都需要配置以下 Docker 路径映射：

| Unraid 宿主机 | 容器内 | 用途 |
| --- | --- | --- |
| `/mnt/download` | `/temp` | SSD 下载与做种 |
| `/mnt/disk3` | `/hdd` | HDD 下载与做种 |

HDD 按站点分类，例如 `/mnt/disk3/nicept/`、`/mnt/disk3/rousi/`。

## 标签规则

- `storage:ssd`：种子数据位于 `/temp`。
- `storage:hdd`：种子数据位于 `/hdd`。
- `move:hdd`：**仅在 Transmission 中**标记需要迁移到 HDD 的种子。

脚本扫描 qBittorrent 和 Transmission 的全部种子，根据可识别的保存路径补充或纠正存储标签。位置无法识别时不猜测，记录警告并跳过。

只有 Transmission 中带有 `move:hdd`、已完成下载、站点标签唯一且通过共享文件检查的 SSD 种子，才可能迁移。qBittorrent 不负责执行迁移，但会参与共享文件检查。脚本采用保守策略，无法确认安全时跳过。

## 安装

将本目录中的 `migrate.php`、`config.json`、`script` 放到：

```text
/boot/config/plugins/user.scripts/pt-storage-migrate/
```

沿用本地凭据文件：

```text
/boot/config/plugins/user.scripts/pt-common/credentials.json
```

**不要将真实账号密码提交到 GitHub。**

在 Unraid User Scripts 中运行 `script`，也可通过 SSH 测试：

```bash
BASE="/boot/config/plugins/user.scripts/pt-storage-migrate"
php -l "$BASE/migrate.php"
bash "$BASE/script"
```

## 安全开关

`config.json` 默认配置：

```json
{
  "dry_run": true,
  "enable_move": false
}
```

- `dry_run: true`：仅预览，不修改标签，也不移动文件。
- `dry_run: false`：允许同步存储标签。
- `enable_move: true`：配合 `dry_run: false` 才允许实际迁移。

**建议先完整检查 Dry Run 输出，再决定是否开启写入。**

## 站点目录

在 `config.json` 的 `site_labels` 中填写允许识别的站点标签，例如 `NicePT`、`Rousi`、`HappyFappy`。默认将站点名转换为小写作为 HDD 文件夹名称。

站点标签缺失或存在冲突时，跳过迁移，不自动猜测目标站点。

## 当前版本限制

- 采用保守的共享文件检查；如果 qBittorrent 或其他 Transmission 任务可能共用数据，跳过迁移。
- 无法可靠识别存储位置的种子不会强制补标签。
- 当前初版使用 Transmission RPC 发起迁移，但**不会自动移除 `move:hdd` 标签**；需要先验证文件迁移及任务状态，再完善迁移完成确认。
- 暂不建议启用定时自动迁移；先手动测试。
