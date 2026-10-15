# 部署与运维

## 环境要求

- Linux + Nginx/Apache
- PHP-FPM 8.0+
- PHP 扩展：PDO、pdo_sqlite、sqlite3、gd、fileinfo
- 可选：ImageMagick `convert`
- HTTPS（生产必需）

确认扩展：

```bash
php -m | grep -E 'PDO|pdo_sqlite|sqlite3|gd|fileinfo'
php -l index.php
```

## 推荐目录

```text
/var/www/photo-selector/index.php       # 公开入口，Web 用户只需读取源码
/var/lib/photo-selector/                # 私有数据，Web 用户需要读写
├── selector.sqlite
├── originals/
├── previews/
└── thumbs/
```

数据目录不要放在站点根目录。通过 PHP-FPM 环境设置：

```text
PHOTO_SELECTOR_DATA_DIR=/var/lib/photo-selector
```

可参考 `deploy/nginx.example.conf`。不同面板对 PHP-FPM 环境变量的配置方式不同；若无法传递环境变量，当前代码会回退到站点父目录的 `private_model_photo_selector`。

## 首次部署

1. 将仓库中的 `index.php` 发布到站点根目录。
2. 创建私有数据目录并将所有者设为 PHP-FPM 运行用户。
3. 配置 `PHOTO_SELECTOR_DATA_DIR`。
4. 配置 HTTPS、Nginx 和 PHP-FPM，确保上传限制大于应用的 30 MiB 单文件限制，例如 40 MiB。
5. 打开站点，设置管理员密码。
6. 创建一个专用测试活动和模特，验证上传、查看、评级、备注、旋转和下载开关。
7. 删除测试数据前确认它确实不是生产活动。

相关 PHP/Nginx 参数至少关注：

- `upload_max_filesize`
- `post_max_size`
- `memory_limit`（应用当前也设置为 512M）
- `max_execution_time`
- `client_max_body_size`

浏览器目前每次请求只上传一张，选择 200 张不会形成单个超大 POST，但会持续产生多次请求。

## 生产升级

现有生产环境记录：

- 站点：`https://fes.rany.ing`
- 入口：`/www/wwwroot/fes.rany.ing/index.php`
- 数据：`/www/wwwroot/private_model_photo_selector`
- PHP-FPM：8.0

安全升级顺序：

1. 检查变更是否包含数据库初始化、迁移、删除或派生图规则变化；包含 `models.session_version` 的版本升级会执行兼容加列，旧模特 Session 需要重新登录。
2. 备份 `selector.sqlite`（包含 WAL/SHM 时应在一致性条件下备份）和整个 `originals/`。
3. 最稳妥的方式是短暂停止写入或进入维护窗口，再进行 SQLite 在线备份或文件复制。
4. 将现有 `index.php` 保存为带时间戳的服务器端回滚副本。
5. 原子替换新的 `index.php`，不要覆盖私有数据目录。
6. 运行服务器上的 `php -l`。
7. 做非破坏性冒烟测试：管理员登录、活动列表、模特访问码登录、缩略图、预览、评分保存。正式切换访问码前，务必告知模特旧登录将失效。
8. 检查 PHP-FPM 与 Nginx 错误日志。

## 备份范围

完整恢复至少需要：

- `selector.sqlite`，以及在直接复制时与其一致的 `-wal` / `-shm` 状态
- `originals/`
- 当前部署的 `index.php`

`previews/` 和 `thumbs/` 可以重建，但备份它们能加快恢复。只备份数据库而不备份原图，无法恢复照片；只备份原图而没有数据库，会丢失照片归属、原文件名、评级和备注。

备份中包含私密照片和访问控制信息，应加密、限制访问并设置保留期限。

## 回滚

- 仅源码问题且没有数据库结构变化：恢复上一版 `index.php`。
- 涉及数据结构或破坏性操作：停止写入，同时恢复匹配时间点的数据库和照片目录。
- 不要把新数据库和旧照片目录或旧数据库和新照片目录随意混用。

## 高风险操作

`delete_event` 和 `delete_model` 会永久删除数据库记录与文件系统中的原图、预览图、缩略图。禁止在生产环境做删除测试。上线任何删除逻辑变更前，应在隔离的临时数据目录完成验证并测试恢复。

