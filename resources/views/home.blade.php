<x-layouts.app>
    {{-- The first-time invitation and the quiet cards live on the home page
         only, so they never interrupt a lesson in progress. --}}
    <livewire:notifications.reminders-prompt variant="sheet" />

    <div class="mx-auto max-w-2xl space-y-3 px-3 pt-3 empty:hidden sm:px-6">
        <livewire:notifications.reminders-prompt />
    </div>

    <livewire:missions.overview />
</x-layouts.app>
