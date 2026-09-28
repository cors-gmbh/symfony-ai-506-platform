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

use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\HttpStatusErrorHandlingTrait;
use Symfony\AI\Platform\Result\RawResultInterface;
use Symfony\AI\Platform\Result\ResultInterface;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\Result\StreamResult;
use Symfony\AI\Platform\Result\TextResult;
use Symfony\AI\Platform\ResultConverterInterface;
use Symfony\AI\Platform\StructuredOutput\PlatformSubscriber;
use Symfony\AI\Platform\TokenUsage\TokenUsageExtractorInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

final class ResultConverter implements ResultConverterInterface
{
    use HttpStatusErrorHandlingTrait;

    private const ERRORS = [
        403 => 'Forbidden — no permission on files, data collections, roles or assistant (or agent mode)',
        406 => 'Not Acceptable — model id not allowed for this organization',
        413 => 'Payload Too Large',
    ];

    public function supports(Model $model): bool
    {
        return $model instanceof Ai506;
    }

    /**
     * @param array<string, mixed> $options
     */
    public function convert(RawResultInterface $result, array $options = []): ResultInterface
    {
        $response = $result->getObject();
        if (!$response instanceof ResponseInterface) {
            throw new RuntimeException('506.ai result must wrap an HTTP response.');
        }

        $this->throwOnHttpError($response);

        if (($status = $response->getStatusCode()) >= 400) {
            $body = trim($response->getContent(false));

            throw new RuntimeException(\sprintf('506.ai responded %d %s%s', $status, self::ERRORS[$status] ?? 'error', '' !== $body ? ': '.$body : ''));
        }

        if (true === ($options['stream'] ?? false)) {
            return new StreamResult($this->convertStream($result));
        }

        $data = $result->getData();
        $content = $data['content'] ?? null;
        if (!\is_string($content)) {
            throw new RuntimeException('506.ai response does not contain "content".');
        }

        if (isset($options[PlatformSubscriber::RESPONSE_FORMAT])) {
            $content = $this->unwrapStructuredOutput($content);
        }

        $textResult = new TextResult($content);
        $metadata = $textResult->getMetadata();
        if ([] !== ($data['references'] ?? [])) {
            $metadata->add('references', $data['references']);
        }
        if (isset($data['generationId'])) {
            $metadata->add('generation_id', $data['generationId']);
        }

        return $textResult;
    }

    public function getTokenUsageExtractor(): ?TokenUsageExtractorInterface
    {
        // 506.ai does not report token usage
        return null;
    }

    /**
     * Every event is one line of the answer, the line breaks between them are implicit.
     *
     * @return \Generator<int, TextDelta>
     */
    private function convertStream(RawResultInterface $result): \Generator
    {
        $first = true;
        foreach ($result->getDataStream() as $event) {
            $line = \is_string($event['text'] ?? null) ? $event['text'] : '';

            yield new TextDelta($first ? $line : "\n".$line);
            $first = false;
        }
    }

    /**
     * 506 answers with {"item": {…}} — unwrap it so Symfony AI can deserialize into the response class.
     */
    private function unwrapStructuredOutput(string $content): string
    {
        try {
            $decoded = json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new RuntimeException('506.ai structured output is not valid JSON.', previous: $e);
        }

        if (\is_array($decoded) && 1 === \count($decoded) && \array_key_exists('item', $decoded)) {
            return json_encode($decoded['item'], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);
        }

        return $content;
    }
}
