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

use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\ModelClientInterface;
use Symfony\AI\Platform\Result\RawHttpResult;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Routes a request to the matching 506.ai endpoint:
 *  - response_format set → POST /v1/public/generateStructuredOutput
 *  - stream              → POST /v1/public/chat (text/event-stream)
 *  - otherwise           → POST /v1/public/chatNoStream
 */
final readonly class ModelClient implements ModelClientInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        #[\SensitiveParameter]
        private string $organizationId,
        #[\SensitiveParameter]
        private string $apiKey,
    ) {
    }

    public function supports(Model $model): bool
    {
        return $model instanceof Ai506;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function request(Model $model, array|string $payload, array $options = []): RawHttpResult
    {
        if (!\is_array($payload) || !\is_array($payload['messages'] ?? null)) {
            throw new InvalidArgumentException('506.ai expects a MessageBag as input.');
        }

        /** @var list<array{role: string, content: string}> $messages */
        $messages = $payload['messages'];

        if ('AGENT' === ($options['mode'] ?? null)) {
            throw new InvalidArgumentException('Agent mode is not available via the 506.ai public API.');
        }

        if (isset($options[PlatformSubscriber::RESPONSE_FORMAT])) {
            return $this->structuredOutput($model, $messages, $options);
        }

        $body = [
            'model' => ['id' => $model->getName()],
            'messages' => $messages,
            'roleId' => $options['role_id'] ?? '',
            // mandatory on 506 side (400 without), ignored when an assistant is selected
            'temperature' => $options['temperature'] ?? 0.2,
            'selectedMode' => $options['mode'] ?? 'BASIC',
            'selectedFiles' => $options['selected_files'] ?? [],
            'selectedDataCollections' => $options['data_collections'] ?? [],
        ];

        if (isset($options['assistant_id'])) {
            $body['selectedAssistantId'] = $options['assistant_id'];
        }

        $query = [
            'internalSystemPrompt' => $this->bool($options['internal_system_prompt'] ?? true),
        ];

        if (true === ($options['stream'] ?? false)) {
            return $this->post('v1/public/chat', $body, $query, 'text/event-stream');
        }

        if (isset($options['citation'])) {
            $query['citation'] = $this->bool($options['citation']);
        }

        return $this->post('v1/public/chatNoStream', $body, $query);
    }

    /**
     * generateStructuredOutput has no chat history, only "content" — the messages are flattened.
     *
     * @param list<array{role: string, content: string}> $messages
     * @param array<string, mixed>                       $options
     */
    private function structuredOutput(Model $model, array $messages, array $options): RawHttpResult
    {
        if (true === ($options['stream'] ?? false)) {
            throw new InvalidArgumentException('506.ai does not support streaming structured output.');
        }

        /** @var array<string, mixed> $responseFormat */
        $responseFormat = $options[PlatformSubscriber::RESPONSE_FORMAT];

        return $this->post('v1/public/generateStructuredOutput', [
            'model' => ['id' => $model->getName()],
            'content' => implode("\n\n", array_column($messages, 'content')),
            'selectedMode' => $options['mode'] ?? 'BASIC',
            'selectedFiles' => $options['selected_files'] ?? [],
            'selectedDataCollections' => $options['data_collections'] ?? [],
            'jsonStructure' => JsonStructureConverter::convert($responseFormat),
        ]);
    }

    /**
     * @param array<string, mixed>  $body
     * @param array<string, string> $query
     */
    private function post(string $path, array $body, array $query = [], string $accept = 'application/json'): RawHttpResult
    {
        $response = $this->httpClient->request('POST', $this->baseUrl.'/api/'.$path, [
            'headers' => [
                'api-organization-id' => $this->organizationId,
                'api-key' => $this->apiKey,
                'Accept' => $accept,
            ],
            'query' => $query,
            'json' => $body,
        ]);

        return new RawHttpResult($response, new LineEventStream($this->httpClient));
    }

    private function bool(mixed $value): string
    {
        return (bool) $value ? 'true' : 'false';
    }
}
