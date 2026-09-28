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

use CORS\AI\Platform\Bridge\Ai506\Ai506;
use CORS\AI\Platform\Bridge\Ai506\LineEventStream;
use CORS\AI\Platform\Bridge\Ai506\ResultConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\AuthenticationException;
use Symfony\AI\Platform\Exception\BadRequestException;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Exception\ServerException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;
use Symfony\Component\HttpClient\Response\MockResponse;

final class ResultConverterTest extends TestCase
{
    public function testSupports(): void
    {
        $this->assertTrue((new ResultConverter())->supports(new Ai506('gpt-4.1')));
        $this->assertFalse((new ResultConverter())->supports(new Model('gpt-4.1')));
        $this->assertNull((new ResultConverter())->getTokenUsageExtractor());
    }

    public function testText(): void
    {
        $result = (new ResultConverter())->convert($this->raw(new JsonMockResponse([
            'content' => 'The Kornspitz is a bread roll.',
            'references' => [['uniqueTitle' => 'dc_recipes.pdf', 'title' => 'recipes.pdf', 'chunks' => [1], 'url' => '']],
            'generationId' => 'chat:q-1',
        ])));

        $this->assertInstanceOf(TextResult::class, $result);
        $this->assertSame('The Kornspitz is a bread roll.', $result->getContent());
        $this->assertSame([['uniqueTitle' => 'dc_recipes.pdf', 'title' => 'recipes.pdf', 'chunks' => [1], 'url' => '']], $result->getMetadata()->get('references'));
        $this->assertSame('chat:q-1', $result->getMetadata()->get('generation_id'));
    }

    public function testStructuredOutputIsUnwrapped(): void
    {
        $result = (new ResultConverter())->convert(
            $this->raw(new JsonMockResponse(['content' => '{"item":{"city":"Linz","degrees":20}}', 'references' => []])),
            ['response_format' => ['type' => 'json_schema']],
        );

        $this->assertInstanceOf(TextResult::class, $result);
        $this->assertSame('{"city":"Linz","degrees":20}', $result->getContent());
    }

    public function testInvalidStructuredOutput(): void
    {
        $this->expectException(RuntimeException::class);

        (new ResultConverter())->convert($this->raw(new JsonMockResponse(['content' => 'nope'])), ['response_format' => []]);
    }

    public function testMissingContent(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('does not contain "content"');

        (new ResultConverter())->convert($this->raw(new JsonMockResponse(['foo' => 'bar'])));
    }

    public function testStream(): void
    {
        $result = (new ResultConverter())->convert($this->raw(new MockResponse(
            ["data:### Breads\n\n", "data:\n\n", "data:- Rye\n\ndata:  - dark\n\n"],
            ['response_headers' => ['content-type' => 'text/event-stream']],
        )), ['stream' => true]);

        $this->assertInstanceOf(StreamResult::class, $result);

        $text = '';
        foreach ($result->getContent() as $delta) {
            $this->assertInstanceOf(TextDelta::class, $delta);
            $text .= $delta->getText();
        }

        $this->assertSame("### Breads\n\n- Rye\n  - dark", $text);
    }

    /**
     * @param class-string<\Throwable> $exception
     */
    #[DataProvider('provideErrors')]
    public function testErrors(int $status, string $body, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        (new ResultConverter())->convert($this->raw(new MockResponse($body, ['http_code' => $status])));
    }

    /**
     * @return iterable<string, array{int, string, class-string<\Throwable>, string}>
     */
    public static function provideErrors(): iterable
    {
        yield 'bad request' => [400, '', BadRequestException::class, 'Bad Request'];
        yield 'unauthorized' => [401, '', AuthenticationException::class, 'Unauthorized'];
        yield 'agent mode' => [403, '{"message": "Agent mode is not available via the public API."}', RuntimeException::class, '506.ai responded 403 Forbidden'];
        yield 'plain text' => [403, 'Agent mode is not available via the public API.', RuntimeException::class, 'Agent mode is not available'];
        yield 'model not allowed' => [406, '', RuntimeException::class, 'model id not allowed'];
        yield 'rate limit' => [429, '', RateLimitExceededException::class, 'Rate limit exceeded'];
        yield 'server error' => [500, '', ServerException::class, 'Server error (HTTP 500)'];
    }

    private function raw(MockResponse $response): RawHttpResult
    {
        $client = new MockHttpClient($response);

        return new RawHttpResult($client->request('POST', 'https://acme.506.ai:3003/api/v1/public/chat'), new LineEventStream($client));
    }
}
