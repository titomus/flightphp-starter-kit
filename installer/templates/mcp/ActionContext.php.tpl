<?php
declare(strict_types=1);

namespace {{namespace}}\Actions;

/**
 * Authenticated server-side actor, never constructed from incoming action args.
 * The current Flight OAuth provider represents administrators only. Tenant
 * actions therefore fail closed for MCP until server-side tenant binding exists.
 */
final class ActionContext
{
    private function __construct(
        public readonly string $channel,
        public readonly string $actorType,
        public readonly int $actorId,
        public readonly ?int $tenantId,
        public readonly array $scopes,
    ) {
        if (!in_array($channel, ['web', 'mcp'], true)
            || !in_array($actorType, ['admin', 'member'], true)
            || $actorId < 1 || ($tenantId !== null && $tenantId < 1)) {
            throw new \InvalidArgumentException('A verified identity is required.');
        }
    }

    /** Only call with identity read from the application's auth guard. */
    public static function verifiedWebActor(string $guard, array $identity): self
    {
        if (!in_array($guard, ['admin', 'member'], true)
            || !filter_var($identity['id'] ?? null, FILTER_VALIDATE_INT)
            || (int) $identity['id'] < 1) {
            throw new \RuntimeException('Authenticated web actor required.');
        }

        // The tenant ID must come from the authenticated record, never from
        // the submitted form / JSON payload.
        $tenant = $identity['tenant_id'] ?? null;
        if ($tenant !== null && (!filter_var($tenant, FILTER_VALIDATE_INT) || (int) $tenant < 1)) {
            throw new \RuntimeException('Invalid server-side tenant binding.');
        }
        return new self('web', $guard, (int) $identity['id'], $tenant === null ? null : (int) $tenant, []);
    }

    public static function fromWebSession(): self
    {
        $owner = auth('admin')->user();
        if (is_array($owner) && (int) ($owner['id'] ?? 0) > 0) {
            return self::verifiedWebActor('admin', $owner);
        }
        $member = auth('frontend')->user();
        if (is_array($member) && (int) ($member['id'] ?? 0) > 0) {
            return self::verifiedWebActor('member', $member);
        }
        throw new \RuntimeException('Authenticated session required.');
    }

    /**
     * The caller MUST be DynamicOAuth::principal($bearer), not HTTP JSON.
     * The current provider stores admin_id and OAuth scopes, NOT member_id.
     */
    public static function fromVerifiedOAuth(array $principal): self
    {
        $id = $principal['admin_id'] ?? null;
        $scopes = $principal['scopes'] ?? null;
        if (!filter_var($id, FILTER_VALIDATE_INT) || (int) $id < 1
            || !is_array($scopes) || count($scopes) > 20
            || count(array_filter($scopes, 'is_string')) !== count($scopes)) {
            throw new \RuntimeException('Invalid OAuth principal.');
        }
        return new self('mcp', 'admin', (int) $id, null, array_values(array_unique($scopes)));
    }

    public function hasScope(string $scope): bool
    {
        return in_array($scope, $this->scopes, true);
    }

    /** An action that reads tenant records must use this ID in its SQL WHERE. */
    public function requireTenant(): int
    {
        if ($this->tenantId === null) {
            throw new \DomainException('No tenant bound to the authenticated actor.');
        }
        return $this->tenantId;
    }
}
