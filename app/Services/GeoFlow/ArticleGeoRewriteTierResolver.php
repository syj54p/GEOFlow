<?php

namespace App\Services\GeoFlow;

use App\Models\Article;
use App\Models\ArticleAiOptimizationRun;
use App\Models\ArticleAiQualityCheck;

/**
 * 根据文章质量评分自动选择 GEO 改写级别和优化策略。
 *
 * 改写级别映射规则（基于现有 100 分制质量评分）：
 * - score >= 85  → Light（格式优化，pass 策略）
 * - score 70-84  → Medium（结构重组，excellent_80 策略）
 * - score < 70   → Heavy（完全重构，excellent_90 策略）
 *
 * 同时提供对应的提示词模板名称，方便前端和自动化流程选用。
 */
final class ArticleGeoRewriteTierResolver
{
    public const TIER_LIGHT = 'light';
    public const TIER_MEDIUM = 'medium';
    public const TIER_HEAVY = 'heavy';

    public const STRATEGY_PASS = ArticleAiOptimizationPolicy::STRATEGY_PASS;
    public const STRATEGY_EXCELLENT_80 = ArticleAiOptimizationPolicy::STRATEGY_EXCELLENT_80;
    public const STRATEGY_EXCELLENT_90 = ArticleAiOptimizationPolicy::STRATEGY_EXCELLENT_90;

    private const TIER_PROMPT_NAMES = [
        self::TIER_LIGHT => 'GEO轻改写·格式优化（30-40%）',
        self::TIER_MEDIUM => 'GEO中改写·结构重组（50-60%）',
        self::TIER_HEAVY => 'GEO重改写·完全重构（70-80%）',
    ];

    private const TIER_STRATEGIES = [
        self::TIER_LIGHT => self::STRATEGY_PASS,
        self::TIER_MEDIUM => self::STRATEGY_EXCELLENT_80,
        self::TIER_HEAVY => self::STRATEGY_EXCELLENT_90,
    ];

    /**
     * 根据质量评分解析改写级别。
     *
     * @return array{tier: string, strategy: string, prompt_name: string, score: int, reason: string}
     */
    public function resolve(int $qualityScore): array
    {
        $qualityScore = max(0, min(100, $qualityScore));

        if ($qualityScore >= 85) {
            $tier = self::TIER_LIGHT;
            $reason = "质量评分 {$qualityScore} >= 85，仅需格式优化";
        } elseif ($qualityScore >= 70) {
            $tier = self::TIER_MEDIUM;
            $reason = "质量评分 {$qualityScore} 在 70-84 区间，需要结构重组";
        } else {
            $tier = self::TIER_HEAVY;
            $reason = "质量评分 {$qualityScore} < 70，需要完全重构";
        }

        return [
            'tier' => $tier,
            'strategy' => self::TIER_STRATEGIES[$tier],
            'prompt_name' => self::TIER_PROMPT_NAMES[$tier],
            'score' => $qualityScore,
            'reason' => $reason,
        ];
    }

    /**
     * 从文章的最新质检结果自动解析改写级别。
     *
     * @return array{tier: string, strategy: string, prompt_name: string, score: int, reason: string}|null
     */
    public function resolveForArticle(Article $article): ?array
    {
        $latestCheck = ArticleAiQualityCheck::query()
            ->where('article_id', (int) $article->id)
            ->whereNotNull('score')
            ->orderByDesc('id')
            ->first();

        if (! $latestCheck) {
            return null;
        }

        return $this->resolve((int) $latestCheck->score);
    }

    /**
     * 获取所有可用的改写级别列表（供前端下拉框使用）。
     *
     * @return list<array{tier: string, label: string, strategy: string, prompt_name: string, description: string}>
     */
    public static function availableTiers(): array
    {
        return [
            [
                'tier' => self::TIER_LIGHT,
                'label' => '轻改写（格式优化）',
                'strategy' => self::STRATEGY_PASS,
                'prompt_name' => self::TIER_PROMPT_NAMES[self::TIER_LIGHT],
                'description' => '改写范围 30-40%，保留原文结构，优化格式和表达',
            ],
            [
                'tier' => self::TIER_MEDIUM,
                'label' => '中改写（结构重组）',
                'strategy' => self::STRATEGY_EXCELLENT_80,
                'prompt_name' => self::TIER_PROMPT_NAMES[self::TIER_MEDIUM],
                'description' => '改写范围 50-60%，调整结构，强化信息增益和AI适配性',
            ],
            [
                'tier' => self::TIER_HEAVY,
                'label' => '重改写（完全重构）',
                'strategy' => self::STRATEGY_EXCELLENT_90,
                'prompt_name' => self::TIER_PROMPT_NAMES[self::TIER_HEAVY],
                'description' => '改写范围 70-80%，完全按GEO标准重构，最大化AI引用率',
            ],
        ];
    }

    /**
     * 根据改写级别获取对应的提示词模板名称。
     */
    public static function promptNameForTier(string $tier): ?string
    {
        return self::TIER_PROMPT_NAMES[$tier] ?? null;
    }

    /**
     * 根据改写级别获取对应的优化策略。
     */
    public static function strategyForTier(string $tier): ?string
    {
        return self::TIER_STRATEGIES[$tier] ?? null;
    }
}
