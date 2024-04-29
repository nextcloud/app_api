<template>
	<NcModal :show="show" @close="closeModal">
		<div class="test-deploy-dialog">
			<h2>{{ t('app_api', 'Test deploy') }} - {{ daemon.display_name }}</h2>
			<p>
				{{ t('app_api', 'AppAPI will try to install small skeleton ExApp to verify Daemon configured correctly and deployment steps are passing.') }}
			</p>
			<p>
				{{ t('app_api', 'The following Deploy test checks must be passed to succeed:') }}
				({{ Object.values(statusChecks).reduce((acc, status_check) => acc + (status_check.passed ? 1 : 0), 0) }} / {{ Object.keys(statusChecks).length }})
			</p>
			<div class="status-checks">
				<div v-for="statusCheck in statusChecks"
					:key="statusCheck.id"
					class="status-check">
					<NcNoteCard
						:type="getStatusCheckType(statusCheck)"
						:heading="statusCheck?.progress ? statusCheck.title + ` (${statusCheck.progress}%)` : statusCheck.title"
						style="margin: 0 0 10px 0;">
						<template #icon>
							<NcLoadingIcon v-if="statusCheck.loading && !statusCheck.error" :size="20" />
							<Check v-else-if="statusCheck.passed" :size="20" />
						</template>
						{{ statusCheck.text }}
					</NcNoteCard>
					<p v-if="statusCheck.error && statusCheck.error_message !== ''" class="error">
						{{ statusCheck.error_message }}
					</p>
					<div class="actions">
						<NcButton
							v-if="statusCheck.error"
							type="tertiary"
							:href="statusCheck.help_url"
							target="_blank"
							style="margin: 5px 0 15px 0;">
							<template #icon>
								<OpenInNew :size="20" />
							</template>
							{{ t('app_api', 'More info') }}
						</NcButton>
					</div>
				</div>
			</div>
			<div class="actions">
				<NcButton
					type="tertiary"
					:href="getDownloadLogsUrl()"
					target="_blank"
					style="margin-right: 10px;">
					<template #icon>
						<Download :size="20" />
					</template>
					{{ t('app_api', 'Download ExApp logs') }}
				</NcButton>
				<NcButton
					v-if="!testRunning"
					:disabled="startingTest"
					type="primary"
					@click="startDeployTest">
					<template #icon>
						<NcLoadingIcon v-if="startingTest" :size="20" />
					</template>
					{{ t('app_api', 'Start Deploy test') }}
				</NcButton>
				<NcButton
					v-if="testRunning"
					type="warning"
					style="margin-left: 5px;"
					:disabled="stoppingTest"
					@click="stopDeployTest">
					<template #icon>
						<StopIcon v-if="!stoppingTest" :size="20" />
						<NcLoadingIcon v-else :size="20" />
					</template>
					{{ t('app_api', 'Stop Deploy test') }}
				</NcButton>
			</div>
		</div>
	</NcModal>
</template>

<script>
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import { showError, showSuccess } from '@nextcloud/dialogs'

import NcModal from '@nextcloud/vue/dist/Components/NcModal.js'
import NcNoteCard from '@nextcloud/vue/dist/Components/NcNoteCard.js'
import NcButton from '@nextcloud/vue/dist/Components/NcButton.js'

import NcLoadingIcon from '@nextcloud/vue/dist/Components/NcLoadingIcon.js'
import Check from 'vue-material-design-icons/Check.vue'
import StopIcon from 'vue-material-design-icons/Stop.vue'
import OpenInNew from 'vue-material-design-icons/OpenInNew.vue'
import Download from 'vue-material-design-icons/Download.vue'

