@component('mail.layouts.branded-text', [
    'actionUrl' => $url,
    'actionText' => 'Accept the invite',
    'footer' => 'You’re getting this because someone invited '.$email.' to '.config('brand.name').'.',
])
You’re invited to {!! config('brand.name') !!}

{!! $inviter ?? 'The '.config('brand.name').' team' !!} invited you to the private beta.

{!! config('brand.name') !!} tracks a Pokémon card collection: every card with its daily price, its value over time and your own photo of it.

The link works until {!! $expiresOn !!}. If you weren’t expecting this, ignore it: no account is created unless you use the link.
@endcomponent
