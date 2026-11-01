<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor\PlatformAdapters;

/**
 * Kimi（月之暗面）平台适配器
 */
final class KimiAdapter extends BasePlatformAdapter
{
    public function platformCode(): string
    {
        return 'kimi';
    }

    public function platformName(): string
    {
        return 'Kimi';
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
