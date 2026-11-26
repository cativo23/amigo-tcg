@component('mail.layouts.branded-text', [
    'actionUrl' => $url,
    'actionText' => 'Choose a new password',
    'footer' => 'You’re getting this because a password reset was asked for on your '.config('brand.name').' account.',
])
Reset your password

Someone asked to reset the password for your {!! config('brand.name') !!} account. Open the link below to choose a new one.

The link works for {!! $minutes !!} minutes. If you didn’t ask for this, ignore it: your password stays the same.
@endcomponent
