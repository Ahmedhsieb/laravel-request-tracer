<?php

declare(strict_types=1);

namespace Ahmedhsieb\RequestTracer\Dumper;

/**
 * Placeholder that casters put where a secret value would be. The StructureBuilder turns it into a {"$":"mask"} node,
 * so the real value never reaches the cloner output, the stored JSON, or the database.
 */
final class MaskedValue {}
