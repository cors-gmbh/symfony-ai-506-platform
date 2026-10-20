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

use CORS\AI\Platform\Bridge\Ai506\TextExtractor;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Platform\Exception\RateLimitExceededException;
use Symfony\AI\Platform\Exception\RuntimeException;
use Symfony\AI\Platform\Message\Content\Document;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\JsonMockResponse;

final class TextExtractorTest extends TestCase
{
    public function testExtractsAllFilesInOneRequest(): void
    {
        $response = new JsonMockResponse([
            ['filename' => 'document-1.pdf', 'status' => 200, 'text' => 'Flour, salt'],
            ['filename' => 'document-2.docx', 'status' => 200, 'text' => 'Bake at 220 °C'],
        ], ['http_code' => 207]);

        $texts = $this->extractor($response)->extract([
            new Document('%PDF-1.4 recipe', 'application/pdf'),
            new Document('PK docx', 'application/octet-stream', '/tmp/Back Anleitung.DOCX'),
        ]);

        $this->assertSame(['Flour, salt', 'Bake at 220 °C'], $texts);
        $this->assertSame('POST', $response->getRequestMethod());
        $this->assertSame('https://acme.506.ai:3003/api/v1/public/extractText', $response->getRequestUrl());

        $headers = $this->headers($response);
        $this->assertContains('api-organization-id: org', $headers);
        $this->assertContains('api-key: key', $headers);
        $this->assertNotEmpty(preg_grep('/^content-type: multipart\/form-data; boundary=/', $headers));

        $body = $this->body($response);
        $this->assertStringContainsString('name="media"; filename="document-1.pdf"', $body);
        $this->assertStringContainsString('%PDF-1.4 recipe', $body);
        $this->assertStringContainsString('name="media"; filename="document-2.docx"', $body);
        $this->assertStringContainsString('PK docx', $body);
    }

    public function testRetriesPdfsWithoutTextWithOcr(): void
    {
        $extract = new JsonMockResponse([
            ['filename' => 'document-1.pdf', 'status' => 422],
            ['filename' => 'document-2.pdf', 'status' => 200, 'text' => 'Digital'],
        ], ['http_code' => 207]);
        $ocr = new JsonMockResponse([
            ['filename' => 'document-1.pdf', 'status' => 200, 'text' => 'Scanned'],
        ], ['http_code' => 207]);

        $texts = $this->extractor($extract, $ocr)->extract([
            new Document('scan', 'application/pdf'),
            new Document('digital', 'application/pdf'),
        ]);

        $this->assertSame(['Scanned', 'Digital'], $texts);
        $this->assertSame('https://acme.506.ai:3003/api/v1/public/extractTextOcr', $ocr->getRequestUrl());
        $this->assertStringContainsString('filename="document-1.pdf"', $this->body($ocr));
        $this->assertStringNotContainsString('filename="document-2.pdf"', $this->body($ocr));
    }

    public function testNoOcrForOtherFileTypes(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('506.ai could not extract text from "notes.txt" (422 the file contains no text).');

        $this->extractor(new JsonMockResponse([
            ['filename' => 'document-1.txt', 'status' => 422],
        ], ['http_code' => 207]))->extract([new Document('', 'text/plain', '/tmp/notes.txt')]);
    }

    public function testUnsupportedFileType(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('(415 unsupported file type)');

        $this->extractor(new JsonMockResponse([
            ['filename' => 'document-1.zip', 'status' => 415],
        ], ['http_code' => 207]))->extract([new Document('PK', 'application/zip')]);
    }

    public function testMissingResult(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('506.ai returned no text for "document".');

        $this->extractor(new JsonMockResponse([]))->extract([new Document('%PDF', 'application/pdf')]);
    }

    public function testRateLimit(): void
    {
        $this->expectException(RateLimitExceededException::class);

        $this->extractor(new JsonMockResponse(['message' => 'Too many requests'], ['http_code' => 429]))
            ->extract([new Document('%PDF', 'application/pdf')]);
    }

    public function testForbidden(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('506.ai text extraction responded 403');

        $this->extractor(new JsonMockResponse(['message' => 'Forbidden'], ['http_code' => 403]))
            ->extract([new Document('%PDF', 'application/pdf')]);
    }

    private function extractor(JsonMockResponse ...$responses): TextExtractor
    {
        return new TextExtractor(new MockHttpClient($responses), 'https://acme.506.ai:3003', 'org', 'key');
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

    private function body(JsonMockResponse $response): string
    {
        $body = $response->getRequestOptions()['body'];
        $this->assertIsString($body);

        return $body;
    }
}
