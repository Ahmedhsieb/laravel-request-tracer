<?php

declare(strict_types=1);

namespace RequestTracer\Tests\Fixtures\Deep\Corpus;

final class Marker
{
    public function __construct(public readonly string $name = 'x') {}
}

/**
 * Places where a function call would be a compile error (constant expressions) and other awkward spots.
 */
class Contexts
{
    public const MODE = PHP_INT_SIZE > 4 ? 'wide' : 'narrow';

    public array $defaults = [PHP_INT_SIZE > 4 ? 'a' : 'b' => true];

    public ?Marker $flag = null;

    private array $data = [1, 2, 3];

    public function __construct(public readonly int $size = PHP_INT_SIZE > 4 ? 8 : 4, ?Marker $flag = new Marker('dflt'))
    {
        $this->flag = $flag;
    }

    #[Marker(PHP_INT_SIZE > 4 ? 'a' : 'b')]
    public function run(int $x = PHP_INT_SIZE > 4 ? 1 : 2): array
    {
        static $calls = PHP_INT_SIZE > 4 ? 0 : 1;
        $calls++;
        $ref = &$this->getRef();
        $ref[] = 4;

        return [
            self::MODE,
            $x,
            $calls,
            $this->size,
            $this->flag?->name,
            implode(',', $this->data),
            $this->describe(__LINE__, basename(__FILE__), basename(__DIR__), __CLASS__, __FUNCTION__, __METHOD__),
        ];
    }

    public function &getRef(): array
    {
        return $this->data;
    }

    public function describe(int $line, string $file, string $dir, string $class, string $function, string $method): string
    {
        return implode('|', [$line, $file, $dir, str_replace('\\', '/', $class), $function, str_replace('\\', '/', $method)]);
    }

    public function render(array $items): string
    {
        ob_start();
        ?>
<ul>
<?php foreach ($items as $item): ?>
  <li><?= htmlspecialchars((string) $item) ?></li>
<?php endforeach; ?>
</ul>
<?php if (count($items) > 1) { ?>
  <p>many</p>
<?php } else { ?>
  <p>few</p>
<?php } ?>
<?php

        return (string) ob_get_clean();
    }
}
