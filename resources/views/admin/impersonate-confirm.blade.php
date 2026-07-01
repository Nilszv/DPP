@extends('layouts.admin')
@section('title', 'Confirm impersonation - DPP Admin')

@section('content')
    <h1>Confirm your identity to impersonate {{ $target->email }}</h1>
    <p class="muted">
        For security, impersonating a user always requires a fresh two-factor code, even though
        you're already signed in as an admin.
    </p>

    <form class="auth-form" method="POST" action="{{ route('admin.impersonate.confirm.submit') }}">
        @csrf
        <div class="form-row">
            <label for="code">6-digit code</label>
            <input id="code" name="code" type="text" inputmode="numeric"
                   pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" autofocus>
        </div>

        <p class="auth-alt">Lost your device? Use a recovery code instead:</p>

        <div class="form-row">
            <label for="recovery_code">Recovery code</label>
            <input id="recovery_code" name="recovery_code" type="text" autocomplete="off">
        </div>

        @error('code')
            <p class="field-error" role="alert">{{ $message }}</p>
        @enderror

        <div class="form-actions">
            <button type="submit">Confirm and impersonate</button>
        </div>
    </form>
@endsection
