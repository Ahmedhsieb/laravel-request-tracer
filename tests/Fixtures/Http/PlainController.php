<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Tests\Fixtures\Http;

/** A controller that extends nothing, like the default base controller of Laravel 11 and later. */
class PlainController
{
    public function show(string $id): array
    {
        return ['id' => $id, 'label' => $this->label($id)];
    }

    private function label(string $id): string
    {
        return 'Voucher #'.$id;
    }
}
