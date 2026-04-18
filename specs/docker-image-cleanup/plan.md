# Plan: Automatic Clean-up of Outdated ExApp Docker Images

**Spec:** [spec-docker-image-cleanup.md](./spec-docker-image-cleanup.md)
**Issue:** [nextcloud/app_api#667](https://github.com/nextcloud/app_api/issues/667)

---

## Phases

### Phase 1: Docker API Methods — DONE

| Task | File | What |
|------|------|------|
| `removeImage()` | `DockerActions.php` | `DELETE /images/{id}`, uses `Http::STATUS_*` constants |
| `pruneImages()` | `DockerActions.php` | `POST /images/prune`, uses `Util::humanFileSize()` |
| Config constants | `Application.php` | `CONF_IMAGE_CLEANUP_INTERVAL_DAYS`, `CONF_IMAGE_CLEANUP_ON_UPDATE` |

### Phase 2: Background Job — DONE

| Task | File | What |
|------|------|------|
| `DockerImageCleanupJob` | New file | Dynamic `setInterval()` from config, `isCleanupDisabled()`, `pruneImagesOnAllDaemons()`, `supportsPrune()` |
| Register job | `info.xml` | Added to `<background-jobs>` |

### Phase 3: Cleanup on Update — DONE

| Task | File | What |
|------|------|------|
| Old image removal | `Update.php` | Added `IAppConfig` dep, captures old image before deploy, `removeOldImageIfEnabled()` after success |

### Phase 4: Admin Settings — DONE

| Task | File | What |
|------|------|------|
| Initial state | `Admin.php` | Added both settings to `$adminInitialData` |
| UI controls | `AdminSettings.vue` | Interval input + checkbox, uses existing `onInput()`/`onCheckboxChanged()` |

---

## Verification

- [x] `removeImage()` handles 200, 404, 409 correctly
- [x] `pruneImages()` sends correct filters, logs space reclaimed
- [x] Background job disabled when interval = 0
- [x] Background job skips non-Docker and HaRP daemons
- [x] Background job errors don't block other daemons
- [x] Update command only removes old image when checkbox enabled
- [x] Update cleanup failure doesn't block the update
- [x] Admin UI displays and saves both settings
- [x] Config keys centralized as constants
- [x] Registered in `info.xml`
- [x] Psalm: 0 errors
- [x] CS Fixer: 0 issues
- [x] Tests: 25 passing (8 + 11 + 6)

---

## Risks

| Risk | Mitigation |
|------|------------|
| DSP haproxy blocks `/images/prune` | Log error, document in release notes |
| Prune removes non-AppAPI images | Standard `docker image prune` behavior, only dangling images |
| HaRP doesn't support prune | Skip + log, follow-up when upstream adds it |
| Shared image between ExApps | Docker refuses with 409, handled gracefully |
