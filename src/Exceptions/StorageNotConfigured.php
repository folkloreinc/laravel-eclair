<?php

namespace Folklore\Eclair\Exceptions;

class StorageNotConfigured extends EclairException
{
    public static function forDisk(string $disk): self
    {
        return new self(sprintf('No Eclair storage points to the disk "%s". Add it to eclair.storages.', $disk));
    }
}
