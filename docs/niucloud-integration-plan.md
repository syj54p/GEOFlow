# GEOFlow ↔ niucloud API 对接方案

> 版本：v1.0 | 日期：2026-11-01 | 状态：方案评审

---

## 一、系统定位对比

| 维度 | GEOFlow | niucloud (df_cms + df_diypage) |
|---|---|---|
| **核心定位** | GEO 内容工程平台（AI 生产 + 质量门禁 + 多站分发） | 多业态 SaaS 业务平台（CMS + 可视化页面 + 业务插件） |
| **内容生产** | AI 辅助写作、知识库、质量检查、标题/关键词库 | 手动编辑、模型字段、微信发布 |
| **内容分发** | 多站点分发（WordPress、Headless CMS 等） | 单站点展示（前台 + 移动端） |
| **页面搭建** | 主题系统（Theme Workspace） | df_diypage 可视化拖拽 |
| **用户体系** | 独立管理员（admins 表） | niucloud 统一用户（user 表 + site_id 隔离） |
| **技术栈** | Laravel 12 + PostgreSQL + Redis | ThinkPHP 8 + MySQL + Redis |

---

## 二、对接架构设计

### 方案 A：API 松耦合（推荐）

```
┌─────────────────────────────────────────────────────────────┐
│  GEOFlow（内容生产中台）                                      │
│  • AI 写作 + 质量门禁 + 知识库                                │
│  • 提供 REST API（Sanctum Token 认证）                        │
└──────────────────────┬──────────────────────────────────────┘
                       │ HTTP API
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  niucloud 插件层（df_geoflow_bridge）                        │
│  • 同步文章到 df_cms                                          │
│  • 为 df_diypage 提供内容组件                                  │
│  • 统一用户映射（GEOFlow admin ↔ niucloud user）              │
└──────────────────────┬──────────────────────────────────────┘
                       │
                       ▼
┌─────────────────────────────────────────────────────────────┐
│  niucloud 业务层                                              │
│  • df_cms：文章展示、栏目管理                                  │
│  • df_diypage：可视化页面调用 GEOFlow 内容                     │
│  • df_store/df_canyin 等：业务插件                            │
└─────────────────────────────────────────────────────────────┘
```

**优点**：
- 两个系统独立部署、独立升级
- GEOFlow 可服务多个 niucloud 站点
- 故障隔离，一方宕机不影响另一方基本功能

**缺点**：
- 需要维护 API Token 和同步逻辑
- 数据存在双写一致性挑战

### 方案 B：数据库共享（不推荐）

直接共用数据库，GEOFlow 写入 niucloud 的表。

**缺点**：
- 数据库类型不同（PostgreSQL vs MySQL）
- 表结构耦合，升级困难
- 违反中台边界原则

---

## 三、核心对接场景

### 场景 1：AI 生成内容 → df_cms 展示

**流程**：
```
GEOFlow AI 写作 → 质量检查通过 → 发布
    ↓ Webhook / 定时同步
df_geoflow_bridge 插件拉取已发布文章
    ↓ 转换字段映射
写入 df_cms_article 表
    ↓
niucloud 前台展示
```

**字段映射**：
| GEOFlow articles | df_cms df_cms_article | 说明 |
|---|---|---|
| id | id | 需维护映射关系表 |
| title | title | 直接映射 |
| slug | - | GEOFlow 用 slug，niucloud 用 id |
| content | content | HTML 内容，可能需要清洗 |
| category_id | category_id | 需维护分类映射 |
| author_id | author_id | 需维护作者映射 |
| published_at | create_time | 时间戳格式转换 |
| status | status | 状态值映射 |

### 场景 2：df_diypage 内容组件

**组件设计**：
```
┌─────────────────────────────────────────┐
│  df_diypage 组件：GEOFlow 文章列表        │
├─────────────────────────────────────────┤
│  配置项：                                 │
│  • 数据源：GEOFlow API 地址               │
│  • API Token：Sanctum Token              │
│  • 分类筛选：[下拉选择 GEOFlow 分类]       │
│  • 排序方式：最新 / 最热 / 推荐            │
│  • 显示数量：6 / 9 / 12                  │
│  • 缓存时间：30 分钟 / 1 小时 / 6 小时    │
├─────────────────────────────────────────┤
│  渲染：                                   │
│  • 调用 GEOFlow /api/v1/articles         │
│  • 本地缓存结果（Redis）                   │
│  • 降级：缓存过期时显示上次成功数据         │
└─────────────────────────────────────────┘
```

### 场景 3：知识库增强搜索

**流程**：
```
用户在 niucloud 前台搜索
    ↓
df_geoflow_bridge 拦截搜索请求
    ↓
调用 GEOFlow /api/v1/catalog（语义搜索）
    ↓
合并 GEOFlow 结果 + niucloud 本地结果
    ↓
返回统一搜索结果
```

### 场景 4：统一内容分发

**流程**：
```
GEOFlow 内容生产完成
    ↓
GEOFlow 分发渠道配置：
    • 渠道 1：WordPress 站点
    • 渠道 2：niucloud 站点（通过 df_geoflow_bridge）
    • 渠道 3：微信公众号
    ↓
GEOFlow 推送文章到各渠道
    ↓
df_geoflow_bridge 接收 Webhook
    ↓
写入 df_cms + 触发 df_diypage 缓存更新
```

---

## 四、接口清单

### GEOFlow 提供的 API（已有）

