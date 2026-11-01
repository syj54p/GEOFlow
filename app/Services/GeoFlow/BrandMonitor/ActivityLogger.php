<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor;

use App\Models\BrandActivityLog;

/**
 * 品牌监测活动日志记录器。
 *
 * 记录诊断完成、排名变化、竞品预警、舆情告警等活动事件，
 * 供诊断概览仪表盘展示。
 */
final class ActivityLogger
{
    /**
     * 记录诊断完成事件
     */
    public function logDiagnosisCompleted(int $articleId, string $articleTitle, int $score, ?int $siteId = null): void
    {
        $grade = match (true) {
            $score >= 80 => '优秀',
            $score >= 60 => '良好',
            $score >= 40 => '一般',
            default => '较差',
        };

        BrandActivityLog::create([
            'site_id' => $siteId,
            'activity_type' => BrandActivityLog::TYPE_DIAGNOSIS_COMPLETED,
            'title' => "「{$articleTitle}」诊断完成",
            'description' => "{$score} 分 · {$grade}",
            'related_id' => $articleId,
            'related_type' => 'article',
            'severity' => $score >= 60 ? BrandActivityLog::SEVERITY_INFO : BrandActivityLog::SEVERITY_WARNING,
            'created_at' => now(),
        ]);
    }

    /**
     * 记录品牌排名变化事件
     */
    public function logBrandRankChange(string $platformName, string $keyword, int $rank, int $rankDelta, ?int $siteId = null): void
    {
        $direction = $rankDelta > 0 ? '↑' : ($rankDelta < 0 ? '↓' : '→');
        $deltaText = $rankDelta !== 0 ? " · 较昨日 {$direction} " . abs($rankDelta) : '';

        BrandActivityLog::create([
            'site_id' => $siteId,
            'activity_type' => BrandActivityLog::TYPE_BRAND_RANK_CHANGE,
            'title' => "品牌监测：{$platformName}「{$keyword}」查询完成",
            'description' => "排名第 {$rank}{$deltaText}",
            'related_type' => 'brand_monitor_result',
            'severity' => BrandActivityLog::SEVERITY_INFO,
            'created_at' => now(),
        ]);
    }

    /**
     * 记录竞品预警事件
     */
    public function logCompetitorAlert(string $competitorName, float $quotePct, ?int $siteId = null): void
    {
        BrandActivityLog::create([
            'site_id' => $siteId,
            'activity_type' => BrandActivityLog::TYPE_COMPETITOR_ALERT,
            'title' => "预警：竞品「{$competitorName}」AI 引用占比升至 {$quotePct}%",
            'description' => $quotePct > 50 ? '超过 50% 警戒线' : '竞品引用占比上升',
            'related_type' => 'brand_competitor',
            'severity' => $quotePct > 50 ? BrandActivityLog::SEVERITY_ALERT : BrandActivityLog::SEVERITY_WARNING,
            'created_at' => now(),
        ]);
    }

    /**
     * 记录舆情告警事件
     */
    public function logSentimentAlert(string $platform, string $sentiment, string $text, ?int $siteId = null): void
    {
        BrandActivityLog::create([
            'site_id' => $siteId,
            'activity_type' => BrandActivityLog::TYPE_SENTIMENT_ALERT,
            'title' => "{$platform} 舆情告警：{$sentiment}",
            'description' => mb_substr($text, 0, 200),
            'related_type' => 'brand_monitor_result',
            'severity' => $sentiment === 'negative' ? BrandActivityLog::SEVERITY_ALERT : BrandActivityLog::SEVERITY_WARNING,
            'created_at' => now(),
        ]);
    }

    /**
     * 获取最近活动日志
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, BrandActivityLog>
     */
    public function getRecentActivities(?int $siteId = null, int $limit = 10): \Illuminate\Database\Eloquent\Collection
    {
        return BrandActivityLog::query()
            ->forSite($siteId)
            ->latestFirst()
            ->limit($limit)
            ->get();
    }
}
