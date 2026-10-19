<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Media base URL
    |--------------------------------------------------------------------------
    |
    | Where the missions' audio and video (and the listening picks' audio)
    | are served from — the download host, e.g. https://dl.growwise.ir/englishos,
    | with one folder per mission (m01/, m02/, ...). Leave it empty to serve
    | them from this app's own disk (local development, tests). See
    | App\Services\MediaHost.
    |
    */

    'base_url' => env('MEDIA_BASE_URL'),

];
