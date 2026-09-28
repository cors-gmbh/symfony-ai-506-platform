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

namespace CORS\AI\Platform\Bridge\Ai506\Tests;

use CORS\AI\Platform\Bridge\Ai506\JsonStructureConverter;
use CORS\AI\Platform\Bridge\Ai506\Tests\Fixtures\Recipe;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\StructuredOutput\ResponseFormatFactory;

final class JsonStructureConverterTest extends TestCase
{
    public function testConvert(): void
    {
        $structure = JsonStructureConverter::convert([
            'type' => 'json_schema',
            'json_schema' => [
                'name' => 'city_data',
                'strict' => true,
                'schema' => [
                    'type' => 'object',
                    'description' => 'Weather data',
                    'properties' => [
                        'unit' => ['type' => ['string', 'null'], 'enum' => ['°C', 'K', null], 'description' => 'Unit'],
                        'degrees' => ['type' => 'number', 'minimum' => -50, 'maximum' => 100],
                        'handle' => ['type' => 'string', 'pattern' => '^@\w+$'],
                        'location' => ['anyOf' => [['type' => 'null'], ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]], 'description' => 'Where'],
                        'stars' => [
                            'type' => 'array',
                            'items' => [
                                'type' => 'object',
                                'properties' => ['firstName' => ['type' => 'string']],
                                'required' => ['firstName'],
                                'additionalProperties' => false,
                            ],
                        ],
                    ],
                    'required' => ['unit', 'degrees', 'handle', 'location', 'stars'],
                    'additionalProperties' => false,
                ],
            ],
        ]);

        $this->assertSame([
            'name' => 'city_data',
            'schema' => [[
                'description' => 'Weather data',
                'properties' => [
                    'unit' => ['type' => 'string', 'description' => 'Unit', 'enum' => ['°C', 'K']],
                    'degrees' => ['type' => 'number', 'minimum' => -50, 'maximum' => 100],
                    'handle' => ['type' => 'string', 'pattern' => '^@\w+$'],
                    'location' => ['type' => 'object', 'description' => 'Where', 'properties' => ['name' => ['type' => 'string']]],
                    'stars' => ['type' => 'array', 'items' => ['type' => 'object', 'properties' => ['firstName' => ['type' => 'string']]]],
                ],
            ]],
        ], $structure);
    }

    public function testConvertSymfonyResponseFormat(): void
    {
        $structure = JsonStructureConverter::convert((new ResponseFormatFactory())->create(Recipe::class));

        $this->assertSame('Recipe', $structure['name']);
        $this->assertSame('Recipe', $structure['schema'][0]['description']);
        $this->assertSame(['title', 'servings', 'ingredients', 'vegan'], array_keys($structure['schema'][0]['properties']));
        $this->assertSame([
            'type' => 'array',
            'items' => [
                'type' => 'object',
                'properties' => [
                    'name' => ['type' => 'string'],
                    'amount' => ['type' => 'string'],
                ],
            ],
        ], $structure['schema'][0]['properties']['ingredients']);
    }

    public function testRejectsNonJsonSchema(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonStructureConverter::convert(['type' => 'json_object']);
    }

    public function testRejectsSchemaWithoutProperties(): void
    {
        $this->expectException(InvalidArgumentException::class);

        JsonStructureConverter::convert(['type' => 'json_schema', 'json_schema' => ['name' => 'x', 'schema' => ['type' => 'object', 'properties' => []]]]);
    }
}
