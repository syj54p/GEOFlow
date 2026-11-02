# yao-meta-skill × GEOFlow 整合方案

## 一、整合目标

将 yao-meta-skill 的**技能工程化能力**注入 GEOFlow 的 AI 内容生产流程，实现：
1. **标准化 AI 质量评估** - 用技能化的评估模板替代硬编码的检查规则
2. **可复用提示词管理** - 将 yao-open-prompts 的 25 个 GEO 模板技能化
3. **技能化工作流** - 把 GEOFlow 的 AI 工作流封装为可组合、可评估的技能

---

## 二、架构设计

```
┌─────────────────────────────────────────────────────────────────┐
│                        GEOFlow 内容生产流程                       │
├─────────────────────────────────────────────────────────────────┤
│                                                                  │
│  ┌──────────────┐    ┌──────────────┐    ┌──────────────┐       │
│  │  文章编辑     │ →  │  AI 质量检查  │ →  │  分发发布     │       │
│  └──────┬───────┘    └──────┬───────┘    └──────────────┘       │
│         │                   │                                    │
│         ▼                   ▼                                    │
│  ┌─────────────────────────────────────────────────────┐        │
│  │              yao-meta-skill 技能层                    │        │
│  ├─────────────────────────────────────────────────────┤        │
│  │  ┌─────────────┐  ┌─────────────┐  ┌─────────────┐  │        │
│  │  │ GEO 优化技能 │  │ 质量评估技能 │  │ 提示词管理  │  │        │
│  │  │ (geo-opt)   │  │ (qa-eval)   │  │ (prompt-mgr)│  │        │
│  │  └─────────────┘  └─────────────┘  └─────────────┘  │        │
│  └─────────────────────────────────────────────────────┘        │
│                                                                  │
└─────────────────────────────────────────────────────────────────┘
```

---

## 三、核心整合模块

### 3.1 GEO 优化技能 (`geo-opt-skill`)

**功能**：将 yao-open-prompts 的 25 个 GEO 模板封装为可调用技能

**输入**：
- 文章原文
- 目标 AI 引擎（ChatGPT/Perplexity/Google AI）
- 优化维度（结构/语义/证据）

**输出**：
- 优化后的文章
- 优化报告（改动点、预期提升）

**实现**：
```php
// app/Services/SkillIntegration/GeoOptimizationSkill.php
class GeoOptimizationSkill extends BaseSkill
{
    protected $skillId = 'geo-opt-v1';
    protected $promptsPath = '/wwwroot/yao-open-prompts/prompts/08-ai-marketing/';
    
    public function execute(Article $article, array $options): SkillResult
    {
        // 1. 加载适用的 GEO 提示词模板
        $template = $this->loadTemplate($options['target_engine']);
        
        // 2. 编译提示词（注入文章内容）
        $prompt = $this->compilePrompt($template, $article);
        
        // 3. 调用 AI 模型执行优化
        $result = $this->invokeModel($prompt, $options['model']);
        
        // 4. 生成优化报告
        return new SkillResult([
            'optimized_content' => $result->content,
            'changes' => $result->diff,
            'score_improvement' => $result->score_delta,
        ]);
    }
}
```

---

### 3.2 质量评估技能 (`qa-eval-skill`)

**功能**：用技能化的评估标准替代硬编码的质量检查

**输入**：
- 文章 ID
- 评估维度（事实性/可读性/SEO/GEO）
- 通过阈值

**输出**：
- 各维度得分
- 问题清单
- 改进建议

**实现**：
```php
// app/Services/SkillIntegration/QualityEvaluationSkill.php
class QualityEvaluationSkill extends BaseSkill
{
    protected $skillId = 'qa-eval-v1';
    
    public function execute(int $articleId, array $dimensions): SkillResult
    {
        $article = Article::find($articleId);
        
        // 1. 加载评估技能（含评估标准、示例、评分规则）
        $evalSkill = $this->loadSkill('article-quality-eval');
        
        // 2. 执行多维度评估
        $scores = [];
        foreach ($dimensions as $dim) {
            $scores[$dim] = $this->evaluateDimension($article, $dim, $evalSkill);
        }
        
        // 3. 生成评估报告
        return new SkillResult([
            'scores' => $scores,
            'passed' => $this->checkThreshold($scores),
            'issues' => $this->extractIssues($scores),
            'recommendations' => $this->generateRecommendations($scores),
        ]);
    }
}
```

