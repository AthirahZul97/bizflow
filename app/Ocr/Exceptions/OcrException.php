<?php

namespace App\Ocr\Exceptions;

use RuntimeException;

/**
 * A provider could not read a receipt. The message is shown to the user and stored, so it
 * must be short, generic and free of receipt contents and provider response bodies.
 */
abstract class OcrException extends RuntimeException {}
