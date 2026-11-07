<?php

namespace App\Exceptions;

use Exception;

/**
 * Contract Management — billing contract domain errors.
 *
 * Thrown by BillingContractService / BillingOccurrenceService for
 * validation failures and illegal lifecycle transitions. The
 * controller turns these into a redirect with the message; they
 * are never a 500.
 */
class BillingContractException extends Exception
{
}
