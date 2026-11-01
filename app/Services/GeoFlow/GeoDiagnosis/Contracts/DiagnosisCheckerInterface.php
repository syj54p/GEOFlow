<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis\Contracts;

use App\Models\Article;

/**
 * 诊断检查项接口。
 *
 * 每个检查项实现此接口，针对一篇文章执行单项 GEO 就绪度检查，
 * 返回 findings（检查结果）和 recommendations（改进建议）。
 */
interface DiagnosisCheckerInterface
{
    /**
     * 检查项唯一标识（如 CS-01, KA-01, TG-01, AV-01）
     */
    public function checkId(): string;

    /**
     * 所属维度（content_structure / knowledge_alignment / technical_geo / ai_visibility）
     */
    public function dimension(): string;

    /**
     * 默认严重度（high / medium / low）
     */
    public function defaultSeverity(): string;

    /**
     * 执行检查。
     *
     * @param  Article  $article    待检查文章
     * @param  array    $context    额外上下文（知识库数据、品牌监测数据等）
     * @return array{
     *     passed: bool,
     *     score: int,
     *     finding: array{id: string, title: string, description: string, severity: string, dimension: string},
     *     recommendation: array{id: string, title: string, description: string, impact_score: int, fix_guide: string, auto_fixable: bool}|null
     * }
     */
    public function check(Article $article, array $context = []): array;
}
