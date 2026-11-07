<?php

namespace App\Exceptions;

use Exception;

/**
 * Domain rule violation in the Treasury module (duplicate account code,
 * currency locked, deactivation blocked, missing exchange rate,
 * confirmed row edited, negative-balance policy, ...). The controller
 * turns this into a friendly redirect error; the service layer throws
 * it so later phases can rely on the same guards.
 */
class TreasuryException extends Exception
{
}
