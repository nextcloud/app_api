<?php

namespace OCA\AppEcosystemV2\DeployActions;


use OCA\AppEcosystemV2\Db\DaemonConfig;

/**
 * DockerActions base interface with common required methods for Docker
 */
interface IDockerActions extends IDeployActions {
	public function buildApiUrl(string $dockerUrl, string $route): string;
	public function buildImageName(array $imageParams): string;
	public function buildDockerUrl(DaemonConfig $daemonConfig): string;
	public function healthcheckContainer(string $containerId, DaemonConfig $daemonConfig): bool;
	public function initGuzzleClient(DaemonConfig $daemonConfig): void;
	public function removePrevExAppContainer(string $dockerUrl, string $containerId): array;
	public function removeVolume(string $dockerUrl, string $volume): array;
}
