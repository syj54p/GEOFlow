# GEO 诊断第一期（MVP）需求清单

> 版本：v2.0 | 日期：2026-11-01 | 状态：草稿
> 关联项目：GEOFlow 3.2+ | 优先级：P1
> 关联原型：[geo-diagnosis-prototype.html](./geo-diagnosis-prototype.html)

---

## 一、产品定位

**GEO 诊断**是 GEOFlow 后台的一站式 GEO 运营中心，采用**「内外结合」**的差异化设计：

- **内部 — 内容级 GEO 评分**（GEOFlow 独有优势）：基于 GEOFlow 知识库→文章生成→质量门禁→渠道分发的完整链路，对每篇文章进行四维 GEO 就绪度评分（内容结构 / 知识对齐 / 技术 GEO / AI 可见度），输出可操作的改进建议。
- **外部 — 品牌 AI 可见度监测**（轻量互补）：监控品牌在主流 AI 平台（豆包 / Kimi / 通义千问 / 文心一言等）的搜索结果排名、信源引用占比和舆情评价，及时发现竞品动态。

**竞品差异：** adminingeo 等竞品只做外部品牌监测（需安装 EXE 客户端），不做内容级诊断。GEOFlow 利用已有的内容生产管线数据，将内部优化与外部监测闭环。

**MVP 边界：** 诊断概览仪表盘 + 品牌监测（3 子模块） + 文章级诊断 + 诊断设置。全站批量扫描、AI 引用归因追踪、诊断报告 PDF 导出留第二期。

---

## 二、用户故事

| 编号 | 角色 | 故事 | 验收标准 |
|------|------|------|----------|
| US-01 | 内容运营 | 在诊断概览页一眼看到全站 GEO 健康度 | 显示文章均分、品牌可见度、待优化文章数、监控关键词数 4 个指标卡 |
| US-02 | 内容运营 | 在概览页看到最近诊断活动和品牌预警 | 活动列表含诊断完成、品牌排名变化、竞品预警等事件 |
| US-03 | 内容运营 | 在品牌监测页查看品牌在各 AI 平台的搜索排名 | 表格展示每个关键词在各平台的排名及趋势 |
| US-04 | 内容运营 | 管理监控关键词和平台 | 可增删关键词，可选择启用/禁用 AI 平台 |
| US-05 | 内容运营 | 查看 AI 引用信源分析 | 显示品牌引用占比、高频引用内容 TOP 5、竞品信源追踪 |
| US-06 | 内容运营 | 查看 AI 舆情评价 | 显示正面/中性/负面评价比例和近期摘要 |
| US-07 | 内容运营 | 在文章详情页看到 GEO 诊断评分卡 | cat 评分卡显示 0-100 分，分 4 个维度 |
| US-08 | 内容运营 | 点击"运行诊断"后 30 秒内出结果 | 异步任务，进度条动画 + 分步提示展示 |
| US-09 | 内容运营 | 诊断结果包含可操作的改进建议 | 每条建议有"问题描述 + 影响维度 + 修复指引" |
| US-10 | 内容运营 | 诊断历史可追溯 | 保留最近 10 次诊断记录，可对比分数变化 |
| US-11 | 超级管理员 | 可配置诊断检查项的权重 | 设置页可调整各维度权重（总和 100%）和检查项开关 |

---

## 三、功能模块

### 3.1 诊断概览仪表盘（NEW）

**位置：** 侧边栏 → GEO 诊断 → 诊断概览

**功能：**
- 4 个指标卡：文章平均 GEO 分、品牌 AI 可见度、待优化文章数、监控关键词数
- 最近诊断活动列表（混合展示诊断完成、品牌排名变化、竞品预警等事件）
- 品牌 AI 可见度快照（各平台可见度百分比）

**数据来源：**
- 文章均分：`geo_diagnosis_runs` 表聚合
- 品牌可见度：`brand_monitor_results` 表聚合
- 待优化文章：`articles` 表 + 最近诊断分数 < 60 筛选
- 监控关键词：`brand_keywords` 表计数

### 3.2 品牌监测（NEW）

**位置：** 侧边栏 → GEO 诊断 → 品牌监测

