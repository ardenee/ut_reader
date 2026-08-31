<?php
declare(strict_types=1);

namespace UnrealDb\Catalog\Infrastructure\Jobs;

/** Resolves the PHP CLI binary and launches detached worker processes. */
final class CatalogWorkerProcessLauncher
{
    /** @param array<string,mixed> $config */
    public function __construct(
        private readonly array $config,
        private readonly string $catalogRoot
    ) {
    }

    public function phpBinary(): string
    {
        $configured = trim((string)($this->config['queue']['worker_php_binary'] ?? ''));
        $environment = trim((string)(getenv('UNREALDB_WORKER_PHP_BINARY') ?: ''));

        // A configured path is a preference, not a permanent machine binding.
        // If a drive/path disappears after a host/storage change, fall through to
        // the current PHP runtime and PATH instead of preventing the worker pool
        // from starting.
        foreach ([$configured, $environment] as $preferred) {
            $resolved = $this->resolveExecutable($preferred);
            if ($resolved !== null) {
                return $resolved;
            }
        }

        $executable = PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php';
        $candidates = [];
        $loadedIni = php_ini_loaded_file();
        if (is_string($loadedIni) && $loadedIni !== '') {
            $candidates[] = dirname($loadedIni) . DIRECTORY_SEPARATOR . $executable;
        }
        $extensionDir = trim((string)ini_get('extension_dir'));
        if ($extensionDir !== '') {
            $candidates[] = dirname(rtrim($extensionDir, '/\\')) . DIRECTORY_SEPARATOR . $executable;
        }
        $candidates[] = rtrim(PHP_BINDIR, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $executable;
        if (is_file(PHP_BINARY) && preg_match('/^php(?:\.exe)?$/i', basename(PHP_BINARY)) === 1) {
            $candidates[] = PHP_BINARY;
        }
        foreach ($this->pathDirectories() as $directory) {
            $candidates[] = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $executable;
        }

        foreach (array_values(array_unique($candidates)) as $candidate) {
            if (is_file($candidate)) {
                $resolved = realpath($candidate);
                return is_string($resolved) && $resolved !== '' ? $resolved : $candidate;
            }
        }
        return $executable;
    }

    public function assertPhpBinary(string $php): void
    {
        $hasPath = str_contains($php, '/') || str_contains($php, '\\') || preg_match('/^[A-Za-z]:/', $php) === 1;
        if ($hasPath && !is_file($php)) {
            throw new \RuntimeException(
                'Resolved detached-worker PHP binary does not exist: ' . $php . '.'
            );
        }
        if (PHP_OS_FAMILY === 'Windows' && !$hasPath) {
            throw new \RuntimeException(
                'Could not resolve the PHP CLI executable for detached workers. Leave queue.worker_php_binary '
                . 'empty for automatic detection, or set it to the current absolute PHP CLI path.'
            );
        }
    }

    private function resolveExecutable(string $value): ?string
    {
        if ($value === '') {
            return null;
        }

        $hasPath = str_contains($value, '/') || str_contains($value, '\\')
            || preg_match('/^[A-Za-z]:/', $value) === 1;
        if ($hasPath) {
            if (!is_file($value)) {
                return null;
            }
            $resolved = realpath($value);
            return is_string($resolved) && $resolved !== '' ? $resolved : $value;
        }

        foreach ($this->pathDirectories() as $directory) {
            $candidate = rtrim($directory, '/\\') . DIRECTORY_SEPARATOR . $value;
            if (!is_file($candidate)) {
                continue;
            }
            $resolved = realpath($candidate);
            return is_string($resolved) && $resolved !== '' ? $resolved : $candidate;
        }
        return null;
    }

    /** @return list<string> */
    private function pathDirectories(): array
    {
        $directories = [];
        foreach (explode(PATH_SEPARATOR, (string)(getenv('PATH') ?: '')) as $directory) {
            $directory = trim($directory, " \t\n\r\0\x0B\"");
            if ($directory !== '') {
                $directories[] = $directory;
            }
        }
        return array_values(array_unique($directories));
    }

    /** @param list<array{slot:int,arguments:list<string>,log:string}> $launchSpecs */
    public function launchPool(string $php, string $script, array $launchSpecs): void
    {
        if ($launchSpecs === []) {
            return;
        }
        $this->assertPhpBinary($php);
        if (PHP_OS_FAMILY === 'Windows') {
            $this->spawnWindowsPool($php, $script, $launchSpecs);
            return;
        }
        foreach ($launchSpecs as $launch) {
            $this->spawn($php, $script, $launch['arguments'], $launch['log']);
        }
    }

    /** @param list<string> $arguments */
    private function spawn(string $php, string $script, array $arguments, string $log): void
    {
        $parts = [escapeshellarg($php), escapeshellarg($script)];
        foreach ($arguments as $argument) {
            $parts[] = escapeshellarg($argument);
        }
        $program = implode(' ', $parts);
        $command = 'nohup ' . $program . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';
        $handle = @popen($command, 'r');
        if (!is_resource($handle)) {
            throw new \RuntimeException('Could not launch the detached worker process.');
        }
        pclose($handle);
    }

    /** @param list<array{slot:int,arguments:list<string>,log:string}> $launchSpecs */
    private function spawnWindowsPool(string $php, string $script, array $launchSpecs): void
    {
        $launcherBase = (string)$launchSpecs[0]['log'] . '.pool-launch-' . bin2hex(random_bytes(5));
        $launcher = $launcherBase . '.ps1';
        $outputFile = $launcherBase . '.out.log';
        $errorFile = $launcherBase . '.error.log';
        $source = "\$ErrorActionPreference = 'Stop'\r\n\$started = @()\r\n";

        foreach ($launchSpecs as $launch) {
            $slot = (int)$launch['slot'];
            $log = (string)$launch['log'];
            $errorLog = $log . '.error.log';
            @unlink($log);
            @unlink($errorLog);
            $argumentLiterals = array_map(
                static fn(string $argument): string => self::powershellLiteral($argument),
                array_merge([$script], (array)$launch['arguments'])
            );
            $source .= '$process = Start-Process -FilePath ' . self::powershellLiteral($php)
                . ' -ArgumentList @(' . implode(', ', $argumentLiterals) . ')'
                . ' -WorkingDirectory ' . self::powershellLiteral($this->catalogRoot)
                . ' -WindowStyle Hidden'
                . ' -RedirectStandardOutput ' . self::powershellLiteral($log)
                . ' -RedirectStandardError ' . self::powershellLiteral($errorLog)
                . " -PassThru\r\n"
                . '$started += "' . $slot . ':$($process.Id)"' . "\r\n";
        }
        $source .= "Write-Output (\$started -join ',')\r\n";

        if (file_put_contents($launcher, $source, LOCK_EX) === false) {
            throw new \RuntimeException('Could not write the Windows detached-worker pool launcher script.');
        }

        $systemRoot = trim((string)(getenv('SystemRoot') ?: 'C:\\Windows'));
        $powershell = rtrim($systemRoot, '/\\') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
        if (!is_file($powershell)) {
            $powershell = 'powershell.exe';
        }
        $command = [$powershell, '-NoLogo', '-NoProfile', '-NonInteractive', '-ExecutionPolicy', 'Bypass', '-File', $launcher];
        $descriptors = [
            0 => ['file', 'NUL', 'r'],
            1 => ['file', $outputFile, 'w'],
            2 => ['file', $errorFile, 'w'],
        ];
        $pipes = [];
        $process = @proc_open($command, $descriptors, $pipes, $this->catalogRoot, null, [
            'bypass_shell' => true,
            'create_process_group' => true,
        ]);
        if (!is_resource($process)) {
            @unlink($launcher);
            throw new \RuntimeException('Could not invoke PowerShell to launch the detached PHP worker pool.');
        }
        $exitCode = proc_close($process);
        $output = is_file($outputFile) ? trim((string)@file_get_contents($outputFile)) : '';
        $errorOutput = is_file($errorFile) ? trim((string)@file_get_contents($errorFile)) : '';
        @unlink($launcher);
        @unlink($outputFile);
        @unlink($errorFile);

        if ($exitCode !== 0) {
            $detail = trim($errorOutput . ($output !== '' ? ' ' . $output : ''));
            throw new \RuntimeException(
                'PowerShell could not start the detached PHP worker pool'
                . ($detail !== '' ? ': ' . substr(preg_replace('/\s+/', ' ', $detail) ?? $detail, -1600) : '.')
            );
        }

        $missingLaunches = [];
        foreach ($launchSpecs as $launch) {
            $slot = (int)$launch['slot'];
            if (preg_match('/(?:^|,)\s*' . preg_quote((string)$slot, '/') . ':\d+\s*(?:,|$)/', $output) !== 1) {
                $missingLaunches[] = $slot;
            }
        }
        if ($missingLaunches !== []) {
            throw new \RuntimeException(
                'PowerShell returned without process IDs for worker slot(s) ' . implode(', ', $missingLaunches)
                . ($output !== '' ? '. Launcher output: ' . substr($output, -1200) : '.')
            );
        }
    }

    private static function powershellLiteral(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
