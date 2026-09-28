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

use CORS\AI\Platform\Bridge\Ai506\Ai506;
use CORS\AI\Platform\Bridge\Ai506\ModelCatalog;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Capability;
use Symfony\AI\Platform\Exception\InvalidArgumentException;

final class ModelCatalogTest extends TestCase
{
    public function testKnownModel(): void
    {
        $model = (new ModelCatalog())->getModel('gpt-4.1');

        $this->assertInstanceOf(Ai506::class, $model);
        $this->assertSame('gpt-4.1', $model->getName());
        $this->assertTrue($model->supports(Capability::OUTPUT_STREAMING));
        $this->assertTrue($model->supports(Capability::OUTPUT_STRUCTURED));
        $this->assertFalse($model->supports(Capability::TOOL_CALLING));
        $this->assertFalse($model->supports(Capability::INPUT_IMAGE));
    }

    public function testModelIdWithColon(): void
    {
        $this->assertSame(
            'claude-sonnet-4-5-20250929-v1:0',
            (new ModelCatalog())->getModel('claude-sonnet-4-5-20250929-v1:0')->getName(),
        );
    }

    public function testUnknownModelIsAccepted(): void
    {
        $model = (new ModelCatalog())->getModel('some-future-model?mode=QA&temperature=0.5');

        $this->assertInstanceOf(Ai506::class, $model);
        $this->assertSame('some-future-model', $model->getName());
        $this->assertSame(['mode' => 'QA', 'temperature' => 0.5], $model->getOptions());
        $this->assertTrue($model->supports(Capability::OUTPUT_TEXT));
    }

    public function testAdditionalModelsAreListed(): void
    {
        $this->assertArrayHasKey('my-model', (new ModelCatalog(['my-model']))->getModels());
    }

    public function testEmptyModelName(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new ModelCatalog())->getModel('?mode=QA');
    }
}
