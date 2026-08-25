<?php

declare(strict_types=1);

namespace CloudPortal\Controllers;

use CloudPortal\Application;
use CloudPortal\Http\HttpException;
use CloudPortal\Http\Request;
use CloudPortal\Http\Response;
use PDOException;

final class ProjectCreateController
{
    public function __construct(private readonly Application $app)
    {
    }

    public function create(Request $request): Response
    {
        $this->app->auth()->requirePermission('admin.access');
        $this->app->csrf->verify($request);

        $user = $this->app->auth()->requireUser();
        $polish = ($user['locale'] ?? 'pl') !== 'en';
        $name = trim((string) $request->input('name'));
        $slug = trim((string) $request->input('slug'));
        $description = trim((string) $request->input('description', ''));
        $fields = [];

        if ($name === '') {
            $fields['name'] = $polish ? 'Nazwa projektu jest wymagana.' : 'Project name is required.';
        } elseif (mb_strlen($name) > 100) {
            $fields['name'] = $polish ? 'Nazwa projektu może mieć maksymalnie 100 znaków.' : 'Project name may contain at most 100 characters.';
        }

        if ($slug === '') {
            $fields['slug'] = $polish ? 'Slug projektu jest wymagany.' : 'Project slug is required.';
        } elseif (strlen($slug) < 2 || strlen($slug) > 100) {
            $fields['slug'] = $polish ? 'Slug musi mieć od 2 do 100 znaków.' : 'Slug must contain between 2 and 100 characters.';
        } elseif (preg_match('/^[a-z0-9][a-z0-9-]{1,99}$/', $slug) !== 1) {
            $fields['slug'] = $polish
                ? 'Slug może zawierać tylko małe litery a-z, cyfry 0-9 i myślnik (-) oraz musi zaczynać się literą lub cyfrą. Przykład: moj-projekt.'
                : 'Slug may contain only lowercase a-z letters, digits 0-9 and hyphens (-), and must start with a letter or digit. Example: my-project.';
        }

        if (mb_strlen($description) > 5000) {
            $fields['description'] = $polish ? 'Opis projektu może mieć maksymalnie 5000 znaków.' : 'Project description may contain at most 5000 characters.';
        }

        if ($fields !== []) {
            $prefix = $polish ? 'Nie można utworzyć projektu. ' : 'The project cannot be created. ';
            throw new HttpException(422, $prefix . implode(' ', array_values($fields)), ['fields' => $fields]);
        }

        try {
            $statement = $this->app->pdo()->prepare(
                'INSERT INTO projects (name,slug,description,created_by) VALUES (:name,:slug,:description,:user)'
            );
            $statement->execute([
                'name' => $name,
                'slug' => $slug,
                'description' => $description,
                'user' => $this->app->auth()->id(),
            ]);
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                $message = mb_strtolower($exception->getMessage());
                if (str_contains($message, 'uq_projects_slug') || str_contains($message, "'" . mb_strtolower($slug) . "'")) {
                    throw new HttpException(409, $polish
                        ? 'Projekt z takim slugiem już istnieje. Wybierz inny slug, np. ' . $slug . '-2.'
                        : 'A project with this slug already exists. Choose another slug, for example ' . $slug . '-2.', ['fields' => ['slug' => $slug]]);
                }
                if (str_contains($message, 'uq_projects_name')) {
                    throw new HttpException(409, $polish
                        ? 'Projekt o tej nazwie już istnieje. Podaj inną nazwę projektu.'
                        : 'A project with this name already exists. Choose another project name.', ['fields' => ['name' => $name]]);
                }
            }
            throw $exception;
        }

        $id = (int) $this->app->pdo()->lastInsertId();
        $this->app->audit()->log($this->app->auth()->id(), $request->ip(), 'admin.projects.create', 'success', 'projects', $id);
        return Response::json(['data' => ['id' => $id]], 201);
    }
}
