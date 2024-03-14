<?php

namespace SimpleTrader\Helpers;

class Calculator
{
    public static function calculate(): string
    {
        $functions = 'sqrt';
        // list of | separated functions
        // sqrt refer to bcsqrt etc.
        // function must take exactly 1 argument

        $argv = func_get_args();
        $string = str_replace(' ', '', '('.$argv[0].')');
        $string = preg_replace_callback('/\$([0-9\.]+)/', function ($matches) use ($argv) {
            return $argv[$matches[1]];
        }, $string);
        while (preg_match('/(('.$functions.')?)\(([^\)\(]*)\)/', $string, $match)) {
            while (
                preg_match('/([0-9\.]+)(\^)([0-9\.]+)/', $match[3], $m) ||
                preg_match('/([0-9\.]+)([\*\/\%])([0-9\.]+)/', $match[3], $m) ||
                preg_match('/([0-9\.]+)([\+\-])([0-9\.]+)/', $match[3], $m)
            ) {
                switch($m[2]) {
                    case '+': $result = bcadd($m[1], $m[3]); break;
                    case '-': $result = bcsub($m[1], $m[3]); break;
                    case '*': $result = bcmul($m[1], $m[3]); break;
                    case '/': $result = bcdiv($m[1], $m[3]); break;
                    case '%': $result = bcmod($m[1], $m[3]); break;
                    case '^': $result = bcpow($m[1], $m[3]); break;
                }
                $match[3] = str_replace($m[0], $result, $match[3]);
            }
            if (!empty($match[1]) && function_exists($func = 'bc'.$match[1]))  {
                $match[3] = $func($match[3]);
            }
            $string = str_replace($match[0], $match[3], $string);
        }
        return $string;
    }

    public static function compare(string $val1, string $val2): int
    {
        return bccomp($val1, $val2);
    }

    /**
     * This user-land implementation follows the implementation quite strictly;
     * it does not attempt to improve the code or algorithm in any way. It will
     * raise a warning if you have fewer than 2 values in your array, just like
     * the extension does (although as an E_USER_WARNING, not E_WARNING).
     *
     * @param array $a
     * @param bool $sample [optional] Defaults to false
     * @return float|bool The standard deviation or false on error.
     */
    public static function stdDev(array $a, bool $sample = false): float|bool
    {
        $n = count($a);
        if ($n === 0) {
            trigger_error("The array has zero elements", E_USER_WARNING);
            return false;
        }
        if ($sample && $n === 1) {
            trigger_error("The array has only 1 element", E_USER_WARNING);
            return false;
        }
        $mean = array_sum($a) / $n;
        $carry = 0.0;
        foreach ($a as $val) {
            $d = ((double) $val) - $mean;
            $carry += $d * $d;
        };
        if ($sample) {
            --$n;
        }
        return sqrt($carry / $n);
    }
}