<?php
/**
 * Verifies the decryption password for encrypted archives.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Import\Stages;

use MigrateKit\Archive\Encryptor;
use MigrateKit\Archive\Protocol;
use MigrateKit\Pipeline\PipelineContext;
use MigrateKit\Pipeline\StageInterface;

final class EncryptionStage implements StageInterface {

	public function run(array &$state, PipelineContext $ctx): bool {
		if (empty($state['package']['Encrypted'])) {
			return true;
		}

		$password = $state['password'];

		if ($password === null || $password === '') {
			throw new \RuntimeException('This archive is encrypted. Provide the decryption password to continue.');
		}

		$signature = isset($state['package']['EncryptedSignature'])
			? (string) $state['package']['EncryptedSignature']
			: '';

		if ($signature !== '') {
			$encryptor = new Encryptor((string) $password);

			if (! $encryptor->verify($signature, Protocol::SIGN_TEXT)) {
				throw new \RuntimeException('The decryption password is incorrect.');
			}
		}

		$ctx->report('Decryption password verified');

		return true;
	}
}
