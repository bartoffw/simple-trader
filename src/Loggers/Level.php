<?php

namespace SimpleTrader\Loggers;

enum Level:string {
    case Debug = 'DEBUG';
    case Info = 'INFO';
    case Warning = 'WARNING';
    case Error = 'ERROR';
    case Exec = 'EXEC';
}