**三个子标签页：**

#### 3.2.1 AI 可见性

- **平台选择器**：卡片式展示 6 个 AI 平台（豆包 / Kimi / 通义千问 / 文心一言 / Google AI Overview / ChatGPT），已绑定的显示检测频率
- **关键词管理**：标签式展示，支持增删
- **可见性结果表**：每行一个关键词，列含各平台排名 + 综合排名 + 趋势箭头

#### 3.2.2 信源检测

- **品牌引用占比**：进度条可视化（本站 vs 竞品 vs 其他）
- **高频引用内容 TOP 5**：列出被 AI 引用最多的本站文章
- **竞品信源追踪**：卡片式展示竞品的引用占比和 7 天变化

#### 3.2.3 舆情评价

- **情感分布**：正面/中性/负面评价百分比（3 个指标卡）
- **近期评价摘要**：按时间倒序展示 AI 平台的评价原文片段
- **负面评价行动建议**：对负面评价提供可操作的修复提示

### 3.3 文章列表（增强）

**位置：** 侧边栏 → 内容 → 文章

**增强点：**
- 文章列表新增「GEO 评分」列，显示最近一次诊断分数（带颜色标签）
- 点击文章行进入详情页可查看诊断结果

### 3.4 诊断评分卡（核心，保留）

**位置：** 文章详情页 → 右侧栏

**评分维度（4 项，满分 100）：**

| 维度 | 权重（默认） | 检查项 | 数据来源 |
|------|-------------|--------|----------|
| **内容结构** | 25% | 标题层级完整性、段落长度合理性、FAQ/定义性语句存在性、列表/表格使用 | Article body 解析 |
| **知识对齐** | 30% | 知识库事实覆盖率、关键术语出现频次、与知识库矛盾检测 | KnowledgeBase + Article 对比 |
| **技术 GEO** | 20% | llms.txt 可达性、Schema.org 完整性、canonical 链接、robots 策略 | SiteSetting + 前台路由 |
| **AI 可见度** | 25% | 核心关键词 AI 搜索排名、竞品引用占比 | brand_monitor_results 关联 |

**评分等级：**
- 🟢 优秀（80-100）：内容 GEO 就绪，保持更新
- 🟡 良好（60-79）：有改进空间，建议优先处理高影响项
- 🟠 一般（40-59）：多项关键指标不达标，建议系统性优化
- 🔴 较差（0-39）：内容几乎不被 AI 引用，需要重构

### 3.5 改进建议清单（保留）

每条建议包含：

```json
{
  "id": "content_structure_h1_missing",
  "severity": "high",
  "dimension": "content_structure",
  "title": "缺少 H1 标题",
  "description": "文章正文未检测到 H1 标签，AI 引擎难以提取核心主题",
  "impact_score": 8,
  "fix_guide": "在文章开头添加一个 H1 标题，概括文章核心主题",
  "auto_fixable": false
}
```

**MVP 检查项清单（共 12 项）：**

| 编号 | 检查项 | 维度 | 严重度 | 可自动修复 |
|------|--------|------|--------|-----------|
| CS-01 | H1 标题存在且唯一 | 内容结构 | high | ❌ |
| CS-02 | 标题层级无跳跃（H1→H2→H3） | 内容结构 | medium | ❌ |
| CS-03 | 段落平均长度 ≤ 200 字 | 内容结构 | low | ❌ |
| CS-04 | 包含 FAQ 或定义性语句 | 内容结构 | medium | ❌ |
| KA-01 | 知识库关键事实覆盖率 ≥ 60% | 知识对齐 | high | ❌ |
| KA-02 | 核心术语出现频次 ≥ 3 次 | 知识对齐 | medium | ❌ |
| KA-03 | 无知识库矛盾内容 | 知识对齐 | high | ❌ |
| TG-01 | llms.txt 可达且包含本站点 | 技术 GEO | high | ✅ |
| TG-02 | Schema.org JSON-LD 完整 | 技术 GEO | medium | ✅ |
| TG-03 | canonical 链接正确 | 技术 GEO | medium | ✅ |
| AV-01 | 至少 1 个关键词进入 AI 搜索前 10 | AI 可见度 | high | ❌ |
| AV-02 | 竞品引用占比 ≤ 50% | AI 可见度 | medium | ❌ |

