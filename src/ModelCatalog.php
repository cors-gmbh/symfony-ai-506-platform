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

use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Exception\ModelNotFoundException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelCatalog\AbstractModelCatalog;

/**
 * The available models depend on the 506.ai organization, so unknown model ids are accepted as well —
 * 506 answers with 406 for a model that is not enabled.
 */
final class ModelCatalog extends AbstractModelCatalog
{
    public const CAPABILITIES = [
        Capability::INPUT_MESSAGES,
        Capability::INPUT_TEXT,
        Capability::OUTPUT_TEXT,
        Capability::OUTPUT_STREAMING,
        Capability::OUTPUT_STRUCTURED,
    ];

    /**
     * @param list<string> $additionalModels
     */
    public function __construct(
        array $additionalModels = [],
    ) {
        $models = [
            'gpt-5',
            'gpt-4.1',
            'gpt-4.1-mini',
            'gemini-2.5-pro',
            'mistral-large',
            'claude-sonnet-4-5-20250929-v1:0',
            ...$additionalModels,
        ];

        $this->models = [];
        foreach ($models as $model) {
            $this->models[$model] = [
                'class' => Ai506::class,
                'capabilities' => self::CAPABILITIES,
            ];
        }
    }

    public function getModel(string $modelName): Model
    {
        try {
            return parent::getModel($modelName);
        } catch (ModelNotFoundException) {
            $parsed = $this->parseModelName($modelName);
            if ('' === $parsed['name']) {
                throw new InvalidArgumentException('Model name cannot be empty.');
            }

            return new Ai506($parsed['name'], self::CAPABILITIES, $parsed['options']);
        }
    }
}
