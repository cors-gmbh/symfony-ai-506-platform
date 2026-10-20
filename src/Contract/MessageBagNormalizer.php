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
use CORS\AI\Platform\Bridge\Ai506\TextExtractor;
use Symfony\AI\Platform\Contract\Normalizer\ModelContractNormalizer;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\AI\Platform\Message\Content\Text;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\MessageInterface;
use Symfony\AI\Platform\Message\SystemMessage;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\AI\Platform\Model;

/**
 * 506.ai only understands plain text messages: {role, content, references: [], sources: []}.
 *
 * Documents in user messages are turned into text by the TextExtractor (one request for the whole bag) and
 * inlined as <document name="…">…</document> blocks.
 */
final class MessageBagNormalizer extends ModelContractNormalizer
{
    public function __construct(
        private readonly ?TextExtractor $textExtractor = null,
    ) {
    }

    /**
     * @return array{messages: list<array{role: string, content: string, references: array{}, sources: array{}}>}
     */
    public function normalize(mixed $data, ?string $format = null, array $context = []): array
    {
        if (!$data instanceof MessageBag) {
            throw new InvalidArgumentException(\sprintf('Expected "%s", got "%s".', MessageBag::class, get_debug_type($data)));
        }

        $documents = $this->extractDocuments($data);

        return [
            'messages' => array_map(fn (MessageInterface $message): array => $this->normalizeMessage($message, $documents), $data->getMessages()),
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
     * @param \WeakMap<Document, string> $documents
     *
     * @return array{role: string, content: string, references: array{}, sources: array{}}
     */
    private function normalizeMessage(MessageInterface $message, \WeakMap $documents): array
    {
        $content = match (true) {
            $message instanceof SystemMessage => (string) $message->getContent(),
            $message instanceof UserMessage => $this->userText($message, $documents),
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

    /**
     * Text parts are joined with a space like UserMessage::asText(), documents get a paragraph of their own.
     *
     * @param \WeakMap<Document, string> $documents
     */
    private function userText(UserMessage $message, \WeakMap $documents): string
    {
        $text = '';
        $previous = null;
        foreach ($message->getContent() as $content) {
            $part = match (true) {
                $content instanceof Text => $content->getText(),
                $content instanceof Document => $this->documentBlock($content, $documents[$content]),
                default => throw new InvalidArgumentException(\sprintf('506.ai only supports text and documents, got "%s". Upload other media via the 506.ai API and pass it as "selected_files".', $content::class)),
            };

            if (null !== $previous) {
                $text .= $content instanceof Text && $previous instanceof Text ? ' ' : "\n\n";
            }
            $text .= $part;
            $previous = $content;
        }

        return $text;
    }

    /**
     * @return \WeakMap<Document, string>
     */
    private function extractDocuments(MessageBag $messages): \WeakMap
    {
        $files = [];
        foreach ($messages->getMessages() as $message) {
            if (!$message instanceof UserMessage) {
                continue;
            }

            foreach ($message->getContent() as $content) {
                if ($content instanceof Document && !\in_array($content, $files, true)) {
                    $files[] = $content;
                }
            }
        }

        /** @var \WeakMap<Document, string> $documents */
        $documents = new \WeakMap();
        if ([] === $files) {
            return $documents;
        }

        if (null === $this->textExtractor) {
            throw new InvalidArgumentException(\sprintf('506.ai needs a "%s" for documents, create the platform with the Factory or pass one to "%s".', TextExtractor::class, self::class));
        }

        foreach ($this->textExtractor->extract($files) as $i => $text) {
            $documents[$files[$i]] = $text;
        }

        return $documents;
    }

    private function documentBlock(Document $document, string $text): string
    {
        $name = $document->getFilename();

        return \sprintf(
            "<document%s>\n%s\n</document>",
            null !== $name ? ' name="'.htmlspecialchars($name, \ENT_QUOTES | \ENT_XML1).'"' : '',
            trim($text),
        );
    }
}