export default {
	name: 'DaemonTestDeploy',
	components: {
		NcModal,
		NcNoteCard,
		NcLoadingIcon,
		NcButton,
		Check,
		StopIcon,
		OpenInNew,
		Download,
	},
	props: {
		show: {
			type: Boolean,
			required: true,
			default: false,
		},
		daemon: {
			type: Object,
			required: true,
			default: () => null,
		},
	},
	data() {
		return {
			startingTest: false,
			stoppingTest: false,
			testRunning: false,
			polling: null,
			statusChecks: {
				register: {
					id: 'register',
					title: t('app_api', 'Register ExApp in Nextcloud'),
					text: t('app_api', 'Check if the ExApp is registered in Nextcloud before deployment'),
					passed: false,
					loading: false,
					error: false,
					error_message: '',
					help_url: 'https://cloud-py-api.github.io/app_api/TestDeploy.html#register',
				},
				image_pull: {
					id: 'image_pull',
					title: t('app_api', 'Image pull'),
					text: t('app_api', 'Check if the image is successfully pulled'),
					passed: false,
					loading: false,
					error: false,
					error_message: '',
					help_url: 'https://cloud-py-api.github.io/app_api/TestDeploy.html#image-pull',
				},
				container_started: {
					id: 'container_started',
					title: t('app_api', 'Container started'),
					text: t('app_api', 'Check if the image successfully pulled and container is created and started'),
					passed: false,
					loading: false,
					error: false,
					error_message: '',
					help_url: 'https://cloud-py-api.github.io/app_api/TestDeploy.html#container-started',
				},
				heartbeat: {
					id: 'heartbeat',
					title: t('app_api', 'Heartbeat'),
					text: t('app_api', 'Check for the heartbeat is finished and healthy'),
					passed: false,
					loading: false,
					error: false,
					error_message: '',
					help_url: 'https://cloud-py-api.github.io/app_api/TestDeploy.html#heartbeat',
				},
				init: {
					id: 'init',
					title: t('app_api', 'Init step'),
					text: t('app_api', 'Wait for initialization step to finish'),
					passed: false,
					loading: false,
					error: false,
					error_message: '',
					help_url: 'https://cloud-py-api.github.io/app_api/TestDeploy.html#init',
				},
				enabled: {
					id: 'enabled',
					title: t('app_api', 'Enabled'),
					text: t('app_api', 'Check if ExApp successfully handled the enabled event and registered all stuff properly'),
					passed: false,
					loading: false,
					error: false,
					error_message: '',
					help_url: 'https://cloud-py-api.github.io/app_api/TestDeploy.html#enabled',
				},
			},
		}
	},
	beforeMount() {
		this.fetchTestDeployStatus()
	},
	beforeDestroy() {
		clearInterval(this.polling)
	},
	methods: {
		closeModal() {
			this.$emit('update:show', false)
		},
		startDeployTest() {
			this.startingTest = true
			Object.values(this.statusChecks).forEach(statusCheck => {
				statusCheck.loading = false
				statusCheck.passed = false
				statusCheck.error = false
				statusCheck.error_message = ''
				if (statusCheck.progress) {
					delete statusCheck.progress
				}
			})
			this._startDeployTest().then((res) => {
				this.testRunning = true
				if (this._detectCurrentStep(res.data.status) === 'register') {
					this.statusChecks.register.passed = true
					this.statusChecks.register.loading = false
				}
			}).catch(() => {
				this.stopDeployTest()
			}).finally(() => {
				this.startingTest = false
			})
		},
		_startDeployTest() {
			return axios.post(generateUrl(`/apps/app_api/daemons/${this.daemon.name}/test_deploy`))
				.then(res => {
					this.startDeployTestPolling()
					return res
				}).catch(err => {
					console.debug(err)
					if (err.data.error) {
						showError(err.data.error)
					}
					this.clearTestRunning()
					return err
				})
		},
		startDeployTestPolling() {
			this.polling = setInterval(() => {
				this.fetchTestDeployStatus()
			}, 1000)
		},
		stopDeployTest() {
			this._stopDeployTest().then(() => {
				Object.values(this.statusChecks).forEach(statusCheck => {
					statusCheck.loading = false
				})
				this.clearTestRunning()
			})
		},
		_stopDeployTest() {
			this.stoppingTest = true
			return axios.delete(generateUrl(`/apps/app_api/daemons/${this.daemon.name}/test_deploy`)).then(() => {
				clearInterval(this.polling)
			}).finally(() => {
				this.stoppingTest = false
			})
		},
		fetchTestDeployStatus() {
			return axios.get(generateUrl(`/apps/app_api/daemons/${this.daemon.name}/test_deploy/status`))
				.then(res => {
					this.handleTestDeployStatus(res.data)
				}).catch(err => {
					// test-deploy app is not registered, test is not running
					if (err.status === 404) {
						this.clearTestRunning()
					}
				})
		},
		handleTestDeployStatus(status) {
			const currentStep = this._detectCurrentStep(status)
			if (currentStep !== null && status.error === '') {
				this.testRunning = true
				if (this.polling === null) {
					this.startDeployTestPolling()
				}
			}
			Object.keys(this.statusChecks).forEach(step => {
				const statusCheck = this.statusChecks[step]
				statusCheck.loading = step === currentStep
				switch (step) {
				case 'register':
					statusCheck.passed = true // at this point we're reading app status, so it's already registered
					break
				case 'image_pull':
					statusCheck.passed = status.deploy >= 94
					statusCheck.progress = status.deploy
					break
				case 'container_started':
					statusCheck.passed = status.deploy >= 98
					break
				case 'heartbeat':
					statusCheck.passed = status.deploy === 100
					break
				case 'init':
					statusCheck.passed = status.init === 100
					statusCheck.progress = status.init
					break
				case 'enabled':
					statusCheck.passed = status.init === 100 && status.deploy === 100 && status.action === '' && status.error === ''
					if (statusCheck.passed) {
						showSuccess(t('app_api', 'Deploy test passed successfully!'))
						this.clearTestRunning()
						statusCheck.loading = false
					}
					break
				}
				if (status.error && step === currentStep) {
					statusCheck.error = true
					statusCheck.error_message = status.error
					statusCheck.loading = false
					statusCheck.passed = false
					showError(t('app_api', 'Deploy test failed at step "{step}"', { step }))
					this.clearTestRunning()
				}
			})
		},
		_detectCurrentStep(status) {
			if (status.action === '' && status.deploy === 0 && status.init === 0) {
				return 'register'
			}
			if (status.action === 'deploy') {
				if (status.deploy > 0 && status.deploy < 94) {
					return 'image_pull'
				}
				if (status.deploy >= 95 && status.deploy <= 97) {
					return 'container_started'
				}
				if (status.deploy >= 98 && status.deploy <= 99) {
					return 'heartbeat'
				}
			}
			if (status.action === 'healthcheck') {
				return 'heartbeat'
			}
			if (status.action === 'init') {
				return 'init'
			}
			if (status.action === '' && status.deploy === 100 && status.init === 100) {
				return 'enabled'
			}
			return null
		},
		getStatusCheckType(statusCheck) {
			if (statusCheck.error || statusCheck.error_message !== '') {
				return 'error'
			}
			if (statusCheck.passed) {
				return 'success'
			}
			return 'info'
		},
		clearTestRunning() {
			this.testRunning = false
			clearInterval(this.polling)
			this.polling = null
		},
		getDownloadLogsUrl() {
			return generateUrl('/apps/app_api/apps/logs/test-deploy')
		},
	},
}
</script>

<style scoped lang="scss">
.test-deploy-dialog {
	padding: 20px;

	.status-checks {
		max-height: 50vh;
		overflow-y: auto;
		margin: 20px 0;
	}

	.actions {
		display: flex;
		justify-content: flex-end;
	}

	.error {
		color: var(--color-error-text);
	}
}
</style>
