{{-- text/plain twin of branded.blade.php. Never rendered as HTML, so values
     are output raw and appear exactly as written. --}}
{!! config('brand.name') !!}
{!! str_repeat('=', mb_strlen(config('brand.name'))) !!}

{!! $slot !!}
@isset($actionUrl)

{!! $actionText !!}: {!! $actionUrl !!}
@endisset

--
{!! $footer ?? 'You’re getting this because of your '.config('brand.name').' account.' !!}
{!! config('brand.name') !!} · {!! url('/') !!} · Privacy: {!! route('privacy') !!}
