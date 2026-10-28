CREATE TABLE instance_confirmations (
 instance_id TEXT PRIMARY KEY REFERENCES instances(id),
 slot_id TEXT NOT NULL REFERENCES day_slots(id),
 starts_at_utc TEXT NOT NULL, ends_at_utc TEXT NOT NULL,
 confirmed_at TEXT NOT NULL, mask_json TEXT NOT NULL CHECK(json_valid(mask_json))
);
CREATE TRIGGER confirmation_slot_insert BEFORE INSERT ON instance_confirmations
WHEN NOT EXISTS (SELECT 1 FROM day_slots s JOIN instance_days d ON d.id=s.day_id WHERE s.id=NEW.slot_id AND d.instance_id=NEW.instance_id AND s.starts_at_utc=NEW.starts_at_utc AND s.ends_at_utc=NEW.ends_at_utc)
BEGIN SELECT RAISE(ABORT,'confirmation slot mismatch'); END;
CREATE TRIGGER confirmation_immutable BEFORE UPDATE ON instance_confirmations
BEGIN SELECT RAISE(ABORT,'confirmation is immutable'); END;
