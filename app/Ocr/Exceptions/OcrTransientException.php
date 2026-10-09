<?php

namespace App\Ocr\Exceptions;

/**
 * Trying again later may work: a timeout, a rate limit, an outage.
 */
class OcrTransientException extends OcrException {}
