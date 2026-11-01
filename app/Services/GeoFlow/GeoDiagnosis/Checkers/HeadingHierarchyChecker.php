<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * CS-02: 标题层级无跳跃（H1→H2→H3）
 *
 * 检查文章的标题层级是否连续，不存在跳跃（如 H1 直接到 H3）。
 * 结构化的标题层级帮助 AI 引擎理解内容层次。
 */
final class HeadingHierarchyChecker implements DiagnosisCheckerInterface
{
    public function checkId(): string
    {
        return 'CS-02';
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

        // 提取所有标题标签及其层级
        preg_match_all('/<h([1-6])[\s>]/i', $content, $matches);
        $levels = array_map('intval', $matches[1] ?? []);

        if (empty($levels)) {
            return [
                'passed' => false,
                'score' => 0,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '未检测到任何标题标签',
                    'description' => '文章内容没有任何 H1-H6 标题，结构扁平',
                    'severity' => $this->defaultSeverity(),
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => [
                    'id' => $this->checkId(),
                    'title' => '添加结构化标题层级',
                    'description' => '为文章添加 H1（主题）→ H2（章节）→ H3（子章节）的标题结构',
                    'impact_score' => 7,
                    'fix_guide' => '使用 H1 作为文章主标题，H2 划分主要章节，H3 细分章节内容',
                    'auto_fixable' => false,
                ],
            ];
        }

        // 检查层级跳跃
        $jumps = [];
        for ($i = 1; $i < count($levels); $i++) {
            $diff = $levels[$i] - $levels[$i - 1];
            if ($diff > 1) {
                $jumps[] = "H{$levels[$i - 1]} → H{$levels[$i]}";
            }
        }

        $passed = empty($jumps);

        if ($passed) {
            return [
                'passed' => true,
                'score' => 100,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '标题层级连续',
                    'description' => '标题层级从 H' . min($levels) . ' 到 H' . max($levels) . '，无跳跃',
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        return [
            'passed' => false,
            'score' => max(20, 100 - count($jumps) * 25),
            'finding' => [
                'id' => $this->checkId(),
                'title' => '标题层级存在跳跃',
                'description' => '发现 ' . count($jumps) . ' 处层级跳跃：' . implode(', ', array_slice($jumps, 0, 3)),
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '修复标题层级跳跃',
                'description' => '标题层级应逐级递进，不应跳过中间层级',
                'impact_score' => 5,
                'fix_guide' => '将跳跃的标题降级或补充中间层级标题。例如 H1 后直接跟 H2，H2 后再跟 H3',
                'auto_fixable' => false,
            ],
        ];
    }
}
