# Photo Selector — 当前状态与下一步

更新：2026-10-15。本文件是动态交接摘要；产品硬约束以 AGENTS.md 为准，实际行为以当前代码和测试为准。

## 当前实现

- 单文件 PHP 8.0+ 应用 index.php，无 Composer/Node 构建步骤。
- SQLite + 私有本地图片目录；管理员管理活动与模特，模特使用 6 位访问码进行评级与备注。
- JPEG/PNG/WebP 多图上传（浏览器 3 路并发、逐文件进度）、缩略图/预览图、EXIF 方向与手动显示旋转。
- 照片 API 按 Session 身份和模特归属鉴权；模特读原图还受下载开关约束。
- 下载响应保留 UTF-8 原文件名，同时提供安全 ASCII 兼容文件名。
- 更换访问码递增 models.session_version，旧模特 Session 在下一次鉴权时立即失效。
- 对旧 models 表自动兼容新增 session_version 列，不删除照片；升级前的旧模特 Session 需要重新登录。

## 验证方法及覆盖

- 语法：php -l index.php
- 变更检查：git diff --check
- 集成测试：python3 -m unittest discover -s tests -v
- GitHub CI：.github/workflows/php-regression.yml，在 push 和 PR 上运行。

测试使用仓库外临时数据目录和生成的 PNG，验证首次设置、活动与模特创建、大小写无关访问码、文件上传、中文文件名下载、原图字节一致性、跨模特权限、评级备注持久化、关闭下载后拒绝原图、访问码轮换后撤销旧 Session、旧 SQLite 模式兼容加列及照片记录保留。

尚未自动覆盖真实浏览器/手机交互、全部 EXIF 方向、超大像素边界、失败上传与重试、Nginx/PHP-FPM 环境差异及备份恢复演练。CI 成功不代表已在生产环境部署。

## 生产记录（尚未独立核实当前运行版本）

- URL：https://fes.rany.ing
- 入口：/www/wwwroot/fes.rany.ing/index.php
- 私有数据：/www/wwwroot/private_model_photo_selector
- PHP-FPM：8.0

**生产当前部署的 commit SHA 未核对。** 不得仅根据 Git HEAD 推断生产代码版本。发布前须在服务器上只读检查实际文件哈希、PHP/扩展、FPM 配置、数据目录权限、备份可恢复性。必须一致性备份 SQLite 数据库（包含 WAL 的状态）和 originals 目录；保留可回滚的 index.php 版本。不得将生产照片、数据库、访问码、密码、Session 或密钥复制进仓库或 CI。

## 后续优先级

1. P0：添加真实浏览器回归：390px 手机大图完整显示、滑动/导航、评分自动保存、上传队列进度和重试；添加 EXIF、异常图片及内存边界测试。
2. P0：只读核实线上部署版本；记录 commit 或实际源码哈希；建设非破坏性的发布核对脚本。
3. P1：规划回收站/软删除（数据库迁移、照片恢复、清理策略）。当前删除仍永久有效，禁止在生产环境以真实数据测试。
4. P1：管理员编辑模特改用经过验证的表单对话框，代替浏览器 prompt。
5. P2：在测试保护下逐步分层 index.php，始终保留无需构建的部署入口。
6. 未授权不要增加 Nikon 自动上传、第三方对象存储/分析、公开图库或额外账号系统。

## 新 AI 无上下文接手流程

1. 依次阅读 README.md、AGENTS.md、本文、相关 docs 专项文档。
2. 读取当前 git status / HEAD / 分支，检查未合并 PR、CI 与近期提交，勿覆盖未提交改动。
3. 使用 Web 根目录之外的全新临时数据目录本地运行；先执行 PHP 语法和集成测试。
4. 仅在有明确需求时最小范围修改，补对应回归测试，兼容 PHP 8.0。
5. 使用独立分支和 PR，等待 CI；真实数据、部署、删除、密钥与发布都需明确操作授权及可恢复的备份计划。
6. 修改功能时同步更新文档与本文；正式发布后记录线上实际版本。
