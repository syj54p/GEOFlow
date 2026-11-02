<?php

declare(strict_types=1);

namespace App\Services\SkillIntegration;

/**
 * 技能执行结果
 *
 * 封装 yao-meta-skill 技能的执行输出，包含结果数据、状态和元信息
 */
class SkillResult
{
    public function __construct(
        protected array $data = [],
        protected bool $success = true,
        protected string $message = '',
        protected array $metrics = [],
        protected ?string $skillId = null,
        protected ?float $executionTime = null,
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->data[$key] ?? $default;
    }

    public function getMessage(): string
    {
        return $this->message;
    }

    public function getMetrics(): array
    {
        return $this->metrics;
    }

    public function getSkillId(): ?string
    {
        return $this->skillId;
    }

    public function getExecutionTime(): ?float
    {
        return $this->executionTime;
    }

    public static function success(array $data, string $skillId, float $time, array $metrics = []): self
    {
        return new self(
            data: $data,
            success: true,
            skillId: $skillId,
            executionTime: $time,
            metrics: $metrics,
        );
    }

    public static function failure(string $message, string $skillId, float $time): self
    {
        return new self(
            success: false,
            message: $message,
            skillId: $skillId,
            executionTime: $time,
        );
    }
}
