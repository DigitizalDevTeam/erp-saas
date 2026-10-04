@extends('layouts.admin')

@section('page-title')
    {{ __('Dev Tool') }}
@endsection

@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Dev Tool') }}</li>
@endsection

@section('content')
    <div class="row">
        <div class="col-12">
            @if (session('success'))
                <div class="alert alert-success">{{ session('success') }}</div>
            @endif
            @if (session('error'))
                <div class="alert alert-danger">{{ session('error') }}</div>
            @endif
            @php $report = session('migrate_report'); @endphp
            @if (is_array($report))
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('Migration result') }}</h5>
                    </div>
                    <div class="card-body">
                        @if (! empty($report['ran']))
                            <p class="mb-2"><span class="badge bg-success">{{ count($report['ran']) }}</span> {{ __('applied') }}</p>
                            <ul>
                                @foreach ($report['ran'] as $name)
                                    <li><code>{{ $name }}</code></li>
                                @endforeach
                            </ul>
                        @endif
                        @if (! empty($report['skipped']))
                            <div class="alert alert-warning mb-2">
                                {{ __('These were already in the database, so the migration was marked as done and the next ones continued.') }}
                            </div>
                            <ul>
                                @foreach ($report['skipped'] as $row)
                                    <li>
                                        <code>{{ $row['detail'] ?? ($row['table'] ?? __('already in the database')) }}</code>
                                        — {{ $row['migration'] }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                        @if (! empty($report['failed']))
                            <div class="alert alert-danger mb-0">
                                {{ $report['failed']['message'] }}
                            </div>
                        @endif
                        @if (empty($report['ran']) && empty($report['skipped']) && empty($report['failed']))
                            <p class="mb-0 text-muted">{{ __('No pending migrations.') }}</p>
                        @endif
                    </div>
                </div>
            @endif

            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h5 class="mb-0">{{ __('Pending migrations') }}</h5>
                    <form method="POST" action="{{ route('dev-tool.migrate') }}" onsubmit="return confirm('{{ __('Run all pending migrations now?') }}');">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-primary" @if (count($pending) === 0) disabled @endif>
                            {{ __('Run migrations') }}
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    @if (count($pending) === 0)
                        <p class="mb-0 text-muted">{{ __('No pending migrations.') }}</p>
                    @else
                        <p class="text-muted">{{ __('These migration files have not been applied yet. If a table or column is already in the database, that step is marked done and the rest keep going.') }}</p>
                        <ul class="mb-0">
                            @foreach ($pending as $name)
                                <li><code>{{ $name }}</code></li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            </div>

            @php $seedReport = session('seed_report'); @endphp
            @if (is_array($seedReport))
                <div class="card mb-3">
                    <div class="card-header">
                        <h5 class="mb-0">{{ __('Seeder result') }}</h5>
                    </div>
                    <div class="card-body">
                        <p class="mb-2"><code>{{ $seedReport['seeder'] ?? '' }}</code></p>
                        @if (! empty($seedReport['failed']))
                            <div class="alert alert-danger mb-0">{{ $seedReport['failed'] }}</div>
                        @elseif (($seedReport['output'] ?? '') !== '')
                            <pre class="mb-0" style="max-height: 320px; overflow: auto; white-space: pre-wrap; background: #1e1e1e; color: #e8e8e8; padding: 12px; border-radius: 6px;">{{ $seedReport['output'] }}</pre>
                        @else
                            <p class="mb-0 text-muted">{{ __('Seeder finished with no output.') }}</p>
                        @endif
                    </div>
                </div>
            @endif

            <div class="card mb-3">
                <div class="card-header">
                    <h5 class="mb-0">{{ __('Run database seeder') }}</h5>
                </div>
                <div class="card-body">
                    <p class="text-muted">{{ __('Pick a seeder and run it. Seeders can add or update data, so run only the one you need.') }}</p>
                    <form method="POST" action="{{ route('dev-tool.run-seeder') }}" onsubmit="return confirm('{{ __('Run the selected seeder now? It may add or update data.') }}');">
                        @csrf
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <select name="seeder" class="form-select" style="min-width: 260px; max-width: 420px;" @if (count($seeders) === 0) disabled @endif>
                                <option value="">{{ __('Select seeder') }}</option>
                                @foreach ($seeders as $seeder)
                                    <option value="{{ $seeder }}">{{ $seeder }}</option>
                                @endforeach
                            </select>
                            <button type="submit" class="btn btn-primary" @if (count($seeders) === 0) disabled @endif>
                                {{ __('Run seeder') }}
                            </button>
                        </div>
                        @if (count($seeders) === 0)
                            <p class="mb-0 mt-2 text-muted">{{ __('No seeders found.') }}</p>
                        @endif
                    </form>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h5 class="mb-0">{{ __('Cache') }}</h5>
                    <form method="POST" action="{{ route('dev-tool.clear-all-caches') }}" onsubmit="return confirm('{{ __('Clear all caches now? Compiled views, application cache, configuration, routes and permissions. No data is deleted.') }}');">
                        @csrf
                        <button type="submit" class="btn btn-sm btn-danger">
                            {{ __('Clear everything') }}
                        </button>
                    </form>
                </div>
                <div class="card-body">
                    <p class="mb-0 text-muted">{{ __('Clears compiled views, application cache, configuration cache, route cache, event cache, compiled services and the permission cache. Use this after uploading updated files so the new code takes effect. No data is deleted.') }}</p>
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h5 class="mb-0">{{ __('Debug mode') }}</h5>
                    @if ($appDebug ?? false)
                        <span class="badge bg-warning text-dark">{{ __('ON') }}</span>
                    @else
                        <span class="badge bg-secondary">{{ __('OFF') }}</span>
                    @endif
                </div>
                <div class="card-body">
                    <p class="text-muted">{{ __('Turns APP_DEBUG in .env on or off. When ON, error pages show the full technical details — useful while fixing a problem. Keep it OFF on a live site and turn it back off as soon as you are done.') }}</p>
                    <form method="POST" action="{{ route('dev-tool.toggle-debug') }}" onsubmit="return confirm('{{ ($appDebug ?? false) ? __('Turn debug mode OFF?') : __('Turn debug mode ON? Error pages will show full technical details.') }}');">
                        @csrf
                        <input type="hidden" name="enabled" value="{{ ($appDebug ?? false) ? '0' : '1' }}">
                        @if ($appDebug ?? false)
                            <button type="submit" class="btn btn-sm btn-secondary">{{ __('Turn OFF') }}</button>
                        @else
                            <button type="submit" class="btn btn-sm btn-warning">{{ __('Turn ON') }}</button>
                        @endif
                    </form>
                </div>
            </div>

            <div class="card">
                <div class="card-header d-flex align-items-center justify-content-between flex-wrap gap-2">
                    <h5 class="mb-0">{{ __('Laravel log') }}</h5>
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <form method="GET" action="{{ route('dev-tool.index') }}" class="d-flex align-items-center gap-2">
                            <select name="file" class="form-select form-select-sm" style="min-width: 220px;" onchange="this.form.submit()">
                                @forelse ($logFiles as $file)
                                    <option value="{{ $file }}" @selected($file === $selectedLog)>{{ $file }}</option>
                                @empty
                                    <option value="">{{ __('No log files') }}</option>
                                @endforelse
                            </select>
                        </form>
                        @if ($selectedLog !== '')
                            <form method="POST" action="{{ route('dev-tool.clear-log') }}" onsubmit="return confirm('{{ __('Clear this log file?') }}');">
                                @csrf
                                <input type="hidden" name="file" value="{{ $selectedLog }}">
                                <button type="submit" class="btn btn-sm btn-danger">{{ __('Clear log') }}</button>
                            </form>
                        @endif
                    </div>
                </div>
                <div class="card-body">
                    @if ($selectedLog === '')
                        <p class="mb-0 text-muted">{{ __('No Laravel log file found.') }}</p>
                    @else
                        <p class="text-muted small mb-2">
                            {{ $selectedLog }}
                            @if ($logTruncated)
                                — {{ __('Showing the last part of the file.') }}
                            @endif
                        </p>
                        <pre class="mb-0" style="max-height: 520px; overflow: auto; white-space: pre-wrap; background: #1e1e1e; color: #e8e8e8; padding: 12px; border-radius: 6px;">{{ $logBody !== '' ? $logBody : __('Log is empty.') }}</pre>
                    @endif
                </div>
            </div>
        </div>
    </div>
@endsection
