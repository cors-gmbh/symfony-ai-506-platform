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

use Symfony\AI\Platform\Result\Stream\HttpStreamInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;

/**
 * 506.ai streams one SSE event per line of answer text ("data:<line>\n\n", no JSON, no space after
 * the colon). This yields ['text' => <line>] for every event. Symfony's EventSourceHttpClient is avoided on
 * purpose: it strips one leading space per the SSE spec, which would break indented markdown.
 */
final readonly class LineEventStream implements HttpStreamInterface
{
    public function __construct(
        private HttpClientInterface $httpClient,
    ) {
    }

    /**
     * @return \Generator<int, array{text: string}>
     */
    public function stream(ResponseInterface $response): \Generator
    {
        $buffer = '';

        foreach ($this->httpClient->stream($response) as $chunk) {
            $buffer .= $chunk->getContent();

            while (1 === preg_match('/\r\n\r\n|\n\n|\r\r/', $buffer, $match, \PREG_OFFSET_CAPTURE)) {
                $block = substr($buffer, 0, $match[0][1]);
                $buffer = substr($buffer, $match[0][1] + \strlen($match[0][0]));

                if (null !== $data = $this->extractData($block)) {
                    yield ['text' => $data];
                }
            }
        }

        if (null !== $data = $this->extractData($buffer)) {
            yield ['text' => $data];
        }
    }

    private function extractData(string $block): ?string
    {
        $data = null;
        foreach (explode("\n", str_replace(["\r\n", "\r"], "\n", $block)) as $line) {
            if (str_starts_with($line, 'data:')) {
                $payload = substr($line, 5);
                $data = null === $data ? $payload : $data."\n".$payload;
            }
        }

        return $data;
    }
}
