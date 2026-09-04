<!--
Thank you for contributing to Laravel Brain.

Use a clear, imperative title (for example, "Trace queued listener dispatches").
Keep this description focused on the reviewer-facing intent and evidence. Remove
sections that genuinely do not apply, rather than marking every item "N/A".
-->

## Why

<!--
What problem does this solve? Link the issue, discussion, or real-world use case.
Describe the before/after behaviour, especially for a bug fix.
-->

Closes #

<!-- Required: describe the problem and desired outcome in your own words. -->

## What changed

<!--
Summarize the design and the important trade-offs. Do not narrate the diff.
Call out new nodes/edges, scan output fields, commands, routes, configuration,
or optional integrations if applicable.
-->

-

<!-- Required: replace the bullet above with a meaningful reviewer-facing summary. -->

## Architecture impact

<!-- Complete when this affects the analyzer, graph schema, viewer, exports, or MCP. -->

- [ ] No persisted graph schema, API, or generated-file format changed.
- [ ] Graph schema / API / generated-file compatibility is described below.
- [ ] Existing scan data remains readable, or the migration/regeneration path is documented.
- [ ] Added or changed node/edge detection includes fixture coverage.
- [ ] MCP tools, AI-context exports, or generated AI rules were reviewed where relevant.

<details>
<summary>Compatibility or migration notes</summary>

<!-- State affected Laravel/PHP versions, configuration changes, regeneration steps,
and whether downstream consumers of storage/app/laravel-brain need to act. -->

</details>

## Risk, security, and performance

<!-- Leave only the applicable checks. Explain any risk below. -->

- [ ] No new route, endpoint, command, subprocess, or filesystem write was introduced.
- [ ] Route/API changes protect scanned source and scan output appropriately.
- [ ] Stress-test changes preserve the target-host allowlist and avoid SSRF exposure.
- [ ] Input parsing and generated output were checked for unsafe data handling.
- [ ] Scan performance / memory use is unaffected or was measured on a representative project.
- [ ] This change does not introduce a breaking change.
- [ ] A breaking change is documented in the notes below and includes an upgrade path.

<details>
<summary>Risk, performance, or breaking-change notes</summary>

<!-- Include benchmark results, expected memory changes, and mitigation or rollback details. -->

</details>

## Evidence

<!--
Show reviewers how you established correctness. Include a concise manual scenario
when automated tests cannot cover it. Paste relevant output or link to CI runs.
-->

### Automated checks

- [ ] `composer test:lint`
- [ ] `composer test:unit`
- [ ] `composer test:types`
- [ ] `cd frontend && npm run lint` — required when `frontend/` changed
- [ ] `cd frontend && npm run build` — required when `frontend/` changed
- [ ] Added or updated focused tests / fixtures.

### Manual verification

<!-- Example: `php artisan brain:scan`, open `/_laravel-brain`, inspect the affected
route tab, then validate the exported Mermaid or AI context. Include exact steps and result. -->

1.

<!-- Required: replace the step above with the verification you performed and its result. -->

## UI and documentation

- [ ] No user-visible behaviour changed.
- [ ] Added screenshots or a recording for viewer/UI changes.
- [ ] Updated `README.md` and/or `docs/` for user-facing behaviour, commands, or configuration.
- [ ] Updated inline PHPDoc / TypeScript types where the public contract changed.
- [ ] Added a changelog/release-note entry if maintainers should announce this change.

<!-- Drag screenshots, graph exports, or short recordings below when useful. -->

## Reviewer focus

<!-- Optional: point reviewers at areas where you want a second set of eyes,
known limitations, deferred work, or decisions that need confirmation. -->

## Contributor confirmation

- [ ] I have completed the required sections, kept this pull request focused, and have not included credentials, private source code, or scan output from a real application.
