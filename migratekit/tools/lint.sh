#!/bin/sh
# Quick syntax check over sources touched during the parity phase.
set -e
for f in \
	src/Support/OptionStore.php \
	src/Support/Paths.php \
	src/Support/SecretKey.php \
	src/Support/Maintenance.php \
	src/Job/Status.php \
	src/Job/Runner.php \
	src/Storage/BackupsRepository.php \
	src/Scheduler/Cron.php \
	src/Http/AjaxController.php \
	src/Http/RestController.php \
	src/Http/AdminPostController.php \
	src/Http/Options.php \
	src/Archive/Inspector.php \
	src/Admin/Menu.php \
	src/Admin/AssetLoader.php \
	src/Cli/Command.php \
	src/Export/ExportPipeline.php \
	src/Export/Stages/EnumerateFilesStage.php \
	src/Import/Stages/DatabaseStage.php \
	src/Plugin.php \
	; do
	php -l "$f" >/dev/null 2>&1 || echo "SYNTAX-FAIL: $f"
done
echo LINT-DONE
