<?php

namespace Reach\StatamicResrv\Traits;

trait HandlesComparisons
{
    private $operator = [
        '==' => 'equal',
        '!=' => 'notEqual',
        '>' => 'greaterThan',
        '<' => 'lessThan',
        '>=' => 'greaterOrEqualThan',
        '<=' => 'lessOrEqualThan',
    ];

    protected function compare($value_a, $operation, $value_b)
    {
        if ($method = $this->operator[$operation]) {
            return $this->$method($value_a, $value_b);
        }

        throw new \Exception('Unknown operator');
    }

    private function equal($value_a, $value_b)
    {
        return $value_a == $value_b;
    }

    private function notEqual($value_a, $value_b)
    {
        return $value_a != $value_b;
    }

    private function greaterThan($value_a, $value_b)
    {
        return $value_a > $value_b;
    }

    private function lessThan($value_a, $value_b)
    {
        return $value_a < $value_b;
    }

    private function greaterOrEqualThan($value_a, $value_b)
    {
        return $value_a >= $value_b;
    }

    private function lessOrEqualThan($value_a, $value_b)
    {
        return $value_a <= $value_b;
    }
}
