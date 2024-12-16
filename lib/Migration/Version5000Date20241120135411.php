<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\AppAPI\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;
use OCP\Security\ICrypto;

class Version5000Date20241120135411 extends SimpleMigrationStep {

	public function __construct(
		private IDBConnection $connection,
		private ICrypto $crypto,
	) {
	}

	/**
	 * @param IOutput $output
	 * @param Closure $schemaClosure The `\Closure` returns a `ISchemaWrapper`
	 * @param array $options
	 *
	 * @return null|ISchemaWrapper
	 */
	public function postSchemaChange(IOutput $output, Closure $schemaClosure, array $options) {
		// encrypt "haproxy_password" in the "ex_apps_daemons" table
		$qbSelect = $this->connection->getQueryBuilder();
		$qbSelect->select(['id', 'deploy_config'])
			->from('ex_apps_daemons');
		$req = $qbSelect->executeQuery();

		while ($row = $req->fetch()) {
			$deployConfig = $row['deploy_config'];
			$deployConfig = json_decode($deployConfig, true);
			if (!empty($deployConfig['haproxy_password'])) {
				$deployConfig['haproxy_password'] = $this->crypto->encrypt($deployConfig['haproxy_password']);
				$encodedDeployConfig = json_encode($deployConfig);
				$qbUpdate = $this->connection->getQueryBuilder();
				$qbUpdate->update('ex_apps_daemons')
					->set('deploy_config', $qbUpdate->createNamedParameter($encodedDeployConfig))
					->where(
						$qbUpdate->expr()->eq('id', $qbUpdate->createNamedParameter($row['id']))
					);
				$qbUpdate->executeStatement();
			}
		}
		$req->closeCursor();
		return null;
	}
}
