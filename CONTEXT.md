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

**Approval Pipeline**:
The single lifecycle that presents an Action Proposal, records the human decision, and—only after approval—routes the Tool to its registered Implementation for Tool Execution.
_Avoid_: Approval handler, pending-action type

## Relationships

- A **Tool** has exactly one **Tool Declaration**
- A **Tool Declaration** resolves to exactly one Tool Implementation
- A read-only **Tool** produces a **Tool Execution** immediately
- A mutating **Tool** produces an **Action Proposal** in chat and a **Tool Execution** only after authorization
- Every chat **Action Proposal** passes through the same **Approval Pipeline**
- A declined **Action Proposal** resolves the provider transcript without producing a **Tool Execution**

## Example dialogue

> **Developer:** "Should the chat dispatcher decide whether `edit` needs approval?"
> **Domain expert:** "No. Its Tool Declaration identifies it as mutating, so chat receives an Action Proposal while authenticated MCP receives a Tool Execution."

> **Developer:** "Should plugin activation have its own pending response and AJAX executor?"
> **Domain expert:** "No. Presentation may vary by Tool, but the Action Proposal and approved Tool Execution use the same Approval Pipeline."

## Flagged ambiguities

- "Tool schema" previously meant both the AI declaration and the MCP declaration; resolved: both are projections of one **Tool Declaration**.
- "Approval flow" previously meant separate file, SQL, PHP, and plugin browser paths; resolved: presentation details sit inside one **Approval Pipeline**.
