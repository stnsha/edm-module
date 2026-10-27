<?php

/**
 * Email cleaner page API - actions are documented on the controller.
 * No database writes: uploads are cleansed by cleanse/import_check.py.
 */
require __DIR__ . '/../app/bootstrap.php';

\Edm\Controllers\EmailCleanerController::run();
