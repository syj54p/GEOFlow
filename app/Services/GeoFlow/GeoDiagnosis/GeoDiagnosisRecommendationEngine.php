<?php

declare(strict_types=1);

namespace App\Services\GeoFlow\GeoDiagnosis;

/**
 * GEO 诊断建议引擎。
 *
 * 汇总所有检查项的 findings 和 recommendations，
 * 按严重度和影响分数排序，输出最终建议清单。
 */
final class GeoDiagnosisRecommendationEngine
{
    /**
     * 汇总并排序建议。
     *
     * @param  list<array{finding: array, recommendation: array|null}>  $checkOutputs  各检查项输出
     * @return array{findings: list<array>, recommendations: list<array>}
     */
    public function compile(array $checkOutputs): array
    {
        $findings = [];
        $recommendations = [];

        foreach ($checkOutputs as $output) {
            $findings[] = $output['finding'];

            if ($output['recommendation'] !== null) {
                $recommendations[] = $output['recommendation'];
            }
        }

        // 按严重度排序建议
        $severityOrder = ['high' => 0, 'medium' => 1, 'low' => 2, 'info' => 3];
        usort($recommendations, function ($a, $b) use ($severityOrder) {
            // 先按 impact_score 降序
            $scoreDiff = ($b['impact_score'] ?? 0) - ($a['impact_score'] ?? 0);
            if ($scoreDiff !== 0) {
                return $scoreDiff;
            }
            // 再按严重度排序
            $aSev = $severityOrder[$a['severity'] ?? 'low'] ?? 2;
            $bSev = $severityOrder[$b['severity'] ?? 'low'] ?? 2;
            return $aSev - $bSev;
        });

        return [
            'findings' => $findings,
            'recommendations' => $recommendations,
        ];
    }
}
