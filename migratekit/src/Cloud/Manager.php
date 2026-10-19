<?php
/**
 * Cloud provider registry, configuration and connection lifecycle.
 *
 * Configuration lives in one option (`migratekit_cloud`) keyed per provider
 * id. Re-saves merge over stored settings so tokens written by OAuth
 * callbacks survive settings updates, and a masked placeholder never
 * replaces a stored secret.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Cloud;

final class Manager {

	public const OPTION    = 'migratekit_cloud';
	public const MASK      = '********';
	public const PROVIDERS = array( S3Provider::ID, DropboxProvider::ID, DriveProvider::ID );

	/** @var array<string,string> Per-provider secret-ish field kept on save. */
	public const SECRET_FIELDS = array(
		S3Provider::ID      => 'secret_key',
		DropboxProvider::ID => 'client_secret',
		DriveProvider::ID   => 'client_secret',
	);

	private function __construct() {
	}

	/**
	 * @return array<string,array<string,mixed>> Raw stored settings per id.
	 */
	public static function settings(): array {
		$raw = get_option(self::OPTION, array());

		return is_array($raw) ? $raw : array();
	}

	/**
	 * Build a provider from stored configuration. Null when unknown.
	 *
	 * @param string $id Provider id.
	 * @return CloudProviderInterface|null
	 */
	public static function provider(string $id): ?CloudProviderInterface {
		if (! in_array($id, self::PROVIDERS, true)) {
			return null;
		}

		$saved    = self::settings();
		$settings = isset($saved[ $id ]) && is_array($saved[ $id ]) ? $saved[ $id ] : array();

		if ($id === S3Provider::ID) {
			return new S3Provider($settings);
		}

		if ($id === DropboxProvider::ID) {
			return new DropboxProvider($settings);
		}

		return new DriveProvider($settings);
	}

	/**
	 * Sanitise and merge new settings for one provider.
	 *
	 * Tokens already stored for OAuth providers survive: the settings form
	 * never echoes them, so an absent value cannot wipe them.
	 *
	 * @param string $id    Provider id.
	 * @param array  $input Raw input.
	 * @return array<string,mixed> The merged, stored settings.
	 */
	public static function save(string $id, array $input): array {
		if ($id === S3Provider::ID) {
			$clean = S3Provider::settings($input);
		} elseif ($id === DropboxProvider::ID) {
			$clean = DropboxProvider::settings($input);
		} elseif ($id === DriveProvider::ID) {
			$clean = DriveProvider::settings($input);
		} else {
			throw new \RuntimeException('Unknown cloud provider: ' . $id);
		}

		$previous = self::settings();
		$old      = isset($previous[ $id ]) && is_array($previous[ $id ]) ? $previous[ $id ] : array();

		// New settings override their own fields; stored extras (tokens)
		// remain untouched, and non-empty incoming blanks do not wipe them.
		$merged = array();
		$keep   = array( 'access_token', 'refresh_token', 'expires_at', 'account' );

		foreach ($old as $field => $value) {
			if (in_array($field, $keep, true)) {
				$merged[ $field ] = $value;
			}
		}

		$secret_field = isset(self::SECRET_FIELDS[ $id ]) ? self::SECRET_FIELDS[ $id ] : '';

		foreach ($clean as $field => $value) {
			if ($field === $secret_field && (string) $value === '' && isset($old[ $field ]) && (string) $old[ $field ] !== '') {
				$merged[ $field ] = $old[ $field ];
				continue;
			}

			if ($field === $secret_field && (string) $value === self::MASK) {
				$merged[ $field ] = $old[ $field ] ?? '';
				continue;
			}

			$merged[ $field ] = $value;
		}

		$all        = self::settings();
		$all[ $id ] = $merged;

		update_option(self::OPTION, $all, false);

		return $merged;
	}

	/**
	 * Merge OAuth token fields into stored settings.
	 *
	 * @param string                 $id     Provider id.
	 * @param array<string,string|int> $tokens Token payload.
	 * @return void
	 */
	public static function store_tokens(string $id, array $tokens): void {
		if (! in_array($id, self::PROVIDERS, true)) {
			return;
		}

		$all        = self::settings();
		$all[ $id ] = isset($all[ $id ]) && is_array($all[ $id ]) ? $all[ $id ] : array();

		foreach ($tokens as $field => $value) {
			$all[ $id ][ $field ] = $value;
		}

		update_option(self::OPTION, $all, false);
	}

	/**
	 * Wipe settings (also used when disconnecting an OAuth provider).
	 *
	 * @param string $id Provider id.
	 * @return void
	 */
	public static function reset(string $id): void {
		if (! in_array($id, self::PROVIDERS, true)) {
			return;
		}

		$all        = self::settings();
		$all[ $id ] = array();

		update_option(self::OPTION, $all, false);
	}

	/**
	 * Statuses for the UI: per-provider ready/display/selectable state.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function status(): array {
		$items = array();

		foreach (self::PROVIDERS as $id) {
			$provider = self::provider($id);

			if ($provider === null) {
				continue;
			}

			$items[] = array(
				'id'       => $id,
				'label'    => $provider->label(),
				'ready'    => $provider->is_ready(),
				'auth'     => $provider->authorize_url() !== '',
				'authUrl'  => $provider->authorize_url(),
				'settings' => $provider->display(),
			);
		}

		return $items;
	}

	/**
	 * Run one provider's connection test.
	 *
	 * @param string $id Provider id.
	 * @return void
	 */
	public static function test(string $id): void {
		$provider = self::provider($id);

		if ($provider === null) {
			throw new \RuntimeException('Unknown cloud provider: ' . $id);
		}

		if (! $provider->is_ready()) {
			throw new \RuntimeException('The ' . $provider->label() . ' settings are incomplete.');
		}

		$provider->test();
	}

	/**
	 * True when at least one provider is ready.
	 *
	 * @return bool
	 */
	public static function any_ready(): bool {
		foreach (self::PROVIDERS as $id) {
			$provider = self::provider($id);

			if ($provider !== null && $provider->is_ready()) {
				return true;
			}
		}

		return false;
	}

	/**
	 * OAuth callback guard: the provider id must be registered and at least
	 * have app credentials configured for the exchange.
	 *
	 * @param string $id Provider id.
	 * @return bool
	 */
	public static function save_guard(string $id): bool {
		$provider = self::provider($id);

		if ($provider === null) {
			return false;
		}

		if ($id === S3Provider::ID) {
			return false;
		}

		return $provider->authorize_url() !== '';
	}

	/**
	 * Remote names must be identical in shape to local backup names.
	 *
	 * @param string $name Remote name.
	 * @return bool
	 */
	public static function valid_remote(string $name): bool {
		return (bool) preg_match('/^[A-Za-z0-9._-]{1,247}\.wpress$/', $name);
	}
}
