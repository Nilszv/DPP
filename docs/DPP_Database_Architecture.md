# DPP SaaS - Database Architecture & Schema

**Goal:** host a very large number of DPP records, stay fast under heavy *read* load (QR scans), and satisfy the regulatory data obligations.
**Stack (confirmed):** PHP 8.3 / Laravel 12 · **PostgreSQL 14** · object storage (local disk in dev → S3-compatible in prod). Redis + CDN are designed-for but **added later** (see §5 / §9).

---

## 0. Confirmed decisions & review refinements (2026-06-30)

**Decisions locked:**
- **PostgreSQL** is the engine (native JSONB, partial unique indexes, declarative partitioning, GIN).
- **Snapshot-now, Redis/CDN-later:** build the `published_snapshots` table + rebuild-on-publish job + "resolver reads one snapshot row, never a live join" pattern now. Redis and CDN drop in front of that later with **no rewrite**. Keeps the MVP free of a Redis service dependency.

**Five refinements applied to the design below:**
1. **Canonical hashing.** `content_hash` = `sha256(` canonical JSON `)` - keys sorted, compact (no insignificant whitespace), UTF-8. Without a canonical form the hash isn't reproducible/verifiable. (RFC 8785-style JCS / sorted-key compact encoding.)
2. **GDPR erasure vs. append-only retention.** Keep personal data **out of the locked master version**. Lifecycle/repair entries that may contain personal data live on a separately-erasable path so erasure never breaks the immutable, hashed version chain.
3. **`scan_events.ip_hash` must be a keyed HMAC** with a rotating salt (or a truncated IP), not a bare hash - a plain hash of an IP is reversible.
4. **Redis/CDN are not day-one** (see decision above).
5. **Partition maintenance is a real job.** A scheduled command pre-creates next month's `scan_events` / `audit_log` partitions ahead of the boundary, or inserts fail.

---

## 1. Is there a DB regulation? No - but these obligations constrain the design

| Obligation (ESPR/GDPR) | What it forces in the data layer |
|---|---|
| Decentralised hosting | You store everything; the EU stores only identifiers. Scale is your problem. |
| Structured & machine-readable | Store the passport body as **JSON-LD** (JSONB), validated against a template. |
| Lifetime + ~10y retention, survives churn | **Archive tier** + retention dates + can't hard-delete published passports. |
| Versioning + audit | **Append-only** version history; master data locked after publish. |
| Tamper-evidence | Store a **content hash** (SHA-256) per published version. |
| Field-level / tiered access | Access map per template; **pre-rendered per-audience views**. |
| GDPR | Lifecycle data may be personal → minimise, support export/erasure within retention rules. |
| Availability / fast resolution | Read path must be cache/CDN-served, not live joins. |

> The fields *inside* a passport are set per product group by each **delegated act** - and they differ by category. That's why the passport body is **JSONB validated against a template**, not a rigid column-per-field table.

---

## 2. Core principle: split "system of record" from "system of delivery"

```
WRITE side (normalised, transactional)      READ side (denormalised, cached)
  tenants / products / passports      --->   published_snapshots (per audience+locale)
  passport_versions (append-only)            Redis cache  ->  CDN edge
        |  on publish/update: rebuild snapshot  ^
        +---------------------------------------+
```

- **Scan = single key lookup** of a pre-built snapshot. No joins on the hot path.
- Published master data is immutable → cache aggressively with long TTL, bust on update.
- Writes are rare vs. reads; they can afford to be normalised and do the heavy lifting.

---

## 3. Schema (PostgreSQL)

### Tenancy & billing
```sql
tenants (
  id           uuid PRIMARY KEY,
  name         text NOT NULL,
  slug         text UNIQUE NOT NULL,
  plan         text NOT NULL,          -- free | medium | commercial
  custom_domain text,                  -- commercial tier resolver domain
  vat_id       text,
  status       text NOT NULL,
  created_at   timestamptz DEFAULT now()
);

users (id uuid PRIMARY KEY, email citext UNIQUE NOT NULL, ...);
tenant_members (tenant_id uuid, user_id uuid, role text,
                PRIMARY KEY (tenant_id, user_id));
subscriptions (id uuid PRIMARY KEY, tenant_id uuid, status text,
               current_period_end timestamptz, ...);
```

### Product / passport core (system of record)
```sql
products (
  id         uuid PRIMARY KEY,
  tenant_id  uuid NOT NULL REFERENCES tenants,
  name       text NOT NULL,
  category   text NOT NULL,            -- drives template + delegated-act fields
  created_at timestamptz DEFAULT now()
);

passports (
  id                uuid PRIMARY KEY,
  tenant_id         uuid NOT NULL REFERENCES tenants,   -- isolation / partition key
  product_id        uuid NOT NULL REFERENCES products,
  public_id         uuid UNIQUE NOT NULL,               -- opaque URL id (no-GTIN path)
  identifier_scheme text NOT NULL,                      -- gs1 | self | iec61406 | did
  gtin              text,                               -- nullable (GS1 path)
  serial            text,
  batch             text,
  status            text NOT NULL DEFAULT 'draft',      -- draft | published | archived
  current_version_id uuid,
  default_locale    text NOT NULL DEFAULT 'lv',
  published_at      timestamptz,
  retention_until   date,                               -- published_at + lifetime + 10y
  created_at        timestamptz DEFAULT now(),
  updated_at        timestamptz DEFAULT now()
);

-- append-only version history
passport_versions (
  id           uuid PRIMARY KEY,
  passport_id  uuid NOT NULL REFERENCES passports,
  version_no   int  NOT NULL,
  data         jsonb NOT NULL,         -- the JSON-LD passport body
  content_hash text NOT NULL,          -- sha256(data) for tamper-evidence
  created_by   uuid,
  created_at   timestamptz DEFAULT now(),
  locked       boolean DEFAULT false   -- true once published (immutable)
);

templates (
  id         uuid PRIMARY KEY,
  category   text NOT NULL,
  field_schema jsonb NOT NULL,         -- which fields, validation
  access_map jsonb NOT NULL            -- field -> [consumer,repairer,recycler,authority]
);
```

