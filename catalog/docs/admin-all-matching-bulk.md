# All-matching administrator bulk actions

Large catalog operations must not depend on rendering every matching row in the browser.

Current behavior:

- **Unverified Files:** `All matching` queues one durable parent job over the current filters. The parent snapshots the highest matching file id, plans bounded 100-file child batches and the children checkpoint after every file. Individual file failures are recorded and do not stop later files.
- **System Errors:** `Apply action to all` executes the chosen status/delete action directly against the current filter predicate; no page-sized ID list is posted.
- **Upload Issues:** `Apply action to all` uses the same predicate-based bulk model.
- **Background Jobs:** the existing `Select all matching` control and `scope=matching` bulk API remain the canonical behavior.

Display page sizes intentionally remain bounded. `All matching` is an action scope, not a request to render 100,000+ HTML rows.
