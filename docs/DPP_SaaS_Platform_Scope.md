# DPP SaaS Platform - Scope

**Product:** a multi-tenant SaaS where users create and host Digital Product Passports.
**Tiers (proposed):** Free (1 DPP) · Medium €9/mo (up to 5 DPP) · Commercial (custom).
**Stack assumption:** PHP backend, JS frontend, SCSS styling.

---

## 0. The constraint that shapes the whole business model
A DPP is **not a one-time artifact** - it is a legal commitment to keep a passport accessible for **product lifetime + ~10 years**, surviving customer churn, non-payment, and even bankruptcy.

Design consequences (decide these before building):
- **Tiers = recurring subscriptions**, not one-time fees. A one-time €9 for a 10-year hosting duty is an unfunded liability.
- **Separate "draft" from "published".** Free/Medium DPPs should be *drafts / test passports* by default. A passport only becomes **published (placed on market)** - registry push + persistence guarantee + locked master data - on a paid action.
- **Define a lapse policy.** What happens to a published DPP if the subscription ends? Options: forced migration/export, a paid "perpetual hosting" archive add-on, or transfer to the independent backup service-provider role. A published DPP can **never** silently 404.
- This is also why you may want a **separate per-published-DPP "lifetime hosting" SKU** funded enough to cover 10+ years, independent of the monthly subscription.

---

## 1. Plans & tiers

| | **Free** | **Medium - €9/mo** | **Commercial - custom** |
|---|---|---|---|
| Published DPPs | 1 (or draft-only) | up to 5 | custom / high volume |
| Draft DPPs | a few | more | unlimited |
| Product templates | basic | all standard | all + custom fields |
| QR / data carrier | yes | yes | yes + bulk generation |
| Tiered access views (consumer/repairer/recycler/authority) | basic | full | full + custom roles |
| Custom branding | no | logo only | full white-label / custom domain |
| EU Registry push | manual | included | automated / API |
| Bulk import (CSV/ERP) | no | no | yes |
| Public REST API | no | read-only / limited | full read+write |
| Team members | 1 | 2-3 | many + SSO |
| Persistence / backup guarantee | best-effort | standard | SLA + 3rd-party backup |
| Scan analytics | basic count | per-passport | advanced + export |
| Support | docs / community | email | priority / dedicated |

> Gating mix: **quota-gated** (number of DPPs, team seats) + **feature-gated** (API, white-label, bulk, SSO). Enforce both server-side, never only in UI.

---

## 2. SaaS foundation layer (generic platform requirements)

### 2.1 Account & tenancy model
- [ ] Hierarchy: **User → Organization (tenant) → Products → DPPs**. Bill at org level.
- [ ] Multi-tenancy with strict tenant isolation (no cross-tenant data leakage).
- [ ] One user can belong to multiple orgs (agencies managing client passports).

### 2.2 Authentication & authorization
- [ ] Email/password signup + email verification; password reset.
- [ ] OAuth / social login (optional); **SSO (SAML/OIDC)** for commercial tier.
- [ ] In-org roles: Owner, Admin, Editor, Viewer.
- [ ] 2FA option; session management; rate limiting on auth endpoints.

### 2.3 Billing & subscriptions
- [ ] Payment provider (Stripe Billing recommended) - subscriptions, not just one-off charges.
- [ ] Plan management: create/upgrade/downgrade, **proration**, monthly/annual.
- [ ] **EU VAT handling** (VAT MOSS / OSS, reverse charge for B2B with valid VAT ID), compliant **invoices/receipts**.
- [ ] Dunning / failed-payment recovery; grace period; **what-happens-on-lapse** logic (see §0).
- [ ] Commercial tier: custom quotes, manual invoicing, contracts.
- [ ] Usage metering (DPP count, API calls, storage) to enforce quotas.

### 2.4 Quota & feature enforcement
- [ ] Server-side checks: block creating DPP #2 on Free, #6 on Medium, etc.
- [ ] Feature flags per plan (API, bulk, white-label).
- [ ] Clear in-app upgrade prompts when a limit is hit.

### 2.5 Onboarding & UX
- [ ] First-run: create org → create first DPP via guided wizard → generate QR.
- [ ] Empty states that teach; "aha moment" = first scannable passport live.
- [ ] Template gallery per product category to reduce blank-page friction.

### 2.6 Admin / back-office
- [ ] Internal admin: view orgs, plans, usage; impersonate (with audit); handle support.
- [ ] Manage published-DPP lifecycle (migrations, archive, legal holds).

