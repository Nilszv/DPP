@extends('layouts.app')
@section('title', 'New passport - DPP Platform')

@section('content')
    <h1>New passport</h1>

    <form method="POST" action="{{ route('passports.store') }}">
        @csrf
        <div class="form-row">
            <label for="product_name">Product name</label>
            <input id="product_name" name="product_name" type="text" required value="{{ old('product_name') }}">
            @error('product_name')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-row">
            <label for="template_id">Template</label>
            <select id="template_id" name="template_id" required>
                @foreach ($templates as $template)
                    <option value="{{ $template->id }}">{{ $template->name }}</option>
                @endforeach
            </select>
            @error('template_id')<p class="field-error">{{ $message }}</p>@enderror
        </div>

        <div class="form-actions">
            <button type="submit">Create draft</button>
            <a href="{{ route('passports.index') }}">Cancel</a>
        </div>
    </form>
@endsection
