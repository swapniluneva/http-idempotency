<?php

declare(strict_types=1);

use Symplify\MonorepoBuilder\Config\MBConfig;

return static function (MBConfig $mbConfig): void {
    // The packages that make up this monorepo. monorepo-builder reads each
    // package's composer.json to keep their versions and shared dependency
    // constraints in sync, and to bump inter-package constraints on release.
    $mbConfig->packageDirectories([__DIR__.'/packages']);
};
