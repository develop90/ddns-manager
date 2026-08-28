# Lessons

Record only durable, verified lessons that should prevent repeated mistakes.

## Rules for adding lessons
- Add a lesson only after observing a real failure, regression, constraint or confirmed project-specific behavior.
- Keep each lesson short and actionable.
- Include evidence such as commit, file, test or incident when useful.
- Do not store guesses, temporary debugging notes or generic best practices here.

## Current lessons
- The repository uses PHP without a framework and intentionally avoids external libraries; preserve that unless a task explicitly justifies a dependency.
- `APP_BUILD` in `config.php` must be incremented for every commit that changes the application.
- `plesk_test.php` is intentionally retained while Plesk DNS behavior is still being stabilized; do not delete it as cleanup without an explicit decision.
