<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * KA-02: 核心术语出现频次 ≥ 3 次
 *
 * 检查文章标题和元数据中的核心术语是否在正文中充分出现。
 * 足够的术语频次帮助 AI 引擎确认内容主题相关性。
 */
final class KeywordFrequencyChecker implements DiagnosisCheckerInterface
{
    private const MIN_FREQUENCY = 3;

    public function checkId(): string
    {
        return 'KA-02';
    }

    public function dimension(): string
    {
        return 'knowledge_alignment';
    }

    public function defaultSeverity(): string
    {
        return 'medium';
    }

    public function check(Article $article, array $context = []): array
    {
        $keywords = $this->extractCoreKeywords($article);

        if (empty($keywords)) {
            return [
                'passed' => false,
                'score' => 30,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '无法提取核心术语',
                    'description' => '文章标题、关键词字段和元描述中未提取到有效术语',
                    'severity' => $this->defaultSeverity(),
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => [
                    'id' => $this->checkId(),
                    'title' => '设置文章关键词',
                    'description' => '为核心主题设置明确的关键词标签',
                    'impact_score' => 5,
                    'fix_guide' => '在文章的"关键词"字段中添加 3-5 个核心术语',
                    'auto_fixable' => false,
                ],
            ];
        }

        $content = mb_strtolower(strip_tags((string) $article->content));
        $missingKeywords = [];
        $lowFrequencyKeywords = [];
        $totalChecked = count($keywords);
        $passedCount = 0;

        foreach ($keywords as $keyword) {
            $keywordLower = mb_strtolower($keyword);
            $freq = mb_substr_count($content, $keywordLower);

            if ($freq === 0) {
                $missingKeywords[] = $keyword;
            } elseif ($freq < self::MIN_FREQUENCY) {
                $lowFrequencyKeywords[] = "{$keyword}（{$freq}次）";
            } else {
                $passedCount++;
            }
        }

        $passed = empty($missingKeywords) && empty($lowFrequencyKeywords);

        if ($passed) {
            return [
                'passed' => true,
                'score' => 100,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '核心术语频次充足',
                    'description' => "{$totalChecked} 个核心术语均在正文中出现 ≥ " . self::MIN_FREQUENCY . ' 次',
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        $issues = [];
        if (!empty($missingKeywords)) {
            $issues[] = '缺失：' . implode('、', $missingKeywords);
        }
        if (!empty($lowFrequencyKeywords)) {
            $issues[] = '频次不足：' . implode('、', $lowFrequencyKeywords);
        }

        $score = (int) round(($passedCount / max(1, $totalChecked)) * 100);

        return [
            'passed' => false,
            'score' => $score,
            'finding' => [
                'id' => $this->checkId(),
                'title' => '核心术语频次不足',
                'description' => implode('；', $issues),
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '增加核心术语出现频次',
                'description' => '确保核心术语在正文中自然出现至少 ' . self::MIN_FREQUENCY . ' 次',
                'impact_score' => 5,
                'fix_guide' => '在文章开头、小标题和结尾处自然融入核心术语，避免堆砌',
                'auto_fixable' => false,
            ],
        ];
    }

    /**
     * 从文章标题、关键词和元描述中提取核心术语
     *
     * @return list<string>
     */
    private function extractCoreKeywords(Article $article): array
    {
        $keywords = [];

        // 从 keywords 字段提取
        if (!empty($article->keywords)) {
            $parts = preg_split('/[,，;；\s]+/u', (string) $article->keywords);
            foreach ($parts as $part) {
                $part = trim($part);
                if (mb_strlen($part) >= 2) {
                    $keywords[] = $part;
                }
            }
        }

        // 从 original_keyword 提取
        if (!empty($article->original_keyword) && mb_strlen((string) $article->original_keyword) >= 2) {
            $keywords[] = (string) $article->original_keyword;
        }

        // 从标题提取（取 2-6 字的中文词组）
        if (!empty($article->title)) {
            preg_match_all('/[\x{4e00}-\x{9fff}]{2,6}/u', (string) $article->title, $matches);
            foreach ($matches[0] as $match) {
                $keywords[] = $match;
            }
        }

        return array_values(array_unique(array_slice($keywords, 0, 10)));
    }
}
