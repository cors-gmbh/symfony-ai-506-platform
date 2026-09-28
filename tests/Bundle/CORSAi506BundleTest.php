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

namespace CORS\AI\Platform\Bridge\Ai506\Tests\Bundle;

use CORS\AI\Platform\Bridge\Ai506\Bundle\CORSAi506Bundle;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Platform;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpClient\MockHttpClient;

final class CORSAi506BundleTest extends TestCase
{
    public function testRegistersPlatform(): void
    {
        $container = $this->container([
            'host' => 'acme',
            'organization_id' => 'org',
            'api_key' => 'key',
            'models' => ['my-model'],
        ]);

        $this->assertSame(['ai.platform.ai506' => [['name' => 'ai506']]], $container->findTaggedServiceIds('ai.platform'));
        $this->assertTrue($container->hasAlias(PlatformInterface::class.' $ai506Platform'));

        $container->getAlias(PlatformInterface::class.' $ai506Platform')->setPublic(true);
        $container->compile();

        $platform = $container->get(PlatformInterface::class.' $ai506Platform');
        $this->assertInstanceOf(Platform::class, $platform);
        $this->assertArrayHasKey('my-model', $platform->getModelCatalog()->getModels());
    }

    public function testRequiresCredentials(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        $this->container(['host' => 'acme']);
    }

    /**
     * @param array<string, mixed> $config
     */
    private function container(array $config): ContainerBuilder
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.environment', 'test');
        $container->setParameter('kernel.build_dir', sys_get_temp_dir());
        $container->register('http_client', MockHttpClient::class);

        $bundle = new CORSAi506Bundle();
        $extension = $bundle->getContainerExtension();
        $this->assertNotNull($extension);
        $this->assertSame('cors_ai506', $extension->getAlias());

        $container->registerExtension($extension);
        $extension->load([$config], $container);

        return $container;
    }
}
