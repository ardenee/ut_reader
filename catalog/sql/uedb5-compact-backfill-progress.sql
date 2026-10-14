-- Optional persistent progress ledger for a future compact V5 backfill.
-- Do NOT apply before coordinated dual-write integration and a migration rehearsal.
-- One durable, transactionally updated cursor per game.
CREATE TABLE ue_uedb5_compact_backfill_progress (
  game_id INT UNSIGNED NOT NULL,
  last_verified_file_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  verified_file_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  verified_name_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  verified_object_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at DATETIME(6) NOT NULL,
  PRIMARY KEY (game_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;
