<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BrandMonitorResult extends Model
{
    protected $table = 'brand_monitor_results';

    protected $fillable = [
        'site_id',
        'keyword_id',
        'platform_id',
        'search_rank',
        'visibility_score',
        'is_in_top10',
        'source_analysis',
        'sentiment_analysis',
        'detected_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'keyword_id' => 'integer',
            'platform_id' => 'integer',
            'search_rank' => 'integer',
            'visibility_score' => 'decimal:2',
            'is_in_top10' => 'boolean',
            'source_analysis' => 'array',
            'sentiment_analysis' => 'array',
            'detected_at' => 'datetime',
        ];
    }

    public function keyword(): BelongsTo
    {
        return $this->belongsTo(BrandKeyword::class, 'keyword_id');
    }

    public function platform(): BelongsTo
    {
        return $this->belongsTo(BrandPlatform::class, 'platform_id');
    }

    /**
     * Scope: 按检测时间倒序
     */
    public function scopeLatestDetected($query)
    {
        return $query->orderByDesc('detected_at');
    }

    /**
     * Scope: 指定时间段
     */
    public function scopeInPeriod($query, string $period = '7d')
    {
        $days = match ($period) {
            '1d' => 1,
            '7d' => 7,
            '30d' => 30,
            '90d' => 90,
            default => 7,
        };
        return $query->where('detected_at', '>=', now()->subDays($days));
    }

    /**
     * 获取信源分析中的品牌引用占比
     */
    public function getBrandQuotePctAttribute(): float
    {
        return (float) ($this->source_analysis['brand_quote_pct'] ?? 0);
    }

    /**
     * 获取舆情分析中的正面占比
     */
    public function getPositivePctAttribute(): float
    {
        return (float) ($this->sentiment_analysis['positive_pct'] ?? 0);
    }

    /**
     * 获取舆情分析中的负面占比
     */
    public function getNegativePctAttribute(): float
    {
        return (float) ($this->sentiment_analysis['negative_pct'] ?? 0);
    }
}
