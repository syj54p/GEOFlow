# GEOFlow 多租户适配实施方案（路线 A+：轻量映射 + Token 绑定）

> **版本**：v1.2
> **日期**：2026-11-07
> **状态**：Phase 0 三项决策已确认（2026-11-07），无阻塞项，可按 Phase 1 开工
> **前置依赖**：GEO 诊断 MVP 已交付（commit `5e9cc7d`）
> **修订说明**：v1.2 基于对 GEOFlow / df_geo_bridge 实际代码的逐条核实重写，修正了 v1.1 中与代码现状脱节的假设（路由鉴权面、Service 层现状、null 语义、job 层缺口等）。变更明细见附录 A。

---

## 1. 背景

### 1.1 系统全景

GEOFlow 定位为 **GEO（生成式引擎优化）大脑**，不直接面向终端用户，而是作为 niucloud SaaS 平台的后端智能服务，通过 `df_geo_bridge` 桥接插件与 niucloud 的各个租户站点通信。

```
┌─────────────────────────────────────────────────────────────────────┐
│                        niucloud SaaS 平台                           │
│  (ThinkPHP 8 + MySQL，标准多租户架构，site_id 贯穿所有业务表)        │
│                                                                     │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐              │
│  │ 站点 A       │  │ 站点 B       │  │ 站点 C       │  ...         │
│  │ site_id=1    │  │ site_id=2    │  │ site_id=3    │              │
│  │ 宠物用品商城 │  │ 餐饮连锁     │  │ 物业管理     │              │
│  │              │  │              │  │              │              │
│  │ df_geo_bridge│  │ df_geo_bridge│  │ df_geo_bridge│              │
│  │ config(token)│  │ config(token)│  │ config(token)│              │
│  └──────┬───────┘  └──────┬───────┘  └──────┬───────┘              │
│         │                  │                  │                      │
│         └──────────────────┼──────────────────┘                      │
│                            │  各自的 REST API 调用                   │
└────────────────────────────┼────────────────────────────────────────┘
                             │
                             ▼
┌────────────────────────────────────────────────────────────────────┐
│                     GEOFlow (Laravel 12 + PostgreSQL)               │
│                     单实例部署，当前无租户隔离                        │
│                                                                    │
│  ┌────────────┐  ┌────────────┐  ┌────────────┐  ┌────────────┐   │
│  │ 文章管理    │  │ 知识库     │  │ GEO 诊断   │  │ 品牌监测    │   │
│  │ articles   │  │ knowledge  │  │ diagnosis  │  │ brand_*     │   │
│  │ (无site_id)│  │ (无site_id)│  │ (部分有)   │  │ (有列未用)  │   │
│  └────────────┘  └────────────┘  └────────────┘  └────────────┘   │
│                                                                    │
│  ┌────────────────────────────────────────────────────────────┐    │
│  │ HostedSiteProfile (内容分发子域名，≠ niucloud 租户概念)     │    │
│  └────────────────────────────────────────────────────────────┘    │
└────────────────────────────────────────────────────────────────────┘
```

### 1.2 niucloud 多租户模型

niucloud 是标准 SaaS 多租户平台，**`site_id` 是贯穿所有业务数据的核心隔离维度**。

**租户模型核心表**：

| 表 | 主键 | 说明 |
|---|---|---|
| `site` | `site_id` | 租户站点表，每个客户至少一个站点。`site_id=0` 为平台级默认站点 |
| `site_group` | `group_id` | 站点分组，用于归类管理 |
| `site_merchant_bind` | `id` | 站点与商户的绑定关系 |
| `sys_user_role` | — | 用户角色表，含 `site_id` 字段，控制用户只能操作所属站点数据 |

**site_id 的传递机制**：

niucloud 的所有 Service 继承 `BaseAdminService` 或 `BaseApiService`，基类在构造时自动从请求上下文解析 site_id：

```php
// niucloud/core/base/BaseAdminService.php
protected $site_id;

public function __construct()
{
    $this->site_id = $this->request->siteId();  // 从 session/token 自动解析
}
```

所有 Model 查询都隐式带 `where('site_id', $this->site_id)`，业务代码无需手动处理隔离。这是 niucloud 的**铁律级约定**。

**df_geo_bridge 插件如何获取 site_id**：

bridge 插件的控制器同样继承 `BaseAdminController`，通过 `$this->request->siteId()` 获取当前站点 ID：

```php
// niucloud/addon/df_geo_bridge/app/adminapi/controller/setting/Config.php
public function get(): Response
{
    $siteId = (int) $this->request->siteId();  // ← niucloud 自动注入
    $service = new ConfigService();
    $config = $service->getConfig($siteId);
    return success($config);
}
```

bridge 的 `df_geo_bridge_config` 表按 `site_id` 存储每个站点独立的 GEOFlow 连接配置：

| 字段 | 说明 |
|---|---|
| `site_id` | niucloud 站点 ID（唯一） |
| `api_base_url` | 该站点连接的 GEOFlow API 地址 |
| `api_token` | 该站点使用的 Bearer Token（**每站点各一个，这是 token 绑定 site_id 的现成基础**） |
| `auto_sync` | 是否自动同步文章 |
| `default_category_id` | 默认分类映射 |

### 1.3 GEOFlow 现有的"站点"概念

GEOFlow 内部有一套 **HostedSiteProfile** 体系，但它的语义与 niucloud 的 `site_id` 完全不同：

| 维度 | niucloud `site_id` | GEOFlow `HostedSiteProfile` |
|---|---|---|
| **语义** | 租户/客户隔离 | 内容分发目标（子域名站点） |
| **粒度** | 一个客户 = 一个 site_id | 一个客户可建多个子域名站点 |
| **用途** | 数据隔离：文章、订单、会员各自分开 | 内容发布：文章发布到 `client-a.geoflow.site` 子域名 |
| **解析方式** | 从 session/token 自动注入 | 从 HTTP Host 头 + DNS 解析 |
| **数据模型** | `site` 表，`site_id` 贯穿所有业务表 | `hosted_site_profiles` 表，关联 `distribution_channels` |

**HostedSiteProfile 核心字段**：

```
hostname          — 子域名（如 client-a.geoflow.site）
root_domain       — 根域名（如 geoflow.site）
serving_status    — 服务状态（online/maintenance/archived）
indexing_status   — 搜索引擎索引状态（index/noindex）
daily_publish_limit — 每日发布限额
topic             — 站点主题
locale            — 语言区域
```

**关键结论**：GEOFlow 的 HostedSiteProfile 是"一个 GEOFlow 实例下挂多个对外发布的子域名站点"，不是"多个客户共享一个 GEOFlow 实例"。两者不能混用。

### 1.4 当前数据流与断层（v1.2 按实际代码核实重写）

#### 已有数据流（bridge 文章同步）

```
niucloud site_id=1                    GEOFlow
  │                                      │
  │  GET /api/v1/articles                │
  │  Authorization: Bearer {token}       │
  │  Host: localhost                     │  ← 固定 localhost，不区分租户
  │  (无 X-Site-Id)                      │  ← 没有租户标识
  │ ──────────────────────────────────→  │
  │                                      │  返回所有文章（无租户过滤）
  │  ← ────────────────────────────────  │
  │                                      │
  │  写入 df_cms_article                 │
  │  SET site_id = 1                     │  ← niucloud 侧做了隔离
  │  写入 df_geo_bridge_article_mapping  │
  │  SET site_id = 1                     │  ← 映射表也做了隔离
```