---

### 3.3 提示词管理技能 (`prompt-mgr-skill`)

**功能**：统一管理 yao-open-prompts 的提示词，支持版本控制和效果追踪

**数据结构**：
```sql
CREATE TABLE skill_prompts (
    id BIGINT PRIMARY KEY,
    skill_id VARCHAR(50),           -- 关联技能 ID
    prompt_key VARCHAR(100),        -- 提示词标识
    version VARCHAR(20),            -- 版本号
    content TEXT,                   -- 提示词内容
    category VARCHAR(50),           -- 分类（GEO/质量/优化）
    usage_count INT DEFAULT 0,      -- 使用次数
    avg_score FLOAT,                -- 平均效果分
    created_at TIMESTAMP,
    updated_at TIMESTAMP
);
```

---

## 四、权限与安全

### 4.1 技能执行权限

| 权限 | 说明 | 默认 |
|---|---|---|
| `skill:read` | 读取技能定义和提示词 | ✅ 管理员 |
| `skill:execute` | 执行技能（调用 AI） | ✅ 管理员 |
| `skill:write` | 创建/修改技能 | ❌ 需审批 |
| `skill:delete` | 删除技能 | ❌ 需审批 |

### 4.2 沙箱隔离

```php
// 技能执行沙箱
class SkillSandbox
{
    protected $allowedOperations = [
        'ai_invoke',      // 允许调用 AI
        'read_article',   // 允许读取文章
        'write_result',   // 允许写入结果
    ];
    
    protected $forbiddenOperations = [
        'db_write',       // 禁止直接写数据库
        'file_delete',    // 禁止删除文件
        'network_call',   // 禁止外部网络请求（除 AI API）
    ];
}
```

---

## 五、实施计划

### Phase 1: 基础整合（1-2 天）
- [ ] 创建技能基础架构（BaseSkill、SkillResult）
- [ ] 导入 yao-open-prompts 的 25 个 GEO 模板
- [ ] 实现 `geo-opt-skill` 基础版

### Phase 2: 质量评估（2-3 天）
- [ ] 设计质量评估技能框架
- [ ] 实现 `qa-eval-skill`
- [ ] 与 GEOFlow 现有质量检查集成

### Phase 3: 提示词管理（1-2 天）
- [ ] 创建提示词管理表
- [ ] 实现提示词版本控制
- [ ] 添加效果追踪

### Phase 4: 治理与监控（1-2 天）
- [ ] 实现技能权限控制
- [ ] 添加技能执行日志
- [ ] 创建技能效果仪表盘

---

## 六、预期收益

| 指标 | 当前 | 整合后 | 提升 |
|---|---|---|---|
| GEO 优化效率 | 手动应用模板 | 一键自动化 | ⬆️ 80% |
| 质量评估一致性 | 依赖检查规则 | 标准化技能 | ⬆️ 60% |
| 提示词复用率 | 分散在文件中 | 统一管理 | ⬆️ 90% |
| AI 内容引用率 | 基线 | 优化后 | ⬆️ 40% |

---

## 七、风险与缓解

| 风险 | 概率 | 影响 | 缓解措施 |
|---|---|---|---|
| 技能执行超时 | 中 | 中 | 设置超时限制，异步执行 |
| AI API 费用超支 | 中 | 高 | 用量监控，每日上限 |
| 技能权限泄露 | 低 | 高 | 沙箱隔离，审计日志 |
| 提示词效果不佳 | 中 | 中 | A/B 测试，效果追踪 |

---

## 八、下一步

1. **确认整合方案** - 审阅本设计文档
2. **启动 Phase 1** - 创建技能基础架构
3. **导入 GEO 模板** - 将 25 个提示词技能化
4. **测试验证** - 在测试环境验证整合效果
