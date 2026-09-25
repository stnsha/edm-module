<?php

declare(strict_types=1);

namespace Edm\Core;

use mysqli_sql_exception;

/**
 * Base controller for a module folder's api.php:
 *
 *   require __DIR__ . '/../app/bootstrap.php';
 *   \Edm\Controllers\CampaignController::run();
 *
 * run() resolves the logged-in staff, gates on EDM access, calls handle()
 * with the requested action and writes the JSON response:
 *   handle() returns data     -> { success: true, data }
 *   handle() returns null     -> { success: true }
 *   ValidationException       -> 422 { success: false, message, errors }
 *   HttpException             -> its status { success: false, message }
 * A handler with a different response shape calls Response::json() itself.
 */
abstract class Controller
{
    protected Validator $validator;

    final public function __construct(
        protected Request $request,
        protected Auth $auth,
        protected Database $db,
    ) {
        $this->validator = new Validator($db);
    }

    /**
     * @return mixed data for the success response
     * @throws HttpException for an unknown action or a refused request
     */
    abstract protected function handle(string $action): mixed;

    public static function run(): never
    {
        $db = Database::get();
        $auth = Auth::fromSession($db);

        if (!$auth->isLoggedIn()) {
            Response::json(['success' => false, 'message' => 'Staff ID is required. Please ensure you are logged in.'], 401);
        }
        if (!$auth->hasAccess()) {
            Response::json(['success' => false, 'message' => 'You do not have access to the EDM module.'], 403);
        }

        try {
            $controller = new static(new Request(), $auth, $db);
            $data = $controller->handle((string) $controller->request->action);
            Response::json($data === null ? ['success' => true] : ['success' => true, 'data' => $data]);
        } catch (ValidationException $e) {
            Response::json(['success' => false, 'message' => $e->getMessage(), 'errors' => $e->errors()], 422);
        } catch (HttpException $e) {
            Response::json(['success' => false, 'message' => $e->getMessage()], $e->status());
        } catch (mysqli_sql_exception $e) {
            error_log('[EDM] ' . static::class . ': ' . $e->getMessage());
            Response::json(['success' => false, 'message' => 'Database error. Please try again.'], 500);
        }
    }

    protected function unknown(): never
    {
        throw new HttpException('Unknown action', 400);
    }

    protected function requireId(string $label = 'Record'): int
    {
        $id = $this->request->id();
        if ($id <= 0) {
            throw new HttpException($label . ' id is required', 422);
        }

        return $id;
    }

    /**
     * Standard list / create / update / delete against one model.
     *
     * @param class-string<Model> $model
     * @param array<string, mixed> $payload shaped body fields
     * @param array<string, list<string>> $rules create rules
     * @param array<string, list<string>> $updateRules update rules (defaults to $rules)
     */
    protected function crud(string $verb, string $model, array $payload, array $rules, array $updateRules = []): mixed
    {
        switch ($verb) {
            case 'list':
                return $model::all();
            case 'create':
                return $model::create($this->validator->validate($payload, $rules));
            case 'update':
                $id = $this->requireId();
                $model::findOrFail($id);
                return $model::update($id, $this->validator->validate($payload, $updateRules ?: $rules, $id));
            case 'delete':
                $model::delete($this->requireId());
                return null;
        }
        $this->unknown();
    }

    /** created_by / created_by_name style stamp for new rows. */
    protected function stamp(string $prefix = 'created_by'): array
    {
        return [$prefix => $this->auth->staffId, $prefix . '_name' => $this->auth->staffName];
    }
}
