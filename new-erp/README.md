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

一张表一份完整初始建表 migration，包含全部字段、索引和约束。开发期修改对应文件，不叠加增删字段等变更 migration；新增新表仍各自一份完整建表文件。修改前先读原定义及涉及该表的历史迁移，并核对实际开发库。历史迁移整理须先列范围及记录兼容影响，不批量重写无关迁移。

全库已整理为 **285 份单表完整初始 migration**，覆盖业务及框架表；Laravel 自管 migrations 记录表不另建文件。字段、索引、外键及历史 CHECK 意图集中各自文件，公共 helper 仅负责安全预检与执行机制。189 份历史原文和全量映射保存在外部 docs/schema-consolidation-20261012。原迁移中的生产阶段/包装目录移至 ErpProductionCatalogSeeder，只按编码补缺。

编辑已执行文件不会自动改库。2026-10-12 已按明确授权补齐生产耗料表 prod_material_consumption_source_uq，并由 Laravel 完成 285 条新基线登记；现有 286 表保留，285/285 目标结构匹配，记录共 474 条（189 历史＋285 新基线），待登记 0。原历史记录保留，禁止清空记录或 fresh/reset。普通 helper 仍严格拒绝漂移；精确兼容仅归属该表初始文件，不能推广为任意自动修复。会话恢复失败会隔离不安全 PDO 并抛错，初始迁移 down 拒绝自动删表。MySQL 8.0.12 不强制 13 项历史 CHECK；部分入口已补校验，不代表所有写路径受保护。

全库只读核验：在 backend 运行 php tools/verify-schema-baseline-readonly.php。不启动业务应用、不执行迁移或Seeder；退出0表示结构与记录一致，1表示结构差异或待基线登记，2表示配置/连接诊断失败。新空库一次migrate的循环FK路径已做离线检查，尚未实际创建空库运行。

基础数据统一通过专用 Seeder，只补缺失定义并保留已有业务数据、人工字段、时间戳、账号及授权。默认链已移出管理员初始化、历史权限合并/授权扩展和旧审批版本修复；先严格全结构预检，再事务执行，身份或依赖冲突报错。新审批模板只补 v2 未发布草稿，完成动作需经正式配置验证发布，不能伪造已发布有效版。历史升级需另按受控方案；2026-10-12 已按用户新授权在开发库实际执行默认 Seeder，8 项全部成功，仅补 2 条采购审批字段定义，既有配置和授权保留；详见外部 authorized-seeder-execution.md。此前纯内存测试不替代真实业务流程验收。详见外部 authorized-closeout-seeders.md。

当前第一阶段在现有开发库做非破坏性验证：在 `backend` 运行 `php tools/verify-phase-one-readonly.php`。入口使用现有 `.env`，通过数据库只读事务核对结构与已有记录关系，不启动 Laravel 业务流程、不造测试记录。退出码 0 表示只读检查通过，1 表示有缺失结构或需核对的数据，2 表示诊断配置或连接失败；结果不替代实际业务流程验收。

既有 PHPUnit Feature 测试会写入夹具数据，仍由 `_test` 保护约束，本轮不执行或重定向到开发库；开发库验证不以取得测试库许可为前提。无数据库单测可独立执行。禁止在已有开发库运行 `migrate:fresh`、`RefreshDatabase`、`truncate` 或清库脚本。

## 仓库边界

- 不提交 `.env`、依赖目录、运行日志、构建产物或本地压缩包。
- 已通过的 ProductDesign 是页面实现规格；设计图、浏览器验收截图、报告和唯一开发进度文件统一保留在 `D:\codex-introduce\new_erp`，不写入源码仓库。
- 旧 ERP 数据仅在明确执行一次性同步时读取，不进行实时同步。
