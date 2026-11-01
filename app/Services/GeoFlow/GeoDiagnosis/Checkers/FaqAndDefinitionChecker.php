<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * CS-04: 包含 FAQ 或定义性语句
 *
 * 检查文章是否包含 FAQ 结构或定义性语句（如"什么是..."、"...是指"）。
 * AI 引擎倾向引用这类直接回答用户问题的内容。
 */
final class FaqAndDefinitionChecker implements DiagnosisCheckerInterface
{
    public function checkId(): string
    {
        return 'CS-04';
    }

    public function dimension(): string
    {
        return 'content_structure';
    }

    public function defaultSeverity(): string
    {
        return 'medium';
    }

    public function check(Article $article, array $context = []): array
    {
        $content = (string) $article->content;
        $textContent = strip_tags($content);

        // 检查 FAQ 结构
        $hasFaqStructure = $this->detectFaqStructure($content);

        // 检查定义性语句
        $hasDefinition = $this->detectDefinition($textContent);

        // 检查问答模式
        $hasQuestionAnswer = $this->detectQuestionAnswer($textContent);

        $passed = $hasFaqStructure || $hasDefinition || $hasQuestionAnswer;

        if ($passed) {
            $features = [];
            if ($hasFaqStructure) $features[] = 'FAQ 结构';
            if ($hasDefinition) $features[] = '定义性语句';
            if ($hasQuestionAnswer) $features[] = '问答模式';

            return [
                'passed' => true,
                'score' => 100,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '包含 AI 友好结构',
                    'description' => '检测到：' . implode('、', $features),
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        return [
            'passed' => false,
            'score' => 20,
            'finding' => [
                'id' => $this->checkId(),
                'title' => '缺少 FAQ 或定义性内容',
                'description' => '文章未包含 FAQ 结构、定义性语句或问答模式，降低被 AI 引用的概率',
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '添加 FAQ 或定义段落',
                'description' => 'AI 引擎偏好直接回答用户问题的内容结构',
                'impact_score' => 6,
                'fix_guide' => '在文章中添加"什么是 XXX？"定义段落，或在末尾添加 FAQ 部分（如"常见问题"），用 H2/H3 标题 + 简洁回答的格式',
                'auto_fixable' => false,
            ],
        ];
    }

    private function detectFaqStructure(string $html): bool
    {
        // 检查 FAQ schema 标记
        if (stripos($html, '"@type": "FAQPage"') !== false) {
            return true;
        }

        // 检查常见 FAQ 标题模式
        $faqPatterns = ['/常见(问题|问答)/u', '/FAQ/i', '/Q\s*[:：]/', '/问\s*[:：]/'];
        foreach ($faqPatterns as $pattern) {
            if (preg_match($pattern, $html)) {
                return true;
            }
        }

        return false;
    }

    private function detectDefinition(string $text): bool
    {
        $defPatterns = [
            '/什么是.{2,30}[？?]/u',
            '/.{2,30}是指/u',
            '/.{2,30}定义为/u',
            '/.{2,30}即/u',
            '/所谓.{2,30}/u',
        ];

        foreach ($defPatterns as $pattern) {
            if (preg_match($pattern, $text)) {
                return true;
            }
        }

        return false;
    }

    private function detectQuestionAnswer(string $text): bool
    {
        // 检查问句 + 紧跟的回答模式
        $qaPattern = '/[^。！？\n]*[？?][^。！？\n]*[。！？][^。！？\n]{10,100}[。！？]/u';
        return (bool) preg_match($qaPattern, $text);
    }
}
