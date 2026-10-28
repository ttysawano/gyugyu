CREATE TABLE instance_groups (
 id TEXT PRIMARY KEY,
 instance_id TEXT NOT NULL REFERENCES instances(id),
 name TEXT NOT NULL CHECK(length(name) BETWEEN 1 AND 60),
 name_key TEXT NOT NULL,
 description TEXT NOT NULL DEFAULT '' CHECK(length(description)<=500),
 version INTEGER NOT NULL DEFAULT 1,
 archived_at TEXT, sort_index INTEGER NOT NULL,
 UNIQUE(id,instance_id)
);
CREATE UNIQUE INDEX groups_active_name ON instance_groups(instance_id,name_key) WHERE archived_at IS NULL;
CREATE TRIGGER groups_limit_insert BEFORE INSERT ON instance_groups
WHEN NEW.archived_at IS NULL AND (SELECT COUNT(*) FROM instance_groups WHERE instance_id=NEW.instance_id AND archived_at IS NULL)>=9
BEGIN SELECT RAISE(ABORT,'group limit'); END;
CREATE TRIGGER groups_limit_update BEFORE UPDATE OF archived_at,instance_id ON instance_groups
WHEN NEW.archived_at IS NULL AND (SELECT COUNT(*) FROM instance_groups WHERE instance_id=NEW.instance_id AND archived_at IS NULL AND id!=OLD.id)>=9
BEGIN SELECT RAISE(ABORT,'group limit'); END;
CREATE UNIQUE INDEX participant_instance_identity ON participants(id,instance_id);
CREATE TABLE participant_groups (
 participant_id TEXT NOT NULL, group_id TEXT NOT NULL, instance_id TEXT NOT NULL,
 PRIMARY KEY(participant_id,group_id),
 FOREIGN KEY(participant_id,instance_id) REFERENCES participants(id,instance_id),
 FOREIGN KEY(group_id,instance_id) REFERENCES instance_groups(id,instance_id)
);
CREATE INDEX memberships_group ON participant_groups(group_id);
