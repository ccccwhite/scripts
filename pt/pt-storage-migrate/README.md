# PT 双下载盘与保种迁移

Transmission 中完成下载且标记 `move:hdd` 的任务，从下载盘迁至 `/hdd/<site>`。默认调用 Transmission 内置 `torrent_set_location(move=true)` 移动数据，迁移后检查保存路径、文件存在与大小、任务完成状态和本地错误，再删除 qBittorrent 同 Hash 的任务记录，保留 Transmission 做种；不额外调用完整 Hash 校验。只删除任务记录，API 始终使用 `deleteFiles=false`。

## 当前目录映射

两个下载器应使用相同映射，PHP 在 Unraid 宿主机运行：

| 宿主机 | 容器路径 | 用途 | 存储标签 |
| --- | --- | --- | --- |
| `/mnt/download` | `/temp` | SSD 下载 | `storage:ssd` |
| `/mnt/disk3` | `/download-hdd` | Disk 3 下载 | `storage:disk3` |
| `/mnt/disk2` | `/hdd` | Disk 2 长期保种 | `storage:disk2` |

`hdd_path` 决定 Transmission 移动到哪里，`hdd_host_path` 决定宿主机检查哪里，两者必须对应同一挂载。脚本检查目标挂载点与符号链接，但不自动读取 Docker 配置验证映射。不要在迁移期间修改映射、重命名文件、恢复 qBit 源任务或操作同一批数据。

## 安装与配置

目录：`/boot/config/plugins/user.scripts/pt-storage-migrate/`。更新时同步 `migrate.php`、`progress.php`、`script` 和 README。主程序的 `run.lock` 保护每轮操作，入口的 `workflow.lock` 保护整个持续运行流程；旧版入口的外层 `run.lock` 会导致新版 PHP 跳过运行。凭据保持在本地公共文件，不要覆盖凭据、`state.json` 或直接覆盖已有本地配置。

配置含义：

| 字段 | 默认值 | 行为 |
| --- | --- | --- |
| `dry_run` | `true` | 只预览下载器及迁移操作 |
| `enable_move` | `false` | 禁止移动、校验和迁移收尾；`dry_run=false` 时仍会同步存储标签 |
| `delete_qbit_after_move` | 示例配置为 `true`；旧配置缺省为 `false` | 迁移检查通过后允许删除同 Hash qBit 任务记录 |
| `verify_after_move` | `false`，旧配置缺少此字段也按 `false` | 是否在内置移动完成后额外请求完整 Hash 校验 |
| `max_pending_moves` | `1` | 限制尚未收尾的迁移数量；包含移动和校验阶段 |
| `move_rpc_timeout` | `86400` 秒（24 小时），旧配置缺少此字段也使用此值 | 仅用于内置移动接口等待响应；普通 API 请求仍为 45 秒 |
| `move_stall_seconds` | `900` 秒（15 分钟），可不填 | 目标文件长时间无变化时只输出停滞警告，不自动重试、清理或重启下载器 |
| `state_file` | 配置文件同目录 `state.json` | 持久化迁移阶段；同目录 `run.lock` 保护直接 PHP 和入口脚本调用 |

开关必须是 JSON 布尔值，不要写成字符串。首次验证保持 `dry_run=true`、`enable_move=false`。实际移动需设为 `dry_run=false`、`enable_move=true`，且要保留 `delete_qbit_after_move=true` 才能处理存在同 Hash qBit 任务的种子。两个开关关闭时不会自动处理待完成迁移。

站点从 Transmission 标签、qBit 同 Hash 分类及目录推断，信息冲突时跳过。旧 `site_labels` 从未作为白名单生效，已从示例配置删除；旧本地字段保留也不会生效。

```bash
BASE=/boot/config/plugins/user.scripts/pt-storage-migrate
php -l "$BASE/migrate.php"
php -l "$BASE/progress.php"
bash "$BASE/script"
```

`script` 默认集成迁移、进度监控、可选校验和收尾，无需打开第二个终端。真实迁移模式下，每 10 秒显示磁盘占用估算，并反复调用主程序推进状态。一个任务完成后继续处理下一个符合条件的任务；没有待处理迁移且没有发起新迁移时自动退出。预览模式或禁用移动时只执行一轮。

```bash
# 默认：一条命令完成流程，包括继续处理已有待完成迁移。
bash "$BASE/script"
# 只检查/执行一轮，不等待后续校验和收尾。
bash "$BASE/script" --once
# 自定义显示/检查间隔及等待时限（秒，默认最多等待 24 小时）。
bash "$BASE/script" --interval=10 --timeout=86400
```

