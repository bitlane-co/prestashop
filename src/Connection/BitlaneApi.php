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
use Bitlane\PrestaShop\Support\Translator;

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Bitlane's API with the grant's access token. A 401 is retried once with
 * refreshed tokens; a second one means the grant ended (the app was revoked
 * in Bitlane), and the connection is closed.
 */
final class BitlaneApi
{
    public function __construct(
        private OAuthClient $oauth,
        private ConnectionStore $connection,
        private Http $http,
        private Translator $translator,
    ) {
    }

    /**
     * Sends a request and returns the decoded answer; an error status throws
     * with Bitlane's message and field errors.
     *
     * @param array<string, mixed>|null $body a JSON body
     * @param array<string, scalar> $query
     *
     * @return array<mixed>
     */
    public function request(string $method, string $path, ?array $body = null, array $query = []): array
    {
        $token = $this->oauth->accessToken();
        $res = $this->send($method, $path, $body, $query, $token);
        if ($res['status'] === 401) {
            $token = $this->oauth->refresh($token);
            $res = $this->send($method, $path, $body, $query, $token);
            if ($res['status'] === 401) {
                $this->connection->end(true);
                throw new BitlaneError('disconnected', $this->translator->trans('Bitlane ended this shop’s connection. Connect again to keep searching.', [], 'Modules.Bitlane.Admin'), 401);
            }
        }
        if ($res['status'] >= 400) {
            throw new BitlaneError('api', (string) ($res['body']['message'] ?? $this->translator->trans('Bitlane could not do that. Try again in a moment.', [], 'Modules.Bitlane.Admin')), $res['status'], is_array($res['body']['errors'] ?? null) ? $res['body']['errors'] : null);
        }

        return $res['body'];
    }

    /**
     * @param array<string, mixed>|null $body
     * @param array<string, scalar> $query
     *
     * @return array{status: int, body: array<string, mixed>, retry_after: int}
     */
    private function send(string $method, string $path, ?array $body, array $query, string $token): array
    {
        $options = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                // Bitlane answers in the employee's language (en or it).
                'Accept-Language' => (string) (\Context::getContext()->language->iso_code ?? 'en'),
            ],
        ];
        if ($body !== null) {
            $options['json'] = $body;
        }
        $url = $this->oauth->apiUrl() . $path . ($query ? '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986) : '');

        return $this->http->request($method, $url, $options);
    }
}
