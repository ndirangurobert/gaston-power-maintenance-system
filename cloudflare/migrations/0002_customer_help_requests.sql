PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS customer_help_requests (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  customer_id INTEGER REFERENCES customers(id) ON DELETE SET NULL,
  subject TEXT NOT NULL,
  message TEXT NOT NULL,
  status TEXT NOT NULL DEFAULT 'pending'
    CHECK(status IN ('pending','in_progress','resolved')),
  assigned_to INTEGER REFERENCES users(id) ON DELETE SET NULL,
  staff_response TEXT,
  created_by INTEGER REFERENCES users(id) ON DELETE SET NULL,
  created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_help_status
ON customer_help_requests(status);

CREATE INDEX IF NOT EXISTS idx_help_assigned
ON customer_help_requests(assigned_to);