### 3.6 诊断历史（保留）

- 每次诊断生成一条 `geo_diagnosis_runs` 记录
- 保留最近 10 次，超期自动清理
- 可对比两次诊断的评分变化（delta 标签）

### 3.7 诊断设置（保留）

**位置：** 侧边栏 → GEO 诊断 → 诊断设置

- 维度权重滑块（4 个维度，总和必须 100%）
- 检查项开关列表（12 项，可 individually 启用/禁用）

---

## 四、数据模型

### 4.1 已有表（保留）

#### `geo_diagnosis_runs`

```sql
CREATE TABLE geo_diagnosis_runs (
    id BIGSERIAL PRIMARY KEY,
    article_id BIGINT NOT NULL REFERENCES articles(id) ON DELETE CASCADE,
    admin_id BIGINT REFERENCES admins(id) ON DELETE SET NULL,

    -- 评分结果
    total_score INTEGER NOT NULL DEFAULT 0,
    content_structure_score INTEGER NOT NULL DEFAULT 0,
    knowledge_alignment_score INTEGER NOT NULL DEFAULT 0,
    technical_geo_score INTEGER NOT NULL DEFAULT 0,
    ai_visibility_score INTEGER NOT NULL DEFAULT 0,

    -- 诊断元数据
    status VARCHAR(20) NOT NULL DEFAULT 'pending',
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    error_message TEXT,

    -- 检查项结果（JSON）
    findings JSONB NOT NULL DEFAULT '[]',
    recommendations JSONB NOT NULL DEFAULT '[]',

    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_geo_diagnosis_runs_article ON geo_diagnosis_runs(article_id);
CREATE INDEX idx_geo_diagnosis_runs_status ON geo_diagnosis_runs(status);
```

#### `geo_diagnosis_settings`

```sql
CREATE TABLE geo_diagnosis_settings (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT,

    dimension_weights JSONB NOT NULL DEFAULT '{
        "content_structure": 0.25,
        "knowledge_alignment": 0.30,
        "technical_geo": 0.20,
        "ai_visibility": 0.25
    }',

    enabled_checks JSONB NOT NULL DEFAULT '[]',

    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

    UNIQUE(site_id)
);
```

### 4.2 新增表：`brand_platforms`（品牌监测平台配置）

```sql
CREATE TABLE brand_platforms (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT,
    name VARCHAR(50) NOT NULL,         -- 豆包 / Kimi / 通义千问 / 文心一言 / Google AI Overview / ChatGPT
    code VARCHAR(30) NOT NULL UNIQUE,  -- doubao / kimi / qianwen / wenxin / google_ai / chatgpt
    icon_color VARCHAR(20),            -- 前端展示用颜色
    is_enabled BOOLEAN NOT NULL DEFAULT FALSE,
    check_interval_minutes INTEGER NOT NULL DEFAULT 60,  -- 检测频率（分钟）
    api_config JSONB DEFAULT '{}',     -- 平台 API 配置（加密存储）
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_brand_platforms_site ON brand_platforms(site_id);
```

### 4.3 新增表：`brand_keywords`（监控关键词）

```sql
CREATE TABLE brand_keywords (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT,
    keyword VARCHAR(100) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

    UNIQUE(site_id, keyword)
);

CREATE INDEX idx_brand_keywords_site ON brand_keywords(site_id, is_active);
```

### 4.4 新增表：`brand_monitor_results`（品牌监测结果）

