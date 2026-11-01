<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * TG-03: canonical 链接正确
 *
 * 检查文章是否有正确的 canonical 链接，避免 AI 引擎将同一内容视为重复页面。
 */
final class CanonicalLinkChecker implements DiagnosisCheckerInterface
{
    public function checkId(): string
    {
        return 'TG-03';
    }

    public function dimension(): string
    {
        return 'technical_geo';
    }

    public function defaultSeverity(): string
    {
        return 'medium';
    }

    public function check(Article $article, array $context = []): array
    {
        $siteUrl = $context['site_settings']['site_url'] ?? config('app.url', '');
        $content = (string) $article->content;

        // 检查 HTML 中是否有 canonical 标签
        $hasCanonical = (bool) preg_match('/<link[^>]*rel=["\']canonical["\'][^>]*>/i', $content);

        // 检查 Schema.org 数据中是否有 canonical 或 url 字段
        $schemaData = $article->schema_org_data;
        $hasSchemaUrl = false;
        if (is_array($schemaData) && !empty($schemaData['url'])) {
            $hasSchemaUrl = true;
        } elseif (is_string($schemaData)) {
            $decoded = json_decode($schemaData, true);
            $hasSchemaUrl = is_array($decoded) && !empty($decoded['url']);
        }

        // 检查文章 slug 是否有效
        $hasValidSlug = !empty($article->slug) && preg_match('/^[a-z0-9]+(?:-[a-z0-9]+)*$/', (string) $article->slug);

        if ($hasCanonical || $hasSchemaUrl) {
            $score = 100;
            if (!$hasValidSlug) {
                $score = 80; // slug 不规范扣分
            }

            return [
                'passed' => true,
                'score' => $score,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => 'Canonical 链接配置正确',
                    'description' => $hasCanonical
                        ? 'HTML 中包含 canonical 标签'
                        : 'Schema.org 中包含 URL 字段',
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => $hasValidSlug ? null : [
                    'id' => $this->checkId(),
                    'title' => '规范化 URL slug',
                    'description' => '文章 slug 包含不规范字符',
                    'impact_score' => 3,
                    'fix_guide' => '使用小写字母、数字和连字符组成 slug，如 "geo-optimization-guide"',
                    'auto_fixable' => true,
                ],
            ];
        }

        // 没有 canonical 链接
        $expectedCanonical = rtrim($siteUrl, '/') . '/articles/' . ($article->slug ?: $article->id);

        return [
            'passed' => false,
            'score' => 20,
            'finding' => [
                'id' => $this->checkId(),
                'title' => '缺少 Canonical 链接',
                'description' => '文章未设置 canonical 链接，可能导致 AI 引擎将同一内容视为重复页面',
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '添加 Canonical 链接',
                'description' => 'Canonical 链接告诉 AI 引擎哪个 URL 是内容的权威版本',
                'impact_score' => 6,
                'fix_guide' => "在文章页面的 <head> 中添加：<link rel=\"canonical\" href=\"{$expectedCanonical}\" />",
                'auto_fixable' => true,
            ],
        ];
    }
}
