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

use CORS\AI\Platform\Bridge\Ai506\LineEventStream;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

final class LineEventStreamTest extends TestCase
{
    public function testYieldsOneEventPerLine(): void
    {
        $this->assertSame(
            ['### Breads', '', '- **Rye**', '  - indented', '- last without separator'],
            $this->stream([
                "data:### Breads\n\ndata:\n\n",
                'data:- **Ry',
                "e**\n",
                "\ndata:  - indented\n\n",
                'data:- last without separator',
            ]),
        );
    }

    public function testCrLfAndMultiLineEvents(): void
    {
        $this->assertSame(
            ['first', "multi\nline"],
            $this->stream(["data:first\r\n\r\n", ": comment\r\ndata:multi\r\ndata:line\r\n\r\n"]),
        );
    }

    /**
     * @param list<string> $chunks
     *
     * @return list<string>
     */
    private function stream(array $chunks): array
    {
        $client = new MockHttpClient(new MockResponse($chunks, ['response_headers' => ['content-type' => 'text/event-stream']]));
        $response = $client->request('POST', 'https://acme.506.ai:3003/api/v1/public/chat');

        $lines = [];
        foreach ((new LineEventStream($client))->stream($response) as $event) {
            $lines[] = $event['text'];
        }

        return $lines;
    }
}
