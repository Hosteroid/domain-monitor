<?php

namespace Core;

use Twig\Loader\FilesystemLoader;

/**
 * Loads the templates in app/Views and gives each a cache key that changes when its file does.
 *
 * In production the compiled templates are cached and never compared with their files, so after
 * an update the pages kept coming from the cache: the old pages on top of the new code. With the
 * file's time and size in the key, a template that changed is compiled again and one that didn't
 * keeps its compiled copy. That holds however the files got there: copied by the updater,
 * unzipped with the dates they had in the archive, or put back by a rollback.
 */
class TemplateLoader extends FilesystemLoader
{
    public function getCacheKey(string $name): string
    {
        $key = parent::getCacheKey($name);
        $path = $this->findTemplate($name, false);

        return $path ? $key . ':' . (int)@filemtime($path) . ':' . (int)@filesize($path) : $key;
    }
}
