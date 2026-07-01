# Onboarding: country-aware VAT + phone

Date: 2026-07-01
Status: approved design, ready for implementation plan

## Goal

Make the first-run onboarding form (`/app/onboarding`) feel professional by making it
country-aware: pick the country first, and the VAT number and contact phone fields then
guide and validate input based on that country. Validation is client-side only. The same
improvements land on the org profile edit page (`/app/organization`) because both pages
share the `company-fields` partial.

## Scope

In scope:
- Reorder the shared `company-fields` partial so Country is the first field.
- Country-driven VAT number field: auto-prefix, format hint, client-side format validation.
- Country-driven contact phone field: auto-prefill the dial code, client-side digit-count
  validation.
- A single source of country metadata in `config/tax.php`.
- Modest, class-based visual polish in `public/css/app.css` only.

Out of scope (explicitly):
- Server-side VAT/phone validation (owner chose client-side only; VAT and phone are
  nullable, so nothing new is rejected server-side).
- Validating the plain Registration number field (stays free text).
- Any markup-level redesign, CSS framework, or build step.
- Schema or controller changes (contact_phone stays one text column).

## Decisions (from brainstorming)

1. The country prefix + digit validation applies to the VAT number field (the owner's
   examples EE102347522 and LV41203036980 are EU VAT formats). Registration number stays
   free text.
2. Countries with no trustworthy format get free text, no validation.
3. Validation runs client-side only.
4. Contact phone stays a single text field; the dial code is prefilled and remains editable.

## Architecture

### Data source: `config/tax.php`

Each country row gains four optional keys next to the existing `name` and `vat`:

- `vat_prefix`: the VAT registration country code shown before the digits. Usually the same
  as the ISO code, with known exceptions: Greece is `EL` (not `GR`), Switzerland is `CHE`,
  Norway is `NO`. `null` means the country has no VAT prefix handling (free text).
- `vat_pattern`: a JavaScript regex source (string, no delimiters) that the national part
  after the prefix must match. `null` means no format check (free text) for that country.
- `dial_code`: international dialing code, e.g. `+372`.
- `phone_min` / `phone_max`: inclusive range for the count of digits in the national part of
  the phone number (dialing code excluded). Ranges are deliberately generous so valid
  numbers are never rejected. `null` means no digit-count check.

The onboarding and organization views already receive `config('tax.countries')`. The partial
emits this metadata as a JSON island for the client script. Keeping the data in config
matches the repo convention of one editable source per concern.

### View: `resources/views/app/partials/company-fields.blade.php`

- Move the Country `form-row` to the top of the partial (before Company name).
- VAT number input gains: `data-vat` marker, an `aria-describedby` hint element
  (`<p class="field-hint" id="vat_hint">`), and `autocomplete="off"`.
- Contact phone input gains: `data-phone` marker and a `field-hint` element (`phone_hint`).
- Add a `<script type="application/json" id="country-meta">` island containing the per
  country metadata, and a small vanilla `<script>` that wires the behavior. Because the
  partial is included once per page, the script runs once on each of onboarding and the org
  profile page with no duplication.

### Client script behavior (vanilla JS, no dependencies)

On DOMContentLoaded and on every change of the country `<select>`:

- VAT field:
  - Look up the selected country's `vat_prefix` / `vat_pattern`.
  - If the field is empty or currently holds only the previous country's prefix, set it to
    the new prefix. Never clobber digits the user already typed.
  - Force upper-case as the user types.
  - Set the input's native `pattern` attribute to `^<prefix>(<vat_pattern>)$` when a pattern
    exists (the pattern is wrapped in a group so alternations like `\d{9}|\d{12}` keep correct
    precedence), so the browser blocks submit and shows a message; clear `pattern` when the
    country has none (free text).
  - Update the hint text, e.g. "Format: EE + 9 digits". If unknown, "No specific format".
