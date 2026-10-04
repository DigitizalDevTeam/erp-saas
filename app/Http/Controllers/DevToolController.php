<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DevToolController extends Controller
{
    public function index(Request $request)
    {
        if ($denied = $this->denyUnlessSuperAdmin()) {
            return $denied;
        }

        $logFiles = $this->logFiles();
        $selected = (string) $request->query('file', $logFiles[0] ?? '');
        if ($selected !== '' && ! in_array($selected, $logFiles, true)) {
            $selected = $logFiles[0] ?? '';
        }

        $log = $this->readLog($selected);

        return view('dev-tool.index', [
            'pending' => $this->pendingMigrations(),
            'seeders' => $this->seederClasses(),
            'logFiles' => $logFiles,
            'selectedLog' => $selected,
            'logBody' => $log['body'],
            'logTruncated' => $log['truncated'],
            'logBytes' => $log['bytes'],
        ]);
    }

    public function clearLog(Request $request)
    {
        if ($denied = $this->denyUnlessSuperAdmin()) {
            return $denied;
        }

        $name = basename((string) $request->input('file', ''));
        $path = $this->logPath($name);
        if ($path === null) {
            return redirect()->route('dev-tool.index')->with('error', __('Log file not found.'));
        }

        file_put_contents($path, '');

        return redirect()->route('dev-tool.index', ['file' => $name])->with('success', __('Log cleared.'));
    }

    public function migrate()
    {
        if ($denied = $this->denyUnlessSuperAdmin()) {
            return $denied;
        }

        @set_time_limit(300);

        $report = $this->runPendingMigrations();
        $redirect = redirect()->route('dev-tool.index')->with('migrate_report', $report);

        if ($report['failed'] !== null) {
            return $redirect->with('error', $report['failed']['message']);
        }

        if ($report['skipped'] !== []) {
            return $redirect->with('success', __('Finished. Anything already in the database was marked as done.'));
        }

        return $redirect->with('success', $report['ran'] === []
            ? __('No pending migrations.')
            : __('Migrations finished.'));
    }

    /**
     * Seeder class names available to run from /dev-tool,
     * discovered from database/seeders (short class names, A-Z).
     *
     * @return array<int, string>
     */
    protected function seederClasses(): array
    {
        $dir = database_path('seeders');
        if (! is_dir($dir)) {
            return [];
        }

        $names = [];
        foreach (File::files($dir) as $file) {
            if (strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $contents = (string) file_get_contents($file->getPathname());
            if (preg_match('/^\s*class\s+([A-Za-z0-9_]+)\s+extends\s+Seeder/m', $contents, $match)) {
                $names[] = $match[1];
            }
        }

        sort($names, SORT_NATURAL);

        return array_values(array_unique($names));
    }

    public function runSeeder(Request $request)
    {
        if ($denied = $this->denyUnlessSuperAdmin()) {
            return $denied;
        }

        $class = (string) $request->input('seeder', '');
        if (! in_array($class, $this->seederClasses(), true)) {
            return redirect()->route('dev-tool.index')->with('error', __('Please select a valid seeder.'));
        }

        @set_time_limit(300);

        try {
            Artisan::call('db:seed', ['--class' => $class, '--force' => true]);
            $output = trim((string) Artisan::output());
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            foreach (['(SQL:', '(Connection:'] as $marker) {
                $pos = strpos($message, $marker);
                if ($pos !== false) {
                    $message = trim(substr($message, 0, $pos));
                }
            }

            return redirect()->route('dev-tool.index')
                ->with('seed_report', ['seeder' => $class, 'output' => '', 'failed' => $message !== '' ? $message : __('The seeder could not be run.')])
                ->with('error', __('Seeder failed: :seeder', ['seeder' => $class]));
        }

        return redirect()->route('dev-tool.index')
            ->with('seed_report', ['seeder' => $class, 'output' => $output, 'failed' => null])
            ->with('success', __('Seeder finished: :seeder', ['seeder' => $class]));
    }

    /**
     * Clear every runtime cache in one click (compiled Blade views,
     * application cache, config, route, event, compiled services and the
     * Spatie permission cache), so freshly uploaded files take effect
     * without cPanel/terminal access. No data is touched.
     */
    public function clearAllCaches()
    {
        if ($denied = $this->denyUnlessSuperAdmin()) {
            return $denied;
        }

        $cleared = [];
        $commands = [
            'view:clear' => __('compiled views'),
            'cache:clear' => __('application cache'),
            'config:clear' => __('configuration cache'),
            'route:clear' => __('route cache'),
            'event:clear' => __('event cache'),
            'clear-compiled' => __('compiled services'),
        ];

        try {
            foreach ($commands as $command => $label) {
                Artisan::call($command);
                $cleared[] = $label;
            }

            if (array_key_exists('permission:cache-reset', Artisan::all())) {
                Artisan::call('permission:cache-reset');
                $cleared[] = __('permission cache');
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            foreach (['(SQL:', '(Connection:'] as $marker) {
                $pos = strpos($message, $marker);
                if ($pos !== false) {
                    $message = trim(substr($message, 0, $pos));
                }
            }

            return redirect()->route('dev-tool.index')
                ->with('error', __('Cache clear stopped on :step. :reason', [
                    'step' => $label ?? $command ?? '',
                    'reason' => $message !== '' ? $message : __('The cache could not be cleared.'),
                ]));
        }

        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }

        return redirect()->route('dev-tool.index')
            ->with('success', __('All caches cleared: :list.', ['list' => implode(', ', $cleared)]));
    }

    /**
     * Run pending migrations one file at a time.
     * If the table or column already exists, record that migration and continue.
     *
     * @return array{ran: array<int, string>, skipped: array<int, array{migration: string, detail: string}>, failed: array{migration: string, message: string}|null}
     */
    protected function runPendingMigrations(): array
    {
        $report = ['ran' => [], 'skipped' => [], 'failed' => null];
        $files = $this->pendingMigrationFiles();
        if ($files === []) {
            return $report;
        }

        $migrator = app('migrator');
        $repository = $migrator->getRepository();
        if (! $migrator->repositoryExists()) {
            $repository->createRepository();
        }

        foreach ($files as $name => $path) {
            try {
                $migrator->run([$path]);
                $report['ran'][] = $name;
            } catch (\Throwable $e) {
                DB::reconnect();
                if ($this->isAlreadyApplied($e)) {
                    $repository->log($name, $repository->getNextBatchNumber());
                    $report['skipped'][] = [
                        'migration' => $name,
                        'detail' => $this->alreadyAppliedDetail($e),
                    ];

                    continue;
                }

                $report['failed'] = [
                    'migration' => $name,
                    'message' => $this->friendlyMigrationError($name, $e),
                ];
                break;
            }
        }

        return $report;
    }

    protected function isAlreadyApplied(\Throwable $e): bool
    {
        while ($e) {
            $message = $e->getMessage();
            if (
                str_contains($message, '42S01')
                || str_contains($message, 'Base table or view already exists')
                || str_contains($message, '42S21')
                || str_contains($message, 'Duplicate column name')
            ) {
                return true;
            }
            $e = $e->getPrevious();
        }

        return false;
    }

    protected function alreadyAppliedDetail(\Throwable $e): string
    {
        while ($e) {
            $message = $e->getMessage();
            if (preg_match("/Duplicate column name '([^']+)'/i", $message, $match)) {
                return __('column').' '.$match[1];
            }
            if (preg_match("/Table '([^']+)' already exists/i", $message, $match)) {
                $table = $match[1];
                $dot = strrpos($table, '.');
                if ($dot !== false) {
                    $table = substr($table, $dot + 1);
                }

                return __('table').' '.$table;
            }
            $e = $e->getPrevious();
        }

        return __('already in the database');
    }

    protected function friendlyMigrationError(string $migration, \Throwable $e): string
    {
        $message = $e->getMessage();
        foreach (['(SQL:', '(Connection:'] as $marker) {
            $pos = strpos($message, $marker);
            if ($pos !== false) {
                $message = trim(substr($message, 0, $pos));
            }
        }

        return __('Stopped on :migration. :reason', [
            'migration' => $migration,
            'reason' => $message !== '' ? $message : __('The migration could not be applied.'),
        ]);
    }

    /**
     * @return array<int, string>
     */
    protected function pendingMigrations(): array
    {
        return array_keys($this->pendingMigrationFiles());
    }

    /**
     * @return array<string, string> migration name => file path
     */
    protected function pendingMigrationFiles(): array
    {
        $migrator = app('migrator');
        $paths = array_values(array_unique(array_merge(
            [database_path('migrations')],
            $migrator->paths()
        )));
        $files = $migrator->getMigrationFiles($paths);
        $ran = $migrator->repositoryExists() ? $migrator->getRepository()->getRan() : [];

        return array_diff_key($files, array_flip($ran));
    }

    /**
     * @return array<int, string>
     */
    protected function logFiles(): array
    {
        $dir = storage_path('logs');
        if (! is_dir($dir)) {
            return [];
        }

        return collect(File::files($dir))
            ->filter(fn ($file) => strtolower($file->getExtension()) === 'log')
            ->sortByDesc(fn ($file) => $file->getMTime())
            ->map(fn ($file) => $file->getFilename())
            ->values()
            ->all();
    }

    protected function logPath(string $name): ?string
    {
        if ($name === '' || ! preg_match('/^[A-Za-z0-9._-]+\.log$/', $name)) {
            return null;
        }

        $dir = realpath(storage_path('logs'));
        $path = realpath(storage_path('logs'.DIRECTORY_SEPARATOR.$name));
        if ($dir === false || $path === false || ! is_file($path)) {
            return null;
        }
        if (! str_starts_with($path, $dir.DIRECTORY_SEPARATOR) && $path !== $dir) {
            return null;
        }

        return $path;
    }

    /**
     * @return array{body: string, truncated: bool, bytes: int}
     */
    protected function readLog(string $name): array
    {
        $path = $this->logPath($name);
        if ($path === null) {
            return ['body' => '', 'truncated' => false, 'bytes' => 0];
        }

        $bytes = (int) filesize($path);
        $max = 200000;
        if ($bytes === 0) {
            return ['body' => '', 'truncated' => false, 'bytes' => 0];
        }

        $handle = fopen($path, 'rb');
        if ($handle === false) {
            return ['body' => '', 'truncated' => false, 'bytes' => $bytes];
        }

        $truncated = $bytes > $max;
        if ($truncated) {
            fseek($handle, -$max, SEEK_END);
            fgets($handle);
        }
        $body = stream_get_contents($handle);
        fclose($handle);

        return [
            'body' => $body === false ? '' : $body,
            'truncated' => $truncated,
            'bytes' => $bytes,
        ];
    }

    protected function denyUnlessSuperAdmin()
    {
        if (Auth::user()->type !== 'super admin') {
            return redirect()->back()->with('error', __('Permission denied.'));
        }

        return null;
    }
}
