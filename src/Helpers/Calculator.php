<?php

namespace SimpleTrader\Helpers;

class Calculator
{
    public static function calculate()
    {
        $functions = 'sqrt';
        // list of | separated functions
        // sqrt refer to bcsqrt etc.
        // function must take exactly 1 argument

        $argv = func_get_args();
        $string = str_replace(' ', '', '('.$argv[0].')');
        $string = preg_replace('/\$([0-9\.]+)/e', '$argv[$1]', $string);
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
}