```sql
CREATE TABLE brand_monitor_results (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT,
    keyword_id BIGINT REFERENCES brand_keywords(id) ON DELETE CASCADE,
    platform_id BIGINT REFERENCES brand_platforms(id) ON DELETE CASCADE,

    -- 检测结果
    search_rank INTEGER,               -- 搜索排名（NULL 表示未找到）
    visibility_score DECIMAL(5,2),     -- 可见度评分（0-100）
    is_in_top10 BOOLEAN DEFAULT FALSE, -- 是否进入前 10

    -- 信源数据
    source_analysis JSONB DEFAULT '{}',
    -- { brand_quote_pct, competitor_quotes: [{name, pct}], top_cited_articles: [{article_id, cite_count}] }

    -- 舆情数据
    sentiment_analysis JSONB DEFAULT '{}',
    -- { positive_pct, neutral_pct, negative_pct, snippets: [{platform, sentiment, text, detected_at}] }

    -- 元数据
    detected_at TIMESTAMP NOT NULL DEFAULT NOW(),
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_brand_monitor_keyword ON brand_monitor_results(keyword_id, detected_at DESC);
CREATE INDEX idx_brand_monitor_platform ON brand_monitor_results(platform_id, detected_at DESC);
CREATE INDEX idx_brand_monitor_site ON brand_monitor_results(site_id, detected_at DESC);
```

### 4.5 新增表：`brand_competitors`（竞品追踪）

```sql
CREATE TABLE brand_competitors (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT,
    name VARCHAR(100) NOT NULL,
    quote_percentage DECIMAL(5,2) DEFAULT 0,  -- AI 引用占比
    weekly_change DECIMAL(5,2) DEFAULT 0,     -- 7 天变化
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),

    UNIQUE(site_id, name)
);
```

### 4.6 新增表：`brand_activity_log`（活动日志，供概览仪表盘使用）

```sql
CREATE TABLE brand_activity_log (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT,
    activity_type VARCHAR(30) NOT NULL,
    -- diagnosis_completed / brand_rank_change / competitor_alert / sentiment_alert
    title VARCHAR(200) NOT NULL,
    description TEXT,
    related_id BIGINT,            -- 关联 article_id 或 monitor_result_id
    related_type VARCHAR(50),     -- 'article' / 'brand_monitor_result'
    severity VARCHAR(10) DEFAULT 'info',  -- info / warning / alert
    created_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_brand_activity_site ON brand_activity_log(site_id, created_at DESC);
```

---

## 五、API 设计

### 5.1 诊断概览

```
GET /api/admin/geo-diagnosis/dashboard
```

**响应：**
```json
{
  "data": {
    "avg_geo_score": 72,
    "avg_geo_score_delta": 7,
    "brand_visibility_pct": 38,
    "brand_visibility_delta": 5,
    "articles_needing_optimization": 6,
    "low_score_articles": 2,
    "active_keywords": 8,
    "active_platforms": 3,
    "recent_activities": [
      {
        "type": "diagnosis_completed",
        "title": "「2026 年企业 GEO 运营完全指南」诊断完成",
        "description": "72 分 · 良好",
        "score": 72,
        "severity": "info",
        "created_at": "2026-11-01T10:20:00Z"
      },
      {
        "type": "brand_rank_change",
        "title": "品牌监测：豆包「GEO 运营」查询完成",
        "description": "排名第 8 · 较昨日 ↑ 2",
        "severity": "info",
        "created_at": "2026-11-01T07:00:00Z"
      },
      {
        "type": "competitor_alert",
        "title": "预警：竞品「XX 公司」AI 引用占比升至 62%",
        "description": "超过 50% 警戒线",
        "severity": "alert",
        "created_at": "2026-11-01T05:00:00Z"
      }
    ],
    "platform_visibility": [
      { "platform": "doubao", "name": "豆包", "visibility_pct": 42 },
      { "platform": "kimi", "name": "Kimi", "visibility_pct": 28 },
      { "platform": "qianwen", "name": "通义千问", "visibility_pct": 35 },
      { "platform": "wenxin", "name": "文心一言", "visibility_pct": 18 }
    ]
  }
}
```

### 5.2 品牌监测 — AI 可见性

```
GET  /api/admin/brand-monitor/visibility?platform_ids[]=1&platform_ids[]=2&keyword_ids[]=1,2,3
POST /api/admin/brand-monitor/visibility/refresh   -- 手动触发一次检测
```

