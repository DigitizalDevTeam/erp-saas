<?php

namespace App\Exceptions;

use Exception;

/**
 * Domain rule violation in the Cost Centre module (cycle, cross-company
 * link, duplicate code, inactive centre taking a transaction, delete
 * while referenced, ...). The controller turns this into a friendly
 * redirect error; the service layer throws it so Phase 1 can rely on
 * the same guards.
 */
class CostCentreException extends Exception
{
}
