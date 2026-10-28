<?php

/**
 * Bitlane Search for PrestaShop
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to a commercial license from NMC, in the file
 * LICENSE.md that comes with it. Use, copy, modification or distribution of
 * this file or its parts without NMC's written permission is not allowed.
 *
 * @author    Bitlane <https://bitlane.co>
 * @copyright Since 2026 NMC
 * @license   Commercial license, see LICENSE.md
 */

namespace Bitlane\PrestaShop\Connection;

use Bitlane\PrestaShop\Support\BitlaneError;
use Bitlane\PrestaShop\Support\Http;
use Bitlane\PrestaShop\Support\Lock;
use Bitlane\PrestaShop\Support\State;
use Bitlane\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Bitlane's OAuth 2.1 flow for a confidential client (docs/oauth-plan.md,
 * docs/prestashop-plan.md PS2), as the WordPress plugin runs it: the module
 * registers itself (Dynamic Client Registration) the first time, sends the
 * employee to Bitlane's consent page with PKCE, and exchanges the code from
 * PHP, so the shop doesn't have to be reachable from the internet. Refreshes
 * run one at a time, since several requests can find the token expired
 * together.
 */
final class OAuthClient
{
    public const SCOPES = 'projects:read engines:write widgets:write analytics:read offline_access';

    /** How long a Connect waits for Bitlane's answer. */
    private const PENDING = 900;

    public function __construct(
        private ConnectionStore $connection,
        private State $state,
        private Http $http,
        private Lock $lock,
        private Translator $translator,
        private string $version,
    ) {
    }

    /**
     * The API, https://api.bitlane.co unless BITLANE_API_URL says otherwise.
     */
    public function apiUrl(): string
    {
        return rtrim(defined('BITLANE_API_URL') ? (string) constant('BITLANE_API_URL') : 'https://api.bitlane.co', '/');
    }

    /**
     * Where Bitlane sends the employee back: the Bitlane screen, in the back
     * office being used (its folder and host are part of it, so a renamed
     * folder registers a new client on the next Connect).
     */
    public function redirectUri(): string
    {
        return \Context::getContext()->link->getAdminBaseLink() . basename(_PS_ADMIN_DIR_) . '/index.php?controller=AdminBitlane&oauth=callback';
    }

    /**
     * The authorization server's metadata (RFC 8414), kept a day.
     *
     * @return array<string, mixed>
     */
    public function metadata(): array
    {
        $cached = $this->state->json('oauth_metadata');
        if (($cached['url'] ?? '') === $this->apiUrl() && ($cached['at'] ?? 0) > time() - 86400 && is_array($cached['data'] ?? null)) {
            return $cached['data'];
        }
        $res = $this->http->request('GET', $this->apiUrl() . '/.well-known/oauth-authorization-server');
        if ($res['status'] !== 200 || empty($res['body']['authorization_endpoint'])) {
            throw new BitlaneError('metadata', $this->translator->trans('Bitlane could not be reached. Try again in a moment.', [], 'Modules.Bitlane.Admin'), $res['status']);
        }
        $this->state->setJson('oauth_metadata', ['url' => $this->apiUrl(), 'at' => time(), 'data' => $res['body']]);

        return $res['body'];
    }

