<?php

namespace App\Ocr\Exceptions;

/**
 * Trying again cannot work: the provider rejected the file or the credentials.
 */
class OcrPermanentException extends OcrException {}
