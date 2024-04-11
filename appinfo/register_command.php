<?php

declare(strict_types=1);

use Psr\Container\ContainerExceptionInterface;
use Psr\Container\ContainerInterface;
use Psr\Container\NotFoundExceptionInterface;
use Symfony\Component\Console\Application as SymfonyApplication;

use OCP\Server;
use OCP\IConfig;
use OCA\AppAPI\Service\ExAppOccService;
use OCA\AppAPI\Db\Console\ExAppOccCommand;

try {
	$config = Server::get(IConfig::class);
	$serverContainer = Server::get(ContainerInterface::class);
	if ($config->getSystemValueBool('installed', false)) {
		$exAppOccService = Server::get(ExAppOccService::class);
		/**
		 * @var ExAppOccCommand $occCommand
		 * @var SymfonyApplication $application
		 */
		foreach ($exAppOccService->getOccCommands() as $occCommand) {
			$application->add($exAppOccService->buildCommand(
				$occCommand,
				$serverContainer
			));
		}
	}
} catch (NotFoundExceptionInterface|ContainerExceptionInterface $e) {
}
