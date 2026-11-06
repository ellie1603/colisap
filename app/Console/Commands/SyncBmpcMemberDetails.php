<?php

namespace App\Console\Commands;

use App\Services\Colisap\BmpcMemberLink;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('colisap:sync-bmpc-members')]
#[Description('Copy the CIF Key, birthdate, contact number, segmentation, savings balance and last transaction date from the BMPC member list to every COLISAP member with the same Savings Number and name.')]
class SyncBmpcMemberDetails extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(BmpcMemberLink $link): int
    {
        set_time_limit(0);

        $changed = $link->syncAll();

        $this->info("{$changed} COLISAP member(s) updated from the BMPC member list.");

        return self::SUCCESS;
    }
}
