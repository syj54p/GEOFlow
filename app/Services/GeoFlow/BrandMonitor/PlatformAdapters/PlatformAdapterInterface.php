<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor\PlatformAdapters;

/**
 * AI 平台适配器接口。
 *
 * 每个 AI 平台（豆包、Kimi、通义千问等）实现此接口，
 * 提供关键词排名查询、信源分析和舆情分析能力。
 */
interface PlatformAdapterInterface
{
    /**
     * 平台代码（如 doubao, kimi, qianwen）
     */
    public function platformCode(): string;

    /**
     * 平台显示名称
     */
    public function platformName(): string;

    /**
     * 查询关键词在该平台的搜索排名。
     *
     * @param  string  $keyword  搜索关键词
     * @param  array   $config   平台 API 配置
     * @return array{rank: int|null, visibility_score: float, in_top10: bool, snippets: list<string>}
     */
    public function checkKeywordRank(string $keyword, array $config = []): array;

    /**
     * 获取品牌信源分析。
     *
     * @param  string  $brandName  品牌名称
     * @param  array   $config     平台 API 配置
     * @return array{brand_quote_pct: float, competitor_quotes: list<array{name: string, pct: float}>, top_articles: list<array{title: string, cite_count: int}>}
     */
    public function analyzeSources(string $brandName, array $config = []): array;

    /**
     * 获取品牌舆情分析。
     *
     * @param  string  $brandName  品牌名称
     * @param  array   $config     平台 API 配置
     * @return array{positive_pct: float, neutral_pct: float, negative_pct: float, snippets: list<array{text: string, sentiment: string, detected_at: string}>}
     */
    public function analyzeSentiment(string $brandName, array $config = []): array;
}
