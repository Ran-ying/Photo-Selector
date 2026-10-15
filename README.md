# Photo Selector（模特选片台）

一个适合摄影约拍现场使用的轻量选片网站。摄影师创建拍摄活动和模特，上传 JPEG/PNG/WebP 照片并生成 6 位访问码；模特无需注册，用访问码进入自己的图库，进行星级评价、填写备注，并在摄影师允许时下载原图。

项目刻意保持为单文件 PHP 应用：部署时只需发布 [`index.php`](index.php)，无需 Node.js、Composer 或数据库服务器。

## 当前功能

### 摄影师端

- 首次访问设置管理员密码，之后使用 Session 登录。
- 按“活动名称 + 拍摄日期”管理拍摄活动。
- 在活动下创建模特，记录 CN、角色名并自动生成 6 位数字字母访问码。
- 修改或删除模特、重新生成访问码并撤销已登录的旧模特 Session、控制模特是否可以下载原图。
- JPEG/PNG/WebP 多图上传；每张独立显示进度，默认 3 路并发，失败后可单独重传。
- 按上传顺序、星级从高到低/从低到高、未评级优先排序。
- 查看模特的星级和备注，并对显示方向错误的照片手动左转或右转。

### 模特端

- 无需账号，用访问码进入专属图库。
- 支持缩略图网格和大图查看，桌面端与手机端自适应。
- 1–5 星评级、备注自动保存。
- 上一张/下一张、键盘方向键和手机滑动切换。
- 摄影师允许时可以下载原图。
- 自动读取 JPEG EXIF 方向；仍不正确时可由摄影师手动旋转显示。

## 运行要求

- PHP 8.0 或更高版本
- PDO SQLite 扩展
- GD 与 fileinfo 扩展
- 可选：ImageMagick 的 `/usr/bin/convert`，存在时优先用于生成预览图和缩略图
- Web 服务器对数据目录有读写权限

开发环境可直接运行：

```bash
export PHOTO_SELECTOR_DATA_DIR="$(mktemp -d /tmp/photo-selector-dev.XXXXXX)"
php -S 127.0.0.1:8080 index.php
```

然后打开 `http://127.0.0.1:8080`。该命令使用仓库以外的临时数据目录；测试结束后自行清理。生产环境必须将 `PHOTO_SELECTOR_DATA_DIR` 指向 Web 根目录之外；不要把数据库和照片放入 Git 仓库。

## 自动化回归

本地安装 PHP、PDO SQLite、GD、fileinfo 后运行：

```bash
php -l index.php
python3 -m unittest discover -s tests -v
```

GitHub Actions 会在提交和 PR 时运行语法检查和隔离 API 集成测试。测试数据始终与生产数据隔离。

## 仓库结构

```text
.
├── index.php                  # 完整可部署应用：后端、API、HTML、CSS、JavaScript
├── AGENTS.md                  # 给 AI/开发者的完整接手说明和硬性约束
├── README.md                  # 项目入口文档
├── tests/
│   └── test_integration.py       # 独立 API 集成测试
├── .github/workflows/
│   └── php-regression.yml       # GitHub CI
├── docs/
│   ├── ARCHITECTURE.md        # 架构、数据模型、接口和图像流程
│   ├── DEPLOYMENT.md          # 部署、升级、备份与回滚
│   ├── DEVELOPMENT.md         # 开发规范和人工验收清单
│   ├── SECURITY.md            # 权限、隐私和安全边界
│   └── PROJECT_STATUS.md      # 交接状态、测试和下一步
└── deploy/
    └── nginx.example.conf     # Nginx + PHP-FPM 配置示例
```

## 文档入口

- AI 或新开发者接手：先读 [`AGENTS.md`](AGENTS.md)
- 理解代码与接口：[`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md)
- 本地开发与验收：[`docs/DEVELOPMENT.md`](docs/DEVELOPMENT.md)
- 部署和数据备份：[`docs/DEPLOYMENT.md`](docs/DEPLOYMENT.md)
- 安全与隐私：[`docs/SECURITY.md`](docs/SECURITY.md)
- 当前进度与下一步：[`docs/PROJECT_STATUS.md`](docs/PROJECT_STATUS.md)

## 当前生产环境

- 站点：<https://fes.rany.ing>
- 当前入口：`/www/wwwroot/fes.rany.ing/index.php`
- 当前私有数据目录：`/www/wwwroot/private_model_photo_selector`
- PHP-FPM：8.0

这些路径只是现有部署记录，不应写死进源码。新环境应通过 `PHOTO_SELECTOR_DATA_DIR` 配置数据目录。

## 重要提醒

删除活动或模特会永久删除其数据库记录、原图、预览图和缩略图。生产环境测试前必须同时备份 SQLite 数据库和整个照片目录，禁止拿真实数据测试删除功能。

本仓库不包含生产照片、数据库、管理员密码、访问码或服务器密钥。