### 2.7 Cross-cutting
- [ ] Transactional email (verification, billing, scan alerts, expiry warnings).
- [ ] Dashboards: DPP status, scans, plan usage.
- [ ] **GDPR**: data processing agreement, export, deletion (respecting the DPP retention duty), cookie/consent.
- [ ] Security baseline: HTTPS, encryption at rest, ISO 27001 posture, audit logs, backups, DR.
- [ ] **i18n**: Latvian + English minimum; passport public layer must support the buyer's Member-State language.
- [ ] Help docs / knowledge base.

---

## 3. DPP product layer (what lives inside each passport)
*(This is the regulated core - see the separate compliance checklist for full detail.)*

### 3.1 Creation
- [ ] DPP wizard with **per-product-category templates** (textiles, furniture, electronics, etc.).
- [ ] Field validation; block "publish" until mandatory fields complete.
- [ ] Draft → Publish workflow (publish = lock master data + push to registry).

### 3.2 Identity & carrier
- [ ] Generate the four identifiers (product / operator / facility / registry).
- [ ] **GS1 Digital Link** URI as default identifier scheme.
- [ ] Generate **QR / Data Matrix** (and RFID/NFC where needed) - free, smartphone-readable, no app.
- [ ] Downloadable carrier assets (print-ready, correct quality/size).

### 3.3 Data & access
- [ ] Structured **JSON-LD** data model; master vs. lifecycle data separated.
- [ ] **Tiered public viewer**: consumer / repairer / recycler / authority / customs views with field-level permissions.
- [ ] Public passport page: fast, mobile-first, multilingual, no login for consumer layer.
- [ ] Versioning + audit trail of every change; append-only for locked fields.

### 3.4 Integrations & persistence
- [ ] **EU Registry push** (identifiers + commodity code for customs).
- [ ] Long-term persistence (lifetime + 10y) + **independent third-party backup**.
- [ ] Export / hand-over (so a passport survives churn or platform wind-down).
- [ ] Later: ERP/PLM import, ECHA/SCIP for substances.

### 3.5 Your legal role
- [ ] Decide: generic host vs. **ESPR "DPP service provider"** (authorized third party). The latter needs authorization records, processing logs, and explicit "economic operator remains accountable" terms in your ToS.

---

## 4. Suggested architecture (PHP / JS / SCSS)
- **Backend:** PHP (Laravel recommended - gives auth, queues, Stripe Cashier billing, multi-tenancy packages, API out of the box).
- **DB:** PostgreSQL or MySQL for relational/tenant data; store DPP payloads as JSON/JSONB (JSON-LD).
- **Frontend:** JS SPA or Laravel + Inertia/Livewire; **SCSS** design system; separate lightweight **public passport viewer** (must be fast, cacheable, CDN-served, no auth on consumer layer).
- **Queue/workers:** for QR generation, registry sync, email, backups.
- **Carrier generation:** server-side QR/Data Matrix library.
- **Caching/CDN:** public passport pages cached at edge (resolution must be fast and always-up).
- **Billing:** Stripe (Cashier). **Auth:** Laravel + SAML/OIDC package for SSO tier.

---

## 5. Build phases

**MVP (validate the loop):**
1. Auth + org/tenant model
2. Create 1 DPP (wizard + template) → JSON-LD store
3. GS1 Digital Link identifier + QR generation
4. Public passport viewer (consumer layer only)
5. Stripe billing + 3 plans + quota enforcement (1 / 5 / custom)
6. Basic dashboard

**Phase 2 (compliance depth):**
7. Tiered access views (repairer/recycler/authority)
8. EU Registry push + commodity code
9. Versioning + audit trail
10. Persistence/backup + lapse policy
11. VAT/invoicing polish, i18n (LV/EN)

**Phase 3 (commercial tier):**
12. REST API + API keys
13. Bulk import (CSV/ERP)
14. White-label / custom domain
15. SSO, team roles, SLA, advanced analytics

---

## 6. Open decisions (resolve before MVP)
- [x] ~~Is €9 per month or one-time?~~ **Confirmed: €9/month (recurring).** ✅
- [ ] **Lapse policy is now the #1 question** (see §0): a monthly sub covers hosting *while they pay*, but the 10-year duty outlives the subscription. Need a rule for published DPPs after cancellation.
- [ ] Does the Free tier allow a **published** DPP or **draft-only**? (Draft-only de-risks the 10-year liability.)
- [ ] Lapse policy for published DPPs (export / archive add-on / 3rd-party transfer).
- [ ] Do you operate as **DPP service provider** (regulated role) or generic host?
- [ ] Which product category do you target first? (Determines first templates + which delegated act fields to model.)
- [ ] Self-serve commercial pricing or sales-led quotes?
