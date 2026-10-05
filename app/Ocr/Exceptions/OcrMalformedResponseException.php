<?php

namespace App\Ocr\Exceptions;

/**
 * The provider answered, but not with anything BizFlow can use. Not retried automatically (the
 * same input would likely give the same answer); the user may retry or enter the details by hand.
 */
class OcrMalformedResponseException extends OcrException {}
