# TaskStick for Claude

Work with your Google Tasks from Claude through [TaskStick](https://tasks.tesh.ai). Ask what to focus on, plan your day, add and update tasks, star tasks into your Priority card, snooze what can wait, and review everything on an interactive board right in the conversation.

## What's inside

- **Connector**: the TaskStick MCP server at `https://tasks.tesh.ai/mcp` (16 tools plus an interactive board). You sign in with your Google account the first time you connect; you can grant read-only access if you prefer.
- **Skill** `taskstick`: how TaskStick's Priority, Snooze, Daily Ritual and AI notes work, so Claude uses them the way the app does.
- **Commands**: `/taskstick:today` plans your day; `/taskstick:review` runs a weekly review.

## Install

- **claude.ai / Claude Desktop / Cowork**: **Customize > Plugins > Add > Add marketplace**, enter `tesh/taskstick`, then add **TaskStick**. Or download [taskstick-plugin.zip](https://tasks.tesh.ai/mcp/taskstick-plugin.zip) and use **Add > Upload plugin**.
- **Claude Code**:

  ```
  /plugin install taskstick --marketplace tesh/taskstick
  ```

## Use it

1. Add the plugin, then open it in **Customize > Plugins**, go to its **Connectors** tab, and connect TaskStick.
2. Approve access on the TaskStick sign-in page.
3. Ask things like "what's on my plate today?", "add 'renew passport' to Personal, due Friday", or "show me my task board".

## Data

The connector reads and changes your Google Tasks, and TaskStick's own stars and snoozes, on your behalf through tasks.tesh.ai. TaskStick does not keep copies of your tasks; it stores only your sign-in tokens and app preferences. See the [privacy policy](https://tasks.tesh.ai/privacy.html). Disconnect any time from [tasks.tesh.ai/mcp/setup.php](https://tasks.tesh.ai/mcp/setup.php).
