<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('gifter:expire-certificates')->dailyAt('00:10');
