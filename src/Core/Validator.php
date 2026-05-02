<?php
declare(strict_types=1);

namespace App\Core;

final class Validator
{
    /**
     * Rules:
     *   required, string, int, numeric, email, min:N, max:N, in:a,b,c, regex:/.../, date
     * Returns the validated array on success or aborts with 422.
     */
    public static function check(array $data, array $rules): array
    {
        $errors = [];
        $clean  = [];

        foreach ($rules as $field => $ruleStr) {
            $value = $data[$field] ?? null;
            $list  = is_array($ruleStr) ? $ruleStr : explode('|', $ruleStr);
            $required = in_array('required', $list, true);

            if ($value === null || $value === '') {
                if ($required) $errors[$field][] = 'required';
                continue;
            }

            foreach ($list as $rule) {
                if ($rule === 'required') continue;
                [$name, $arg] = array_pad(explode(':', $rule, 2), 2, null);
                switch ($name) {
                    case 'string':  if (!is_string($value)) $errors[$field][] = 'must_be_string'; break;
                    case 'int':     if (!is_int($value) && !ctype_digit((string)$value)) $errors[$field][] = 'must_be_int'; else $value = (int)$value; break;
                    case 'numeric': if (!is_numeric($value)) $errors[$field][] = 'must_be_numeric'; break;
                    case 'email':   if (!filter_var($value, FILTER_VALIDATE_EMAIL)) $errors[$field][] = 'invalid_email'; break;
                    case 'min':     if (mb_strlen((string)$value) < (int)$arg) $errors[$field][] = "min_$arg"; break;
                    case 'max':     if (mb_strlen((string)$value) > (int)$arg) $errors[$field][] = "max_$arg"; break;
                    case 'in':      if (!in_array((string)$value, explode(',', (string)$arg), true)) $errors[$field][] = "must_be_in_$arg"; break;
                    case 'regex':   if (!preg_match((string)$arg, (string)$value)) $errors[$field][] = 'invalid_format'; break;
                    case 'date':    if (!strtotime((string)$value)) $errors[$field][] = 'invalid_date'; break;
                }
            }
            $clean[$field] = $value;
        }

        if ($errors) {
            Response::error('validation_failed', 422, ['details' => $errors]);
        }
        return $clean;
    }
}