    /**
     * The dashboard's address, for "Open in Bitlane": the consent page's host.
     */
    public function appUrl(): ?string
    {
        try {
            $parts = parse_url((string) $this->metadata()['authorization_endpoint']);
        } catch (BitlaneError) {
            return null;
        }
        if (!isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /**
     * The consent page's URL for a new grant, registering the client first
     * when needed. The PKCE verifier, the expected issuer, the employee and
     * the shop wait in the state under the request's state.
     */
    public function authorizeUrl(int $employeeId, int $shopId): string
    {
        $metadata = $this->metadata();
        $client = $this->client($metadata);

        $verifier = $this->random();
        $state = $this->random();
        $this->state->prune('oauth:', time() - self::PENDING);
        $this->state->setJson('oauth:' . hash('sha256', $state), [
            'verifier' => $verifier,
            'issuer' => (string) ($metadata['issuer'] ?? ''),
            'employee' => $employeeId,
            'shop' => $shopId,
        ]);

        return $metadata['authorization_endpoint'] . '?' . http_build_query([
            'response_type' => 'code',
            'client_id' => $client['client_id'],
            'redirect_uri' => $this->redirectUri(),
            'scope' => self::SCOPES,
            'state' => $state,
            'code_challenge' => $this->base64url(hash('sha256', $verifier, true)),
            'code_challenge_method' => 'S256',
            'resource' => $this->apiUrl(),
        ], '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * Handles Bitlane's answer on the redirect URI: checks the state (made
     * for this employee, not expired) and the issuer (RFC 9207), then
     * exchanges the code.
     *
     * @param array<string, string> $query the callback's query
     *
     * @return int the shop Connect was clicked in
     */
    public function handleCallback(array $query, int $employeeId): int
    {
        $key = 'oauth:' . hash('sha256', $query['state'] ?? '');
        $pending = ($query['state'] ?? '') === '' ? [] : $this->state->json($key);
        $this->state->prune('oauth:', time() - self::PENDING);
        if (!$pending || $this->state->get($key) === null || $pending['employee'] !== $employeeId) {
            throw new BitlaneError('state', $this->translator->trans('The connection request expired or came from elsewhere. Click Connect again.', [], 'Modules.Bitlane.Admin'));
        }
        $this->state->set($key, null);

        if (isset($query['error'])) {
            throw $query['error'] === 'access_denied' ? new BitlaneError('access_denied', $this->translator->trans('The connection was declined in Bitlane.', [], 'Modules.Bitlane.Admin')) : new BitlaneError($query['error'], (string) ($query['error_description'] ?? $query['error']));
        }
        if (isset($query['iss']) && $query['iss'] !== $pending['issuer']) {
            throw new BitlaneError('issuer', $this->translator->trans('The answer did not come from Bitlane.', [], 'Modules.Bitlane.Admin'));
        }
        if (empty($query['code'])) {
            throw new BitlaneError('no_code', $this->translator->trans('Bitlane sent no authorization code.', [], 'Modules.Bitlane.Admin'));
        }

        $tokens = $this->token([
            'grant_type' => 'authorization_code',
            'code' => $query['code'],
            'redirect_uri' => $this->redirectUri(),
            'code_verifier' => $pending['verifier'],
            'resource' => $this->apiUrl(),
        ]);
        $this->saveTokens($tokens, ['project_id' => $tokens['project_id'] ?? null]);

        return (int) $pending['shop'];
    }

    /**
     * A valid access token, refreshed when it's about to expire.
     */
    public function accessToken(): string
    {
        $connection = $this->connection->get();
        if (empty($connection['refresh_token'])) {
            throw new BitlaneError('disconnected', $this->translator->trans('This shop isn’t connected to Bitlane.', [], 'Modules.Bitlane.Admin'), 409);
        }
        if (!empty($connection['access_token']) && (int) ($connection['expires_at'] ?? 0) > time() + 60) {
            return (string) $connection['access_token'];
        }

        return $this->refresh((string) ($connection['access_token'] ?? ''));
    }

    /**
     * Refreshes the tokens, unless another request did since $stale was read.
     * A refused refresh token ends the connection.
     *
     * @param string $stale the access token found expired or refused
     */
    public function refresh(string $stale): string
    {
        return $this->lock->with('refresh', function () use ($stale): string {
            $connection = $this->connection->get();
            if (empty($connection['refresh_token'])) {
                throw new BitlaneError('disconnected', $this->translator->trans('This shop isn’t connected to Bitlane.', [], 'Modules.Bitlane.Admin'), 409);
            }
            $renewed = !empty($connection['access_token']) && $connection['access_token'] !== $stale;
            if ($renewed && (int) ($connection['expires_at'] ?? 0) > time() + 60) {
                return (string) $connection['access_token'];
            }

            try {
                $tokens = $this->token([
                    'grant_type' => 'refresh_token',
                    'refresh_token' => (string) $connection['refresh_token'],
                    'resource' => $this->apiUrl(),
                ]);
            } catch (BitlaneError $error) {
                if ($error->kind === 'invalid_grant') {
                    $this->connection->end(true);
                }
                throw $error;
            }

            return $this->saveTokens($tokens);
        });
    }

    /**
     * Revokes the grant's refresh token (RFC 7009); the connection ends either way.
     */
    public function revoke(): void
    {
        $connection = $this->connection->get();
        try {
            $metadata = $this->metadata();
            if (!empty($connection['refresh_token']) && !empty($metadata['revocation_endpoint'])) {
                $this->http->request('POST', (string) $metadata['revocation_endpoint'], [
                    'headers' => $this->clientAuth($connection),
                    'form' => ['token' => (string) $connection['refresh_token'], 'token_type_hint' => 'refresh_token'],
                ]);
            }
        } catch (BitlaneError) {
            // Bitlane can't be reached: the shop forgets the grant anyway.
        }
        $this->connection->end(false);
    }

    /**
     * The registered client, registering it when there's none for this
     * redirect URI (a new install, or the back office moved).
     *
     * @param array<string, mixed> $metadata
     *
     * @return array<string, mixed>
     */
    private function client(array $metadata): array
    {
        $connection = $this->connection->get();
        if (!empty($connection['client_id']) && $this->redirectUri() === ($connection['redirect_uri'] ?? '')) {
            return $connection;
        }
        if (empty($metadata['registration_endpoint'])) {
            throw new BitlaneError('register', $this->translator->trans('Bitlane does not accept new connections right now.', [], 'Modules.Bitlane.Admin'));
        }

        $res = $this->http->request('POST', (string) $metadata['registration_endpoint'], [
            'json' => [
                'redirect_uris' => [$this->redirectUri()],
                'token_endpoint_auth_method' => 'client_secret_basic',
                'grant_types' => ['authorization_code', 'refresh_token'],
                'response_types' => ['code'],
                'client_name' => $this->translator->trans('Bitlane for PrestaShop (%host%)', ['%host%' => (string) parse_url($this->redirectUri(), PHP_URL_HOST)], 'Modules.Bitlane.Admin'),
                'client_uri' => \Context::getContext()->shop->getBaseURL(true),
                'software_id' => 'bitlane-prestashop',
                'software_version' => $this->version,
            ],
        ]);
        if (!in_array($res['status'], [200, 201], true) || empty($res['body']['client_id'])) {
            throw new BitlaneError('register', (string) ($res['body']['error_description'] ?? $this->translator->trans('Bitlane refused to register this shop.', [], 'Modules.Bitlane.Admin')), $res['status']);
        }

        return $this->connection->update([
            'client_id' => $res['body']['client_id'],
            'client_secret' => $res['body']['client_secret'] ?? '',
            'redirect_uri' => $this->redirectUri(),
        ]);
    }

    /**
     * Calls the token endpoint with the client's credentials; a refusal
     * throws with the OAuth error code as its kind.
     *
     * @param array<string, string> $params
     *
     * @return array<string, mixed>
     */
    private function token(array $params): array
    {
        $res = $this->http->request('POST', (string) $this->metadata()['token_endpoint'], [
            'headers' => $this->clientAuth($this->connection->get()),
            'form' => $params,
        ]);
        if ($res['status'] !== 200 || empty($res['body']['access_token'])) {
            throw new BitlaneError((string) ($res['body']['error'] ?? 'token'), (string) ($res['body']['error_description'] ?? $this->translator->trans('Bitlane refused the connection.', [], 'Modules.Bitlane.Admin')), $res['status']);
        }

        return $res['body'];
    }

    /**
     * Stores a token answer and returns the access token.
     *
     * @param array<string, mixed> $tokens
     * @param array<string, mixed> $extra
     */
    private function saveTokens(array $tokens, array $extra = []): string
    {
        $connection = $this->connection->update(array_merge([
            'access_token' => $tokens['access_token'],
            // A refresh without rotation keeps the one it has.
            'refresh_token' => $tokens['refresh_token'] ?? $this->connection->get()['refresh_token'] ?? null,
            'expires_at' => time() + (int) ($tokens['expires_in'] ?? 3600),
            'scope' => $tokens['scope'] ?? self::SCOPES,
            'revoked' => null,
        ], $extra));

        return (string) $connection['access_token'];
    }

    /**
     * HTTP Basic authentication with the client's id and secret (RFC 6749 2.3.1).
     *
     * @param array<string, mixed> $connection
     *
     * @return array<string, string>
     */
    private function clientAuth(array $connection): array
    {
        $credentials = rawurlencode((string) ($connection['client_id'] ?? '')) . ':' . rawurlencode((string) ($connection['client_secret'] ?? ''));

        return ['Authorization' => 'Basic ' . base64_encode($credentials)];
    }

    private function random(): string
    {
        return $this->base64url(random_bytes(32));
    }

    private function base64url(string $bytes): string
    {
        return rtrim(strtr(base64_encode($bytes), '+/', '-_'), '=');
    }
}