**响应（GET）：**
```json
{
  "data": [
    {
      "keyword_id": 1,
      "keyword": "GEO 运营",
      "platforms": {
        "doubao": { "rank": 8, "in_top10": true },
        "kimi": { "rank": 15, "in_top10": false },
        "qianwen": { "rank": 6, "in_top10": true }
      },
      "avg_rank": 9.7,
      "trend": 2,
      "trend_direction": "up"
    }
  ]
}
```

### 5.3 品牌监测 — 信源检测

```
GET /api/admin/brand-monitor/sources?period=7d
```

**响应：**
```json
{
  "data": {
    "brand_quote_pct": 35,
    "competitor_quote_pct": 62,
    "other_quote_pct": 3,
    "top_cited_articles": [
      { "article_id": 1, "title": "什么是 GEO？生成式引擎优化完全指南", "cite_count": 28 },
      { "article_id": 5, "title": "2026 年企业 GEO 运营框架", "cite_count": 19 }
    ],
    "competitors": [
      { "name": "竞品 A — XX 公司", "quote_pct": 62, "weekly_change": 8 },
      { "name": "竞品 B — YY 科技", "quote_pct": 21, "weekly_change": -2 }
    ]
  }
}
```

### 5.4 品牌监测 — 舆情评价

```
GET /api/admin/brand-monitor/sentiment?period=7d
```

**响应：**
```json
{
  "data": {
    "positive_pct": 67,
    "neutral_pct": 24,
    "negative_pct": 9,
    "positive_delta": 3,
    "neutral_delta": -1,
    "negative_delta": -2,
    "snippets": [
      {
        "platform": "doubao",
        "sentiment": "positive",
        "query": "GEOFlow 好用吗",
        "text": "GEOFlow 是一款专业的 GEO 内容运营平台...",
        "detected_at": "2026-11-01T08:00:00Z"
      }
    ]
  }
}
```

### 5.5 关键词管理

```
GET    /api/admin/brand-monitor/keywords
POST   /api/admin/brand-monitor/keywords          -- 添加关键词
DELETE /api/admin/brand-monitor/keywords/{id}      -- 删除关键词
```

### 5.6 平台管理

```
GET  /api/admin/brand-monitor/platforms
PUT  /api/admin/brand-monitor/platforms/{id}       -- 启用/禁用平台、修改检测频率
```

### 5.7 文章诊断（保留原有）

```
POST /api/admin/articles/{article}/geo-diagnosis/run
GET  /api/admin/articles/{article}/geo-diagnosis/latest
GET  /api/admin/articles/{article}/geo-diagnosis/history?page=1&per_page=10
```

### 5.8 诊断设置（保留原有）

```
GET /api/admin/geo-diagnosis/settings
PUT /api/admin/geo-diagnosis/settings
```

---

## 六、服务层设计

### 6.1 目录结构

```
app/Services/GeoFlow/
├── GeoDiagnosis/                          # 文章级诊断（已有）
│   ├── GeoDiagnosisOrchestrator.php
│   ├── GeoDiagnosisScorer.php
│   ├── GeoDiagnosisRecommendationEngine.php
│   ├── Checkers/
│   │   ├── ContentStructureChecker.php
│   │   ├── KnowledgeAlignmentChecker.php
│   │   ├── TechnicalGeoChecker.php
│   │   └── AiVisibilityChecker.php
│   └── Contracts/
│       └── DiagnosisCheckerInterface.php
│
└── BrandMonitor/                          # 品牌监测（NEW）
    ├── BrandMonitorOrchestrator.php       # 编排器，协调各平台检测
    ├── BrandVisibilityService.php         # AI 可见性计算
    ├── BrandSourceService.php             # 信源分析
    ├── BrandSentimentService.php          # 舆情分析
    ├── PlatformAdapters/
    │   ├── PlatformAdapterInterface.php   # 平台适配器接口
    │   ├── DoubaoAdapter.php
    │   ├── KimiAdapter.php
    │   ├── QianwenAdapter.php
    │   ├── WenxinAdapter.php
    │   ├── GoogleAiAdapter.php
    │   └── ChatGptAdapter.php
    ├── KeywordManager.php                 # 关键词管理
    └── ActivityLogger.php                 # 活动日志记录
```

### 6.2 平台适配器接口

