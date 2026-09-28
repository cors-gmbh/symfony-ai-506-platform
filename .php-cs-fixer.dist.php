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

$header = <<<'EOF_HEADER'
    CORS GmbH

    This source file is available under the MIT license

    Full copyright and license information is available in
    LICENSE.md which is distributed with this source code.

    @copyright  Copyright (c) CORS GmbH (https://www.cors.gmbh)
    @license    https://opensource.org/license/mit MIT
    EOF_HEADER;

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PHP8x2Migration' => true,
        '@PHPUnit10x0Migration:risky' => true,
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'declare_strict_types' => true,
        'header_comment' => ['header' => $header],
        'native_function_invocation' => ['include' => ['@compiler_optimized'], 'scope' => 'namespaced'],
        'phpdoc_to_comment' => false,
    ])
    ->setFinder(
        (new PhpCsFixer\Finder())
            ->in([__DIR__.'/src', __DIR__.'/tests'])
            ->append([__FILE__])
    )
;
