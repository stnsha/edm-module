<?php

/**
 * assets page API - actions are documented on the controller.
 * Data: edm_* tables in the odb database (see app/bootstrap.php).
 */
require __DIR__ . '/../app/bootstrap.php';

\Edm\Controllers\AssetController::run();
