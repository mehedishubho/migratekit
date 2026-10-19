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
use MigrateKit\Pipeline\Pipeline;

final class ExportPipeline {

	/**
	 * Directory names under wp-content that are archived by dedicated stages.
	 *
	 * @var string[]
	 */
	private const CONTENT_EXCLUDES = array( 'uploads', 'plugins', 'themes', 'upgrade', 'migratekit-backups' );

	private function __construct() {
	}

	public static function create(): Pipeline {
		$self = defined('MIGRATEKIT_PATH')
			? basename(rtrim((string) MIGRATEKIT_PATH, '/\\'))
			: 'migratekit';

		$plugin_excludes = $self === '' ? array() : array( $self );

		return new Pipeline(
			array(
				new PrepareStage(),
				new ConfigStage(),
				new EnumerateFilesStage('content', 'content_path', '', self::CONTENT_EXCLUDES),
				new EnumerateFilesStage('media', 'uploads_path', 'uploads', array(), array(), 'no_media'),
				new EnumerateFilesStage('plugins', 'plugins_path', 'plugins', $plugin_excludes, array(), 'no_plugins', 'no_inactive_plugins', 'plugins'),
				new EnumerateFilesStage('themes', 'themes_path', 'themes', array(), array(), 'no_themes', 'no_inactive_themes', 'themes'),
				new ArchiveFilesStage('content', 'content_path', ''),
				new ArchiveFilesStage('media', 'uploads_path', 'uploads'),
				new ArchiveFilesStage('plugins', 'plugins_path', 'plugins'),
				new ArchiveFilesStage('themes', 'themes_path', 'themes'),
				new DatabaseStage(),
				new DatabaseFileStage(),
				new FinalizeStage(),
			)
		);
	}
}
