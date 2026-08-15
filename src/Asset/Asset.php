<?php

declare(strict_types=1);

namespace App\Asset;

use InvalidArgumentException;

final readonly class Asset
{
    public function __construct(
        public string $id,
        public string $name,
        public string $description,
    ) {
        if ('' === trim($id)) {
            throw new InvalidArgumentException('An asset needs a non-empty id.');
        }

        if ('' === trim($name)) {
            throw new InvalidArgumentException(sprintf('Asset "%s" has an empty name.', $id));
        }

        if ('' === trim($description)) {
            throw new InvalidArgumentException(sprintf('Asset "%s" has an empty description, there is nothing to embed.', $id));
        }
    }
}
