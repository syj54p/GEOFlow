<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class GeoDiagnosisSetting extends Model
{
    protected $table = 'geo_diagnosis_settings';

    protected $fillable = [
        'site_id',
        'dimension_weights',
        'enabled_checks',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'dimension_weights' => 'array',
            'enabled_checks' => 'array',
        ];
    }

    // 默认维度权重
    public const DEFAULT_WEIGHTS = [
        'content_structure' => 0.25,
        'knowledge_alignment' => 0.30,
        'technical_geo' => 0.20,
        'ai_visibility' => 0.25,
    ];

    // 全部检查项
    public const ALL_CHECKS = [
        'CS-01', 'CS-02', 'CS-03', 'CS-04',
        'KA-01', 'KA-02', 'KA-03',
        'TG-01', 'TG-02', 'TG-03',
        'AV-01', 'AV-02',
    ];

    /**
     * 获取指定维度的权重
     */
    public function getWeight(string $dimension): float
    {
        $weights = $this->dimension_weights ?? self::DEFAULT_WEIGHTS;
        return (float) ($weights[$dimension] ?? 0.25);
    }

    /**
     * 检查某个检查项是否启用
     */
    public function isCheckEnabled(string $checkId): bool
    {
        $enabled = $this->enabled_checks ?? self::ALL_CHECKS;
        return in_array($checkId, $enabled, true);
    }

    /**
     * 获取或创建设置（单例模式）
     */
    public static function getForSite(?int $siteId = null): self
    {
        return self::firstOrCreate(
            ['site_id' => $siteId],
            [
                'dimension_weights' => self::DEFAULT_WEIGHTS,
                'enabled_checks' => self::ALL_CHECKS,
            ]
        );
    }
}
