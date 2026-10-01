<?php

namespace App\Console\Commands;

use App\Models\Member;
use App\Services\Colisap\MemberStatusEngine;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('colisap:update-member-statuses')]
#[Description('Apply COLISAP policy to every participating member: waiting period, dormancy, replenishment notices, terminations, downgrades and upgrades.')]
class UpdateMemberStatuses extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(MemberStatusEngine $engine): int
    {
        $evaluated = 0;
        $changed = 0;

        Member::query()
            ->participating()
            ->chunkById(500, function ($members) use ($engine, &$evaluated, &$changed) {
                foreach ($members as $member) {
                    $evaluated++;

                    if ($engine->evaluate($member)) {
                        $changed++;
                    }
                }
            });

        $this->info("Evaluated {$evaluated} member(s); {$changed} changed.");

        return self::SUCCESS;
    }
}
