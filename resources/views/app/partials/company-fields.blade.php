{{--
  Shared company-profile fields. Used by onboarding and the org profile edit form, so the
  set of fields is defined in ONE place and is easy to adjust. $org prefills values.

  Country is first on purpose: selecting it drives the VAT number prefix/format and the
  contact phone dial code + digit-count check (all client-side; see the script below).
  The per-country metadata comes from config/tax.php, emitted once as a JSON island.
--}}
@php
    // Compact metadata for the client script: prefix, national VAT pattern, dial code, phone range.
    $countryMeta = collect($countries)->map(fn ($info) => [
        'p' => $info['vat_prefix'] ?? null,
        'r' => $info['vat_pattern'] ?? null,
        'd' => $info['dial_code'] ?? null,
        'mn' => $info['phone_min'] ?? null,
        'mx' => $info['phone_max'] ?? null,
    ]);
@endphp

<div id="company-fields" class="form-grid">
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
        <input id="vat_id" name="vat_id" type="text" value="{{ old('vat_id', $org->vat_id) }}"
               data-vat autocomplete="off" aria-describedby="vat_hint">
        <p class="field-hint" id="vat_hint">Select a country to see the expected format.</p>
        @error('vat_id')<p class="field-error">{{ $message }}</p>@enderror
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
        <input id="contact_phone" name="contact_phone" type="text" value="{{ old('contact_phone', $org->contact_phone) }}"
               data-phone autocomplete="off" aria-describedby="phone_hint">
        <p class="field-hint" id="phone_hint">Select a country to prefill the dialing code.</p>
    </div>
</div>

<script type="application/json" id="country-meta">{!! json_encode($countryMeta, JSON_UNESCAPED_SLASHES) !!}</script>
<script>
// Country-aware VAT + phone helpers. Client-side only; the server accepts these as-is.
(function () {
    var metaEl = document.getElementById('country-meta');
    var country = document.getElementById('country');
    if (!metaEl || !country) { return; }

    var meta = JSON.parse(metaEl.textContent);
    var vat = document.getElementById('vat_id');
    var vatHint = document.getElementById('vat_hint');
    var phone = document.getElementById('contact_phone');
    var phoneHint = document.getElementById('phone_hint');
    var prev = { vatPrefix: '', dial: '' };

    function countDigits(s) { return (String(s).match(/\d/g) || []).length; }

    // Friendly description of a national VAT pattern for the common cases.
    function describeVat(prefix, pattern) {
        if (!pattern) { return prefix + ' followed by the number, as issued'; }
        var m;
        if ((m = pattern.match(/^\\d\{(\d+)\}$/))) { return prefix + ' + ' + m[1] + ' digits'; }
        if ((m = pattern.match(/^\\d\{(\d+),(\d+)\}$/))) { return prefix + ' + ' + m[1] + ' to ' + m[2] + ' digits'; }
        if (/^\\d\{\d+\}\|\\d\{\d+\}$/.test(pattern)) {
            var counts = (pattern.match(/\{(\d+)\}/g) || []).map(function (x) { return x.replace(/[{}]/g, ''); });
            return prefix + ' + ' + counts.join(' or ') + ' digits';
        }
        return prefix + ' + national number (letters and digits allowed)';
    }

    function applyVat(m) {
        if (!vat) { return; }
        if (m && m.p) {
            var cur = vat.value.trim().toUpperCase();
            // Prefill the prefix only when the field is empty or still holds the old prefix.
            if (cur === '' || cur === prev.vatPrefix) { vat.value = m.p; }
            // Allow empty, prefix-only (treated as empty on submit), or the full VAT number.
            var national = m.r ? '((' + m.r + '))?' : '';
            vat.setAttribute('pattern', '^(' + m.p + national + ')?$');
            if (vatHint) { vatHint.textContent = 'Format: ' + describeVat(m.p, m.r) + '. Optional.'; }
            prev.vatPrefix = m.p;
        } else {
            vat.removeAttribute('pattern');
            if (vatHint) { vatHint.textContent = 'No VAT number needed for this country. Optional.'; }
            prev.vatPrefix = '';
        }
    }

    function nationalPhoneDigits(value, dial) {
        var s = value.trim();
        if (dial && s.indexOf(dial) === 0) { s = s.slice(dial.length); }
        return countDigits(s);
    }

    function validatePhone(m) {
        if (!phone) { return; }
        phone.setCustomValidity('');
        if (!m || m.mn == null || m.mx == null) { return; }
        var n = nationalPhoneDigits(phone.value, m.d);
        if (n === 0) { return; } // empty / dial-code only -> optional, valid
        if (n < m.mn || n > m.mx) {
            var range = (m.mn === m.mx) ? String(m.mn) : (m.mn + ' to ' + m.mx);
            phone.setCustomValidity('Enter ' + range + ' digits after ' + (m.d || 'the country code') + '.');
        }
    }

    function applyPhone(m) {
        if (!phone) { return; }
        if (m && m.d) {
            var cur = phone.value.trim();
            if (cur === '' || cur === prev.dial || cur === prev.dial.trim()) { phone.value = m.d + ' '; }
            prev.dial = m.d;
        }
        if (phoneHint) {
            if (m && m.mn != null && m.mx != null) {
                var range = (m.mn === m.mx) ? (m.mn + ' digits') : (m.mn + ' to ' + m.mx + ' digits');
                phoneHint.textContent = 'Enter ' + range + ' after ' + (m.d || 'the country code') + '. Optional.';
            } else {
                phoneHint.textContent = 'Optional.';
            }
        }
        validatePhone(m);
    }

    function current() { return meta[country.value] || null; }

    function apply() { var m = current(); applyVat(m); applyPhone(m); }

    country.addEventListener('change', apply);
    if (vat) {
        vat.addEventListener('input', function () {
            var pos = vat.selectionStart;
            vat.value = vat.value.toUpperCase();
            try { vat.setSelectionRange(pos, pos); } catch (e) {}
        });
    }
    if (phone) { phone.addEventListener('input', function () { validatePhone(current()); }); }

    // On submit, drop a field that holds only its prefix / dial code so it stays optional.
    var form = country.form;
    if (form) {
        form.addEventListener('submit', function () {
            var m = current();
            if (vat && m && m.p && vat.value.trim().toUpperCase() === m.p) { vat.value = ''; }
            if (phone && m && nationalPhoneDigits(phone.value, m.d) === 0) { phone.value = ''; }
        });
    }

    apply(); // initialise for the currently selected country (edit page prefill)
})();
</script>
