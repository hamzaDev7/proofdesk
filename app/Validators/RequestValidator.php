<?php

namespace ProofDesk\Validators;

class RequestValidator
{
    private array $data;
    private array $errors = [];

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public static function validate(array $data, array $rules): array
    {
        $validator = new self($data);
        foreach ($rules as $field => $fieldRules) {
            $ruleList = is_string($fieldRules) ? explode('|', $fieldRules) : $fieldRules;
            $val = $data[$field] ?? null;

            foreach ($ruleList as $rule) {
                $validator->applyRule($field, $val, $rule);
            }
        }

        if (!empty($validator->errors)) {
            return [
                'valid' => false,
                'errors' => $validator->errors
            ];
        }

        return [
            'valid' => true,
            'errors' => []
        ];
    }

    private function applyRule(string $field, mixed $val, string $rule): void
    {
        $params = [];
        if (str_contains($rule, ':')) {
            [$ruleName, $paramStr] = explode(':', $rule, 2);
            $params = explode(',', $paramStr);
        } else {
            $ruleName = $rule;
        }

        switch ($ruleName) {
            case 'required':
                if ($val === null || $val === '' || (is_array($val) && empty($val))) {
                    $this->addError($field, sprintf('%s is required.', $this->prettify($field)));
                }
                break;

            case 'email':
                if ($val !== null && $val !== '' && !filter_var($val, FILTER_VALIDATE_EMAIL)) {
                    $this->addError($field, sprintf('%s must be a valid email address.', $this->prettify($field)));
                }
                break;

            case 'min':
                $min = (int)($params[0] ?? 0);
                if (is_string($val) && mb_strlen($val) < $min) {
                    $this->addError($field, sprintf('%s must be at least %d characters.', $this->prettify($field), $min));
                } elseif (is_numeric($val) && $val < $min) {
                    $this->addError($field, sprintf('%s must be at least %d.', $this->prettify($field), $min));
                }
                break;

            case 'max':
                $max = (int)($params[0] ?? 255);
                if (is_string($val) && mb_strlen($val) > $max) {
                    $this->addError($field, sprintf('%s cannot exceed %d characters.', $this->prettify($field), $max));
                } elseif (is_numeric($val) && $val > $max) {
                    $this->addError($field, sprintf('%s cannot exceed %d.', $this->prettify($field), $max));
                }
                break;

            case 'numeric':
                if ($val !== null && $val !== '' && !is_numeric($val)) {
                    $this->addError($field, sprintf('%s must be a number.', $this->prettify($field)));
                }
                break;

            case 'in':
                if ($val !== null && $val !== '' && !in_array($val, $params)) {
                    $this->addError($field, sprintf('%s must be one of: %s.', $this->prettify($field), implode(', ', $params)));
                }
                break;

            case 'uuid':
                if ($val !== null && $val !== '' && !preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $val)) {
                    $this->addError($field, sprintf('%s must be a valid UUID.', $this->prettify($field)));
                }
                break;
        }
    }

    private function addError(string $field, string $message): void
    {
        if (!isset($this->errors[$field])) {
            $this->errors[$field] = [];
        }
        $this->errors[$field][] = $message;
    }

    private function prettify(string $field): string
    {
        return ucfirst(str_replace('_', ' ', $field));
    }
}