**问题**：niucloud 侧靠 `site_id` 隔离了同步的文章，但 GEOFlow 侧完全不知道这次调用属于哪个客户。当前文章同步能工作，是因为 bridge 拉取的是 GEOFlow 全量文章再写入自己的 `site_id=1` 库——**隔离发生在 niucloud 侧，GEOFlow 侧是裸的**。

#### GEO 诊断 / 品牌监测的真实现状（v1.2 更正）

v1.1 假设"bridge 用 Bearer Token 调诊断端点"——**这与代码不符**。核实结果：

1. **诊断/品牌监测的全部端点都在 admin web 面**：`routes/web.php` 的 `admin.` 前缀组内（`geo-diagnosis`、`brand-monitor`、`articles/{articleId}/geo-diagnosis` 三个子组，约 490–563 行），守卫是 `admin.auth`（`AuthenticateAdminWeb`，**纯 session**，未登录跳转 admin.login）。`routes/api.php` 中**没有任何**诊断/品牌监测端点。
2. **bridge 目前没有任何诊断/品牌监测调用**：`df_geo_bridge/app` 全目录 grep 无 diagnosis/brand 相关代码。诊断目前只能从 GEOFlow 自己的后台 UI 触发。
3. **Service 层比 v1.1 描述的要好**：`KeywordManager`、`BrandMonitorOrchestrator`、`BrandVisibilityService`、`RunBrandMonitorJob` **已经**全链路支持 `?int $siteId` 参数（查询用 `when($siteId !== null, ...)`）。缺的是入口层传参和 `geo_diagnosis_runs` 的落库列。
4. **已有的 partial 接线**：`ArticleDiagnosisController::run()` 已经读 `$request->input('site_id')` 放进 `$context`，orchestrator 用它取 `GeoDiagnosisSetting::getForSite()`——但 **run 记录本身不落 site_id**（表里没这列）。

因此多租户适配的第一件事不是"给现有 API 加 Header"，而是**先把诊断/品牌监测能力暴露到 token 鉴权的 API 面**，否则 bridge 的 Bearer 调用会被 session 守卫直接 401。

#### 品牌监测定时任务 —— v1.1 完全遗漏的数据错标

`routes/console.php` 每小时调度 `new RunBrandMonitorJob()`（siteId=null，即全量）。`BrandVisibilityService::refreshVisibility()` 写结果时：

```php
BrandMonitorResult::create([
    'site_id' => $siteId,   // ← 用的是传入参数（null），不是关键词自身的 site_id！
    'keyword_id' => $keyword->id,
    ...
]);
```

**即使关键词已归属租户 A，定时任务产出的监测结果仍全部标 NULL**。多租户上线后这是持续性的数据污染，必须修（见 Phase 4.3）。

#### 不隔离的故障场景（保留 v1.1 结论，仍成立）

**场景 1：诊断报告串户**
> 客户 A（宠物商城，site_id=1）和客户 B（餐饮连锁，site_id=2）都同步了同一篇 GEOFlow 文章（article_id=42）。两人分别触发 GEO 诊断，结果写入 `geo_diagnosis_runs` 时无 site_id 区分。客户 A 打开诊断面板可能看到客户 B 的评分结果。

**场景 2：关键词互相可见**
> 客户 A 配置了品牌关键词"萌友宠物"，客户 B 配置了"御湖物业"。入口层不传 site_id 时 `when(null)` 不过滤，两人的品牌监测 Dashboard 上会看到对方配置的关键词和监测结果。

**场景 3：竞品分析混淆**
> 客户 A 的竞品是"宠物之家"、"小狗当家"，客户 B 的竞品是"万科物业"、"碧桂园服务"。`brand_competitors` 不按 site_id 隔离，AI 可见性分析会把所有竞品混在一起计算引用占比。

**场景 4：诊断设置互相覆盖**
> `geo_diagnosis_settings` 使用 `getForSite(null)` 单例模式（firstOrCreate），所有客户共享同一套维度权重和检查项配置。客户 A 调整了权重，客户 B 的设置也跟着变。

### 1.5 数据归属矩阵（v1.2 更新现状列）

| 数据类型 | GEOFlow 表 | 当前 site_id | 现状核实 | 隔离需求 | 优先级 |
|---|---|---|---|---|---|
| 文章 | `articles` | **无** | GEOFlow 创建，niucloud 同步 | niucloud 侧靠映射表隔离，GEOFlow 侧暂不需要 | P2 |
| 文章映射 | niucloud `df_geo_bridge_article_mapping` | **有** | ✅ 已隔离 | — | — |
| GEO 诊断记录 | `geo_diagnosis_runs` | **无** | context 里有 site_id 但不落库 | **必须隔离** | **P0** |
| 诊断设置 | `geo_diagnosis_settings` | 有（nullable, unique） | `getForSite()` 已支持，入口传 null | 需启用 | P0 |
| 品牌监测平台 | `brand_platforms` | 有（nullable） | Service 已支持过滤，入口未传 | 需启用 | P0 |
| 品牌监测关键词 | `brand_keywords` | 有（nullable, composite unique） | `KeywordManager` 已支持，入口未传 | **必须隔离** | **P0** |
| 品牌监测结果 | `brand_monitor_results` | 有（nullable） | **定时任务错标 NULL（见 1.4）** | **必须隔离 + 修 job** | **P0** |
| 品牌竞品 | `brand_competitors` | 有（nullable, composite unique） | 入口未传 | 需启用 | P0 |
| 活动日志 | `brand_activity_log` | 有（nullable） | 入口未传 | 需启用 | P0 |
| API Token | `personal_access_tokens` | **无** | Sanctum 标准表 + abilities scopes | **MVP 加列绑定 site_id** | **P0** |
| 知识库 | `knowledge_bases` / `knowledge_chunks` | **无** | 全局共享 | 后续需隔离 | P2 |
| AI 质检记录 | `ai_quality_*` | **无** | 质检跟随文章 | 文章不隔离则质检不隔离 | P3 |
| 分发渠道/托管站点 | `distribution_channels` / `hosted_site_profiles` | **无**（语义不同） | GEOFlow 内部概念 | 与 niucloud 租户无关 | — |

### 1.6 设计约束（v1.2 修订）

基于以上分析，方案必须满足以下约束：

1. **niucloud 是租户权威源**：site_id 由 niucloud 生成和管控，GEOFlow 被动接收，不自行管理租户。
2. **不改 GEOFlow 内部站点体系**：HostedSiteProfile/DistributionChannel 是内容分发概念，与租户隔离无关，不混用。
3. **双面分治**：admin web 面（session 守卫）保持超管全局视图，MVP 不动；**API 面（Bearer + scope）是租户隔离的强制边界，fail-closed**——解析不到 site 上下文直接拒绝，绝不降级全局。
4. **Token 绑定进 MVP**：niucloud 每站点本来就各存一个 api_token，GEOFlow 的 `ApiTokenService::createToken` 已有 scopes 体系，绑定成本低。Header 仅作未绑定 token 的过渡通道；已绑定 token 的 Header 不匹配即 403。
5. **向后兼容**：现有 bridge 文章同步（不传 site_id）不受影响——文章端点不在租户强制范围内；诊断/品牌监测是新暴露的 API 面，无历史调用方，不存在兼容包袱。
6. **最小侵入**：不改 articles、knowledge_bases 等核心表结构，只在诊断/品牌监测域 + token 表做隔离。

