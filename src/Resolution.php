<?php

namespace SimpleTrader;

enum Resolution {
    case Tick;
    case Minute;
    case Hourly;
    case FourHours;
    case Daily;
    case Weekly;
    case Monthly;
}