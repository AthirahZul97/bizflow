<?php

namespace App\Billing;

use RuntimeException;

/**
 * Raised by a billing provider when something it was asked to do isn't available or can't be
 * trusted.
 */
class BillingProviderException extends RuntimeException {}