```php
interface PlatformAdapterInterface
{
    public function platformCode(): string;
    public function platformName(): string;

    /**
     * 查询关键词在该平台的搜索排名
     * @return array{rank: int|null, visibility_score: float, in_top10: bool}
     */
    public function checkKeywordRank(string $keyword, array $config): array;

    /**
     * 获取品牌信源分析
     * @return array{brand_quote_pct: float, competitors: array, top_articles: array}
     */
    public function analyzeSources(string $brandName, array $config): array;

    /**
     * 获取品牌舆情分析
     * @return array{positive_pct: float, neutral_pct: float, negative_pct: float, snippets: array}
     */
    public function analyzeSentiment(string $brandName, array $config): array;
}
```

### 6.3 Job

```
app/Jobs/
├── RunGeoDiagnosisJob.php               # 文章诊断 Job（已有）
├── RunBrandMonitorJob.php               # 品牌监测 Job（NEW）
│   - 遍历所有已启用平台和活跃关键词
│   - 调用各 PlatformAdapter 获取排名/信源/舆情数据
│   - 写入 brand_monitor_results 表
│   - 生成活动日志（排名变化、竞品预警等）
│   - 通过 Reverb 推送更新
└── CleanupOldDiagnosisJob.php           # 清理过期诊断记录（NEW）
    - 保留最近 10 次诊断/每篇文章
    - 保留最近 30 天品牌监测结果
```

---

## 七、前端需求

### 7.1 页面清单（5 页）

| 页面 | 路由 | 侧边栏位置 | 说明 |
|------|------|------------|------|
| 诊断概览 | `/admin/geo-diagnosis` | GEO 诊断 → 诊断概览 | 仪表盘，4 指标卡 + 活动列表 + 品牌快照 |
| 品牌监测 | `/admin/geo-diagnosis/brand` | GEO 诊断 → 品牌监测 | 3 子标签：AI 可见性 / 信源检测 / 舆情评价 |
| 文章列表 | `/admin/articles` | 内容 → 文章 | 增强：新增 GEO 评分列 |
| 文章详情 | `/admin/articles/{id}` | — | 右侧栏诊断面板（评分环 + 3 tab） |
| 诊断设置 | `/admin/geo-diagnosis/settings` | GEO 诊断 → 诊断设置 | 权重滑块 + 检查项开关 |

### 7.2 组件清单

```
resources/views/components/geo-diagnosis/
├── dashboard/
│   ├── metric-cards.blade.php           # 4 个指标卡
│   ├── activity-list.blade.php          # 最近诊断活动
│   └── platform-snapshot.blade.php      # 品牌可见度快照
│
├── brand-monitor/
│   ├── platform-selector.blade.php      # 平台选择器（卡片式）
│   ├── keyword-manager.blade.php        # 关键词标签管理
│   ├── visibility-table.blade.php       # AI 可见性结果表
│   ├── source-analysis.blade.php        # 信源分析（进度条 + TOP 5）
│   ├── competitor-tracker.blade.php     # 竞品追踪卡片
│   └── sentiment-overview.blade.php     # 舆情评价（情感分布 + 摘要）
│
├── diagnosis/
│   ├── score-ring.blade.php             # 评分环 + 4 维度条形图
│   ├── findings-list.blade.php          # 检查项列表
│   ├── recommendations-list.blade.php   # 改进建议列表
│   ├── history-list.blade.php           # 历史诊断对比
│   └── progress-overlay.blade.php       # 诊断运行进度弹窗
│
└── settings/
    ├── weight-sliders.blade.php         # 维度权重滑块
    └── check-toggles.blade.php          # 检查项开关
```

### 7.3 交互规范

- 所有页面使用 GEOFlow Admin UI V3 设计规范（白色侧边栏 256px、固定顶栏 64px、内容区 max-width 1280px）
- 诊断运行使用进度弹窗 + 分步动画（5 步，每步约 650ms）
- 评分环使用 SVG 动画（stroke-dashoffset transition 1s）
- 品牌监测数据每 5 分钟自动刷新（通过 Reverb WebSocket）
- 关键词增删使用标签式交互（添加用 prompt，删除用 × 按钮）

---

