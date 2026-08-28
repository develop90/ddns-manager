# Project Context

## Purpose
DDNS Manager is a lightweight self-hosted DDNS service for GVweb. It provides a web UI, a DynDNS2-compatible update endpoint and Plesk DNS integration.

## Source of truth
- Runtime/code truth: repository code and configuration.
- Agent operating rules: `AGENTS.md` and files under `.agent/`.
- Product/roadmap/decision context: Notion project documentation.

## Current architecture
- PHP 8.x, no framework.
- SQLite in WAL mode.
- DynDNS2-compatible update flow.
- Plesk DNS integration via XML API.
- GitHub → Plesk webhook deployment.

## Operating principles
- Inspect the existing implementation before proposing generic best practices.
- Keep changes local to the requested scope.
- Avoid new dependencies unless the task genuinely requires them.
- Preserve existing deployment assumptions unless explicitly changing them.
- After changes, run the smallest relevant real verification available.
- Report what was actually verified versus what remains unverified.

## Read order for agents
1. `AGENTS.md`
2. `.agent/PROJECT_CONTEXT.md`
3. `.agent/LESSONS.md`
4. `.agent/WORKFLOW.md` when executing tasks through the ChatGPT ↔ Codex bridge
5. Only then inspect the code/docs relevant to the task
