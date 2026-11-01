<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor\PlatformAdapters;

/**
 * Google AI Overview 平台适配器
 */
final class GoogleAiAdapter extends BasePlatformAdapter
{
    public function platformCode(): string
    {
        return 'google_ai';
    }

    public function platformName(): string
    {
        return 'Google AI Overview';
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
