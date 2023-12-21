import { translate as t } from '@nextcloud/l10n'

/** Enum of verification constants, according to Apps */
export const APPS_SECTION_ENUM = Object.freeze({
	enabled: t('app_api', 'Active apps'),
	disabled: t('app_api', 'Disabled apps'),
	updates: t('app_api', 'Updates'),
	featured: t('app_api', 'Featured apps'),
	supported: t('app_api', 'Supported apps'), // From subscription
})
