# v1.1.0 rollback guidance

The v1.1 migration is resumable, but it is not fully reversible: all v1.0
bearer tokens are deliberately revoked and their plaintext database values are
cleared. A database backup is the only way to restore those bearer values.

Before rolling application code back:

1. Disable uploads, deletes, default-file writes, and token issuance.
2. Confirm there are no `pending_upload`, `deleting`, or `cleanup_required`
   records. Finish or manually reconcile those records first.
3. Revoke every v1.1 token. Older code cannot verify the digest-only format.
4. Copy `file_default_sets.user` and `set_key` back into the retained nullable
   legacy columns on `file_default`.
5. Remove the v1.1 foreign keys and unique indexes only after verifying that
   doing so will not create orphan or duplicate rows.
6. If old code must run, add a nullable `token VARCHAR(255)` compatibility
   column. Existing tokens will remain unusable and token issuance must stay
   disabled until v1.1 is restored.
7. Keep `object_key` and lifecycle columns during an emergency code rollback;
   dropping them loses recovery information. Remove them only after exporting
   cleanup state and proving every surviving row is active and represented by
   `_path` plus `_name`.

Do not recreate any table as a rollback shortcut. Restore the pre-upgrade
backup instead when an exact schema-and-token rollback is required.
