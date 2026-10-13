<?php

declare(strict_types=1);

use Ahmedhsieb\RequestTracer\Http\Controllers\AssetController;

beforeEach(function () {
    $this->published = public_path('vendor/request-tracer');
    @mkdir($this->published, 0777, true);
    $this->copy = $this->published.'/app.js';
    $this->shipped = dirname(__DIR__, 2).'/dist/app.js';
});

afterEach(function () {
    @unlink($this->copy);
    @rmdir($this->published);
});

it('serves a published copy that is newer than the shipped one', function () {
    file_put_contents($this->copy, '// patched');
    touch($this->copy, filemtime($this->shipped) + 60);

    expect(AssetController::locate('app.js'))->toBe($this->copy);
});

it('ignores a published copy that is older than the shipped one (a stale copy after an upgrade)', function () {
    file_put_contents($this->copy, '// published by an older version');
    touch($this->copy, filemtime($this->shipped) - 3600);

    expect(AssetController::locate('app.js'))->toBe($this->shipped);
});