## 八、迁移文件

```
database/migrations/
├── 2026_11_01_000001_create_geo_diagnosis_runs_table.php       # 已有
├── 2026_11_01_000002_create_geo_diagnosis_settings_table.php   # 已有
├── 2026_11_01_000003_create_brand_platforms_table.php          # NEW
├── 2026_11_01_000004_create_brand_keywords_table.php           # NEW
├── 2026_11_01_000005_create_brand_monitor_results_table.php    # NEW
├── 2026_11_01_000006_create_brand_competitors_table.php        # NEW
└── 2026_11_01_000007_create_brand_activity_log_table.php       # NEW
```

---

## 九、验收标准

### 9.1 功能验收

- [ ] 诊断概览页正确展示 4 个指标卡和最近活动
- [ ] 品牌监测页 3 个子标签均可切换且数据正确
- [ ] 平台选择器可启用/禁用平台
- [ ] 关键词可增删，列表实时更新
- [ ] AI 可见性表格展示各平台排名和趋势
- [ ] 信源检测显示品牌引用占比和竞品追踪
- [ ] 舆情评价显示情感分布和近期摘要
- [ ] 文章列表新增 GEO 评分列
- [ ] 文章详情页诊断面板正确显示评分和建议
- [ ] 点击运行诊断后 30 秒内返回结果
- [ ] 诊断历史保留最近 10 次
- [ ] 设置页可调整维度权重（总和校验 100%）

### 9.2 性能验收

- [ ] 单次文章诊断耗时 ≤ 30 秒（不含 AI 平台 API 调用）
- [ ] 品牌监测全平台刷新耗时 ≤ 60 秒
- [ ] 诊断 Job 失败时有重试机制（最多 2 次）
- [ ] 同一文章同时只能运行 1 个诊断任务
- [ ] 概览仪表盘接口响应 ≤ 500ms

### 9.3 数据验收

- [ ] 所有新增表有正确的索引
- [ ] `brand_monitor_results` 数据按时间倒序查询性能良好
- [ ] 文章删除时级联删除诊断记录
- [ ] 关键词删除时级联删除监测结果
- [ ] 活动日志自动清理（保留 30 天）

---

## 十、排期估算

| 阶段 | 工作内容 | 预估工时 |
|------|----------|----------|
| 数据层 | 5 个新迁移 + Model + Repository | 1 天 |
| 服务层 — 诊断 | 4 个 Checker + Scorer + RecommendationEngine（已有，微调） | 0.5 天 |
| 服务层 — 品牌监测 | 6 个 PlatformAdapter + Orchestrator + 3 个 Service | 3 天 |
| Job + 事件 | RunBrandMonitorJob + CleanupJob + Reverb 推送 | 1 天 |
| API | 8 组接口 + 验证 + 错误处理 | 1.5 天 |
| 前端 — 概览 | 仪表盘 3 个组件 | 1 天 |
| 前端 — 品牌监测 | 6 个组件 + 3 个标签页 | 2 天 |
| 前端 — 诊断面板 | 评分环 + 3 tab + 进度弹窗 | 1 天 |
| 前端 — 设置 + 文章列表增强 | 权重滑块 + 检查项开关 + 表格列 | 0.5 天 |
| 测试 | 单元测试 + 集成测试 + E2E | 1.5 天 |
| **合计** | | **13 天** |

---

## 十一、风险与依赖

| 风险 | 缓解措施 |
|------|----------|
| AI 平台 API 调用慢/失败 | 各 Adapter 设超时 10 秒，失败时该维度给 0 分并标记"数据不可用" |
| AI 平台无公开 API | MVP 阶段使用网页抓取或第三方数据服务；长期推动平台开放 API |
| 知识库对比计算量大 | 限制对比文档数量（最多 3 个），异步执行 |
| 品牌监测数据量大 | 按 site_id 分片，定期归档历史数据 |
| 前端组件复杂度高 | 先用 Blade + Alpine.js 实现，后续迭代可升级 Vue |

**外部依赖：**
- KnowledgeBase + KnowledgeChunk 数据（已有）
- SiteSetting 数据（已有）
- Reverb WebSocket（已有）
- 各 AI 平台的数据获取能力（需调研，见风险项）

