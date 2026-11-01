<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * CS-01: H1 标题存在且唯一
 *
 * 检查文章正文中是否有且仅有一个 <h1> 标签。
 * AI 搜索引擎依赖 H1 提取文章核心主题。
 */
final class H1TitleChecker implements DiagnosisCheckerInterface
{
    public function checkId(): string
    {
        return 'CS-01';
    }

    public function dimension(): string
    {
        return 'content_structure';
    }

    public function defaultSeverity(): string
    {
        return 'high';
    }

    public function check(Article $article, array $context = []): array
    {
        $content = (string) $article->content;
        $h1Count = preg_match_all('/<h1[\s>]/i', $content);

        $passed = $h1Count === 1;

        if ($passed) {
            return [
                'passed' => true,
                'score' => 100,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => 'H1 标题存在且唯一',
                    'description' => '文章包含 1 个 H1 标签，符合 GEO 规范',
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        $h1Count = max(0, $h1Count);
        $description = $h1Count === 0
            ? '文章正文未检测到 H1 标签，AI 引擎难以提取核心主题'
            : "文章包含 {$h1Count} 个 H1 标签，应确保唯一";

        return [
            'passed' => false,
            'score' => $h1Count === 0 ? 0 : 40,
            'finding' => [
                'id' => $this->checkId(),
                'title' => $h1Count === 0 ? '缺少 H1 标题' : 'H1 标题不唯一',
                'description' => $description,
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => $h1Count === 0 ? '添加 H1 标题' : '合并为唯一 H1 标题',
                'description' => $description,
                'impact_score' => 8,
                'fix_guide' => $h1Count === 0
                    ? '在文章开头添加一个 H1 标题，概括文章核心主题（如"什么是 GEO？生成式引擎优化完全指南"）'
                    : '保留一个最能概括文章主题的 H1，其余降级为 H2 或 H3',
                'auto_fixable' => false,
            ],
        ];
    }
}
