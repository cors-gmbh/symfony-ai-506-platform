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

use CORS\AI\Platform\Bridge\Ai506\Factory;
use CORS\AI\Platform\Bridge\Ai506\Tests\Fixtures\Recipe;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * End-to-end through Symfony AI's Platform, with a mocked 506.ai API.
 */
final class PlatformTest extends TestCase
{
    public function testText(): void
    {
        $platform = Factory::createPlatform('acme', 'org', 'key', new MockHttpClient(new JsonMockResponse(['content' => 'Hello!'])));

        $this->assertSame('Hello!', $platform->invoke('gpt-4.1', new MessageBag(Message::ofUser('Hi')))->asText());
    }

    public function testStream(): void
    {
        $platform = Factory::createPlatform('acme', 'org', 'key', new MockHttpClient(new MockResponse(
            ["data:Hello\n\n", "data:World\n\n"],
            ['response_headers' => ['content-type' => 'text/event-stream']],
        )));

        $text = '';
        foreach ($platform->invoke('gpt-4.1', new MessageBag(Message::ofUser('Hi')), ['stream' => true])->asStream() as $delta) {
            $this->assertInstanceOf(TextDelta::class, $delta);
            $text .= $delta->getText();
        }

        $this->assertSame("Hello\nWorld", $text);
    }

    public function testStructuredOutput(): void
    {
        $dispatcher = new EventDispatcher();
        $dispatcher->addSubscriber(new PlatformSubscriber());

        $platform = Factory::createPlatform('acme', 'org', 'key', new MockHttpClient(new JsonMockResponse([
            'content' => '{"item":{"title":"Kornspitz","servings":4,"ingredients":[{"name":"Flour","amount":"500 g"},{"name":"Salt","amount":null}],"vegan":true}}',
            'references' => [],
        ])), eventDispatcher: $dispatcher);

        $recipe = $platform->invoke('gpt-4.1', new MessageBag(Message::ofUser('...')), ['response_format' => Recipe::class])->asObject();

        $this->assertInstanceOf(Recipe::class, $recipe);
        $this->assertSame('Kornspitz', $recipe->title);
        $this->assertSame(4, $recipe->servings);
        $this->assertTrue($recipe->vegan);
        $this->assertCount(2, $recipe->ingredients);
        $this->assertSame('Flour', $recipe->ingredients[0]->name);
        $this->assertNull($recipe->ingredients[1]->amount);
    }
}
