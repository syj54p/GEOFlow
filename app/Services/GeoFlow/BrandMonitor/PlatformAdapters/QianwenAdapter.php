<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor\PlatformAdapters;

/**
 * 通义千问（阿里云）平台适配器
 */
final class QianwenAdapter extends BasePlatformAdapter
{
    public function platformCode(): string
    {
        return 'qianwen';
    }

    public function platformName(): string
    {
        return '通义千问';
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
