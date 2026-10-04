<?php

namespace App\Providers;

use App\Livewire\Concerns\PreviewsStep;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

use function Livewire\on;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Most cPanel/shared MySQL hosts still default to a 767-byte InnoDB
        // index prefix limit. A default varchar(255) unique/index column
        // under utf8mb4 (4 bytes/char) needs 1020 bytes and fails migration
        // with "Specified key too long" — capping the default length at 191
        // (191*4=764) is the standard Laravel fix and is a no-op on Postgres.
        Schema::defaultStringLength(191);

        $this->refuseActionsOnPreviewedSteps();
    }

    /**
     * A step shown in preview (see PreviewsStep) is look-only: any action a
     * client sends to it - save, proceed, an AI call, a file upload - is
     * refused before it runs. Property updates are harmless on their own
     * (nothing persists without an action) and the inputs are disabled by
     * readOnly anyway.
     */
    private function refuseActionsOnPreviewedSteps(): void
    {
        on('call', function ($component, string $method) {
            if (! in_array(PreviewsStep::class, class_uses_recursive($component), true)) {
                return;
            }

            if ($component->preview && $method !== '$refresh') {
                abort(403, 'This step is preview-only.');
            }
        });
    }
}
