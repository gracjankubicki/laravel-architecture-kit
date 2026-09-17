---
name: architecture-kit-inertia
description: Build and review Inertia 3 page responses, props, forms, shared data, partial reloads, and deferred props under the project's Laravel architecture rules.
---

# Inertia 3

Use this skill when you add or change an Inertia page response, shared prop, form endpoint, partial reload, deferred prop, or a class that prepares page props.

## Before coding

1. Read the enabled Architecture Kit profiles.
2. Inspect the existing controller, page component, prop types, route, authorization, validation, and tests.
3. Identify which values the first render needs, which values can load later, and which values are route-specific.
4. If Query Objects, API Resources, Form Requests, Actions, or Data Objects are enabled, load their Architecture Kit skills before changing those boundaries.

## Choose the smallest prop shape

Use an explicit array for a simple page:

```php
return Inertia::render('Projects/Show', [
    'projectId' => $project->getKey(),
    'can' => [
        'update' => $request->user()->can('update', $project),
    ],
]);
```

When API Resources are enabled, format model data with a Resource after the query has loaded its relations:

```php
$project = $projects->handle($projectId);

return Inertia::render('Projects/Show', [
    'project' => ProjectResource::make($project),
]);
```

When API Resources are not enabled, map only the required fields in the presentation layer. Do not expose an unfiltered Eloquent model.

Extract a named props class when several pages reuse the composition or when the payload has enough branches to deserve focused tests:

```php
final readonly class ProjectIndexProps
{
    public function __construct(private ListProjects $projects) {}

    public function toArray(ProjectFilters $filters): array
    {
        return [
            'filters' => $filters->toArray(),
            'projects' => ProjectResource::collection(
                $this->projects->handle($filters),
            ),
        ];
    }
}
```

Keep that class in the presentation layer. If Query Objects are enabled, it may call them to read data. It must not write. Use `ProvidesInertiaProperties` only for a reusable individual prop that needs the Inertia `PropertyContext`.

## Keep boundaries one-way

- Controllers, middleware, page responders, and page props classes may know Inertia.
- Actions and Query Objects must not import, accept, return, or call `Inertia\*` types.
- API Resources only serialize loaded data.
- Data Objects only store typed values.
- Page prop preparation must not mutate data or dispatch work.
- Spatie Laravel Data is optional. Do not add it only to satisfy this profile.

## Handle requests and forms

Select request fields explicitly:

```php
'filters' => $request->only(['status', 'owner']),
'search' => $request->input('search'),
'form' => $request->validated(),
```

Do not pass `$request`, `$request->all()`, `$request->toArray()`, `$request->input()` without a key, or `$request->collect()` without a key.

Authorize every operation on the server. A `can` prop is UI data, not permission enforcement. Let Laravel redirect back with validation errors. After a successful write, redirect to a GET route. Keep the frontend prop type synchronized with the serialized response rather than the model.

## Share and load data deliberately

- Put only small, common values in middleware `share()`. Use namespaced keys such as `auth.user` and `flash.message`.
- Keep route-specific data in `Inertia::render()`.
- Use closures for values a partial reload can omit.
- Use `Inertia::optional(...)` for props requested only through `only`.
- Use `Inertia::always(...)` only for props that must survive every partial reload.
- Use `Inertia::defer(...)` for independent data that can arrive after the first response.
- Group deferred props only when they belong to one follow-up request.
- Use `rescue()` for an expected deferred failure only when the fallback is part of the page contract. Re-throw unexpected failures.

This profile does not prescribe React or Vue folders, state management, component composition, or a generated TypeScript architecture.

## Verify the page contract

- Use `assertInertia` for the component and serialized props.
- Use `reloadOnly` for partial reload behavior.
- Use `loadDeferredProps` for deferred payloads and their failure fallback.
- Test server-side authorization, validation redirects, and the successful POST, PUT, PATCH, or DELETE redirect.

## Know what the guard checks

The deterministic `inertia` rule reports an error when an Action or Query Object depends on a recognized `Inertia\*` symbol. It also reports an error when a whole unfiltered Request reaches Inertia props. Explicit `only(...)`, `validated()`, `safe()`, and keyed `input(...)` or `collect(...)` access are accepted. An unresolved request-derived prop produces a warning.

The guard does not prove prop completeness, frontend type accuracy, correct authorization, correct partial reload behavior, or correct deferred error UX. Cover those requirements with application tests.
