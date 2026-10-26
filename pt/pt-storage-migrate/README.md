# PT 双下载盘与保种迁移

Transmission 中完成下载且标记 `move:hdd` 的任务，从下载盘迁至 `/hdd/<site>`。迁移后检查物理文件、执行完整校验，再删除 qBittorrent 同 Hash 的任务记录，保留 Transmission 做种。只删除任务记录，API 始终使用 `deleteFiles=false`。

## 当前目录映射

两个下载器应使用相同映射，PHP 在 Unraid 宿主机运行：

| 宿主机 | 容器路径 | 用途 | 存储标签 |
| --- | --- | --- | --- |
| `/mnt/download` | `/temp` | SSD 下载 | `storage:ssd` |
| `/mnt/disk3` | `/download-hdd` | Disk 3 下载 | `storage:download-hdd` |
| `/mnt/disk2` | `/hdd` | Disk 2 长期保种 | `storage:hdd` |

`hdd_path` 决定 Transmission 移动到哪里，`hdd_host_path` 决定宿主机检查哪里，两者必须对应同一挂载。脚本检查目标挂载点与符号链接，但不自动读取 Docker 配置验证映射。不要在迁移期间修改映射、重命名文件、恢复 qBit 源任务或操作同一批数据。

## 安装与配置

目录：`/boot/config/plugins/user.scripts/pt-storage-migrate/`。更新时同步 `migrate.php`、`progress.php`、`script` 和 README。主程序的 `run.lock` 保护每轮操作，入口的 `workflow.lock` 保护整个持续运行流程；旧版入口的外层 `run.lock` 会导致新版 PHP 跳过运行。凭据保持在本地公共文件，不要覆盖凭据、`state.json` 或直接覆盖已有本地配置。

配置含义：

| 字段 | 默认值 | 行为 |
| --- | --- | --- |
| `dry_run` | `true` | 只预览下载器及迁移操作 |
| `enable_move` | `false` | 禁止移动、校验和迁移收尾；`dry_run=false` 时仍会同步存储标签 |
| `delete_qbit_after_move` | 示例配置为 `true`；旧配置缺省为 `false` | 校验通过后允许删除同 Hash qBit 任务记录 |
| `max_pending_moves` | `1` | 限制尚未收尾的迁移数量；包含移动和校验阶段 |
| `state_file` | 配置文件同目录 `state.json` | 持久化迁移阶段；同目录 `run.lock` 保护直接 PHP 和入口脚本调用 |

开关必须是 JSON 布尔值，不要写成字符串。首次验证保持 `dry_run=true`、`enable_move=false`。实际移动需设为 `dry_run=false`、`enable_move=true`，且要保留 `delete_qbit_after_move=true` 才能处理存在同 Hash qBit 任务的种子。两个开关关闭时不会自动处理待完成迁移。

站点从 Transmission 标签、qBit 同 Hash 分类及目录推断，信息冲突时跳过。旧 `site_labels` 从未作为白名单生效，已从示例配置删除；旧本地字段保留也不会生效。

```bash
BASE=/boot/config/plugins/user.scripts/pt-storage-migrate
php -l "$BASE/migrate.php"
php -l "$BASE/progress.php"
bash "$BASE/script"
```

`script` 默认集成迁移、进度监控、校验和收尾，无需打开第二个终端。真实迁移模式下，每 3 秒显示磁盘占用估算，并反复调用主程序推进状态。一个任务完成后继续处理下一个符合条件的任务；没有待处理迁移且没有发起新迁移时自动退出。预览模式或禁用移动时只执行一轮。

```bash
# 默认：一条命令完成流程，包括继续处理已有待完成迁移。
bash "$BASE/script"
# 只检查/执行一轮，不等待后续校验和收尾。
bash "$BASE/script" --once
# 自定义显示/检查间隔及等待时限（秒，默认最多等待 24 小时）。
bash "$BASE/script" --interval=3 --timeout=86400
```

`Ctrl+C` 会退出入口并清理本次启动的监控和 PHP 子进程，保留迁移状态。Transmission 已接受的移动或校验可能仍在后台继续；再次运行同一入口即可检查并继续。接口报错、需要人工确认或等待超时会退出并保留状态；不会自动绕过校验。重复启动入口会被工作流锁拦截。进度显示为磁盘占用估算，无法提供精确复制百分比。

## 保护与恢复

