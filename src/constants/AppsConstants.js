import { translate as t } from '@nextcloud/l10n'

/** Enum of verification constants, according to Apps */
export const APPS_SECTION_ENUM = Object.freeze({
	enabled: t('settings', 'Active apps'),
	disabled: t('settings', 'Disabled apps'),
	updates: t('settings', 'Updates'),
	featured: t('settings', 'Featured apps'),
	supported: t('settings', 'Supported apps'), // From subscription
})
