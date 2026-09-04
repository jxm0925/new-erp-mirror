# 简谈 ERP 重构项目

本仓库承载当前 ERP 重构项目的前后端源码。

```text
new-erp/
├─ backend/     Laravel API、数据库迁移、业务服务与自动化测试
└─ frontend/    Vue 管理端
```

## 本地启动

后端：

```powershell
cd backend
composer install
Copy-Item .env.example .env
php artisan key:generate
php artisan migrate --force
php artisan serve --host=127.0.0.1 --port=8011
composer reverb
```

`composer reverb` 是库存预警及后续审批/待办等实时消息的 WebSocket 服务，默认监听 `127.0.0.1:8083`，必须与后端服务一同运行。

前端：

```powershell
cd frontend
npm install
npm run serve
```

## 数据库原则

所有数据库结构均由 `backend/database/migrations` 管理。新环境使用一次 `php artisan migrate --force` 建立结构；不得在业务代码中执行 DDL。

## 仓库边界

- 不提交 `.env`、依赖目录、运行日志、构建产物或本地压缩包。
- 已通过的 ProductDesign 是页面实现规格；设计图、浏览器验收截图、报告和唯一开发进度文件统一保留在 `D:\codex-introduce\new_erp`，不写入源码仓库。
- 旧 ERP 数据仅在明确执行一次性同步时读取，不进行实时同步。
