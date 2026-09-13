<?php

declare(strict_types=1);

namespace Armes\Emitter;

use Armes\Tree\Node;
use Symfony\Component\Yaml\Yaml;

final class YamlEmitter
{
    public function emitTree(Node $node, string $mode = JsonEmitter::MODE_COMPACT): string
    {
        return $this->emitData((new JsonEmitter())->toData($node, $mode));
    }

    public function emitData(mixed $data): string
    {
        return Yaml::dump($data, 8, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
    }
}
