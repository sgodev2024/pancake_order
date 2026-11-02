<?php

namespace App\Exceptions;

/** Verification was deferred because a source change is waiting to project. */
class OrderSummaryPendingException extends \RuntimeException
{
}
