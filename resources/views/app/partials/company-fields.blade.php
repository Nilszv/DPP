{{--
  Shared company-profile fields. Used by onboarding and the org profile edit form, so the
  set of fields is defined in ONE place and is easy to adjust. $org prefills values.
--}}
<div class="form-row">
    <label for="legal_name">Company name</label>
    <input id="legal_name" name="legal_name" type="text" value="{{ old('legal_name', $org->legal_name) }}" required>
    @error('legal_name')<p class="field-error">{{ $message }}</p>@enderror
</div>

<div class="form-row">
    <label for="registration_number">Registration number</label>
    <input id="registration_number" name="registration_number" type="text" value="{{ old('registration_number', $org->registration_number) }}">
</div>

<div class="form-row">
    <label for="vat_id">VAT number</label>
    <input id="vat_id" name="vat_id" type="text" value="{{ old('vat_id', $org->vat_id) }}">
</div>

<div class="form-row">
    <label for="address_line1">Address line 1</label>
    <input id="address_line1" name="address_line1" type="text" value="{{ old('address_line1', $org->address_line1) }}" required>
    @error('address_line1')<p class="field-error">{{ $message }}</p>@enderror
</div>

<div class="form-row">
    <label for="address_line2">Address line 2</label>
    <input id="address_line2" name="address_line2" type="text" value="{{ old('address_line2', $org->address_line2) }}">
</div>

<div class="form-row">
    <label for="city">City</label>
    <input id="city" name="city" type="text" value="{{ old('city', $org->city) }}" required>
    @error('city')<p class="field-error">{{ $message }}</p>@enderror
</div>

<div class="form-row">
    <label for="postal_code">Postal code</label>
    <input id="postal_code" name="postal_code" type="text" value="{{ old('postal_code', $org->postal_code) }}" required>
    @error('postal_code')<p class="field-error">{{ $message }}</p>@enderror
</div>

<div class="form-row">
    <label for="country">Country (sets the applicable tax rate)</label>
    <select id="country" name="country" required>
        <option value="">Select a country</option>
        @foreach ($countries as $code => $info)
            <option value="{{ $code }}" @selected(old('country', $org->country) === $code)>
                {{ $info['name'] }} ({{ rtrim(rtrim(number_format($info['vat'], 1), '0'), '.') }}% VAT)
            </option>
        @endforeach
    </select>
    @error('country')<p class="field-error">{{ $message }}</p>@enderror
</div>

<div class="form-row">
    <label for="contact_name">Contact person name</label>
    <input id="contact_name" name="contact_name" type="text" value="{{ old('contact_name', $org->contact_name) }}" required>
    @error('contact_name')<p class="field-error">{{ $message }}</p>@enderror
</div>

<div class="form-row">
    <label for="contact_email">Contact email</label>
    <input id="contact_email" name="contact_email" type="email" value="{{ old('contact_email', $org->contact_email) }}" required>
    @error('contact_email')<p class="field-error">{{ $message }}</p>@enderror
</div>

<div class="form-row">
    <label for="contact_phone">Contact phone</label>
    <input id="contact_phone" name="contact_phone" type="text" value="{{ old('contact_phone', $org->contact_phone) }}">
</div>
