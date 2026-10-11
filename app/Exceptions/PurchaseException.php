<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown when an order cannot be created. The message is safe to show on screen.
 */
class PurchaseException extends RuntimeException {}
