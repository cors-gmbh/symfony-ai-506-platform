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

namespace CORS\AI\Platform\Bridge\Ai506\Tests;

use CORS\AI\Platform\Bridge\Ai506\Factory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\InvalidArgumentException;

final class FactoryTest extends TestCase
{
    #[DataProvider('provideHosts')]
    public function testBaseUrl(string $host, string $expected): void
    {
        $this->assertSame($expected, Factory::baseUrl($host));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function provideHosts(): iterable
    {
        yield 'subdomain' => ['acme', 'https://acme.506.ai:3003'];
        yield 'host' => ['acme.506.ai', 'https://acme.506.ai:3003'];
        yield 'host with port' => ['acme.506.ai:8443', 'https://acme.506.ai:8443'];
        yield 'url' => ['https://acme.506.ai:3003/', 'https://acme.506.ai:3003'];
        yield 'http url' => ['http://localhost:3003', 'http://localhost:3003'];
    }

    public function testEmptyHost(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Factory::baseUrl(' ');
    }

    public function testCreatePlatform(): void
    {
        $platform = Factory::createPlatform('acme', 'org', 'key');

        $this->assertSame('gpt-4.1', $platform->getModelCatalog()->getModel('gpt-4.1')->getName());
    }
}
