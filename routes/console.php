<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('biomass:backfill-growth --apply --active-only')->dailyAt('00:10')->timezone('Asia/Jakarta')->withoutOverlapping();
