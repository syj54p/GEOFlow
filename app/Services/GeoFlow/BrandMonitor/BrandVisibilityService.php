<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor;

use App\Models\BrandKeyword;
use App\Models\BrandMonitorResult;
use App\Models\BrandPlatform;
use App\Services\GeoFlow\BrandMonitor\PlatformAdapters\PlatformAdapterInterface;
use Illuminate\Support\Collection;

/**
 * 品牌 AI 可见性服务。
 *
 * 计算品牌在各 AI 平台的搜索排名和可见度评分。
 */
final class BrandVisibilityService
{
    /**
     * 获取可见性数据（按关键词分组，含各平台排名）
     *
     * @param  list<int>|null  $platformIds
     * @param  list<int>|null  $keywordIds
     * @return Collection<int, array>
     */
    public function getVisibilityData(?array $platformIds = null, ?array $keywordIds = null, ?int $siteId = null): Collection
    {
        $keywords = BrandKeyword::query()
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->when($keywordIds !== null, fn($q) => $q->whereIn('id', $keywordIds))
            ->active()
            ->get();

        $platforms = BrandPlatform::query()
            ->when($platformIds !== null, fn($q) => $q->whereIn('id', $platformIds))
            ->enabled()
            ->get()
            ->keyBy('code');

        return $keywords->map(function (BrandKeyword $keyword) use ($platforms, $siteId) {
            $platformData = [];

            foreach ($platforms as $code => $platform) {
                $latestResult = BrandMonitorResult::query()
                    ->where('keyword_id', $keyword->id)
                    ->where('platform_id', $platform->id)
                    ->latestDetected()
                    ->first();

                if ($latestResult !== null) {
                    $platformData[$code] = [
                        'rank' => $latestResult->search_rank,
                        'in_top10' => $latestResult->is_in_top10,
                        'visibility_score' => $latestResult->visibility_score,
                    ];
                } else {
                    $platformData[$code] = [
                        'rank' => null,
                        'in_top10' => false,
                        'visibility_score' => 0,
                    ];
                }
            }

            // 计算平均排名和趋势
            $ranks = array_filter(array_column($platformData, 'rank'), fn($r) => $r !== null);
            $avgRank = !empty($ranks) ? round(array_sum($ranks) / count($ranks), 1) : null;

            // 趋势：与前一天对比
            $trend = $this->calculateTrend($keyword->id, $siteId);

            return [
                'keyword_id' => $keyword->id,
                'keyword' => $keyword->keyword,
                'platforms' => $platformData,
                'avg_rank' => $avgRank,
                'trend' => $trend['value'],
                'trend_direction' => $trend['direction'],
            ];
        });
    }

    /**
     * 手动触发一次可见性检测
     *
     * @param  PlatformAdapterInterface[]  $adapters  平台适配器映射 [code => adapter]
     */
    public function refreshVisibility(array $adapters, ?int $siteId = null): int
    {
        $keywords = BrandKeyword::query()
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->active()
            ->get();

        $platforms = BrandPlatform::query()
            ->when($siteId !== null, fn($q) => $q->where('site_id', $siteId))
            ->enabled()
            ->get();

        $count = 0;

        foreach ($keywords as $keyword) {
            foreach ($platforms as $platform) {
                $adapter = $adapters[$platform->code] ?? null;
                if ($adapter === null) {
                    continue;
                }

                $config = $platform->api_config ?? [];
                $result = $adapter->checkKeywordRank($keyword->keyword, $config);

                BrandMonitorResult::create([
                    'site_id' => $siteId,
                    'keyword_id' => $keyword->id,
                    'platform_id' => $platform->id,
                    'search_rank' => $result['rank'],
                    'visibility_score' => $result['visibility_score'],
                    'is_in_top10' => $result['in_top10'],
                    'detected_at' => now(),
                ]);

                $count++;
            }
        }

        return $count;
    }

    /**
     * 计算排名趋势
     *
     * @return array{value: int, direction: string}
     */
    private function calculateTrend(int $keywordId, ?int $siteId = null): array
    {
        $today = BrandMonitorResult::query()
            ->where('keyword_id', $keywordId)
            ->where('detected_at', '>=', now()->startOfDay())
            ->avg('visibility_score');

        $yesterday = BrandMonitorResult::query()
            ->where('keyword_id', $keywordId)
            ->whereBetween('detected_at', [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()])
            ->avg('visibility_score');

        if ($today === null || $yesterday === null) {
            return ['value' => 0, 'direction' => 'flat'];
        }

        $delta = (int) round($today - $yesterday);

        return [
            'value' => abs($delta),
            'direction' => $delta > 0 ? 'up' : ($delta < 0 ? 'down' : 'flat'),
        ];
    }
}
