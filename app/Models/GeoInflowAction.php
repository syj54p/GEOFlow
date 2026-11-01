<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoInflowAction extends Model
{
    protected $table = 'geo_inflow_actions';

    protected $fillable = [
        'article_id',
        'action_type',
        'trigger_source',
        'severity_score',
        'reasons',
        'context',
        'status',
        'result_message',
        'executed_at',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'severity_score' => 'integer',
            'reasons' => 'array',
            'context' => 'array',
            'executed_at' => 'datetime',
        ];
    }

    // 动作类型常量
    public const ACTION_REWRITE_HEAVY = 'rewrite_heavy';
    public const ACTION_REWRITE_MEDIUM = 'rewrite_medium';
    public const ACTION_REWRITE_LIGHT = 'rewrite_light';
    public const ACTION_REDISTRIBUTE = 'redistribute';
    public const ACTION_PAUSE_CHANNEL = 'pause_channel';
    public const ACTION_OPTIMIZE_METADATA = 'optimize_metadata';

    // 触发来源常量
    public const TRIGGER_VISIBILITY = 'visibility';
    public const TRIGGER_DISTRIBUTION = 'distribution';
    public const TRIGGER_TRAFFIC = 'traffic';
    public const TRIGGER_QUALITY_TREND = 'quality_trend';
    public const TRIGGER_MANUAL = 'manual';

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_EXECUTING = 'executing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    /**
     * 关联文章。
     */
    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    /**
     * 获取动作类型的中文标签。
     */
    public function getActionTypeLabel(): string
    {
        return match ($this->action_type) {
            self::ACTION_REWRITE_HEAVY => '重改写',
            self::ACTION_REWRITE_MEDIUM => '中改写',
            self::ACTION_REWRITE_LIGHT => '轻改写',
            self::ACTION_REDISTRIBUTE => '重新分发',
            self::ACTION_PAUSE_CHANNEL => '暂停渠道',
            self::ACTION_OPTIMIZE_METADATA => '优化元数据',
            default => $this->action_type,
        };
    }

    /**
     * 获取触发来源的中文标签。
     */
    public function getTriggerSourceLabel(): string
    {
        return match ($this->trigger_source) {
            self::TRIGGER_VISIBILITY => 'AI 可见度',
            self::TRIGGER_DISTRIBUTION => '分发失败',
            self::TRIGGER_TRAFFIC => '流量不足',
            self::TRIGGER_QUALITY_TREND => '质量下降',
            self::TRIGGER_MANUAL => '手动触发',
            default => $this->trigger_source,
        };
    }

    /**
     * 获取状态的中文标签。
     */
    public function getStatusLabel(): string
    {
        return match ($this->status) {
            self::STATUS_PENDING => '待执行',
            self::STATUS_EXECUTING => '执行中',
            self::STATUS_COMPLETED => '已完成',
            self::STATUS_FAILED => '失败',
            self::STATUS_CANCELLED => '已取消',
            default => $this->status,
        };
    }
}
