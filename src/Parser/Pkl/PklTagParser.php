<?php

declare(strict_types=1);

namespace Armes\Parser\Pkl;

use Armes\Exception\ParseException;
use Armes\Parser\Native\NativeTagParser;
use Armes\Tree\Node;
use JsonException;

final class PklTagParser
{
    public function __construct(
        private readonly NativeTagParser $nativeParser = new NativeTagParser(),
        private readonly string $binary = 'pkl',
        private readonly int $timeoutSeconds = 5,
    ) {
    }

    public function parseFile(string $path): Node
    {
        if (!is_file($path)) {
            throw new ParseException(sprintf('Pkl source "%s" does not exist.', $path));
        }

        $realPath = realpath($path);
        if ($realPath === false) {
            throw new ParseException(sprintf('Unable to resolve Pkl source "%s".', $path));
        }

        return $this->parseEvaluatedJson($this->evaluateFile($realPath, dirname($realPath)), $path);
    }

    public function parseString(string $pkl, ?string $source = null): Node
    {
        $root = sys_get_temp_dir() . '/armes-pkl-' . bin2hex(random_bytes(8));
        if (!mkdir($root, 0700, true) && !is_dir($root)) {
            throw new ParseException('Unable to create temporary Pkl evaluation directory.');
        }

        $path = $root . '/source.pkl';
        if (file_put_contents($path, $pkl) === false) {
            $this->removeDirectory($root);
            throw new ParseException('Unable to write temporary Pkl source.');
        }

        try {
            return $this->parseEvaluatedJson($this->evaluateFile($path, $root), $source ?? $path);
        } finally {
            $this->removeDirectory($root);
        }
    }

    private function parseEvaluatedJson(string $json, ?string $source): Node
    {
        try {
            $decoded = json_decode($json, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ParseException(sprintf('Pkl did not emit valid JSON: %s', $exception->getMessage()), 0, $exception);
        }

        return $this->nativeParser->parse($decoded, $source);
    }

    private function evaluateFile(string $path, string $rootDir): string
    {
        $command = [
            $this->binary,
            'eval',
            '--format=json',
            '--no-project',
            '--color=never',
            '--timeout=' . $this->timeoutSeconds,
            '--root-dir=' . $rootDir,
            '--working-dir=' . $rootDir,
            $path,
        ];

        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = @proc_open($command, $descriptors, $pipes, $rootDir);
        if (!is_resource($process)) {
            throw new ParseException(sprintf('Unable to start Pkl CLI "%s". Is it installed and on PATH?', $this->binary));
        }

        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $stdout = '';
        $stderr = '';
        $startedAt = microtime(true);
        $exitCode = null;

        try {
            while (true) {
                $stdout .= stream_get_contents($pipes[1]) ?: '';
                $stderr .= stream_get_contents($pipes[2]) ?: '';

                $status = proc_get_status($process);
                if (!$status['running']) {
                    $exitCode = $status['exitcode'];
                    break;
                }

                if (microtime(true) - $startedAt > $this->timeoutSeconds + 1) {
                    proc_terminate($process);
                    throw new ParseException(sprintf('Pkl evaluation timed out after %d seconds.', $this->timeoutSeconds));
                }

                usleep(10_000);
            }
        } finally {
            $stdout .= stream_get_contents($pipes[1]) ?: '';
            $stderr .= stream_get_contents($pipes[2]) ?: '';
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($process);
        }

        if ($exitCode !== 0) {
            $message = trim($stderr) !== '' ? trim($stderr) : 'Pkl CLI exited unsuccessfully.';
            throw new ParseException($message);
        }

        return $stdout;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
                continue;
            }
            @unlink($child);
        }
        @rmdir($path);
    }
}
