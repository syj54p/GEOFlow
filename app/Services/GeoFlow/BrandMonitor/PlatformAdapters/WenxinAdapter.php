<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor\PlatformAdapters;

/**
 * 文心一言（百度）平台适配器
 */
final class WenxinAdapter extends BasePlatformAdapter
{
    public function platformCode(): string
    {
        return 'wenxin';
    }

    public function platformName(): string
    {
        return '文心一言';
    }

    public function checkKeywordRank(string $keyword, array $config = []): array
    {
        return $this->simulateRankData($keyword);
    }

    public function analyzeSources(string $brandName, array $config = []): array
    {
        return $this->simulateSourceData($brandName);
    }

    public function analyzeSentiment(string $brandName, array $config = []): array
    {
        return $this->simulateSentimentData($brandName);
    }
}
