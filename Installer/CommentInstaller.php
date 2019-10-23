<?php

declare(strict_types=1);

namespace pvr\EzCommentBundle\Installer;

use EzSystems\PlatformInstallerBundle\Installer\CoreInstaller;

class CommentInstaller extends CoreInstaller
{
    public function importSchema()
    {
        parent::importSchema();

        $this->runQueriesFromFile(
            __DIR__.'/../Resources/installer/sql/schema.sql'
        );
    }
}
