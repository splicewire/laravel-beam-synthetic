<?php

namespace Splicewire\Beam\Synthetic\Blueprints;

use Closure;
use InvalidArgumentException;
use Rushing\Popcorn\Registries\Registrant;
use Rushing\Popcorn\Registries\Registrar;
use Rushing\Popcorn\Registries\Registry;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * Fills a registry from the declarative files under a list of directories: every `*.yaml`, `*.yml`
 * and `*.json` file directly inside each one, in path order and then filename order. Each file is one
 * entry, hydrated by `$hydrate` and keyed by its `id`.
 *
 * It fails loudly on the three ways a declared directory can silently register less than it says:
 *
 * - a declared path that is not a directory (a typo would otherwise register nothing);
 * - a file that does not parse or hydrate (the message names the file);
 * - two files declaring the same id. The message names both files. Ids seed the curve, so two
 *   blueprints sharing one would replay correlated curves and leave it ambiguous which one a portfolio
 *   meant.
 *
 * Every write carries `config:<key>` as its registrant: the files are found through that config key.
 */
final class DirectoryRegistrar implements Registrar
{
    private const EXTENSIONS = ['yaml', 'yml', 'json'];

    /**
     * @param  list<string>  $paths  absolute directories
     * @param  string  $configKey  the config key the paths were read from
     * @param  Closure(array<string, mixed>): object  $hydrate  payload → entry
     * @param  Closure(object): string  $key  entry → its id
     */
    public function __construct(
        private readonly array $paths,
        private readonly string $configKey,
        private readonly Closure $hydrate,
        private readonly Closure $key,
    ) {}

    public function fill(Registry $registry): void
    {
        $by = Registrant::fromConfig($this->configKey);

        foreach ($this->entries() as $id => $entry) {
            $registry->register($id, $entry, by: $by);
        }
    }

    public function source(): string
    {
        return "*.yaml, *.yml and *.json files under config {$this->configKey}";
    }

    /** @return list<string> every file this registrar reads, in read order */
    public function files(): array
    {
        $files = [];

        foreach ($this->paths as $path) {
            if (! is_dir($path)) {
                throw new InvalidArgumentException("{$this->configKey} names '{$path}', which is not a directory.");
            }

            $found = [];
            foreach (scandir($path) ?: [] as $name) {
                $file = rtrim($path, '/').'/'.$name;

                if (is_file($file) && in_array(strtolower(pathinfo($name, PATHINFO_EXTENSION)), self::EXTENSIONS, true)) {
                    $found[] = $file;
                }
            }

            sort($found);
            array_push($files, ...$found);
        }

        return $files;
    }

    /** @return array<string, object> id => entry, in read order */
    private function entries(): array
    {
        $entries = [];
        $from = [];

        foreach ($this->files() as $file) {
            $entry = $this->read($file);
            $id = ($this->key)($entry);

            if (isset($from[$id])) {
                throw new InvalidArgumentException("Two files under {$this->configKey} declare the id '{$id}': {$from[$id]} and {$file}.");
            }

            $from[$id] = $file;
            $entries[$id] = $entry;
        }

        return $entries;
    }

    private function read(string $file): object
    {
        try {
            $payload = strtolower(pathinfo($file, PATHINFO_EXTENSION)) === 'json'
                ? json_decode((string) file_get_contents($file), true, flags: JSON_THROW_ON_ERROR)
                : Yaml::parseFile($file);

            if (! is_array($payload)) {
                throw new InvalidArgumentException('the file does not hold a mapping');
            }

            return ($this->hydrate)($payload);
        } catch (Throwable $e) {
            throw new InvalidArgumentException("Could not read {$file}: {$e->getMessage()}", previous: $e);
        }
    }
}
