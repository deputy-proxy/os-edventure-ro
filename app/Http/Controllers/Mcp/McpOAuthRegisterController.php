<?php

namespace App\Http\Controllers\Mcp;

use App\Mcp\Authorization\McpToolPermissionRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server\Http\Controllers\OAuthRegisterController;

/**
 * Registers ChatGPT's public OAuth client with every scope exposed by this MCP server.
 *
 * Laravel MCP's default registration response only grants its generic mcp:use scope.
 * Our tools additionally require their dedicated matrix scopes, so the registration
 * response must advertise those scopes to the OAuth client.
 * Live domain permissions remain the authority that limits the AI principal.
 */
final class McpOAuthRegisterController extends OAuthRegisterController
{
    public function __invoke(Request $request): JsonResponse
    {
        $response = parent::__invoke($request);

        if ($response->getStatusCode() !== 201) {
            return $response;
        }

        $metadata = $response->getData(true);

        if (! is_array($metadata)) {
            return $response;
        }

        $scopes = [
            'mcp:use',
            ...array_keys(app(McpToolPermissionRegistry::class)->passportScopes()),
        ];
        $metadata['scope'] = implode(' ', $scopes);

        return response()->json($metadata, 201);
    }
}
