<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Attributes;

use Attribute;

/**
 * Never trace this class or method, even inside a proxied namespace. On a class it also keeps Deep trace out of the file;
 * on a method it keeps probes out of that method's body.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class NoTrace {}
