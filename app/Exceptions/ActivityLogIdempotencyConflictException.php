<?php

namespace App\Exceptions;

use InvalidArgumentException;

class ActivityLogIdempotencyConflictException extends InvalidArgumentException {}
