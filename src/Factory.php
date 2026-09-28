<?php

declare(strict_types=1);

/*
 * This file is part of the 506.ai platform bridge for Symfony AI.
 *
 * (c) CORS GmbH <office@cors.gmbh>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace CORS\AI\Platform\Bridge\Ai506;

use CORS\AI\Platform\Bridge\Ai506\Contract\MessageBagNormalizer;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\ModelCatalog\ModelCatalogInterface;
use Symfony\AI\Platform\ModelRouter\CatalogBasedModelRouter;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\Provider;
use Symfony\AI\Platform\ProviderInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Symfony AI platform for the 506.ai public API (https://developers.506.ai).
 */
final class Factory
{
    /**
     * @param string           $host "<subdomain>", "<subdomain>.506.ai" or a full base URL like "https://x.506.ai:3003"
     * @param non-empty-string $name
     */
    public static function createProvider(
        string $host,
        #[\SensitiveParameter]
        string $organizationId,
        #[\SensitiveParameter]
        string $apiKey,
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $name = 'ai506',
    ): ProviderInterface {
        return new Provider(
            $name,
            [new ModelClient($httpClient ?? HttpClient::create(), self::baseUrl($host), $organizationId, $apiKey)],
            [new ResultConverter()],
            $modelCatalog,
            $contract ?? Contract::create([new MessageBagNormalizer()]),
            $eventDispatcher,
        );
    }

    /**
     * @param string           $host "<subdomain>", "<subdomain>.506.ai" or a full base URL like "https://x.506.ai:3003"
     * @param non-empty-string $name
     */
    public static function createPlatform(
        string $host,
        #[\SensitiveParameter]
        string $organizationId,
        #[\SensitiveParameter]
        string $apiKey,
        ?HttpClientInterface $httpClient = null,
        ModelCatalogInterface $modelCatalog = new ModelCatalog(),
        ?Contract $contract = null,
        ?EventDispatcherInterface $eventDispatcher = null,
        string $name = 'ai506',
    ): Platform {
        return new Platform(
            [self::createProvider($host, $organizationId, $apiKey, $httpClient, $modelCatalog, $contract, $eventDispatcher, $name)],
            new CatalogBasedModelRouter(),
            $eventDispatcher,
        );
    }

    public static function baseUrl(string $host): string
    {
        $host = trim($host);
        if ('' === $host) {
            throw new InvalidArgumentException('The 506.ai host must not be empty.');
        }

        if (str_contains($host, '://')) {
            return rtrim($host, '/');
        }

        $host = str_contains($host, '.') ? $host : $host.'.506.ai';

        return 'https://'.(str_contains($host, ':') ? $host : $host.':3003');
    }
}
