<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GeoDiagnosisRun extends Model
{
    protected $table = 'geo_diagnosis_runs';

    protected $fillable = [
        'article_id',
        'admin_id',
        'total_score',
        'content_structure_score',
        'knowledge_alignment_score',
        'technical_geo_score',
        'ai_visibility_score',
        'status',
        'started_at',
        'completed_at',
        'error_message',
        'findings',
        'recommendations',
    ];

    protected function casts(): array
    {
        return [
            'article_id' => 'integer',
            'admin_id' => 'integer',
            'total_score' => 'integer',
            'content_structure_score' => 'integer',
            'knowledge_alignment_score' => 'integer',
            'technical_geo_score' => 'integer',
            'ai_visibility_score' => 'integer',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'findings' => 'array',
            'recommendations' => 'array',
        ];
    }

    // 状态常量
    public const STATUS_PENDING = 'pending';
    public const STATUS_RUNNING = 'running';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';

    // 评分等级
    public const GRADE_EXCELLENT = 'excellent';   // 80-100
    public const GRADE_GOOD = 'good';              // 60-79
    public const GRADE_FAIR = 'fair';              // 40-59
    public const GRADE_POOR = 'poor';              // 0-39

    public function article(): BelongsTo
    {
        return $this->belongsTo(Article::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    /**
     * 获取评分等级
     */
    public function getGradeAttribute(): string
    {
        return match (true) {
            $this->total_score >= 80 => self::GRADE_EXCELLENT,
            $this->total_score >= 60 => self::GRADE_GOOD,
            $this->total_score >= 40 => self::GRADE_FAIR,
            default => self::GRADE_POOR,
        };
    }

    /**
     * 获取评分等级标签（中文）
     */
    public function getGradeLabelAttribute(): string
    {
        return match ($this->grade) {
            self::GRADE_EXCELLENT => '优秀',
            self::GRADE_GOOD => '良好',
            self::GRADE_FAIR => '一般',
            self::GRADE_POOR => '较差',
        };
    }

    /**
     * 获取评分等级颜色
     */
    public function getGradeColorAttribute(): string
    {
        return match ($this->grade) {
            self::GRADE_EXCELLENT => 'emerald',
            self::GRADE_GOOD => 'blue',
            self::GRADE_FAIR => 'amber',
            self::GRADE_POOR => 'red',
        };
    }

    /**
     * Scope: 仅已完成的诊断
     */
    public function scopeCompleted($query)
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Scope: 按文章最新诊断排序
     */
    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('created_at');
    }
}
