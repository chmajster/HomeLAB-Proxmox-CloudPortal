<?php

declare(strict_types=1);

namespace CloudPortal\Http;

final class Validator
{
    /** @param array<string,mixed> $data @param array<string,string> $rules @return array<string,mixed> */
    public static function validate(array $data, array $rules): array
    {
        $errors = [];
        $validated = [];
        foreach ($rules as $field => $ruleString) {
            $value = $data[$field] ?? null;
            $fieldRules = explode('|', $ruleString);
            foreach ($fieldRules as $rule) {
                [$name, $argument] = array_pad(explode(':', $rule, 2), 2, null);
                $invalid = match ($name) {
                    'required' => $value === null || $value === '',
                    'string' => $value !== null && !is_string($value),
                    'int' => $value !== null && filter_var($value, FILTER_VALIDATE_INT) === false,
                    'bool' => $value !== null && !in_array($value, [true, false, 0, 1, '0', '1'], true),
                    'email' => $value !== null && filter_var($value, FILTER_VALIDATE_EMAIL) === false,
                    'min' => $value !== null && ((is_string($value) ? mb_strlen($value) : (float) $value) < (float) $argument),
                    'max' => $value !== null && ((is_string($value) ? mb_strlen($value) : (float) $value) > (float) $argument),
                    'regex' => $value !== null && (!is_string($value) || preg_match($argument ?? '//', $value) !== 1),
                    'in' => $value !== null && !in_array((string) $value, explode(',', (string) $argument), true),
                    default => throw new \LogicException("Unknown validation rule: {$name}"),
                };
                if ($invalid) {
                    $errors[$field][] = $name;
                }
            }
            if (!isset($errors[$field]) && array_key_exists($field, $data)) {
                $validated[$field] = $value;
            }
        }
        if ($errors !== []) {
            throw new HttpException(422, 'Validation failed.', ['fields' => $errors]);
        }
        return $validated;
    }
}