- Phone field:
  - If the field is empty or holds only the previous dial code, set it to the country's
    `dial_code` followed by a space.
  - On blur / submit, count the digits after the dial code; if outside
    `[phone_min, phone_max]` set a custom validity message ("Enter 7 to 8 digits after
    +372"); otherwise clear it. Uses the Constraint Validation API so it blocks submit.
  - Update the hint text, e.g. "Estonia numbers: +372 then 7 to 8 digits".

Guard rails: if the current field value looks user-edited (not empty, not just the old
prefix / dial code), the script leaves it alone so re-selecting a country on the profile
edit page never destroys saved data.

### CSS: `public/css/app.css`

Additive, class-based, override-friendly (a designer can still replace the whole file):

- `.field-hint` (muted, smaller, tight top margin under the input).
- `input:invalid` gets a subtle red outline (only after interaction, using `:user-invalid`
  where supported, falling back gracefully).
- Optional `.form-grid` two-column layout on wider viewports for the company fields, single
  column on narrow screens. Markup keeps meaningful class names.

No change to `body` max-width or existing rules beyond additions.

## Per-country data table

VAT `pattern` is the national part after the prefix (JS regex source). Phone range is the
count of digits after the dial code. Ranges are generous on purpose.

| ISO | Country        | VAT prefix | VAT national pattern      | Dial | Phone digits |
|-----|----------------|-----------|---------------------------|------|--------------|
| AT  | Austria        | AT        | `U\d{8}`                  | +43  | 7 to 13      |
| BE  | Belgium        | BE        | `\d{10}`                  | +32  | 8 to 9       |
| BG  | Bulgaria       | BG        | `\d{9,10}`                | +359 | 8 to 9       |
| HR  | Croatia        | HR        | `\d{11}`                  | +385 | 8 to 9       |
| CY  | Cyprus         | CY        | `\d{8}[A-Z]`              | +357 | 8 to 8       |
| CZ  | Czechia        | CZ        | `\d{8,10}`                | +420 | 9 to 9       |
| DK  | Denmark        | DK        | `\d{8}`                   | +45  | 8 to 8       |
| EE  | Estonia        | EE        | `\d{9}`                   | +372 | 7 to 8       |
| FI  | Finland        | FI        | `\d{8}`                   | +358 | 7 to 11      |
| FR  | France         | FR        | `[A-Z0-9]{2}\d{9}`        | +33  | 9 to 9       |
| DE  | Germany        | DE        | `\d{9}`                   | +49  | 7 to 11      |
| GR  | Greece         | EL        | `\d{9}`                   | +30  | 10 to 10     |
| HU  | Hungary        | HU        | `\d{8}`                   | +36  | 8 to 9       |
| IE  | Ireland        | IE        | `[A-Z0-9]{8,9}`           | +353 | 7 to 9       |
| IT  | Italy          | IT        | `\d{11}`                  | +39  | 9 to 11      |
| LV  | Latvia         | LV        | `\d{11}`                  | +371 | 8 to 8       |
| LT  | Lithuania      | LT        | `\d{9}\|\d{12}`           | +370 | 8 to 8       |
| LU  | Luxembourg     | LU        | `\d{8}`                   | +352 | 6 to 9       |
| MT  | Malta          | MT        | `\d{8}`                   | +356 | 8 to 8       |
| NL  | Netherlands    | NL        | `\d{9}B\d{2}`             | +31  | 9 to 9       |
| PL  | Poland         | PL        | `\d{10}`                  | +48  | 9 to 9       |
| PT  | Portugal       | PT        | `\d{9}`                   | +351 | 9 to 9       |
| RO  | Romania        | RO        | `\d{2,10}`                | +40  | 9 to 9       |
| SK  | Slovakia       | SK        | `\d{10}`                  | +421 | 9 to 9       |
| SI  | Slovenia       | SI        | `\d{8}`                   | +386 | 8 to 8       |
| ES  | Spain          | ES        | `[A-Z0-9]\d{7}[A-Z0-9]`   | +34  | 9 to 9       |
| SE  | Sweden         | SE        | `\d{12}`                  | +46  | 7 to 10      |
| GB  | United Kingdom | GB        | `\d{9}\|\d{12}`           | +44  | 9 to 10      |
| NO  | Norway         | NO        | `\d{9}`                   | +47  | 8 to 8       |
| CH  | Switzerland    | CHE       | `\d{9}`                   | +41  | 9 to 9       |
| US  | United States  | (none)    | (free text)               | +1   | 10 to 10     |

Notes:
- Greece's VAT prefix is `EL`, an intentional divergence from its ISO code `GR`.
- Formats with letters or wide variability (AT, CY, FR, IE, NL, ES) use lenient patterns that
  accept the real shape without over-fitting.
- US has no VAT: the field is free text there; the dial code and phone check still apply.
- All values live in `config/tax.php` and are trivially editable if a rule proves too tight.

## Error handling / edge cases

- Re-selecting a country on the profile page must not wipe an existing saved VAT or phone.
  The script only fills a field that is empty or still holds the previous prefix / dial code.
- No JavaScript (or a failed island parse): the form still submits; server rules are
  unchanged, so nothing breaks. Native `pattern` on VAT is set by JS, so with JS off there is
  simply no client format gate, which is acceptable.
- VAT and phone remain optional (nullable). An empty field is always valid.

## Testing

- Client-side behavior is verified manually in the browser (select several countries, watch
  prefix/dial-code prefill, hint text, and that bad input blocks submit while good input
  passes). No automated JS test harness exists in this repo and none is added here.
- A light PHP assertion (optional) that `config('tax.countries')` rows all carry the new keys
  can guard against a half-edited config; decide during planning whether to include it.

## Files touched

- `config/tax.php` (add metadata keys per country).
- `resources/views/app/partials/company-fields.blade.php` (reorder, hints, JSON island,
  script).
- `public/css/app.css` (additive polish).
