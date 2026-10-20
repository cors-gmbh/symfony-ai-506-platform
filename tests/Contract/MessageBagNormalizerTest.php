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

namespace CORS\AI\Platform\Bridge\Ai506\Tests\Contract;

use CORS\AI\Platform\Bridge\Ai506\Ai506;
use CORS\AI\Platform\Bridge\Ai506\Contract\MessageBagNormalizer;
use CORS\AI\Platform\Bridge\Ai506\TextExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\DocumentUrl;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class MessageBagNormalizerTest extends TestCase
{
    public function testNormalize(): void
    {
        $payload = $this->payload(new MessageBag(
            Message::forSystem('You are a baker.'),
            Message::ofUser('Remember 4711.'),
            Message::ofAssistant('Okay.'),
            Message::ofUser('Which', 'number?'),
        ));

        $this->assertSame(['messages' => [
            ['role' => 'system', 'content' => 'You are a baker.', 'references' => [], 'sources' => []],
            ['role' => 'user', 'content' => 'Remember 4711.', 'references' => [], 'sources' => []],
            ['role' => 'assistant', 'content' => 'Okay.', 'references' => [], 'sources' => []],
            ['role' => 'user', 'content' => 'Which number?', 'references' => [], 'sources' => []],
        ]], $payload);
    }

    public function testOnlyForAi506Models(): void
    {
        $normalizer = new MessageBagNormalizer();

        $this->assertTrue($normalizer->supportsNormalization(new MessageBag(), context: [Contract::CONTEXT_MODEL => new Ai506('gpt-4.1')]));
        $this->assertFalse($normalizer->supportsNormalization(new MessageBag(), context: [Contract::CONTEXT_MODEL => new Model('gpt-4.1')]));
    }

    public function testDocumentsAreInlinedAsText(): void
    {
        $recipe = new Document('%PDF recipe', 'application/pdf', '/tmp/Kornspitz "classic".pdf');
        $response = new JsonMockResponse([
            ['filename' => 'document-1.pdf', 'status' => 200, 'text' => "Flour, salt\n"],
            ['filename' => 'document-2.pdf', 'status' => 200, 'text' => 'Bake at 220 °C'],
        ], ['http_code' => 207]);

        $payload = $this->payload(new MessageBag(
            Message::forSystem('You are a baker.'),
            Message::ofUser('Summarize', 'this:', $recipe),
            Message::ofAssistant('Flour and salt.'),
            Message::ofUser('And this?', new Document('%PDF baking', 'application/pdf'), 'Compare with', $recipe),
        ), $response);

        $this->assertIsArray($payload);
        $this->assertIsArray($payload['messages']);
        $this->assertSame([
            'You are a baker.',
            "Summarize this:\n\n<document name=\"Kornspitz &quot;classic&quot;.pdf\">\nFlour, salt\n</document>",
            'Flour and salt.',
            "And this?\n\n<document>\nBake at 220 °C\n</document>\n\nCompare with\n\n<document name=\"Kornspitz &quot;classic&quot;.pdf\">\nFlour, salt\n</document>",
        ], array_column($payload['messages'], 'content'));

        // one request for the whole bag, the same document only once
        $body = $response->getRequestOptions()['body'];
        $this->assertIsString($body);
        $this->assertSame(2, substr_count($body, 'name="media"'));
    }

    public function testDocumentsNeedATextExtractor(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('needs a "CORS\\AI\\Platform\\Bridge\\Ai506\\TextExtractor" for documents');

        Contract::create([new MessageBagNormalizer()])->createRequestPayload(
            new Ai506('gpt-4.1'),
            new MessageBag(Message::ofUser('Summarize', new Document('%PDF', 'application/pdf'))),
        );
    }

    public function testRejectsImages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only supports text and documents');

        $this->payload(new MessageBag(Message::ofUser('Describe', new ImageUrl('https://example.com/bread.jpg'))));
    }

    public function testRejectsDocumentUrls(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only supports text and documents');

        $this->payload(new MessageBag(Message::ofUser('Summarize', new DocumentUrl('https://example.com/recipe.pdf'))));
    }

    public function testRejectsToolCallMessages(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no tool calling');

        $this->payload(new MessageBag(Message::ofToolCall(new ToolCall('1', 'clock'), 'now')));
    }

    /**
     * @return array<mixed>|string
     */
    private function payload(MessageBag $messages, ?JsonMockResponse $extractResponse = null): array|string
    {
        $extractor = new TextExtractor(new MockHttpClient($extractResponse ?? []), 'https://acme.506.ai:3003', 'org', 'key');

        return Contract::create([new MessageBagNormalizer($extractor)])->createRequestPayload(new Ai506('gpt-4.1'), $messages);
    }
}
