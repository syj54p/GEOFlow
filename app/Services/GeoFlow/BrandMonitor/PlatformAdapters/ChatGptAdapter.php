<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\BrandMonitor\PlatformAdapters;

/**
 * ChatGPT（OpenAI）平台适配器
 */
final class ChatGptAdapter extends BasePlatformAdapter
{
    public function platformCode(): string
    {
        return 'chatgpt';
    }

    public function platformName(): string
    {
        return 'ChatGPT';
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
