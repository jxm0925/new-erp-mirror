# ERP 项目指令入口

开始任何前端或后端工作前，必须完整读取并遵循：

- `D:\codex-introduce\new_erp\agent.md`
- `D:\codex-introduce\new_erp\DEVELOPMENT_PROGRESS.md`

影响分析、开发进度、验收报告、设计图、浏览器截图、审查包和临时说明统一保存在 `D:\codex-introduce\new_erp`，禁止在本源码仓库内新增此类文件。进度变化只维护外部唯一进度文件。

## 响应式设计强制规则（2026-09-08 用户指令锁定）
所有的页面不管是 PC 端还是移动端（小程序、H5、管理后台）都必须做成**响应式设计（Responsive Design）**。必须自适应所有不同型号与分辨率的屏幕（移动端自 320px、375px、390px、414px 到 430px+ 及折叠屏，PC 端自笔记本 1366px 到大屏 1920px+），利用弹性自适应布局（flex/grid、min-width: 0、box-sizing: border-box、文本截断与固定徽标防挤压），确保在所有不同型号设备下页面元素整齐贴合，绝不发生错位、重叠换行、无意横向溢出或操作按钮被挤跑丢失。
