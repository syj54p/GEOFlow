<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * TG-02: Schema.org JSON-LD 完整
 *
 * 检查文章页面是否包含完整的 Schema.org JSON-LD 结构化数据。
 * 结构化数据帮助 AI 引擎理解内容语义。
 */
final class SchemaOrgChecker implements DiagnosisCheckerInterface
{
    private const REQUIRED_FIELDS = ['@context', '@type', 'headline', 'datePublished'];
    private const RECOMMENDED_FIELDS = ['author', 'description', 'image', 'publisher'];

    public function checkId(): string
    {
        return 'TG-02';
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
        // 优先从文章的 schema_org_data 字段获取
        $schemaData = $article->schema_org_data;

        if (empty($schemaData)) {
            // 尝试从文章内容中提取内嵌的 JSON-LD
            $schemaData = $this->extractJsonLdFromContent((string) $article->content);
        }

        if (empty($schemaData)) {
            return [
                'passed' => false,
                'score' => 0,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '缺少 Schema.org 结构化数据',
                    'description' => '文章未包含任何 JSON-LD 结构化数据标记',
                    'severity' => $this->defaultSeverity(),
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => [
                    'id' => $this->checkId(),
                    'title' => '添加 Schema.org JSON-LD',
                    'description' => '结构化数据帮助 AI 引擎理解文章内容和元数据',
                    'impact_score' => 7,
                    'fix_guide' => '在文章页面添加 Article 类型的 JSON-LD，至少包含 @context、@type、headline、datePublished 字段',
                    'auto_fixable' => true,
                ],
            ];
        }

        // 解析 JSON-LD
        $schema = is_string($schemaData) ? json_decode($schemaData, true) : $schemaData;

        if (!is_array($schema)) {
            return [
                'passed' => false,
                'score' => 20,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => 'Schema.org 数据格式错误',
                    'description' => 'JSON-LD 数据无法解析为有效 JSON',
                    'severity' => $this->defaultSeverity(),
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => [
                    'id' => $this->checkId(),
                    'title' => '修复 JSON-LD 格式',
                    'description' => '结构化数据格式错误，AI 引擎无法解析',
                    'impact_score' => 6,
                    'fix_guide' => '确保 JSON-LD 使用标准 JSON 格式，键名使用双引号',
                    'auto_fixable' => true,
                ],
            ];
        }

        // 检查必填字段
        $missingRequired = [];
        foreach (self::REQUIRED_FIELDS as $field) {
            if (!isset($schema[$field]) || empty($schema[$field])) {
                $missingRequired[] = $field;
            }
        }

        // 检查推荐字段
        $missingRecommended = [];
        foreach (self::RECOMMENDED_FIELDS as $field) {
            if (!isset($schema[$field]) || empty($schema[$field])) {
                $missingRecommended[] = $field;
            }
        }

        if (empty($missingRequired) && empty($missingRecommended)) {
            return [
                'passed' => true,
                'score' => 100,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => 'Schema.org 数据完整',
                    'description' => '包含所有必填和推荐字段，类型：' . ($schema['@type'] ?? 'Article'),
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        if (!empty($missingRequired)) {
            $score = max(20, 80 - count($missingRecommended) * 10);
            return [
                'passed' => false,
                'score' => $score,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => 'Schema.org 缺少必填字段',
                    'description' => '缺少必填字段：' . implode(', ', $missingRequired),
                    'severity' => $this->defaultSeverity(),
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => [
                    'id' => $this->checkId(),
                    'title' => '补充必填字段',
                    'description' => 'Schema.org 必填字段缺失会影响 AI 引擎解析',
                    'impact_score' => 7,
                    'fix_guide' => '在 JSON-LD 中添加：' . implode(', ', $missingRequired),
                    'auto_fixable' => true,
                ],
            ];
        }

        // 仅缺推荐字段
        return [
            'passed' => true,
            'score' => 80,
            'finding' => [
                'id' => $this->checkId(),
                'title' => 'Schema.org 基本完整',
                'description' => '必填字段齐全，建议补充：' . implode(', ', $missingRecommended),
                'severity' => 'info',
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '补充推荐字段',
                'description' => '添加更多字段可提升 AI 引擎对内容的理解',
                'impact_score' => 3,
                'fix_guide' => '建议添加：' . implode(', ', $missingRecommended),
                'auto_fixable' => true,
            ],
        ];
    }

    /**
     * 从 HTML 内容中提取 JSON-LD
     */
    private function extractJsonLdFromContent(string $content): ?array
    {
        if (preg_match('/<script[^>]*type=["\']application\/ld\+json["\'][^>]*>(.*?)<\/script>/is', $content, $matches)) {
            $decoded = json_decode(trim($matches[1]), true);
            return is_array($decoded) ? $decoded : null;
        }
        return null;
    }
}
