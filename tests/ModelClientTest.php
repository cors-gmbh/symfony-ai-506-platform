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
use CORS\AI\Platform\Bridge\Ai506\ModelClient;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class ModelClientTest extends TestCase
{
    private const MESSAGES = ['messages' => [['role' => 'user', 'content' => 'Hi', 'references' => [], 'sources' => []]]];

    public function testSupports(): void
    {
        $client = new ModelClient(new MockHttpClient(), 'https://acme.506.ai:3003', 'org', 'key');

        $this->assertTrue($client->supports(new Ai506('gpt-4.1')));
        $this->assertFalse($client->supports(new Model('gpt-4.1')));
    }

    public function testChatNoStreamWithDefaults(): void
    {
        $response = new JsonMockResponse(['content' => 'Hello']);
        $this->client($response)->request(new Ai506('gpt-4.1'), self::MESSAGES)->getObject()->getStatusCode();

        $this->assertSame('POST', $response->getRequestMethod());
        $this->assertSame('https://acme.506.ai:3003/api/v1/public/chatNoStream?internalSystemPrompt=true', $response->getRequestUrl());
        $this->assertContains('api-organization-id: org', $this->headers($response));
        $this->assertContains('api-key: key', $this->headers($response));
        $this->assertContains('accept: application/json', $this->headers($response));
        $this->assertSame([
            'model' => ['id' => 'gpt-4.1'],
            'messages' => self::MESSAGES['messages'],
            'roleId' => '',
            'temperature' => 0.2,
            'selectedMode' => 'BASIC',
            'selectedFiles' => [],
            'selectedDataCollections' => [],
        ], $this->body($response));
    }

    public function testChatNoStreamWithOptions(): void
    {
        $response = new JsonMockResponse(['content' => 'Hello']);
        $this->client($response)->request(new Ai506('gpt-4.1'), self::MESSAGES, [
            'temperature' => 0.7,
            'mode' => 'QA',
            'role_id' => 'role-1',
            'assistant_id' => 'assistant-1',
            'selected_files' => ['file.pdf'],
            'data_collections' => ['dc-1'],
            'internal_system_prompt' => false,
            'citation' => true,
        ])->getObject()->getStatusCode();

        $this->assertSame('https://acme.506.ai:3003/api/v1/public/chatNoStream?internalSystemPrompt=false&citation=true', $response->getRequestUrl());
        $this->assertSame([
            'model' => ['id' => 'gpt-4.1'],
            'messages' => self::MESSAGES['messages'],
            'roleId' => 'role-1',
            'temperature' => 0.7,
            'selectedMode' => 'QA',
            'selectedFiles' => ['file.pdf'],
            'selectedDataCollections' => ['dc-1'],
            'selectedAssistantId' => 'assistant-1',
        ], $this->body($response));
    }

    public function testStreamUsesChatEndpoint(): void
    {
        $response = new JsonMockResponse([]);
        $this->client($response)->request(new Ai506('gpt-4.1'), self::MESSAGES, ['stream' => true, 'citation' => true])->getObject()->getStatusCode();

        $this->assertSame('https://acme.506.ai:3003/api/v1/public/chat?internalSystemPrompt=true', $response->getRequestUrl());
        $this->assertContains('accept: text/event-stream', $this->headers($response));
    }

    public function testStructuredOutput(): void
    {
        $response = new JsonMockResponse(['content' => '{}']);
        $this->client($response)->request(new Ai506('gpt-4.1'), ['messages' => [
            ['role' => 'system', 'content' => 'Extract the city.', 'references' => [], 'sources' => []],
            ['role' => 'user', 'content' => 'It rains in Linz.', 'references' => [], 'sources' => []],
        ]], [
            'response_format' => [
                'type' => 'json_schema',
                'json_schema' => ['name' => 'city', 'schema' => ['type' => 'object', 'properties' => ['name' => ['type' => 'string']]]],
            ],
        ])->getObject()->getStatusCode();

        $this->assertSame('https://acme.506.ai:3003/api/v1/public/generateStructuredOutput', $response->getRequestUrl());
        $this->assertSame([
            'model' => ['id' => 'gpt-4.1'],
            'content' => "Extract the city.\n\nIt rains in Linz.",
            'selectedMode' => 'BASIC',
            'selectedFiles' => [],
            'selectedDataCollections' => [],
            'jsonStructure' => [
                'name' => 'city',
                'schema' => [['description' => 'city', 'properties' => ['name' => ['type' => 'string']]]],
            ],
        ], $this->body($response));
    }

    public function testStructuredOutputCannotStream(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client(new JsonMockResponse([]))->request(new Ai506('gpt-4.1'), self::MESSAGES, ['stream' => true, 'response_format' => []]);
    }

    public function testAgentModeIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Agent mode');

        $this->client(new JsonMockResponse([]))->request(new Ai506('gpt-4.1'), self::MESSAGES, ['mode' => 'AGENT']);
    }

    public function testRejectsStringPayload(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->client(new JsonMockResponse([]))->request(new Ai506('gpt-4.1'), 'Hi');
    }

    private function client(JsonMockResponse $response): ModelClient
    {
        return new ModelClient(new MockHttpClient($response), 'https://acme.506.ai:3003', 'org', 'key');
    }

    /**
     * @return list<string>
     */
    private function headers(JsonMockResponse $response): array
    {
        /** @var list<string> $headers */
        $headers = $response->getRequestOptions()['headers'];

        return array_map(strtolower(...), $headers);
    }

    /**
     * @return array<mixed>
     */
    private function body(JsonMockResponse $response): array
    {
        $body = $response->getRequestOptions()['body'];
        $this->assertIsString($body);
        $decoded = json_decode($body, true, flags: \JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}
