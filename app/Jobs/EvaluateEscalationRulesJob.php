<?php

namespace App\Jobs;

use App\Services\EscalationRuleEvaluator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

class EvaluateEscalationRulesJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** @return list<object> */
    public function middleware(): array
    {
        return [(new WithoutOverlapping('evaluate-escalation-rules'))->expireAfter(300)];
    }

    public function handle(EscalationRuleEvaluator $evaluator): void
    {
        $evaluator->evaluate();
    }
}