### 1.7 方案选型对比

| 方案 | 思路 | 改动量 | 安全性 | 兼容性 | 结论 |
|---|---|---|---|---|---|
| **A. Header 轻量映射** | bridge 传 `X-Site-Id`，中间件解析注入 | 小 | 中（Header 可伪造） | 好 | v1.1 选定，**安全性不足** |
| **A+. Header + Token 绑定** | A 的基础上，token 发行时绑定 site_id，中间件优先取绑定值 | 小+（一列 + 发行流程一处） | 好（伪造 Header 无效） | 好 | **✅ v1.2 选定 MVP 方案** |
| B. HostedSite 复用 | 把 site_id 映射为 HostedSiteProfile | 大（改 Resolver、改路由解析、语义冲突） | 好 | 差（语义不匹配） | ❌ 语义不匹配 |
| C. 独立租户表 | GEOFlow 新建 tenants 表，与 niucloud site 做映射 | 中 | 好 | 中（需双向同步） | ⏳ 留作后续演进 |

**选择 A+ 的理由**：

1. GEOFlow 定位为 niucloud 的附属服务（"GEO 大脑"），不需要独立的租户管理能力（排除 C）。
2. niucloud 侧每站点独立 token 是现成事实，绑定 site_id 只是"发行时多写一列"，把 v1.1 最大的安全风险（Header 伪造）在 MVP 就关掉。
3. GEOFlow 生产部署在公网（geo.jiakeyuan.net），v1.1 "同内网风险可控"的前提不成立，不能把安全加固推迟到 MVP 之后。
4. 后续仍可平滑升级到 C（tenants 表 + 双向同步），不浪费 MVP 投入。

---

## 2. 实施方案

### 2.1 总体架构：双面分治

```
┌────────────────────────────── GEOFlow ──────────────────────────────┐
│                                                                     │
│  API 面（租户强制边界）              Admin Web 面（超管全局视图）     │
│  routes/api.php /api/v1/...         routes/web.php /admin/...       │
│  ┌─────────────────────────┐        ┌─────────────────────────┐     │
│  │ api.request_id          │        │ admin.auth (session)    │     │
│  │ api.auth (Bearer)       │        │ admin.activity/recent   │     │
│  │ api.recovery            │        │ (MVP 保持现状，不传     │     │
│  │ api.scope:geo-*         │        │  site = 全局聚合)       │     │
│  │ api.tenant ← 新增       │        └───────────┬─────────────┘     │
│  │  · token 绑定 site 优先 │                    │                   │
│  │  · Header 不匹配 → 403  │                    │                   │
│  │  · 解析不到 → 422       │                    │                   │
│  └───────────┬─────────────┘                    │                   │
│              │        复用同一套 Service/Orchestrator               │
│              └────────────────┬─────────────────┘                   │
│                               ▼                                     │
│              Service/Model 按 site_id 过滤/落库                     │
│              （API 面 siteId 恒非 null；web 面 null = 全局）         │
└─────────────────────────────────────────────────────────────────────┘
```

### 2.2 数据流（bridge 诊断链路）

```
niucloud 站点管理员操作（session 已含 site_id）
  │
  ▼
df_geo_bridge adminapi 控制器（新增）
  │ $this->request->siteId()
  ▼
GeoflowApiService::fromSiteId($siteId)
  │ 读 df_geo_bridge_config（该站点的 base_url + token）
  │ 构造实例，siteId 必填且 > 0
  ▼
HTTP → GEOFlow /api/v1/articles/{id}/geo-diagnosis/run
  Headers:
    Authorization: Bearer {token}     ← 该 token 发行时已绑定 site_id
    X-Site-Id: {site_id}              ← 与绑定值一致（不一致即 403）
  │
  ▼
api.auth → api.scope → api.tenant（ResolveTenant）
  │ 注入 ApiAuthContext.siteId + request attribute tenant_site_id
  ▼
Api/V1 控制器 → Service → Model
  │ 查询 where('site_id', $siteId)；写入落 site_id
  ▼
响应仅含该 site 数据
```

### 2.3 null 语义统一表（v1.2 新增，消除 v1.1 两套口径）

| 场景 | siteId 取值 | 查询行为 | 说明 |
|---|---|---|---|
| API 面，token 已绑定 | 绑定值（非 null） | 严格 `where('site_id', $id)` | Header 缺失用绑定值；不匹配 403 |
| API 面，token 未绑定（过渡期） | Header 值（必须 >0） | 严格 `where('site_id', $id)` | Header 缺失/非法 → **422 拒绝** |
| Admin web 面（超管） | null | `when($siteId !== null, ...)` 不过滤 = 全局聚合 | MVP 保持现状 |
| 遗留数据（site_id=NULL 的旧 run/结果） | — | 仅全局视图可见 | 租户视图永远看不到；不做回填 |

**铁律**：API 面解析不到 site 上下文时 fail-closed（422），任何情况下不降级为全局查询。v1.1 中"bridge 忘传 header 静默拿全量"的升权路径被堵死。

---

## 3. 分阶段实施

### Phase 0：前置核实与决策确认（0.5 天）

> **2026-11-07：三项遗留决策已确认**，以下为决议与派生行动项。

1. **网络拓扑与 HTTPS —— 已确认：按公网链路对待，HTTPS 强制校验进本期**。GEOFlow 生产为公网域名（geo.jiakeyuan.net），"同一内网"前提不成立；且 bridge `ConfigService::saveConfig` 对 `api_base_url` 零校验（仅 rtrim），`install.sql`/语言包示例仍引导 `http://`。不再以逐站核实拓扑作为前置阻塞，直接按最坏情况设计。行动项：
   - bridge `saveConfig` 增加 scheme 校验：仅允许 `https://`；`http://` 只放行 localhost/127.0.0.1 开发回环。示例文案（install.sql 注释、zh-cn/en dict）同步改 `https://`。
   - `GeoflowApiService` 构造函数与 `login()` 中硬编码的 `'Host' => 'localhost'` 请求头**必须去掉**——公网 HTTPS 下该头会命中错误的 nginx vhost（现有代码实测存在）。
   - 存量各站 `api_base_url` 做一次**只读盘点**；发现公网 http 地址的站引导改配置，不做静默兼容。
   - token 绑定进 MVP 的决策不变（见 §1.7 路线 A+）。
2. **品牌平台归属规则 —— 已确认采纳**：`brand_platforms.site_id = NULL` 视为全局共享平台；非 NULL 则只服务于同 site 的关键词。依据：现有平台行全部为 NULL（入口层从未传 site_id），规则对存量完全向后兼容；平台本质是 AI 引擎端点/凭据类技术配置而非租户业务数据。定时任务按此规则配对（Phase 4.2 守卫 + 单测），避免租户 A 的关键词跑在租户 B 的私有平台配置上。租户私有平台配置界面留待后续演进，本期不做。
3. **PG `NULLS NOT DISTINCT` —— 已确认不纳入本期（P2）**：应用层 `updateOrCreate` 已兜底去重；NULL-site 全局桶是过渡态遗留数据，随 token 绑定推进只会萎缩；裸 `DB::statement` 重建唯一约束有锁表与约束名跨环境漂移风险，收益边际。**重访触发条件**：审计发现全局桶出现重复关键词，或演进到路线 C（tenants 表）使全局桶成为长期一等模式时再收口（见 §4 迁移备注）。
4. **scope 命名 —— 已确认**：`geo-diagnosis:read` / `geo-diagnosis:write` / `brand-monitor:read` / `brand-monitor:write`。

