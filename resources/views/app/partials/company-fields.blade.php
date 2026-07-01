{{--
  Shared company-profile fields. Used by onboarding and the org profile edit form, so the
  set of fields is defined in ONE place and is easy to adjust. $org prefills values.

  Country is first on purpose: selecting it drives the VAT number and the contact phone.
    - VAT number: the country prefix (e.g. LV) is shown locked and cannot be edited; only the
      national part is typed, and it is restricted to digits where the country's format is
      numeric (letters are allowed only for countries whose VAT genuinely contains them).
    - Contact phone: a searchable country-code dropdown selects the dialing code (defaults to
      the company country, changeable for a foreign number); only the national number is typed.
  Both national inputs are combined with their prefix into the hidden vat_id / contact_phone
  fields the server already expects. All of this is client-side; the server accepts values
  as-is. Per-country metadata comes from config/tax.php, emitted once as a JSON island.
--}}
@php
    // Compact metadata for the client script: name, prefix, national VAT pattern, dial code, phone range.
    $countryMeta = collect($countries)->map(fn ($info) => [
        'n' => $info['name'],
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
        <input id="registration_number" name="registration_number" type="text" value="{{ old('registration_number', $org->registration_number) }}" required>
        @error('registration_number')<p class="field-error">{{ $message }}</p>@enderror
    </div>

    <div class="form-row">
        <label for="vat_national">VAT number</label>
        <div class="input-group">
            <span class="input-prefix" id="vat_prefix" data-vat-prefix hidden></span>
            <input id="vat_national" type="text" autocomplete="off" aria-describedby="vat_hint" data-vat-national>
        </div>
        <input type="hidden" name="vat_id" id="vat_id" value="{{ old('vat_id', $org->vat_id) }}">
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
        <label for="phone_national">Contact phone</label>
        <div class="input-group">
            <select id="phone_country" aria-label="Country dialing code" data-phone-country>
                @foreach ($countries as $code => $info)
                    <option value="{{ $code }}">{{ $info['name'] }} ({{ $info['dial_code'] }})</option>
                @endforeach
            </select>
            <input id="phone_national" type="tel" inputmode="tel" autocomplete="off" aria-describedby="phone_hint" data-phone-national required>
        </div>
        <input type="hidden" name="contact_phone" id="contact_phone" value="{{ old('contact_phone', $org->contact_phone) }}">
        <p class="field-hint" id="phone_hint">Pick the dialing code and enter the number.</p>
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

    var vatPrefixEl = document.getElementById('vat_prefix');
    var vatNational = document.getElementById('vat_national');
    var vatHidden = document.getElementById('vat_id');
    var vatHint = document.getElementById('vat_hint');

    var phoneCountry = document.getElementById('phone_country');
    var phoneNational = document.getElementById('phone_national');
    var phoneHidden = document.getElementById('contact_phone');
    var phoneHint = document.getElementById('phone_hint');

    function countDigits(s) { return (String(s).match(/\d/g) || []).length; }

    // A VAT pattern is numeric-only when, after removing the \d tokens, no letters remain
    // (so NL "\d{9}B\d{2}" and AT "U\d{8}" keep letters; EE "\d{9}" is numeric-only).
    function isNumericVat(pattern) {
        if (!pattern) { return false; }
        return !/[A-Za-z]/.test(pattern.replace(/\\d/g, ''));
    }

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

    // Longest string the national pattern can match, used to cap the input length so more
    // than the required number of digits cannot be entered. Handles our token vocabulary
    // (\d, [A-Z0-9], literals) with {n} / {n,m} quantifiers and | alternation.
    function maxLen(pattern) {
        return pattern.split('|').reduce(function (mx, alt) {
            var len = 0, i = 0;
            while (i < alt.length) {
                if (alt[i] === '\\') { i += 2; }
                else if (alt[i] === '[') { i = alt.indexOf(']', i) + 1; }
                else { i += 1; }
                var q = alt.slice(i).match(/^\{(\d+)(?:,(\d+))?\}/);
                if (q) { len += q[2] !== undefined ? parseInt(q[2], 10) : parseInt(q[1], 10); i += q[0].length; }
                else { len += 1; }
            }
            return Math.max(mx, len);
        }, 0);
    }

    function vatMeta() { return meta[country.value] || null; }

    // Keep only the characters this country's VAT allows (digits, or upper-case alphanumerics).
    function filterVatNational() {
        var m = vatMeta();
        var numeric = m ? isNumericVat(m.r) : true;
        var pos = vatNational.selectionStart;
        var cleaned = numeric
            ? vatNational.value.replace(/\D/g, '')
            : vatNational.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
        if (cleaned !== vatNational.value) {
            vatNational.value = cleaned;
            try { vatNational.setSelectionRange(pos, pos); } catch (e) {}
        }
    }

    function syncVat() {
        var m = vatMeta();
        var national = vatNational.value.trim();
        var prefix = (m && m.p) ? m.p : '';
        vatHidden.value = national === '' ? '' : (prefix + national);
    }

    function applyVat() {
        var m = vatMeta();
        var prefix = (m && m.p) ? m.p : '';
        if (prefix) {
            vatPrefixEl.textContent = prefix;
            vatPrefixEl.hidden = false;
        } else {
            vatPrefixEl.textContent = '';
            vatPrefixEl.hidden = true;
        }
        // VAT is required only for countries that actually have a VAT format (a prefix).
        var vatRequired = !!prefix;
        if (vatRequired) { vatNational.setAttribute('required', ''); } else { vatNational.removeAttribute('required'); }
        if (m && m.r) {
            vatNational.setAttribute('pattern', '^(' + m.r + ')$');
            vatNational.setAttribute('maxlength', String(maxLen(m.r)));
        } else {
            vatNational.removeAttribute('pattern');
            vatNational.removeAttribute('maxlength');
        }
        var numeric = m ? isNumericVat(m.r) : true;
        vatNational.setAttribute('inputmode', numeric ? 'numeric' : 'text');
        if (vatHint) {
            vatHint.textContent = vatRequired
                ? 'Format: ' + describeVat(prefix, m.r) + '. Required.'
                : 'Not applicable for this country. Optional.';
        }
        filterVatNational();
        syncVat();
    }

    // ---- phone ----
    function phoneMeta() { return meta[phoneCountry.value] || null; }

    function nationalPhoneDigits() { return countDigits(phoneNational.value); }

    function validatePhone() {
        phoneNational.setCustomValidity('');
        var m = phoneMeta();
        if (!m || m.mn == null || m.mx == null) { return; }
        var n = nationalPhoneDigits();
        if (n === 0) { return; } // optional
        if (n < m.mn || n > m.mx) {
            var range = (m.mn === m.mx) ? String(m.mn) : (m.mn + ' to ' + m.mx);
            phoneNational.setCustomValidity('Enter ' + range + ' digits for ' + (m.d || 'this country') + '.');
        }
    }

    function syncPhone() {
        var m = phoneMeta();
        var national = phoneNational.value.trim();
        phoneHidden.value = (national === '' || countDigits(national) === 0)
            ? ''
            : ((m && m.d ? m.d + ' ' : '') + national);
    }

    function applyPhone() {
        var m = phoneMeta();
        if (phoneHint) {
            if (m && m.mn != null && m.mx != null) {
                var range = (m.mn === m.mx) ? (m.mn + ' digits') : (m.mn + ' to ' + m.mx + ' digits');
                phoneHint.textContent = 'Enter ' + range + ' after ' + (m.d || 'the country code') + '. Optional.';
            } else {
                phoneHint.textContent = 'Optional.';
            }
        }
        validatePhone();
        syncPhone();
    }

    // ---- init: split any pre-filled hidden values back into their parts (edit page) ----
    function initVat() {
        var m = vatMeta();
        var existing = (vatHidden.value || '').trim().toUpperCase();
        if (existing) {
            var prefix = (m && m.p) ? m.p : '';
            vatNational.value = (prefix && existing.indexOf(prefix) === 0)
                ? existing.slice(prefix.length)
                : existing.replace(/^[A-Z]{2,3}/, ''); // strip a leading country code if present
        }
        applyVat();
    }

    function initPhone() {
        var existing = (phoneHidden.value || '').trim();
        // Prefer the country whose dial code the stored number starts with (longest match).
        var best = null;
        if (existing) {
            Object.keys(meta).forEach(function (code) {
                var d = meta[code].d;
                if (d && existing.indexOf(d) === 0 && (!best || d.length > meta[best].d.length)) { best = code; }
            });
        }
        if (best) {
            phoneCountry.value = best;
            phoneNational.value = existing.slice(meta[best].d.length).trim();
        } else {
            phoneCountry.value = country.value || phoneCountry.value;
            phoneNational.value = existing;
        }
        applyPhone();
    }

    // ---- wiring ----
    country.addEventListener('change', function () {
        applyVat();
        // Auto-follow: point the phone dialing code at the newly chosen company country.
        if (meta[country.value]) { phoneCountry.value = country.value; }
        applyPhone();
    });
    vatNational.addEventListener('input', function () { filterVatNational(); syncVat(); });
    phoneCountry.addEventListener('change', applyPhone);
    phoneNational.addEventListener('input', function () { validatePhone(); syncPhone(); });

    var form = country.form;
    if (form) {
        form.addEventListener('submit', function () { syncVat(); syncPhone(); });
    }

    initVat();
    initPhone();
})();
</script>
