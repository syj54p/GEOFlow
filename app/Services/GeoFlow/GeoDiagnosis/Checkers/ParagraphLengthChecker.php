<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * CS-03: 段落平均长度 ≤ 200 字
 *
 * 检查文章段落是否过长。过长的段落降低可读性，
 * AI 引擎更倾向引用结构清晰、段落适中的内容。
 */
final class ParagraphLengthChecker implements DiagnosisCheckerInterface
{
    private const MAX_AVG_LENGTH = 200;
    private const MAX_SINGLE_LENGTH = 500;

    public function checkId(): string
    {
        return 'CS-03';
    }

    public function dimension(): string
    {
        return 'content_structure';
    }

    public function defaultSeverity(): string
    {
        return 'low';
    }

    public function check(Article $article, array $context = []): array
    {
        $content = strip_tags((string) $article->content);

        // 按段落分割（连续换行或 <p> 标签）
        $paragraphs = preg_split('/\n\s*\n|<\/p>\s*<p>/i', $content);
        $paragraphs = array_filter(array_map('trim', $paragraphs), fn($p) => mb_strlen($p) > 0);

        if (empty($paragraphs)) {
            return [
                'passed' => false,
                'score' => 0,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '无法解析段落',
                    'description' => '文章内容无法分割为有效段落',
                    'severity' => $this->defaultSeverity(),
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => [
                    'id' => $this->checkId(),
                    'title' => '检查文章内容格式',
                    'description' => '确保文章内容使用标准段落格式',
                    'impact_score' => 3,
                    'fix_guide' => '使用段落标签或空行分隔内容段落',
                    'auto_fixable' => false,
                ],
            ];
        }

        $lengths = array_map('mb_strlen', $paragraphs);
        $avgLength = (int) round(array_sum($lengths) / count($lengths));
        $maxParagraphLength = max($lengths);
        $longParagraphs = array_filter($lengths, fn($l) => $l > self::MAX_SINGLE_LENGTH);

        $passed = $avgLength <= self::MAX_AVG_LENGTH && empty($longParagraphs);

        if ($passed) {
            return [
                'passed' => true,
                'score' => 100,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '段落长度合理',
                    'description' => "平均段落 {$avgLength} 字，最长 {$maxParagraphLength} 字",
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        $issues = [];
        if ($avgLength > self::MAX_AVG_LENGTH) {
            $issues[] = "平均段落长度 {$avgLength} 字（建议 ≤ " . self::MAX_AVG_LENGTH . ' 字）';
        }
        if (!empty($longParagraphs)) {
            $issues[] = count($longParagraphs) . ' 个段落超过 ' . self::MAX_SINGLE_LENGTH . ' 字';
        }

        return [
            'passed' => false,
            'score' => max(30, 100 - (int) (($avgLength - self::MAX_AVG_LENGTH) / 10) - count($longParagraphs) * 10),
            'finding' => [
                'id' => $this->checkId(),
                'title' => '段落过长',
                'description' => implode('；', $issues),
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '拆分过长段落',
                'description' => 'AI 引擎偏好结构清晰、段落适中的内容',
                'impact_score' => 4,
                'fix_guide' => '将超过 200 字的段落拆分为 2-3 个短段落，每段聚焦一个要点',
                'auto_fixable' => false,
            ],
        ];
    }
}
