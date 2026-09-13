<?php

declare(strict_types=1);

namespace Armes\Mapper;

final class JsxDocument
{
    /**
     * @param list<ReactImport> $imports
     */
    public function __construct(
        public readonly TargetNode $root,
        public readonly array $imports = [],
    ) {
    }
}
