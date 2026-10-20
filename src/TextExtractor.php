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
use Symfony\AI\Platform\Message\Content\File;
use Symfony\AI\Platform\Result\HttpStatusErrorHandlingTrait;
use Symfony\Component\Mime\MimeTypes;
use Symfony\Component\Mime\Part\DataPart;
use Symfony\Component\Mime\Part\Multipart\FormDataPart;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * 506.ai has no binary content in chat messages — documents are turned into text first:
 *  - POST /v1/public/extractText     (pdf, docx, pptx, xlsx, csv, txt, …; 15 requests per minute)
 *  - POST /v1/public/extractTextOcr  (PDFs without a text layer; 10 requests per minute)
 *
 * All files go into one multipart request (field "media"), the OCR retry into a second one.
 */
final readonly class TextExtractor
{
    use HttpStatusErrorHandlingTrait;

    private const FILE_ERRORS = [
        415 => 'unsupported file type',
        422 => 'the file contains no text',
    ];

    public function __construct(
        private HttpClientInterface $httpClient,
        private string $baseUrl,
        #[\SensitiveParameter]
        private string $organizationId,
        #[\SensitiveParameter]
        private string $apiKey,
    ) {
    }

    /**
     * @param list<File> $files
     *
     * @return list<string> the text of each file, in the given order
     */
    public function extract(array $files): array
    {
        if ([] === $files) {
            return [];
        }

        // generated ASCII names — the response is matched by filename, the real names may contain anything
        $names = [];
        foreach ($files as $i => $file) {
            $names[$i] = \sprintf('document-%d.%s', $i + 1, $this->extension($file));
        }

        $texts = [];
        $ocr = [];
        $results = $this->request('v1/public/extractText', $files, $names);
        foreach ($files as $i => $file) {
            $status = $results[$names[$i]]['status'] ?? null;
            if (422 === $status && str_ends_with($names[$i], '.pdf')) {
                $ocr[$i] = $file;

                continue;
            }

            $texts[$i] = $this->text($results[$names[$i]] ?? null, $file);
        }

        if ([] !== $ocr) {
            $results = $this->request('v1/public/extractTextOcr', $ocr, $names);
            foreach ($ocr as $i => $file) {
                $texts[$i] = $this->text($results[$names[$i]] ?? null, $file);
            }
        }

        ksort($texts);

        return array_values($texts);
    }

    /**
     * @param array<int, File>   $files
     * @param array<int, string> $names
     *
     * @return array<string, array<mixed>> per-file results by filename
     */
    private function request(string $path, array $files, array $names): array
    {
        $fields = [];
        foreach ($files as $i => $file) {
            $fields[] = ['media' => new DataPart($file->asBinary(), $names[$i], $file->getFormat())];
        }
        $form = new FormDataPart($fields);

        $response = $this->httpClient->request('POST', $this->baseUrl.'/api/'.$path, [
            'headers' => [
                'api-organization-id' => $this->organizationId,
                'api-key' => $this->apiKey,
                'Accept' => 'application/json',
                ...$form->getPreparedHeaders()->toArray(),
            ],
            'body' => $form->bodyToString(),
        ]);

        $this->throwOnHttpError($response);

        if (($status = $response->getStatusCode()) >= 400) {
            $body = trim($response->getContent(false));

            throw new RuntimeException(\sprintf('506.ai text extraction responded %d%s', $status, '' !== $body ? ': '.$body : ''));
        }

        $results = [];
        foreach ($response->toArray() as $result) {
            if (\is_array($result) && \is_string($result['filename'] ?? null)) {
                $results[$result['filename']] = $result;
            }
        }

        return $results;
    }

    /**
     * @param array<mixed>|null $result
     */
    private function text(?array $result, File $file): string
    {
        $name = $file->getFilename() ?? 'document';

        if (null === $result) {
            throw new RuntimeException(\sprintf('506.ai returned no text for "%s".', $name));
        }

        $status = \is_int($result['status'] ?? null) ? $result['status'] : 0;
        if (200 !== $status || !\is_string($result['text'] ?? null)) {
            throw new RuntimeException(\sprintf('506.ai could not extract text from "%s" (%d %s).', $name, $status, self::FILE_ERRORS[$status] ?? 'error'));
        }

        return $result['text'];
    }

    /**
     * 506 decides by file extension: the one of the path, else the default for the MIME type.
     */
    private function extension(File $file): string
    {
        $extension = strtolower(pathinfo($file->asPath() ?? '', \PATHINFO_EXTENSION));

        return '' !== $extension ? $extension : (MimeTypes::getDefault()->getExtensions($file->getFormat())[0] ?? 'bin');
    }
}