- 移动前重新读取两个下载器。qBit 同 Hash 任务必须停止且完整，其他共享文件任务会阻止迁移；Transmission 共享文件用路径索引检查。
- 检查目标盘挂载、剩余空间、目标父目录与文件，拒绝覆盖和符号链接。存在其他待完成迁移时保守预留其全部数据大小；因此提高并发上限可能因保守空间检查而跳过。
- 保存文件清单、源目录、目标目录及映射。移动请求前写入意图，超时结果不明时不自动重发。后续执行若目标文件与目录确认完成，继续完整校验。
- 删除 qBit 前刷新任务，检查源和目标路径的共享任务；删除前记录 `deleting_qbit`，确认删除后记录 `qbit_removed`。任一步中断，下次可继续。只有已记录删除意图时才接受原 qBit 任务不存在；无意图的任务消失、换路径或删除完成后又重新出现，会停止并要求复核。
- 完整校验需观察到 Transmission 校验状态，再看到 `have_valid` 等本地数据字段完整。Tracker 错误不单独阻止完成；本地文件缺失、损坏或正在校验时不收尾。脚本不把文件大小相同视作完整校验通过。
- 任务被手动删除、清单或映射改变、目标部分存在时，不自动合并、覆盖或清理数据。保留状态和两端文件进行人工检查，不要直接删除 `state.json` 试图解除等待。

恢复命令必须填写完整 Hash，不接受缩写；同样受预览及移动开关控制：

```bash
# 移动请求失败/结果不明：先确认下载器没有移动操作仍在运行。
# 仅在 Trans 仍指向原下载目录、文件清单未变、目标文件全部不存在时允许重试。
php "$BASE/migrate.php" "$BASE/config.json" --retry-move=完整Hash

# 目标完整，但校验请求中断或没有观察到校验阶段：重新发起完整校验。
php "$BASE/migrate.php" "$BASE/config.json" --recheck=完整Hash
```

也可通过集成入口传入上述恢复参数，例如 `bash "$BASE/script" --recheck=完整Hash`。恢复参数仅在第一轮执行，后续只查询和推进状态，避免不断重新发起校验。

部分移动情况下不使用重试命令，先人工核对源与目标文件，避免重复移动。旧版状态会沿用保守规则；旧版在删除 qBit 后中断且没有删除意图记录时，不能自动推断已经安全删除。

非常小的文件可能校验太快，一直无法观察到校验状态。先在 Transmission 手动执行完整校验并确认结束、全部数据完整，再明确执行：

```bash
php "$BASE/migrate.php" "$BASE/config.json" --accept-verified=完整Hash
```

此命令是人工确认入口，会记录确认时间；仍要求脚本有过校验请求、物理文件完整、Trans 本地校验字段完整，并执行所有 qBit 删除保护。它不自动证明刚刚发生过一次新校验，不应放入定时任务或在未人工确认时使用。

迁移与校验由 Transmission 执行；集成入口自动重复检查并推进后续阶段。直接调用 `migrate.php` 或使用 `script --once` 时仍仅执行一轮，需要再次运行才能继续收尾。

## 可选的独立只读监控

正常使用集成入口无需另外启动监控。只有需要单独查看时才运行：

```bash
php "$BASE/progress.php" --watch --interval=3
# 自定义配置，状态路径默认读取配置；也可把状态文件作为位置参数传入。
php "$BASE/progress.php" --config=/路径/config.json --watch
```

监控共用主程序路径校验，读取配置和每个迁移保存的映射，每轮刷新文件状态缓存。显示迁移阶段、磁盘占用比例、占用变化速度、逻辑文件大小和已出现文件数量。

**allocation 是磁盘占用估算，不是复制进度。** 预分配可能提前占满空间，稀疏文件也可能占用很少；100% 不代表移动或校验完成。因此不再显示容易误导的复制 ETA，最终以主程序 `COMPLETE` 和待处理状态清除为准。监控不调用下载器，也不修改状态或数据。

## 本地回归测试

需要 Python 3、PHP 8.1+ 和 PHP cURL：

```bash
python3 tests/test_migration.py
# 指定其他 PHP
python3 tests/test_migration.py --php /usr/bin/php
```

测试只访问本机模拟接口并使用临时文件，不接触真实下载器。覆盖完整迁移、删除后异常恢复、移动请求失败、部分目标拒绝、共享文件、操作前刷新、锁、映射改变、预览和监控配置，以及集成入口的连续任务、移动请求期间显示、单轮模式、重复启动、超时、人工确认和退出后恢复。不能替代 Unraid 上的小文件真实迁移验证。