### Phase 1：Tenant Context 基础设施（1–1.5 天）

**目标**：建立租户上下文传递机制 + token 绑定。

#### 1.1 扩展 ApiAuthContext

**文件**：`app/Http/ApiAuthContext.php`（现状：`token` array + `auditAdminId` int）

```php
final class ApiAuthContext
{
    public function __construct(
        public array $token,
        public int $auditAdminId,
        public ?int $siteId = null  // ← 新增
    ) {}
}
```

#### 1.2 Token 表加 site_id 列 + 发行/读取流程接线

**迁移**：`personal_access_tokens` 加 `site_id`（nullable, unsignedBigInteger, index）。详见 Phase 2.2。

**文件**：`app/Services/Api/ApiTokenService.php`

- `createToken(string $name, array $scopes, ?int $adminId, ?string $expiresAt = null, ?int $siteId = null)`：在现有 `$model->forceFill(['recovery_epoch' => ...])->save()` 同一位置一并写入 `site_id`（Sanctum 标准模型无需自定义类，forceFill 即可）。
- `hydrate()` 返回数组增加 `'site_id' => $row->site_id !== null ? (int) $row->site_id : null`，使 `getActiveTokenByPlaintext()` 的 token array 携带绑定值。
- `getAvailableScopes()` 注册 Phase 0 确认的四个新 scope。

**文件**：`app/Http/Controllers/Admin/ApiTokenController.php`

- `store()` 接受可选 `site_id` 输入（正整数校验），透传给 `createToken`；Token 管理页表单加"绑定站点"可选字段。
- `listTokens()` 输出含 site_id，便于运营核对。

> **运营流程**：给 niucloud 站点发 token 时在 GEOFlow 后台填对应 site_id；把 token 写入该站点的 `df_geo_bridge_config.api_token`。存量未绑定 token 仍可用（走 Header 过渡通道），逐步换发。

#### 1.3 新增 ResolveTenant 中间件（API 面专用，fail-closed）

**文件**：`app/Http/Middleware/ResolveTenant.php`（新建）

```php
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Http\ApiAuthContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * 解析 API 请求的租户上下文（site_id）。仅挂在 api.auth 之后的 API 路由组。
 *
 * 规则（fail-closed）：
 *   1. token 已绑定 site_id：Header 缺失 → 用绑定值；Header 不匹配 → 403
 *   2. token 未绑定（过渡期）：Header 必须为 >0 整数，否则 422
 *   3. 绝不降级为 null/全局
 */
class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $auth = $request->attributes->get('api_auth');
        if (! $auth instanceof ApiAuthContext) {
            // 未走 api.auth 的路由不允许挂本中间件
            throw new ApiException('unauthorized', '缺少认证上下文', 401);
        }

        $boundSiteId = isset($auth->token['site_id']) && $auth->token['site_id'] !== null
            ? (int) $auth->token['site_id']
            : null;

        $headerValue = $request->header('X-Site-Id');
        $headerSiteId = (is_string($headerValue) && ctype_digit($headerValue) && (int) $headerValue > 0)
            ? (int) $headerValue
            : null;

        if ($boundSiteId !== null) {
            if ($headerSiteId !== null && $headerSiteId !== $boundSiteId) {
                throw new ApiException('tenant_mismatch', 'X-Site-Id 与 Token 绑定站点不一致', 403);
            }
            $siteId = $boundSiteId;
        } else {
            if ($headerSiteId === null) {
                throw new ApiException('tenant_required', '缺少租户标识：请传入 X-Site-Id 或使用绑定站点的 Token', 422);
            }
            $siteId = $headerSiteId;
        }

        $request->attributes->set('api_auth', new ApiAuthContext($auth->token, $auth->auditAdminId, $siteId));
        $request->attributes->set('tenant_site_id', $siteId);

        return $next($request);
    }
}
```

> 与 v1.1 的差异：去掉 query/input 回退（API 面不接受 URL 传租户，防日志/缓存泄漏）；去掉 null 降级；增加绑定值优先与不匹配 403。Admin web 面的超管筛选是 Phase 6 的事，不走本中间件。

#### 1.4 注册中间件别名

**文件**：`bootstrap/app.php`（Laravel 12 无 Kernel.php，v1.1 该选项删除）

```php
$middleware->alias([
    // ... 现有别名（api.auth / api.scope / admin.auth 等）
    'api.tenant' => \App\Http\Middleware\ResolveTenant::class,
]);
```

#### 1.5 Tenant 读取辅助：trait 而非基类方法

v1.1 把 `tenant()` 加在 `Api/V1/BaseApiController`，但 Admin 面控制器继承的是 `App\Http\Controllers\Controller`——放基类两边够不着。改为 trait：

**文件**：`app/Http/Controllers/Concerns/ResolvesTenant.php`（新建）

```php
<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Http\Request;

trait ResolvesTenant
{
    /** API 面：经过 api.tenant 后恒非 null */
    protected function tenantId(Request $request): ?int
    {
        $v = $request->attributes->get('tenant_site_id');
        return $v !== null ? (int) $v : null;
    }
}
```

新建的 Api/V1 控制器 `use ResolvesTenant`；Phase 6 若 admin web 面要加站点筛选，同样复用。

### Phase 2：数据库迁移（0.5 天）

**目标**：补齐 site_id 列，建立索引。

#### 2.1 geo_diagnosis_runs 加 site_id

**文件**：`database/migrations/2026_11_XX_000001_add_site_id_to_geo_diagnosis_runs_table.php`（新建）

```php
Schema::table('geo_diagnosis_runs', function (Blueprint $table) {
    $table->unsignedBigInteger('site_id')->nullable();  // PG 不支持 ->after()，不写
    $table->index(['site_id', 'created_at']);
    $table->index(['site_id', 'status']);
});
```

现有记录 site_id=NULL（遗留全局），**不回填**；租户视图永远看不到它们，超管全局视图可见。`GeoDiagnosisRun` Model 的 `$fillable` 补 `site_id`。

#### 2.2 personal_access_tokens 加 site_id

**文件**：`database/migrations/2026_11_XX_000002_add_site_id_to_personal_access_tokens_table.php`（新建）

```php
Schema::table('personal_access_tokens', function (Blueprint $table) {
    $table->unsignedBigInteger('site_id')->nullable();
    $table->index('site_id');
});
```

存量 token site_id=NULL，走 Header 过渡通道；换发后绑定。

#### 2.3 已有表确认（无需迁移）

以下表已在 MVP 阶段建好 site_id 列与索引（已逐一核对迁移文件）：

| 表 | site_id 列 | 索引 |
|---|---|---|
| `geo_diagnosis_settings` | ✅ nullable, unique | ✅ |
| `brand_platforms` | ✅ nullable | ✅ |
| `brand_keywords` | ✅ nullable | ✅ composite unique |
| `brand_monitor_results` | ✅ nullable | ✅ |
| `brand_competitors` | ✅ nullable | ✅ composite unique |
| `brand_activity_log` | ✅ nullable | ✅ |

