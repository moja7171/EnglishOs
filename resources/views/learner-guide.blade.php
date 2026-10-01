<?php
    // Merged with the old /program page (English roadmap doc) — the two
    // used to answer overlapping "how does this work" questions in
    // different languages from different entry points, with no link
    // between them. This is now the one page for both.
    $seededMissions = \App\Models\Mission::orderBy('code')->get()->keyBy('code');
    $learner = auth()->user();
    $roadmapCatalog = \App\Models\Mission::roadmapCatalog();
    $roadmapSlots = collect(range(1, \App\Models\Mission::TOTAL_ROADMAP_MISSIONS))
        ->map(fn ($n) => sprintf('M%02d', $n))
        ->map(function ($code) use ($seededMissions, $roadmapCatalog, $learner) {
            $mission = $seededMissions->get($code);

            return [
                'code' => $code,
                'mission' => $mission,
                'title' => $mission->title ?? ($roadmapCatalog[$code]['title'] ?? 'Coming soon'),
                'blockedBy' => $mission ? \App\Models\MissionRun::gatingMission($learner, $mission) : null,
            ];
        });
?>
<x-layouts.app>
    <div class="font-fa mx-auto max-w-2xl space-y-6 p-6" dir="rtl">

        <header class="border-b border-line pb-4 dark:border-line-dark">
            <a href="{{ route('home') }}" wire:navigate class="inline-flex items-center gap-1 text-xs font-semibold text-ink-faint hover:text-ink dark:text-ink-faint-dark dark:hover:text-ink-dark">
                @svg('heroicon-o-chevron-right', 'h-3.5 w-3.5') بازگشت به ماموریت‌ها
            </a>
            <div class="mt-3 flex items-center gap-3">
                <span class="inline-flex h-11 w-11 shrink-0 items-center justify-center rounded-2xl bg-accent text-white dark:bg-accent-dark">
                    @svg('heroicon-o-book-open', 'h-5 w-5')
                </span>
                <div>
                    <h1 class="font-display text-2xl font-extrabold text-ink dark:text-ink-dark">راهنمای یادگیرنده</h1>
                    <p class="mt-0.5 text-sm text-ink-soft dark:text-ink-soft-dark">همه‌چیزهایی که برای شروع و پیش رفتن لازم داری، یک‌جا.</p>
                </div>
            </div>
        </header>

        <section class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <p class="text-sm leading-8 text-ink-soft dark:text-ink-soft-dark">
                <strong class="text-ink dark:text-ink-dark">English OS</strong> یه برنامه‌ی یادگیری انگلیسیه که <strong class="text-accent-ink dark:text-accent-ink-dark">با کمک هوش مصنوعی</strong> کارهای گفتاری و نوشتاریت رو چک می‌کنه و بهت فیدبک می‌ده — نه یه معلم واقعی پشت صحنه، بلکه یه AI Instructor که صدا و متنت رو گوش می‌ده/می‌خونه و بهت می‌گه چی خوب بوده و چی رو باید اصلاح کنی. کل برنامه ۲۴ ماموریت و ۱۰۰ روزه؛ هر روز حدود ۴۰ دقیقه.
            </p>
        </section>

        <section class="flex items-start gap-3 rounded-2xl border-2 border-accent/40 bg-surface p-4 dark:border-accent-dark/40 dark:bg-surface-dark">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-o-rocket-launch', 'h-4 w-4')
            </span>
            <div>
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">اولین قدم</p>
                <p class="mt-1 text-sm leading-8 text-ink dark:text-ink-dark">
                    وقتی وارد می‌شی، صفحه‌ی اصلی («Missions») رو می‌بینی. یه بخش به اسم «Today» بهت می‌گه امروز دقیقاً باید چیکار کنی. فقط کافیه روی دکمه‌ی «Start» (برای شروع یه ماموریت جدید) یا «Continue» / «Open mission» (برای ادامه‌ی ماموریتی که وسطشی) بزنی — بقیه‌ش رو خود برنامه قدم‌به‌قدم نشونت می‌ده.
                </p>
            </div>
        </section>

        <section class="flex items-start gap-3 rounded-2xl border border-line bg-surface-sunken p-4 dark:border-line-dark dark:bg-surface-sunken-dark">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-amber-100 text-amber-700 dark:bg-amber-900 dark:text-amber-400">
                @svg('heroicon-o-heart', 'h-4 w-4')
            </span>
            <div>
                <p class="text-xs font-semibold tracking-wide text-amber-700 uppercase dark:text-amber-400">خیالت راحت باشه</p>
                <p class="mt-1 text-sm leading-8 text-ink-soft dark:text-ink-soft-dark">
                    هیچ‌وقت توی این برنامه به‌خاطر اشتباه سرزنش نمی‌شی. اگه چندبار پشت‌سرهم یه چیزی رو درست نگی/ننویسی، AI به‌جای سخت‌گیری بیشتر، <strong class="text-ink dark:text-ink-dark">زودتر بهت کمک پیشنهاد می‌ده</strong>. ضبط صدات بد بود؟ جمله‌ت گرامر نداشت؟ مشکلی نیست — دقیقاً برای همینه که این تمرین‌ها وجود دارن. هدف اینجا امتحان دادن نیست، تمرین کردنه.
                </p>
            </div>
        </section>

        <section class="space-y-3">
            <div>
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">ساختار برنامه</p>
                <h2 class="mt-0.5 font-display text-lg font-bold text-ink dark:text-ink-dark">نقشه‌ی هر روز</h2>
                <p class="mt-1 text-sm text-ink-soft dark:text-ink-soft-dark">هر ماموریت یه موضوع واقعی زندگی روزمره‌ست که توی ۴ روز پیش می‌ری.</p>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                @foreach ([
                    ['۱', 'پایه', 'شروع ماموریت', 'با موضوع آشنا می‌شی، چند کلمه‌ی جدید یاد می‌گیری، و یه فایل صوتی واقعی گوش می‌دی.', '۴۰'],
                    ['۲', 'ساخت', 'یادگیری عمیق‌تر', 'کلمات بیشتر، یه نکته‌ی گرامری با مثال واقعی، و یه ویدیوی واقعی که دنبالش حرف می‌زنی.', '۵۵'],
                    ['۳', 'تمرین', 'به‌کاربردن', 'با هوش مصنوعی مکالمه می‌کنی، یه متن می‌خونی، و یه متن می‌نویسی.', '۶۵'],
                    ['۴', 'چالش', 'جمع‌بندی', 'یه تصویر رو توصیف می‌کنی، اشتباهاتت رو رفع می‌کنی، یه مکالمه‌ی نهایی بدون آمادگی انجام می‌دی، و نتیجه رو می‌بینی.', '۳۵'],
                ] as [$num, $phase, $title, $desc, $minutes])
                    <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                        <div class="flex items-center justify-between gap-2">
                            <div class="flex items-center gap-2">
                                <span class="inline-flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-accent text-sm font-bold text-white dark:bg-accent-dark">{{ $num }}</span>
                                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">{{ $phase }}</p>
                            </div>
                            <span class="shrink-0 text-xs text-ink-faint dark:text-ink-faint-dark">~{{ $minutes }} دقیقه</span>
                        </div>
                        <p class="mt-2 text-sm font-bold text-ink dark:text-ink-dark">{{ $title }}</p>
                        <p class="mt-1 text-xs leading-7 text-ink-soft dark:text-ink-soft-dark">{{ $desc }}</p>
                    </div>
                @endforeach
            </div>
        </section>

        <section class="space-y-2">
            <div>
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">برای جواب گرفتن</p>
                <h2 class="mt-0.5 font-display text-lg font-bold text-ink dark:text-ink-dark">سه عادت که جواب می‌ده</h2>
            </div>
            <ul class="list-disc space-y-1.5 pe-5 text-sm leading-7 text-ink-soft dark:text-ink-soft-dark">
                <li><strong class="text-ink dark:text-ink-dark">هر روز سر یه ساعت ثابت.</strong> چهل دقیقه‌ی روزانه بیشتر از دو ساعت یک‌جا توی آخر هفته جواب می‌ده.</li>
                <li><strong class="text-ink dark:text-ink-dark">همیشه با صدای بلند حرف بزن.</strong> توی هر تمرین ضبط‌صدا، دهنت باید کار کنه، نه فقط چشمت.</li>
                <li><strong class="text-ink dark:text-ink-dark">قبل از مطلب جدید، Daily Review رو بزن.</strong> ده دقیقه مرور توی یه روز کوتاه، از یه قدم جدید باارزش‌تره.</li>
            </ul>
        </section>

        <section class="flex items-start gap-3 rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
            <span class="inline-flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                @svg('heroicon-o-shield-check', 'h-4 w-4')
            </span>
            <div>
                <p class="text-sm font-bold text-ink dark:text-ink-dark">چرا نمی‌تونم یه مرحله رو رد کنم؟</p>
                <p class="mt-1 text-sm leading-8 text-ink-soft dark:text-ink-soft-dark">
                    چون یادگیری زبان با دیدن جواب درست اتفاق نمی‌افته، با <strong class="text-ink dark:text-ink-dark">تلاش کردن</strong> اتفاق می‌افته. هر مرحله از تو یه خروجی واقعی می‌خواد (صدا، نوشته، یا جواب) تا AI Instructor بتونه واقعاً بهت فیدبک بده.
                </p>
            </div>
        </section>

        <section class="space-y-3">
            <div>
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">خارج از برنامه</p>
                <h2 class="mt-0.5 font-display text-lg font-bold text-ink dark:text-ink-dark">منابع تکمیلی</h2>
            </div>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                        @svg('heroicon-o-speaker-wave', 'h-4 w-4')
                    </span>
                    <p class="mt-2 text-sm font-bold text-ink dark:text-ink-dark">شنیداری و تلفظ</p>
                    <ul class="mt-1.5 list-disc space-y-1.5 pe-4 text-xs leading-6 text-ink-soft dark:text-ink-soft-dark">
                        <li>کانال‌های BBC Learning English و Rachel's English روی یوتیوب</li>
                        <li>کلمات «My Words» رو دوبار لمس کن تا تلفظ امریکنش رو بشنوی</li>
                    </ul>
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                        @svg('heroicon-o-chat-bubble-left-right', 'h-4 w-4')
                    </span>
                    <p class="mt-2 text-sm font-bold text-ink dark:text-ink-dark">مکالمه</p>
                    <ul class="mt-1.5 list-disc space-y-1.5 pe-4 text-xs leading-6 text-ink-soft dark:text-ink-soft-dark">
                        <li>اگه دوستی روی پلتفرم داری، باهاش تمرین کن</li>
                        <li>جلوی آینه جواب سوال‌های همون روز رو بدون آمادگی تمرین کن</li>
                    </ul>
                </div>
                <div class="rounded-2xl border border-line bg-surface p-4 dark:border-line-dark dark:bg-surface-dark">
                    <span class="inline-flex h-8 w-8 items-center justify-center rounded-full bg-accent-soft text-accent-ink dark:bg-accent-soft-dark dark:text-accent-ink-dark">
                        @svg('heroicon-o-sparkles', 'h-4 w-4')
                    </span>
                    <p class="mt-2 text-sm font-bold text-ink dark:text-ink-dark">واژگان</p>
                    <ul class="mt-1.5 list-disc space-y-1.5 pe-4 text-xs leading-6 text-ink-soft dark:text-ink-soft-dark">
                        <li>نیازی نیست خودت کلمات رو مرور کنی</li>
                        <li>با فاصله‌ی زمانی مناسب خودکار جلوت میان</li>
                    </ul>
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <div>
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">مسیر کامل</p>
                <h2 class="mt-0.5 font-display text-lg font-bold text-ink dark:text-ink-dark">نقشه‌ی ۲۴ ماموریت</h2>
            </div>
            <ol class="space-y-1.5">
                @foreach ($roadmapSlots as $slot)
                    <li class="flex items-center gap-3 rounded-xl px-3 py-2 text-sm {{ $slot['mission'] && ! $slot['blockedBy'] ? 'hover:bg-surface-sunken dark:hover:bg-surface-sunken-dark' : '' }}">
                        <span class="w-9 shrink-0 text-xs font-bold text-ink-faint dark:text-ink-faint-dark">{{ $slot['code'] }}</span>
                        @if ($slot['mission'] && ! $slot['blockedBy'])
                            <a href="{{ route('missions.show', [$slot['mission'], 'overview']) }}" wire:navigate class="flex-1 font-semibold text-ink dark:text-ink-dark">{{ $slot['title'] }}</a>
                            @svg('heroicon-o-chevron-left', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                        @else
                            <span class="flex-1 {{ $slot['mission'] ? 'text-ink-soft dark:text-ink-soft-dark' : 'text-ink-faint dark:text-ink-faint-dark' }}">{{ $slot['title'] }}</span>
                            @svg('heroicon-o-lock-closed', 'h-4 w-4 shrink-0 text-ink-faint dark:text-ink-faint-dark')
                        @endif
                    </li>
                @endforeach
            </ol>
        </section>

        <section class="space-y-3">
            <div>
                <p class="text-xs font-semibold tracking-wide text-accent-ink uppercase dark:text-accent-ink-dark">هنوز سوال داری؟</p>
                <h2 class="mt-0.5 font-display text-lg font-bold text-ink dark:text-ink-dark">سوالات متداول</h2>
            </div>

            <div class="space-y-2">
                @foreach ([
                    ['میکروفون لازم دارم؟', 'برای تمرین‌های گفتاری (مکالمه، توصیف تصویر، Shadowing) بله — گوشی یا لپ‌تاپت کافیه، نیازی به تجهیزات خاص نیست.'],
                    ['روی موبایل هم کار می‌کنه؟', 'بله، کاملاً. اتفاقاً خیلی از قابلیت‌ها (مثل تلفظ کلمات با دوبار لمس) مخصوص موبایل طراحی شدن.'],
                    ['اگه یه کلمه یا جمله رو نفهمم چی؟', 'نگران نباش، همه‌چیز با معنی و مثال همراهشه. اگه بازم گیر کردی، همون سوالی که داری رو با AI یا با دوستات مطرح کن.'],
                    ['می‌تونم یه مرحله یا کل ماموریت رو دوباره انجام بدم؟', 'بله، مرحله‌هایی که قابل تکرارن همیشه در دسترسن.'],
                    ['اگه یه روز جا موندم چی می‌شه؟', 'هیچی از دست نمی‌ره — ماموریت منتظرت می‌مونه، فقط استریکت صفر می‌شه. تاریخ تقویمی هیچ‌وقت جریمه نداره.'],
                    ['نتیجه‌ی ماموریت چطور تعیین می‌شه؟', 'AI Instructor بر اساس چیزایی که واقعاً نوشتی و گفتی (نه خودارزیابیت) تصمیم می‌گیره که ماموریت کامله یا یه مرحله رو دوباره نیاز داری — نه چون اشتباه بزرگی کردی، بلکه چون اونجا بیشترین کمک رو بهت می‌کنه.'],
                ] as [$q, $a])
                    <div class="rounded-xl border border-line bg-surface dark:border-line-dark dark:bg-surface-dark" x-data="{ open: false }">
                        <button
                            type="button"
                            x-on:click="open = ! open"
                            class="flex w-full cursor-pointer items-center justify-between gap-3 px-4 py-3 text-right"
                        >
                            <span class="text-sm font-semibold text-ink dark:text-ink-dark">{{ $q }}</span>
                            <span class="shrink-0 text-ink-faint dark:text-ink-faint-dark" :class="open ? 'rotate-180' : ''" style="transition: transform 150ms">
                                @svg('heroicon-o-chevron-down', 'h-4 w-4')
                            </span>
                        </button>
                        <div x-show="open" x-cloak x-transition.opacity.duration.150ms class="px-4 pb-3">
                            <p class="text-sm leading-7 text-ink-soft dark:text-ink-soft-dark">{{ $a }}</p>
                        </div>
                    </div>
                @endforeach
            </div>
        </section>

    </div>
</x-layouts.app>
