<?php

declare(strict_types=1);

namespace App\Support\GeoFlow;

/**
 * 内容工厂 7 阶段枚举。
 *
 * 映射 GEO 内容工程的完整生命周期：
 * 1. strategy   — 战略规划（Task 配置、选题、KPI 映射）
 * 2. evidence   — 证据收集（知识库检索、证据块结构化）
 * 3. generation — AI 辅助生成（提示词渲染、模型调用、正文产出）
 * 4. review     — 专家审核（AI 质检 + 人工审核）
 * 5. schema     — 机器可读优化（Schema.org JSON-LD 生成）
 * 6. publish    — 战略发布（工作流发布 + 多渠道分发）
 * 7. monitor    — 性能监控（引用率追踪、迭代决策）
 */
enum ContentFactoryStage: string
{
    case STRATEGY = 'strategy';
    case EVIDENCE = 'evidence';
    case GENERATION = 'generation';
    case REVIEW = 'review';
    case SCHEMA = 'schema';
    case PUBLISH = 'publish';
    case MONITOR = 'monitor';

    /**
     * 获取阶段的中文标签。
     */
    public function label(): string
    {
        return match ($this) {
            self::STRATEGY => '战略规划',
            self::EVIDENCE => '证据收集',
            self::GENERATION => 'AI 生成',
            self::REVIEW => '质量审核',
            self::SCHEMA => '结构化标记',
            self::PUBLISH => '战略发布',
            self::MONITOR => '性能监控',
        };
    }

    /**
     * 获取阶段的排序权重（越小越靠前）。
     */
    public function order(): int
    {
        return match ($this) {
            self::STRATEGY => 10,
            self::EVIDENCE => 20,
            self::GENERATION => 30,
            self::REVIEW => 40,
            self::SCHEMA => 50,
            self::PUBLISH => 60,
            self::MONITOR => 70,
        };
    }

    /**
     * 获取下一个阶段。如果是最后阶段则返回自身。
     */
    public function next(): self
    {
        return match ($this) {
            self::STRATEGY => self::EVIDENCE,
            self::EVIDENCE => self::GENERATION,
            self::GENERATION => self::REVIEW,
            self::REVIEW => self::SCHEMA,
            self::SCHEMA => self::PUBLISH,
            self::PUBLISH => self::MONITOR,
            self::MONITOR => self::MONITOR,
        };
    }

    /**
     * 获取所有阶段列表（按顺序）。
     *
     * @return list<self>
     */
    public static function all(): array
    {
        return [
            self::STRATEGY,
            self::EVIDENCE,
            self::GENERATION,
            self::REVIEW,
            self::SCHEMA,
            self::PUBLISH,
            self::MONITOR,
        ];
    }

    /**
     * 从文章的工作流状态推断当前阶段。
     */
    public static function fromArticleState(string $status, string $reviewStatus, bool $hasSchemaData, bool $isPublished): self
    {
        if ($isPublished && $hasSchemaData) {
            return self::MONITOR;
        }
        if ($isPublished) {
            return self::PUBLISH;
        }
        if ($hasSchemaData && ($reviewStatus === 'approved' || $reviewStatus === 'auto_approved')) {
            return self::PUBLISH;
        }
        if ($hasSchemaData) {
            return self::SCHEMA;
        }
        if ($reviewStatus === 'approved' || $reviewStatus === 'auto_approved') {
            return self::SCHEMA;
        }
        if ($reviewStatus === 'pending' || $reviewStatus === 'rejected') {
            return self::REVIEW;
        }
        if ($status === 'draft') {
            return self::GENERATION;
        }

        return self::STRATEGY;
    }
}
