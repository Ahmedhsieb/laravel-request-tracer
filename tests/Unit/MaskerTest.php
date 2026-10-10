<?php

declare(strict_types=1);

use RequestTracer\Dumper\Masker;

function defaultMasker(): Masker
{
    return Masker::fromConfig(config('request-tracer.masking'));
}

it('matches the default sensitive keys in every common spelling', function (string $key) {
    expect(defaultMasker()->isSensitiveKey($key))->toBeTrue();
})->with([
    'password', 'Password', 'user_password', 'passwordConfirmation', 'password_confirmation', 'passwd',
    'secret', 'client_secret', 'clientSecret', 'token', 'access_token', 'accessToken', 'X-CSRF-TOKEN', 'tokens',
    'Authorization', 'authorization', 'Cookie', 'Set-Cookie', 'api_key', 'apiKey', 'api-key', 'API_KEY', 'apikey',
    'card', 'credit_card_number', 'cardNumber', 'cvv', 'CVV', 'pan', 'pan_number', 'iban', 'IBAN',
]);

it('does not mask harmless keys that merely contain the letters', function (string $key) {
    expect(defaultMasker()->isSensitiveKey($key))->toBeFalse();
})->with([
    'company', 'expand', 'panel', 'span', 'scorecard', 'cardinality', 'name', 'email', 'id', 'status', 'ibanana', 'discard',
]);

it('treats integer keys as never sensitive', function () {
    expect(defaultMasker()->isSensitiveKey(0))->toBeFalse();
});

it('supports regex entries', function () {
    $m = new Masker(['/^x_internal_/i'], '***');

    expect($m->isSensitiveKey('X_INTERNAL_flag'))->toBeTrue()->and($m->isSensitiveKey('internal'))->toBeFalse();
});

it('masks bearer tokens in strings', function () {
    expect(defaultMasker()->maskString('Authorization: Bearer abc.DEF-123 end'))->toBe('Authorization: •••••••• end');
});
