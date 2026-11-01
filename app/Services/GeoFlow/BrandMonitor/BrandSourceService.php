<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor;

use App\Models\BrandCompetitor;
use App\Models\BrandMonitorResult;
use Illuminate\Support\Collection;

/**
 * 品牌信源分析服务。
 *
 * 分析品牌在 AI 搜索结果中的引用占比、高频引用内容和竞品信源。
 */
final class BrandSourceService
{
    /**
     * 获取信源分析数据
     *
     * @return array{
     *     brand_quote_pct: float,
     *     competitor_quote_pct: float,
     *     other_quote_pct: float,
     *     top_cited_articles: list<array{article_id: int|null, title: string, cite_count: int}>,
     *     competitors: list<array{name: string, quote_pct: float, weekly_change: float}>
     * }
     */
    public function getSourceAnalysis(string $period = '7d', ?int $siteId = null): array
    {
        // 从最新的监测结果中聚合信源数据
        $results = BrandMonitorResult::query()
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->inPeriod($period)
            ->whereNotNull('source_analysis')
            ->where('source_analysis', '!=', '{}')
            ->latestDetected()
            ->limit(50)
            ->get();

        // 聚合品牌引用占比
        $brandPcts = [];
        $topArticles = [];
        foreach ($results as $result) {
            $analysis = $result->source_analysis;
            if (!empty($analysis['brand_quote_pct'])) {
                $brandPcts[] = (float) $analysis['brand_quote_pct'];
            }
            if (!empty($analysis['top_cited_articles'])) {
                foreach ($analysis['top_cited_articles'] as $article) {
                    $key = $article['article_id'] ?? $article['title'];
                    if (!isset($topArticles[$key])) {
                        $topArticles[$key] = [
                            'article_id' => $article['article_id'] ?? null,
                            'title' => $article['title'] ?? '未知',
                            'cite_count' => 0,
                        ];
                    }
                    $topArticles[$key]['cite_count'] += (int) ($article['cite_count'] ?? 0);
                }
            }
        }

        $brandQuotePct = !empty($brandPcts) ? round(array_sum($brandPcts) / count($brandPcts), 1) : 0;

        // 获取竞品数据
        $competitors = BrandCompetitor::query()
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->active()
            ->byQuotePct()
            ->get();

        $competitorQuotePct = 0;
        $competitorData = [];
        foreach ($competitors as $comp) {
            $competitorQuotePct += (float) $comp->quote_percentage;
            $competitorData[] = [
                'name' => $comp->name,
                'quote_pct' => (float) $comp->quote_percentage,
                'weekly_change' => (float) $comp->weekly_change,
            ];
        }

        $otherQuotePct = max(0, 100 - $brandQuotePct - $competitorQuotePct);

        // 排序 TOP 文章
        usort($topArticles, fn($a, $b) => $b['cite_count'] - $a['cite_count']);

        return [
            'brand_quote_pct' => $brandQuotePct,
            'competitor_quote_pct' => round($competitorQuotePct, 1),
            'other_quote_pct' => round($otherQuotePct, 1),
            'top_cited_articles' => array_slice(array_values($topArticles), 0, 5),
            'competitors' => $competitorData,
        ];
    }
}
