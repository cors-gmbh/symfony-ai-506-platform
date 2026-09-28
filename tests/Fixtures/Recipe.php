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

namespace CORS\AI\Platform\Bridge\Ai506\Tests\Fixtures;

final class Recipe
{
    public string $title;
    public int $servings;
    /** @var list<Ingredient> */
    public array $ingredients = [];
    public bool $vegan;
}
