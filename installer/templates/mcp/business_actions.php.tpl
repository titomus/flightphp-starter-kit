<?php
declare(strict_types=1);

use {{namespace}}\Actions\ActionContext;

/**
 * MCP Native Actions: the ONLY business action registry for new features.
 *
 * One definition becomes a POST /business/actions/{name} web operation AND a
 * business_{name} MCP tool, sharing authorization, validation and handler.
 * Never put tenant IDs or user IDs in inputSchema; derive them from context.
 *
 * Example:
 *
 * return [
 *    'quote_create' => [
 *       'description' => 'Create a quote for the authenticated company',
 *       'mode' => 'write', 'web' => true, 'mcp' => true,
 *       'tenant_scoped' => true, // requires a server-verified tenant binding
 *       'inputSchema' => [
 *          'type' => 'object', 'properties' => [
 *              'title' => ['type' => 'string', 'maxLength' => 120],
 *          ], 'required' => ['title'], 'additionalProperties' => false,
 *       ],
 *       'authorize' => static fn (ActionContext $actor, array $input): bool =>
 *           $actor->actorType === 'member',
 *       'handler' => static fn (ActionContext $actor, array $input): array =>
 *           (new \{{namespace}}\Services\Business\QuoteService())->create(
 *               $actor->requireTenant(), $actor->actorId, $input['title']
 *           ),
 *    ],
 * ];
 *
 * IMPORTANT: Flight's current MCP OAuth represents ADMIN identities, not
 * members. Tenant actions remain unavailable over MCP until OAuth is extended
 * with a secure server-side member/tenant identity mapping.
 */
return [];