`Ctrl+C` 会退出入口并清理本次启动的监控和 PHP 子进程，保留迁移状态。Transmission 已接受的移动或校验可能仍在后台继续；再次运行同一入口即可检查并继续。接口报错、需要人工确认或等待超时会退出并保留状态；启用完整校验时不会自动绕过其确认条件。重复启动入口会被工作流锁拦截。进度显示为磁盘占用估算，无法提供精确复制百分比。

Transmission 的内置移动接口可能等待文件搬完才返回 HTTP 响应，大任务不能沿用普通 API 的 45 秒超时。移动请求默认等待最多 24 小时，期间集成监控继续显示文件变化；这不是强制等待 24 小时，操作完成返回后立即进入后续检查。入口 `--timeout` 控制每轮结束后的持续等待，正在执行的移动请求另受 `move_rpc_timeout` 限制。

如果看到 `Operation timed out ... with 0 bytes received`，只表示没有及时收到接口响应，不代表 Transmission 已停止移动。保留 `state.json`，先用独立只读监控检查目标文件是否仍在增长；结果未确认前不要使用 `--retry-move`、删除状态或手动移动同一批文件。移动期间其他 Transmission API 查询也可能因服务忙而超时；待原操作结束后重新运行入口，会继续检查已有迁移而非重复发起。网络故障或超过移动等待上限仍会安全退出并保留状态。

## 移动状态与中断保护（新版）

- 新发起移动时，脚本**不会主动停止或启动 Transmission 种子**，直接调用原生 `torrent_set_location(move=true)`，由 Transmission 自行管理文件句柄。原本正在做种的任务保持其运行状态，但磁盘迁移期间上传可能短暂受到影响；原本停止的任务保持停止。对于历史版本已设置 `auto_paused` 的待处理迁移，仍在完成验证后按其记录恢复，兼容旧状态。
- `preparing_move` 仅可能出现在旧版遗留状态中，`move_requested` 是移动 RPC 已发出但未收到确定结果，`awaiting_completion` 是 RPC 已响应但仍需核验，`copy_observed` 仅代表看到了目标文件（**不代表完成**）。完成只能以 `COMPLETE` 为准。
- 每次检查待完成迁移会读取目标文件占用和文件数；默认超过 15 分钟没有观察到增长时输出 `WARN MOVE STALLED`，仅报警，不自动重新移动。进度状态每分钟最多写入一次，以降低启动 U 盘写入。
- 显式 `--retry-move=HASH` 在上次请求后 15 分钟内也会拒绝重试；此外仍要求源目录未变、目标文件均不存在，并通过原有完整性及共享文件保护。**如果移动仍在进行或目标文件已有部分内容，不要使用重试。**
- `Ctrl+C` 中断入口可能停止等待 RPC 的客户端进程，但不代表 Transmission 停止后台移动。先运行 `php "$BASE/progress.php" --watch --interval=10` 只读监测，完成后再运行不带重试参数的 `bash "$BASE/script"` 收尾。
- 升级时只替换 `migrate.php`；保留 NAS 本地 `config.json`、`state.json`、凭据和现有标签。新的状态字段兼容既有记录。

## 移动后 qBittorrent 显示 missingFiles 的安全收尾

Transmission 成功将数据从 SSD 搬到 HDD 后，原路径的同 Hash qBittorrent 任务可能显示 `missingFiles`。这时旧的“必须仍为 stopped”的收尾检查会阻止完成。

新版只在迁移已经进入待收尾状态、Transmission 目标路径和全部文件大小验证通过、本地任务完整，且该 qBittorrent 哈希曾在移动前通过停种/完整度/共享文件检查并记录在 `state.json` 中时，允许以 `missingFiles` 删除**任务记录**，始终使用 `deleteFiles=false`。其他非停止状态仍被阻止。绝不直接清理源文件或重新移动。

只完成当前 pending 的安全收尾，不修改其他种子标签或启动下一项迁移：

```bash
BASE=/boot/config/plugins/user.scripts/pt-storage-migrate
bash "$BASE/script" --once --finalize-only
```

不会为无 pending 的新种子发起移动。替换新版 `migrate.php` 和入口 `script` 即可；保留 NAS 的 `config.json`、`state.json` 和凭据。

## 保护与恢复

