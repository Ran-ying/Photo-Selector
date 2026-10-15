# 架构说明

## 总览

Photo Selector 是一个无框架、单入口 PHP 应用：

```text
浏览器
  ├─ 管理员界面 ─┐
  └─ 模特界面 ───┼─ HTTPS → index.php (?api=...)
                  │              ├─ PHP Session / CSRF / 权限
                  │              ├─ PDO → selector.sqlite
                  │              └─ GD/ImageMagick → 图片目录
                  └────────────────────────────────────────
```

`index.php` 前半部分负责初始化、数据库、图片处理和 API；未命中 API 路由时输出 HTML，页面内嵌 CSS 与原生 JavaScript。

## 启动与配置

关键常量：

- `MAX_UPLOAD_BYTES = 30 * 1024 * 1024`
- `MAX_UPLOAD_FILES = 200`
- `DERIVATIVE_VERSION = 5`

数据目录按以下顺序确定：

1. 环境变量 `PHOTO_SELECTOR_DATA_DIR`
2. `dirname(__DIR__) . '/private_model_photo_selector'`

启动时会创建数据目录及 `originals/`、`previews/`、`thumbs/`，打开 SQLite，启用外键和 WAL，并在不存在时创建表。

## 数据模型

### settings

| 字段 | 说明 |
| --- | --- |
| `key` | 设置名，主键 |
| `value` | 设置值；当前包含管理员密码哈希 |

### events

| 字段 | 说明 |
| --- | --- |
| `id` | 活动 ID |
| `name` | 活动名称 |
| `shoot_date` | 拍摄日期 |
| `created_at` | 创建时间 |

### models

| 字段 | 说明 |
| --- | --- |
| `id` | 模特 ID |
| `event_id` | 所属活动，删除活动时级联删除 |
| `cn_name` | CN |
| `character_name` | 角色名 |
| `access_code` | 唯一 6 位访问码，登录时转大写 |
| `allow_download` | 是否允许模特下载原图 |
| `review_status` | 历史完成状态字段；当前 UI 不使用 |
| `submitted_at` | 历史提交时间字段；当前 UI 不使用 |
| `created_at` | 创建时间 |

### photos

| 字段 | 说明 |
| --- | --- |
| `id` | 照片 ID |
| `model_id` | 所属模特，删除模特时级联删除 |
| `stored_name` | 随机生成的内部文件名 |
| `original_name` | 用户上传时的文件名 |
| `mime` / `file_size` | 文件类型与大小 |
| `width` / `height` | 原始像素尺寸 |
| `rotation` | 管理员手动旋转角度 |
| `rating` | 模特评分，1–5 或空 |
| `note` | 模特备注 |
| `reviewed_at` | 最近评价时间 |
| `created_at` | 上传时间 |

### access_attempts

用 `ip_hash` 聚合访问码失败次数和时间窗口。IP 不明文保存。

## 权限模型

Session 中存在两种互斥身份：管理员和模特。

- 管理员可以管理所有活动、模特和照片。
- 模特只能读取自己 `model_id` 下的照片，只能修改自己的评分和备注。
- 原图下载还要同时满足 `allow_download = 1`。
- 图片不是公开静态文件；`?api=photo&id=...` 会在输出前校验 Session 和资源归属。

所有 POST API 经过 CSRF 校验；管理端写操作还经过管理员校验，模特评价经过模特身份校验。

## API

统一响应为 JSON（图片输出除外）。错误响应包含合适的 HTTP 状态码和可显示给用户的简短消息。

### GET `?api=bootstrap`

返回 CSRF token、是否已初始化、当前身份以及当前身份可见的数据。管理员得到完整活动树；模特只得到自己的元数据和照片。

### GET `?api=photo`

参数：

- `id`：照片 ID
- `size`：`thumb`、`preview` 或 `original`
- `download`：可选，下载原图时触发附件响应

派生图不存在时按需生成。读取原图和下载权限均需服务端授权。

### 登录与初始化

- `setup`：首次设置管理员密码。
- `admin_login`：管理员登录。
- `model_login`：访问码登录，包含失败限流。
- `logout`：清除身份。

### 管理端写操作

- `create_event` / `delete_event`
- `create_model` / `update_model` / `delete_model`
- `regenerate_code`
- `toggle_download`
- `rotate_photo`
- `upload`

### 模特写操作

- `review`：保存一张照片的 `rating` 和 `note`。
- `submit_review`：遗留接口，当前 UI 不使用。

## 上传与派生图

浏览器为每张文件发起独立 XHR，因此能独立显示进度与错误并单张重试；任务队列限制为 3 路并发。

服务器上传流程：

1. 验证管理员身份、CSRF、模特归属、上传错误和文件大小。
2. 使用实际文件内容识别 MIME，只接受 JPEG、PNG、WebP。
3. 生成随机内部文件名并移动到 `originals/`。
4. 读取像素尺寸，写入数据库。
5. 按需生成预览图和缩略图。

派生图规格：

- preview：最长边 2200 px，JPEG 质量 86。
- thumb：最长边 560 px，JPEG 质量 78。
- 输出会应用 JPEG EXIF Orientation 和数据库手动旋转角度。
- 原文件永不因显示旋转而重写。

若改变尺寸、质量、方向处理或命名，应提高 `DERIVATIVE_VERSION`，以免客户端继续使用旧缓存。

## 前端状态与交互

- 首次加载和每次变更后通过 `bootstrap` 刷新状态。
- 管理员端按活动组织模特和照片；上传队列在浏览器内维护。
- 模特评分和备注通过 AJAX 自动保存，不存在最终提交步骤。
- 大图查看器支持缩略图打开、上一张/下一张、键盘方向键和触摸滑动。
- 手机端查看器使用动态视口单位和 `object-fit: contain` 保证完整显示。

