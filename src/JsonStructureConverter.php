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

namespace CORS\AI\Platform\Bridge\Ai506;

use Symfony\AI\Platform\Exception\InvalidArgumentException;

/**
 * Converts an OpenAI-style response_format (json_schema) into 506.ai's "jsonStructure":
 * {name, schema: [{description, properties}]}. 506 supports type, description, properties, items,
 * enum, pattern, minimum and maximum — everything else (required, additionalProperties, $defs …)
 * is dropped. 506 wraps the answer in {"item": …}, see ResultConverter.
 */
final class JsonStructureConverter
{
    private const KEPT_KEYWORDS = ['description', 'pattern', 'minimum', 'maximum'];

    /**
     * @param array<string, mixed> $responseFormat
     *
     * @return array{name: string, schema: list<array{description: string, properties: array<string, mixed>}>}
     */
    public static function convert(array $responseFormat): array
    {
        $jsonSchema = $responseFormat['json_schema'] ?? null;
        if (!\is_array($jsonSchema) || !\is_array($jsonSchema['schema'] ?? null)) {
            throw new InvalidArgumentException('506.ai only supports response_format of type "json_schema".');
        }

        $name = \is_string($jsonSchema['name'] ?? null) ? $jsonSchema['name'] : 'output';
        $schema = $jsonSchema['schema'];

        if ('object' !== self::type($schema) || !\is_array($schema['properties'] ?? null) || [] === $schema['properties']) {
            throw new InvalidArgumentException('506.ai structured output needs an object schema with at least one property.');
        }

        return [
            'name' => $name,
            'schema' => [[
                'description' => \is_string($schema['description'] ?? null) ? $schema['description'] : $name,
                'properties' => self::properties($schema['properties']),
            ]],
        ];
    }

    /**
     * @param array<mixed> $properties
     *
     * @return array<string, mixed>
     */
    private static function properties(array $properties): array
    {
        $converted = [];
        foreach ($properties as $name => $property) {
            if (\is_array($property)) {
                $converted[(string) $name] = self::property($property);
            }
        }

        return $converted;
    }

    /**
     * @param array<mixed> $schema
     *
     * @return array<string, mixed>
     */
    private static function property(array $schema): array
    {
        // nullable / union types: 506 has no null, use the first concrete variant
        foreach (['anyOf', 'oneOf'] as $composition) {
            if (\is_array($schema[$composition] ?? null)) {
                foreach ($schema[$composition] as $variant) {
                    if (\is_array($variant) && 'null' !== self::type($variant)) {
                        return self::property([...$variant, ...array_diff_key($schema, [$composition => true])]);
                    }
                }
            }
        }

        $property = [];
        if (null !== $type = self::type($schema)) {
            $property['type'] = $type;
        }

        foreach (self::KEPT_KEYWORDS as $keyword) {
            if (\array_key_exists($keyword, $schema)) {
                $property[$keyword] = $schema[$keyword];
            }
        }

        if (\is_array($schema['enum'] ?? null)) {
            $property['enum'] = array_values(array_filter($schema['enum'], static fn (mixed $v): bool => null !== $v));
        }

        if (\is_array($schema['properties'] ?? null)) {
            $property['properties'] = self::properties($schema['properties']);
        }

        if (\is_array($schema['items'] ?? null)) {
            $property['items'] = self::property($schema['items']);
        }

        return $property;
    }

    /**
     * @param array<mixed> $schema
     */
    private static function type(array $schema): ?string
    {
        $type = $schema['type'] ?? null;

        if (\is_array($type)) {
            $type = array_values(array_filter($type, static fn (mixed $t): bool => 'null' !== $t))[0] ?? 'null';
        }

        return \is_string($type) ? $type : null;
    }
}
