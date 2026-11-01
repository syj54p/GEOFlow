# GEO 诊断第一期（MVP）需求清单

> 版本：v1.0 | 日期：2026-11-01 | 状态：草稿
> 关联项目：GEOFlow 3.2+ | 优先级：P1

---

## 一、产品定位

**GEO 诊断**是 GEOFlow 后台的页面级健康检查工具，自动扫描文章/页面的 GEO 就绪度，输出评分与改进建议，帮助运营人员快速定位"为什么我的内容不被 AI 引用"。

**MVP 边界：** 只做**单页诊断 + 建议清单**，不做全站扫描、竞品对比、历史趋势（这些留第二期）。

---

## 二、用户故事

| 编号 | 角色 | 故事 | 验收标准 |
|------|------|------|----------|
| US-01 | 内容运营 | 在文章详情页能看到 GEO 诊断评分卡 | 评分卡显示 0-100 分，分 4 个维度 |
| US-02 | 内容运营 | 点击"运行诊断"后 30 秒内出结果 | 异步任务，WebSocket 推送进度 |
| US-03 | 内容运营 | 诊断结果包含可操作的改进建议 | 每条建议有"问题描述 + 影响维度 + 修复指引" |
| US-04 | 内容运营 | 诊断历史可追溯 | 保留最近 10 次诊断记录 |
| US-05 | 超级管理员 | 可配置诊断检查项的权重 | 后台设置页可调整各维度权重 |

---

## 三、功能模块

### 3.1 诊断评分卡（核心）

**评分维度（4 项，满分 100）：**

| 维度 | 权重（默认） | 检查项 | 数据来源 |
|------|-------------|--------|----------|
| **内容结构** | 25% | 标题层级完整性、段落长度合理性、FAQ/定义性语句存在性、列表/表格使用 | Article body 解析 |
| **知识对齐** | 30% | 知识库事实覆盖率、关键术语出现频次、与知识库矛盾检测 | KnowledgeBase + Article 对比 |
| **技术 GEO** | 20% | llms.txt 可达性、Schema.org 完整性、规范化链接、robots 策略 | SiteSetting + 前台路由 |
| **AI 可见度** | 25% | 核心关键词 AI 搜索排名、竞品引用占比、最近一次可见度运行结果 | AiVisibilityRun + AiVisibilityCompetitor |

**评分等级：**
- 🟢 优秀（80-100）：内容 GEO 就绪，保持更新
- 🟡 良好（60-79）：有改进空间，建议优先处理红色项
- 🟠 一般（40-59）：多项关键指标不达标，建议系统性优化
- 🔴 较差（0-39）：内容几乎不被 AI 引用，需要重构

### 3.2 改进建议清单

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
| TG-03 | 规范化链接（canonical）正确 | 技术 GEO | medium | ✅ |
| AV-01 | 至少 1 个关键词进入 AI 搜索前 10 | AI 可见度 | high | ❌ |
| AV-02 | 竞品引用占比 ≤ 50% | AI 可见度 | medium | ❌ |

### 3.3 诊断历史

- 每次诊断生成一条 `geo_diagnosis_runs` 记录
- 保留最近 10 次，超出自动清理
- 可对比两次诊断的评分变化

---

## 四、数据模型

### 4.1 新增表：`geo_diagnosis_runs`

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
    -- pending / running / completed / failed
    started_at TIMESTAMP,
    completed_at TIMESTAMP,
    error_message TEXT,
    
    -- 检查项结果（JSON）
    findings JSONB NOT NULL DEFAULT '[]',
    -- [{id, severity, dimension, title, description, impact_score, fix_guide, auto_fixable, passed}]
    
    -- 建议清单（JSON）
    recommendations JSONB NOT NULL DEFAULT '[]',
    -- [{id, severity, dimension, title, description, impact_score, fix_guide}]
    
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_geo_diagnosis_runs_article ON geo_diagnosis_runs(article_id);
CREATE INDEX idx_geo_diagnosis_runs_status ON geo_diagnosis_runs(status);
```

### 4.2 新增表：`geo_diagnosis_settings`

```sql
CREATE TABLE geo_diagnosis_settings (
    id BIGSERIAL PRIMARY KEY,
    site_id BIGINT,
    -- NULL 表示全局设置
    
    dimension_weights JSONB NOT NULL DEFAULT '{
        "content_structure": 0.25,
        "knowledge_alignment": 0.30,
        "technical_geo": 0.20,
        "ai_visibility": 0.25
    }',
    
    enabled_checks JSONB NOT NULL DEFAULT '[]',
    -- 空数组表示启用全部检查项
    
    created_at TIMESTAMP NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMP NOT NULL DEFAULT NOW(),
    
    UNIQUE(site_id)
);
```

---

## 五、API 设计

### 5.1 运行诊断

```
POST /api/admin/articles/{article}/geo-diagnosis/run
```

**响应：**
```json
{
  "data": {
    "run_id": 123,
    "status": "pending",
    "estimated_seconds": 30
  }
}
```

**逻辑：**
1. 创建 `geo_diagnosis_runs` 记录（status=pending）
2. 派发 Job：`RunGeoDiagnosisJob`
3. Job 依次执行 4 个维度的检查器
4. 计算各维度分数和总分
5. 生成建议清单
6. 更新记录（status=completed）
7. 通过 Reverb 推送进度给前端

### 5.2 获取诊断结果

```
GET /api/admin/articles/{article}/geo-diagnosis/latest
```

**响应：**
```json
{
  "data": {
    "run_id": 123,
    "status": "completed",
    "total_score": 72,
    "grade": "good",
    "dimension_scores": {
      "content_structure": 18,
      "knowledge_alignment": 22,
      "technical_geo": 16,
      "ai_visibility": 16
    },
    "findings": [...],
    "recommendations": [...],
    "completed_at": "2026-11-01T10:30:00Z"
  }
}
```

### 5.3 获取诊断历史

```
GET /api/admin/articles/{article}/geo-diagnosis/history?page=1&per_page=10
```

**响应：**
```json
{
  "data": [
    {
      "run_id": 123,
      "total_score": 72,
      "status": "completed",
      "completed_at": "2026-11-01T10:30:00Z"
    },
    ...
  ],
  "meta": {"current_page": 1, "total": 5}
}
```

### 5.4 获取/更新诊断设置

```
GET  /api/admin/geo-diagnosis/settings
PUT  /api/admin/geo-diagnosis/settings
```

---

## 六、服务层设计

### 6.1 核心服务类

```
app/Services/GeoFlow/GeoDiagnosis/
├── GeoDiagnosisOrchestrator.php      # 编排器，调度各维度检查器
├── GeoDiagnosisScorer.php            # 评分计算器
├── GeoDiagnosisRecommendationEngine.php  # 建议生成器
├── Checkers/
│   ├── ContentStructureChecker.php   # 内容结构检查
│   ├── KnowledgeAlignmentChecker.php # 知识对齐检查
│   ├── TechnicalGeoChecker.php       # 技术 GEO 检查
│   └── AiVisibilityChecker.php       # AI 可见度检查
└── Contracts/
    └── DiagnosisCheckerInterface.php # 检查器接口
