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

namespace CORS\AI\Platform\Bridge\Ai506\Contract;

use CORS\AI\Platform\Bridge\Ai506\Ai506;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;

/**
 * 506.ai only understands plain text messages: {role, content, references: [], sources: []}.
 */
final class MessageBagNormalizer extends ModelContractNormalizer
{
    /**
     * @return array{messages: list<array{role: string, content: string, references: array{}, sources: array{}}>}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof MessageBag) {
            throw new InvalidArgumentException(\sprintf('Expected "%s", got "%s".', MessageBag::class, get_debug_type($data)));
        }

        return [
            'messages' => array_map($this->normalizeMessage(...), $data->getMessages()),
        ];
    }

    protected function supportedDataClass(): string
    {
        return MessageBag::class;
    }

    protected function supportsModel(Model $model): bool
    {
        return $model instanceof Ai506;
    }

    /**
     * @return array{role: string, content: string, references: array{}, sources: array{}}
     */
    private function normalizeMessage(MessageInterface $message): array
    {
        $content = match (true) {
            $message instanceof SystemMessage => (string) $message->getContent(),
            $message instanceof UserMessage => $this->userText($message),
            $message instanceof AssistantMessage => $message->asText() ?? '',
            default => throw new InvalidArgumentException(\sprintf('506.ai does not support "%s" (no tool calling via the public API).', $message::class)),
        };

        return [
            'role' => $message->getRole()->value,
            'content' => $content,
            'references' => [],
            'sources' => [],
        ];
    }

    private function userText(UserMessage $message): string
    {
        foreach ($message->getContent() as $content) {
            if (!$content instanceof Text) {
                throw new InvalidArgumentException(\sprintf('506.ai only supports text input, got "%s". Upload media via the 506.ai API and pass it as "selected_files".', $content::class));
            }
        }

        return $message->asText() ?? '';
    }
}
