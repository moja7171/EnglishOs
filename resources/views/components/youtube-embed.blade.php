{{--
    A standard YouTube iframe embed, responsive 16:9. Embedding a public
    video like this is normal, licensed YouTube behavior — unlike copying
    a creator's own transcript verbatim into our database (see EOS-009
    §14), this component never stores or proxies the video itself, only
    points to youtube-nocookie.com/embed/{id}.

    @param string $videoId The YouTube video id (from a youtu.be/{id} or
        watch?v={id} URL).
    @param string $title Accessible title for the iframe.
--}}
@props(['videoId', 'title' => 'Video'])

<div class="relative aspect-video w-full overflow-hidden rounded-2xl border border-line dark:border-line-dark">
    <iframe
        class="absolute inset-0 h-full w-full"
        src="https://www.youtube-nocookie.com/embed/{{ $videoId }}"
        title="{{ $title }}"
        loading="lazy"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture"
        allowfullscreen
    ></iframe>
</div>
