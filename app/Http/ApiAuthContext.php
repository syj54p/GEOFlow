<?php

declare(strict_types=1);

namespace App\Http;

/**
 * API 请求的认证与租户上下文。
 *
 * 由 AuthenticateApiToken 中间件构造，ResolveTenant 中间件补充 siteId。
 * siteId 为 null 表示该请求尚未经过租户解析（仅出现在未挂 api.tenant 的路由上）。
 */
final class ApiAuthContext
{
    /**
     * @param  array<string, mixed>  $token       Sanctum personal_access_tokens 记录（含可能的 site_id 绑定）
     * @param  int                   $auditAdminId  创建该 token 的管理员 ID
     * @param  int|null              $siteId        解析后的租户站点 ID（api.tenant 中间件保证非 null）
     */
    public function __construct(
        public array $token,
        public int $auditAdminId,
        public ?int $siteId = null,
    ) {}
}
