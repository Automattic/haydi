# Haydi

Haydi is a WordPress administrator assistant that lets a model inspect a site and propose or perform site-management work through registered tools.

## Language

**Tool**:
A named capability that Haydi can expose to a model or remote MCP client.
_Avoid_: Function, command

**Tool Declaration**:
The canonical name, description, JSON Schema, exposure, and execution policy of a Tool.
_Avoid_: Tool schema, function definition

**Tool Execution**:
The result of invoking a Tool Implementation with normalized arguments through an authorized surface.
_Avoid_: Tool response, callback result

**Action Proposal**:
A side-effect-free request for a human to approve a mutating Tool before its Tool Execution.
_Avoid_: Pending write, pending install

## Relationships

- A **Tool** has exactly one **Tool Declaration**
- A **Tool Declaration** resolves to exactly one Tool Implementation
- A read-only **Tool** produces a **Tool Execution** immediately
- A mutating **Tool** produces an **Action Proposal** in chat and a **Tool Execution** only after authorization

## Example dialogue

> **Developer:** "Should the chat dispatcher decide whether `edit` needs approval?"
> **Domain expert:** "No. Its Tool Declaration identifies it as mutating, so chat receives an Action Proposal while authenticated MCP receives a Tool Execution."

## Flagged ambiguities

- "Tool schema" previously meant both the AI declaration and the MCP declaration; resolved: both are projections of one **Tool Declaration**.
