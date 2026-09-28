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
