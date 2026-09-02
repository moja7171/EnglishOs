<?php

use App\Livewire\Concerns\DailyListenStep;
use Livewire\Component;

new class extends Component
{
    use DailyListenStep;

    protected function phaseKey(): string
    {
        return 'daily_listen_2';
    }
};
?>

@include('components.missions.steps.partials.daily-listen-view')
