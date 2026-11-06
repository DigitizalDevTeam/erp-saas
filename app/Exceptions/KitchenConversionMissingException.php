<?php

namespace App\Exceptions;

use InvalidArgumentException;

/**
 * Thrown when a recipe line cannot be converted to the ingredient's
 * stock unit because no conversion is defined. The recipe must BLOCK
 * approval in this case — the system must never guess a factor.
 */
class KitchenConversionMissingException extends InvalidArgumentException
{
}
