<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor\PlatformAdapters;

/**
 * 豆包（字节跳动）平台适配器
 */
final class DoubaoAdapter extends BasePlatformAdapter
{
    public function platformCode(): string
    {
        return 'doubao';
    }

    public function platformName(): string
    {
        return '豆包';
    }

    public function checkKeywordRank(string $keyword, array $config = []): array
    {
        // MVP: 使用模拟数据；正式版接入豆包搜索 API
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
