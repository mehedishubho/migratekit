<?php
/**
 * Constants that are part of the .wpress interoperability contract.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Archive;

final class Protocol {

	/** Table prefix placeholder written into database.sql. */
	public const TABLE_PREFIX = 'SERVMASK_PREFIX_';

	/**
	 * Plaintext carried in package.json.EncryptedSignature.
	 *
	 * This must match the value the reference format verifies so that encrypted
	 * archives remain readable in both directions. It is a protocol constant,
	 * not application copy.
	 */
	public const SIGN_TEXT = '"How long do you want these messages to remain secret? I want them to remain secret for as long as men are capable of evil." - Neal Stephenson';

	private function __construct() {
	}
}
