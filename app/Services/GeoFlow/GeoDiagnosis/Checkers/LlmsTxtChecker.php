<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Checkers;

use App\Models\Article;
use App\Services\GeoFlow\GeoDiagnosis\Contracts\DiagnosisCheckerInterface;

/**
 * TG-01: llms.txt 可达且包含本站点
 *
 * 检查站点是否配置了 llms.txt 文件，并且文件中包含当前站点的信息。
 * llms.txt 是 AI 引擎发现和理解站点内容的重要入口。
 */
final class LlmsTxtChecker implements DiagnosisCheckerInterface
{
    public function checkId(): string
    {
        return 'TG-01';
    }

    public function dimension(): string
    {
        return 'technical_geo';
    }

    public function defaultSeverity(): string
    {
        return 'high';
    }

    public function check(Article $article, array $context = []): array
    {
        $siteSettings = $context['site_settings'] ?? null;
        $siteUrl = $siteSettings['site_url'] ?? config('app.url', '');

        if (empty($siteUrl)) {
            return [
                'passed' => false,
                'score' => 0,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => '站点 URL 未配置',
                    'description' => '无法检查 llms.txt，请先在站点设置中配置 URL',
                    'severity' => $this->defaultSeverity(),
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => [
                    'id' => $this->checkId(),
                    'title' => '配置站点 URL',
                    'description' => 'llms.txt 检查需要站点 URL',
                    'impact_score' => 7,
                    'fix_guide' => '在"系统设置 → 站点设置"中填写完整的站点 URL（如 https://example.com）',
                    'auto_fixable' => true,
                ],
            ];
        }

        // 检查 llms.txt 是否可达
        $llmsTxtUrl = rtrim($siteUrl, '/') . '/llms.txt';
        $reachable = $this->checkUrl($llmsTxtUrl);

        if (!$reachable) {
            return [
                'passed' => false,
                'score' => 0,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => 'llms.txt 不可达',
                    'description' => "{$llmsTxtUrl} 无法访问，AI 引擎无法通过此文件发现站点内容",
                    'severity' => $this->defaultSeverity(),
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => [
                    'id' => $this->checkId(),
                    'title' => '创建 llms.txt 文件',
                    'description' => 'llms.txt 是 AI 引擎发现站点内容的标准入口',
                    'impact_score' => 9,
                    'fix_guide' => "在站点根目录创建 llms.txt 文件，包含站点名称、描述和主要内容链接。参考格式：\n# Site Name\n> Description\n\n- [Article Title](/path/to/article)",
                    'auto_fixable' => true,
                ],
            ];
        }

        // 检查 llms.txt 内容是否包含站点信息
        $content = @file_get_contents($llmsTxtUrl);
        $hasSiteInfo = $content !== false && (
            stripos($content, parse_url($siteUrl, PHP_URL_HOST) ?: '') !== false
            || mb_strlen(trim($content)) > 50
        );

        if ($hasSiteInfo) {
            return [
                'passed' => true,
                'score' => 100,
                'finding' => [
                    'id' => $this->checkId(),
                    'title' => 'llms.txt 可达且有效',
                    'description' => "{$llmsTxtUrl} 可正常访问，包含站点信息",
                    'severity' => 'info',
                    'dimension' => $this->dimension(),
                ],
                'recommendation' => null,
            ];
        }

        return [
            'passed' => false,
            'score' => 40,
            'finding' => [
                'id' => $this->checkId(),
                'title' => 'llms.txt 内容不完整',
                'description' => 'llms.txt 文件存在但内容过少或未包含站点信息',
                'severity' => $this->defaultSeverity(),
                'dimension' => $this->dimension(),
            ],
            'recommendation' => [
                'id' => $this->checkId(),
                'title' => '补充 llms.txt 内容',
                'description' => 'llms.txt 应包含站点名称、描述和主要页面链接',
                'impact_score' => 6,
                'fix_guide' => '在 llms.txt 中添加站点名称（# 开头）、描述（> 开头）和核心页面链接列表',
                'auto_fixable' => true,
            ],
        ];
    }

    private function checkUrl(string $url): bool
    {
        $headers = @get_headers($url);
        if ($headers === false) {
            return false;
        }
        $statusLine = $headers[0] ?? '';
        return (bool) preg_match('/\s(200|301|302)\s/', $statusLine);
    }
}
