<?php

declare(strict_types=1);

namespace Lateo\Explorer\Tests\Support;

/**
 * A child PHP process for the tests on real files: the program is written to its standard
 * input, it reports on standard output. stop() ends it (also after a failed test).
 */
final class ChildProcess
{
    /** @var resource|null */
    private $process;
    /** @var array<int,resource> */
    private array $pipes = [];
    private string $buffer = '';

    /**
     * A PHP process that runs $program, given on its standard input.
     *
     * @throws \RuntimeException when the process cannot be started
     */
    public static function program(string $program): self
    {
        return new self([PHP_BINARY, '-n'], $program, null);
    }

    /**
     * A process that runs $command and writes its output to files in $logDir (a server that
     * is never read from must not fill a pipe).
     *
     * @param non-empty-list<string> $command
     * @throws \RuntimeException when the process cannot be started
     */
    public static function server(array $command, string $logDir): self
    {
        return new self($command, null, $logDir);
    }

    /** @param non-empty-list<string> $command */
    private function __construct(array $command, ?string $program, ?string $logDir)
    {
        $spec = $logDir === null
            ? [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']]
            : [0 => ['pipe', 'r'], 1 => ['file', $logDir . '/server.out', 'w'], 2 => ['file', $logDir . '/server.err', 'w']];
        $process = @proc_open($command, $spec, $this->pipes);
        if (!is_resource($process)) {
            throw new \RuntimeException('a child process cannot be started');
        }
        $this->process = $process;
        if ($program !== null) {
            fwrite($this->pipes[0], $program);
        }
        fclose($this->pipes[0]);
        unset($this->pipes[0]);
    }

    /** Wait up to $seconds for one more line on standard output. Null when none came. */
    public function readLine(float $seconds = 5.0): ?string
    {
        $deadline = microtime(true) + $seconds;
        while (true) {
            $pos = strpos($this->buffer, "\n");
            if ($pos !== false) {
                $line = substr($this->buffer, 0, $pos);
                $this->buffer = substr($this->buffer, $pos + 1);
                return $line;
            }
            $left = $deadline - microtime(true);
            if ($left <= 0 || !isset($this->pipes[1])) {
                return null;
            }
            $read = [$this->pipes[1]];
            $write = $except = null;
            if (stream_select($read, $write, $except, 0, (int) min(200000, $left * 1_000_000)) > 0) {
                $chunk = fread($this->pipes[1], 8192);
                if ($chunk === false || $chunk === '') {
                    return null; // the process closed its output
                }
                $this->buffer .= $chunk;
            }
        }
    }

    /** What the process wrote to standard error so far (for failure messages). */
    public function errors(): string
    {
        if (!isset($this->pipes[2])) {
            return '';
        }
        stream_set_blocking($this->pipes[2], false);
        return (string) stream_get_contents($this->pipes[2]);
    }

    /** Wait up to $seconds for the process to end on its own; true when it has. */
    public function waitForExit(float $seconds = 5.0): bool
    {
        $deadline = microtime(true) + $seconds;
        while ($this->process !== null) {
            $status = proc_get_status($this->process);
            if (!$status['running']) {
                $this->close();
                return true;
            }
            if (microtime(true) >= $deadline) {
                return false;
            }
            usleep(10000);
        }
        return true;
    }

    /** End the process (kill it when it is still running) and release everything. */
    public function stop(): void
    {
        if ($this->process !== null) {
            $status = proc_get_status($this->process);
            if ($status['running']) {
                proc_terminate($this->process, 9);
            }
        }
        $this->close();
    }

    private function close(): void
    {
        foreach ($this->pipes as $pipe) {
            @fclose($pipe);
        }
        $this->pipes = [];
        if ($this->process !== null) {
            proc_close($this->process);
            $this->process = null;
        }
    }
}