**可选加固（P2，Phase 0 已确认不纳入本期）**：PG 下 `unique(site_id, keyword)` 对 NULL 互不相等，全局关键词理论上可重复插入。PG15+ 可用 `UNIQUE NULLS NOT DISTINCT` 收口（Laravel Blueprint 不直接支持，需 migration 内 `DB::statement` 重建约束，约束名以实测为准）。应用层 `updateOrCreate` 已兜底，此项不阻塞 MVP。重访触发条件：审计发现全局桶重复关键词，或路线 C 使全局桶成为长期一等模式。

### Phase 3：API 面暴露 + 控制器改造（2–3 天）

**目标**：把诊断/品牌监测能力暴露到 token 鉴权 API 面（v1.1 完全遗漏的工作），并接通租户上下文。

#### 3.1 新增 API 路由

**文件**：`routes/api.php`，在现有 `Route::middleware(['api.auth', 'api.recovery'])` 组内追加：

```php
use App\Http\Controllers\Api\V1\ArticleGeoDiagnosisController;
use App\Http\Controllers\Api\V1\BrandMonitorApiController;
use App\Http\Controllers\Api\V1\GeoDiagnosisDashboardController;

// GEO 诊断 / 品牌监测（租户强制边界）
Route::middleware(['api.tenant'])->group(function (): void {
    Route::prefix('articles/{articleId}/geo-diagnosis')
        ->whereNumber('articleId')
        ->name('api.v1.article-diagnosis.')
        ->group(function (): void {
            Route::post('run', [ArticleGeoDiagnosisController::class, 'run'])
                ->middleware(['api.scope:geo-diagnosis:write', 'throttle:30,1'])->name('run');
            Route::get('latest', [ArticleGeoDiagnosisController::class, 'latest'])
                ->middleware(['api.scope:geo-diagnosis:read', 'throttle:120,1'])->name('latest');
            Route::get('history', [ArticleGeoDiagnosisController::class, 'history'])
                ->middleware(['api.scope:geo-diagnosis:read', 'throttle:120,1'])->name('history');
        });

    Route::get('geo-diagnosis/dashboard', [GeoDiagnosisDashboardController::class, 'dashboard'])
        ->middleware(['api.scope:geo-diagnosis:read', 'throttle:120,1'])
        ->name('api.v1.geo-diagnosis.dashboard');

    Route::prefix('brand-monitor')->name('api.v1.brand-monitor.')
        ->middleware('api.scope:brand-monitor:read')
        ->group(function (): void {
            Route::get('dashboard', [BrandMonitorApiController::class, 'dashboard'])->middleware('throttle:120,1');
            Route::get('visibility', [BrandMonitorApiController::class, 'visibility'])->middleware('throttle:120,1');
            Route::get('sources', [BrandMonitorApiController::class, 'sources'])->middleware('throttle:120,1');
            Route::get('sentiment', [BrandMonitorApiController::class, 'sentiment'])->middleware('throttle:120,1');
            Route::get('keywords', [BrandMonitorApiController::class, 'keywords'])->middleware('throttle:120,1');
            Route::get('platforms', [BrandMonitorApiController::class, 'platforms'])->middleware('throttle:120,1');
            Route::post('keywords', [BrandMonitorApiController::class, 'addKeyword'])
                ->middleware(['api.scope:brand-monitor:write', 'throttle:30,1']);
            Route::delete('keywords/{id}', [BrandMonitorApiController::class, 'removeKeyword'])
                ->whereNumber('id')
                ->middleware(['api.scope:brand-monitor:write', 'throttle:30,1']);
        });
});
```

> 路由命名、throttle 档位与现有 api.php 风格一致。`api.tenant` 组内所有端点 siteId 恒非 null。诊断设置（settings）的读写 MVP 仅留在 admin web 面，API 面暂不暴露（租户级设置经 bridge 的需求出现后再加）。

#### 3.2 新建 Api/V1 控制器（复用 Service，不复制逻辑）

**文件**（均新建，`use ResolvesTenant`，继承 `Api/V1/BaseApiController`）：

- `app/Http/Controllers/Api/V1/ArticleGeoDiagnosisController.php`
- `app/Http/Controllers/Api/V1/GeoDiagnosisDashboardController.php`
- `app/Http/Controllers/Api/V1/BrandMonitorApiController.php`

**示例：ArticleGeoDiagnosisController**（按 orchestrator 真实签名 `runDiagnosis(Article, ?int, array)` 编写）：

```php
public function run(Request $request, int $articleId): JsonResponse
{
    $article = Article::query()->whereKey($articleId)->firstOrFail();
    $siteId = $this->tenantId($request);  // api.tenant 保证非 null

    // 运行中检查也要按 site 隔离，否则别的租户在跑会阻塞本租户
    $running = GeoDiagnosisRun::query()
        ->where('article_id', $article->id)
        ->where('site_id', $siteId)
        ->where('status', GeoDiagnosisRun::STATUS_RUNNING)
        ->exists();
    if ($running) {
        return response()->json(['error' => [
            'code' => 'diagnosis_running',
            'message' => '该文章已有诊断任务在运行中，请等待完成',
        ]], 409);
    }

    $run = $this->orchestrator->runDiagnosis($article, $this->auth($request)->auditAdminId, [
        'site_id' => $siteId,
    ]);

    return response()->json(['data' => [/* run 序列化，与 Admin 面 run() 输出结构一致 */]], 201);
}

public function latest(Request $request, int $articleId): JsonResponse
{
    $article = Article::query()->whereKey($articleId)->firstOrFail();
    $run = $this->orchestrator->getLatestDiagnosis($article, $this->tenantId($request));
    // null → {data: null, meta: ...}，与 Admin 面口径一致
    // ...
}
```

**BrandMonitorApiController** 各方法统一模式：`$siteId = $this->tenantId($request)` → 透传给已有的 orchestrator/KeywordManager（它们已支持 siteId 参数，见 Phase 4）。`removeKeyword` 需先校验关键词归属本 site 再删（防跨租户删除，KeywordManager 增加带 siteId 的归属校验，见 4.2）。

#### 3.3 Admin web 面控制器（MVP 最小改动）

`routes/web.php` 的诊断/品牌监测路由与控制器 **MVP 保持现状**（超管全局视图）。仅两处小改：

- `Admin/ArticleDiagnosisController::run()`：现有 `$request->input('site_id')` 接线保留，orchestrator 落库后（Phase 4.1）该 input 才真正生效；超管 UI 可选传，不传为全局。`latest()`/`history()` 调用点随 orchestrator 新签名补传参（不传 = null = 全局，行为不变）。
- `Admin/BrandMonitorController::refreshVisibility()`：现状直接 `new BrandVisibilityService()` 且无 site 参数，改为注入 orchestrator 统一入口（顺带消除绕过容器的坏味道），全局刷新语义不变。

超管按站点筛选视图放 Phase 6。

### Phase 4：Service 层与 Job 层改造（1.5–2 天）

**目标**：诊断记录落 site_id；查询按租户过滤；修复定时任务结果错标。

#### 4.1 GeoDiagnosisOrchestrator

**文件**：`app/Services/GeoFlow/GeoDiagnosis/GeoDiagnosisOrchestrator.php`

真实签名是 `runDiagnosis(Article $article, ?int $adminId = null, array $context = [])`（v1.1 样例签名错误，已按实际重写）：

