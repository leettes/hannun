-- 회의 할 일을 빌드업(features)에 올렸을 때의 연결. 한 할 일은 빌드업 항목 하나에만 올린다
-- 올린 할 일의 상태는 빌드업 상태를 따른다 (FeatureController 에서 맞춘다)
ALTER TABLE features ADD COLUMN meeting_task_id INTEGER NULL REFERENCES meeting_tasks (id);
CREATE UNIQUE INDEX idx_features_meeting_task ON features (meeting_task_id) WHERE meeting_task_id IS NOT NULL;
