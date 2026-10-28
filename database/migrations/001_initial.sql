CREATE TABLE instances (
 id TEXT PRIMARY KEY, title TEXT NOT NULL CHECK(length(title) BETWEEN 1 AND 140),
 description TEXT NOT NULL DEFAULT '' CHECK(length(description)<=2000),
 timezone TEXT NOT NULL DEFAULT 'Asia/Tokyo' CHECK(timezone='Asia/Tokyo'),
 status TEXT NOT NULL DEFAULT 'open' CHECK(status IN ('open','locked','confirmed','deleted')),
 version INTEGER NOT NULL DEFAULT 1, schema_revision INTEGER NOT NULL DEFAULT 1,
 deleted_at TEXT, status_before_delete TEXT, created_at TEXT NOT NULL, updated_at TEXT NOT NULL
);
CREATE INDEX instances_updated ON instances(deleted_at, updated_at);
CREATE TABLE instance_days (
 id TEXT PRIMARY KEY, instance_id TEXT NOT NULL REFERENCES instances(id), local_date TEXT NOT NULL,
 sort_index INTEGER NOT NULL, archived_at TEXT, UNIQUE(instance_id, local_date)
);
CREATE TABLE day_slots (
 id TEXT PRIMARY KEY, day_id TEXT NOT NULL REFERENCES instance_days(id), ordinal INTEGER NOT NULL CHECK(ordinal BETWEEN 0 AND 47),
 starts_at_utc TEXT NOT NULL, ends_at_utc TEXT NOT NULL, is_candidate INTEGER NOT NULL CHECK(is_candidate IN (0,1)),
 UNIQUE(day_id, ordinal)
);
CREATE TABLE audit_log (
 id INTEGER PRIMARY KEY, instance_id TEXT NOT NULL REFERENCES instances(id), actor TEXT NOT NULL,
 action TEXT NOT NULL, summary_json TEXT NOT NULL, created_at TEXT NOT NULL
);
