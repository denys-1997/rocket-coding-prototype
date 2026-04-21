<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', fn () => $this->comment(Inspiring::quote()))->purpose('Display an inspiring quote');

// In production: prune stale llm_requests older than 7 years (HIPAA retention minimum).
Schedule::command('model:prune', ['--model' => \App\Models\LlmRequest::class])
    ->daily()
    ->name('prune-audit')
    ->withoutOverlapping();
