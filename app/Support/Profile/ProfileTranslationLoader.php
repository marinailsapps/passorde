<?php

namespace App\Support\Profile;

use Illuminate\Contracts\Translation\Loader;

/**
 * Laravel's translation loader, with the installation's wording (Wording) laid
 * over the French JSON texts (lang/fr.json) as they load. Group files (validation…),
 * namespaces and other locales are left as they are.
 */
final class ProfileTranslationLoader implements Loader
{
    public function __construct(private readonly Loader $loader) {}

    /**
     * @return array<string, mixed>
     */
    public function load($locale, $group, $namespace = null)
    {
        $lines = $this->loader->load($locale, $group, $namespace);

        return $group === '*' && $namespace === '*' && $locale === 'fr' ? Wording::apply($lines) : $lines;
    }

    public function addNamespace($namespace, $hint)
    {
        $this->loader->addNamespace($namespace, $hint);
    }

    public function addJsonPath($path)
    {
        $this->loader->addJsonPath($path);
    }

    /**
     * @return array<string, string>
     */
    public function namespaces()
    {
        return $this->loader->namespaces();
    }
}
