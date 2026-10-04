CREATE TABLE screenshot_captures (
 id uuid PRIMARY KEY, device_id bigint NOT NULL, user_id bigint,
 detected_at timestamp, created_at timestamp NOT NULL
);
CREATE INDEX captures_detected ON screenshot_captures(detected_at);
CREATE INDEX captures_user_detected ON screenshot_captures(user_id,detected_at);
CREATE TABLE screenshot_capture_stages (
 id bigserial PRIMARY KEY, capture_id uuid REFERENCES screenshot_captures(id),
 stage varchar(40) NOT NULL, user_id bigint, occurred_at timestamp NOT NULL,
 last_occurred_at timestamp
);
CREATE UNIQUE INDEX capture_stage_unique ON screenshot_capture_stages(capture_id,stage);
CREATE INDEX stage_time ON screenshot_capture_stages(stage,occurred_at);
CREATE INDEX stage_occurred ON screenshot_capture_stages(occurred_at);
INSERT INTO screenshot_captures
SELECT md5(n::text)::uuid, n%1000+1, CASE WHEN n%10=0 THEN NULL ELSE n%1000+1 END,
 CASE WHEN n%20=0 THEN NULL ELSE timestamp '2026-10-02' - (n%365)*interval '1 day' END,
 timestamp '2026-10-02' - (n%365)*interval '1 day'
FROM generate_series(1,100000) n;
INSERT INTO screenshot_capture_stages(capture_id,stage,user_id,occurred_at,last_occurred_at)
SELECT c.id, stage, c.user_id, c.created_at+interval '1 minute', c.created_at+interval '1 minute'
FROM screenshot_captures c CROSS JOIN (VALUES
 ('detected'),('overlay_shown'),('share_tapped'),('share_started'),('share_completed'),
 ('private_save_tapped'),('private_save_started'),('private_save_completed'),
 ('ignored_timeout'),('ignored_dismissed'),('share_failed'),('private_save_cancelled')
) stages(stage)
WHERE stage IN ('detected','overlay_shown') OR
 (abs(hashtext(c.id::text))::bigint % 3=0 AND stage IN ('share_tapped','share_started','share_completed')) OR
 (abs(hashtext(c.id::text))::bigint % 5=0 AND stage IN ('private_save_tapped','private_save_started','private_save_completed')) OR
 (abs(hashtext(c.id::text))::bigint % 7=0 AND stage IN ('ignored_timeout','share_failed','private_save_cancelled'));
ANALYZE;
