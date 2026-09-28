<?php

declare(strict_types=1);

/*
 * CORS GmbH
 *
 * This source file is available under the MIT license
 *
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 * @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
 * @license    https://opensource.org/license/mit MIT
 */

namespace CORS\AI\Platform\Bridge\Ai506\Bundle;

use CORS\AI\Platform\Bridge\Ai506\Factory;
use CORS\AI\Platform\Bridge\Ai506\ModelCatalog;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

/**
 * Registers the 506.ai platform as "ai.platform.ai506", tagged "ai.platform" so symfony/ai-bundle
 * picks it up (profiler, ai:platform:invoke, agents via "platform: ai.platform.ai506").
 */
final class CORSAi506Bundle extends AbstractBundle
{
    public const PLATFORM_ID = 'ai.platform.ai506';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('host')
                    ->info('"<subdomain>", "<subdomain>.506.ai" or a full base URL like "https://acme.506.ai:3003"')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('organization_id')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('api_key')
                    ->isRequired()
                    ->cannotBeEmpty()
                ->end()
                ->scalarNode('http_client')
                    ->info('Service id of the HTTP client')
                    ->defaultValue('http_client')
                ->end()
                ->arrayNode('models')
                    ->info('Additional model ids enabled for your organization (unknown ids are accepted anyway)')
                    ->scalarPrototype()->end()
                ->end()
            ->end()
        ;
    }

    /**
     * @param array<mixed> $config
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        /** @var array{host: string, organization_id: string, api_key: string, http_client: string, models: list<string>} $config */
        $container->services()
            ->set('ai.platform.model_catalog.ai506', ModelCatalog::class)
                ->args([$config['models']])

            ->set(self::PLATFORM_ID, Platform::class)
                ->factory([Factory::class, 'createPlatform'])
                ->args([
                    $config['host'],
                    $config['organization_id'],
                    $config['api_key'],
                    service($config['http_client'])->nullOnInvalid(),
                    service('ai.platform.model_catalog.ai506'),
                    null,
                    service('event_dispatcher')->nullOnInvalid(),
                    'ai506',
                ])
                ->tag('ai.platform', ['name' => 'ai506'])

            ->alias(PlatformInterface::class.' $ai506Platform', self::PLATFORM_ID)
        ;
    }
}