```

### 6.2 检查器接口

```php
interface DiagnosisCheckerInterface
{
    public function dimension(): string;
    public function checks(): array;
    public function run(Article $article, array $context): array;
    // 返回: [{id, passed, severity, impact_score, data}]
}
```

### 6.3 Job

```
app/Jobs/RunGeoDiagnosisJob.php
- 接收 article_id, admin_id
- 依次调用 4 个 Checker
- 调用 Scorer 计算分数
- 调用 RecommendationEngine 生成建议
- 更新 geo_diagnosis_runs 记录
- 通过 Reverb 推送完成事件
```

---

## 七、前端需求

### 7.1 文章详情页 - 诊断面板

**位置：** 文章编辑页右侧边栏或底部 Tab

**组件：**
- `GeoDiagnosisCard.vue` - 评分卡（环形进度图 + 4 维度条形图）
- `GeoDiagnosisFindings.vue` - 检查项列表（✅/⚠️/❌ 图标）
- `GeoDiagnosisRecommendations.vue` - 改进建议列表
- `GeoDiagnosisHistory.vue` - 历史诊断对比

**交互：**
1. 点击"运行诊断"按钮 → 显示进度条 → 完成后刷新面板
2. 建议项可点击"查看修复指引"展开详细说明
3. 历史诊断可点击对比分数变化

### 7.2 诊断设置页

**位置：** 后台设置 → GEO 诊断

**组件：**
- 权重滑块（4 个维度，总和 100%）
- 检查项开关列表

---

## 八、迁移文件

```
database/migrations/
├── 2026_11_01_000001_create_geo_diagnosis_runs_table.php
└── 2026_11_01_000002_create_geo_diagnosis_settings_table.php
```

---

## 九、验收标准

### 9.1 功能验收

- [ ] 文章详情页显示 GEO 诊断入口
- [ ] 点击运行后 30 秒内返回结果
- [ ] 评分卡正确显示 4 维度分数和总分
- [ ] 建议清单至少包含 3 条可操作建议
- [ ] 诊断历史保留最近 10 次
- [ ] 设置页可调整维度权重

### 9.2 性能验收

- [ ] 单次诊断耗时 ≤ 30 秒（不含 AI 可见度 API 调用）
- [ ] 诊断 Job 失败时有重试机制（最多 2 次）
- [ ] 同一文章同时只能运行 1 个诊断任务

### 9.3 数据验收

- [ ] `geo_diagnosis_runs` 表正确记录每次诊断
- [ ] findings 和 recommendations JSON 结构符合规范
- [ ] 文章删除时级联删除诊断记录

---

## 十、排期估算

| 阶段 | 工作内容 | 预估工时 |
|------|----------|----------|
| 数据层 | 迁移文件 + Model + Repository | 0.5 天 |
| 服务层 | 4 个 Checker + Scorer + RecommendationEngine | 2 天 |
| Job + 事件 | RunGeoDiagnosisJob + Reverb 推送 | 0.5 天 |
| API | 4 个接口 + 验证 + 错误处理 | 1 天 |
| 前端 | 诊断面板组件 + 设置页 | 2 天 |
| 测试 | 单元测试 + 集成测试 | 1 天 |
| **合计** | | **7 天** |

---

## 十一、风险与依赖

| 风险 | 缓解措施 |
|------|----------|
| AI 可见度 API 调用慢/失败 | 设超时 15 秒，失败时该维度给 0 分并标记"数据不可用" |
| 知识库对比计算量大 | 限制对比的知识库数量（最多 3 个），异步执行 |
| 前端组件复杂度高 | 先用简单列表，后续迭代可视化 |

**外部依赖：**
- AiVisibilityRun 数据（已有）
- KnowledgeBase + KnowledgeChunk 数据（已有）
- SiteSetting 数据（已有）
- Reverb WebSocket（已有）

---

## 十二、后续迭代（第二期）

- 全站批量诊断
- 竞品 GEO 对比面板
- AI 引用归因追踪
- 诊断报告 PDF 导出
- 与 df_cms/df_diypage 集成