```php
public function runDiagnosis(Article $article, ?int $adminId = null, array $context = []): GeoDiagnosisRun
{
    $siteId = isset($context['site_id']) && $context['site_id'] !== null
        ? (int) $context['site_id'] : null;

    $run = GeoDiagnosisRun::create([
        'article_id' => $article->id,
        'site_id' => $siteId,          // ← 新增落库（Phase 2.1 加的列）
        'admin_id' => $adminId,
        'status' => GeoDiagnosisRun::STATUS_RUNNING,
        'started_at' => now(),
    ]);
    // ... 其余不变（getForSite($context['site_id'] ?? null) 接线已存在）
}

/** 新增 $siteId 参数；null = 全局（admin web 面），非 null 严格过滤（API 面） */
public function getLatestDiagnosis(Article $article, ?int $siteId = null): ?GeoDiagnosisRun
{
    return $article->geoDiagnosisRuns()
        ->completed()
        ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
        ->latestFirst()
        ->first();
}

public function getDiagnosisHistory(Article $article, int $limit = 10, ?int $siteId = null): Collection
{
    return $article->geoDiagnosisRuns()
        ->completed()
        ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
        ->latestFirst()
        ->limit($limit)
        ->get();
}

// getDashboardData(?int $siteId = null) 签名已存在；
// 内部所有查询补 ->when($siteId !== null, fn ($q) => $q->where('site_id', $siteId))
```

> 注意：`getLatestDiagnosis` 现状走 `$article->latestGeoDiagnosisRun` 关联（无过滤），改为查询构造器。`GeoDiagnosisRun` Model 的 `$fillable` 补 `site_id`。

#### 4.2 KeywordManager / BrandMonitorOrchestrator

**已支持 siteId 的方法不动**（`getKeywords`、`addKeyword`、`addKeywords`、`runFullMonitor`、`getVisibilityData`、`getSourceAnalysis`、`getSentimentAnalysis` 均已有 `?int $siteId` 参数）。补齐：

- `KeywordManager::removeKeyword(int $keywordId, ?int $siteId = null)` / `toggleKeyword(...)`：非 null 时先校验归属（`where('site_id', $siteId)` 查不到即抛 not found），防跨租户删改。
- `BrandMonitorOrchestrator::getEnabledAdapters(?int $siteId)` 及信源/舆情刷新中的平台选择：按 Phase 0 规则配对——`site_id IS NULL` 的平台全局共享，非 NULL 平台仅配同 site 关键词。

#### 4.3 Job 层：修复监测结果错标（v1.1 遗漏，P0）

**文件**：`app/Services/GeoFlow/BrandMonitor/BrandVisibilityService.php`（信源/舆情两个 Service 同步检查修改）

`refreshVisibility()` 写结果处：

```php
BrandMonitorResult::create([
    'site_id' => $keyword->site_id,   // ← 归属以关键词自身为准，不再用传入参数
    'keyword_id' => $keyword->id,
    'platform_id' => $platform->id,
    // ...
]);
```

传入的 `$siteId` 参数仅用于**选择**关键词/平台范围，不再决定结果归属。这样：

- 每小时全量 job（siteId=null）产出的结果自动正确归属各关键词的租户；
- 按 site 扇出的 job（若未来启用）同样正确。

**调度**：`routes/console.php` 的 `Schedule::job(new RunBrandMonitorJob())->hourly()` MVP 保持单 job 全量（错标修复后归属已正确）；按 site 扇出调度列为后续优化，不阻塞。

**其余 job 核查**：`RunGeoDiagnosisJob` 的 payload 必须携带 site_id 并透传到 `runDiagnosis` 的 context；`CleanupOldDiagnosisJob` 按保留期全局清理，无需租户化（清理不泄漏数据）。

### Phase 5：niucloud Bridge 改造（1.5–2 天）

**目标**：df_geo_bridge 调用 GEOFlow API 时传入 X-Site-Id，并交付诊断/品牌监测的完整调用链。

#### 5.1 GeoflowApiService：siteId 必填，杜绝静默降级

**文件**：`niucloud/addon/df_geo_bridge/app/service/GeoflowApiService.php`

```php
private int $siteId;

/**
 * @param int $siteId niucloud 站点 ID，必须 > 0（去掉默认值，忘传直接报错）
 */
public function __construct(string $baseUrl, string $token, int $siteId)
{
    if ($siteId <= 0) {
        throw new \InvalidArgumentException('GeoflowApiService 需要有效的 site_id（>0）');
    }
    $this->siteId = $siteId;
    // ... 现有 Client 配置，headers 增加：
    //   'X-Site-Id' => (string) $this->siteId,
}

public static function fromSiteId(int $siteId): ?self
{
    $config = DfGeoBridgeConfig::getBySiteId($siteId);
    if (empty($config) || empty($config['api_base_url']) || empty($config['api_token'])) {
        return null;
    }
    return new self($config['api_base_url'], $config['api_token'], $siteId);
}
```

**所有构造点排查**：`fromSiteId` 之外若有直接 `new self(baseUrl, token)` 的调用（如配置页连接测试），一律补传当前会话 site_id。静态 `login()` 不涉及实例，不改。文章同步链路（`ArticleSyncService` 等）经 `fromSiteId` 自动带上 Header；文章端点不在 `api.tenant` 组内，多传 Header 无副作用，向后兼容。

**Phase 0 决议派生改动（与本节同批交付）**：① 构造函数与 `login()` 的 Guzzle headers 中删除硬编码 `'Host' => 'localhost'`（公网 HTTPS 下命中错误 vhost）；② `ConfigService::saveConfig` 增加 `api_base_url` scheme 校验（https 强制，http 仅放行开发回环）；③ install.sql 注释与 zh-cn/en dict 示例文案改 `https://`。

#### 5.2 新增诊断/品牌监测调用方法

在 `GeoflowApiService` 中新增（base_uri 已含 `/api/v1`，路径不再带前缀；错误处理沿用现有 `['error' => ...]` 风格）：

```php
public function runDiagnosis(int $articleId): array
{
    // POST /articles/{$articleId}/geo-diagnosis/run
    // 200/201 → $body['data']；409 → 诊断运行中；422/403 → 租户上下文错误（提示检查 token 绑定）
}

public function getLatestDiagnosis(int $articleId): array
{
    // GET /articles/{$articleId}/geo-diagnosis/latest
    // 200 → $body['data']（可能为 null = 尚未诊断）
}

public function getDiagnosisHistory(int $articleId, int $perPage = 10): array
{
    // GET /articles/{$articleId}/geo-diagnosis/history
}

public function getGeoDiagnosisDashboard(): array
{
    // GET /geo-diagnosis/dashboard
}

public function getBrandMonitorDashboard(): array
{
    // GET /brand-monitor/dashboard
}

public function getBrandKeywords(): array
{
    // GET /brand-monitor/keywords
}
```

#### 5.3 bridge 调用方：控制器 + 前端入口（v1.1 遗漏）

Service 方法没有调用方等于没交付。新增：