---

## 十二、后续迭代（第二期）

> 以下功能不在 MVP 范围内，但第一期架构设计需预留扩展点。

### 12.1 竞品 GEO 策略对比面板

- 在品牌监测页新增「竞品对比」标签，展示竞品在各 AI 平台排名趋势曲线
- **架构预留：** `brand_competitors` 表已有 `quote_percentage` 和 `weekly_change`，后续加 `rank_history JSONB` 字段存时序数据即可
- **依赖：** 需积累至少 4 周品牌监测数据才有对比意义

### 12.2 AI 引用归因追踪

- 追踪具体哪些 AI 引擎（豆包/Kimi/通义千问...）在什么查询中引用了本站哪篇文章
- **架构预留：** `brand_monitor_results.source_analysis.top_cited_articles` 已存 article_id + cite_count，后续扩展为独立 `brand_citation_events` 表记录每次引用事件
- **数据量预估：** 每平台每关键词每天约 10-50 条引用事件，需考虑分区表

### 12.3 全站批量诊断

- 批量扫描所有已发布文章，生成全站 GEO 健康度报告
- **架构预留：** `RunGeoDiagnosisJob` 已支持单文章诊断，后续用 `ChunkById` 分批派发即可
- **性能注意：** 全站 1000 篇文章 × 30 秒/篇 ≈ 8 小时，需支持断点续跑和优先级队列

### 12.4 诊断报告导出

- 支持导出 PDF/HTML 格式的 GEO 诊断报告，含评分趋势图和改进建议
- **技术方案：** 后端用 `barryvdh/laravel-dompdf` 或前端用 `html2canvas + jsPDF`
- **导出方式：** 文章详情页增加「导出报告」按钮；全站诊断可自动生成

### 12.5 与 niucloud 生态集成

GEO 诊断可作为独立能力嵌入 niucloud 插件体系：

**df_cms 集成方案：**
- 文章编辑器（TinyMCE/UEditor）侧边栏增加「GEO 评分」面板
- 保存文章时自动触发诊断（debounce 3 秒），实时显示分数变化
- 技术实现：监听 `article.saved` 事件，前端通过 Echo 接收分数更新

**df_diypage 集成方案：**
- 页面搭建器增加「GEO 分析」组件，检查页面的 Schema.org 标记和结构化数据
- 装修页发布前自动跑诊断，分数 < 60 时弹出警告
- 技术实现：在 `DiyPageController@publish` 前插入诊断中间件

**df_shop 商品页集成（可选）：**
- 商品详情页自动注入 Product 类型的 Schema.org JSON-LD
- 商品发布时跑轻量诊断（仅检查技术 GEO 维度）

**集成架构：**
```
niucloud/addon/df_geo/
├── app/
│   ├── Listeners/
│   │   ├── ArticleSavedListener.php      # 监听 df_cms 文章保存
│   │   ├── PagePublishingListener.php     # 监听 df_diypage 页面发布
│   │   └── ProductSavedListener.php      # 监听 df_shop 商品保存
│   └── Services/
│       └── GeoDiagnosisFacade.php        # 统一诊断入口 Facade
└── resources/views/
    ├── components/
    │   ├── geo-score-widget.blade.php     # 侧边栏评分小组件
    │   └── geo-analysis-panel.blade.php  # 完整分析面板
    └── admin/
        └── geo-settings.blade.php        # 集成设置页
```

### 12.6 更多 AI 平台适配器

- Perplexity、Copilot、秘塔搜索等新兴 AI 搜索引擎
- 每个平台实现 `PlatformAdapterInterface` 即可，无需改动核心逻辑
- 部分平台可能需要付费 API Key，需在 `brand_platforms.api_config` 中管理密钥

### 12.7 品牌监测告警规则

- 用户可配置告警阈值（如"竞品引用占比超过 50% 时通知"）
- 通知渠道：站内消息 / 邮件 / 企业微信 / 钉钉
- **架构预留：** `brand_activity_log.severity` 已区分 info/warning/alert，后续加 `alert_rules` 表匹配规则即可
