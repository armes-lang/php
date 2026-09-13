<?php

declare(strict_types=1);

namespace Armes\Mapper;

use Armes\Tree\Node;

interface MapperInterface
{
    public function map(Node $node, ?MappingContext $context = null): mixed;
}