- **后端**：`addon/df_geo_bridge/app/adminapi/controller/GeoDiagnosis.php`（继承 BaseAdminController，`$this->request->siteId()` → `GeoflowApiService::fromSiteId()` → 对应方法），路由按插件现有 adminapi 路由注册方式追加；文章映射 ID 转换复用 `df_geo_bridge_article_mapping`（本地 article_id → GEOFlow article_id）。
- **前端**：插件前端新增诊断 api 模块与页面入口（文章列表行操作"GEO 诊断" + 诊断结果抽屉/页；品牌监测概览页）。目录按 df_geo_bridge 现有前端结构，文案走 `t()`，改完跑对应 vue-tsc 闸门 + `build-addon`。
- **运营配套**：插件设置页提示"请先在 GEOFlow 后台为该站点创建绑定 site_id 的 Token"；连接测试（testConnection）增加租户上下文探测（调一次 dashboard，422/403 时给出明确指引）。

### Phase 6：Admin 面板适配（可选，MVP 后）

**目标**：GEOFlow 管理后台支持按站点查看数据。

保持 v1.1 结论：MVP 阶段 GEOFlow 后台仅供超管看全局聚合。后续二选一：

- **方案 A**：全局聚合 + 顶部站点下拉筛选（超管运营视角，改动小，复用 `ResolvesTenant` + query 参数）。
- **方案 B**：`admins` 表加 site_id，站点管理员直登 GEOFlow 后台只看本站（改动大，涉及登录/权限全链路）。

届时 `brand_activity_log` 等写入口同步补 site_id。

---

## 4. 文件变更清单（v1.2 按实际代码重写）

### GEOFlow 侧

| 文件 | 操作 | 说明 |
|---|---|---|
| `app/Http/ApiAuthContext.php` | 修改 | 增加 `?int $siteId = null` |
| `app/Http/Middleware/ResolveTenant.php` | **新建** | fail-closed 租户解析（绑定优先/不匹配 403/缺失 422） |
| `app/Http/Controllers/Concerns/ResolvesTenant.php` | **新建** | tenantId() trait |
| `bootstrap/app.php` | 修改 | 注册 `api.tenant` 别名 |
| `routes/api.php` | 修改 | 新增诊断/品牌监测 API 路由组（api.tenant + scope） |
| `app/Http/Controllers/Api/V1/ArticleGeoDiagnosisController.php` | **新建** | run/latest/history |
| `app/Http/Controllers/Api/V1/GeoDiagnosisDashboardController.php` | **新建** | dashboard |
| `app/Http/Controllers/Api/V1/BrandMonitorApiController.php` | **新建** | 品牌监测读 + 关键词写 |
| `app/Services/Api/ApiTokenService.php` | 修改 | createToken 绑定 site_id；hydrate 输出；注册新 scope |
| `app/Http/Controllers/Admin/ApiTokenController.php` | 修改 | store/list 支持 site_id |
| `app/Services/GeoFlow/GeoDiagnosis/GeoDiagnosisOrchestrator.php` | 修改 | run 落 site_id；latest/history/dashboard 过滤 |
| `app/Models/GeoDiagnosisRun.php` | 修改 | `$fillable` 补 site_id |
| `app/Services/GeoFlow/BrandMonitor/KeywordManager.php` | 修改 | remove/toggle 归属校验 |
| `app/Services/GeoFlow/BrandMonitor/BrandMonitorOrchestrator.php` | 修改 | 平台-关键词按 site 配对 |
| `app/Services/GeoFlow/BrandMonitor/BrandVisibilityService.php` | 修改 | **结果 site_id 改从关键词归属回填** |
| `app/Services/GeoFlow/BrandMonitor/BrandSourceService.php` | 修改 | 同上核查 |
| `app/Services/GeoFlow/BrandMonitor/BrandSentimentService.php` | 修改 | 同上核查 |
| `app/Jobs/RunGeoDiagnosisJob.php` | 修改 | payload 携带 site_id |
| `app/Http/Controllers/Admin/ArticleDiagnosisController.php` | 修改 | latest/history 调用点补传参（默认 null 不变行为） |
| `app/Http/Controllers/Admin/BrandMonitorController.php` | 修改 | refreshVisibility 走 orchestrator 注入 |
| `database/migrations/..._add_site_id_to_geo_diagnosis_runs_table.php` | **新建** | 加列 + 索引（PG 无 ->after） |
| `database/migrations/..._add_site_id_to_personal_access_tokens_table.php` | **新建** | 加列 + 索引 |
| Token 管理页 Blade/前端 | 修改 | 绑定站点字段 |

### niucloud 侧（df_geo_bridge）

| 文件 | 操作 | 说明 |
|---|---|---|
| `addon/df_geo_bridge/app/service/GeoflowApiService.php` | 修改 | siteId 必填 + X-Site-Id Header + 诊断/品牌监测方法 |
| `addon/df_geo_bridge/app/adminapi/controller/GeoDiagnosis.php` | **新建** | 诊断/品牌监测调用方 |
| 插件 adminapi 路由文件 | 修改 | 追加路由 |
| 插件前端 api 模块 + 诊断页面 | **新建** | 文章行操作 + 结果展示 + 品牌监测概览 |
| 插件设置页 | 修改 | token 绑定指引 + 连接测试租户探测 |
| 直接 `new GeoflowApiService(...)` 的存量调用点 | 修改 | 补传 siteId（全目录排查） |

---

## 5. 测试计划（v1.2 扩充）

### 5.1 单元测试

- [ ] `ResolveTenant` 矩阵：绑定+Header 一致 → 注入；绑定+Header 缺失 → 用绑定值；绑定+Header 不匹配 → 403；未绑定+Header 有效 → 注入；未绑定+Header 缺失/0/非数字 → 422；无 api_auth → 401
- [ ] `ApiTokenService::createToken(siteId: N)` → token 记录落 site_id；hydrate 输出含 site_id
- [ ] `GeoDiagnosisOrchestrator::runDiagnosis` context 带 site_id → run 落库；getLatestDiagnosis/getDiagnosisHistory 按 site 过滤，null 时全局
- [ ] `KeywordManager::removeKeyword($id, $siteId)` 跨租户删除 → not found
- [ ] `BrandVisibilityService::refreshVisibility(null)` → 结果 site_id = 关键词自身归属（**错标修复回归**）
- [ ] 平台配对：租户关键词不跑其他租户私有平台；NULL 平台全局共享

### 5.2 集成测试

- [ ] bridge（绑定 token）调诊断 API → run 落正确 site_id；latest/history 只返回本 site
- [ ] 两个 site 对同一 article 分别诊断 → 互不可见；运行中检查互不阻塞
- [ ] 未绑定 token + 忘传 Header → 422（**fail-closed，绝不返回全局数据**）
- [ ] 伪造 X-Site-Id（与绑定值不符）→ 403
- [ ] scope 缺失 → 403（api.scope 闸门）
- [ ] 现有文章同步链路回归：不传/多传 X-Site-Id 均不影响 /articles 端点
- [ ] Admin web 面回归：超管 session 访问诊断/品牌监测页，全局聚合行为不变

### 5.3 端到端测试

- [ ] niucloud 站点 A 文章列表触发诊断 → GEOFlow 创建 site_id=A 的 run → A 看到结果
- [ ] 站点 B 查同一文章 → 空（未诊断过），触发后只见 B 的结果
- [ ] 站点 A 配置品牌关键词 → 定时任务跑一轮 → 结果归属 A；站点 B Dashboard 看不到 A 的关键词与结果
- [ ] 站点 A 调整诊断权重 → 站点 B 权重不变（getForSite 按 site 单例）

---

## 6. 后续演进（MVP 之后）

