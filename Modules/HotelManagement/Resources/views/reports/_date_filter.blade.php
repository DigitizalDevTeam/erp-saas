<form method="get" class="row g-2 align-items-end mb-3">
    <div class="col-auto">
        <label class="form-label mb-0">{{ __('From') }}</label>
        <input type="date" name="from" class="form-control" value="{{ $from->toDateString() }}">
    </div>
    <div class="col-auto">
        <label class="form-label mb-0">{{ __('To') }}</label>
        <input type="date" name="to" class="form-control" value="{{ $to->toDateString() }}">
    </div>
    <div class="col-auto">
        <button type="submit" class="btn btn-primary">{{ __('Apply') }}</button>
    </div>
</form>
