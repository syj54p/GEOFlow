<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BrandActivityLog extends Model
{
    protected $table = 'brand_activity_log';

    public $timestamps = false;

    protected $fillable = [
        'site_id',
        'activity_type',
        'title',
        'description',
        'related_id',
        'related_type',
        'severity',
        'created_at',
    ];

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'related_id' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    // 活动类型
    public const TYPE_DIAGNOSIS_COMPLETED = 'diagnosis_completed';
    public const TYPE_BRAND_RANK_CHANGE = 'brand_rank_change';
    public const TYPE_COMPETITOR_ALERT = 'competitor_alert';
    public const TYPE_SENTIMENT_ALERT = 'sentiment_alert';

    // 严重级别
    public const SEVERITY_INFO = 'info';
    public const SEVERITY_WARNING = 'warning';
    public const SEVERITY_ALERT = 'alert';

    /**
     * Scope: 按时间倒序
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('created_at');
    }

    /**
     * Scope: 指定站点
     */
    public function scopeForSite($query, ?int $siteId)
    {
        return $query->where('site_id', $siteId);
    }

    /**
     * Scope: 最近 N 天
     */
    public function scopeRecent($query, int $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * 获取活动类型的图标
     */
    public function getIconAttribute(): string
    {
        return match ($this->activity_type) {
            self::TYPE_DIAGNOSIS_COMPLETED => 'clipboard-check',
            self::TYPE_BRAND_RANK_CHANGE => 'trending-up',
            self::TYPE_COMPETITOR_ALERT => 'alert-triangle',
            self::TYPE_SENTIMENT_ALERT => 'message-circle-warning',
            default => 'info',
        };
    }

    /**
     * 获取严重级别的颜色
     */
    public function getSeverityColorAttribute(): string
    {
        return match ($this->severity) {
            self::SEVERITY_INFO => 'blue',
            self::SEVERITY_WARNING => 'amber',
            self::SEVERITY_ALERT => 'red',
            default => 'gray',
        };
    }
}