1. **存量 token 换发**：运营推动各站点换发绑定 site_id 的 token，全部换发后可考虑关闭未绑定 token 的 Header 过渡通道（配置开关）。
2. **知识库隔离**：`knowledge_bases` 加 site_id，全局共享 + 站点私有混合模式。
3. **文章归属**：`articles` 加 `tenant_id`（不叫 site_id，避免与 HostedSite 混淆），诊断/品牌监测自动关联。
4. **Admin 用户多租户**：Phase 6 方案 B，admins 绑定 site_id + 超管全局。
5. **品牌监测按 site 扇出调度**：租户量大后替代单 job 全量，隔离失败影响面。
6. **路线 C 演进**：如 GEOFlow 未来服务多个 niucloud 平台，再建 tenants 表做平台级映射。

---

## 7. 风险与缓解（v1.2 更新）

| 风险 | 影响 | 缓解措施 |
|---|---|---|
| Header 伪造 | 跨租户读数据 | **MVP 即关**：token 绑定 site_id，不匹配 403；未绑定 token 过渡期要求 Header 且 fail-closed |
| 忘传租户标识 | 静默拿到全局数据 / 写入 NULL 归属 | API 面 422 拒绝；bridge siteId 必填无默认值；job 结果从关键词归属回填 |
| 公网传输 | Token/Header 被嗅探 | Phase 0 已确认按公网对待：bridge 强制 HTTPS 校验 + 删除 Host:localhost 硬编码 + 存量地址只读盘点 |
| 遗留 NULL 数据 | 租户视图看不到旧记录 | 明确不回填；仅超管全局视图可见；文档告知 |
| 定时任务跨租户配对 | 租户 A 关键词跑 B 的平台配置 | 平台 NULL=共享、非 NULL=同 site 配对规则 + 单测覆盖 |
| 新 API 面扩大攻击面 | 诊断/品牌监测端点暴露到公网 | scope 最小授权 + throttle + api.recovery 闸门复用现有体系 |
| 性能 | 查询增加 site_id 条件 | 相关列均已建索引，影响可忽略 |

---

## 8. 实施顺序与工期（v1.2 重估）

| 阶段 | 内容 | 工期 |
|---|---|---|
| Phase 0 | 拓扑核实 + 规则确认 | 0.5 天 |
| Phase 1 | Tenant Context + token 绑定 | 1–1.5 天 |
| Phase 2 | 数据库迁移 | 0.5 天 |
| Phase 3 | API 面暴露 + 控制器 | 2–3 天 |
| Phase 4 | Service + Job 层 | 1.5–2 天 |
| Phase 5 | bridge 改造 + 调用方 + 前端 | 1.5–2 天 |
| 测试 | 单测/集成/E2E | 1.5–2 天 |
| Phase 6 | Admin 面板租户视图 | 推迟 |

**总计：8–12 天**（v1.1 估 5–8 天，未含 API 面暴露、job 层修复、bridge 调用方三块漏项）。

---

## 9. 验收标准

- [ ] niucloud 站点 A / B 的诊断报告、品牌监测数据互相不可见（E2E 实证）
- [ ] 定时监测任务产出的结果归属正确（关键词 site_id 回填，回归测试通过）
- [ ] API 面 fail-closed：无租户上下文 422、Header 与绑定不符 403，任何路径不降级全局
- [ ] 现有文章同步与 GEOFlow 后台全局视图不受影响（回归通过）
- [ ] 所有新增/修改代码通过 PHP 语法检查 + 项目静态分析闸门；bridge 前端过 vue-tsc + build-addon
- [ ] 测试计划 5.1–5.3 全部勾完

---

## 附录 A：v1.1 → v1.2 修订记录

| # | v1.1 问题 | v1.2 修订 | 核实依据 |
|---|---|---|---|
| 1 | 假设诊断/品牌监测端点在 Bearer API 面，bridge 可直接调 | 实际全部在 `routes/web.php` admin session 组；新增 Phase 3.1 API 面暴露（路由 + 3 个 Api/V1 控制器 + scope） | `routes/web.php:490-563`；`routes/api.php` 无 diagnosis/brand 端点；`AuthenticateAdminWeb` 纯 session |
| 2 | `tenant()` 放 `Api/V1/BaseApiController`，但 Admin 控制器继承 `Controller` | 改为 `ResolvesTenant` trait，两面通用 | `Admin/ArticleDiagnosisController` 等 `extends Controller` |
| 3 | Phase 3/4 代码样例签名与真实代码不符 | 按真实签名重写：`run(Request, int $articleId)`、`runDiagnosis(Article, ?int, array)`、`getLatestDiagnosis(Article)` 等 | 控制器/orchestrator 源码逐行核对 |
| 4 | 称"Service 层不传递 site_id" | 实际 KeywordManager/Orchestrator/Job 已全链路支持；缺口在入口传参、run 落库、job 错标 | `KeywordManager.php:22-82`、`BrandMonitorOrchestrator.php:41-180`、`RunBrandMonitorJob.php` |
| 5 | 完全遗漏定时任务数据错标 | 新增 Phase 4.3：结果 site_id 从关键词归属回填；平台-关键词配对规则 | `BrandVisibilityService.php:114` `'site_id' => $siteId`；`routes/console.php:222` hourly 全量 job |
| 6 | null 语义两套口径（控制器 where vs Service when），忘传 Header 静默升权 | §2.3 统一表：API 面 fail-closed 恒非 null；web 面 null=超管全局；遗留 NULL 不回填 | Laravel `where(col, null)` 转 whereNull 的行为差异 |
| 7 | Header 伪造推迟到 MVP 后加固，前提是"同内网" | Token 绑定提前进 MVP（A+ 路线）；GEOFlow 生产在公网，前提不成立 | `df_geo_bridge_config` 每站点独立 token；`ApiTokenService::createToken` 已有 scopes；生产部署 geo.jiakeyuan.net |
| 8 | bridge `int $siteId = 0` 默认值 → Header "0" → 静默降级全局 | siteId 必填无默认值，<=0 抛异常；排查所有构造点 | `GeoflowApiService.php:34-61` 现状 |
| 9 | Phase 5 只加 Service 方法，无调用方 | 新增 bridge adminapi 控制器 + 前端入口 + 设置页指引 | df_geo_bridge 全目录无 diagnosis/brand 调用 |
| 10 | 迁移用 `->after('id')`（MySQL 专用）；注册写"bootstrap/app.php 或 Kernel.php" | PG 不写 after；Laravel 12 只有 bootstrap/app.php | GEOFlow 为 Laravel 12 + PostgreSQL |
| 11 | 工期 5–8 天 | 重估 8–12 天（补三块漏项） | — |
| 12 | 测试计划缺 job 归属、fail-closed、伪造 Header、跨租户删除 | 5.1–5.3 扩充对应条目 | — |

**遗留待确认项（Phase 0）**：~~① 各生产站 api_base_url 的网络拓扑与 HTTPS；② 品牌平台"NULL=全局共享"配对规则是否符合运营预期；③ PG `NULLS NOT DISTINCT` 加固是否纳入本期~~ —— **2026-11-07 三项均已确认**：① 按公网对待，HTTPS 强制校验 + 删 Host:localhost 硬编码 + 存量只读盘点进本期（见 Phase 0 / Phase 5.1）；② NULL=全局共享规则采纳，Phase 4.2 守卫落地；③ 不纳入本期（P2），设重访触发条件。Phase 0 无阻塞项，可按 Phase 1 开工。