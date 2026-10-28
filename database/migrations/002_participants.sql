ALTER TABLE instances ADD COLUMN answers_revision INTEGER NOT NULL DEFAULT 1;
CREATE TABLE participants (
 id TEXT PRIMARY KEY,
 instance_id TEXT NOT NULL REFERENCES instances(id),
 display_name TEXT NOT NULL CHECK(length(display_name) BETWEEN 1 AND 80),
 version INTEGER NOT NULL DEFAULT 1,
 is_active INTEGER NOT NULL DEFAULT 1 CHECK(is_active IN (0,1)),
 submitted_at TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
 submission_key TEXT NOT NULL,
 UNIQUE(instance_id, submission_key)
);
CREATE INDEX participants_instance ON participants(instance_id, created_at, id);
CREATE TABLE answers (
 participant_id TEXT NOT NULL REFERENCES participants(id),
 slot_id TEXT NOT NULL REFERENCES day_slots(id),
 state TEXT NOT NULL CHECK(state IN ('yes','maybe','no')),
 updated_at TEXT NOT NULL,
 PRIMARY KEY(participant_id, slot_id)
);
CREATE INDEX answers_slot ON answers(slot_id);
CREATE TRIGGER answers_same_instance_insert BEFORE INSERT ON answers
WHEN (SELECT instance_id FROM participants WHERE id=NEW.participant_id) !=
 (SELECT d.instance_id FROM day_slots s JOIN instance_days d ON d.id=s.day_id WHERE s.id=NEW.slot_id)
BEGIN SELECT RAISE(ABORT,'cross-instance answer'); END;
CREATE TRIGGER answers_same_instance_update BEFORE UPDATE ON answers
WHEN (SELECT instance_id FROM participants WHERE id=NEW.participant_id) !=
 (SELECT d.instance_id FROM day_slots s JOIN instance_days d ON d.id=s.day_id WHERE s.id=NEW.slot_id)
BEGIN SELECT RAISE(ABORT,'cross-instance answer'); END;
