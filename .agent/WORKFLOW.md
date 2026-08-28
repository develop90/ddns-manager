# ChatGPT ↔ Codex Bridge Workflow

## Goal
Use ChatGPT for discussion, clarification and task shaping; use Codex for focused repository execution.

## Responsibility split
- ChatGPT: clarify intent, inspect the repo when needed, condense requirements, review results and issue follow-up tasks.
- GitHub: persistent task transport, audit trail, commits/PRs and execution trigger.
- Codex: implement, test, verify and report.
- Notion: roadmap, product decisions, project status and long-form context that is not required for every code change.

## Task contract
A task sent to Codex should contain only the context required to execute safely:

```md
# Task
<single concrete objective>

## Requirements
- ...

## Constraints
- ...

## Acceptance criteria
- ...

## Verification
- smallest relevant checks to run
```

## Result contract
Codex should return a concise result containing:

```md
# Result
status: success | partial | blocked
commit: <sha or none>
tests: <summary>

## Changed
- ...

## Verified
- ...

## Remaining / risks
- ...
```

## Context discipline
- Do not repeat long Notion discussions in every Codex task.
- Prefer references to stable repository instructions when sufficient.
- Do not ask Codex to re-decide product choices already settled in ChatGPT/Notion.
- Codex may reason locally where implementation requires it, but should not broaden scope without a concrete need.

## Source-of-truth rule
If Notion and the repository disagree about technical reality, inspect the current repository/runtime first. Notion records intent and decisions; code and verified configuration establish current implementation truth.
