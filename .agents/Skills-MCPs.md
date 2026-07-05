# Managing Custom Skills and MCP Servers

This document serves as the core instruction manual for managing MCP (Model Context Protocol) Servers and Custom Skills. Any agent reading this workspace should reference this file when asked to add, modify, or explain MCPs or Skills.

## 1. Adding a Custom MCP Server

When the user wants to add an MCP Server (e.g., using a GitHub repository or an NPX command), the agent should configure it using the standard `mcp_config.json` file.

### Where is the config file?
The `mcp_config.json` file is located at the root of the project (or in the IDE's global configuration directory). If the user provides a new MCP, the agent should append the server configuration to this file.

### How to format `mcp_config.json`:
```json
{
  "mcpServers": {
    "my_custom_server": {
      "command": "npx",
      "args": [
        "-y",
        "@example/mcp-server",
        "--flag"
      ]
    }
  }
}
```

### Agent Handling Protocol:
When the user says: "Add an MCP Server [Name] with [Config]", the agent must:
1. Parse the provided JSON configuration.
2. Locate and edit the `mcp_config.json` file to include the new server.
3. Save the file and inform the user that the server will be available upon reloading or restarting the session.

---

## 2. Adding a New Custom Skill

Skills allow the agent to adopt new personas, strictly follow specific architectural rules, or execute complex multi-step workflows automatically.

### Where to put Skills:
For this workspace, skills must be placed in the local agents directory:
`<Workspace-Root>\.agents\skills\<skill_name>\`

### How to create a Skill:
1. Create a folder for the skill in the directory.
2. Create a `SKILL.md` file inside that folder.
3. The file **MUST** contain YAML frontmatter with `name` and `description`. The description is what triggers the agent to use the skill.

### `SKILL.md` Template:
```markdown
---
name: [Skill Name]
description: [When the agent should use this skill. Be specific.]
---

# Instructions
1. Step one of the skill.
2. Step two of the skill.
(Keep the file under 500 lines).
```

### Supporting Files:
You can also add directories next to `SKILL.md` to enhance the agent's capabilities:
- `scripts/`: Shell scripts or Python files the agent can execute.
- `examples/`: Reference code or templates.
- `references/`: Detailed documentation the agent can read.

### Agent Handling Protocol:
When the user asks to add a new skill, the agent must use the `write_to_file` tool to create the directory and the `SKILL.md` file automatically according to the user's instructions.
