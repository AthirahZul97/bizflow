<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * bcrypt only reads the first 72 BYTES of a password and ignores the rest, so a password longer than
 * that would be accepted but only partly checked. "max:72" counts characters, and one emoji is four
 * bytes, so this rule counts bytes. (Hashing does not enforce it: the check belongs here, where the
 * user can be told.)
 */
class PasswordFitsBcrypt implements ValidationRule
{
    public const MAX_BYTES = 72;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (is_string($value) && strlen($value) > self::MAX_BYTES) {
            $fail('The :attribute is too long: at most '.self::MAX_BYTES.' bytes are allowed, and characters such as emoji or accented letters count for more than one.');
        }
    }
}
