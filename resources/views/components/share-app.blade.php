{{--
    Sends the learner's own invite link to a friend: the phone's own share sheet (SMS,
    Telegram, WhatsApp, ...), or a copy of the same message where there is
    none. The slot can use `copied` (true for two seconds after a copy).
--}}
@php
    $message = 'من با English OS هر روز انگلیسی تمرین می‌کنم. تو هم امتحانش کن: '.auth()->user()->inviteUrl();
@endphp

<button
    type="button"
    x-data="{
        message: @js($message),
        copied: false,
        async share() {
            if (navigator.share) {
                try {
                    await navigator.share({ text: this.message });

                    return;
                } catch (error) {
                    if (error.name === 'AbortError') {
                        return;
                    }
                }
            }

            await navigator.clipboard?.writeText(this.message);
            this.copied = true;
            setTimeout(() => this.copied = false, 2000);
        },
    }"
    x-on:click="share()"
    {{ $attributes }}
>{{ $slot }}</button>
