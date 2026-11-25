<?php
/**
 * Assembles the ordered export pipeline.
 *
 * @package MigrateKit
 */

declare(strict_types=1);

namespace MigrateKit\Export;

use MigrateKit\Export\Stages\ArchiveFilesStage;
use MigrateKit\Export\Stages\ConfigStage;
use MigrateKit\Export\Stages\DatabaseFileStage;
use MigrateKit\Export\Stages\DatabaseStage;
use MigrateKit\Export\Stages\EnumerateFilesStage;
use MigrateKit\Export\Stages\FinalizeStage;
use MigrateKit\Export\Stages\PrepareStage;
use MigrateKit\Export\Stages\SpaceCheckStage;
use MigrateKit\Pipeline\Pipeline;
use MigrateKit\Support\Paths;

final class ExportPipeline {

	/**
	 * Directory names under wp-content that are archived by dedicated stages.
	 *
	 * @var string[]
	 */
	private const CONTENT_EXCLUDES = array( 'uploads', 'plugins', 'themes', 'upgrade', 'migratekit-backups' );

	/**
	 * Directories where other backup plugins keep their archives. Recreating a
	 * site never needs them, and they are often the largest folders on it.
	 * A trailing * matches every name that starts with the rest.
	 *
	 * @var string[]
	 */
	private const BACKUP_EXCLUDES = array(
		'ai1wm-backups',
		'backupbuddy_backups',
		'backups-dup-lite',
		'backups-dup-pro',
		'backupwordpress',
		'backupwordpress-*',
		'boldgrid_backup',
		'updraft',
		'wpvividbackups',
	);

	public static function create(): Pipeline {
		return new Pipeline(self::stages());
	}

	/**
	 * Export stages in run order.
	 *
	 * @return \MigrateKit\Pipeline\StageInterface[]
	 */
	public static function stages(): array {
		$self = defined('MIGRATEKIT_PATH')
			? basename(rtrim((string) MIGRATEKIT_PATH, '/\\'))
			: 'migratekit';

		$plugin_excludes = $self === '' ? array() : array( $self );
		$backup_excludes = self::BACKUP_EXCLUDES;

		return array(
			new PrepareStage(),
			new ConfigStage(),
			new EnumerateFilesStage(
				'content',
				'content_path',
				'',
				array_merge(self::CONTENT_EXCLUDES, $backup_excludes),
				array(),
				'',
				'',
				'',
				self::backups_inside_content()
			),
			new EnumerateFilesStage('media', 'uploads_path', 'uploads', $backup_excludes, array(), 'no_media'),
			new EnumerateFilesStage('plugins', 'plugins_path', 'plugins', $plugin_excludes, array(), 'no_plugins', 'no_inactive_plugins', 'plugins'),
			new EnumerateFilesStage('themes', 'themes_path', 'themes', array(), array(), 'no_themes', 'no_inactive_themes', 'themes'),
			new SpaceCheckStage(),
			new ArchiveFilesStage('content', 'content_path', ''),
			new ArchiveFilesStage('media', 'uploads_path', 'uploads'),
			new ArchiveFilesStage('plugins', 'plugins_path', 'plugins'),
			new ArchiveFilesStage('themes', 'themes_path', 'themes'),
			new DatabaseStage(),
			new DatabaseFileStage(),
			new FinalizeStage(),
		);
	}

	/**
	 * This plugin's backups directory, when a custom path puts it under wp-content.
	 *
	 * @return string[]
	 */
	private static function backups_inside_content(): array {
		if (! defined('WP_CONTENT_DIR')) {
			return array();
		}

		$backups = Paths::backups();
		$content = str_replace('\\', '/', rtrim((string) WP_CONTENT_DIR, '/\\')) . '/';

		return strpos(str_replace('\\', '/', $backups) . '/', $content) === 0 ? array( $backups ) : array();
	}
}
