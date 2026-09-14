@extends('layouts.admin')
@section('page-title')
    {{ __('Layout Management') }}
@endsection
@section('breadcrumb')
    <li class="breadcrumb-item"><a href="{{ route('dashboard') }}">{{ __('Dashboard') }}</a></li>
    <li class="breadcrumb-item">{{ __('Layout Management') }}</li>
@endsection
@section('action-btn')
    <div class="float-end">
        <a href="{{ route('document-layouts.create', ['document_type' => $type]) }}" class="btn btn-sm btn-primary">
            {{ __('Create Layout') }}
        </a>
    </div>
@endsection
@section('content')
    <div class="row mb-3">
        <div class="col-12">
            <div class="btn-group flex-wrap" role="group">
                @foreach ($types as $key => $label)
                    <a href="{{ route('document-layouts.index', ['document_type' => $key]) }}"
                       class="btn btn-sm {{ $type === $key ? 'btn-primary' : 'btn-outline-primary' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>
            <p class="text-muted small mt-2 mb-0">
                {{ __('Create and activate a custom print layout. When active, it replaces the default Blade template for that document type.') }}
            </p>
            <div class="alert alert-light border mt-2 mb-0 py-2 small">
                <strong>{{ __('UTHARA Mozambique pack') }}:</strong>
                {{ __('Factura A4/A5, Talão 80mm and Inventory Map are managed under POS Settings (UTHARA print pack + paper size). They take priority over designer layouts when enabled.') }}
                —
                @foreach(\App\Services\DocumentLayoutCatalog::utharaPresets() as $presetLabel)
                    <span class="badge bg-success-subtle text-success border me-1">{{ $presetLabel }}</span>
                @endforeach
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-12">
            <div class="card">
                <div class="card-body table-border-style">
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                            <tr>
                                <th>{{ __('Name') }}</th>
                                <th>{{ __('Type') }}</th>
                                <th>{{ __('Status') }}</th>
                                <th>{{ __('Updated') }}</th>
                                <th class="text-end">{{ __('Action') }}</th>
                            </tr>
                            </thead>
                            <tbody>
                            @forelse ($layouts as $layout)
                                <tr>
                                    <td>{{ $layout->name }}</td>
                                    <td>{{ $types[$layout->document_type] ?? $layout->document_type }}</td>
                                    <td>
                                        @if ($layout->is_active)
                                            <span class="badge bg-success">{{ __('Active') }}</span>
                                        @else
                                            <span class="badge bg-secondary">{{ __('Draft') }}</span>
                                        @endif
                                    </td>
                                    <td>{{ optional($layout->updated_at)->format('Y-m-d H:i') }}</td>
                                    <td class="text-end">
                                        <a href="{{ route('document-layouts.edit', $layout->id) }}" class="btn btn-sm btn-outline-primary">{{ __('Edit') }}</a>
                                        @if ($layout->is_active)
                                            <form action="{{ route('document-layouts.deactivate', $layout->id) }}" method="POST" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-secondary">{{ __('Deactivate') }}</button>
                                            </form>
                                        @else
                                            <form action="{{ route('document-layouts.activate', $layout->id) }}" method="POST" class="d-inline">
                                                @csrf
                                                <button type="submit" class="btn btn-sm btn-outline-success">{{ __('Activate') }}</button>
                                            </form>
                                        @endif
                                        <a href="{{ route('document-layouts.preview', $layout->id) }}" target="_blank" class="btn btn-sm btn-outline-info">{{ __('Preview') }}</a>
                                        <form action="{{ route('document-layouts.destroy', $layout->id) }}" method="POST" class="d-inline" onsubmit="return confirm('{{ __('Delete this layout?') }}');">
                                            @csrf
                                            @method('DELETE')
                                            <button type="submit" class="btn btn-sm btn-outline-danger">{{ __('Delete') }}</button>
                                        </form>
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted">{{ __('No layouts yet. Create one to get started.') }}</td>
                                </tr>
                            @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
@endsection
