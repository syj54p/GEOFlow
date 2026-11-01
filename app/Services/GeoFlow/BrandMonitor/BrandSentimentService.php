<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor;

use App\Models\BrandMonitorResult;

/**
 * 品牌舆情分析服务。
 *
 * 分析品牌在 AI 平台中的情感倾向（正面/中性/负面）和近期评价摘要。
 */
final class BrandSentimentService
{
    /**
     * 获取舆情分析数据
     *
     * @return array{
     *     positive_pct: float,
     *     neutral_pct: float,
     *     negative_pct: float,
     *     positive_delta: float,
     *     neutral_delta: float,
     *     negative_delta: float,
     *     snippets: list<array{platform: string, sentiment: string, query: string, text: string, detected_at: string}>
     * }
     */
    public function getSentimentAnalysis(string $period = '7d', ?int $siteId = null): array
    {
        // 当前时段数据
        $currentResults = BrandMonitorResult::query()
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->inPeriod($period)
            ->whereNotNull('sentiment_analysis')
            ->where('sentiment_analysis', '!=', '{}')
            ->latestDetected()
            ->limit(50)
            ->get();

        // 上一时段数据（用于计算 delta）
        $days = match ($period) {
            '1d' => 1, '7d' => 7, '30d' => 30, '90d' => 90, default => 7,
        };
        $previousResults = BrandMonitorResult::query()
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->whereBetween('detected_at', [now()->subDays($days * 2), now()->subDays($days)])
            ->whereNotNull('sentiment_analysis')
            ->where('sentiment_analysis', '!=', '{}')
            ->latestDetected()
            ->limit(50)
            ->get();

        // 聚合当前情感分布
        $current = $this->aggregateSentiment($currentResults);
        $previous = $this->aggregateSentiment($previousResults);

        // 收集评价片段
        $snippets = [];
        foreach ($currentResults as $result) {
            $analysis = $result->sentiment_analysis;
            $platformSnippets = $analysis['snippets'] ?? [];
            foreach ($platformSnippets as $snippet) {
                $snippets[] = [
                    'platform' => $result->platform->code ?? 'unknown',
                    'sentiment' => $snippet['sentiment'] ?? 'neutral',
                    'query' => $snippet['query'] ?? '',
                    'text' => $snippet['text'] ?? '',
                    'detected_at' => $snippet['detected_at'] ?? $result->detected_at?->toIso8601String(),
                ];
            }
        }

        // 按时间倒序
        usort($snippets, fn($a, $b) => strcmp($b['detected_at'], $a['detected_at']));

        return [
            'positive_pct' => $current['positive'],
            'neutral_pct' => $current['neutral'],
            'negative_pct' => $current['negative'],
            'positive_delta' => round($current['positive'] - $previous['positive'], 1),
            'neutral_delta' => round($current['neutral'] - $previous['neutral'], 1),
            'negative_delta' => round($current['negative'] - $previous['negative'], 1),
            'snippets' => array_slice($snippets, 0, 20),
        ];
    }

    /**
     * 聚合情感数据
     *
     * @return array{positive: float, neutral: float, negative: float}
     */
    private function aggregateSentiment($results): array
    {
        $positives = [];
        $neutrals = [];
        $negatives = [];

        foreach ($results as $result) {
            $analysis = $result->sentiment_analysis;
            if (isset($analysis['positive_pct'])) {
                $positives[] = (float) $analysis['positive_pct'];
            }
            if (isset($analysis['neutral_pct'])) {
                $neutrals[] = (float) $analysis['neutral_pct'];
            }
            if (isset($analysis['negative_pct'])) {
                $negatives[] = (float) $analysis['negative_pct'];
            }
        }

        return [
            'positive' => !empty($positives) ? round(array_sum($positives) / count($positives), 1) : 0,
            'neutral' => !empty($neutrals) ? round(array_sum($neutrals) / count($neutrals), 1) : 0,
            'negative' => !empty($negatives) ? round(array_sum($negatives) / count($negatives), 1) : 0,
        ];
    }
}