### Delivery (read-optimised, rebuilt on publish/update)
```sql
published_snapshots (
  passport_id uuid NOT NULL REFERENCES passports,
  audience    text NOT NULL,           -- consumer | repairer | recycler | authority | full
  locale      text NOT NULL,
  rendered    jsonb NOT NULL,          -- pre-filtered, pre-translated view
  etag        text NOT NULL,
  updated_at  timestamptz DEFAULT now(),
  PRIMARY KEY (passport_id, audience, locale)
);
```

### Analytics & compliance (append-only, time-partitioned)
```sql
scan_events (                          -- the biggest, fastest-growing table
  id          bigserial,
  passport_id uuid NOT NULL,
  ts          timestamptz NOT NULL,
  link_type   text,
  locale      text,
  country     text,
  ua_class    text,
  ip_hash     text                     -- hashed, GDPR-friendly
) PARTITION BY RANGE (ts);             -- monthly partitions

registry_sync (passport_id uuid, registry_id text, commodity_code text,
               status text, synced_at timestamptz);

audit_log (id bigserial, tenant_id uuid, actor uuid, action text,
           target text, ts timestamptz, meta jsonb)
           PARTITION BY RANGE (ts);
```

---

## 4. Indexing (the ones that matter)
```sql
-- fast resolver lookups
CREATE UNIQUE INDEX ON passports (public_id);
CREATE UNIQUE INDEX ON passports (gtin, serial)
  WHERE identifier_scheme = 'gs1';
CREATE INDEX ON passports (tenant_id);
CREATE INDEX ON passports (status);
CREATE INDEX ON passports (retention_until);          -- archival jobs

-- version history
CREATE INDEX ON passport_versions (passport_id, version_no DESC);

-- analytics
CREATE INDEX ON scan_events (passport_id, ts DESC);   -- on each partition

-- optional: JSONB GIN index only if you query inside the body
-- CREATE INDEX ON passport_versions USING gin (data jsonb_path_ops);
```

---

## 5. Caching & delivery layers (the hot path)
1. **CDN edge** in front of the public passport GET. Published content is immutable → long TTL, purge on update via `etag`/versioned URL.
2. **Redis**: `dpp:{public_id}:{audience}:{locale}` → rendered snapshot JSON. Sub-millisecond lookups.
3. **Read replicas** of Postgres for any reads that miss cache.
4. Origin only rebuilds a snapshot on **publish/update** (a queued job), never per scan.

Result: a QR scan is normally **CDN hit → Redis → (rarely) replica**. The primary DB barely sees scan traffic.

---

## 6. Partitioning & scaling strategy
- **`scan_events` and `audit_log`: partition by month.** Drop/archive old partitions cheaply; queries hit only recent ones. This is your highest-volume data - keep it out of the main tables.
- **`passports`: single table + good indexes is fine for millions.** Only partition by `tenant_id` (hash) if/when you reach very large scale or noisy-neighbour issues.
- **Read replicas** scale reads horizontally; the resolver reads from replicas/cache.
- Keep large binaries (passport images, documents, QR PNGs, backup snapshots) in **object storage**, never in the DB - store only the URL/key.

---

## 7. Storage tiering for the 10-year retention
| Tier | What | Where |
|---|---|---|
| Hot | Active + recently scanned passports | Postgres + Redis + CDN |
| Warm | Live but rarely scanned | Postgres (replica) |
| Cold | `archived` / end-of-life but legally retained | JSON-LD files in object storage, served on demand |
| Backup | Independent 3rd-party copy (ESPR) | Separate provider / region |

An archived passport doesn't need to sit in your primary DB - export it as a signed JSON-LD file to cheap object storage and resolve it from there. Keeps the hot DB small and fast no matter how many historical records accumulate.

---

## 8. Integrity & audit
- Store `content_hash = sha256(data)` per published version; serve it so anyone can verify the passport wasn't altered.
- `locked = true` on publish → never UPDATE the body; corrections create a **new version**.
- All access/edits → `audit_log`.
- (Optional, commercial tier) anchor hashes to a ledger for stronger anti-counterfeiting.

---

## 9. MVP vs. scale-later
**Build now (MVP):**
- The normalised core + append-only versions
- `published_snapshots` + Redis + CDN read path (this is the one to do early - retrofitting is painful)
- Monthly partitioning on `scan_events`
- Object storage for assets

**Add when volume demands it:**
- Read replicas
- Tenant-hash partitioning of `passports`
- Cold-archive export job + backup-provider copy
- JSONB GIN indexes / separate analytics (OLAP) store
- Ledger anchoring

> Don't shard on day one. The read/write split and the partitioned scan table are what actually keep you fast; everything else is added when metrics say so.