- 移动前重新读取两个下载器。qBit 同 Hash 任务必须停止且完整，其他共享文件任务会阻止迁移；Transmission 共享文件用路径索引检查。
- 检查目标盘挂载、剩余空间、目标父目录与文件，拒绝覆盖和符号链接。存在其他待完成迁移时保守预留其全部数据大小；因此提高并发上限可能因保守空间检查而跳过。
- 保存文件清单、源目录、目标目录及映射。移动请求前写入意图，超时结果不明时不自动重发。后续执行确认目标文件、保存目录及任务状态后收尾；只有启用 `verify_after_move` 时才额外请求完整校验。
- 删除 qBit 前刷新任务，检查源和目标路径的共享任务；删除前记录 `deleting_qbit`，确认删除后记录 `qbit_removed`。任一步中断，下次可继续。只有已记录删除意图时才接受原 qBit 任务不存在；无意图的任务消失、换路径或删除完成后又重新出现，会停止并要求复核。
- 默认不额外读取整份文件做 Hash 校验；文件存在和大小相同不能证明内容 Hash 一致。Transmission 必须报告任务完成、没有本地错误，且目标物理文件检查通过。Tracker 错误不单独阻止完成；本地文件缺失、大小不符、本地错误或正在校验时不收尾。
- 如需更严格的迁移后完整校验，设置 `verify_after_move=true`。此模式需观察到 Transmission 校验状态，再看到 `have_valid` 等本地数据字段完整。`--recheck=完整Hash` 即使在默认模式下，也会强制该任务完成完整校验，并把此要求保存在状态中。
- 从旧版升级且已有校验正在进行时，等待其结束，不中断 Transmission 的校验；以后正常迁移不再额外发起校验。
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

以下仅适用于已启用或明确请求完整校验的任务。非常小的文件可能校验太快，一直无法观察到校验状态。先在 Transmission 手动执行完整校验并确认结束、全部数据完整，再明确执行：

```bash
php "$BASE/migrate.php" "$BASE/config.json" --accept-verified=完整Hash
```

此命令是人工确认入口，会记录确认时间；仍要求脚本有过校验请求、物理文件完整、Trans 本地校验字段完整，并执行所有 qBit 删除保护。它不自动证明刚刚发生过一次新校验，不应放入定时任务或在未人工确认时使用。

迁移与校验由 Transmission 执行；集成入口自动重复检查并推进后续阶段。直接调用 `migrate.php` 或使用 `script --once` 时仍仅执行一轮，需要再次运行才能继续收尾。

## 可选的独立只读监控

正常使用集成入口无需另外启动监控。只有需要单独查看时才运行：

```bash
php "$BASE/progress.php" --watch --interval=10
# 自定义配置，状态路径默认读取配置；也可把状态文件作为位置参数传入。
php "$BASE/progress.php" --config=/路径/config.json --watch
```

监控共用主程序路径校验，读取配置和每个迁移保存的映射，每轮刷新文件状态缓存。显示迁移阶段、磁盘占用比例、占用变化速度、逻辑文件大小和已出现文件数量。

**allocation 是磁盘占用估算，不是复制进度。** 预分配可能提前占满空间，稀疏文件也可能占用很少；100% 不代表移动或校验完成。因此不再显示容易误导的复制 ETA，最终以主程序 `COMPLETE` 和待处理状态清除为准。监控不调用下载器，也不修改状态或数据。

## 验证说明

开发回归测试仅在本地保留，不随运行脚本上传或部署。生产运行不依赖 tests 目录。发布前的模拟测试不能替代 Unraid 上的实际迁移验证。

## 存储标签统一

本脚本根据明确的容器下载目录进行标签归一化：`/temp` → `storage:ssd`，`/download-hdd` → `storage:disk3`，`/hdd` → `storage:disk2`。删除冗余的旧版 `storage:hdd`、`storage:download-hdd`、`disk:N`、`route:ready` 标签；其他站点标签、`move:hdd` 保持不变。**位于 `/qbit` 的新选盘任务不属于本迁移脚本已知路径，脚本会跳过它们，不会猜测所在磁盘**。若磁盘映射日后发生变化，应先修正代码映射再启用写入；当前映射以仓库 README 为准。`config.json` 中旧 `*_label` 字段仍保留以兼容旧配置，不应再用它们代表统一后的磁盘标识。

## 单独同步 Transmission 存储标签（不执行迁移）

新增 `sync_trans_tags.php`：仅根据 Transmission 容器的**实际 Docker bind 映射**和真实存在的下载目录确认物理磁盘，再把存储标签归一化为 `storage:ssd`、`storage:disk2`、`storage:disk3` 或 `storage:disk6`。保留其他标签（站点分类和 `move:hdd`），删除冗余 `storage:*`、`disk:N` 和 `route:ready`。路径有歧义、目录不存在、容器挂载不明确时跳过；与迁移程序共用运行锁。

```bash
BASE=/boot/config/plugins/user.scripts/pt-storage-migrate
php -l "$BASE/sync_trans_tags.php"
php "$BASE/sync_trans_tags.php"         # 仅预览
php "$BASE/sync_trans_tags.php" --apply # 审查预览后再执行
```

此入口不连接 qBittorrent、不调用 Transmission 移动/校验/删除接口，仅显式使用 `--apply` 时更新 Transmission `torrent_set(labels=...)`。它不负责新增 Tracker 站点标签；继续用已有 `pt-category-sync`。**不应将该程序作为自动存储迁移器。**
