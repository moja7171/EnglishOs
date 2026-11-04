<?php

namespace App\Services;

use App\Models\DirectMessage;
use App\Models\PairGame;
use App\Models\PairPracticeSession;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Livewire\Component;
use Livewire\Livewire;
use Throwable;
use WeakMap;

/**
 * What the frame around every page shows about the learner: the numbers on
 * the bottom tabs and the avatar in the header. That frame is plain Blade,
 * outside any Livewire component, so an action on a page can't redraw it.
 * Instead <body data-shell> seeds a browser store (Alpine "shell", see
 * app.js) and <livewire:notifications.bell> keeps it current: the cheap
 * pulse() on every 30s poll, the whole state() when the page opens or when
 * changed() says something moved.
 */
class AppShell
{
    /** @var WeakMap<Component, true>|null Components already told in this request. */
    private static ?WeakMap $told = null;

    /**
     * @return array{review: int, notebook: bool, friends: int, live: string, avatar: string}
     */
    public function state(User $user): array
    {
        return [
            'review' => $user->dailyReviewCount(),
            'notebook' => $user->notebookEntries()->unpractised()->exists(),
            'avatar' => $this->avatar($user),
        ] + $this->pulse($user);
    }

    /**
     * The part other people can change while the app sits open. `live` is a
     * fingerprint of what the friend lists show: when it moves, an open
     * Friends, Pair Practice or Games page redraws itself.
     *
     * @return array{friends: int, live: string}
     */
    public function pulse(User $user): array
    {
        $friends = $user->friendsBadgeCount();

        return [
            'friends' => $friends,
            'live' => md5(json_encode([
                $friends,
                DirectMessage::query()->where('recipient_id', $user->id)->max('id'),
                PairPracticeSession::query()
                    ->where(fn ($query) => $query->where('user_a_id', $user->id)->orWhere('user_b_id', $user->id))
                    ->open()
                    ->pluck('stage', 'id'),
                PairGame::involving($user)->open()->pluck('stage', 'id'),
            ])),
        ];
    }

    /**
     * The one definition of the header avatar, so the page render and the
     * live update can't drift apart.
     */
    public function avatar(User $user): string
    {
        return Blade::render('<x-user-avatar :user="$user" class="h-8 w-8 text-xs" />', ['user' => $user]);
    }

    /**
     * Something in the frame moved during this request. The open page hears
     * it once however many rows were saved; outside a Livewire request
     * there is no page to tell, and the next page load is fresh anyway.
     */
    public static function changed(): void
    {
        try {
            $component = Livewire::current();

            if ($component === null) {
                return;
            }

            self::$told ??= new WeakMap;

            if (isset(self::$told[$component])) {
                return;
            }

            self::$told[$component] = true;

            $component->dispatch('shell-changed');
        } catch (Throwable) {
            // Not inside a Livewire request.
        }
    }
}
