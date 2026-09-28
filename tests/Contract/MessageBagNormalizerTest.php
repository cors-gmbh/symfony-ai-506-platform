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

namespace CORS\AI\Platform\Bridge\Ai506\Tests\Contract;

use CORS\AI\Platform\Bridge\Ai506\Ai506;
use CORS\AI\Platform\Bridge\Ai506\Contract\MessageBagNormalizer;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Contract;
use Symfony\AI\Platform\Exception\InvalidArgumentException;
use Symfony\AI\Platform\Message\Content\ImageUrl;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Model;
use Symfony\AI\Platform\Result\ToolCall;

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

    public function testRejectsNonTextContent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('only supports text input');

        $this->payload(new MessageBag(Message::ofUser('Describe', new ImageUrl('https://example.com/bread.jpg'))));
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
    private function payload(MessageBag $messages): array|string
    {
        return Contract::create([new MessageBagNormalizer()])->createRequestPayload(new Ai506('gpt-4.1'), $messages);
    }
}
