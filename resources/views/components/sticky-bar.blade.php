{{--
    A bottom action bar that stays anchored to the viewport as the page
    scrolls past it — purely visual/positional, no button logic of its
    own. Built for the "long single-scroll step" UI/UX finding (see
    EOS-009 §8): 11 of 17 mission steps have no sub-step pagination, and
    without this, a learner has to scroll all the way to the bottom of a
    long page just to find (or check the enabled state of) Continue.

    The negative margins unwind the mission runner's own `p-6` wrapper
    exactly (see ⚡runner.blade.php) so the bar's edges line up with the
    app's actual content column instead of just the button's own — a
    step rendered somewhere with different ancestor padding would need
    a different offset, but every step currently only ever renders
    inside that one wrapper.
--}}
<div class="sticky bottom-0 z-10 -mx-6 -mb-6 border-t border-line bg-ground/90 px-6 pt-4 pb-6 backdrop-blur-sm dark:border-line-dark dark:bg-ground-dark/90">
    {{ $slot }}
</div>
