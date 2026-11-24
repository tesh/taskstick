---
name: taskstick
description: Manage the user's Google Tasks through the TaskStick connector. Use when the user mentions their tasks, to-dos, to-do list, reminders, priorities, what to work on next, planning their day or week, adding or finishing something on a list, or TaskStick by name.
---

# Working with TaskStick

TaskStick is the user's Google Tasks app (tasks.tesh.ai). Its connector tools all start with `tasks_`. If they are missing, tell the user to connect the TaskStick connector from this plugin's **Connectors** tab (or add `https://tasks.tesh.ai/mcp` as a custom connector) and stop.

## How TaskStick organizes things

- **Lists** hold **tasks**; a task can have **subtasks** (one level).
- **Starred = Priority.** Starred tasks make up the Priority card the user looks at first. Star sparingly; it is the user's focus list, not a tag.
- **Snoozed = set aside.** Snoozing hides a task from the active part of its list and from Priority without completing it. Snoozing a subtask snoozes its whole parent family.
- **Due dates are dates only** (`YYYY-MM-DD`). Work out relative dates ("Friday") from today's date before calling a tool.
- A list called **Daily Ritual** is a checklist the app resets every day. Don't add one-off tasks to it.
- **AI notes**: record suggestions, context or next steps with `tasks_add_ai_note`. It appends a timestamped block and keeps the user's own notes. Never overwrite notes with `tasks_update_task` unless the user asked to replace them.

## Finding things

1. Unknown list → `tasks_list_tasklists`. List IDs and exact list titles both work as `listId`.
2. Unknown task location → `tasks_search` (title and notes, every word must match).
3. "What should I focus on?" → `tasks_list_priority`, then overdue items from the lists that matter.

## Adding tasks

- Search first when a similar task might already exist; update it instead of creating a duplicate.
- Pick the obvious list from context. If two lists fit equally well, ask once.
- Keep titles short and action-first ("Email Kim the Q4 deck"); put detail in `notes`.
- Only star a new task if the user said it's important or urgent.

## Changing tasks

- Prefer `tasks_complete_task` to deleting. Delete only when the user asks, and confirm first; deletion is permanent and removes subtasks.
- Moving between lists: `tasks_move_task` with `destinationListId`.
- After a batch of changes, summarize what changed in one short list instead of repeating each tool result.

## Showing tasks

- When the user wants to see or work through their tasks, call `tasks_open_board` (optionally with one `listId`). In apps that support MCP Apps it opens an interactive board where they can tick off, star and snooze tasks directly; elsewhere it returns a text summary.
- In text, show tasks as a short checklist with ⭐ for starred, 📅 for due dates, and 💤 for snoozed. Leave out IDs unless the user needs them.

## Read-only connections

If a write tool fails with an insufficient-scope / read-only message, tell the user the connection was approved as read-only and they can reconnect TaskStick and leave **Create and change tasks** ticked.
