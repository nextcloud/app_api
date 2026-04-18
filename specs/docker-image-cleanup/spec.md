# Spec: Automatic Clean-up of Outdated ExApp Docker Images

**Issue:** [nextcloud/app_api#667](https://github.com/nextcloud/app_api/issues/667)
**Status:** Implemented

---

## Problem

When ExApps are updated, new Docker images are pulled but old ones are never removed, consuming disk space over time.

---

## Solution

Two complementary mechanisms:

### Mechanism A: Periodic Background Prune Job

- `DockerImageCleanupJob` — a `TimedJob` that calls `POST /images/prune` (filter: `dangling=true`) on each eligible Docker daemon.
- Interval configurable via `image_cleanup_interval_days` (default 7, 0 = disabled).
- Uses `getValueInt()` and dynamic `setInterval()` — Nextcloud handles scheduling.
- Skips HaRP daemons (upstream doesn't support prune endpoint yet).
- Errors are logged per-daemon; one failing daemon doesn't block others.

### Mechanism B: Immediate Cleanup on Update

- After a successful ExApp update, optionally removes the old image via `DELETE /images/{id}`.
- Controlled by `image_cleanup_on_update` checkbox (default: disabled).
- Uses `getValueBool()` to read the setting.
- Failures are logged as warnings, never block the update.

### Admin UI

Both settings exposed in `/settings/admin/app_api`:
- Number input for cleanup interval (days)
- Checkbox for "Remove old images after ExApp update"

---

## Architecture

```
AdminSettings.vue
  |-- saveOptions() / onCheckboxChanged()
  v
ConfigController → IAppConfig (DB)
  |                          |
  v                          v
DockerImageCleanupJob     Update.php
  |                          |
  v                          v
pruneImages()             removeImage()
POST /images/prune        DELETE /images/{id}
(bulk, per daemon)        (specific old image)
```

### New on `DockerActions`

- `removeImage(dockerUrl, imageId): string` — deletes specific image. 404/409 treated as success.
- `pruneImages(dockerUrl, filters): array` — bulk prune. Uses `Http::STATUS_*` constants, `Util::humanFileSize()` for logging.

### `DockerImageCleanupJob` structure

- Constructor: reads interval from config, passes to `setInterval()`
- `run()` → `isCleanupDisabled()` → `pruneImagesOnAllDaemons()` → `supportsPrune(daemon)`

### Update command (`Update.php`)

- Before deploy: captures old image name via `buildDeployParams()` + `buildBaseImageName()`
- After successful deploy: `removeOldImageIfEnabled()` checks setting, calls `removeImage()`

---

## Configuration

| Key | Type | Default | Notes |
|-----|------|---------|-------|
| `image_cleanup_interval_days` | int | `7` | 0 = disabled. Constant: `Application::CONF_IMAGE_CLEANUP_INTERVAL_DAYS` |
| `image_cleanup_on_update` | bool | `false` | Constant: `Application::CONF_IMAGE_CLEANUP_ON_UPDATE` |

---

## Error Handling

| Scenario | Behavior |
|----------|----------|
| Daemon unreachable | Log error, skip, continue to next |
| Image not found (404) | Treat as success |
| Image in use (409) | Log warning, skip |
| Prune fails | Log error, skip daemon |
| Cleanup fails during update | Log warning, don't block update |
| HaRP daemon | Log debug, skip |

---

## Files Changed

| File | Change |
|------|--------|
| `lib/BackgroundJob/DockerImageCleanupJob.php` | **New** — periodic prune job |
| `lib/DeployActions/DockerActions.php` | Added `removeImage()`, `pruneImages()` |
| `lib/Command/ExApp/Update.php` | Added `removeOldImageIfEnabled()` |
| `lib/AppInfo/Application.php` | Added config key constants |
| `lib/Settings/Admin.php` | Added settings to initial state |
| `src/components/AdminSettings.vue` | Added interval input + checkbox |
| `appinfo/info.xml` | Registered background job |
| `tests/php/BackgroundJob/DockerImageCleanupJobTest.php` | **New** — 8 tests |
| `tests/php/DeployActions/DockerActionsImageCleanupTest.php` | **New** — 11 tests |
| `tests/php/Command/ExApp/UpdateImageCleanupTest.php` | **New** — 6 tests |

---

## Out of Scope

- HaRP prune API (requires upstream changes)
- Docker Socket Proxy haproxy config (requires upstream changes)
- Kubernetes image cleanup (different mechanism)
