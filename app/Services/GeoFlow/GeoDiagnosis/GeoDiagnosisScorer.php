<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis;

use App\Models\GeoDiagnosisSetting;

/**
 * GEO 诊断评分器。
 *
 * 将各检查器的原始分数按维度权重加权汇总为总分（0-100）。
 */
final class GeoDiagnosisScorer
{
    /**
     * 计算总分和各维度分数。
     *
     * @param  array<string, list<array{passed: bool, score: int, dimension: string}>>  $checkResults  按维度分组的检查结果
     * @param  GeoDiagnosisSetting  $settings  诊断设置（含权重配置）
     * @return array{
     *     total_score: int,
     *     content_structure_score: int,
     *     knowledge_alignment_score: int,
     *     technical_geo_score: int,
     *     ai_visibility_score: int
     * }
     */
    public function score(array $checkResults, GeoDiagnosisSetting $settings): array
    {
        $dimensionScores = [];

        foreach (['content_structure', 'knowledge_alignment', 'technical_geo', 'ai_visibility'] as $dim) {
            $results = $checkResults[$dim] ?? [];
            if (empty($results)) {
                $dimensionScores[$dim] = 0;
                continue;
            }

            // 维度内取各检查项分数的平均值
            $scores = array_column($results, 'score');
            $dimensionScores[$dim] = (int) round(array_sum($scores) / count($scores));
        }

        // 按权重计算总分
        $weights = $settings->dimension_weights ?? GeoDiagnosisSetting::DEFAULT_WEIGHTS;
        $totalScore = 0;
        foreach ($dimensionScores as $dim => $score) {
            $weight = (float) ($weights[$dim] ?? 0.25);
            $totalScore += $score * $weight;
        }

        return [
            'total_score' => (int) round($totalScore),
            'content_structure_score' => $dimensionScores['content_structure'],
            'knowledge_alignment_score' => $dimensionScores['knowledge_alignment'],
            'technical_geo_score' => $dimensionScores['technical_geo'],
            'ai_visibility_score' => $dimensionScores['ai_visibility'],
        ];
    }
}