| 接口 | 方法 | 说明 | 认证 |
|---|---|---|---|
| `/api/v1/auth/login` | POST | 管理员登录获取 Token | 无 |
| `/api/v1/articles` | GET | 文章列表 | Bearer Token + `articles:read` |
| `/api/v1/articles/{id}` | GET | 文章详情 | Bearer Token + `articles:read` |
| `/api/v1/articles` | POST | 创建文章 | Bearer Token + `articles:write` |
| `/api/v1/articles/{id}` | PATCH | 更新文章 | Bearer Token + `articles:write` |
| `/api/v1/articles/{id}/publish` | POST | 发布文章 | Bearer Token + `articles:publish` |
| `/api/v1/catalog` | GET | 元数据（分类、作者、模型等） | Bearer Token + `catalog:read` |
| `/api/v1/materials/{type}` | GET | 素材库（关键词、标题、图片） | Bearer Token + `materials:read` |
| `/api/v1/tasks` | GET | 任务列表 | Bearer Token + `tasks:read` |
| `/api/v1/management/sites` | GET | 站点列表 | Bearer Token + `sites:read` |

### df_geoflow_bridge 需要实现的接口

| 接口 | 方法 | 说明 |
|---|---|---|
| `/addon/df_geoflow_bridge/adminapi/sync/articles` | POST | 手动触发文章同步 |
| `/addon/df_geoflow_bridge/adminapi/sync/categories` | POST | 同步分类映射 |
| `/addon/df_geoflow_bridge/adminapi/config` | GET/POST | 插件配置（API 地址、Token） |
| `/addon/df_geoflow_bridge/api/webhook` | POST | 接收 GEOFlow Webhook |
| `/addon/df_geoflow_bridge/api/articles` | GET | niucloud 前台调用（代理 GEOFlow API） |

---

## 五、实现步骤

### 第一阶段：基础对接（1-2 周）

1. **创建 df_geoflow_bridge 插件骨架**
   - 插件目录结构
   - 安装/卸载脚本
   - 后台设置页面

2. **实现 API Token 管理**
   - 在 GEOFlow 后台创建 API Token（scope: `articles:read`, `catalog:read`）
   - 在 df_geoflow_bridge 设置页保存 Token

3. **实现文章同步**
   - 定时任务拉取 GEOFlow 已发布文章
   - 字段映射转换
   - 写入 df_cms_article 表
   - 维护 ID 映射关系表

### 第二阶段：组件集成（1 周）

4. **df_diypage 内容组件**
   - 注册新组件类型
   - 组件配置表单
   - 前端渲染逻辑
   - Redis 缓存

5. **分类/作者映射管理**
   - 后台映射配置页面
   - 自动创建不存在的分类

### 第三阶段：高级功能（2-3 周）

6. **Webhook 实时同步**
   - GEOFlow 发布时推送 Webhook
   - df_geoflow_bridge 接收并处理

7. **语义搜索集成**
   - 拦截 niucloud 搜索请求
   - 调用 GEOFlow 向量搜索
   - 合并结果返回

8. **统一用户体系**
   - GEOFlow admin ↔ niucloud user 映射
   - 单点登录（可选）

---

## 六、数据同步策略

### 同步模式

| 模式 | 触发方式 | 延迟 | 适用场景 |
|---|---|---|---|
| **定时拉取** | Cron 每 5 分钟 | 5 分钟 | 文章列表、分类 |
| **Webhook 推送** | GEOFlow 发布时 | 秒级 | 单篇文章发布 |
| **手动同步** | 后台点击按钮 | 即时 | 批量同步、修复数据 |
| **按需代理** | 前台请求时 | 即时 | df_diypage 组件 |

### 冲突处理

- **以 GEOFlow 为准**：文章内容由 GEOFlow 生产，niucloud 只读展示
- **本地扩展字段**：niucloud 可添加展示相关字段（如排序、推荐位），不影响 GEOFlow
- **删除同步**：GEOFlow 软删 → niucloud 同步软删；GEOFlow 硬删 → niucloud 保留归档

---

## 七、风险与应对

| 风险 | 影响 | 应对措施 |
|---|---|---|
| GEOFlow API 不可用 | niucloud 前台内容缺失 | 本地缓存降级 + 告警通知 |
| 字段映射变更 | 同步失败 | 版本化映射配置 + 同步日志 |
| Token 过期 | 同步中断 | Token 自动刷新 + 过期告警 |
| 数据量过大 | 同步超时 | 分页拉取 + 增量同步 |
| 两个系统升级不兼容 | 接口变更 | API 版本锁定 + 升级前测试 |

---

## 八、下一步行动

- [ ] 确认对接方案（方案 A API 松耦合）
- [ ] 创建 df_geoflow_bridge 插件骨架
- [ ] 在 GEOFlow 后台创建 API Token
- [ ] 实现第一篇文章同步
- [ ] 开发 df_diypage 内容组件

---

## 附录：GEOFlow API Token 创建步骤

1. 登录 GEOFlow 后台：http://localhost:18080/geo_admin
2. 进入 **超级管理员** → **API Tokens**
3. 点击 **创建 Token**
4. 填写名称（如 `niucloud-bridge`）
5. 选择 Scopes：
   - `articles:read` — 读取文章
   - `catalog:read` — 读取元数据
   - `materials:read` — 读取素材（可选）
6. 保存 Token（仅显示一次）
